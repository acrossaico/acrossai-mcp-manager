# publish-release.mjs

Publishes a GitHub release entry to a custom post type on a WordPress site
whenever a release is published. Zero npm dependencies — runs on Node 20's
built-in `fetch`.

The workflow that drives it lives at `.github/workflows/publish-release.yml`.

## Required secrets

Set these in the repo's **Settings → Secrets and variables → Actions**:

| Secret            | Value                                                                 |
| ----------------- | --------------------------------------------------------------------- |
| `WP_URL`          | Base site URL, e.g. `https://example.com` (no trailing `/wp-json`).   |
| `WP_USER`         | WordPress username of an account that can create posts in the CPT.   |
| `WP_APP_PASSWORD` | Application password for that user (WP admin → Users → your profile). |

## Per-repo env vars (set in the workflow)

These live in the `env:` block at the top of `.github/workflows/publish-release.yml`.
Change them per repo — the script itself has no repo-specific values.

| Var                  | Purpose                                                                 |
| -------------------- | ----------------------------------------------------------------------- |
| `README_PATH`        | Path to the wp.org-format readme in this repo (this repo uses `README.txt`; wp.org convention is `readme.txt`). |
| `CPT_REST_BASE`      | REST base of the changelog CPT on the target site, e.g. `changelogs`.   |
| `TAXONOMY_REST_BASE` | REST base of the taxonomy that classifies posts per product.            |
| `TAXONOMY_TERM_SLUG` | Slug of the term for this specific plugin (must already exist on-site). |
| `PRODUCT_NAME`       | Display name used in the post title, e.g. `AcrossAI MCP Manager`.       |
| `PRODUCT_TERM`       | Slug prefix used to build the post slug, e.g. `acrossai-mcp-manager`.   |
| `POST_STATUS`        | `publish`, `draft`, `pending`, `future`, or `private`.                  |
| `IGNORE_GLOBS`       | Comma-separated globs excluded from the file list.                      |
| `FILE_LIST_CAP`      | Max files listed; the rest collapse into a "… and N more" line.         |
| `TAXONOMY_FIELD`     | Optional. Post JSON field for the term IDs. Defaults to `TAXONOMY_REST_BASE`. |

The taxonomy term is **looked up, never created** — create it once in the
site admin before the first run.

## Post shape

- **Title**: `{PRODUCT_NAME} {version}` (e.g. `AcrossAI MCP Manager 0.3.0`).
- **Slug**: `{PRODUCT_TERM}-{version-with-dots-as-dashes}` (idempotent
  lookup key — updates in place if the slug exists in any status).
- **Content**: Gutenberg block markup — a heading + list for the changelog
  bullets, then a heading + list for the changed files grouped by status.
- **Date**: the release's `published_at` timestamp.
- **Taxonomy**: the resolved term ID under the taxonomy REST base.

## How to do a dry run

Manually via **Actions → Publish release to WordPress site → Run workflow**:

1. Enter the existing tag (e.g. `v0.3.0`).
2. Tick **Print the payload and exit without calling the site**.
3. Click **Run workflow** — the job prints the exact JSON payload it would
   POST and exits without touching the site.

Locally (requires `git` + Node 20):

```bash
DRY_RUN=true \
README_PATH=README.txt \
RELEASE_TAG=v0.3.0 \
RELEASE_PUBLISHED_AT="2026-08-21T01:15:50Z" \
CPT_REST_BASE=changelogs \
TAXONOMY_REST_BASE=products \
TAXONOMY_TERM_SLUG=acrossai-mcp-manager \
PRODUCT_NAME="AcrossAI MCP Manager" \
PRODUCT_TERM=acrossai-mcp-manager \
WP_URL=https://example.test WP_USER=admin WP_APP_PASSWORD=xxxx \
node .github/scripts/publish-release.mjs
```

`PARSE_ONLY=true` is a smaller sibling — it prints just the parsed
changelog + diff summary and skips composing the body. The workflow runs
it as a preflight check on every real run so a bad readme fails the job
before any HTTP call.

## Failure modes

- Readme's topmost `= X.Y.Z =` doesn't match the release tag → job fails
  with a clear "update the readme before tagging" message.
- No previous git tag → the Files-changed section says "First release" and
  skips the file list rather than dumping the entire tree.
- Any non-2xx REST response → job fails with the status code + response
  body printed to the log.
