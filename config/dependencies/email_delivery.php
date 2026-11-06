<?php

declare(strict_types=1);

use Maatify\ChannelDelivery\Email\AtomicEmailConnectionFactory;
use Maatify\ChannelDelivery\Email\AtomicEmailGateway;
use Maatify\ChannelDelivery\Email\ConsumerNamespaceRepositoryInterface;
use Maatify\ChannelDelivery\Email\PdoConsumerNamespaceRepository;
use Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface;
use Maatify\EmailDelivery\Queue\EmailEnqueueReceiptReaderInterface;
use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use Maatify\EmailDelivery\Queue\EmailQueueWriterInterface;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use Maatify\EmailDelivery\Renderer\EmailRendererInterface;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Transport\SmtpEmailTransport;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;

use function DI\autowire;
use function DI\factory;
use function DI\get;

return [

    // ── SMTP Config ───────────────────────────────────────────
    EmailTransportConfigDTO::class   => factory(function (): EmailTransportConfigDTO {
        return new EmailTransportConfigDTO(
            host          : (string)$_ENV['MAIL_HOST'],
            port          : (int)$_ENV['MAIL_PORT'],
            username      : (string)$_ENV['MAIL_USERNAME'],
            password      : (string)$_ENV['MAIL_PASSWORD'],
            fromAddress   : (string)$_ENV['MAIL_FROM_ADDRESS'],
            fromName      : (string)$_ENV['MAIL_FROM_NAME'],
            encryption    : $_ENV['MAIL_ENCRYPTION'] ?? null,
            timeoutSeconds: (int)($_ENV['MAIL_TIMEOUT'] ?? 10),
            debugLevel    : (int)($_ENV['MAIL_DEBUG_LEVEL'] ?? 0),
        );
    }),

    // ── Transport ─────────────────────────────────────────────
    SmtpEmailTransport::class        => autowire(SmtpEmailTransport::class),
    EmailTransportInterface::class   => get(SmtpEmailTransport::class),

    // ── Renderer ──────────────────────────────────────────────
    // APP_NAME and SUPPORT_EMAIL are injected as Twig globals
    // so templates can use {{ app_name }} and {{ support_email }}
    // without requiring the caller to pass them in the payload context.
    TwigEmailRenderer::class         => factory(function (): TwigEmailRenderer {
        return new TwigEmailRenderer(
            templatesPath: dirname(__DIR__, 2) . '/templates',
            globals    : [
                'app_name'      => (string)($_ENV['APP_NAME'] ?? 'App'),
                'support_email' => (string)($_ENV['SUPPORT_EMAIL'] ?? ''),
            ]
        );
    }),
    EmailRendererInterface::class    => get(TwigEmailRenderer::class),

    // ── Queue Writer ──────────────────────────────────────────
    PdoEmailQueueWriter::class       => autowire(PdoEmailQueueWriter::class),
    EmailQueueWriterInterface::class => get(PdoEmailQueueWriter::class),

    // v2 owns a separate UTC session. Never change the clock/transaction of legacy PDO::class.
    AtomicEmailConnectionFactory::class => factory(function (): AtomicEmailConnectionFactory {
        return new AtomicEmailConnectionFactory(static function (): PDO {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $_ENV['DB_HOST'] ?? '127.0.0.1', $_ENV['DB_PORT'] ?? '3306', $_ENV['DB_DATABASE'] ?? 'channel_delivery');
            $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            return $pdo;
        });
    }),
    AtomicEmailGateway::class => autowire(AtomicEmailGateway::class),
    IdempotentEmailQueueWriterInterface::class => get(AtomicEmailGateway::class),
    EmailEnqueueReceiptReaderInterface::class => get(AtomicEmailGateway::class),
    ConsumerNamespaceRepositoryInterface::class => autowire(PdoConsumerNamespaceRepository::class),

    // ── Worker ────────────────────────────────────────────────
    // LoggerInterface is resolved from the container (defined in crypto.php).
    // EmailQueueWorker now requires a logger for structured failure reporting.
    EmailQueueWorker::class          => autowire(EmailQueueWorker::class),

];