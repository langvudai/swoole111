<?php 

namespace SwooleBase\Foundation;

use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionType;
use SwooleBase\Foundation\Interfaces\IsStronglyClass;

/**
 * Dependency Injection
 * @package Source
 */
final class DI
{
    /** @var array */
    private static $bindings = [];

    /** @var mixed */
    private $factory;

    /** @var array */
    private $instances;

    /** @var array */
    private $middlewareDone = [];

    /** @var array */
    private static $middlewareAlias = [];

    /**
     * @param array $alias
     */
    public static function middlewareAlias(array $alias)
    {
        if (empty(self::$middlewareAlias)) {
            self::$middlewareAlias = $alias;
        }
    }

    public function __construct($instances = [])
    {
        $this->instances = $instances;
    }

    /**
     * Gather the full class names for the middleware short-cut string.
     * Gather argument array if is injected
     *
     * @param string $name
     * @return array
     */
    public function gatherMiddlewareClassNameWithArguments(string $name): array
    {
        $name = trim($name);

        if (empty($name)) {
            return [null, null];
        }

        [$name, $parameters] = array_pad(explode(':', $name, 2), 2, null);
        return [self::$middlewareAlias[$name] ?? $name, explode(',', $parameters ?? '')];
    }

    /**
     * @param $middleware
     * @param array|null $arguments
     * @throws ReflectionException
     */
    public function middleware($middleware, array $arguments = null)
    {
        if (is_callable($middleware)) {
            $this->callbackHandler($middleware, $arguments);
        } elseif (is_string($middleware) && !in_array($middleware, $this->middlewareDone)) {
            if (false !== $this->objectHandler($middleware, $arguments)) {
                $this->middlewareDone[] = $middleware;
            }
        }
    }

    /**
     * Register an existing instance into the Dependency Injection
     * Returns true if the new registration is successful
     * 
     * @param string $abstract
     * @param object $object
     * @param bool $replace
     * @return bool
     */
    public function instance(string $abstract, object $object, bool $replace = false): bool
    {
        if (!isset($this->instances[$abstract]) || $replace) {
            $this->instances[$abstract] = $object;
            return (bool)$this->instances[$abstract];
        }

        return false;
    }

    /**
     * @param string $abstract
     * @param callable|string|null $concrete
     * @param bool $replace
     * @return mixed 
     */
    public function bind(string $abstract, callable|string $concrete = null, bool $replace = false): mixed
    {
        if ($concrete && ($replace || !isset(self::$bindings[$abstract]))) {
            self::$bindings[$abstract] = is_callable($concrete) ? call_user_func($concrete) : $concrete;
        }

        return self::$bindings[$abstract];
    }

    /**
     * @param mixed $factory
     */
    public function setFactory($factory): void
    {
        $this->factory = $factory;
    }

    /**
     * @param string $abstract
     * @param array $arguments
     * @return mixed
     * @throws Exception
     * @throws ReflectionException
     */
    public function makeArgs(string $abstract, array $arguments): mixed
    {        
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $resolved = $this->resolve($abstract, $arguments);

        if ($resolved instanceof IsStronglyClass) {
            $this->assertObject($resolved);
        }

        return $resolved;
    }

    /**
     * @param string $abstract
     * @return mixed
     * @throws Exception
     * @throws ReflectionException
     */
    public function make(string $abstract): mixed
    {
        if (DI::class === $abstract) {
            return $this;
        }

        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $resolved = $this->resolve($abstract);

        if ($resolved instanceof IsStronglyClass) {
            $this->assertObject($resolved);
        }

        return $resolved;
    }

    /**
     * @param string $abstract
     * @param array|null $arguments
     * @return mixed
     * @throws ReflectionException|Exception
     */
    public function __invoke(string $abstract, array $arguments = null): mixed
    {
        return null === $arguments ? $this->make($abstract) : $this->makeArgs($abstract, $arguments);
    }

