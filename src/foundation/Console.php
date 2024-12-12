<?php

namespace SwooleBase\Foundation;

use Closure;
use FastRoute\DataGenerator;
use FastRoute\Dispatcher as FastRouteDispatcherInterface;
use FastRoute\Dispatcher\GroupCountBased as FastRouteDispatcher;
use ReflectionClass;
use ReflectionException;
use SwooleBase\Foundation\Abstracts\BackgroundWorker;
use SwooleBase\Foundation\Http\Request;
use SwooleBase\Foundation\Interfaces\ResponseInterface;
use SwooleBase\Foundation\Interfaces\RouteHandlerInterface;
use SwooleBase\Foundation\Http\Response;
use SwooleBase\Foundation\Http\RouteCollector;
use SwooleBase\Foundation\Interfaces\ExceptionHandler;
use SwooleBase\Foundation\Interfaces\Router;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * @param mixed ...$arguments
 * @return mixed|Console
 */
function console(...$arguments): mixed
{
    if (empty($arguments)) {
        return Console::instance();
    }

    return Console::instance()->accessible($arguments);
}

/**
 * Class Console
 * @package SwooleBase\Foundation
 *
 * @property $workerNum
 * @property $taskWorkerNum
 * @property $daemonize
 * @property $maxRequest
 * @property $logFile
 * @property $pidFile
 * @property $backlog
 * @property $heartbeatCheckInterval
 * @property $heartbeatIdleTime
 * @property $dispatchMode
 * @property $openHttpProtocol
 * @property $openWebsocketProtocol
 * @property $openTcpNodelay
 * @property $enableStaticHandler
 * @property $documentRoot
 * @property $maxConnection
 * @property $bufferOutputSize
 * @property $socketBufferSize
 * @property $taskIpcMode
 * @property $taskMaxRequest
 * @property $taskTimeout
 * @property $sslCertFile
 * @property $sslKeyFile
 *
 * @method onStart(callable $callback)
 * @method onShutdown(callable $callback)
 * @method onWorkerStart(callable $callback)
 * @method onWorkerStop(callable $callback)
 * @method onMessage(callable $callback)
 * @method onTask(callable $callback)
 * @method onFinish(callable $callback)
 * @method onPipeMessage(callable $callback)
 * @method onWorkerError(callable $callback)
 * @method onClose(callable $callback)
 */
class Console 
{
    /** @var Console */
    private static $only;

    /** @var string */
    private $applicationNamespace;

    /** @var string */
    private $applicationPath;

    /** @var string */
    private $basePath;

    /** @var FastRouteDispatcherInterface */
    private $dispatcher;

    private $dispatcherGet;

    private $dispatcherPost;

    /** @var string */
    private $exceptionHandler;

    /** @var array */
    private $handlers;

    /** @var array */
    private $middlewares;

    private $server;

    /** @var array */
    private $serverSettings;

    private $serverEvents;

    /** @var callable */
    private $serverBeforeStart;

    private $responseFactory;

    private $requestFactory;

    /** @var string */
    protected string $handleRoute = 'SwooleBase\Foundation\Http\RouteHandler';

    /**
     * @return Console
     */
    final public static function instance(): Console
    {
        return self::$only;
    }

    /**
     * Dispatcher constructor.
     * @param string|null $basePath
     * @param string $applicationNamespace
     * @param string $applicationPath
     * @throws \Exception
     */
    final public function __construct(string $basePath = null, string $applicationNamespace = '', string $applicationPath = '')
    {
        if (self::$only) {
            throw new \Exception('construct failed');
        }

        if (!$basePath) {
            $basePath = ('cli' === php_sapi_name() || 'phpdbg' == php_sapi_name()) ? getcwd() : (getcwd().'/../');
        }

        $this->basePath = (string)(realpath($basePath));

        $this->applicationNamespace = empty($applicationNamespace) ? '' : ucfirst(trim($applicationNamespace, '\\ ') .'\\');

        if (!$applicationPath) {
            $this->applicationPath = $this->basePath .'/'. lcfirst(trim(str_replace('\\', '/', $this->applicationNamespace), '/ '));
        } else {
            $this->applicationPath = $applicationPath;
        }

        $this->exceptionHandler = null;
        $this->middlewares = [];

        $this->responseFactory = fn() => $this->createResponse();
        $this->requestFactory = fn ($httpRequest) => $this->createRequest($httpRequest);
        self::$only = $this;
    }

