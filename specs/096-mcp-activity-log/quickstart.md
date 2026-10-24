# Quickstart — MCP Activity Log

## Turn it on

Both toggles ship **off**. `Settings → MCP Manager`:

1. **Request logging** — one row per MCP call. Read in `MCP\Controller`, so while off the
   adapter keeps receiving `NullMcpObservabilityHandler` and the recording path is never
   reached.
2. **Ability logging** — one row per ability run, including non-MCP callers. Far noisier; it
   exists as a separate switch so you can run one without the other.
3. **Retention** — days, per kind, default 30 each. **Retained-event ceiling** — oldest
   trimmed first. The daily cron exists only while at least one limit is finite.

## See it work

```bash
# 1. Make real traffic: connect a client and invoke one toolset tool.
#    Then confirm the parent/child pair exists.
wp db query "SELECT id, parent_id, event_type, method, component_name, status, duration_ms, output_bytes
             FROM $(wp db prefix --allow-root 2>/dev/null)acrossai_mcp_activity_log
             ORDER BY id DESC LIMIT 10"

# 2. The catalogue announcement — the row with no children and usually the biggest output_bytes.
wp db query "SELECT method, output_bytes FROM …acrossai_mcp_activity_log
             WHERE method = 'tools/list' ORDER BY id DESC LIMIT 5"

# 3. Which tool is eating the client's context?
wp db query "SELECT component_name, SUM(output_bytes) AS bytes, COUNT(*) AS calls
             FROM …acrossai_mcp_activity_log
             WHERE created_at > UTC_TIMESTAMP() - INTERVAL 1 DAY
             GROUP BY component_name ORDER BY bytes DESC LIMIT 10"
```

Via REST (needs `manage_options` and a REST nonce):

```bash
curl -s "https://example.test/wp-json/acrossai-mcp-manager/v1/activity?per_page=5" \
     -H "X-WP-Nonce: $NONCE" --cookie "$COOKIES"
curl -s ".../v1/activity/42/children" -H "X-WP-Nonce: $NONCE" --cookie "$COOKIES"
```

## Read the screen

**AcrossAI → Activity** (and the per-server **Logs** tab, pre-filtered with the server column
hidden). Requests list newest first; expand one to see the abilities beneath it. The
**Abilities** chip flips the view to ability events — including those no AI client triggered.

Four tiles: Requests, Avg Time, Success Rate, **Active Clients**. Not tokens — see below.

## Two things that will look wrong but are not

**Token figures are approximate, always.** This log cannot report LLM token usage: the model
runs on the client's side and neither MCP nor the adapter carries a usage block. What it
reports is **context cost** — the bytes we handed back, with an approximate token count
derived at read time. Tune it:

```php
add_filter( 'acrossai_mcp_bytes_per_token', fn() => 3.5 );            // JSON is denser than prose
add_filter( 'acrossai_mcp_estimated_tokens', function ( $est, $bytes, $row ) {
    return my_real_tokenizer_count( $row );   // or null to render a dash instead of a guess
}, 10, 3 );
```

**`output_preview` is empty on request rows.** Not a bug. The adapter's seam never passes the
response payload; the child ability row carries the actual result. The request row still
records the response *size*, which is what the five result filters are hooked for.

## Troubleshooting

| Symptom | Cause |
|---|---|
| Screen empty, logging on | Nothing has happened in the period. The empty state says which — it distinguishes "off" from "quiet" |
| Abilities appear with no parent | Correct for REST, WP-CLI and admin callers. Check `invoked_via` |
| Failures missing for non-MCP callers | WP 6.9/7.0 only: core reports success-only there. MCP-originated calls are still fully covered by the bracket. The screen states this |
| A `toolset/database` call shows no detail of what changed | By design — this feature logs *what was called*, not *what changed*. That is F097 |
| Times look hours off | Storage is UTC; display converts to site time. Check `Settings → General → Timezone` |

## For anyone extending it

- Write only through `ActivityLog\Recorder` (A15). Never call `Query::add_item()` directly.
- Need to attribute your own records to the call that caused them? Read
  `Observability\CaptureContext` — do **not** add a second `try`/`finally`. That is the
  contract F097 is built on (FR-012, SC-018).
- Any new request-scoped holder carries the three-stage lifecycle, `shutdown` included (A17).
