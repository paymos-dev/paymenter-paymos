# Paymos Gateway for Paymenter v1

Official Paymos payment gateway extension for **Paymenter v1.x** (Laravel / Filament 4).

Accept **4 stablecoins (`USDT`, `USDC`, `USD1`, `DAI`)** and **gold-backed `XAUT`** across 13 blockchain networks with HMAC-SHA256 signed requests, dual-`v1` webhook signature verification, terminal reverse verification, invoice renewal, and native full refunds.

## Features

- **Supported assets:** 4 stablecoins (`USDT`, `USDC`, `USD1`, `DAI`) and gold-backed `XAUT` (on-chain settlement, unified per-asset merchant balances, 1.0% standard incoming processing fee, 0% Paymos withdrawal commission with subsidized network fees).
- **Native Paymenter v1.x architecture:** PHP 8 `#[ExtensionMeta]` attribute, `Paymenter\Extensions\Gateways\Paymos\Internal\` sub-namespace (no PSR-4 collision with Paymenter's gateway loader), Filament encrypted secret fields (`'encrypted' => true`), and CSRF-exempt webhook route (`withoutMiddleware([PreventRequestForgery::class])`).
- **Self-contained distribution:** Release ZIP bundles the zero-dependency `paymos/php-sdk` under `vendor/paymos/php-sdk/src/` — no Composer step required on the Paymenter host.
- **Defense-in-depth webhook pipeline:**
  - `X-Webhook-Signature` HMAC-SHA256 verification with dual-`v1` secret rotation and 300-second timestamp skew window.
  - Owner-fenced atomic deduplication in `paymos_webhook_events` (`RESERVATION_SECONDS = 300`, HTTP `200` on committed duplicate, HTTP `409` on concurrent in-flight delivery, commit strictly after payment mutation).
  - Live reverse verification (`GET /v1/invoices/{id}`) on all terminal events.
  - `AmountGuard` triangulation (snapshot ↔ current Paymenter invoice ↔ webhook order) holding mismatches for `manual_review` (HTTP `400`).
  - Roll-back guard protecting paid/finalized invoices from stale out-of-order events.
  - Note-only idempotent handling for `invoice.paid_late`.
- **Automatic invoice renewal:** Uses `Paymos\Plugin\InvoiceRenewal::isRequired()` so expired, cancelled, or underpaid invoices automatically regenerate a fresh `external_order_id` (`-r{attempt}`) without stranding buyers.
- **Full refunds from Paymenter Admin:** Implements `supportsRefunds()` and `refund()` via `POST /v1/refunds` (`mode: "full"`), resolving the exact Paymos invoice by `transaction_id`, validating `withdrawal_id`, and rejecting unsupported partial refund amounts before making any network call.

## Requirements

- **Paymenter:** v1.x (Laravel / Filament 4)
- **PHP:** `>= 8.2` (`pdo`, `curl`, `hash`, `json`, `openssl`)
- **Paymos account:** Create a project and credentials at [paymos.io](https://paymos.io)

## Installation

1. Download `Paymos-Paymenter-v1.0.0.zip` from [GitHub Releases](https://github.com/paymos-dev/paymenter-paymos/releases) or BuiltByBit.
2. Extract the archive into your Paymenter `extensions/Gateways/` directory so the layout is:

   ```text
   extensions/Gateways/Paymos/
     Paymos.php
     routes.php
     composer.json
     README.md
     LICENSE
     src/
     vendor/paymos/php-sdk/src/
   ```

3. Clear Laravel route and config caches if caching is enabled:

   ```bash
   php artisan route:clear
   php artisan config:clear
   ```

4. In **Paymenter Admin → Extensions → Gateways**, enable **Paymos**.

## Configuration (Filament Admin)

Open **Paymos** gateway settings in Filament Admin and fill in:

| Setting | Description |
| --- | --- |
| **Environment** | `Live (Production)` or `Sandbox (Test)` |
| **Project ID** | Your Paymos Project UUID |
| **Payment API Key** | `pk_live_*` (Live) or `pk_test_*` (Sandbox). Payout keys (`rk_*`) are rejected before any request is sent. |
| **API Secret** | `sk_live_*` or `sk_test_*` (stored encrypted by Paymenter) |
| **Webhook Signing Secret** | `whsec_*` secret from Paymos Dashboard (stored encrypted by Paymenter) |
| **API Base URL** | HTTPS Paymos API URL (default: `https://api.paymos.io`; non-HTTPS URLs are rejected) |
| **Debug Logging** | Optional sanitized debug logging (secrets are never written to logs) |

## Webhook Setup

Paymos verifies webhook endpoints configured in your dashboard:

1. Open **Paymos Dashboard → Developer → Webhooks**.
2. Click **Add Webhook** and enter your Paymenter webhook URL:

   ```text
   https://<your-paymenter-panel>/extensions/gateways/paymos/webhook
   ```

3. Subscribe to **Invoice** events.
4. Copy the generated signing secret (`whsec_...`) and paste it into **Webhook Signing Secret** in Paymenter Admin.

The route `/extensions/gateways/paymos/webhook` is registered in `routes.php` with `withoutMiddleware([PreventRequestForgery::class])` so Laravel CSRF middleware does not block Paymos POST deliveries.

## Refunds

- **Full refunds:** Trigger a full refund for any paid Paymos transaction from Paymenter Admin. The extension resolves the exact Paymos invoice via `transaction_id`, calls `POST /v1/refunds` with `{invoice_id, mode: "full", external_order_id}`, and validates `withdrawal_id` and non-failed status in the Paymos response.
- **Partial refunds:** Paymos Refunds API supports server-calculated `full` and `overpayment` modes only; partial arbitrary refund amounts are rejected immediately with a validation error before any API request is sent. Overpayment refunds can be issued from the Paymos Dashboard.

## BuiltByBit / Paymenter Marketplace Summary

> **Paymos Gateway for Paymenter v1** lets hosting providers accept **USDT, USDC, USD1, DAI stablecoins** and **gold-backed XAUT** across 13 networks with on-chain settlement, Sandbox and Live environments, encrypted credentials, dual-`v1` webhook verification, reverse verification, automatic expired-invoice renewal, and native full refunds. Drop `Paymos/` into `extensions/Gateways/`, register `/extensions/gateways/paymos/webhook` in Paymos Dashboard, and start accepting payments in minutes.

## Support & License

- Documentation: [https://paymos.io/docs](https://paymos.io/docs)
- Source & Issues: [https://github.com/paymos-dev/paymenter-paymos](https://github.com/paymos-dev/paymenter-paymos)
- Support: `support@paymos.io`
- License: [MIT](LICENSE) — © 2026 Paymos Labs
