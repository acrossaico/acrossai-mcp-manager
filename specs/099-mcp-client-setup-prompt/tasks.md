---
description: "Task list for Feature 099 — Generate-Prompt connect path for MCP clients"
---

# Tasks: Generate-Prompt connect path for MCP clients

**Input**: Design documents from `/specs/099-mcp-client-setup-prompt/`
**Prerequisites**: plan.md, spec.md (28 FRs, 17 SCs), research.md, data-model.md, contracts/, quickstart.md, security-constraints.md (SC-C1..C18)

**Tests**: INCLUDED. The Definition of Done requires PHPUnit coverage for all new PHP logic, `quickstart.md` is a test plan, and SC-C18 mandates an escaping test.

**Revision**: regenerated after the task security review (`docs/security-reviews/2026-10-27-099-mcp-client-setup-prompt-tasks.md`). Two HIGH findings moved work: the disclosure is now blocking for the MVP, and the issuance record is explicitly forbidden from carrying the credential.

## Format: `[ID] [P?] [Story] Description`

## Path Conventions

Single WordPress plugin at repo root: `includes/`, `admin/`, `public/`, `src/js/`, `tests/phpunit/`.

---

## Phase 1: Setup

- [x] T001 Confirm branch `099-mcp-client-setup-prompt` is current and rebased on `main` (includes the 0.4.5 fix)
- [x] T002 Record baseline gate state: `vendor/bin/phpunit --testsuite mcpclients` and `--bootstrap tests/bootstrap-wp.php --testsuite admin`; note counts in the PR description
- [x] T003 [P] Run `vendor/bin/phpstan clear-result-cache` then `composer phpstan` and confirm clean before any edit — a warm cache reports errors CI never sees

---

## Phase 2A: Credential route hardening (Blocking)

**Purpose**: The credential path must be safe and its data shape final before anything consumes it.

**Why first**: not merely "security first". T008 and T009 change the credential's **data shape** — the retained UUID and the new name format are what the later count display and naming tests depend on. These are structural prerequisites.

**⚠️ Runs in parallel with Phase 2B — they share no dependency.** T004–T011 are sequential among themselves (same file).

