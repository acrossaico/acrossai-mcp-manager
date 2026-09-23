<!-- SPECKIT START -->
For additional context about technologies to be used, project structure,
shell commands, and other important information, read the current plan
at `specs/091-schema-drift-reconciliation/plan.md`.
<!-- SPECKIT END -->

<!-- MEMORY-MD START -->
## Memory-Guided Development

Before planning or implementing any feature, you MUST:

1. Read `docs/memory/INDEX.md` — select the relevant Architecture Constraints, Decisions, Bug Patterns, and Security Constraints for this feature.
2. Read the selected source entries from `docs/memory/ARCHITECTURE.md`, `docs/memory/DECISIONS.md`, `docs/memory/BUGS.md`, and `docs/memory/PROJECT_CONTEXT.md`.
3. Read the feature's `specs/NNN-feature/memory.md` if it exists.
4. If `specs/NNN-feature/memory-synthesis.md` exists and is not `draft`, use it as the primary memory snapshot.

**Memory files (never skip)**:
- `docs/memory/INDEX.md` — routing map; read first
- `docs/memory/PROJECT_CONTEXT.md` — plugin-wide constraints and domain language
- `docs/memory/ARCHITECTURE.md` — boot flow, namespace map, module boundaries
- `docs/memory/DECISIONS.md` — active cross-feature decisions
- `docs/memory/BUGS.md` — known failure patterns to avoid
- `.specify/memory/constitution.md` — governing principles and Definition of Done

**Feature memory files (per-spec)**:
- `specs/NNN-feature/memory.md` — feature-local scope notes, open questions, watchlist
- `specs/NNN-feature/memory-synthesis.md` — synthesized snapshot (prefer over manual reads when status is not `draft`)

After implementation, run `/speckit.memory-md.capture-from-diff` to promote durable lessons to `docs/memory/`.
<!-- MEMORY-MD END -->


<!-- COPILOT ACCESS POLICY START -->
## File Access Policy

> AUTO-GENERATED — edit `ai-policy.yml` and run `npm run sync:ai-policy`

### Hard Ignore — never read these paths

- `.git/`
- `.github/skills/`
- `node_modules/`
- `build/`
- `vendor/wp-coding-standards/`
- `vendor/dealerdirect/`
- `vendor/phpunit/`
- `vendor/composer/`
- `vendor/bin/`
- `vendor/autoload_packages.php`
- `vendor/autoload_packages.php`
- `package-lock.json`
- `composer.lock`
- `languages/`
- `tests/_output/`
- `coverage/`
- `tmp/`
- `logs/`
- `**/*.log`
- `.DS_Store`
- `Thumbs.db`

### Requires Permission — ask before reading

Before reading any path below, ask:
> "May I read `<path>` to help with this task?"

- `vendor/automattic/`
<!-- COPILOT ACCESS POLICY END -->
