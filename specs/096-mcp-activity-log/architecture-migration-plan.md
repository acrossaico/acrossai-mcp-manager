# Capture-Bracket and Tab-Mount Consolidation — Migration Plan

Covers the two P1 refactor tasks. The three lower-priority ones (handler resolver, hook-name
constant, constitution layout block) are single-file edits needing no phasing and are omitted.

Both P1 items share a shape: **an abstraction the codebase already has, or should have before its
second use, being bypassed at the point of second use.** Neither is a rewrite. Both are cheapest
*before* implementation, because the duplication they prevent does not exist yet — this plan is a
pre-emptive migration, not a cleanup.

## Current State

```
Planned, pre-fix:

  Base_Toolset_Ability::do_execute()  ──┐
      try { … } finally { … }           ├──> CaptureContext   (two hand-written brackets)
  Abilities\Execute                   ──┘

  McpTrackerTab extends AbstractServerTab
      render_body() { hand-rolled wp_enqueue + div + initialState }

  EmbedsTab      extends AbstractReactMountServerTab   ← the primitive, already consumed once
```

### Problems

- The `try`/`finally` is written twice (T053, T054). Two sites is the threshold at which
  Principle VI requires extraction, and the duplicate can drift: a fix to arming or disarming
  applied to one path only yields a fault visible through one dispatcher. The disarm guarantee is
  F097's contract (FR-012, SC-018), so drift breaks a cross-feature promise.
- `McpTrackerTab` would hand-roll a React mount that `AbstractReactMountServerTab` already
  provides and `EmbedsTab` already uses — a Principle VI breach with a live counter-example in
  the same directory.

## Target State

```
  Base_Toolset_Ability::do_execute()  ──┐
      return CaptureContext::around()   ├──> CaptureContext::around( $ability, $run )
  Abilities\Execute                   ──┘        arms, invokes, disarms in finally

  McpTrackerTab  extends AbstractReactMountServerTab   ← same primitive as EmbedsTab
  EmbedsTab      extends AbstractReactMountServerTab
```

### Benefits

- One bracket, one `finally`, one test (T063) covering both choke points instead of two tests
  that can pass independently while the paths diverge.
- Each choke point holds one delegating line and no control flow, which keeps the Principle V
  footprint in those two existing files as small as it can be.
- The tab inherits mount behaviour already proven by `EmbedsTab`, and future server tabs gain a
  second worked example rather than a second pattern.

## Migration Phases

### Phase 1: Bracket consolidation (estimated: 0.5 day)

**Goal**: both choke points delegate to one wrapper.

- **Task 1.1**: Add `CaptureContext::around( string $ability, callable $run ): mixed` — arm,
  invoke, disarm in `finally`, return the callee's value unchanged. Build it in T050, where
  `CaptureContext` is created.
- **Task 1.2**: Amend T053 so `Base_Toolset_Ability::do_execute()` delegates to the wrapper
  rather than writing its own bracket.
- **Task 1.3**: Amend T054 identically for `Abilities\Execute`.
- **Task 1.4**: Re-point T063's thrown-ability test at the wrapper, so one assertion covers both
  paths.

**Coexistence**: none required. The brackets do not exist yet; this changes what gets written.
If the wrapper proves awkward for one choke point mid-implementation, that path may keep an
inline bracket **provided** it is recorded as an accepted deviation naming which path and why —
silent divergence is the outcome this phase exists to prevent.

### Phase 2: Tab-mount alignment (estimated: 0.5 day)

**Goal**: `McpTrackerTab` consumes the existing primitive.

- **Task 2.1**: Read `AbstractReactMountServerTab` and `EmbedsTab` together to establish how the
  entry handle and per-server `initialState` are supplied.
- **Task 2.2**: Confirm the primitive can express this feature's one extra requirement — the same
  app mounted at two points, one of which hides the Server column. The global page is a normal
  admin page and does not use the tab base at all, so only the per-server mount is in scope.
