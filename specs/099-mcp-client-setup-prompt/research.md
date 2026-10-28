# Phase 0 Research — Generate-Prompt connect path

No `NEEDS CLARIFICATION` markers entered this phase: the spec carries none, and the four
`/speckit.clarify` decisions are already written into it. This document records the technical
decisions those answers imply, plus the one implementation question Q4 deliberately left open.

---

## R1 — Where the prompt is assembled

**Decision**: In the DTO producer, `ConnectionMethodRegistry::get_clients()`, from a new virtual
method on `AbstractMCPClient`. Both renderers consume the finished string.

**Rationale**: `D43 / DEC-CROSS-SURFACE-PARITY-UNIFY-AT-DATA-LAYER` requires that two renderers
showing one domain object share a DTO producer and never a component, and explicitly requires that
*"encoding decisions (JSON flags, date formats, escaping) MUST live in the DTO producer, not
per-renderer — otherwise byte-identity is impossible."* FR-003 demands byte-identity, so this is
not a stylistic preference. D43 names `get_clients()` as its own reference implementation.

**Alternatives considered**:
- *Build it in each renderer.* Rejected — this is the exact drift D43 exists to prevent, and FR-012
  (identical prompt on both surfaces) would depend on two implementations staying in step.
- *Build it in JavaScript from DTO parts.* Rejected, and worth stating plainly because it looks
  attractive: D43 also requires *"translation keys for shared user-facing strings MUST be identical
  across renderers."* Assembling translated prose in JS would key the same sentences twice and
  break byte-identity. **The React surface must consume `setupPrompt`, never rebuild it.**

---

## R2 — Virtual method with a default, not an abstract one

**Decision**: `get_setup_prompt( string $server_url, string $auth_token ): string`, non-abstract,
with a working default that needs zero per-client overrides.

**Rationale**: `AbstractMCPClient` is a public extension point — third parties register clients
through the `acrossai_mcp_client_classes` filter. An abstract method is a breaking change to every
such class (FR-021). The default composes four accessors that already carry all per-client
variation, so overrides are unnecessary. This follows the established precedent of
`get_restart_step_text()` and `get_config_files()`: additive, defaulted, non-breaking.

**Alternatives considered**: abstract method (breaks third parties); a separate builder class
(`A11` keeps `includes/MCPClients/` a pure service layer, and the data all lives on the client, so
a collaborator would only add indirection).

---

## R3 — Transport stays the npx bridge

**Decision**: The prompt embeds the existing `@automattic/mcp-wordpress-remote` configuration,
unchanged, for all sixteen clients.

**Rationale**: FR-004. The feature automates file editing, not configuration. Changing transport
and adding a prompt in one increment would make any connection failure ambiguous between the two.

**Alternatives considered**: native HTTP (`url` + `Authorization: Basic`), which needs no Node.js.
Verified against official vendor documentation for all sixteen clients and viable for fifteen, with
real per-client landmines — Gemini CLI uses `httpUrl` not `url`; Codex uses TOML `mcp_servers` with
`http_headers`; Cline requires exactly `streamableHttp` or it silently falls back to SSE and 405s;
VS Code uses `servers`; Zed uses `context_servers`; Windsurf uses `serverUrl`. **Claude Desktop
cannot do it at all** — static headers are UI-only there, so it would always need the bridge.
Recorded as deferred future work in the planning doc; explicitly not this increment.

---

## R4 — Translating prose without breaking byte-identity

**Decision**: Assemble the prompt from several translatable fragments, each `printf` seeing exactly
one placeholder style. Configuration file paths, the top-level key and the configuration block are
interpolated verbatim and never pass through translation.

**Rationale**: FR-026. `B16` records that mixing positional `%s` with numbered `%1$s` in a single
format string silently mislabels output — PHP accepts both and raises nothing. The prompt is
exactly the shape that triggers it: translated sentences wrapped around untranslated literals.
`B16`'s prescribed fix is to split into separate `printf` calls so each sees one style.

**Verification obligation**: a test must assert the configuration block is byte-identical between a
translated and an untranslated build. FR-003 depends on it and nothing else would catch a
translator localising a JSON key.

---

## R5 — Credential substitution is a format-agnostic string replace

