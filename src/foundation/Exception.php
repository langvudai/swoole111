<?php

namespace SwooleBase\Foundation;

use JetBrains\PhpStorm\Pure;
use RuntimeException;
use SwooleBase\Foundation\Interfaces\ExceptionHandler;
use Throwable;

class Exception extends RuntimeException implements ExceptionHandler
{
    /** @var string */
    private $id;

    /** @var array */
    protected $errors;

    /** @var string */
    protected $baseMessage;

    /**
     * Exception constructor.
     * @param string $message
     * @param array $errors
     * @param Throwable|null $previous
     */
    #[Pure]
    public function __construct(string $message, array $errors = [], Throwable $previous = null)
    {
        $this->errors = $errors;
        $this->baseMessage = $message;

        $this->id = uniqid('#'.microtime(true), true);
        $message = sprintf('[%s] %s', $this->id, $this->baseMessage);
        $code = $errors['code'] ?? 0;

        parent::__construct($message,is_scalar($code) ? (int)$code : 0, $previous);
    }

    /**
     * @return Throwable|null
     */
    public function handler(): ?Throwable
    {
        return $this;
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getBaseMessage(): string
    {
        return $this->baseMessage;
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        $statusCode = $this->errors['status_code'] ?? null;
        return is_scalar($statusCode) ? (int)$statusCode : 0;
    }
}
