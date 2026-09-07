# Specification Quality Checklist: Merge the five connection tabs into one "Connect" tab

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-09-07
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

**Validation performed 2026-09-07. Two iterations.**

Iteration 1 findings (all corrected in the spec before this checklist was finalised):

1. *No implementation details* — FAILED initially. The first draft named concrete artefacts in the
   user-facing sections: the query parameter `?method=`, class names (`ConnectTab`,
   `ConnectPanelRegistry`), the filter name `acrossai_mcp_manager_connect_panels`, and the method
   `LocalEnvironment::needs_tls_bypass()`. Rewritten to describe behaviour instead — "addressable by
   a stable link", "a documented extension point", "the plugin's current local-environment
   detection". Concrete names now appear **only** in the two sections the template designates for
   them (Module Placement and Security Checklist), which are explicitly implementation-facing.
2. *Success criteria technology-agnostic* — FAILED initially. SC entries referenced query-string
   shapes and class names. Rewritten around observable outcomes and counts (8 tabs vs 11, 100% of
   legacy addresses resolving, action buttons responding, three navigation rows distinguishable
   without interaction).
3. *Requirements testable* — PARTIAL initially. Several FRs said "should keep working" without
   naming the observable. Tightened: FR-003 names what "no loss of behaviour" means (forms submit,
   config blocks copy, buttons respond); FR-011 enumerates the shortcut invariants; FR-018 gives the
   exact suppression threshold.

Iteration 2: re-reviewed all sixteen items; all pass. No [NEEDS CLARIFICATION] markers were needed —
the planning brief resolved every material ambiguity in advance (tab label and slug, method order,
local default, the `?method=` parameter choice, in-place resolution over redirect, the
five-pill-not-four decision).

**Deliberate template deviations** (both sanctioned by the template's own instructions to remove
non-applicable sections rather than mark them N/A):

- *REST API Contract* — retained as a one-line explicit statement that no routes change, because a
  reader of a navigation feature may reasonably expect a REST surface; stating its absence is more
  useful than deleting the heading.
- *Database / Storage* — same treatment, using the template's "No persistent storage" option.

**Cross-plugin note for the planning phase**: this specification covers the observable behaviour of
both plugins, but the companion (`acrossai-pro`) changes live in a separate repository and ship as a
separate pull request. The Definition-of-Done gate requiring the companion change to be merged or
scheduled is the tracking mechanism.

---

## Clarification session — 2026-09-07 (re-validation)

Two questions asked and answered; both integrated. All sixteen checklist items **re-verified as
passing** after integration.

1. **Level-2 terminology** → "method" everywhere. Applied to Module Placement class names
   (`Connect\MethodRegistry`, `RegistryEntryNormalizer`) and noted as governing the extension point
   and docs. No user-facing section changed, because the spec already used "connection method"
   throughout — this resolved a drift between the spec and the planning brief, not within the spec.
2. **Un-migrated companion** → no compatibility layer; the plugins are a matched pair. This
   **reduced** scope: FR-014 changed from "absorb old registrations" to "declare a minimum companion
   version", User Story 4 was rewritten around the matched pair, SC-004/SC-005 were re-pointed, one
   Edge Case was added recording the unsupported pairing's symptoms, and one Assumption was replaced.

Re-validation notes:

- *No implementation details* — still passes. The clarification bullets name classes and a filter,
  which is acceptable: the Clarifications section records decisions verbatim, and the template's own
  Module Placement section is implementation-facing by design. The user-facing sections (stories,
  functional requirements, success criteria) remain free of them.
- *No contradictory earlier statement remains* — verified by search: the only surviving mentions of
  the removed compatibility layer are (a) the clarification bullet that records its removal and
  (b) the Edge Case that documents the resulting symptoms. Both are intentional.
- *Scope clearly bounded* — improved. The unsupported plugin pairing is now explicitly out of scope
  with its symptoms documented, rather than being an implicit requirement.

**Impact on the planning brief**: `docs/planings-tasks/084-connect-tab-merge.md` predates both
clarifications and still describes the old names and the compatibility layer. It was re-synced to
match this spec immediately after this session; the spec is authoritative where they differ.