**Decision**: Replace the `(paste generated password here)` placeholder by plain string
substitution, on both surfaces, for both the prompt and the configuration block. Change
`injectPasswordIntoConfig()` in `src/js/backend.js` to the same approach.

**Rationale**: FR-009 requires correct substitution for *every* client. The current
`injectPasswordIntoConfig()` parses the JSON and walks `serverBlock.env`, so it **silently fails for
OpenCode**, whose env key is `environment`. It also re-stringifies without `JSON_UNESCAPED_SLASHES`,
so the redisplayed JSON escapes slashes differently from the PHP original — a second, quieter
byte-identity break. `Step11_ClientDetail` already does the format-agnostic replace correctly.

**Alternatives considered**: teach the JSON walker about `environment` too. Rejected — it fixes one
client and leaves the next shape to fail the same way, and it keeps a second implementation alive
in violation of Principle VI. Standardising on the simpler approach removes a duplicate rather than
adding one.

---

## R6 — Where the administrator-only check lives

**Decision**: An explicit capability check on the prompt panel and the generation control. The
surrounding block's capability continues to come from the renderer's render context.

**Rationale**: Clarification Q1 made the feature administrator-only, but
`DEC-CLIENT-RENDERER-PUBLIC-API` and SEC-013-005 forbid hardcoding `manage_options` inside the
renderer, because the renderer exists to serve more than one context. Both hold at once when the
restriction is expressed at the panel rather than the block. Satisfying FR-024 by hardcoding the
capability in the renderer would violate the decision it is trying to honour.

---

## R7 — How credential issuance is recorded

**Decision**: Record issuance as an `EVENT_REQUEST` row with `event_source` set to the existing
`ActivityLogConstants::SOURCE_AUTH`. No new event type, no schema change.

**Rationale**: This is the question clarification Q4 left to planning. `SOURCE_AUTH` already exists
and has **no production consumer** — only a test asserts its value — so it was declared for exactly
this kind of event and is waiting for one. Its own docblock warns against the alternative:
*"`tool_name` of `_oauth_refresh` with the event stuffed into `result_status`. Do not repeat that."*
Encoding a new event kind into existing columns is a recorded anti-pattern here.

A new event type would mean a table-version bump with a matching `$upgrades` callback (`D28`), and
`B34` records that getting that pairing wrong silently loses writes. That risk is not worth taking
for a row the existing schema already expresses.

**Tradeoff, stated**: `EVENT_REQUEST` is retained 30 days, so the issuance record ages out on that
schedule. Acceptable because the credential itself remains listed in the Tokens tab indefinitely
(FR-019) — the log answers *where a credential came from*, while the credential's existence is
independently visible and independently revocable.

---

## R8 — Per-OS config paths reach both surfaces

**Decision**: The prompt names the path for every OS the client declares, taking them from
`get_config_files()` with a fallback to `get_config_file()`. `Step11_ClientDetail`'s manual panel is
upgraded to the same precedence.

**Rationale**: FR-013 and SC-006. `MCPClientsBlock` already prefers the per-OS map; Step 11 reads
only the single legacy `config_file`, which is the **macOS** path for `ClaudeDesktopClient`,
`VSCodeClient` and `GitHubCopilotClient` — so a Windows user in the wizard is told to edit
`~/Library/Application Support/…`. Without the upgrade, the new prompt would name correct paths
beside a panel naming a wrong one.

**Lesson worth recording**: both surfaces already read one DTO, exactly as D43 requires, and drifted
anyway — because a renderer can silently *ignore* a field the DTO carries. Sharing the producer is
necessary but not sufficient.

---

## R11 — Step 2 is the choice, and only the chosen route is visible

**Decision**: Step 2 presents the two routes as a choice; exactly one renders visible. Selection is
client-side, with a server-honoured `?setup=` parameter and real `href`s underneath.

**Rationale**: the first implementation rendered the prompt panel and the manual steps stacked in
one column. Both were correct and the screen was still wrong — with no boundary between them they
read as one long sequence, so an operator could not tell where the prompt ended, or that the two
were alternatives at all. The Claude Design file *MCP Client Config Steps* named this directly:
*"Step 2 becomes an explicit choice, and only the chosen path is shown, so the prompt and the
manual steps no longer read as one long sequence."*

