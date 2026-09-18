# Specification Quality Checklist: Server Types

> **Retracted, 2026-09-18 — the coarse tool rule is gone.** Anything below that
> describes `tools_default_policy` (the `expose` / `hide` standing rule, its
> column, and `POST /servers/{id}/tools/policy`) was built, merged to `main` in
> schema `1.1.6`, then removed again in schema `1.1.7` before any release.
> `MCP\ToolExposureGate` gates `tools/call` on curated presence rows alone and
> never read the column, so an `expose` server advertised tools it then refused.
> What a server serves is now decided by its curated selection alone; the Tools
> tab's **Enable All** / **Disable All** write that selection directly. The
> `server_type` half of this feature stands as specified. `abilities_default_policy`
> (F082, Abilities tab) is a different feature and is unaffected.


**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-15
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

**Validation run 1 — 2 failures found and fixed:**

1. *No implementation details* — FAILED initially. The User Scenarios and Functional
   Requirements sections named concrete classes, filter names, column names, method
   signatures and slugs (`ServerTypes`, `acrossai_mcp_server_types`, `server_type`,
   `tools_default_policy`, `applyReset()`, `toolset/*`, `acrossai/setup-required`,
   `column_exists()`). These come from the input, which was written as an implementation
   brief rather than a specification. Rewritten to describe *what* and *why*: "the server's
   kind", "the standing tool rule", "the companion add-on", "one entry whose description
   names the required add-on".

2. *Success criteria are technology-agnostic* — FAILED initially. SC items referenced REST
   routes, `tools/list` and column defaults. Rewritten as observable outcomes
   (SC-002 "changes what every pre-existing server advertises by exactly zero entries";
   SC-005 "a connected AI client receives exactly one self-describing entry").

**Validation run 2 — all items pass.**

**Deliberate retentions**, where the template itself requires technical content:
Module Placement, REST API Contract, Database/Storage and the Security Checklist are
template-mandated implementation sections and legitimately name classes, routes and tables.
The Definition of Done gates name the project's actual tools by design.

**Zero [NEEDS CLARIFICATION] markers** — the input was unusually complete. Every gap had a
defensible default, and each is recorded under Assumptions rather than deferred as a
question: unmet-dependency precedence over the standing rule; fallback for an unavailable
kind; operator ownership of the standing rule; no undo for a kind change.

**Clarification session 2026-09-15 (4 questions)** re-validated after each write. Two
answers changed existing spec text rather than only adding to it: the bulk-enable answer
added a scenario to User Story 2 (renumbered to stay sequential), and the type/kind answer
normalised 77 occurrences of "kind" to "type". One earlier scenario (US4 #5) asserted the
opposite of the clarified behaviour and was REPLACED, not appended to, with FR-027 rescoped
so the two no longer contradict. Re-checked: zero occurrences of "kind" remain, and no
implementation identifier leaked back into the stakeholder-facing sections.

**One judgement call worth flagging at planning time**: the spec directory is numbered `090`
to match the branch and `docs/planings-tasks/090-server-types.md`, not the `085` the
sequential scanner would have produced. Features 087, 088 and 089 are merged in `main` but
have no `specs/` directories, so the scanner under-reports. See the report for the wider
issue.
