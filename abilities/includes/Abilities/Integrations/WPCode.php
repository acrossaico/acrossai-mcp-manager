<?php
/**
 * WPCode's toolset declaration.
 *
 * The "adopt what they already registered" shape. WPCode ships five read-only abilities of its own
 * under `wpcode/` (class-wpcode-abilities-api.php:541) and they land in no tab and no MCP tool,
 * because they carry no `tab_group` and nothing claimed their prefix. Claiming `wpcode` here files
 * them alongside ours without re-registering them, so they keep their own permission callbacks.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's WPCode abilities, plus WPCode's own five.
 */
final class WPCode implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_WPCode_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'wpcode';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'WPCode', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'WPCode snippets: create, edit, place, activate and delete code snippets of every type (php, js, css, html, text, universal), set where each one is inserted and the conditional logic that decides when it loads, manage the site-wide header, body and footer scripts, inspect snippet errors and safe mode, and install snippets from the WPCode library. Two things to know before writing. Snippets are a custom post type, but writing that post type directly through the Content tools produces a snippet that never runs: WPCode keeps a separate cache option that its loader actually reads, and only its own save routine rebuilds it. And activating a php or universal snippet makes that code execute on this site, so those calls require confirm: true, are test-run by WPCode first, and report honestly when WPCode refuses. Run get-snippet-status first when a snippet appears to have no effect. Only present when WPCode is active. Requires administrator rights; note that WPCode grants its own wpcode_edit_snippets capability only to roles that already administer the site. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Adopts WPCode's own namespace.
	 *
	 * Their five abilities are read-only and already registered; claiming the prefix gives them a
	 * tab and a tool without this plugin re-declaring them, so their permission callbacks stay
	 * theirs.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'wpcode' );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( 'WPCode_Snippet' ) && function_exists( 'wpcode' );
	}
}
