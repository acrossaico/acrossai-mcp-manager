# Raw-Return URL Builder Enforcement — Migration Plan

Scope note: this plan covers the **one** architecture finding from F084 that has genuine migration
character. The other two refactor tasks (RT-1, RT-2) are single-edit documentation actions with no
old-pattern/new-pattern coexistence problem, so they are tracked as tasks only — inventing phases for
them would be ceremony, not planning.

## Current State

Two raw-return URL builders will coexist in `admin/Partials/ServerTabs/`, with **different levels of
enforcement**:

```
AbstractServerTab::server_edit_url()          ConnectTab::method_url()          [NEW in F084]
  visibility: protected                         visibility: public static
  consumers:  3, all in this repo                consumers:  7 — 5 in this repo,
              ClientsTab:75                                  2 in acrossai-pro
              NpmTab:73
              AccessControlTab:93
  contract:   raw, stated in docblock            contract:   raw, stated in docblock
  inventory:  NONE                               inventory:  contract §2, 7 rows
  grep gate:  NONE                               grep gate:  quickstart.md T054
```

### Problems

- Two builders with **identical** escaping semantics but **asymmetric** enforcement invites the
  reasonable-sounding inference that `server_edit_url()`'s contract is weaker or optional.
- `server_edit_url()` is the older and more widely-inherited of the two — every third-party tab
  extending `AbstractServerTab` can call it — yet it is the one with no inventory and no gate.
- B54, captured from this feature, names this gap explicitly: *"Existing unenforced instance to
  backfill: `AbstractServerTab::server_edit_url()` (`:499`, three call sites)."*
- After F084, T025 removes two of `server_edit_url()`'s three call sites. A reviewer glancing at the
  remaining single call site may conclude the builder is nearly dead and skip enforcing it — exactly
  when third-party consumers still depend on it.

## Target State

```
Both raw-return builders carry the same three enforcement artifacts:
  1. a docblock stating the raw contract AND naming a chaining consumer as the reason
  2. an enumerated output-site inventory in a contract document
  3. a canary grep that lists consumers for review at merge time
```

### Benefits

- The escaping obligation is enforced identically wherever it applies, so no reader can infer a
  hierarchy of strictness between two builders that have none.
- New consumers of either builder hit the same review gate.
- B54's named gap closes, so the durable lesson matches the tree it describes.

## Migration Phases

### Phase 1: Establish the pattern on the new builder (Estimated: within F084, no extra work)

**Goal**: `method_url()` ships with all three artifacts.

- **Task 1.1**: Docblock naming `MCPClientsBlock.php:146` as the reason for the raw contract — F084 T015.
- **Task 1.2**: Seven-row output-site inventory in `contracts/connect-method-registration.md` §2 — already written.
- **Task 1.3**: Canary grep listing every `method_url(` consumer for review — F084 T054.

**Coexistence**: `server_edit_url()` continues unchanged and unenforced. No behaviour differs between
the two builders; only the review surface does.

### Phase 2: Backfill the older builder (Estimated: ~1 hour, non-blocking, after F084 merges)

**Goal**: `server_edit_url()` carries the same three artifacts.

- **Task 2.1**: Extend its docblock at `admin/Partials/ServerTabs/AbstractServerTab.php:499` to state
  the raw contract and name a chaining consumer as the reason, matching `method_url()`'s wording.
- **Task 2.2**: Add a `server_edit_url()` output-site inventory to `docs/extending-per-server-tabs.md`
  — after F084 that is `AccessControlTab.php:93` plus any third-party consumer, which the doc should
  say is the extender's own responsibility to escape.
- **Task 2.3**: Widen the F084 canary grep to cover both builders in one pattern, so a single gate
  serves both.

**Coexistence**: this is additive documentation and tooling only. No signature, return value, or call
site changes, so there is no window in which some consumers follow one rule and some another.

### Phase 3: Consider consolidation (Estimated: not scheduled — evaluate later)

**Goal**: decide whether two raw builders are warranted at all.

- **Task 3.1**: Evaluate whether `method_url()` can be expressed as `server_edit_url( $server,
  'connect' )` plus a `method` argument, collapsing to one builder.

**Do not attempt this during F084.** The two builders serve different levels and have different
visibility (`protected` vs `public static` for cross-plugin use). Consolidating would make an
`AbstractServerTab` method part of the companion's public API surface, which is a larger question than
this feature should settle. Revisit only if a third raw builder is ever proposed — that would be the
signal that the duplication is real rather than incidental.

## Coexistence Strategy

**Why coexistence?** There is no defect to race against. Both builders behave correctly today; only
their *enforcement documentation* differs. A big-bang consolidation would change a cross-plugin API
surface to fix a documentation asymmetry — wildly disproportionate.

**How**:

- New consumers of either builder escape at their output site, as both already require.
- F084 establishes the enforcement pattern on the new builder without touching the old one.
- Phase 2 backfills the old builder additively, at any time, with no coordination.
- Phase 3 is a decision, not a migration, and is explicitly unscheduled.

## Rollback Plan

Every phase is additive documentation plus a grep pattern. Rollback is `git revert` of a
docs-and-comments commit, with zero runtime impact and no data or API implications.

## Success Criteria

- [ ] `method_url()` has a docblock naming its chaining consumer, an inventory, and a grep gate (F084).
- [ ] `server_edit_url()` has the same three (Phase 2).
- [ ] One canary grep pattern covers both builders.
- [ ] `docs/extending-per-server-tabs.md` tells third-party extenders that they own escaping at their
      own output sites for both builders.
- [ ] B54's "existing unenforced instance to backfill" note is updated to reflect the closure.
