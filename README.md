# Wizlo → AffiliateWP Bridge

A WordPress plugin that receives webhooks from [Wizlo](https://wizlo.com) and automatically creates and manages referrals in [AffiliateWP](https://affiliatewp.com).

**Author:** [Tomas Buitrago](https://github.com/TBuitrago)  
**Plugin URL:** https://github.com/TBuitrago/wizlo-affiliatewp-bridge  
**Version:** 2.0.0

---

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.0+ |
| PHP | 7.4+ |
| AffiliateWP | 2.0+ (active and configured) |

---

## Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```
   wp-content/plugins/wizlo-affiliatewp-bridge/
   ```
2. Activate the plugin from **WordPress Admin → Plugins**.
3. Go to **Settings → Wizlo Bridge** to find your webhook URL and configure the HMAC secret.

---

## Configuration

### 1. Webhook URL

After activation, the plugin registers a REST endpoint. Copy the URL shown in the admin page and register it in Wizlo under `POST /tenant/webhooks`:

```
https://your-site.com/wp-json/wizlo/v1/conversion
```

### 2. HMAC Secret

The plugin validates all incoming webhooks using an HMAC-SHA256 signature. Set your secret using one of these two methods:

**Recommended — define in `wp-config.php`** (prevents the value from being edited via the UI):
```php
define( 'WIZLO_WEBHOOK_SECRET', 'your-secret' );
```

**Alternative — enter in the admin panel:**  
Go to **Settings → Wizlo Bridge → HMAC Secret** and save the secret there. This stores it in the WordPress options table.

> The plugin accepts both `hex` and `base64` encoded HMAC signatures, and also strips a leading `sha256=` prefix if present.

### 3. Recommended Webhook Events

Register these events in Wizlo to enable full referral tracking:

| Event | Module | Purpose |
|---|---|---|
| `forms.coupon_used` | forms | **Primary attribution** — carries coupon code, amount, and order ID |
| `order.updated` | orders | Lifecycle transitions: `pending → unpaid → rejected` |
| `forms.completed` | forms | Optional — use only if embedding via iframe + customFields |
| `forms.product_selected` | forms | Optional — alternative without coupon, triggers on `paid` status |

---

## How It Works

### Attribution Flow

```
Wizlo webhook → Signature verification → Event dispatch → AffiliateWP referral
```

1. Wizlo sends a signed `POST` request to the plugin's REST endpoint.
2. The plugin verifies the HMAC signature (hex or base64).
3. The event type is extracted from the payload (`event`, `eventType`, or `type` field).
4. The plugin dispatches to the appropriate handler.

### Referral Creation

The plugin creates AffiliateWP referrals in `pending` status. Duplicate referrals (same `order_id` + context) are automatically skipped.

**Coupon-based attribution (`forms.coupon_used`):**
- Looks up the affiliate linked to the coupon code via AffiliateWP's coupon API.
- Creates a referral with the order amount, order ID, and customer email.

**customFields-based attribution (`forms.completed`, `forms.product_selected`):**
- Recursively searches the entire payload for any key resembling `affiliate_id` (also `affiliateId`, `aff_id`, `affId`, `affiliate`).
- Resolves the value as an affiliate ID (numeric), affiliate email, or username.
- Creates a referral with amount `0`; the amount is backfilled when `order.updated` arrives with `grand_total`.

### Lifecycle Updates (`order.updated`)

The plugin maps Wizlo order statuses to AffiliateWP referral statuses:

| Wizlo Status | AffiliateWP Status |
|---|---|
| `pending`, `partially_paid` | `pending` |
| `paid`, `fulfilled` | `unpaid` |
| `cancelled`, `canceled`, `refunded`, `failed` | `rejected` |

If a referral was created with amount `0` (via `forms.completed`) and `grand_total` is now available, the amount is automatically backfilled using `affwp_calc_referral_amount`.

---

## Admin Panel

Go to **WordPress Admin → Settings → Wizlo Bridge**. The page shows:

- **Webhook URL** — copy and paste into Wizlo's webhook configuration.
- **Recommended events** — the events to register in Wizlo.
- **HMAC Secret** — configure your signing secret.
- **Recent Activity** — a rolling log of the last 100 webhook events, showing the full raw payload for each.

---

## Troubleshooting

### Signature validation fails

The plugin logs a detailed diagnostic entry when a signature mismatch occurs. Open **Settings → Wizlo Bridge → Recent Activity** and look for a `Signature mismatch — diagnostic` entry. It includes:

- `received` — the exact value sent by Wizlo.
- `expected_hex` — the expected signature in hex format.
- `expected_base64` — the expected signature in base64 format.

Compare these to determine which format Wizlo is using and adjust your secret accordingly.

### Webhook headers

The plugin reads the signature from these headers in order:

1. `X-Webhook-Signature`
2. `X-Wizlo-Signature`
3. `X-Signature`
4. `Signature`

### No affiliate found for coupon

If the log shows `Coupon not linked to any affiliate`, verify that the coupon is assigned to an affiliate in AffiliateWP (**AffiliateWP → Affiliates → [Affiliate] → Coupons**).

### customFields not detected

If `affiliate_id` is not being picked up from the payload, check the raw payload in the activity log. The plugin searches recursively for any of these keys: `affiliate_id`, `affiliateId`, `affiliate`, `aff_id`, `affId`.

### Clearing the log

Click **Clear log** in the Recent Activity section to reset the stored log entries.

---

## Changelog

### 2.0.0
- Added support for `forms.coupon_used` as the primary attribution event.
- Added `order.updated` lifecycle handler with status mapping.
- Added `forms.completed` and `forms.product_selected` as secondary attribution sources.
- Recursive `affiliate_id` search across the full payload structure.
- Flexible HMAC signature verification (hex and base64, with `sha256=` prefix support).
- Automatic referral amount backfill when `grand_total` arrives via `order.updated`.
- Admin panel with raw payload log (last 100 entries).
