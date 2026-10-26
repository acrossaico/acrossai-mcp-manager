# Specification Quality Checklist: Generate-Prompt connect path for MCP clients

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-26
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

All items pass on the first validation pass. Zero `[NEEDS CLARIFICATION]` markers were needed — the
detailed description supplied with `/speckit.specify` resolved every decision that would otherwise
have required one, including the two that mattered most (which transport the configuration uses,
and how much of the credential-route hardening is in scope).

Three qualifications on the passes above, recorded so a later reader does not mistake them for
oversights:

1. **"No implementation details" is scoped to the narrative sections.** Implementation detail is
   confined to the sections this project's `spec-template.md` requires to carry it — Module
   Placement, REST API Contract, Database / Storage and Security Checklist. User Scenarios,
   Functional Requirements and Success Criteria are written in outcome terms and name no language,
   framework or class. Specific file paths appear only in Module Placement, which exists to hold
   them.

2. **FR-017, FR-018 and FR-022 have no user-story acceptance scenario**, because they are not
   things an administrator performs. They are verified instead through Success Criteria (SC-010 for
   per-credential revocation) and the Security Checklist (no-store responses; hand-authored error
   messages). This is deliberate, not a coverage gap — but `/speckit.tasks` must still produce
   owning tasks for all three.

3. **Two items are stated as requirements precisely because they are not true today**, and should
   not be read as descriptions of current behaviour:
   - FR-014 (Application Password availability) — nothing in the plugin checks this at present.
   - FR-013 / SC-006 (per-operating-system config locations on both surfaces) — the wizard surface
     currently shows a single location, which is the macOS one for at least three clients.

   FR-009 / SC-004 is a third of the same kind: substitution is broken today for the one client
   whose credential block is named differently from the rest.

## Clarification session 2026-10-27

Four questions asked and answered, adding FR-024 through FR-028 and SC-014 through SC-017. All
sixteen checklist items above were re-validated afterwards and still pass.

| # | Decision | Added |
|---|---|---|
| Q1 | Admin-only everywhere | FR-024, SC-014 |
| Q2 | Mint new, keep old, show count + revoke link | FR-025, SC-015, repeat-generate edge case |
| Q3 | Prose translated, literals never | FR-026, SC-016 |
| Q4 | No rate limit; record every issuance | FR-027, FR-028, SC-017 |

Two of these changed existing text rather than only adding to it, and the edits are worth knowing
about:

- **FR-024 appeared to contradict the Security Checklist**, which says the capability must never be
  a hardcoded `manage_options`. Both are correct and the checklist item now says why: the
  surrounding block's capability stays contextual, and administrator-only is an additional check on
  the prompt panel. An implementer who hardcodes the capability in the renderer to satisfy FR-024
  has done it wrong.
- **Constitution §III's consent-surface exception is now explicitly unused**, recorded in
  Assumptions. Q1 ruled it out. If a later increment broadens access below administrator, FR-028's
  no-throttle decision must be revisited in the same breath — it rests entirely on the actor
  already holding administrator powers.

A fifth candidate question, canonical terminology, was not asked: the skill excludes stylistic
preferences. Set by fiat instead — **"setup prompt"** in code, **"Let your AI client do it"** in the
UI, and never "snippet", which already means the configuration block.

One scope boundary is load-bearing and worth re-reading before planning: the duplicate
credential-route registration is **explicitly deferred** to its own feature. FR-023 and SC-013 exist
to keep this increment from quietly growing into that work — the requirement is that the number of
credential-issuance paths stays at one.