    /**
     * @param string $abstract
     * @param array|null $arguments
     * @return mixed
     * @throws ReflectionException
     * @throws Exception
     */
    private function resolve(string $abstract, array $arguments = null): mixed
    {
        $abstract = $this->getBinding($abstract);
        $reflector = new ReflectionClass($abstract);

        if (!$reflector->isInstantiable()) {
            throw new Exception("Class $abstract is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $abstract;
        }

        $parameters = $constructor->getParameters();

        $dependencies = $this->getDependencies($abstract, $parameters, $arguments);

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * @param string $abstract
     * @return string
     */
    private function getBinding(string $abstract): string
    {
        if (isset($this->factory) && is_scalar($this->factory) && isset(self::$bindings[$abstract][$this->factory])) {
            $class = self::$bindings[$abstract][$this->factory];
        } elseif (isset(self::$bindings[$abstract])) {
            $class = self::$bindings[$abstract];
        }

        if (!isset($class) || (!is_string($class) && !is_object($class))) {
            return $abstract;
        }

        return $class;
    }

    /**
     * @param string $abstract
     * @param array $parameters
     * @param array|null $arguments
     * @return array
     * @throws Exception|ReflectionException
     */
    private function getDependencies(string $abstract, array $parameters, array $arguments = null): array
    {
        $dependencies = [];

        /** @var ReflectionParameter $parameter */
        foreach ($parameters as $parameter) {
            if (isset($arguments[$parameter->getName()])) {
                $dependencies[] = $arguments[$parameter->getName()];
                continue;
            }

            $dependency = $parameter->getType();

            if ($dependency === null) {
                throw new Exception("Could not resolve dependency for parameter $parameter->name while instantiating $abstract");
            }

            /** @var ReflectionType $className */
            $className = trim("$dependency", '?');

            if (class_exists($className) || interface_exists($className)) {
                if ($arguments) {
                    $dependencies[] = $this->makeArgs($className, $arguments);
                } else {
                    $dependencies[] = $this->make($className);
                }
            } elseif ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
            } elseif ($dependency->allowsNull()) {
                $dependencies[] = null;
            }
        }

        return $dependencies;
    }

    /**
     * @param object $obj
     */
    private function assertObject(object $obj)
    {
        $asserts = $obj->assert();

        if (empty($asserts)) {
            return;
        }

        $ref = new ReflectionClass($obj);
        $methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);
        $errorList = [];

        foreach ($methods as $method) {
            $name = $method->getName();

            if ($method->isConstructor() || (isset($asserts[$name]) && false === $asserts[$name])) {
                continue;
            }

            $errors = [];

            if (!$method->hasReturnType()) {
                $errors[] = sprintf('The %s method must return a defined data type.', $name);
            }

            if ($method->getNumberOfParameters()) {
                foreach ($method->getParameters() as $parameter) {
                    $param = $parameter->getName();
                    if (!$parameter->hasType()) {
                        $errors[] = sprintf('The %s parameter of the %s method must have a defined data type', $param, $name);
                        continue;
                    }

                    $type = $parameter->getType();

                    if (isset($asserts[$name]) && is_string($asserts[$name]) && !is_subclass_of("$type", $asserts[$name])) {
                        $errors[] = sprintf('The %s parameter of the %s method must be an instance of %s.', $param, $name, $asserts[$name]);
                        continue;
                    }

                    if (isset($asserts[$name][$param]) && is_string($asserts[$name][$param]) && !is_subclass_of("$type", $asserts[$name][$param])) {
                        $errors[] = sprintf('The %s parameter of the %s method must be an instance of %s.', $param, $name, $asserts[$name][$param]);
                    }
                }
            }

            if ($errors) {
                $errorList = array_merge($errorList, $errors);
            }
        }

        if ($errorList) {
            $str = get_class($obj);
            throw new Exception("There is an error when checking the public methods of the class: $str.", ['key' => 'CLASS_INVALID', $str, $errorList]);
        }
    }

    /**
     * @param $middleware
     * @param array|null $arguments
     * @return void
     * @throws ReflectionException
     */
    private function callbackHandler($middleware, array $arguments = null)
    {
        $ref = new ReflectionFunction($middleware);
        $number = $ref->getNumberOfParameters();

        if (0 === $number) {
            $ref->invoke();
        } else {

            $arguments = $this->getArgs($ref->getParameters(), $arguments ?? []);

            if ($number > count($arguments)) {
                throw new Exception(sprintf('Too few parameters passed to the function: %s:%d', $ref->getFileName(), $ref->getStartLine()));
            }
        }
    }

    /**
     * @param $middleware
     * @param array|null $arguments
     * @return mixed
     * @throws ReflectionException
     */
    private function objectHandler($middleware, array $arguments = null): mixed
    {
        if (class_exists($middleware)) {
            $obj = $this->make($middleware);

            if ($obj) {
                if (method_exists($obj, 'handle')) {
                    $ref = new ReflectionMethod($obj, 'handle');
                    $number = $ref->getNumberOfParameters();
                    if (0 === $number) {
                        return $ref->invoke($obj);
                    }

                    $arguments = $this->getArgs($ref->getParameters(), $arguments ?? []);

                    if ($number > count($arguments)) {
                        throw new Exception(sprintf('Too few parameters passed to the function: %s::handle', get_class($obj)));
                    }

                    return $ref->invokeArgs($obj, $arguments);
                }

                return $obj;
            }
        }

        return false;
    }

    /**
     * @param array $parameters
     * @param array $values
     * @return array
     * @throws ReflectionException
     */
    private function getArgs(array $parameters, array $values): array
    {
        $arguments = [];

        /** @var ReflectionParameter $parameter */
        foreach ($parameters as $parameter) {
            $typeName = (string)$parameter->getType();

            if (DI::class === $typeName) {
                $arguments[] = $this;
            } elseif (class_exists($typeName) || interface_exists($typeName)) {
                $arguments[] = $this->make($typeName);
            } elseif (!empty($values)) {
                $arguments[] = array_shift($values);
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } elseif ($parameter->allowsNull()) {
                $arguments[] = null;
            }
        }

        return $arguments;
    }
}
