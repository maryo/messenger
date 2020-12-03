<?php

declare(strict_types=1);

namespace Fmasa\Messenger\DI;

use Fmasa\Messenger\Exceptions\InvalidHandlerService;
use Fmasa\Messenger\Exceptions\MultipleHandlersFound;
use Fmasa\Messenger\LazyHandlersLocator;
use Fmasa\Messenger\Tracy\LogToPanelMiddleware;
use Fmasa\Messenger\Tracy\MessengerPanel;
use Fmasa\Messenger\Transport\SendersLocator;
use Fmasa\Messenger\Transport\TaggedServiceLocator;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\PhpGenerator\ClassType;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpTransportFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\EventListener\DispatchPcntlSignalListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnSigtermSignalListener;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactory;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function assert;
use function call_user_func;
use function count;
use function is_string;

class MessengerExtension extends CompilerExtension
{
    private const TAG_HANDLER           = 'messenger.messageHandler';
    private const TAG_TRANSPORT_FACTORY = 'messenger.transportFactory';
    private const TAG_RECEIVER_ALIAS    = 'messenger.receiver.alias';
    private const TAG_BUS_NAME          = 'messenger.bus.name';
    private const TAG_RETRY_STRATEGY    = 'messenger.retryStrategy';

    private const HANDLERS_LOCATOR_SERVICE_NAME = '.handlersLocator';
    private const PANEL_MIDDLEWARE_SERVICE_NAME = '.middleware.panel';
    private const PANEL_SERVICE_NAME            = 'panel';

    private const DEFAULT_FACTORIES = [
        'amqp' => AmqpTransportFactory::class,
        'inMemory' => InMemoryTransportFactory::class,
        'redis' => RedisTransportFactory::class,
    ];

    public function getConfigSchema() : Schema
    {
        return Expect::structure([
            'serializer' => Expect::from(new SerializerConfig()),
            'buses' => Expect::arrayOf(Expect::from(new BusConfig())),
            'transports' => Expect::arrayOf(Expect::anyOf(
                Expect::string(),
                Expect::from(new TransportConfig())
            )),
            'routing' => Expect::arrayOf(
                Expect::anyOf(Expect::string(), Expect::listOf(Expect::string()))
            ),
        ]);
    }

    public function loadConfiguration() : void
    {
        $builder = $this->getContainerBuilder();

        $this->processTransports();
        $this->processRouting();
        $this->processBuses();
        $this->processConsoleCommands();

        if (! $this->isPanelEnabled()) {
            return;
        }

        $builder->addDefinition($this->prefix(self::PANEL_SERVICE_NAME))
            ->setType(MessengerPanel::class)
            ->setArguments([$this->getContainerBuilder()->findByType(LogToPanelMiddleware::class)]);
    }

    /**
     * @throws InvalidHandlerService
     * @throws MultipleHandlersFound
     */
    public function beforeCompile() : void
    {
        $config  = $this->getConfig();
        $builder = $this->getContainerBuilder();

        foreach ($config->buses as $busName => $busConfig) {
            assert($busConfig instanceof BusConfig);

            $handlers = [];

            foreach ($this->getHandlersForBus($busName) as $serviceName) {
                foreach ($this->getHandledMessageNames($serviceName) as $messageName) {
                    if (! isset($handlers[$messageName])) {
                        $handlers[$messageName] = [];
                    }

                    $alias = $builder->getDefinition($serviceName)->getTag(self::TAG_HANDLER);

                    $handlers[$messageName][$serviceName] = $alias['alias'] ?? null;
                }
            }

            if ($busConfig->singleHandlerPerMessage) {
                foreach ($handlers as $messageName => $messageHandlers) {
                    if (count($messageHandlers) > 1) {
                        throw MultipleHandlersFound::fromHandlerClasses(
                            $messageName,
                            array_map([$builder, 'getDefinition'], array_keys($messageHandlers))
                        );
                    }
                }
            }

            $handlersLocator = $this->getContainerBuilder()
                ->getDefinition($this->prefix($busName . self::HANDLERS_LOCATOR_SERVICE_NAME));

            assert($handlersLocator instanceof ServiceDefinition);

            $handlersLocator->setArguments([$handlers]);
        }

        $this->passRegisteredTransportFactoriesToMainFactory();
    }

    public function afterCompile(ClassType $class) : void
    {
        if (! $this->isPanelEnabled()) {
            return;
        }

        $this->enableTracyIntegration($class);
    }