- **Task 2.3**: Amend T046 to `extends AbstractReactMountServerTab`, dropping the hand-rolled
  mount.
- **Task 2.4**: If 2.2 fails, keep `AbstractServerTab` and record an accepted deviation in
  `plan.md` naming the specific capability the primitive lacks.

**Coexistence**: `McpTrackerTab` and `EmbedsTab` already coexist on different base classes today,
so there is no interim state to manage. The migration is a base-class change in one file.

## Coexistence Strategy

**Why coexistence?** Normally to avoid a big-bang rewrite. Here it barely applies, and that is the
point of doing this now: both targets are **unwritten code**. The migration changes what
implementation produces rather than converting anything live.

**How**:
- New code uses the target pattern immediately — the wrapper, the primitive.
- No old code exists on the old pattern, because the brackets and the mount are not yet written.
- The one genuine coexistence is `AbstractServerTab` and `AbstractReactMountServerTab` remaining
  side by side, which is already true and intended: tabs that render plain HTML keep the former.
- Escape hatch in both phases: an accepted deviation, written down with its reason, rather than
  an undocumented divergence.

## Rollback Plan

Both phases are revertible by a single edit, and neither changes behaviour:

- Phase 1: inline the bracket at each choke point again. `CaptureContext`'s arm/disarm API is
  unchanged by the wrapper, so nothing else moves. The only loss is the single-test guarantee.
- Phase 2: restore `extends AbstractServerTab` and the hand-rolled `render_body()`. The React app
  and its REST surface are untouched — only the mount differs.

Neither rollback affects storage, the capture contract, or F097's dependency, so neither can
leave the feature half-migrated.

## Success Criteria

- [x] `CaptureContext::around()` exists and is the only place a `try`/`finally` brackets an
      ability run
- [x] `grep -c "finally" includes/Abilities/Toolset/Base_Toolset_Ability.php includes/Abilities/Execute.php`
      returns 0 for both, or an accepted deviation names the exception
- [x] T063's thrown-ability test exercises both choke points through the wrapper
- [x] `McpTrackerTab extends AbstractReactMountServerTab`, or `plan.md` records why not
- [x] Both mount points render the same app; the per-server one hides the Server column
- [x] All PHPUnit suites pass; PHPStan level 8 clean (result cache cleared first)
- [x] No performance regression — neither change adds a query or a hook

**Verified 2026-10-23.** Each criterion checked against the code:

1. `CaptureContext::around()` is the **only** real `try`/`finally` bracketing an ability —
   `grep -cE '^\s*\}?\s*finally\s*\{'` returns 1 for `CaptureContext.php` and **0** for both
   choke points.
2. The plain `grep -c "finally"` in the criterion returns **1** for each choke point, but both
   matches are inside COMMENTS stating that `around()` owns the bracket. Zero real blocks, which
   is what the criterion means. (Third time in this feature a "appears nowhere" grep has matched
   a comment asserting the absence — see also `__return_true` and `dangerouslySetInnerHTML`.)
3. `AbilityRecorderTest::test_a_thrown_ability_still_disarms_the_context()` covers it, and
   because both choke points delegate to `around()` one test genuinely exercises both — which was
   the point of consolidating them.
4. `McpTrackerTab` extends `AbstractServerTab`, taking the criterion's second branch: `plan.md`
   carries "Accepted deviation — `McpTrackerTab` keeps `AbstractServerTab` (recorded 2026-10-21)"
   with the technical reason (`register_rest_routes()` is `final` and registers a CREATABLE route
   unconditionally; the two state methods are abstract; the activity log is read-only).
5. One component, two mounts: the per-server tab passes `serverId` and `showServer: false`
   (`McpTrackerTab.php:86-90`, `index.js:38`).
6. All 17 suites green — 1,476 tests / 3,860 assertions; PHPStan level 8 clean with the result
   cache cleared.
7. No regression: `CaptureContext` registers no hooks and touches no `$wpdb`, and no hook was
   added at either `around()` call site.
