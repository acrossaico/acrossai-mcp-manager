# Hosting: firewall, bot filter and cache rules

What to allow, and what not to cache, so an AI client can connect.

If a client cannot connect, run **AcrossAI → Troubleshoot** first. It probes this
site the way a client does and names what is wrong, which is faster than working
through this page. Come here for the exact rules to give a host.

---

## The short version, for a support ticket

> Please exclude these paths from caching, bot filtering, WAF rules and redirects:
>
> ```
> /.well-known/oauth-authorization-server
> /.well-known/oauth-protected-resource
> /authorize
> /token
> /wp-json/acrossai-mcp-manager/v1/oauth/*
> /wp-json/mcp/*
> ```
>
> …and allow requests from Anthropic's outbound addresses:
>
> ```
> 160.79.104.0/21
> 2607:6bc0::/48
> ```
>
> Context: AI clients connect with non-browser user agents, so generic "block
> Python" or "block bots" rules reject them before WordPress runs. These are
> public metadata endpoints; they expose no private data.

The Troubleshoot screen generates this same block with your site's URL filled
in, ready to copy.

---

## Firewall and bot filtering

### The user-agent trap

**Claude.ai connects as `python-httpx`.** Not a browser string, not a custom
one — the user agent of the HTTP library it uses. So does a great deal of
legitimate automation.

Several popular security plugins ship a "block Python user agents" or "block
bots" rule **enabled by default**, and most attach a multi-hour IP ban to the
first match. The result is the hardest failure to diagnose in this whole
system: the MCP route answers correctly, both discovery documents return 200,
registration validates its input — and the client still cannot connect, because
it never reached WordPress at all. The only record is in the security plugin's
own block log.

The Troubleshoot screen's **Non-browser user agents** check probes this site
with four such agents and reports a 403 or 503 as a block.

Plugins known to carry rules of this shape: Wordfence, Solid Security
(formerly iThemes), Sucuri, All In One WP Security, Cerber Security, ShieldPRO,
MalCare, Advanced IP Blocker.

### IP ranges

Allow Anthropic's **outbound** range:

```
160.79.104.0/21
2607:6bc0::/48
```

> **The documentation page lists two ranges and they are not interchangeable.**
> The `/23` on <https://platform.claude.com/docs/en/api/ip-addresses> is the
> **inbound** range — addresses traffic goes *to*. Allowlisting it changes
> nothing for an inbound connection to your site, and is a common and
> frustrating mistake because the ticket looks correct.

### What no in-site check can tell you

Every check this plugin can run originates from your own server, carrying your
own server's IP address. **It therefore cannot see an IP-based block.** A
datacenter-IP rule, a country block or a CDN bot filter will reject a real AI
client while every check on the Troubleshoot screen passes.

If everything is green and a client still cannot connect, ask your host or CDN
for the block log covering the time of the attempt. That is the only place the
evidence exists.

---

## Caching

### What must never be cached

| Path | Why |
|---|---|
| `/authorize` | carries a single-use authorization code in its redirect |
| `/token` | returns bearer tokens |
| `/wp-json/acrossai-mcp-manager/v1/oauth/*` | registration and token exchange |
| `/wp-json/mcp/*` | per-request, per-user tool results |

These all send `Cache-Control: no-store` and define `DONOTCACHEPAGE`. A layer
that caches them anyway is misconfigured, and will break authentication in ways
that look random.

### What may be cached, briefly

| Path | |
|---|---|
| `/.well-known/oauth-authorization-server` | `public, max-age=60` |
| `/.well-known/oauth-protected-resource` | `public, max-age=60` |

Sixty seconds absorbs a client's burst of discovery requests during one
connection attempt, which is all the caching was ever for. It used to be an
hour, and an hour of stale metadata costs far more in support than it saves in
bandwidth: a document captured while the site was mid-upgrade, or before a
server was enabled, was served for an hour **after** the operator fixed the
real problem — so their re-test failed and nothing told them a cache was
involved.

Change the window with a filter:

```php
// Never cache discovery documents on this site.
add_filter( 'acrossai_mcp_discovery_cache_max_age', '__return_zero' );

// Or widen it.
add_filter( 'acrossai_mcp_discovery_cache_max_age', fn() => 300 );
```

Zero sends `no-store` instead of a max-age.

### Page caches do not read `Cache-Control`

This is the part that surprises people. **A WordPress page cache is not an HTTP
cache.** It stores the response before anything looks at the headers, so
`no-store` does not reach it. The signal that does is the `DONOTCACHEPAGE`
constant, which this plugin defines on every OAuth path, and which nearly all
caching plugins honour.

Host-level and CDN caches honour neither by default. Those need an explicit
exclusion rule — the path list at the top of this page.

### Automatic purging

When a server's **route**, **namespace** or **enabled state** changes, the
plugin asks the cache layers it can reach to drop the affected discovery URLs:
Nginx Helper, LiteSpeed, W3 Total Cache, WP Rocket and Cache Enabler, plus the
path-inserted document for each enabled server, which is the URL MCP hosts
actually request.

It purges **those URLs only**, never the whole site: toggling one server should
not evict a large site's page cache. To reach a layer not in that list, or to
flush everything:

```php
add_action(
	'acrossai_mcp_discovery_cache_purge',
	function ( array $urls ) {
		// $urls holds every absolute discovery URL that just went stale.
		my_cdn_purge( $urls );
	}
);
```

### Diagnosing a stale cache

The Troubleshoot screen's **Cached discovery documents** check builds each
document in-process, fetches the same URL over HTTP twice — once carrying
credentials, once anonymously — and compares all three.

`A = B` but `B ≠ C` means a page cache is serving a stale copy **to anonymous
visitors**, which is exactly what an AI client is. That asymmetry is why the bug
is so hard to find by hand: you check in your own signed-in browser, see the
corrected document, and conclude the cache is fine — while the client keeps
receiving the old one.

---

## Rewrite rules

`/.well-known/oauth-*`, `/authorize` and `/token` are rewrite-rule based, so:

- **Plain permalinks cannot serve them at all.** WordPress keeps no rewrite
  table, and no amount of flushing creates one. Choose any other structure at
  **Settings → Permalinks**.
- **The rules can go missing** while the plugin's version stands still —
  another plugin flushing from a context where this one's `init` hook has not
  run, `wp rewrite flush --skip-plugins`, a database copied from an install
  where this plugin was inactive, a restore. The plugin now detects this on
  each admin page load and re-flushes itself, which is why re-saving Permalinks
  used to "fix" it.

The Troubleshoot screen reports all three states separately.

---

## Related

- `docs/planings-tasks/101-connection-troubleshooter.md` — how the checks work
- Issue #180 — an external prober, the only way to prove an IP-based block
