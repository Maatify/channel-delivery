# Security Audit Report

## Overview
This document outlines the security mechanisms implemented in `maatify/channel-delivery`.

### 1. Data at Rest (Encryption)
All recipient information and message contexts are encrypted at rest using `AES-256-GCM` via the `maatify/crypto` library. Key rotation is fully supported through the `CRYPTO_KEYS` environment variable configuration.

### 2. API Protection
The ingestion API (`/api/v1/email/enqueue`) is protected by:
- **API Keys**: Unique keys generated securely (`random_bytes(32)`).
- **IP Whitelisting**: Keys can be restricted to specific originating IPs.
- **Rate Limiting**: A Redis sliding-window counter prevents denial-of-service (DoS) and abuse.

### 3. Dependency Security
Dependencies are managed via Composer and regularly audited for known vulnerabilities.
