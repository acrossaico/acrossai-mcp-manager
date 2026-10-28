# Memory Synthesis

## Current Scope

Feature 099 — a paste-ready setup prompt that has the AI client edit its own config file, offered
beside the existing manual steps, for all sixteen clients. Modules: `includes/MCPClients/`
(contract), `public/Discovery/ConnectionMethodRegistry` (DTO producer),
`public/Renderers/MCPClientsBlock` (PHP surface), `Step11_ClientDetail` (React surface),
`admin/Partials/ApplicationPasswords` (issuance). No new storage, route, or transport change.

## Relevant Decisions

- **D43 / DEC-CROSS-SURFACE-PARITY-UNIFY-AT-DATA-LAYER** — two renderers showing one domain object
  MUST share a DTO producer, never a component. Encoding lives in the producer; fields additive;
  enrichment opt-in by param; **translation keys identical across renderers**. (099 adds exactly
  such a field. Active. Names `get_clients()` as reference impl.)
- **DEC-CLIENT-RENDERER-PUBLIC-API** — cap from `context['cap']`, never hardcoded; App Password
  locked to `get_current_user_id()`; cross-context nonce bound to server + context. (Active **but
  narrowing** — front-end exposure is being removed; see Conflict Warnings. The
  `get_current_user_id()` lock survives regardless.)
- **D33 / DEC-OAUTH-AUTHORIZE-AC-GATE** — every connection-time credential-issuance surface, App
  Passwords explicitly included, MUST call `user_has_server_access()` before issuing. (Reason:
  FR-015. Status: Active.)
- **D19** — fail-open paths fire a scoped `do_action()` so operators can observe the anomaly.
  (FR-015's AC-absent branch. Active.)
- **D36** — new `public/` classes are `final`; extension via filters only. (Bounds how the prompt
  builder may be overridden. Active.)

## Active Architecture Constraints

- **A22** — `public/Renderers/` MUST NOT use wp-admin-only CSS classes or `backend.scss`, because
  the block is shortcode-exposed front-end where admin CSS never loads. (Status: **dissolving** —
  the shortcode exposure is being removed, so the rationale disappears with it. Still true while
  the shortcode is registered; see Conflict Warnings for the ordering.)
- **A1** — all hook registration in `includes/Main.php`. 099 expects to add none.
- **A11** — `includes/MCPClients/` is a pure service layer: no state, no ctor args, no hooks. The
  prompt builder must stay pure.
- **A18** — WP-free bootstrap is the contract for `tests/phpunit/MCPClients/`.
- **A9** — constants read by ≥2 modules belong in `includes/Utilities/`.

## Accepted Deviations

- **DEV5** — per-server tab sub-forms may use hand-rolled admin HTML instead of `DataForm` when
  ≤3 fields with no filtering. Covers the Connect tab's existing markup.
- **DEV1** — the parent menu keeps `WP_List_Table`. Not extendable to new screens.

## Relevant Security Constraints

- **S3** — App Passwords stored hashed, never plaintext. 099 persists no credential, only the
  non-secret UUID (FR-018).
- **SEC-013-002** — mint only for `get_current_user_id()`, never a request-supplied id (FR-016).
- **SEC-035-002** — DTO strings are deliberately unescaped; consumers escape at render.

## Related Historical Lessons

- **B19** — client-config generators MUST emit both `WP_API_USERNAME` and `WP_API_PASSWORD`;
  password-only configs silently 401 with nothing in wp-admin explaining why. Directly binds FR-005.
- **B16** — mixing positional `%s` with numbered `%1$s` in one `printf` silently mislabels output.
  Sharp edge for FR-026: the prompt is assembled from translatable fragments, so each `printf` must
  see one placeholder style. Prefer split calls.
- **B72** — `wp user application-password delete --all` destroys every credential on the user;
  capture the UUID and delete by UUID. Binds FR-018 and FR-025's accumulation.

## Conflict Warnings

- **Planned removal changes two constraints, and it is good news.** The front-end exposure of the
  client renderer — the shortcodes and action dispatcher in `includes/REST/ClientRendererController`
  — is being removed; the renderers keep serving the admin Connect tab, and
  `public/Discovery/ConnectionMethodRegistry` survives. Consequences for 099:
  - **A22 dissolves.** No front-end render means no admin-CSS trap. Until the shortcode is actually
    unregistered it still binds, so if 099 lands first, keep the panel's class names plugin-owned.
    If the removal lands first, this stops mattering entirely.
  - **FR-024's tension evaporates.** The admin-only-versus-`context['cap']` conflict existed only
    because the block rendered in public contexts. With those gone, administrator-only is the
    natural state rather than an exception needing explanation. The spec's current wording stays
    correct either way, which is why it needs no edit now.
  - **The deferred duplicate-route question may resolve by deletion.** The second
    `/generate-app-password` handler in `ClientRendererController` exists *for* the contexts being
    dropped. If those go, it may go with them — resolving the collision FR-023 and SC-013 fenced
    off, without anyone picking a winner. Check before writing that separate issue.
- **Soft — D43 needs a refinement, and 099 is the evidence.** D43 was followed structurally (both
  surfaces read one DTO) yet drift happened anyway: `Step11_ClientDetail` ignores `meta.config_files`
  that the DTO already carries, so the wizard shows macOS paths to Windows users for at least three
  clients. Sharing the producer is necessary but not sufficient — a renderer can silently ignore a
  field. 099 fixes the instance; the lesson is worth capturing.
- **Binding consequence of D43 for this feature.** Because translation keys must be identical across
  renderers, the React surface MUST consume `setupPrompt` from the DTO and MUST NOT reassemble the
  prompt client-side. Rebuilding it in JS would break both translation parity and FR-003's
  byte-identity.
- **A22 × FR-024.** On the front end the prompt panel will not render at all, but the surrounding
  block still does — the manual path must stay A22-clean regardless.

## Retrieval Notes

Optimizer disabled → markdown-only, index-first. The 174 KB `INDEX.md` was not read whole; rows
selected by scope plus known IDs. ~18 entries considered; source sections read for D43, B16 and A22
only. Within budget: 5 decisions, 5 architecture constraints, 2 deviations, 3 security constraints,
3 bug patterns, 0 worklog. `architecture_constitution.md` and `security_constitution.md` absent;
baseline is `constitution.md` v1.1.1 plus `docs/memory/`. No hard conflicts — planning may
proceed.
