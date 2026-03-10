<?php

declare(strict_types=1);

use Maatify\ChannelDelivery\ApiKey\ApiKeyRepository;
use Maatify\ChannelDelivery\Http\Middleware\ApiKeyMiddleware;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

use function DI\autowire;
use function DI\get;

return [

    ResponseFactoryInterface::class => autowire(ResponseFactory::class),

    ApiKeyRepository::class => autowire(ApiKeyRepository::class),

    ApiKeyMiddleware::class => autowire(ApiKeyMiddleware::class),

];