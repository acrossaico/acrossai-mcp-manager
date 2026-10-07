---
document_type: security-review
review_type: branch
assessment_date: 2026-10-07
codebase_analyzed: acrossai-mcp-manager (095-oauth-migration vs main)
total_files_analyzed: 154
total_findings: 5
overall_risk: MODERATE
critical_count: 0
high_count: 0
medium_count: 2
low_count: 2
informational_count: 1
owasp_categories: [A01, A05, A06]
cwe_ids: [CWE-613, CWE-1104, CWE-16]
---

# SECURITY REVIEW REPORT — BRANCH: 095-oauth-migration vs main

## Executive Summary

No Critical or High findings. **First code-level security review of F095** — the three
prior reviews covered planning artifacts only, never the implemented code.

The ported OAuth implementation is sound. Every primitive checked was correct rather
than merely present: SHA-256 at rest with `hash_equals`, S256-only PKCE with no `plain`
fallback, CSPRNG via `random_bytes`, byte-exact redirect-URI matching before any
redirect, rate limiting on all three sensitive endpoints, `%i` identifier placeholders
where a table name reaches SQL dynamically. The 15 un-prepared statements all
interpolate `$wpdb->prefix` + a hardcoded literal — identifier interpolation that
`prepare()` cannot parameterise.

Both Medium findings are consequences of scope decisions taken this week, not defects
in the ported code, and both were already accepted. They are recorded because an
accepted risk still belongs in the security record.

Scope note: `.specify/scripts/bash/detect-changed-files.sh` and
`.specify/memory/security_constitution.md` are both absent — the extensions are only
partially installed. Fell back to `git diff main...HEAD` and
`specs/095-oauth-migration/security-constraints.md` + constitution §III.

## Branch Diff Reviewed

    Target: 095-oauth-migration (7daac9d)
    Base:   main                (ea399d7)
    154 files changed, 26,271 insertions(+), 1,700 deletions(-)

## Vulnerability Findings

### [MEDIUM] SEC-001 — Revocation diverges across two token tables for n8n grants
- **Location**: `includes/Database/OAuthDataMigration.php:106-111`, `includes/OAuth/TokenValidator.php:82`
- **OWASP**: A01:2025-Broken Access Control · **CWE-613** · **CVSS 5.4**
- The migration copies the tokens table wholesale (only `WHERE id > %d` for cursor
  paging; no `connector_slug` filter), so every pre-split n8n token exists in both
  tables. Under option C, Pro keeps its tables and its own validator; mcp-manager's
  validator applies no slug filter either. Revoking in one does not revoke in the other.
- **Remediation**: assign one owner for `connector_slug = 'n8n'` rows.
- **Status**: accepted risk — bounded to pre-F095 rows, all expiring within ≤90 days.
- **Task**: TASK-SEC-001 → T087

### [MEDIUM] SEC-002 — Consent surface ships ON by default, against §III condition 3
- **Location**: `.specify/memory/constitution.md` §III:91, `includes/OAuth/AuthorizationController.php:115`
- **OWASP**: A05:2025-Security Misconfiguration · **CWE-16** · **CVSS 4.3**
- Condition 3 requires a default-OFF operator gate; the OAuth consent screen is enabled
  by default (decision C5). Medium not High because the surface is not open: verified
  `is_user_logged_in()` (:115), per-server enablement (:170), per-server approval (:192),
  nonce on the consent POST (:269), and binding to the consenting user's own account.
- **Remediation**: scope condition 3 to credentials exceeding the consenting user's own
  authority. MINOR amendment — proposal raised at
  `docs/constitution-proposals/2026-10-07-section-iii-condition-3.md`.
- **Task**: TASK-SEC-002 → T101

### [LOW] SEC-003 — Vulnerable transitive dev dependency (RESOLVED 2026-10-07)
- **Location**: `composer.lock` (packages-dev)
- **OWASP**: A06:2025 · **CWE-1104** · **CVSS 3.1 contextual**
- `squizlabs/php_codesniffer` 3.13.5, CVE-2026-67434 (OS command injection, `<3.13.6`).
  Pre-existing, transitive via `wp-coding-standards/wpcs`, and never shipped — both
  release workflows use `composer install --no-dev`.
- **Resolved**: upgraded to 3.13.6; `composer audit` clean; phpcs still reports 0/0.

### [LOW] SEC-004 — Two new third-party dev dependencies
- `szepeviktor/phpstan-wordpress` + transitive `php-stubs/wordpress-stubs`. Dev-only,
  do not ship. Net security effect positive: they are what made PHPStan analyse anything.
- **Remediation**: none; re-check at next dependency audit.

### [INFORMATIONAL] SEC-005 — `MAX_ADMIN_TTL_SECONDS` is now a single line of defence
- **Location**: `includes/OAuth/Repositories/AccessTokenRepository.php:38,63`
- The 90-day clamp was defence-in-depth behind `AdminTokenController`'s validator; T086
  moved that controller to Pro. Code unchanged and correct — its criticality rose.
  Already annotated so it is not relaxed on "the controller already checks" grounds.

## Confirmed Secure Patterns

| Control | Evidence |
|---|---|
| Credentials hashed at rest | `SecretsVault::hash()` SHA-256; `verify()` uses `hash_equals` (:46,:58) |
| CSPRNG | `random_bytes(32)` / `random_bytes($n)` — never `rand()`/`mt_rand()` (:34,:72) |
| PKCE downgrade-proof | `is_s256()` strict `'S256' ===`; correct base64url; `hash_equals` (PKCE.php:52,64) |
| Open redirect | `assert_redirect_uri_or_die()` at both entry paths (:96,:284) before the single `wp_redirect` (:352); loopback tolerance confined to RFC 8252 hosts |
| SQL injection | All value-bearing statements prepared; 15 un-prepared interpolate only `$wpdb->prefix` + literals; dynamic table name uses `%i`; migration stems from a `private const` |
| REST authorization | Every new route has an explicit `permission_callback`, zero `__return_true` (2/2, 14/14, 4/4); all 14 connector-admin routes share one gate enforcing `manage_options` + `wp_verify_nonce` |
| IP spoofing | `RateLimiter::client_ip()` returns `REMOTE_ADDR` unless the peer matches a configured trusted-proxy CIDR; XFF never trusted by default |
| Rate limiting | authorize 60/60s, token 60/60s, register 10/60s |
| Output escaping | `consent.php` 14 `esc_*`, `message.php` 8; zero raw `echo $var` |
| Fail-closed auth | All eight `TokenValidator` refusal paths return the caller's value; pinned by `TokenValidatorFailsClosedTest` |
| No disclosure (SC-C2) | Verified empirically: forced failure logged table + cursor + attempt count, "No row data is included by design"; zero 64-char hex, zero SQL text |
| No hardcoded secrets | Full `+`-line diff sweep returned nothing; the Freemius `pk_…` key and product ids removed by T086 confirmed gone |
