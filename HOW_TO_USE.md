# How to Use

This document provides a quick overview of integrating with `maatify/channel-delivery`.

## 1. Setup Environment
Copy `.env.example` to `.env` and populate the necessary credentials (DB, Redis, SMTP, Crypto Keys).

## 2. Generate an API Key
Run the built-in script to generate an API Key, specifying the allowed originating IPs:
```bash
php scripts/create_api_key.php --name="Main App" --ips="127.0.0.1"
```

## 3. Run the Worker
Set up a background worker to poll and send emails (e.g., via Supervisor):
```bash
php scripts/email_worker.php --batch=50 --loop --sleep=5
```

## 4. Enqueue Messages
From your main application, send an HTTP POST request to the delivery microservice:
```bash
curl -X POST http://localhost:8080/api/v1/email/enqueue \n  -H "X-Api-Key: YOUR_API_KEY" \n  -d '{...}'
```