- [x] T004 [US1] Add Application Password availability checks (`wp_is_application_passwords_available()`, `..._for_user()`) to `admin/Partials/ApplicationPasswords.php::generate_app_password()`, returning a hand-authored `WP_Error` naming the cause — FR-014, FR-022, SC-C3, SC-C12
- [x] T005 [US1] Add the D33 access gate in `admin/Partials/ApplicationPasswords.php`: call `AcrossAI_MCP_Access_Control::user_has_server_access( $user_id, $server_id )` **before** issuing; fire `acrossai_mcp_access_control_denied` with a context string unique to this surface on denial; fail **open** when the package is absent — FR-015, SC-C4, D19, D33
- [x] T006 [US1] Lock issuance to `get_current_user_id()` in `admin/Partials/ApplicationPasswords.php`; a request-supplied `user_id` MUST NOT be honoured — FR-016, SC-C2
- [x] T007 [US1] Wrap the response in `CacheHeaders::apply_to_rest_response()` in `admin/Partials/ApplicationPasswords.php` — FR-017, SC-C5
- [x] T008 [US1] Capture and retain the `uuid` returned by `WP_Application_Passwords::create_new_application_password()` in `admin/Partials/ApplicationPasswords.php` — FR-018, SC-C6, B72
- [x] T009 [US1] Change `admin/Partials/ApplicationPasswords.php::build_app_name()` to carry `APP_NAME_PREFIX` **plus client, server and date**, so provenance outlives log retention — FR-019, SC-C7, SC-C16, B76
- [x] T010 [US1] Record each issuance from `admin/Partials/ApplicationPasswords.php` via `includes/Database/ActivityLog/Recorder.php` as `EVENT_REQUEST` with `event_source = ActivityLogConstants::SOURCE_AUTH`; do **not** smuggle the event name into `result_status` — FR-027, R7
- [x] T011 [US1] The issuance record MUST carry the credential **UUID and name only — never the password**. Key-name redaction cannot be relied on: `ActivityObservabilityHandler` matches 24 sensitive key patterns and a value outside them is stored as sent — FR-027, SC-C11, SEC-T-002
- [x] T012 [P] [US1] Test the gates in `tests/phpunit/Admin/Partials/ApplicationPasswordsGateTest.php`: clear error when unavailable; denial when access refused; **fails open** when the AC package is absent; no-store header present; name matches prefix; **and no written row contains any substring of the issued credential** — FR-014..019, SC-C3..C7, SC-C11, SEC-T-002, SEC-T-003
- [x] T013 [P] [US1] In `tests/phpunit/REST/GenerateAppPasswordRouteTest.php` assert exactly **one** handler serves `POST /generate-app-password` via `rest_do_request()` (not by calling `permission_check()` directly — that is how issue #193 stayed invisible), **and** that no rate limiter is wired to this route — FR-023, FR-028, SC-C8, SEC-T-006

---

## Phase 2B: Contract and DTO (Blocking)

**⚠️ Runs in parallel with Phase 2A.**

- [x] T014 Add `get_setup_prompt( string $server_url, string $auth_token ): string` to `includes/MCPClients/AbstractMCPClient.php` — **non-abstract, with a working default** composing `get_config_files()`/`get_config_file()`, `get_top_level_key()`, `get_config_snippet()` and `get_restart_step_text()`; zero per-client overrides — FR-001..007, contracts/client-contract.md
- [x] T015 In `includes/MCPClients/AbstractMCPClient.php`, honour the documented `string|array` return of `get_config_snippet()` via `is_array()`, encoding arrays with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` — FR-003, R1
- [x] T016 Assemble the prose in `includes/MCPClients/AbstractMCPClient.php` from separate translatable fragments, each `printf` seeing **one** placeholder style only; interpolate paths, top-level key and config block verbatim, never through translation — FR-026, B16, R4
- [x] T017 Add `setupPrompt` to the DTO in `public/Discovery/ConnectionMethodRegistry.php::get_clients()`, present **only** when `$server !== null`, built with an **empty** token so it carries `EMPTY_TOKEN_PLACEHOLDER` — FR-008, SC-C1, D43
- [x] T018 [P] Test all sixteen clients in `tests/phpunit/MCPClients/ConcreteClientsTest.php`: prompt contains the server URL, top-level key, `WP_API_USERNAME` **key**, every declared OS path, and the placeholder — and **no** credential — FR-001..007, SC-C9, B19
- [x] T019 [P] Test that a bare third-party subclass registered via `acrossai_mcp_client_classes` yields a usable prompt, in `tests/phpunit/MCPClients/AbstractMCPClientTest.php` — FR-021, SC-012
- [x] T020 [P] Test the config block is **byte-identical under translation** in `tests/phpunit/MCPClients/ConcreteClientsTest.php` — FR-003, FR-026, SC-016
- [x] T021 [P] Test `setupPrompt` is absent with no server and carries no credential when present, in `tests/phpunit/Public/Discovery/ConnectionMethodRegistryTest.php` — FR-008, FR-012, SC-C1

**Checkpoint**: credential path gated and recorded; prompt available in the DTO.

---

## Phase 3: User Story 1 — Hand the AI client the instructions (Priority: P1) 🎯 MVP

**Goal**: An administrator picks a client, generates a password, and copies one paste-ready block that connects their assistant — **and understands what they just exported**.

**Independent Test**: At `admin.php?page=acrossai_mcp_manager&action=edit&server=<id>&tab=connect`, pick Claude Code, generate a password, copy the prompt, paste it into a real client, confirm it connects.

- [x] T022 [US1] Render the prompt panel as a second route in step 2 of `public/Renderers/MCPClientsBlock.php::render_client_details()`, reusing `copy_config_button()` and the `.qs-step` markup — FR-001, FR-011
- [x] T023 [US1] In `public/Renderers/MCPClientsBlock.php`, gate the prompt panel and generation control on an explicit `manage_options` check **at the panel**; leave the block's capability coming from `context['cap']` — FR-024, SC-C10, R6
- [x] T024 [US1] Escape at the render boundary with named functions in `public/Renderers/MCPClientsBlock.php` — `esc_textarea()` for the prompt block, `esc_html()` for labels — SC-C18, SEC-035-002
- [x] T025 [US1] In `public/Renderers/MCPClientsBlock.php`, use plugin-owned CSS class names, not wp-admin ones (`.nav-tab`, `.notice`, `.wp-list-table`) — A22 binds while the shortcode is still registered
- [x] T026 [US1] In `src/js/backend.js`, substitute the real credential by format-agnostic replacement of `(paste generated password here)`, and clear it when the client changes — FR-009, FR-010
- [x] T027 [P] [US1] Test that switching client clears the displayed credential and restores the placeholder, in `tests/phpunit/Public/Renderers/MCPClientsBlockRenderTest.php` plus a JS-level assertion — FR-010, SEC-T-004
- [x] T028 [US1] **Blocking for this phase.** In `public/Renderers/MCPClientsBlock.php`, add the disclosure beside the copy control stating **what the credential can do** — that it acts as the full account across the whole site, not only this connection, and that hosted assistants retain it — FR-020, SC-C13, SEC-099-001, SEC-T-001
- [x] T029 [US1] **Blocking for this phase.** In `public/Renderers/MCPClientsBlock.php`, add a revocation affordance reaching the Tokens tab from the same panel — FR-020, SC-C14, SEC-T-001
- [x] T030 [P] [US1] Test both step-2 routes render, the prompt route is absent with no server, and a client class returning markup cannot inject it, in `tests/phpunit/Public/Renderers/MCPClientsBlockRenderTest.php` — SC-C18, FR-012

**Checkpoint**: shippable MVP — the feature *and* its disclosure. T028/T029 are not increments; they are conditions of the control existing.

---

## Phase 4: User Story 2 — Keep the manual path (Priority: P1)

**Independent Test**: the manual route's config block, top-level key and reload text are byte-unchanged from 0.4.5; a password generated on OpenCode lands in `environment`.

- [x] T031 [US2] **Done early, in Phase 2.** Replaced the JSON-walking `injectPasswordIntoConfig()` in `src/js/backend.js` with format-agnostic placeholder replacement. Pulled forward because the Codex TOML fix (R10) made it a prerequisite: `JSON.parse()` throws on TOML and the `catch` swallowed it. Also fixes OpenCode (`environment`, not `env`) and the `JSON_UNESCAPED_SLASHES` re-encode drift — FR-009, R5, R10
- [x] T031a [US2] Fix `CodexClient::get_config_snippet()` to emit TOML (done in Phase 2, see R10); regenerate `tests/phpunit/MCPClients/fixtures/codex-*.txt` and confirm the `.txt` branch of `assertSnippetMatchesFixture()` is exercised — R10
- [x] T032 [US2] Verify `public/Renderers/MCPClientsBlock.php`'s manual route content is byte-unchanged from 0.4.5 — FR-011, SC-011
- [x] T033 [P] [US2] Test substitution succeeds for **every** client including OpenCode, on both surfaces, in `tests/phpunit/Public/Renderers/MCPClientsBlockRenderTest.php` plus a JS-level assertion — FR-009, SC-004

---

## Phase 5: User Story 3 — Credential accumulation is visible (Priority: P2)

**Note**: the disclosure and revocation affordance moved to Phase 3 (T028, T029) per SEC-T-001. What remains here is the accumulation surface.

**Independent Test**: generating twice leaves the first credential working, and the panel shows how many exist with per-credential revocation.

- [x] T034 [US3] In `public/Renderers/MCPClientsBlock.php`, show how many credentials already exist for this server (via `GET /list-app-passwords`), with per-credential revocation — FR-025, SC-015
- [x] T035 [P] [US3] Test repeat generation leaves the first credential intact and both appear by name, in `tests/phpunit/Admin/Partials/ApplicationPasswordsGateTest.php` — FR-025, FR-019, SC-010, SC-015

---

## Phase 6: User Story 4 — Both surfaces agree (Priority: P2)

**Independent Test**: same server and client in both surfaces — prompt identical, per-OS paths in both, Claude Desktop shows the Windows path in the wizard.

- [x] T036 [US4] Render the prompt panel in `src/js/quick-connect/steps/Step11_ClientDetail.jsx`, consuming `setupPrompt` from the DTO — **never reassembling it in JavaScript**, which would break translation parity and byte-identity — FR-012, D43, contracts/dto-contract.md
- [x] T037 [US4] In `src/js/quick-connect/steps/Step11_ClientDetail.jsx`, upgrade the manual panel to render `meta.config_files` with the same OS labels and ordering as `MCPClientsBlock::render_os_config_paths()`, falling back to `config_file` — FR-013, SC-006, D64
- [x] T038 [US4] In `src/js/quick-connect/steps/Step11_ClientDetail.jsx`, keep rendering text as text via `CodeBlock`; never `dangerouslySetInnerHTML` — SC-C18
- [x] T039 [P] [US4] Test prompt parity and per-OS path parity across both surfaces, including Claude Desktop on Windows, in `tests/phpunit/Public/Renderers/MCPClientsBlockRenderTest.php` plus a wizard-level check — FR-012, FR-013, SC-006, SC-007

---

## Phase 6b: Route split (added mid-implementation)

Not in the original plan. Introduced by the Claude Design file *MCP Client Config Steps* after the
first implementation rendered both routes stacked, which made them read as one sequence rather
than alternatives. Recorded here so the shipped behaviour is traceable to a task.

- [x] T048 [US1] Make Step 2 the choice between routes in `public/Renderers/MCPClientsBlock.php::render_path_picker()`, with a recommended option and a cost for each — FR-029
- [x] T049 [US1] Render both routes and show only the chosen one, in both `MCPClientsBlock.php` and `src/js/quick-connect/steps/Step11_ClientDetail.jsx` — FR-030
- [x] T050 [US1] Default to the prompt and fall back to it on an unrecognised selection — FR-031
- [x] T051 [US1] Switch routes client-side in `src/js/backend.js` so a generated credential survives; keep real hrefs and server-side `?setup=` honouring as the no-JS path — FR-032
- [x] T052 [US1] Add `.acrossai-mcp-route-panel[hidden] { display: none; }` to `src/scss/backend.scss` — the panel's own `display: flex` overrides the UA `[hidden]` rule, so both routes rendered visible while every markup assertion passed — FR-030
- [x] T053 [P] [US1] Guard the above against the **built** stylesheet in `tests/phpunit/Public/Renderers/SetupPromptPanelRenderTest.php`; markup cannot prove visibility — SC-018

---

## Phase 7: Polish & Cross-Cutting Concerns

- [x] T040 [P] Confirm `includes/Main.php` gained **no** new hooks (A1) and no new `public/` class was added (D36)
- [x] T041 [P] Confirm `includes/Utilities/LocalEnvironment.php::needs_tls_bypass()` and `includes/MCPClients/AbstractMCPClient.php::build_env()` are untouched — `NODE_TLS_REJECT_UNAUTHORIZED` must stay ungated by URL scheme
- [x] T042 [P] Review **every** error branch this feature adds for hand-authored messages — no exception text, `$wpdb->last_error`, transient keys or filesystem paths — FR-022, SC-C12, SEC-T-005
- [x] T043 [P] Update `docs/planings-tasks/README.md` with the 099 row and mark the planning doc implemented
- [x] T044 Run the full gate set: `composer phpcs`; `vendor/bin/phpstan clear-result-cache && composer phpstan`; `npm run lint:js`; `npm run build` (no bundle drift beyond this feature); `npm run validate-packages`; all PHPUnit suites including `rename-gate`
- [ ] T045 **PARTIAL — credential path proven live, full walkthrough not done.** Walk `quickstart.md` end to end on the live site, ruling out B69 (Local's nginx drops `HTTP_AUTHORIZATION`) **before** diagnosing anything
- [ ] T046 **NOT DONE — the feature's central claim is still unverified.** Paste the generated Claude Code prompt into a real client and confirm it connects — every other check can pass while this fails
- [x] T047 Revoke the credential minted during T046 by its UUID, and confirm the operator's other credentials survive — B72

---

## Dependencies & Execution Order

### Phase Dependencies

- **Phase 1** → no dependencies
- **Phase 2A** and **Phase 2B** → depend on Phase 1, and on **each other: not at all**. Run them in parallel.
- **Phase 3 (US1)** → depends on 2A and 2B
- **Phase 4 (US2)** → depends on 2B; independent of US1
- **Phase 5 (US3)** → depends on Phase 3 (needs the panel)
- **Phase 6 (US4)** → depends on 2B; independent of US1 and US3
- **Phase 7** → depends on all stories

### Parallel Opportunities

- **Phase 2A ∥ Phase 2B** — different files entirely.
- Within 2A: T012, T013 `[P]`. T004–T011 are sequential (one file).
- Within 2B: T018–T021 `[P]`.
- **Phases 3, 4 and 6** proceed in parallel once Phase 2 completes — `MCPClientsBlock.php`, `backend.js`, `Step11_ClientDetail.jsx`.
- **Phase 7**: T040–T043 `[P]`.

---

## Implementation Strategy

**MVP = Phases 1, 2A, 2B and 3** (T001–T030). That delivers the feature on the Connect tab with the credential path gated **and the disclosure in place**.

The disclosure is deliberately inside the MVP, not after it. An earlier draft of this list put it in Phase 5, which made "export a full-administrator credential with no statement of what it can do" a legitimate stopping point. It is not one.

**Do not** resolve the duplicate-route collision here — tracked as [#193](https://github.com/acrossaico/acrossai-mcp-manager/issues/193). T013 only asserts this feature does not worsen it.

---

## Security Constraint Coverage

| Constraint | Owner |
|---|---|
| SC-C1 no credential in the DTO | T017, T021 |
| SC-C2 issue only for current user | T006 |
| SC-C3 availability checked | T004, T012 |
| SC-C4 D33 access gate, fails open | T005, T012 |
| SC-C5 no-store headers | T007, T012 |
| SC-C6 UUID retained | T008, T012 |
| SC-C7 name carries the prefix | T009, T012 |
| SC-C8 exactly one registration | T013 |
| SC-C9 username emitted | T018 |
| SC-C10 capability at the panel | T023 |
| SC-C11 no plaintext credential persisted anywhere | T011, T012 |
| SC-C12 hand-authored errors | T004, T042 |
| SC-C13 disclosure states scope | T028 |
| SC-C14 revocation one step away | T029 |
| SC-C15 FR-024 ↔ FR-020 coupling recorded | in spec Assumptions; verified by T043 |
| SC-C16 provenance in the credential name | T009 |
| SC-C17 duplicate-route issue filed | done — [#193](https://github.com/acrossaico/acrossai-mcp-manager/issues/193) |
| SC-C18 named escaping + injection test | T024, T030, T038 |

### Requirement coverage

All 28 FRs are referenced. FR-022 (T004, T042) and FR-028 (T013) were unreferenced in the previous revision and now have owners.
