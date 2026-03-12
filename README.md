# Maatify Channel Delivery

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maatify/channel-delivery.svg?style=flat-square)](https://packagist.org/packages/maatify/channel-delivery)
[![PHP Version Require](https://img.shields.io/packagist/php-v/maatify/channel-delivery)](https://packagist.org/packages/maatify/channel-delivery)
[![License](https://img.shields.io/github/license/Maatify/channel-delivery.svg?style=flat-square)](https://github.com/Maatify/channel-delivery/blob/main/LICENSE)

## Overview

Maatify Channel Delivery is a standalone async multi-channel delivery microservice for sending notifications across channels like Email, Telegram, SMS, and Push. It offloads delivery processing from your core applications, ensuring high availability, retry mechanisms, and robust security through encrypted payloads and IP whitelisting.

## Features

- **Async Multi-Channel Queue:** Process emails and other notifications asynchronously using specialized workers.
- **Encrypted Payloads:** Built-in `AES-256-GCM` encryption for recipients and message contexts to protect sensitive data at rest.
- **Template Rendering:** Uses Twig to render dynamic email templates securely.
- **Robust Delivery:** Exponential backoff and retry mechanisms for transient failures.
- **API Key & IP Whitelisting:** Endpoint protection ensuring only authorized origin servers can enqueue notifications.
- **Rate Limiting:** Built-in Redis-backed sliding window rate limiter to prevent abuse.
- **Containerized Dependency Injection:** Built on top of Slim 4 and PHP-DI for modularity.

## Architecture

The system operates as an API ingestion layer combined with a background worker polling mechanism. Origin servers enqueue jobs securely over HTTP. Background workers fetch jobs from the database queue, process them (e.g., SMTP delivery), and update the status.

![Architecture Diagram](docs/assets/architecture-diagram.svg)

## Email Pipeline

The email pipeline handles everything from ingestion to delivery:
1.  **Enqueue:** Client calls `/api/v1/email/enqueue` with payload (recipient, template key, context).
2.  **Encryption:** The `EnqueueEmailHandler` encrypts the recipient email and context using `Maatify\Crypto`.
3.  **Persistence:** Job is stored in `cd_email_queue`.
4.  **Worker Poll:** `EmailQueueWorker` polls the database for `pending` jobs.
5.  **Rendering:** Context is decrypted and injected into the specified Twig template.
6.  **Transport:** Final rendered email is sent via `SmtpEmailTransport`.

![Email Flow Diagram](docs/assets/email-flow-diagram.svg)

## Installation

1.  Clone the repository or install via Composer:
    ```bash
    composer create-project maatify/channel-delivery
    cd channel-delivery
    ```
2.  Install dependencies:
    ```bash
    composer install --optimize-autoloader --no-dev
    ```
3.  Set up environment variables:
    ```bash
    cp .env.example .env
    # Edit .env to set your DB, SMTP, Redis, and Crypto keys
    ```
4.  Run database migrations from the `migrations/` directory.

## Quick Example

Enqueue an email using the provided script:

```bash
export CD_BASE_URL=http://localhost:8080
export CD_API_KEY=your_generated_api_key
php scripts/test_enqueue.php
```

Or via cURL:

```bash
curl -X POST http://localhost:8080/api/v1/email/enqueue \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: your_generated_api_key" \
  -d '{
    "entity_type": "user",
    "recipient": "user@example.com",
    "template_key": "welcome",
    "language": "en",
    "sender_type": 1
  }'
```

## Worker Usage

Run the email worker using the provided CLI script:

```bash
php scripts/email_worker.php --batch=50 --loop --sleep=5
```

This should typically be run via a process manager like Supervisor. See [Run Worker Guide](docs/how-to/run-worker.md).

## Documentation Links

- [How to Run the Worker](docs/how-to/run-worker.md)
- [How to Send an Email](docs/how-to/send-email.md)
- [API Documentation: Email Enqueue](docs/api/email-enqueue.md)
- [Basic Usage Example](docs/examples/basic-usage.php)

## License

This library is open-sourced software licensed under the [MIT license](LICENSE).