# Implementation Plan: Client config paths + restart phrasings — upstream-docs verification pass

**Branch**: `078-client-config-upstream-fixes` | **Date**: 2026-08-24 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/078-client-config-upstream-fixes/spec.md`

## Summary

Nine `MCPClient` subclasses receive surgical edits after per-client verification against upstream documentation (see `research.md` for citation URLs). Three fixes are P1 correctness (Kilo Code shape refactor, VS Code + GitHub Copilot path fix); six are restart-phrasing polish (Cursor, Windsurf, Zed, Antigravity, Amazon Q, Roo Code) to match upstream's recommended lightweight reload action; two are optional cleanups (Zed drops non-canonical `source`/`enabled` keys; Amazon Q instructions note the new `default.json` GUI default). No REST/DTO/JSX changes.

## Technical Context

**Language/Version**: PHP 8.1+ (plugin baseline).
**Primary Dependencies**: Existing `AbstractMCPClient` contract; existing `OpenCodeClient` provides a template for the "command as array + environment key" shape that Kilo Code's new format uses.
**Storage**: None.
**Testing**: PHPUnit `mcpclients` suite. Golden fixtures for `VSCodeClient` + `GitHubCopilotClient` need updating in lockstep with the path change; F071-batch clients (`Kilo`/`Roo`/etc.) don't ship fixtures.
**Target Platform**: WordPress admin (PHP 8.1+); values propagate to both the per-server MCP Clients tab (PHP) and Quick Setup wizard Step 11 (React reads from DTO).
**Project Type**: WordPress plugin.
**Performance Goals**: N/A — pure text/shape edits.
**Constraints**: FR-016 preservation of all F075/F076/F077 behavior.
**Scale/Scope**: 9 client files edited (~150 LOC touched net, mostly method-return edits and small array shape refactor for Kilo Code); 2 fixture files updated.

## Constitution Check

| Principle | Compliance | Notes |
|---|---|---|
| **I. Modular Architecture** | ✅ | 9 isolated subclass edits; no cross-module refactor. |
| **II. WordPress Standards** | ✅ | PHPCS + PHPStan L8. All string returns wrapped in `__()` per existing convention. |
| **III. Security First** | ✅ | No new input surface. Hardcoded literal returns; existing escaping in renderers unchanged. |
| **IV. User-Centric Design** | ✅ | Pre-approved exception applies (parent-menu). |
| **V. Extensibility** | ✅ | `AbstractMCPClient` contract unchanged; the 9 subclasses just update their own return values. Companion plugins' subclasses unaffected. |
| **VI. Reusability & DRY** | ✅ | Kilo Code's new shape reuses `OpenCodeClient`'s pattern (command as array, `environment` key). |
| **VII. Definition of Done** | 🟡 (verify) | Gates + fixture updates in Phase 2. |

## Project Structure

```text
specs/078-client-config-upstream-fixes/
├── spec.md              # /speckit.specify (this feature)
├── plan.md              # This file
├── research.md          # Phase 0 — 15+ upstream doc URLs cited per client
├── quickstart.md        # Phase 1 — per-client verification runbook
├── tasks.md             # Phase 2 (/speckit.tasks)
└── checklists/
    └── requirements.md  # ✅ all boxes ticked
```

Source touched:

```text
includes/MCPClients/
├── VSCodeClient.php               # config_file + instructions + restart
├── GitHubCopilotClient.php        # config_file + instructions + restart
├── KiloCodeClient.php             # config_file + top_level_key + config_snippet + instructions + restart
├── CursorClient.php               # restart
├── WindsurfClient.php             # restart
├── ZedClient.php                  # config_snippet (drop source/enabled) + restart
├── AntigravityClient.php          # restart
├── AmazonQClient.php              # instructions (note default.json) + restart tweak
└── RooCodeClient.php              # restart

tests/phpunit/MCPClients/fixtures/
├── vscode-{with,empty}-token.json         # regenerate if shape changed
└── github-copilot-{with,empty}-token.json # regenerate if shape changed

README.txt                         # Unreleased bullet
```

## Phases

### Phase 0 — Research

15+ upstream doc URLs cited in `research.md` (one per client verified). All fixes trace to specific upstream doc sections.

### Phase 1 — Design & Contracts

**Data model**: N/A. **Contracts**: N/A. **Quickstart**: per-client verification runbook with the exact grep for each SC criterion.

### Phase 2 — Post-Design Constitution Check

- ✅ No new violations. Every FR preserves the existing `AbstractMCPClient` contract; only concrete return values change.
- ✅ Zero-storage, zero-new-input; security-review.staged expected zero findings.

Handoff to `/speckit.tasks`.