**Why client-side, when the admin tab already reloads for everything else**: the Application
Password is shown exactly once and cannot be re-fetched. A reload mid-setup discards a credential
the operator may not have copied. That makes this different from switching client, where there is
nothing to lose. Both routes are therefore rendered and one is hidden, rather than the server
emitting only one.

**The trap, which we fell into**: `hidden` works only through the UA stylesheet's
`[hidden] { display: none }`, and **any** author rule setting `display` overrides it — including the
panel's own `display: flex`. The PHP emitted a correct `hidden` attribute and both routes rendered
visible anyway. Every markup assertion passed, because the markup was never wrong. The guard for
this asserts against the **built stylesheet**, and was verified by removing the rule and watching
it fail.

**Alternatives considered**: a segmented toggle (design frame 1c) — lighter, but carries less
affordance for a choice an operator makes once and wants explained; and server-rendering only the
chosen route — simpler, but loses the credential on every switch.

---

## R10 — Codex emits TOML, and the string branch of the contract finally has a user

**Decision**: `CodexClient::get_config_snippet()` returns a **TOML string** rather than an array.

**Rationale**: the class contradicted itself in three places. `get_config_file()` declared
`~/.codex/config.toml`, `get_top_level_key()` returned `mcp_servers`, and `get_instructions()` told
the operator to "copy the TOML snippet" — while `get_config_snippet()` returned an array that both
renderers JSON-encoded. The operator was handed JSON, labelled TOML, for a `.toml` path.

This was survivable while a human read the block and adapted. It stopped being survivable the
moment F099 generated a prompt instructing an AI client to *merge this configuration into
config.toml*: an assistant following that instruction either fails or writes a file Codex cannot
parse, and the operator cannot tell which happened.

**This is the first in-tree use of the `string|array` union.** `get_config_snippet()` has always
been documented that way, and `assertSnippetMatchesFixture()` has always had a `.txt` branch
alongside its `.json` one. Both were written for a case that had not yet arrived. It has.

**Scope note**: FR-004 says the configuration does not change. This is not an exception to it —
FR-004 protects the *transport* (the npx bridge stays, the env vars stay). What changed is the
*serialisation*, from a format Codex cannot read to the one it documents.

**Consequences, all verified**:
- `ConnectionMethodRegistry` (`is_array()` ternary) and `MCPClientsBlock:314` already branched on
  type. No change needed.
- `Step11_ClientDetail.jsx:139` already checks `typeof === 'string'`. No change needed.
- **`src/js/backend.js` did not.** `injectPasswordIntoConfig()` called `JSON.parse()` and would
  have thrown on TOML, with the `catch` swallowing it — password shown beside the button, config
  unchanged below, nothing explaining why. T031's format-agnostic replace was pulled forward from
  Phase 4 to make the Codex fix safe, and it repairs two further defects in the same stroke: the
  OpenCode `environment`-vs-`env` miss, and the `JSON_UNESCAPED_SLASHES` drift on re-encode.

**Alternatives considered**: leave Codex as-is and file an issue (rejected — the prompt actively
amplifies the defect); exclude Codex from the prompt path (rejected — it hides a broken client
rather than fixing it, and the manual path stays wrong either way).

---

## R9 — Credential route hardening, bounded

**Decision**: Harden the handler that actually serves `POST /generate-app-password`
(`admin/Partials/ApplicationPasswords.php`) with the availability check, the D33 access gate,
no-store headers, UUID retention and the prefixed name. Add no second registration.

**Rationale**: FR-014 through FR-019 and FR-023. D33 makes the access gate mandatory for every
connection-time credential-issuance surface, and this feature adds a prominent new entry point to
one that lacks it.

**Explicitly deferred**: two handlers currently register this route and only the first-registered
one ever serves a request, so the stricter Feature-013 handler is unreachable. Choosing a winner
changes a ratified security model and belongs in its own issue. Note for whoever writes it: the
unreachable handler exists *for* the public and embedded contexts, and the front-end exposure is
being removed — so that collision may resolve by deletion rather than by decision.

**No rate limiting** (FR-028). An administrator can already mint unlimited Application Passwords
through WordPress itself, so a limit here refuses legitimate work without removing capability from
the actor it restricts. Recording (R7) answers the same question after the fact.
