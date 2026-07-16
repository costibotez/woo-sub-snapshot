# Woo Subscription Snapshot

A WordPress plugin that reports on WooCommerce Subscriptions, including:
- Active subscriptions (historically accurate — cancelled/expired subscriptions still count for the months they were active)
- Pending cancellations
- Growth, churn, and estimated MRR metrics
- Trend charts, custom date filtering, CSV export
- Automated weekly/monthly email with attached report
- An optional membership column for any WooCommerce Memberships plan (disabled by default)

Works on any WooCommerce Subscriptions store out of the box. WooCommerce Memberships is optional — if it isn't installed, the membership column is simply hidden.

## Features

- 📊 Admin dashboard with trend charts (inline SVG — no external assets) and a month-over-month detail table
- 📈 Growth & churn metrics: new subscriptions, cancellations, net change, churn rate %, and estimated MRR
- 🗄️ Immutable monthly snapshots — closed months are recorded to a custom table so history can't be rewritten by later status changes, and reports render instantly
- 📅 Start Date / End Date filtering
- 📁 Export as CSV (nonce-protected, formula-injection safe)
- ✉️ Automated email with weekly or monthly cadence, multiple recipients, HTML summary, CSV attachment, and a "send test email now" button
- 🔌 REST API (`GET /wp-json/wss/v1/report`) and WP-CLI (`wp wss report`) for external dashboards and scripted exports
- ⚙️ Settings for recipient email(s), cadence, and an optional membership plan (chosen from a dropdown, with a custom column label) plus whether to exclude members who also hold a live subscription
- 🔒 Secure admin-only access (`manage_woocommerce` capability + CSRF nonces on all actions)
- ⚡ Single-pass queries with a 1-hour report cache — no per-subscription object loading

## How It Works

1. Installs as a standard plugin in WordPress.
2. Adds a "Subscription Reports" section in the WP admin.
3. Automatically emails a CSV report on the configured cadence to the recipient(s).
4. Counts subscriptions by their activity window (start date → next payment / end date), so past months reflect what was actually active then.
5. The report CSV attachment is generated in the system temp directory and deleted after sending — nothing is left in the public uploads folder.

## Settings

Found on the **Subscription Reports** admin page:

| Setting | Default | Description |
| --- | --- | --- |
| Report Recipient Email(s) | — | Where the report is sent (comma-separated for multiple) |
| Email Cadence | Monthly | Weekly or monthly |
| Membership plan | Disabled | Optional WooCommerce Memberships plan to count; only shown when Memberships is active |
| Column label | `Members` | Display name for the membership column |
| Exclude members with a live subscription | On | Don't double-count subscribers in the membership column |

## Requirements

- WordPress 5.8+, PHP 7.2+
- WooCommerce Subscriptions (active) — required
- WooCommerce Memberships — optional, only for the membership column
- WP Cron enabled
- Mail configuration (e.g. via SMTP)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin from the WordPress admin
3. Go to `Subscription Reports` → set recipient email(s) and cadence
4. (Optional) If you use WooCommerce Memberships, pick a plan and label for the membership column
5. Enjoy the reports!

## API

Both are gated behind the `manage_woocommerce` capability.

```
GET /wp-json/wss/v1/report?start=2026-01-01&end=2026-07-31
wp wss report --start=2026-01-01 --end=2026-07-31 --format=table|csv|json
```

## Structure

```
woo-sub-snapshot.php          Bootstrap: hooks, cron interval, HPOS compat, dependency guard
includes/
  class-wss-report-data.php   Queries, month bucketing, growth/churn/MRR (single source of truth)
  class-wss-snapshots.php     Immutable monthly snapshots (custom table + recorder cron)
  class-wss-chart.php         Inline SVG line-chart renderer
  class-wss-admin-page.php    Menu, settings, charts, report table
  class-wss-csv-exporter.php  Shared CSV writer + export handler
  class-wss-email-report.php  Scheduled email (weekly/monthly, multi-recipient, test send)
  class-wss-api.php           REST endpoint + WP-CLI command
uninstall.php                 Removes options, cron events, snapshots table, and caches
```

## Notes

- "Pending Cancel" reflects the *current* subscription status, so historical months show subscriptions that are pending cancellation today.
- MRR is estimated from each subscription's recurring total normalised to a monthly figure (day/week/month/year billing periods supported).
- Use **Backfill Monthly Snapshots** on the report page to record closed months immediately; thereafter a daily cron records each month once it closes.
- Deactivating the plugin clears the scheduled events; uninstalling removes all stored options, the snapshots table, and caches.

## Developer

Made with ❤️ by [Costin Botez – Nomad Developer](https://nomad-developer.co.uk)

---
