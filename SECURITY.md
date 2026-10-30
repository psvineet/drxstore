# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 3.0.0   | Yes — current release |
| 2.0.0   | No — end of life; upgrade immediately |

---

## Reporting a Vulnerability

**Do not open a public GitHub issue for security vulnerabilities.**

Open a [GitHub Security Advisory](https://github.com/psvineet/drxstore/security/advisories/new) on this repository, or email **ci.vps@proton.me** with:

- A clear description of the issue
- Steps to reproduce
- The version and storage backend (JSON / MySQL) you tested against
- Any suggested fix or CVSS vector if you have one

### What to expect

- Acknowledgement within **48 hours**
- Fix target and coordinated disclosure timeline agreed within **7 days**
- Credit in the changelog, advisory, and release notes for confirmed issues — your name and a link of your choice

We follow a **90-day disclosure window** from the date of first contact.

---

## Disclosure History

### 3.0.0 — October 2026

**Reported by:** Anil Kumar Voleti
**Profile:** https://www.linkedin.com/in/voleti-anil/

Anil identified two critical vulnerabilities in DRXStore 2.0.0 through independent research on a local installation. He reported them directly and privately before any public disclosure, coordinated the timeline, and offered independent verification of the patch — which he carried out against the v3.0.0 build before release.

| ID | Severity | Issue |
|----|----------|-------|
| C1 | Critical | Broken authorization — `requireAdmin()` checked session existence but not role, granting staff users access to all admin-only pages; combined with `users.php` accepting the `role` field from POST without restriction, a staff user could escalate to admin |
| C2 | Critical | Remote code execution — software updater accepted an arbitrary uploaded ZIP with no file-type restriction or signature verification; an uploaded PHP file could be requested over HTTP and executed |

**CVE:** Pending — to be assigned on publication of the GitHub Security Advisory.
**CVSS:** 8.8 — `CVSS:3.1/AV:N/AC:L/PR:L/UI:N/S:U/C:H/I:H/A:H`
**CWE:** CWE-863 (Incorrect Authorization), CWE-434 (Unrestricted Upload of File with Dangerous Type)

All 19 issues are resolved in v3.0.0. Full fix details are in [CHANGELOG.md](CHANGELOG.md).

---

## Security Design (v3.0.0)

- `requireAdmin()`, `requireSupplier()`, `requireCustomer()` verify both session and role on every protected page
- CSRF tokens on every POST; sensitive GETs validated with `verifyCsrfGet()`
- All DB queries parameterised; all output wrapped in `e()` (`htmlspecialchars`)
- SMTP password encrypted with AES-256-GCM at rest
- Rate limiting and lockout on all login forms and the supplier contact form
- `session_regenerate_id(true)` on login; idle timeout after 2 hours
- Software updater fetches only from `github.com/psvineet/drxstore` via GitHub API — no user-uploaded archive accepted; executable file types blocked on extraction
- Security headers on every admin response (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy)
- Minimum 8-character password with digit or special character enforced on all password forms
- `nginx.conf.example` and PHP-level guards block direct HTTP access to `data/`, `config/`, `helpers/`, `views/`

---

## Known Limitations

- CSP allows `unsafe-inline` — inline scripts and styles will be moved to external files in a future release to allow a stricter policy
- Update packages are not signed — signed releases are on the roadmap
- An admin can change their own role — a self-role-change guard is planned for the next release