    /**
     * @param callable $callback
     * @return mixed
     * @throws Exception
     */
    final public function __invoke(callable $callback): mixed
    {
        if (!$this->server) {
            throw new Exception('Not swoole server to control.');
        }

        return $callback($this->server);
    }

    public function __set(string $name, $value): void
    {
        if (!in_array($name, ['worker_num', 'task_worker_num', 'daemonize', 'max_request', 'log_file', 'pid_file', 'backlog',
            'heartbeat_check_interval', 'heartbeat_idle_time', 'dispatch_mode', 'open_http_protocol', 'open_websocket_protocol',
            'open_tcp_nodelay', 'enable_static_handler', 'document_root', 'max_connection', 'buffer_output_size', 'socket_buffer_size',
            'task_ipc_mode', 'task_max_request', 'task_timeout', 'ssl_cert_file', 'ssl_key_file'])) {
            throw new Exception('swoole server does not have this setting: '.$name.'.');
        }

        $this->serverSettings[$name] = $value;
    }

    /**
     * @param array $settings
     * @return array
     */
    public function serverSettings(array $settings): array
    {
        if (!is_array($this->serverSettings) || empty($this->serverSettings)) {
            return $settings;
        }

        return array_merge($settings, $this->serverSettings);
    }

    public function __call(string $name, array $arguments)
    {
        if (!preg_match('/^on([A-Z][a-zA-Z0-9_]+)$/', $name, $matches)) {
            throw new Exception("$name is not a swoole server event call.");
        }

        $name = lcfirst($matches[1]);

        if (!in_array($name, ['start', 'shutdown', 'workerStart', 'workerStop', 'message', 'task', 'finish', 'pipeMessage', 'workerError', 'close'])) {
            throw new Exception("$name is not a swoole server event.");
        }

        $callback = array_shift($arguments);

        if (!is_callable($callback)) {
            throw new Exception('The '.$name.' event must have a callback set up.');
        }

        $this->serverEvents[$name] = $callback;
    }

    /**
     * @param callable $callback
     */
    public function beforeStart(callable $callback)
    {
        $this->serverBeforeStart = $callback;
    }

    /**
     * @param $server
     * @throws \Exception
     */
    public function onBeforeStart($server)
    {
        try {
            if (!$this->server) {
                throw new Exception('Not swoole server to control.');
            }

            if (is_array($this->serverEvents)) {
                foreach ($this->serverEvents as $event => $callback) {
                    $server->on($event, $callback);
                }
            }

            if (is_callable($this->serverBeforeStart)) {
                call_user_func_array($this->serverBeforeStart, [$this]);
            }
        } catch (Throwable $exception) {
            $this->exceptionHandleOrExit($exception);
        }
    }

    /**
     * @param $server
     * @return void
     */
    final public function swooleServer($server)
    {
        if (!$this->server) {
            $this->server = $server;
        }
    }

    /**
     * register an Foundation\Interfaces\Middleware interface array or a callback
     *
     * @param $middlewares
     */
    final public function middlewares($middlewares)
    {
        if (!is_array($middlewares)) {
            $this->middlewares[] = $middlewares;
        } else {
            $this->middlewares = array_merge($this->middlewares, $middlewares);
        }
    }

    /**
     * @param string $class
     * @return $this
     * @throws \Exception
     */
    final public function loadRoute(string $class): Console
    {
        try {
            if (class_exists($class) && is_subclass_of($class, Router::class)) {
                $name = preg_replace('/^.*\\\\/', '', $class);
                /*  convert to hyphen  */
                $name = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
                $router = new $class(new RouteCollector($name));
                unset($router);
            }

        } catch (Throwable $exception) {
            $this->exceptionHandleOrExit($exception);
        }

        return $this;
    }

    /**
     * @param object|null $argument
     * @param string $method
     */
    final public function defaultRequestHandling(object $argument = null, string $method = 'OPTIONS')
    {
        if ($method && is_callable($argument) && $argument instanceof Closure) {
            if (!$this->handlers || !is_array($this->handlers)) {
                $this->handlers = [$method => $argument];
            } else {
                $this->handlers[$method] = $argument;
            }
        }
    }

