<?php

namespace SwooleBase\Foundation\Abstracts;

use Carbon\Carbon;
use RuntimeException;
use SwooleBase\Foundation\DI;
use SwooleBase\Foundation\Interfaces\Validator as ValidatorInterface;
use SwooleBase\Foundation\Http\Request;
use SwooleBase\Foundation\JsonResponseException;
use function SwooleBase\Foundation\console;

/**
 * Class Validator
 * 
 * @package Foundation\Abstracts
 */
abstract class Validator implements ValidatorInterface
{
    /** @var array */
    private $all;

    /** @var array */
    private $attributes;

    /** @var array */
    private $carbonCache;

    /** @var array */
    private $carbonCacheValidated;

    /** @var array */
    private $errors;

    /** @var null|bool */
    private $passed;

    /** @var Request */
    private $request;

    /** @var array */
    private $validated;

    private static $errMessages = [];

    /**
     * Validator constructor.
     * @param Request $request
     * @param DI $DI
     */
    public function __construct(Request $request, private DI $DI)
    {
        if (!$this->authorize()) {
            throw new RuntimeException('Unauthenticated');
        }

        if (!self::$errMessages) {
            $file = realpath(console()->basePath('resource/lang/vi/validation.php'));

            if ($file && file_exists($file) && is_readable($file)) {
                self::$errMessages = require_once $file;
            }
        }

        $this->request = $request;
        $this->attributes = $this->attributes();
        $all = $this->request->getAll();

        array_walk_recursive($all, function(&$v) {
            if (is_string($v)) {
                $v = trim($v);
            }
        });

        $this->all = $this->prepareInput($all);
        $this->carbonCache = [];
        $this->carbonCacheValidated = [];
        $this->passed = null;
        $this->validated = [];


    }

    /**
     * @return array
     */
    public static function carbonFields(): array
    {
        return [];
    }

    /**
     * @param array $data
     */
    final public function setInput(array $data)
    {
        $this->all = $data;
    }

    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        if (isset($this->validated[$name])) {
            if (isset($this->carbonCacheValidated[$name])) {
                return $this->carbonCacheValidated[$name];
            }

            if (in_array($name, self::carbonFields(), true)) {
                $this->carbonCacheValidated[$name] = $this->toCarbon($this->validated[$name]);
                return $this->carbonCacheValidated[$name];
            }

            return $this->validated[$name];
        }

        if (isset($this->all[$name])) {
            if (isset($this->carbonCache[$name])) {
                return $this->carbonCache[$name];
            }

            if (in_array($name, self::carbonFields(), true)) {
                $this->carbonCache[$name] = $this->toCarbon($this->all[$name]);
                return $this->carbonCache[$name];
            }

            return $this->all[$name];
        }

        return null;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function empty(string $name): bool
    {
        return empty($this->__get($name));
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $keys = array_keys($this->rules([]));
        $all = array_replace($this->all, $this->carbonCache, $this->carbonCacheValidated);
        $all = array_intersect_key($all, array_combine($keys, $keys));

        return array_replace(array_fill_keys($keys, null), $all);
    }

    /**
     * @param array $input
     * @return array
     */
    public function prepareInput(array $input): array
    {
        return $input;
    }

    /**
     * Get attribute list
     *
     * @return array
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Return json if validator failed
     */
    public function throwJsonIfFailed()
    {
        if ($this->fails()) {
            throw new JsonResponseException(symfony_json_response(false, [], $this->errors));
        }
    }

    /**
     * @param array $filter
     * @return array
     */
    public function validated(array $filter = []): array
    {
        $this->fails();

        if (0 === count($filter)) {
            return $this->validated;
        }

        return array_intersect_key($this->validated, array_combine($filter, $filter));
    }

    /**
     * @return bool
     */
    private function fails(): bool
    {
        if (null === $this->passed) {
            $validated = [];

            foreach ($this->rules($this->all) as $field => $rules) {
                if (!is_array($rules)) {
                    throw new JsonResponseException(symfony_json_response(false, [], 'validation rule must is array.'));
                }

                $value = $this->getValue($field);

                foreach ($rules as $rule ) {
                    if (is_callable($rule)) {
                        $result = call_user_func_array($rule, [$value, $this->DI]);

                        [$passed, $error] = array_pad(!is_array($result) ? [] : $result, 2, null);

                        if ($passed) {
                            $validated[$field] = $value;
                        } elseif (is_string($error)) {
                            $this->handleError($field, $error);
                        }

                        continue;
                    }

                    [$pattern, $messageKey] = !is_array($rule) ? [null, null] : array_pad($rule, 2, null);

                    if (!is_string($pattern) || false === preg_match($pattern, '')) {
                        throw new JsonResponseException(symfony_json_response(false, [], 'validation rule must is pattern regex.'));
                    }

                    if (!preg_match($pattern, $value)) {
                        if (is_string($messageKey)) {
                            $this->handleError($field, $messageKey);
                        }
                    } else {
                        $validated[$field] = $value;
                    }
                }
            }

            if (!empty($this->errors)) {
                $this->passed = false;
            } else {
                $this->validated = $this->flattenValidated($validated);
                $this->passed = true;
            }
        }

        return !$this->passed;
    }

    /**
     * @param array $array
     * @return mixed
     */
    private function flattenValidated(array $array): mixed
    {
        $return = [];

        foreach ($array as $key => $value) {
            $keys = explode('.', $key);
            $ref = &$return;

            foreach ($keys as $segment) {
                if (!isset($ref[$segment])) {
                    $ref[$segment] = [];
                }
                $ref = &$ref[$segment];
            }

            $ref = $value;
        }

        return $return;
    }

    /**
     * @param string $field
     * @return mixed
     */
    private function getValue(string $field): mixed
    {
        $keys = explode('.', $field);
        $value = $this->all;

        foreach ($keys as $key) {
            if (array_key_exists($key, $value)) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param string $field
     * @param string|null $messageKey
     */
    protected function handleError(string $field, string $messageKey = null)
    {
        $name = $this->attributes[$field] ?? $field;

        if (isset(self::$errMessages[$messageKey])) {
            $this->errors[$field][] = str_replace(':attribute', $name, self::$errMessages[$messageKey]);
        } else {
            $this->errors[$field][] = str_replace(':attribute', $name, $messageKey);
        }
    }

    /**
     * @param $value
     * @return Carbon|null
     */
    private function toCarbon($value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array
     */
    public function all(): array
    {
        return $this->all;
    }

    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->get($key, $default);
    }

}