    private function processBuses() : void
    {
        $builder = $this->getContainerBuilder();

        foreach ($this->getConfig()->buses as $busName => $busConfig) {
            assert($busConfig instanceof BusConfig);

            $middleware = [];

            if ($busConfig->panel) {
                $middleware[] = $builder->addDefinition($this->prefix($busName . self::PANEL_MIDDLEWARE_SERVICE_NAME))
                    ->setFactory(LogToPanelMiddleware::class, [$busName]);
            }

            foreach ($busConfig->middleware as $index => $middlewareDefinition) {
                $middleware[] = $builder->addDefinition($this->prefix($busName . '.middleware.' . $index))
                    ->setFactory($middlewareDefinition);
            }

            $handlersLocator = $builder->addDefinition($this->prefix($busName . self::HANDLERS_LOCATOR_SERVICE_NAME))
                ->setFactory(LazyHandlersLocator::class);

            $middleware[] = $builder->addDefinition($this->prefix($busName . '.sendMiddleware'))
                ->setFactory(SendMessageMiddleware::class);

            $middleware[] = $builder->addDefinition($this->prefix($busName . '.defaultMiddleware'))
                ->setFactory(HandleMessageMiddleware::class, [$handlersLocator, $busConfig->allowNoHandlers]);

            $builder->addDefinition($this->prefix($busName . '.bus'))
                ->setFactory(MessageBus::class, [$middleware])
                ->setTags([self::TAG_BUS_NAME => $busName]);
        }
    }

    /**
     * @return Statement[]
     */
    private function getSubscribers() : array
    {
        return [
            new Statement(DispatchPcntlSignalListener::class),
            new Statement(
                SendFailedMessageForRetryListener::class,
                [
                    new Statement(TaggedServiceLocator::class, [SendersLocator::TAG_SENDER_ALIAS]),
                    new Statement(TaggedServiceLocator::class, [self::TAG_RETRY_STRATEGY]),
                ]
            ),
            // TODO: Add support for failed transport
            // new Statement(SendFailedMessageToFailureTransportListener::class),
            new Statement(StopWorkerOnSigtermSignalListener::class),
        ];
    }

    private function processConsoleCommands() : void
    {
        $builder = $this->getContainerBuilder();

        $routableBus = $builder->addDefinition($this->prefix('busLocator'))
            ->setAutowired(false)
            ->setFactory(
                RoutableMessageBus::class,
                [new Statement(TaggedServiceLocator::class, [self::TAG_BUS_NAME]), null]
            );

        $receiverLocator = $builder->addDefinition($this->prefix('console.receiversLocator'))
            ->setFactory(TaggedServiceLocator::class, [self::TAG_RECEIVER_ALIAS])
            ->setAutowired(false);

        $eventDispatcher = $builder->addDefinition($this->prefix('console.eventDispatcher'))
            ->setFactory(EventDispatcher::class)
            ->setAutowired(false);

        foreach ($this->getSubscribers() as $subscriber) {
            $eventDispatcher->addSetup('addSubscriber', [$subscriber]);
        }

        $builder->addDefinition($this->prefix('console.command.consumeMessages'))
            ->setFactory(ConsumeMessagesCommand::class, [$routableBus, $receiverLocator, $eventDispatcher]);
    }

    private function processTransports() : void
    {
        $builder = $this->getContainerBuilder();

        $transportFactory = $builder->addDefinition($this->prefix('transportFactory'))
            ->setFactory(TransportFactory::class);

        foreach (self::DEFAULT_FACTORIES as $name => $factoryClass) {
            $builder->addDefinition($this->prefix('transportFactory.' . $name))
                ->setFactory($factoryClass)
                ->setTags([self::TAG_TRANSPORT_FACTORY => true]);
        }

        $serializerConfig = $this->getConfig()->serializer;
        assert($serializerConfig instanceof SerializerConfig);

        $defaultSerializer = $builder->addDefinition($this->prefix('defaultSerializer'))
            ->setType(SerializerInterface::class)
            ->setFactory($serializerConfig->defaultSerializer);

        foreach ($this->getConfig()->transports as $transportName => $transportConfig) {
            assert(is_string($transportConfig) || $transportConfig instanceof TransportConfig);

            if (is_string($transportConfig)) {
                $dsn           = $transportConfig;
                $retryStrategy = new RetryStrategyConfig();
                $options       = [];
                $serializer    = $defaultSerializer;
            } else {
                $dsn           = $transportConfig->dsn;
                $retryStrategy = $transportConfig->retryStrategy ?? new RetryStrategyConfig();
                $options       = $transportConfig->options;                $serializer    = $transportConfig->serializer !== null
                    ? $builder->addDefinition($this->prefix('serializer.' . $transportName))
                        ->setType(SerializerInterface::class)
                        ->setFactory($transportConfig->serializer)
                    : $defaultSerializer;            }

            $retryStrategyService = $builder->addDefinition($this->prefix('transport.' . $transportName . '.retryStrategy'))
                ->setTags([self::TAG_RETRY_STRATEGY => $transportName]);

            if ($retryStrategy->service !== null) {
                $retryStrategyService->setFactory($retryStrategy->service);
            } else {
                $retryStrategyService->setFactory(
                    MultiplierRetryStrategy::class,
                    [
                        $retryStrategy->maxRetries,
                        $retryStrategy->delay,
                        $retryStrategy->multiplier,
                        $retryStrategy->maxDelay,
                    ]
                );
            }

            $transportServiceName = $this->prefix('transport.' . $transportName);

            $builder->addDefinition($transportServiceName)
                ->setFactory([$transportFactory, 'createTransport'], [$dsn, $options, $serializer])
                ->setTags([
                    SendersLocator::TAG_SENDER_ALIAS => $transportName,
                    self::TAG_RECEIVER_ALIAS => $transportName,
                ]);
        }
    }

