# Woo Subscription Snapshot

A lightweight WordPress plugin that generates monthly reports of WooCommerce Subscriptions, including:
- Active subscriptions
- Pending cancellations
- Custom date filtering
- CSV export (now includes revenue and event counts)
- Automated monthly email with attached report

## Features

- 📊 Admin dashboard report with a clean table view
- 📅 Start Date / End Date filtering
- 📁 Export as CSV
- ✉️ Email automation with editable recipient field
- 🔒 Secure admin-only access

## How It Works

1. Installs as a standard plugin in WordPress.
2. Adds a “Subscription Reports” section in the WP admin.
3. Automatically emails a CSV file each month (can be customized).
4. Uses WP Cron and WooCommerce Subscriptions API (`wcs_get_subscription`).

## Requirements

- WordPress 5.8+
- WooCommerce Subscriptions (active)
- WP Cron enabled
- Mail configuration (e.g. via SMTP)

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin from the WordPress admin
3. Go to `Subscription Reports` → Set recipient email
4. Enjoy the reports!

## Developer

Made with ❤️ by [Costin Botez – Nomad Developer](https://nomad-developer.co.uk)

---