    /**
     * @param string $className
     */
    final public function exceptionHandler(string $className)
    {
        if (class_exists($className)) {
            $interfaces = class_implements($className);

            if (in_array(ExceptionHandler::class, $interfaces)) {
                $this->exceptionHandler = $className;
            }
        }
    }

    /**
     * @return string
     */
    final public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * @return string
     */
    final public function getApplicationPath(): string
    {
        return $this->applicationPath;
    }

    /**
     * @return string
     */
    final public function getApplicationNamespace(): string
    {
        return $this->applicationNamespace;
    }

    /**
     * @param string|null $path
     * @return string
     */
    public function basePath(?string $path = ''): string
    {
        return rtrim(sprintf('%s/%s', $this->getBasePath(), trim($path, '/ ')), '/');
    }

    /**
     * @param string|null $path
     * @return string
     */
    public function baseApplicationPath(?string $path = ''): string
    {
        return rtrim(sprintf('%s/%s', $this->getApplicationPath(), trim($path, '/ ')), '/');
    }

    /**
     * @param string $class
     * @return string
     */
    public function className(string $class): string
    {
        $className = sprintf('%s%s', $this->getApplicationNamespace(), trim($class, '\\ '));

        if (class_exists($className)) {
            return $className;
        }

        return $className. '\\';
    }

    /**
     * @param array $alias
     */
    final public function classAlias(array $alias)
    {
        array_walk($alias, 'class_alias');
    }

    /**
     * @param array $arguments
     * @return mixed
     * @throws \Exception
     */
    final public function accessible(array $arguments): mixed
    {
        try {
            $first = array_shift($arguments);

            if (!$first) {
                throw new Exception('too few arguments.');
            }

            if (!is_string($first)) {
                return $this->privateAccess((object)$first, $arguments);
            }

            return $this->staticAccess($first, $arguments);
        } catch (Throwable $exception) {
            $this->exceptionHandleOrExit($exception);
        }

        return null;
    }

    /**
     * @param $serializedFile
     * @throws \Exception
     */
    final public function terminal($serializedFile)
    {
        try {
            if (!file_exists($serializedFile)) {
                throw new Exception("$serializedFile not exists.");
            }

            if (!is_file($serializedFile)) {
                throw new Exception("$serializedFile not is file.");
            }

            if (!is_readable($serializedFile)) {
                throw new Exception("$serializedFile cannot read.");
            }

            $di = new DI();

            $serializedData = file_get_contents($serializedFile);
            $result = unserialize($serializedData);

            if (false === $result && $serializedData !== serialize(false)) {
                // Check also the case serialized to 'b:0;'
                throw new Exception('Unable to deserialize data. File: '.$serializedFile);
            }

            if ($result instanceof BackgroundWorker) {
                $result->call($di);
            }

        } catch (Throwable $exception) {
            $this->exceptionHandleOrExit($exception);
        }
    }

    /**
     * @return array
     */
    public function createResponse(): array
    {
        $response = new Response();
        $response->setHeader('micro-time', microtime());

        if ($response instanceof ResponseInterface) {
            return [ResponseInterface::class, Response::class, $response];
        }

        return [null, Response::class, $response];
    }

    /**
     * @param $httpRequest
     * @return array
     */
    public function createRequest($httpRequest): array
    {
        [$symfonyRequest, $fd] = Request::createRequest($httpRequest);
        return [Request::class, new Request($symfonyRequest, $fd)];
    }