    private function processRouting() : void
    {
        $this->getContainerBuilder()->addDefinition($this->prefix('sendersLocator'))
            ->setFactory(
                SendersLocator::class,
                [
                    array_map(
                        static function ($oneOrManyTransports) : array {
                                return is_string($oneOrManyTransports) ? [$oneOrManyTransports] : $oneOrManyTransports;
                        },
                        $this->getConfig()->routing
                    ),
                ]
            );
    }

    /**
     * @return string[] Service names
     */
    private function getHandlersForBus(string $busName) : array
    {
        $builder = $this->getContainerBuilder();

        /** @var string[] $serviceNames */
        $serviceNames = array_keys(
            array_merge(
                $builder->findByTag(self::TAG_HANDLER),
                $builder->findByType(MessageHandlerInterface::class)
            )
        );

        return array_filter(
            $serviceNames,
            static function (string $serviceName) use ($builder, $busName) : bool {
                $definition = $builder->getDefinition($serviceName);

                return ($definition->getTag(self::TAG_HANDLER)['bus'] ?? $busName) === $busName;
            }
        );
    }

    /**
     * @return iterable<string>
     *
     * @throws InvalidHandlerService
     */
    private function getHandledMessageNames(string $serviceName) : iterable
    {
        $handlerClassName = $this->getContainerBuilder()->getDefinition($serviceName)->getType();
        assert(is_string($handlerClassName));

        $handlerReflection = new ReflectionClass($handlerClassName);

        if ($handlerReflection->implementsInterface(MessageSubscriberInterface::class)) {
            return call_user_func([$handlerClassName, 'getHandledMessages']);
        }

        try {
            $method = $handlerReflection->getMethod('__invoke');
        } catch (ReflectionException $e) {
            throw InvalidHandlerService::missingInvokeMethod($serviceName, $handlerReflection->getName());
        }

        if ($method->getNumberOfRequiredParameters() !== 1) {
            throw InvalidHandlerService::wrongAmountOfArguments($serviceName, $handlerReflection->getName());
        }

        $parameter     = $method->getParameters()[0];
        $parameterName = $parameter->getName();
        $type          = $parameter->getType();
        assert($type instanceof ReflectionNamedType || $type === null);

        if ($type === null) {
            throw InvalidHandlerService::missingArgumentType($serviceName, $handlerClassName, $parameterName);
        }

        if ($type->isBuiltin()) {
            throw InvalidHandlerService::invalidArgumentType($serviceName, $handlerClassName, $parameterName, $type);
        }

        return [$type->getName()];
    }

    private function enableTracyIntegration(ClassType $class) : void
    {
        $class->getMethod('initialize')->addBody($this->getContainerBuilder()->formatPhp('?;', [
            new Statement(
                '@Tracy\Bar::addPanel',
                [new Statement('@' . $this->prefix(self::PANEL_SERVICE_NAME))]
            ),
        ]));
    }

    private function isPanelEnabled() : bool
    {
        return $this->getContainerBuilder()->findByType(LogToPanelMiddleware::class) !== [];
    }

    private function passRegisteredTransportFactoriesToMainFactory() : void
    {
        $builder = $this->getContainerBuilder();

        $transportFactory = $builder->getDefinition($this->prefix('transportFactory'));
        assert($transportFactory instanceof ServiceDefinition);

        $transportFactory->setArguments([
            array_map([$builder, 'getDefinition'], array_keys($builder->findByTag(self::TAG_TRANSPORT_FACTORY))),
        ]);
    }
}
