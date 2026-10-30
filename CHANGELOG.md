# Changelog

All notable changes to DRXStore are documented here.

---

## [3.0.0] — 2026-10-30

### Summary

Version 3.0.0 is a security-hardened release. Nineteen vulnerabilities — two critical, four high, five medium, and eight low — were identified by security researcher **Anil Kumar Voleti** ([@voleti-anil](https://www.linkedin.com/in/voleti-anil/)) through responsible disclosure and resolved in full. A CSV import bug reported separately by a user has also been fixed. No new features have been added; the focus of this release is correctness and security.

Upgrading from 2.0.0 is strongly recommended. The 2.0.0 branch is no longer supported.

---

### Security fixes

#### Critical

**C1 — Broken authentication: no role check in `requireAdmin()`**

`requireAdmin()` verified that `$_SESSION['admin_id']` was set but did not check the user's role. A staff user could access any admin-only page by navigating directly to its URL. Affected pages included `?p=users`, `?p=settings`, `?p=sw_update`, `?p=reports`, `?p=ledger`, `?p=purchase`, `?p=suppliers`, and `?p=adjust_stock`.

As a consequence, a staff user could submit a role-change request against their own account through `users.php` (which accepted the `role` field from the POST body without restriction) and elevate to admin. The session initially retained the staff role, but the change was written to the user record immediately.

Fix: `requireAdmin()` now checks both `$_SESSION['admin_id']` and `$_SESSION['admin_role'] === 'admin'`. Pages not on `STAFF_ALLOWED_PAGES` redirect to login for any non-admin session. `requireSupplier()` and `requireCustomer()` were audited and hardened in the same way.

**C2 — Remote code execution via software updater**

The updater accepted an arbitrary ZIP file uploaded by the user and extracted its contents into the application root. There was no extension allow-list, no PHP-file restriction, and no package signature verification. An attacker with access to the updater page could upload a ZIP containing a PHP file, complete the update, and execute arbitrary code by requesting that file over HTTP.

Fix: The updater no longer accepts user-uploaded files. It now fetches releases exclusively from `https://api.github.com/repos/psvineet/drxstore/releases/latest`, verifies the download URL is under `github.com` or `objects.githubusercontent.com`, and downloads the archive server-side. During extraction, any file with an executable extension (`.php`, `.phtml`, `.phar`, `.htaccess`, `.php5`, `.php7`, etc.) is skipped. Files targeting `data/` or `config/` are also skipped. The updater displays the release version, release notes, and a diff of what will change before the user confirms.

---

#### High

**H1 — No CSRF protection**

No form in the application validated a CSRF token. An attacker could craft a page that submitted a forged request on behalf of a logged-in admin or staff user.

Fix: `csrfField()` embeds a per-session token in every form. `verifyCsrf()` validates it on every POST. `verifyCsrfGet()` is called on sensitive GET actions (such as delete and export endpoints).

**H2 — SQL injection**

Several pages concatenated POST and GET parameters directly into query strings.

Fix: All database queries use parameterised methods. No user-controlled value is interpolated into a query string.

**H3 — Stored XSS**

User-supplied values were echoed to HTML without escaping throughout the admin and portal views.

Fix: Every output point is wrapped in `e()` (`htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8). Template files were audited in full.

**H4 — Unrestricted file upload**

File upload endpoints accepted arbitrary file types, including `.php`. An attacker who could upload to a web-accessible path could execute code.

Fix: Uploads are validated by extension whitelist and MIME type. PHP and executable extensions are explicitly rejected. The updater's separate restriction is described under C2.

---

#### Medium

**M1 — SMTP password stored in plaintext**

The SMTP password was written to the data store in plain text and was readable by anyone with access to `data/`.

Fix: The password is encrypted with AES-256-GCM before storage (stored with an `ENC:` prefix). `Mailer.php` decrypts it at runtime using a key derived from the application secret. Existing plaintext values are re-encrypted on the next settings save.

**M2 — Database restore with no confirmation**

The SQL restore function executed immediately on POST with no secondary confirmation step.

Fix: The restore form now requires the admin to re-enter their current password (`confirm_password`). The restore proceeds only if the password is verified.

**M3 — Setup wizard accessible after installation**

The setup wizard remained accessible at any time after installation, allowing an attacker to re-run it and overwrite the admin account or reconfigure the database.

Fix: A one-time token is written to `data/.setup_token` on first launch and consumed on completion. Subsequent requests to setup pages return HTTP 403 if the token is missing or has already been used.

**M4 — No rate limiting on login**

All three login forms (admin, supplier, customer) accepted unlimited attempts with no lockout.

Fix: `isLockedOut()` and `recordAttempt()` enforce a lockout after 5 failed attempts within 15 minutes. The lockout is stored in `data/` and survives page refreshes.

**M5 — Session fixation**

Sessions were not regenerated after login. An attacker who could plant a session ID could take over the session after the victim authenticated.

Fix: `session_regenerate_id(true)` is called immediately after successful authentication on all three portals.

---

#### Low

**L1 — Missing security headers**

No security headers were set on any response.

Fix: `layout_admin.php` now emits `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and a `Content-Security-Policy` on every admin response.

**L2 — Weak password policy**

Password fields accepted any input, including single-character passwords.

Fix: All seven password forms (admin registration, admin password change, staff creation, customer registration, customer password change, supplier creation, supplier password change) enforce a minimum of 8 characters with at least one digit or special character. The rule is checked server-side.

**L3 — Stack traces exposed on errors**

PHP errors were displayed to the browser, revealing file paths, variable values, and application internals.

Fix: `display_errors = 0` is set in `config/app.php`. Errors are logged, not shown.

**L4 — `data/` directory browsable on Nginx**

No Nginx configuration was provided, leaving `data/` browsable on a default Nginx install.

Fix: `nginx.conf.example` is included with `location` blocks that deny access to `data/`, `config/`, `helpers/`, and `views/`.

**L5 — `config/`, `helpers/`, `views/` accessible via HTTP**

PHP source files in these directories were directly reachable over HTTP on some server configurations.

Fix: `index.php` checks the request path at the PHP level and returns HTTP 403 for direct requests to these directories. The Nginx example config blocks them at the server level as well.

**L6 — Staff users could access admin-only pages**

With the broken `requireAdmin()` check (see C1), staff users were not restricted to the pages defined in `STAFF_ALLOWED_PAGES`.

Fix: Resolved as part of C1. `requireAdmin()` now enforces the whitelist. Staff sessions are redirected to login for any page not on the list.

**L7 — No session timeout**

Sessions did not expire, leaving an unattended browser permanently authenticated.

Fix: `SESSION_TTL = 7200` (2 hours). `startSession()` checks the last activity timestamp on every request and destroys the session if it has been idle for longer than the TTL.

**L8 — Supplier contact form not rate-limited**

The contact form on the supplier portal could be submitted repeatedly with no limit, enabling spam or enumeration.

Fix: `isLockedOut()` and `recordAttempt()` are applied to `supplier/contact.php` using the submitter's IP.

**L9 — Missing SameSite cookie attribute**

The session cookie was set without a `SameSite` attribute, weakening CSRF protection in browsers that do not default to `Lax`.

Fix: The session cookie is now set explicitly with `SameSite=Lax` in `startSession()`.

---

### Bug fixes

**CSV import — preview redirect returned 404 (reported by user)**

The `import_med` route was missing from the route map in `index.php`. After a successful CSV parse, the page redirected to `?p=import_med`, which resolved to a 404, discarding the session preview data and making the import appear to do nothing.

Additional improvements made to the import page: UTF-8 BOM stripping (for CSVs exported from Excel), CRLF normalisation, more flexible column name mapping (`medicine_name`, `generic_name`, `manufacturer`, `dosage_form`, `hsn_code`, `gst_percent`, `rack_location` all accepted as aliases), and clearer error messages throughout.

The `'active'` column was also removed from the insert statement in both `import_medicines.php` and `medicines.php` — that column does not exist in the medicines table.

---

### Acknowledgements

Security issues in 3.0.0 were reported by **Anil Kumar Voleti** ([LinkedIn](https://www.linkedin.com/in/voleti-anil/)). The issues were disclosed responsibly, coordinated through a GitHub Security Advisory, and independently verified by the reporter against the patched build. Thank you.

---

## [2.0.0] — 2026 (prior release)

Initial public release. Feature-complete pharmacy management system with admin, supplier, and customer portals, JSON and MySQL storage backends, and a setup wizard.

Known issues: see the 3.0.0 entry above. This version is no longer supported.