    /**
     * @param $httpRequest
     * @return mixed
     * @throws \Exception
     */
    final public function dispatch($httpRequest): mixed
    {
        $result = null;
        [$responseInterface, $responseClass, $response] = call_user_func($this->responseFactory);

        try {
            $this->createDispatcher();
            [$requestClass, $request] = call_user_func_array($this->requestFactory, [$httpRequest]);
            $di = new DI([$responseClass => $response, $requestClass => $request]);

            if ($responseInterface) {
                $di->instance($responseInterface, $response);
            }

            if ($this->middlewares && is_array($this->middlewares)) {
                foreach ($this->middlewares as $middleware) {
                    $di->middleware($middleware);
                }
            }

            $method = $request->getMethod();
            $requestUri = $request->getPathInfo();
            $result = $this->findRoute($method, $requestUri);

            if ($result && class_exists($this->handleRoute) && is_subclass_of($this->handleRoute, RouteHandlerInterface::class)) {
                $routeHandler = new $this->handleRoute($result, $requestClass, $request, $responseClass, $response, $di);

                foreach ($routeHandler->getMiddleware() as $middleware) {
                    [$className, $arguments] = $di->gatherMiddlewareClassNameWithArguments($middleware);
                    $di->middleware($className, $arguments);
                }

                $result = $routeHandler->handle();

                if (false === $result && $response instanceof ResponseInterface) {
                    $response->setStatusCode(ResponseInterface::SERVICE_UNAVAILABLE); // 503
                    $response->setHeader('Retry-After', 3600);
                    $response->setContent('handle fail');
                    $result = $response;
                }

                unset($routeHandler);
            }

            unset($middlewareHandler);
            unset($request);
            unset($di);
        } catch (Throwable $exception) {
            $result = $this->exceptionHandleOrExit($exception);
        }

        return $result;
    }

    /**
     * @throws \Exception
     */
    public function run()
    {
        $response = $this->dispatch(null);

        if ($response instanceof SymfonyResponse) {
            $response->send();
        }

        if (is_scalar($response)) {
            echo $response;
        }
    }

    /**
     * @throws Exception|\Exception
     */
    private function createDispatcher()
    {
        if ($this->dispatcher instanceof FastRouteDispatcherInterface ||
            $this->dispatcherGet instanceof FastRouteDispatcherInterface ||
            $this->dispatcherPost instanceof FastRouteDispatcherInterface
        ) {
            return;
        }

        $routesRegistered = $this->accessible([RouteCollector::class, '$routesRegistered']);

        /*
         * action for OPTIONS method with any uri
         */
        if (is_array($this->handlers)) {
            foreach ($this->handlers as $method => $handler) {
                if (!isset($routesRegistered[$method.RouteCollector::ANY])) {
                    $this->accessible([RouteCollector::class, 'dataGenerator', $method, RouteCollector::ANY, ['uses' => $handler]]);
                }
             }
         }

        if (empty($this->accessible([RouteCollector::class, '$routesRegistered']))) {
            throw new Exception('No route has been determined yet!');
        }

        $dataGenerator = $this->accessible([RouteCollector::class, '$dataGenerator']);
        $dgGet = $this->accessible([RouteCollector::class, '$dataGeneratorGet']);
        $dgPost = $this->accessible([RouteCollector::class, '$dataGeneratorPost']);

        if (!($dataGenerator instanceof DataGenerator) && !($dgGet instanceof DataGenerator) && !($dgPost instanceof DataGenerator)) {
            throw new Exception('Failed to generate route data');
        }

        if ($dataGenerator) {
            $this->dispatcher = new FastRouteDispatcher($dataGenerator->getData());
        }

        if ($dgGet) {
            $this->dispatcherGet = new FastRouteDispatcher($dgGet->getData());
        }

        if ($dgPost) {
            $this->dispatcherPost = new FastRouteDispatcher($dgPost->getData());
        }
    }

    /**
     * Handle the response from the FastRoute dispatcher.
     *
     * @param string $method
     * @param string $uri
     * @return array
     * @throws Exception
     */
    private function findRoute(string $method, string $uri): array
    {
        $result = match (strtoupper($method)) {
            'GET','DELETE' => $this->dispatcherGet->dispatch($method, $uri),
            'POST','PUT' => $this->dispatcherPost->dispatch($method, $uri),
            default => $this->dispatcher->dispatch($method, $uri)
        };
        
        $dispatcher = array_shift($result);

        if (FastRouteDispatcherInterface::FOUND !== $dispatcher) {
            if (FastRouteDispatcherInterface::NOT_FOUND === $dispatcher) {
                throw new Exception('Not Found', ['status_code' => 404, sprintf('The URI "%s" was not found', $uri)]);
            }

            if (FastRouteDispatcherInterface::METHOD_NOT_ALLOWED === $dispatcher) {
                if (1 === count($result) && 1 === count($result[0]) && 'OPTIONS' === $result[0][0]) {
                    throw new Exception('Route not found', ['status_code' => 404]);
                }

                throw new Exception('Method Not Allowed', ['status_code' => 405, sprintf('Method "%s" is not in list [%s]', $method, implode(', ', $result))]);
            }

            throw new Exception('Internal Server Error', ['status_code' => 500, '#fast-route-dispatcher', $method, $uri, date('Y-m-d H:i')]);
        }

        return $result;
    }

