# Specification Quality Checklist: Migrate OAuth + AI Connectors from acrossai-pro

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-06
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

### Validation iteration 1 — issues found and fixed

1. **Implementation detail leaked into requirements.** The first draft named specific classes, file paths, line numbers and table-column names inside FR items. Rewritten so the FRs state *what must be true* (digests transferred unaltered, identifiers unchanged, migration idempotent) and the concrete file inventory lives in the planning document where it belongs.
2. **Success criteria were technology-flavoured.** Items referencing SHA-256 columns and PHP classes were restated as observable outcomes — "zero connected clients require re-authorisation", "row counts match exactly", "every route registered exactly once".
3. **Scope boundary for the companion was implicit.** Added FR-022 through FR-025 as explicit non-goals, because "do not touch the companion" and "do not change the version floors" are the constraints most likely to be violated by a well-meaning implementer.
4. **Edge case gap.** Added the case where a site's only traffic is AI clients hitting the token endpoint and no operator ever loads an admin screen — a migration triggered solely from an admin-side pass would never run there.

### Validation iteration 2 — clarification resolved

**Question 1 answered 2026-10-06: Option A — delete both wizard steps, repurpose neither.**

Spec updated accordingly:
- FR-016 rewritten to require deleting *both* the promotional step and the add-on-setup step, plus the full licence gate behind them (the method grid's paid marking, the router's skip predicate, and the controller's licence check).
- FR-017 added as an explicit non-goal: no replacement promotion is introduced by this feature.
- Subsequent requirements renumbered; the set is now FR-001 … FR-026 with no duplicates and no gaps.
- An assumption records the accepted trade-off — the product temporarily loses its main in-wizard surface for the paid Abilities library, and re-establishing it is a deliberate follow-up.
- The "Outstanding Clarification" section removed.

**All 16 checklist items now pass.**

### Validation iteration 3 — `/speckit.clarify` session 2026-10-06

Four questions asked and answered; a fifth was not asked because no remaining ambiguity met the impact threshold. Spec grew from 273 to 287 lines; FR set is now FR-001 … FR-027, SC-001 … SC-011, contiguous with no duplicates.

| # | Question | Answer |
|---|---|---|
| 1 | Migration trigger — admin-side only, or also the OAuth request path? | Admin-side only; upgrade window accepted and documented |
| 2 | Data volume — single-pass or batched with cursor? | Batched with persisted cursor |
| 3 | Failure visibility | Silent on success/skip; Site Health critical + log on repeated failure |
| 4 | Authorisation policy | Preserve the companion's layered policy exactly |

Notable during this session:

- **Q1 resolved a genuine self-contradiction.** Edge Cases asserted migration "must not depend solely on an admin-side trigger" while Database/Storage specified exactly that. Checked BerlinDB (`Kern/Table.php:1337` hooks `maybe_upgrade` to `admin_init` alone; the only other call site is `is_testing()`) and this repo's five existing one-shot migrations (all `admin_init`, priorities 3–6, ordering documented as load-bearing). House pattern followed; the contradictory edge case was replaced, not duplicated.
- **Q2 was decided against the measured evidence, deliberately.** Live counts from acrossai.co: 11 clients, 520 tokens, 0 auth codes, 1 approval — 532 rows, which single-pass would handle trivially. Batched was chosen anyway for robustness on customer sites whose volumes cannot be observed before they fail. The measurement is recorded in Database/Storage as the baseline for choosing a batch size.
- **Q4 corrected an overstated risk.** An earlier framing claimed a permissive default would let a low-privileged user grant a token "bound to the site". Verified otherwise: tokens bind to the consenting user and carry only that user's capabilities. The real policy is layered — logged-in floor, operator must enable the connector per server, per-server approval toggle, unrecognised DCR clients always require approval, admins self-approve with an audit event.

### Carried into planning

- Wizard paid-gating spans ~400+ lines across five surfaces, not the single step the originating planning document recorded.
- Batch size is unspecified by design — a planning-phase decision informed by the 532-row baseline.
- `docs/planings-tasks/095-oauth-migration.md` TASK-5 still says "rewrite `Step8_ProPromo.jsx`"; the spec supersedes it and the two should be reconciled.

**Spec is ready for `/speckit.plan`.**

### Scope discovery worth carrying into planning

The wizard's paid-gating is larger than the originating planning document recorded. It spans the method grid, a promotional step, an add-on setup step, the wizard router's skip predicate, and a licence check in the Quick Connect REST controller — roughly 400+ lines, not the single step originally identified. Planning must budget for removing the whole gate, not one screen.
