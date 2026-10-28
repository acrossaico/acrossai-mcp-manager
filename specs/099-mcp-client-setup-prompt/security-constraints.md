# Security Constraints — Feature 099

Binding constraints distilled from the plan security review
(`docs/security-reviews/2026-10-27-099-mcp-client-setup-prompt-plan.md`), Constitution §III, and
`docs/memory/`. Every constraint below MUST have an owning task in `tasks.md`.

| ID | Constraint | Source |
|---|---|---|
| **SC-C1** | The prompt MUST be built server-side with an **empty** token. No credential may ever appear in the DTO, in a cached response, or in a logged payload. | FR-008 |
| **SC-C2** | A credential MUST be issued only for `get_current_user_id()`. A request-supplied user id MUST NOT be honoured. | FR-016, SEC-013-002 |
| **SC-C3** | `wp_is_application_passwords_available()` **and** `..._for_user()` MUST be checked before issuing, with a message naming the cause on failure. | FR-014 |
| **SC-C4** | `user_has_server_access()` MUST run **before** issuing; denial MUST fire `acrossai_mcp_access_control_denied` with a distinct context string; absence of the package MUST fail **open**. | FR-015, D33, D19 |
| **SC-C5** | Responses carrying a credential MUST be marked no-store via `CacheHeaders::apply_to_rest_response()`. | FR-017 |
| **SC-C6** | The returned `uuid` MUST be retained so one credential can be revoked without affecting the user's others. | FR-018, B72 |
| **SC-C7** | Issued credentials MUST carry `APP_NAME_PREFIX` so they appear in the Tokens tab and `/list-app-passwords`. | FR-019 |
| **SC-C8** | Exactly **one** registration of `/generate-app-password` MUST exist after this feature. No second issuance path. | FR-023, SC-013 |
| **SC-C9** | The prompt MUST emit `WP_API_USERNAME` alongside the password. | FR-005, B19 |
| **SC-C10** | The administrator check MUST sit on the **panel**, never hardcoded inside the renderer; the block's capability continues to come from render context. | FR-024, SEC-013-005 |
| **SC-C11** | Credentials MUST remain stored hashed by WordPress core. The plugin persists no secret. | S3 |
| **SC-C12** | Error messages MUST be hand-authored. No exception text, `$wpdb->last_error`, transient keys or filesystem paths in responses. | FR-022 |
| **SC-C13** | **The disclosure MUST state the credential's scope**, not merely its existence — that it acts as the full account across the site, and that pasting it into a hosted assistant sends it to that vendor. | **SEC-099-001** |
| **SC-C14** | Revocation MUST be reachable from the panel where the prompt was generated, not merely documented. | FR-020, SEC-099-001 |
| **SC-C15** | The interaction between administrator-only issuance (FR-024) and the disclosure (FR-020) MUST be recorded in Assumptions, so broadening access below administrator forces the disclosure to be revisited. | **SEC-099-002** |
| **SC-C16** | Issuance provenance MUST survive log retention. Satisfy it by encoding **client + server + date in the credential name**, so the Tokens tab carries provenance indefinitely (FR-019 already mandates the name prefix). Extending the retention API with a source dimension is explicitly **not** required and is discouraged — `prune_by_event_type()` is keyed on event type by design (`Query.php:644`, "never one global cutoff"). | **SEC-099-003** |
| **SC-C17** | The deferred duplicate-route issue is filed as [#193](https://github.com/acrossaico/acrossai-mcp-manager/issues/193). This feature MUST NOT resolve it, and MUST NOT add a third registration. | **SEC-099-004** |
| **SC-C18** | The prompt MUST be escaped with a named function at each render boundary (`esc_textarea()` for the block, `esc_html()` for labels); React MUST use text nodes only. A test MUST prove a client class returning markup cannot inject it. | **SEC-099-005**, SEC-035-002 |

## Added by the task security review (2026-10-27)

| ID | Constraint | Source |
|---|---|---|
| **SC-C19** | The issuance record MUST carry the credential **UUID and name only — never the password**. Key-name redaction is not a defence: `ActivityObservabilityHandler` matches 24 patterns and a value outside them is stored as sent. A test MUST assert no written row contains any substring of the issued credential. | **SEC-T-002** |
| **SC-C20** | The disclosure (SC-C13) and the revocation affordance (SC-C14) MUST ship in the **same increment as the copy control**, never in a later phase. An MVP that exports a full-administrator credential without stating its reach is the exact state SC-C13 exists to prevent. | **SEC-T-001** |
| **SC-C21** | Clearing the displayed credential when the selected client changes (FR-010) MUST be tested, not merely implemented. A stale credential from one client shown under another is invisible in review. | **SEC-T-004** |
| **SC-C22** | FR-028's "no rate limiting" is a **negative** requirement and MUST be asserted, not merely documented — otherwise a later contributor adds a limiter "for safety" and silently reverses a recorded decision. | **SEC-T-006** |

## Accepted risks

- **No rate limiting on issuance** (FR-028). An administrator can already mint unlimited
  credentials through core. Compensated by FR-025's visible count and FR-027's recording. Must be
  revisited if access is ever broadened below administrator.
- **Clipboard exposure.** Inherent to any copy-paste credential flow, including the manual path.
- **The unreachable stricter handler.** Pre-existing; this feature reduces its impact by porting
  the gates onto the handler that actually serves traffic. Tracked by SC-C17.