    /**
     * @param object $obj
     * @param array $arguments
     * @return mixed
     * @throws Exception
     * @throws ReflectionException
     */
    private function privateAccess(object $obj, array $arguments): mixed
    {
        $name = array_shift($arguments);

        if (!is_string($name)) {
            throw new Exception('Property name or method name must be string');
        }

        if (str_starts_with($name, '$') && property_exists($obj, $name = substr($name, 1))) {
            $property = (new ReflectionClass($obj))->getProperty($name);

            if ($arguments) {
                $property->setValue($obj, array_shift($arguments));
                return true;
            }

            return $property->getValue($obj);
        }

        if (method_exists($obj, $name)) {
            $reflectionClass = new ReflectionClass($obj);
            $method = $reflectionClass->getMethod($name);

            if ($arguments) {
                return $method->invokeArgs($obj, $arguments);
            }

            return $method->invoke($obj);
        }

        return null;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     * @throws ReflectionException
     * @throws Exception
     */
    private function staticAccess(string $name, array $arguments): mixed
    {
        if (!class_exists((string)$name)) {
            throw new Exception("Class '$name' not exists");
        }

        $static = array_shift($arguments);
        $reflectionClass = new ReflectionClass($name);

        if (!$static) {
            throw new Exception(sprintf('Error: %s::%s is not a static property or static method', $name, (string)$static));
        }

        if (str_starts_with($static, '$')) {
            $property = $reflectionClass->getProperty(substr($static, 1));

            if (!$property->isStatic()) {
                throw new Exception("Cannot access non-static property $name::$static");
            }

            return !$arguments ? $property->getValue() : ($property->setValue(array_shift($arguments)) ?? null);
        }

        $method = $reflectionClass->getMethod($static);

        if (!$method->isStatic()) {
            throw new Exception("Cannot access non-static method $name::$static");
        }

        if (0 === $method->getNumberOfParameters()) {
            return $method->invoke(null);
        }

        return !$arguments ? function(...$args) use ($method) {
            return $method->invokeArgs(null, $args);
        } : $method->invokeArgs(null, $arguments);
    }

    /**
     * @param Throwable $exception
     * @return mixed
     * @throws \Exception
     */
    private function exceptionHandleOrExit(Throwable $exception): mixed
    {
        $exception = $this->getException($exception);

        if ($exception instanceof Throwable) {
            $errors = ($exception instanceof  Exception) ? json_encode($exception->getErrors()) : null;

            if (defined('SWOOLE_VERSION')) {
                /** @var ResponseInterface $response */
                [,$response] = $this->createResponse();
                $response->setHeader('cache-control', ['no-cache, private']);
                $response->setHeader('date', [(new \DateTime('now', new \DateTimeZone('GMT')))->format('D, d M Y H:i:s \G\M\T')]);
                $response->setContent("<title>SWOOLE Error {$exception->getCode()}</title>" .
                    "<h3 style='color: red'>{$exception->getMessage()}</h3>" .
                    "<pre>{$exception->getFile()}:{$exception->getLine()}\n{$exception->getTraceAsString()}</pre>" .
                    "<div>{$errors}</div>");

                return $response;
            }

            if ('cli' === php_sapi_name() || 'phpdbg' === php_sapi_name()) {
                echo "\033[31m{$exception->getMessage()}\n{$exception->getFile()}: {$exception->getLine()}\033[0m\n\n{$exception->getTraceAsString()}";
                exit();
            }

            echo "<title>Error {$exception->getCode()}</title><h3 style='color: red'>{$exception->getMessage()}</h3>" .
                "<pre>{$exception->getFile()}:{$exception->getLine()}\n{$exception->getTraceAsString()}</pre>" .
                "<div>{$errors}</div>";
            exit();
        }

        return $exception;
    }

    /**
     * @param Throwable $exception
     * @return mixed
     */
    private function getException(Throwable $exception): mixed
    {
        if ($this->exceptionHandler) {
            return (new $this->exceptionHandler($exception))->handler();
        }

        /** @see ExceptionHandler */
        if ($exception instanceof ExceptionHandler) {
            return $exception->handler();
        }

        return $exception;
    }
}