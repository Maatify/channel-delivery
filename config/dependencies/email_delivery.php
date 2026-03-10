<?php

declare(strict_types=1);

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
            templateDir: dirname(__DIR__, 2) . '/templates',
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

    // ── Worker ────────────────────────────────────────────────
    // LoggerInterface is resolved from the container (defined in crypto.php).
    // EmailQueueWorker now requires a logger for structured failure reporting.
    EmailQueueWorker::class          => autowire(EmailQueueWorker::class),

];