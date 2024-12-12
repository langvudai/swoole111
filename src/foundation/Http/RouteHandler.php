<?php

namespace SwooleBase\Foundation\Http;

use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use SwooleBase\Foundation\DI;
use SwooleBase\Foundation\Interfaces\HasResponse;
use SwooleBase\Foundation\Interfaces\ResponseInterface;
use SwooleBase\Foundation\Interfaces\RouteHandlerInterface;
use SwooleBase\Foundation\Exception;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

class RouteHandler implements RouteHandlerInterface
{
    /** @var null|array */
    private $param;

    /** @var string|callable */
    private $uses;

    /** @var array */
    private $middleware;

    /**
     * controller interface
     * @var string
     */
    protected static string $CI;

    /**
     * RouteHandler constructor.
     * @param array $routeFound
     * @param string $requestClass
     * @param object $request
     * @param string $responseClass
     * @param object $response
     * @param DI $di
     */
    final public function __construct(array $routeFound, private string $requestClass, private object $request, private string $responseClass, private object $response, private DI $di)
    {
        $this->parseIncomingRoute($routeFound);
    }

    /**
     * @return array
     */
    public function getMiddleware(): array
    {
        return $this->middleware && is_array($this->middleware) ? $this->middleware : [];
    }

    /**
     * @return mixed
     * @throws ReflectionException|Exception
     */
    public function handle(): mixed
    {
        if (!$this->uses) {
            return false;
        }

        if (is_callable($this->uses) && (($this->uses instanceof \Closure) || is_string($this->uses))) {

            $result = $this->callUserFunction();

            if (false !== $result) {
                return $this->parseResult($result);
            }

            return false;
        }

        [$controller, $method] = $this->uses;

        if (!empty(self::$CI) && !is_subclass_of($controller, self::$CI)) {
            throw new Exception('Controller invalid: '.$controller, ['key' => 'CONTROLLER_INVALID', $controller, self::$CI]);
        }

        if (method_exists($controller, '__invoke')) {
            $controller = $this->di->make($controller);
            return $this->parseResult($controller($method, $this, $this->param, $this->request, $this->response, $this->di));
        }

        $reflectionMethod = $this->prepareMethod($controller, $method);

        if (!$reflectionMethod) {
            throw new Exception("Method $controller::$method not exists.");
        }

        $controller = $this->di->make($controller);
        $result = $this->invokeMethod($reflectionMethod, $controller);

        if (false !== $result) {
            return $this->parseResult($result);
        }

        return false;
    }

    /**
     * @param array|ReflectionParameter[] $parameters
     * @return array
     * @throws ReflectionException|Exception
     */
    public function prepareArgs(array $parameters): array
    {
        $arguments = [];
        /** @var ReflectionParameter $parameter */
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (isset($this->param[$name])) {
                $arguments[] = $this->param[$name];
            } else {
                $typeName = (string)$parameter->getType();

                if (DI::class === $typeName) {
                    $arguments[] = $this->di;
                } elseif ($typeName === $this->requestClass) {
                    $arguments[] = $this->request;
                } elseif ($typeName === $this->responseClass) {
                    $arguments[] = $this->response;
                } elseif (class_exists($typeName) || interface_exists($typeName)) {
                    $arguments[] = $this->di->make($typeName);
                } else {
                    $arguments[] = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
                }
            }
        }

        return $arguments;
    }

    /**
     * @param $result
     * @return mixed
     */
    private function parseResult($result)
    {
        if ($this->response instanceof ResponseInterface) {
            if ($result instanceof JsonResponse) {
                $this->response->merge($result);
                return $this->response;
            }

            if ($result instanceof BinaryFileResponse) {
                $this->response->merge($result);
                return $this->response;
            }

            if ($result instanceof HasResponse) {
                $result->respond($this->response);

                return $this->response;
            }

            if (is_string($result)) {
                $this->response->setContent($result);
                return $this->response;
            }
        }

        return $result;
    }

    /**
     * @return mixed
     * @throws ReflectionException|Exception
     */
    private function callUserFunction(): mixed
    {
        $reflectionFunction = new ReflectionFunction($this->uses);

        if (0 === $reflectionFunction->getNumberOfParameters()) {
            return $reflectionFunction->invoke();
        }

        $parameters = $reflectionFunction->getParameters();

        return $reflectionFunction->invokeArgs($this->prepareArgs($parameters));
    }

    /**
     * @param $class
     * @param $method
     * @return ReflectionMethod|null
     * @throws ReflectionException
     */
    private function prepareMethod($class, $method): ?ReflectionMethod
    {
        if (!method_exists($class, $method)) {
            return null;
        }

        $reflectionMethod = new ReflectionMethod($class, $method);

        if (!$reflectionMethod->isPublic()) {
            return null;
        }

        return $reflectionMethod;
    }

    /**
     * @param ReflectionMethod $reflectionMethod
     * @param object $controller
     * @return mixed
     * @throws ReflectionException|Exception
     */
    private function invokeMethod(ReflectionMethod $reflectionMethod, object $controller): mixed
    {
        if (0 === $reflectionMethod->getNumberOfParameters()) {
            return $reflectionMethod->invoke($controller);
        }

        $parameters = $reflectionMethod->getParameters();

        return $reflectionMethod->invokeArgs($controller, $this->prepareArgs($parameters));
    }

    /**
     * @param array $routeFound
     */
    private function parseIncomingRoute(array $routeFound)
    {
        $this->param = $routeFound[1] ?? null;
        $action = !isset($routeFound[0]) || !is_array($routeFound[0]) ? [] : $routeFound[0];
        $this->middleware = isset($action['middleware']) ? (array) $action['middleware'] : [];
        $this->uses = $action['uses'];
    }

}
