# Specification Quality Checklist: Per-server Ability Policy Defaults

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-05
**Feature**: [../spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — WordPress/PHP/BerlinDB references are unavoidable because this is a WordPress plugin spec; kept to the minimum needed for reviewer orientation and confined to the Module Placement / Storage / Requirements sections per the plugin's spec template.
- [x] Focused on user value and business needs — every user story is written from the site-administrator's perspective (Enable All that survives updates; Disable All that survives updates; backwards compatibility; per-row overrides still work; truthful counter).
- [x] Written for non-technical stakeholders — user stories, acceptance scenarios, and success criteria are plain-language; technical mechanism (resolver split, BerlinDB `$upgrades`) is confined to Module Placement, Storage, and Assumptions where reviewers expect it.
- [x] All mandatory sections completed — User Scenarios & Testing, Requirements, Success Criteria all present with full content.

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain — the planning-doc brief is complete; every open question in the original issue #95 was resolved during the plan phase (resolver split, three-tier priority, F030 hazard, migration path all decided).
- [x] Requirements are testable and unambiguous — FR-001..017 each cite a specific behaviour, boundary, or invariant that can be exercised by PHPUnit / MCP-client call / grep audit.
- [x] Success criteria are measurable — SC-001..007 each have a numeric threshold (≤ 3 clicks), a diff-based check (byte-for-byte GET responses), a HTTP-code check (403), or a subscriber-count check (exactly once per non-no-op transition).
- [x] Success criteria are technology-agnostic where possible — SC-001, SC-002, SC-003, SC-006 describe user-visible outcomes. SC-004, SC-005, SC-007 name technical artefacts (`GET /abilities` diff, F030 empty-meta call, subscribed test double) because the invariants they lock in are precisely technical — allowing a user-facing rewrite would make them unverifiable.
- [x] All acceptance scenarios are defined — five user stories × ≥ 2 Given/When/Then scenarios each.
- [x] Edge cases are identified — F030 bypass hazard, F015 deny precedence, F020 chain-through, adapter-absent degrade, no-op transition, invalid policy string, unknown server_id, unauthenticated caller, cache coherence, client-side merge removal.
- [x] Scope is clearly bounded — Assumptions section names single-site scope, dev/local-only install premise, no behaviour change to F040/F080, F015/F020 untouched.
- [x] Dependencies and assumptions identified — BerlinDB reconciler trust, `wp_get_abilities()` availability, MCP Adapter present at tool-call time, `delete_where` vs. raw prepared DELETE fallback.

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria — each FR maps to one or more acceptance scenarios or measurable SCs (FR-001..004 → US1/US2/US4; FR-005 → US5; FR-006 → US1 acceptance scenario 2; FR-007 → SC-005 F030 fence; FR-008 → US3 + SC-004; FR-009..010 → edge cases; FR-011..012 → SC-007; FR-013 → US1 counter; FR-014 → US3 migration; FR-015 → edge cases; FR-016 → edge cases; FR-017 → Assumptions).
- [x] User scenarios cover primary flows — Enable All money case, Disable All money case, per-row overrides, backwards compat, truthful counter.
- [x] Feature meets measurable outcomes defined in Success Criteria — the SC set is the merge gate.
- [x] No implementation details leak into specification — Requirements sections name technical artefacts (BerlinDB, PHPStan L8) only where the plugin's spec template explicitly asks for them (Module Placement, Storage, Definition-of-Done gates).

## Notes

- All items pass on the first review pass; no re-iteration required.
- The spec inherits the F082 planning doc's F030-hazard framing verbatim in FR-007 and SC-005 because the security invariant is the single most important thing the feature must preserve.
- Backwards-compat story (US3) is P1 alongside the two Enable/Disable All stories because a silent behaviour change on any pre-F082 install is a regression, not a feature.

## Post-clarification updates (2026-09-05)

`/speckit-clarify` ran and resolved 5 ambiguities that were Partial in the initial ambiguity scan (server-delete cascade, `$affected_slugs` shape, confirm-modal UX, header-pill accessibility, concurrent-edit semantics). All 5 answers are recorded in the spec's `## Clarifications` section and integrated into the relevant FR / Edge Cases / Admin UI / SC sections. New requirements FR-018 (cascade delete) and FR-019 (pill ARIA) added; FR-011 and SC-007 tightened to name the `was`/`now` map shape; Admin UI Requirements updated to name `<Modal>` explicitly and inherit focus-trap + Esc semantics; Edge Cases and Assumptions gained a "last-write-wins concurrency" note. All checklist items above still pass — the added content strengthened testability without introducing new ambiguities.
