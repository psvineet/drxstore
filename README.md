# DRXStore v3.0.1 — Complete Pharmacy Management System

**Developed by Vineet | ci.vps@proton.me**

> Version 3.0.1 is a security-hardened release. All 19 reported issues have been resolved. Upgrading from 2.0.0 is strongly recommended.

---

## Quick Start (Termux / XAMPP / Any PHP Server)

1. Extract `drxstore/` to your web root directory.
2. Open your browser and navigate to `http://localhost/drxstore/`
3. The **Setup Wizard** will launch automatically on first run.
4. Complete the 4 setup steps: **Store Info** → **Admin Account** → **Storage** → **Confirm**
5. Log in with your new admin credentials.

---

## Features

### Admin Portal

| Module | Features |
| --- | --- |
| **Dashboard** | Live stats, 7-day sales chart, low-stock alerts, top medicines, recent activity |
| **Medicines** | Full CRUD, search, dosage forms, HSN codes, GST%, rack location tracking |
| **Import CSV** | Bulk import medicines from CSV with preview & duplicate detection |
| **Batches & Stock** | Full batch management, expiry tracking, unit pricing, status filters |
| **Stock Adjustment** | Add/Remove inventory with reason codes & audit trail |
| **Suppliers** | Manage suppliers, auto-create portal accounts, automated credential email |
| **Purchase Orders** | Create POs, notify suppliers via email, mark stock as received |
| **POS / Billing** | Interactive cart, customer selection, unit batch picker, live MRP calculation |
| **Payment Methods** | Cash, UPI (reference ID), Cheque (bank & date), Card, Credit ledger |
| **Discounts** | Flat or percentage-based discount per sale |
| **Sales History** | Filter by date/customer, view full invoice, print customer receipts |
| **Returns** | Process return requests, restock inventory, refund audit trail |
| **Customers** | Customer profile CRUD, purchase history, lifetime value tracking |
| **Ledger** | Unified income, expense, and return records with custom date filters |
| **Analytics** | 12-month visual chart, top medicines, customer spending, payment breakdown |
| **Expiry Report** | Tiered reports (Expired, 30 days, 90 days, Good) with stock count |
| **User Management**| Add/edit/toggle staff accounts, role-based access control (Admin/Staff) |
| **Settings** | Store info, currency symbol, stock thresholds, SMTP setup, password change |
| **Software Update**| GitHub-verified update delivery — no unverified file uploads accepted |

### Supplier Portal

- Dashboard with active purchase order summaries
- View purchase orders and update delivery status (*Confirmed* → *Shipped* → *Received*)
- Direct message interface to contact store administration via email notifications
- Profile & account password management

### Customer / Patient Portal

- Account registration with email verification
- Purchase history viewer with printable receipts and invoices
- Submit item return requests directly to store administrators
- Account profile management

---

## Setup Requirements

- **PHP:** 8.0 or higher
- **Web Server:** Apache / Nginx / Termux `httpd`
- **Permissions:** Write permissions on the `data/` directory (`chmod 755 data/`)
- **Email:** `mail()` enabled or configured SMTP for automated emails (optional)

---

## Storage Options

- **JSON (Default):** Zero-configuration database; runs out of the box on Termux and lightweight servers.
- **MySQL:** Optional relational database support selectable during the Setup Wizard.

---

## Project Structure

```text
drxstore/
├── index.php                ← Router (all pages routed via ?p=pagename)
├── config/app.php           ← Application bootstrap & system constants
├── helpers/
│   ├── JsonDB.php           ← JSON storage engine
│   ├── MySQLDB.php          ← MySQL storage engine
│   ├── Mailer.php           ← SMTP mailer (AES-256-GCM encrypted credentials)
│   └── functions.php        ← Auth, CSRF, rate-limiting, and core utilities
├── views/
│   ├── layout_admin.php     ← Admin shell layout (enforces security headers)
│   └── layout_portal.php    ← Customer/Supplier portal shell layout
├── assets/
│   ├── css/app.css
│   └── js/app.js
├── data/                    ← Auto-created directory for JSON storage
├── nginx.conf.example       ← Recommended Nginx server configuration
└── public/
    ├── admin/
    ├── supplier/
    ├── customer/
    └── setup/
```

---

## Security

DRXStore follows strict security guidelines. Version 3.0.0 resolved 19 security issues across critical, high, medium, and low severity tiers. Refer to [SECURITY.md](SECURITY.md) for vulnerability disclosure policies and [CHANGELOG.md](CHANGELOG.md) for complete patch details.

Key protections built into DRXStore:

- Strict role-verified session checks (`requireAdmin()`, `requireSupplier()`, `requireCustomer()`) on all protected pages
- Anti-CSRF token verification on all POST forms and sensitive GET operations
- Parameterised database queries with automatic `e()` HTML escaping on output
- **AES-256-GCM** encryption for stored SMTP credentials at rest
- IP-based rate limiting and account lockouts across login and contact forms
- Session ID regeneration on authentication with a 2-hour idle timeout
- Secure updater fetching updates strictly from `github.com/psvineet/drxstore` via GitHub API
- Hardened HTTP security headers (`CSP`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`)
- Strict password complexity enforcement across all forms

---

## Support & Community

If you have questions, need assistance with installation, or want to suggest new features, please join our community:

- **Help & Questions:** Open a topic in [GitHub Discussions](https://github.com/psvineet/drxstore/discussions)
- **Direct Email Contact:** [ci.vps@proton.me](mailto:ci.vps@proton.me)
- **Security Disclosures:** Please review our [Security Policy](SECURITY.md) to submit private reports.

---

*DRXStore v3.0.1 — Built with care by Vineet*
