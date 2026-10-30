# DRXStore v3.0.1 — Complete Pharmacy Management System

**Developed by Vineet | ci.vps@proton.me**

> Version 3.0.1 is a security-hardened release. All 19 reported issues have been resolved. Upgrading from 2.0.0 is strongly recommended.

---

## Quick Start (Termux / XAMPP / Any PHP Server)

1. Extract `drxstore/` to your web root
2. Open browser → `http://localhost/drxstore/`
3. The **Setup Wizard** launches automatically on first run
4. Complete 4 steps: Store Info → Admin Account → Storage → Confirm
5. Login with your credentials

---

## Features

### Admin Portal

| Module | Features |
|---|---|
| Dashboard | Live stats, 7-day chart, alerts, top medicines, recent sales |
| Medicines | CRUD, search, dosage forms, HSN, GST%, rack location |
| Import CSV | Bulk import from CSV with preview & duplicate detection |
| Batches / Stock | Full management, expiry tracking, status filters |
| Stock Adjustment | Add/Remove with reason & audit trail |
| Suppliers | CRUD, auto-create portal accounts, email credentials |
| Purchase Orders | Create POs, notify supplier by email, receive |
| New Sale / POS | Cart, customer select, batch picker, live stock/MRP |
| Payment Methods | Cash, UPI (ref no), Cheque (no+bank+date), Card, Credit |
| Discounts | Percentage or flat, per-sale application |
| Sales History | Date/customer filter, full invoice view & print |
| Returns | Process returns, restock, refund tracking |
| Customers | CRUD, purchase history, total spent |
| Ledger | Income/expense/return entries with date filter |
| Analytics | 12-month chart, top medicines, top customers, payment breakdown |
| Expiry Report | Expired/30d/90d/good tier with stock info |
| Users | Add/edit/toggle, role-based (admin/staff) |
| Settings | Store info, currency, thresholds, SMTP, password change |
| Software Update | GitHub-verified update delivery — no file uploads accepted |

### Supplier Portal

- Dashboard with order summary
- View purchase orders, update status (confirmed → shipped → received)
- Contact store via message form (notified by email)
- Profile & password management
- Admin can create supplier accounts and auto-email credentials

### Customer / Patient Portal

- Register with email verification required
- View purchase history with printable invoices
- Submit return requests (store notified by email)
- Profile management

---

## Setup Requirements

- PHP 8.0+
- Web server (Apache / Nginx / Termux httpd)
- Write permission on `data/` folder: `chmod 755 data/`
- `mail()` or configured SMTP for email features (optional)

---

## Storage Options

- **JSON** (default) — No setup, works on Termux immediately
- **MySQL** — Select during setup wizard, same interface

---

## Project Structure

```
drxstore/
├── index.php               ← Router (all pages via ?p=pagename)
├── config/app.php          ← Bootstrap & constants
├── helpers/
│   ├── JsonDB.php          ← JSON storage engine
│   ├── MySQLDB.php         ← MySQL storage engine
│   ├── Mailer.php          ← SMTP mailer (AES-256-GCM for credentials)
│   └── functions.php       ← Auth, CSRF, rate-limiting, all helpers
├── views/
│   ├── layout_admin.php    ← Admin shell (security headers included)
│   └── layout_portal.php
├── assets/
│   ├── css/app.css
│   └── js/app.js
├── data/                   ← Auto-created; all JSON data lives here
├── nginx.conf.example      ← Recommended Nginx config
└── public/
    ├── admin/
    ├── supplier/
    ├── customer/
    └── setup/
```

---

## Security

Version 3.0.0 resolves 19 security issues across critical, high, medium, and low severity. See [SECURITY.md](SECURITY.md) for the disclosure policy and [CHANGELOG.md](CHANGELOG.md) for the full list of fixes.

Key protections in this release:

- Role-verified session checks (`requireAdmin()`) on every admin page
- CSRF tokens on all forms and sensitive GET requests
- Parameterised database queries throughout; `e()` escapes all output
- AES-256-GCM encryption for stored SMTP credentials
- Rate limiting and account lockout on all login forms
- Session regeneration on login; idle timeout after 2 hours
- Software updater downloads only from `github.com/psvineet/drxstore` via the GitHub API — no user-uploaded ZIP is accepted
- Security headers (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) on every admin response
- Minimum password complexity enforced on all seven password forms

---

## Contact

**Developer:** Vineet
**Email:** ci.vps@proton.me

---

*DRXStore v3.0.1 — Built with care by Vineet*
