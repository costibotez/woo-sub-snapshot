# Woo Subscription Snapshot

A lightweight WordPress plugin that generates monthly reports of WooCommerce Subscriptions, including:
- Active subscriptions (historically accurate — cancelled/expired subscriptions still count for the months they were active)
- Pending cancellations
- Active CT Club members (WooCommerce Memberships plan, configurable)
- Custom date filtering
- CSV export
- Automated monthly email with attached report

## Features

- 📊 Admin dashboard report with a clean table view
- 📅 Start Date / End Date filtering
- 📁 Export as CSV (nonce-protected, formula-injection safe)
- ✉️ Automated monthly email (custom `wss_monthly` cron schedule) with HTML summary and CSV attachment
- ⚙️ Settings for recipient email, CT Club membership plan ID, and whether to exclude members who also hold a live subscription
- 🔒 Secure admin-only access (`manage_woocommerce` capability + CSRF nonces on all actions)
- ⚡ Single-pass queries with a 1-hour report cache — no per-subscription object loading

## How It Works

1. Installs as a standard plugin in WordPress.
2. Adds a "Subscription Reports" section in the WP admin.
3. Automatically emails a CSV file on the 1st of each month to the configured recipient.
4. Counts subscriptions by their activity window (start date → next payment / end date), so past months reflect what was actually active then.
5. The report CSV attachment is generated in the system temp directory and deleted after sending — nothing is left in the public uploads folder.

## Settings

Found on the **Subscription Reports** admin page:

| Setting | Default | Description |
| --- | --- | --- |
| Report Recipient Email | — | Where the monthly report is sent |
| CT Club Membership Plan ID | `13981` | Post ID of the Membership Plan to count; `0` disables the column |
| Exclude members with a live subscription | On | Don't double-count subscribers as CT Club members |

## Requirements

- WordPress 5.8+, PHP 7.2+
- WooCommerce Subscriptions (active)
- WooCommerce Memberships (only for the CT Club column)
- WP Cron enabled
- Mail configuration (e.g. via SMTP)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin from the WordPress admin
3. Go to `Subscription Reports` → set recipient email and CT Club plan ID
4. Enjoy the reports!

## Structure

```
woo-sub-snapshot.php          Bootstrap: hooks, cron interval, HPOS compat, dependency guard
includes/
  class-wss-report-data.php   Queries + month bucketing (single source of truth)
  class-wss-admin-page.php    Menu, settings, report table
  class-wss-csv-exporter.php  Shared CSV writer + export handler
  class-wss-email-report.php  Monthly cron email
uninstall.php                 Removes options, cron event, and cached reports
```

## Notes

- "Pending Cancel" reflects the *current* subscription status, so historical months show subscriptions that are pending cancellation today.
- Deactivating the plugin clears the scheduled email; uninstalling removes all stored options and caches.

## Developer

Made with ❤️ by [Costin Botez – Nomad Developer](https://nomad-developer.co.uk)

---
