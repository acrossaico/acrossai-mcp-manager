#!/usr/bin/env bash
#
# Verify that this plugin carrying the core Toolsets changes NOTHING on a site
# that also runs AcrossAI Abilities Manager.
#
# The claim under test is not "it works" but "it is invisible": server types,
# the tool-level ability list and the registered toolset/* set must be byte
# identical whether or not this plugin's copies are loaded. Anything else means
# a second copy won a race it was supposed to lose.
#
# Usage:  bin/verify-toolset-noop.sh /path/to/wordpress
#
# On Local, run this from the site's own shell ("Open site shell" in the Local
# app), which points WP-CLI at the right database. Outside that shell, export
# EXTRA_REQUIRE=/path/to/a-php-file that define()s DB_HOST before WordPress
# does; it is passed to WP-CLI's --require.
#
set -euo pipefail

WP_ROOT="${1:-}"
if [[ -z "$WP_ROOT" || ! -f "$WP_ROOT/wp-load.php" ]]; then
	echo "Usage: $0 /path/to/wordpress   (the folder holding wp-load.php)" >&2
	exit 2
fi

command -v wp >/dev/null || { echo "WP-CLI (wp) is not on PATH." >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

# Snapshot the three things a second copy could disturb.
cat > "$TMP/snap.php" <<'PHP'
<?php
WP_Abilities_Registry::get_instance();

$types = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes::all();
foreach ( $types as $k => $v ) {
	if ( isset( $v['tools'] ) && is_array( $v['tools'] ) ) {
		sort( $types[ $k ]['tools'] );
	}
}

// What the admin OFFERS, per type — not the raw tool-ability list.
//
// That list was the original third signal and is the wrong thing to compare.
// It names which slugs are tool-level, so once this plugin owns the Toolset
// vocabulary it legitimately grows by fifteen entries on a site without the
// add-on. None of them is registered there, so nothing is hidden that would
// otherwise show and nothing is offered that could be added — the list
// changes, the behaviour does not.
//
// The picker pool is the behavioural question: what can an operator actually
// put on a server of this type? It is type-scoped and narrows to registered
// abilities, so it stays fixed whether or not these classes are loaded.
$tool_abilities = array();
foreach ( array_keys( \AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes::all() ) as $type_slug ) {
	$tool_abilities[ $type_slug ] = \AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes::pool( $type_slug );
	sort( $tool_abilities[ $type_slug ] );
}
ksort( $tool_abilities );

$toolsets = array_values(
	array_filter(
		array_keys( wp_get_abilities() ),
		static function ( $slug ) {
			return 0 === strpos( $slug, 'toolset/' );
		}
	)
);
sort( $toolsets );

echo wp_json_encode(
	array(
		'types'          => $types,
		'picker_pool'    => $tool_abilities,
		'toolsets'       => $toolsets,
	),
	JSON_PRETTY_PRINT
) . "\n";
PHP

# Unhook this plugin's Toolsets, to stand in for "before this change".
cat > "$TMP/off.php" <<'PHP'
<?php
WP_CLI::add_wp_hook(
	'plugins_loaded',
	function () {
		remove_action(
			'plugins_loaded',
			array( 'AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Registrar', 'register' ),
			21
		);
		remove_action(
			'wp_abilities_api_categories_init',
			array( \AcrossAI_MCP_Manager\Includes\Abilities\Toolset\Category_Registrar::instance(), 'register' )
		);
	},
	20
);
PHP

cd "$WP_ROOT"

EXTRA=()
[[ -n "${EXTRA_REQUIRE:-}" ]] && EXTRA=( --require="$EXTRA_REQUIRE" )

# Fail loudly here rather than letting every later step return nothing.
if ! wp --path="$WP_ROOT" "${EXTRA[@]}" option get siteurl --skip-themes --skip-plugins >/dev/null 2>&1; then
	echo "FAIL: WP-CLI cannot reach this site's database." >&2
	echo "      On Local, use the site's own shell, or set EXTRA_REQUIRE (see header)." >&2
	exit 2
fi

run() { wp --path="$WP_ROOT" --skip-themes "${EXTRA[@]}" "$@" 2>/dev/null | sed -n '/^{/,$p'; }

echo "Snapshotting with this plugin's Toolsets ACTIVE ..."
run eval-file "$TMP/snap.php" > "$TMP/on.json"

echo "Snapshotting with them REMOVED ..."
run --require="$TMP/off.php" eval-file "$TMP/snap.php" > "$TMP/off.json"

for f in on off; do
	[[ -s "$TMP/$f.json" ]] || { echo "FAIL: the '$f' snapshot is empty — WP-CLI could not boot the site." >&2; exit 1; }
done

echo
if diff -u "$TMP/off.json" "$TMP/on.json"; then
	echo "PASS — identical. Carrying the Toolsets changes nothing on this site."
else
	echo
	echo "FAIL — the snapshots differ. Lines marked + appear only when this" >&2
	echo "plugin's copies are loaded, which is exactly what must not happen." >&2
	exit 1
fi
