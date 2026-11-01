# Security Policy

## Supported Versions

| Version | Supported | Notes |
| :--- | :--- | :--- |
| **3.0.x** | Yes | Current active release |
| **2.0.x** | No | End of Life; upgrade immediately |

---

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

If you discover a security vulnerability in DRXStore, please report it privately via a [GitHub Security Advisory](https://github.com/psvineet/drxstore/security/advisories/new) or by emailing **ci.vps@proton.me**.

### Please include:
* A clear description of the issue
* Step-by-step instructions to reproduce the vulnerability
* The specific version and storage backend (JSON / MySQL) tested
* Any suggested fix, proof-of-concept (PoC), or CVSS vector if available

### What to expect:
* **Acknowledgement:** Within 48 hours
* **Fix Target:** Agreed patch and coordinated disclosure timeline within 7 days
* **Credit:** Acknowledgment in the changelog, security advisory, and release notes for confirmed issues (including your name and a link of your choice)

We follow a **90-day coordinated disclosure window** from the date of first contact.

---

## Disclosure History

### 3.0.0 — October 2026
* **Reported by:** Anil Kumar Voleti ([LinkedIn Profile](https://www.linkedin.com/in/voleti-anil/))
* **Overview:** Anil identified two critical vulnerabilities in DRXStore 2.0.0 through independent research on a local installation. He reported them privately, coordinated the timeline, and performed independent patch verification against the `v3.0.0` release.

| ID | Severity | Issue Summary |
| :--- | :--- | :--- |
| **C1** | **Critical** | **Broken Authorization:** `requireAdmin()` checked session existence but not the user role, granting staff users access to all admin pages. Combined with `users.php` accepting the `role` field from POST without restriction, a staff user could escalate to admin. |
| **C2** | **Critical** | **Remote Code Execution (RCE):** The software updater accepted an arbitrary uploaded ZIP archive with no file-type restrictions or signature verification. An uploaded PHP file could be requested directly over HTTP and executed. |

* **Tracking:** [GHSA-7xhx-j29g-xgx4](https://github.com/psvineet/drxstore/security/advisories/GHSA-7xhx-j29g-xgx4)
* **CVE Status:** Pending
* **CVSS v3.1 Score:** **8.8** (`CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H`)
* **CWE References:** `CWE-863` (Incorrect Authorization), `CWE-434` (Unrestricted Upload of File with Dangerous Type)

> *Note: All identified issues were resolved in v3.0.0. Refer to `CHANGELOG.md` for full implementation details.*

---

## Security Design (v3.0.0+)

* **Role-Based Access Control:** `requireAdmin()`, `requireSupplier()`, and `requireCustomer()` strictly verify both session validity and role privileges on every protected page.
* **CSRF Protection:** Anti-CSRF tokens enforced on all POST requests; sensitive GET actions validated via `verifyCsrfGet()`.
* **Injection Defense:** All database queries are parameterised; HTML outputs are escaped using `e()` (`htmlspecialchars`).
* **Data Encryption:** Sensitive configurations (such as SMTP passwords) are encrypted using **AES-256-GCM** at rest.
* **Authentication Security:** Rate limiting and IP lockout active across all login forms and the supplier contact interface. `session_regenerate_id(true)` executed upon authentication; 2-hour idle timeout enforced.
* **Secure Updater:** Updates are restricted to official releases fetched strictly from `github.com/psvineet/drxstore` via the GitHub API. Direct user-uploaded archives are rejected, and executable file types are blocked during extraction.
* **Hardened Security Headers:** Admin responses enforce strict HTTP headers (`CSP`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`).
* **Path & Directory Protection:** Web server rules (`nginx.conf.example`, `.htaccess`) and PHP-level entry guards block direct HTTP access to internal directories (`/data`, `/config`, `/helpers`, `/views`).
* **Password Policy:** Minimum 8-character passwords with mandatory numeric or special character requirements enforced.

---

## Known Limitations & Roadmap

* **Content Security Policy (CSP):** Currently allows `'unsafe-inline'`. Inline scripts and styles are scheduled to be migrated to external files in an upcoming release to enforce a strict nonce/hash-based CSP.
* **Package Signatures:** Update packages are not yet cryptographically signed; release signing is planned on the development roadmap.
* **Self-Role Modification Guard:** An admin can currently change their own role; a self-role-change protection guard is planned for the next release.
