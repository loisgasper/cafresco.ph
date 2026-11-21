# Cafresco ordering web app

Mobile-first ordering app for Cafresco: customers order pickup or takeout, pay with GCash, and earn loyalty points. Partner riders from local rider companies receive jobs, and staff run everything from a POS-style back office.

It runs on **Hostinger shared hosting** (PHP 8 + MySQL), with no build step, no Node and no paid services.

## What's inside

| Area | URL | Who |
| --- | --- | --- |
| Customer app (installable PWA) | `/` | Customers |
| Back office | `/admin/` | Owner and staff |
| Rider partner portal | `/rider/` | Riders |
| One-time installer | `/setup.html` | Owner, once |

**Customers:** register with email and mobile, verify their email (and mobile by SMS OTP when an SMS provider is set up), browse the menu (variants and add-ons), check out with pickup, own rider, or a Cafresco partner rider, then pay by GCash by uploading the receipt screenshot and reference number. They can track the order live and earn and redeem points (online orders only). On phones they're offered an "Install app" prompt (Android) or Add to Home Screen instructions (iPhone).

**Back office:** live order board with sound and alerts, payment proof review (duplicate GCash reference warning), order status flow, products with variants, add-on groups, categories, discounts (codes or automatic), customers (block, verify, adjust points, notes), rider companies and rider approvals, reports, settings, staff accounts, and an optional Loyverse import.

**Riders:** register under their rider company, wait for approval, go online, get alerts for "find a rider" orders, accept (first come, first served), then mark picked up and delivered. The customer sees the rider's name, company, plate and number.

### Anti-scam measures
- Only logged-in customers with a verified email can order (verified mobile is optional and needs SMS).
- GCash orders aren't prepared until staff confirm the receipt. A reference number can't be reused, and staff are warned about duplicates.
- Unpaid GCash orders auto-cancel after the payment window (default 30 min).
- First-order cap (default ₱1,500). Pay on pickup only unlocks after a completed order. There's a limit on open orders per customer.
- Rate limits on login, registration, OTP, orders and uploads. CSRF protection, server-side pricing, and uploads stored outside the web root.
- Riders only see the customer's address and number after accepting. Staff can block customers or riders.

## Deploying on Hostinger

1. **Database:** hPanel → Databases → create a MySQL database and user.
2. **Config:** copy `config.sample.php` to `cafresco-config.php` in the folder *above* `public_html` (e.g. `/home/<user>/domains/cafrescocafe.com/cafresco-config.php`) and fill it in. If you can't write there, use `public_html/.private/config.php` instead (it's blocked from the web).
3. **Code:** hPanel → Git (or the Hostinger GitHub integration) → deploy this repo's `main` branch into `public_html`. Each push redeploys.
4. **Install:** open `https://cafrescocafe.com/setup.html`, enter the `setup_token`, and create the owner account. Then clear `setup_token` in the config.
5. **Settings:** log in at `/admin/` → Settings: GCash name, number and QR, store hours, points, anti-scam rules. Then add rider companies (Riders) and products (or Products → Import from Loyverse).

Email goes out through a Hostinger mailbox (`mail.driver = smtp`). SMS is off by default (`sms.driver = none`). To turn on SMS OTP, create a Semaphore account (about ₱0.50 per SMS) and set `sms.driver = semaphore`.

## Notifications
Notifications are free and need no third-party push service. Open pages poll every 10–20 seconds and show in-app toasts plus system notifications (when allowed). Customers also get emails on key status changes. Staff should keep the back office open on the counter device, and riders keep the rider page open or installed while online.

## Local development
```bash
# MySQL/MariaDB running locally with a database "cafresco"
cp config.sample.php config.local.php   # set db creds, mail.driver=log, sms.driver=log
php -S 127.0.0.1:8080 tests/router.php
# install: POST /api/setup (or open /setup.html), then run the API smoke test:
bash tests/smoke.sh
```
