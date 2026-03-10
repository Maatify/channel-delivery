<?php

declare(strict_types=1);

namespace Maatify\ChannelDelivery\Http\Handler;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthCheckHandler
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        try {
            $this->pdo->query('SELECT 1');
            $db = 'ok';
        } catch (\Throwable) {
            $db = 'error';
        }

        $payload = json_encode([
            'status' => $db === 'ok' ? 'ok' : 'degraded',
            'db'     => $db,
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($db === 'ok' ? 200 : 503);
    }
}
