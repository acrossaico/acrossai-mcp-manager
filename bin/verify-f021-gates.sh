#!/usr/bin/env bash
# Feature 021 governance grep gates.
#
# Runs the four architectural + security invariants that MUST hold on every
# CI run for the F021 code paths. Any violation fails the build.
#
# Exit codes:
#   0 — all gates pass
#   1 — at least one gate failed
#
# Invocation:
#   bash bin/verify-f021-gates.sh
#
# Extend by adding new `run_gate` sections below.
set -eu

PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PLUGIN_ROOT"

FAIL=0

run_gate() {
	local name="$1"
	local desc="$2"
	local hits="$3"

	if [ -n "$hits" ]; then
		printf '\033[1;31m✗ %s\033[0m — %s\n' "$name" "$desc"
		printf '%s\n' "$hits"
		FAIL=$((FAIL + 1))
	else
		printf '\033[1;32m✓ %s\033[0m\n' "$name"
	fi
}

# ---------------------------------------------------------------------------
# T118b — Principle A1: no add_action/add_filter/register_activation_hook in constructors.
# All wiring MUST live in Main.php via the Loader.
# ---------------------------------------------------------------------------
GATE_A1_HITS="$(
	grep -rEn 'function __construct[^{]*\{[^}]*(add_action|add_filter|add_shortcode|register_activation_hook)' \
		--include='*.php' \
		includes/OAuth \
		includes/Connectors \
		includes/Database/OAuthClients \
		includes/Database/OAuthTokens \
		includes/Database/OAuthAuthCodes \
		admin/Partials/ServerTabs/AIConnectorsTab.php \
	2>/dev/null || true
)"
run_gate 'T118b Principle A1 — no hook wiring in constructors' \
	'Move to Main::define_public_hooks / define_admin_hooks via the Loader.' \
	"$GATE_A1_HITS"

# ---------------------------------------------------------------------------
# T118c — Repository ↔ Query ↔ $wpdb layering.
# Controllers (includes/OAuth/*Controller.php + TokenValidator + UserLifecycle
# + Cleanup + OAuthRouter + PKCE) MUST NOT touch $wpdb directly.
# ---------------------------------------------------------------------------
GATE_LAYERING_HITS="$(
	grep -rEn '(\$wpdb|wpdb\(\)|global[[:space:]]+\$wpdb)' \
		includes/OAuth/DiscoveryController.php \
		includes/OAuth/AuthorizationController.php \
		includes/OAuth/TokenController.php \
		includes/OAuth/ClientRegistrationController.php \
		includes/OAuth/TokenValidator.php \
		includes/OAuth/UserLifecycle.php \
		includes/OAuth/Cleanup.php \
		includes/OAuth/OAuthRouter.php \
		includes/OAuth/PKCE.php \
	2>/dev/null || true
)"
run_gate 'T118c Controller/Repository/$wpdb layering' \
	'Controllers MUST NOT touch $wpdb directly. Route through Repositories → Queries.' \
	"$GATE_LAYERING_HITS"

# ---------------------------------------------------------------------------
# T118d — Presentation (Partials) layer must not touch $wpdb or BerlinDB Query
# classes directly. View code MUST route through Repositories, same layering
# rule as Controllers (T118c). Added 2026-07-12 after architecture review found
# AIConnectorsTab.php:273-326 bypassing the Repository line — T118c scanned
# Controllers only and missed it. Scope: F021/F024 admin partials.
# ---------------------------------------------------------------------------
PARTIAL_TARGETS=(
	admin/Partials/ServerTabs/AIConnectorsTab.php
)
GATE_PARTIAL_HITS="$(
	{
		for f in "${PARTIAL_TARGETS[@]}"; do
			[ -f "$f" ] || continue
			# Direct $wpdb access from the view layer.
			grep -En '(\$wpdb|wpdb\(\)|global[[:space:]]+\$wpdb)' "$f" 2>/dev/null || true
			# Direct BerlinDB Query access (skipping Repository).
			grep -En '\\?AcrossAI_MCP_Manager\\Includes\\Database\\OAuth[A-Za-z]+\\Query::instance' "$f" 2>/dev/null || true
		done
	}
)"
run_gate 'T118d Partial/Repository/$wpdb layering' \
	'Partials MUST NOT touch $wpdb or OAuth*\Query::instance directly. Route through Repositories.' \
	"$GATE_PARTIAL_HITS"

