# PawaPay Gateway for Dhru Fusion

Mobile Money gateway module for Dhru Fusion using the PawaPay REST API v2.

## Files

- `pawapay.php` — main payment gateway module (invoice page form + deposit request)
- `callback/pawapay.php` — webhook callback handler (credits invoices)

## Features

- Loads available countries/providers from `GET /v2/active-conf`
- Country-based currency flow with user currency selection
- DRC supports both **CDF** and **USD** on the user form
- USD invoice amount conversion using admin-defined FX rates
- Idempotent deposit creation using deterministic `depositId`
- Existing status handling (`PENDING`, `COMPLETED`, `FAILED`, `EXPIRED`, etc.)
- Optional strict min/max enforcement when provider limits are available
  - If limits are unavailable, payment is not blocked

## Installation

1. Copy `pawapay.php` into your Dhru gateway modules directory.
2. Copy `callback/pawapay.php` into the gateway callback directory.
3. In Dhru admin, enable/configure the `pawapay` gateway.

## Required Gateway Config

- **API Bearer Token**
- **API Base URL**
  - Production: `https://api.pawapay.io`
  - Sandbox: `https://api.sandbox.pawapay.io`
- **Sandbox / Test Mode** (yes/no)
- **USD Exchange Rates** (textarea)

## Exchange Rates Format

Use one line per currency.

Accepted examples:

- `CDF=2310`
- `CDF : 2310`
- `CDF => 2310`
- `CDF -> 2310`
- `CDF 2310`

Example block:

```text
USD=1
CDF=2310
XAF=535
XOF=535
GHS=11
KES=130
RWF=1462
TZS=2506
UGX=3767
ZMW=20
MGA=4160
MZN=65
```

## Webhook URL

Register this callback URL in PawaPay dashboard notifications:

`https://{your-domain}/modules/gateways/callback/pawapay.php`

## Notes

- Callback converts paid local amount back to USD before invoice credit (`addPayment`).
- Keep exchange rates updated to your operational treasury rates.
- `callback/webhook.log` can be used for debugging incoming callbacks.
# dhru-pawapay-gateways
