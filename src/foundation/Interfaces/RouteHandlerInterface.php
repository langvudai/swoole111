<?php

namespace SwooleBase\Foundation\Interfaces;

use SwooleBase\Foundation\DI;

interface RouteHandlerInterface
{
    public function __construct(array $routeFound, string $requestClass, object $request, string $responseClass, object $response, DI $di);

    public function getMiddleware(): array;

    public function handle(): mixed;
}