# ---------------------------------------------------------------------------
# F090 — `is_enabled` has exactly ONE sanctioned WRITER.
# ServerEnablement::set() enforces the server type's requirement on off -> on.
# A guard applied at each call site is a convention, not a boundary: F090's
# call-site list was built by grep and the security review then found a path it
# had missed. This gate makes the boundary self-maintaining — a future fourth
# writer fails CI instead of relying on review memory.
#
# Scoped to `update_item` deliberately. `'is_enabled' => N` ALSO appears
# legitimately in (a) query filters (`query( array( 'is_enabled' => 1 ) )`, a
# READ) and (b) creation arrays (`add_item`, which seeds 0 per A21's
# disabled-by-default rule). Matching the bare key flags all of those and the
# gate becomes noise people learn to ignore.
# ---------------------------------------------------------------------------
GATE_ENABLED_WRITER_HITS="$(
	grep -rEn -A2 "update_item\(" \
		--include='*.php' \
		includes/ admin/ public/ \
	2>/dev/null \
		| grep -E "'is_enabled'[[:space:]]*=>" \
		| grep -v 'ServerEnablement.php' || true
)"
run_gate 'F090 single is_enabled writer' \
	'Route every enable/disable through ServerEnablement::set() — it enforces the server type requirement on off -> on, and a direct update_item() bypasses it.' \
	"$GATE_ENABLED_WRITER_HITS"

# ---------------------------------------------------------------------------
# T119 — FR-040 column-width invariants.
# hash columns MUST be char(64); PKCE challenge MUST be char(43);
# token_family_id MUST be char(36). No narrowing.
# ---------------------------------------------------------------------------
GATE_WIDTH_HITS="$(
	grep -rEn "'length'[[:space:]]*=>[[:space:]]*'(16|20|24|32|40|48)'" \
		includes/Database/OAuthClients/Schema.php \
		includes/Database/OAuthTokens/Schema.php \
		includes/Database/OAuthAuthCodes/Schema.php \
	2>/dev/null | grep -E "token_hash|code_hash|code_challenge|client_secret_hash|metadata_fingerprint|token_family_id" || true
)"
run_gate 'T119 FR-040 cryptographic column widths' \
	'char(64) for SHA-256 hashes, char(43) for PKCE, char(36) for UUIDv4. DO NOT NARROW.' \
	"$GATE_WIDTH_HITS"

# ---------------------------------------------------------------------------
# T120 — S3 no-raw-at-rest.
# Every path that generates a raw secret MUST go through SecretsVault. Flags
# direct unqualified `random_bytes(` or bareword `random_token(` (NOT
# SecretsVault::random_token() method calls, which ARE the vault boundary).
# Also excludes docblock comment lines.
# ---------------------------------------------------------------------------
GATE_RAW_HITS="$(
	{
		grep -rEn 'random_bytes[[:space:]]*\(' includes/OAuth 2>/dev/null \
			| grep -vE 'SecretsVault\.php|\* ' \
			|| true
		# Bareword `random_token(` NOT preceded by `SecretsVault::` (or `::`).
		grep -rEn '(^|[^:])random_token[[:space:]]*\(' includes/OAuth 2>/dev/null \
			| grep -vE 'SecretsVault\.php|\* ' \
			|| true
	}
)"
run_gate 'T120 S3 raw-secret generation outside SecretsVault' \
	'Only SecretsVault should call random_bytes/random_token. Any other hit needs review.' \
	"$GATE_RAW_HITS"

echo ''
if [ "$FAIL" -eq 0 ]; then
	printf '\033[1;32mAll F021 governance gates passed.\033[0m\n'
	exit 0
else
	printf '\033[1;31m%d gate(s) failed.\033[0m\n' "$FAIL"
	exit 1
fi
