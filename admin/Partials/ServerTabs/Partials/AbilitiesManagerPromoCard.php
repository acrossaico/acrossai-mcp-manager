<?php
/**
 * Feature 030 — inline promotional card for the sibling
 * `acrossai-abilities-manager` plugin, rendered as the third section of
 * the Access Control tab.
 *
 * State detection reuses `\AcrossAI_Main_Menu\AddonsInstaller::find_plugin_file()`
 * (already available via the `acrossai-co/main-menu` composer package) so
 * we don't reimplement the sibling-plugin discovery contract.
 *
 * Install/activate flow — links to the shared main-menu Add-ons page
 * (`admin.php?page=acrossai-addons`) where the operator clicks the
 * existing Install/Activate button. `main-menu` owns the AJAX handlers +
 * nonce + capability enforcement; F030 links into that flow rather than
 * reimplementing it (SEC-030-005: main-menu's baseline entry already
 * ships the wordpress.org `download_url` — HTTPS via `plugins_api()`).
 *
 * When the sibling plugin is active, the card shows an "Edit Abilities"
 * link to `admin.php?page=acrossai-abilities-manager` (the sibling's
 * admin screen).
 *
 * Below the card, a `<details>` block titled "Prefer to use code?"
 * documents the WP core filter this feature registers on
 * (`wp_register_ability_args` at priority `999999`) so developers who
 * prefer not to install another plugin can hook the same filter at a
 * higher priority to fine-tune or override F030's behaviour.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin/Partials/ServerTabs/Partials
 * @since      0.1.0
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials;

use AcrossAI_MCP_Manager\Includes\Abilities\PermissionOverrideProcessor;
use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide singleton. Public methods are render callbacks; wire in
 * `Includes\Main::define_admin_hooks()` if hook-based, or invoke directly
 * from `AccessControlTab::render_body()`.
 *
 * @since 0.1.0
 */
final class AbilitiesManagerPromoCard {

	/**
	 * Sibling plugin slug — canonical identifier used across the addon
	 * registry (see `AcrossAI_Main_Menu\AddonsPageRenderer::ADDONS`).
	 */
	private const SIBLING_SLUG = 'acrossai-abilities-manager';

	/**
	 * Sibling plugin admin page slug.
	 */
	private const SIBLING_ADMIN_PAGE = 'acrossai-abilities-manager';

	/**
	 * Main-menu Add-ons page slug — matches `\AcrossAI_Main_Menu\SettingsPage::ADDONS_SLUG`.
	 */
	private const ADDONS_PAGE_SLUG = 'acrossai-addons';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance().
	 */
	private function __construct() {}

	/**
	 * Back-compat wrapper — renders callout + details in sequence. Prefer
	 * calling `render_callout()` and `render_code_details()` separately so
	 * the two blocks can be positioned independently (callout nested inside
	 * the main form card, details as an outer sibling).
	 *
	 * @param array<string, mixed> $server Server row data.
	 * @return void
	 */
	public function render( array $server ): void {
		$this->render_callout( $server );
		$this->render_code_details();
	}

	/**
	 * Render the promo callout: info icon + heading + description + status
	 * row with state-varying CTA button. Designed to be embedded inside the
	 * main form card so it visually reads as a nested block, not a peer.
	 *
	 * @param array<string, mixed> $server Server row data (unused today; kept for future per-server contextualisation).
	 * @return void
	 */
	public function render_callout( array $server ): void {
		unset( $server );

		$state = $this->resolve_state();

		echo '<div class="acrossai-mcp-pom__callout">';
		echo '<div class="acrossai-mcp-pom__callout-head">';
		echo '<span class="acrossai-mcp-pom__callout-icon dashicons dashicons-info-outline" aria-hidden="true"></span>';
		echo '<h4>' . esc_html__( 'Prefer per-ability control? Use a UI instead.', 'acrossai-mcp-manager' ) . '</h4>';
		echo '</div>';
		echo '<p>';
		printf(
			/* translators: %s: <code>permission_callback</code>. */
			esc_html__( 'The AcrossAI Abilities Manager plugin lets you edit every registered ability’s %s, category, and access-control rules through the admin UI — no code required.', 'acrossai-mcp-manager' ),
			'<code>permission_callback</code>'
		);
		echo '</p>';

		$this->render_status_row( $state );

		echo '</div>';
	}

	/**
	 * Render the status row inside the callout — state-varying text on the
	 * left, state-varying CTA button on the right.
	 *
	 * @param string $state One of 'active', 'inactive', 'missing'.
	 * @return void
	 */
	private function render_status_row( string $state ): void {
		if ( 'active' === $state ) {
			$status_text = __( 'AcrossAI Abilities Manager is installed and active.', 'acrossai-mcp-manager' );
			$cta_url     = add_query_arg( array( 'page' => self::SIBLING_ADMIN_PAGE ), admin_url( 'admin.php' ) );
			$cta_label   = __( 'Edit Abilities →', 'acrossai-mcp-manager' );
		} elseif ( 'inactive' === $state ) {
			$status_text = __( 'AcrossAI Abilities Manager is installed but not active.', 'acrossai-mcp-manager' );
			$cta_url     = add_query_arg( array( 'page' => self::ADDONS_PAGE_SLUG ), admin_url( 'admin.php' ) );
			$cta_label   = __( 'Activate on Add-ons page →', 'acrossai-mcp-manager' );
		} else {
			$status_text = __( 'AcrossAI Abilities Manager is not installed on this site.', 'acrossai-mcp-manager' );
			$cta_url     = add_query_arg( array( 'page' => self::ADDONS_PAGE_SLUG ), admin_url( 'admin.php' ) );
			$cta_label   = __( 'Install & Activate on Add-ons page →', 'acrossai-mcp-manager' );
		}

		echo '<div class="acrossai-mcp-pom__status-row">';
		echo '<span class="acrossai-mcp-pom__status-row-text">' . esc_html( $status_text ) . '</span>';
		printf(
			'<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $cta_url ),
			esc_html( $cta_label )
		);
		echo '</div>';
	}

	/**
	 * Render the "Prefer to use code?" `<details>` block as a sibling of
	 * the main form card. Documents the WP core filter this feature
	 * registers on so a developer can hook the same filter at a higher
	 * priority to fine-tune or override F030's behaviour without
	 * installing the sibling plugin.
	 *
	 * @return void
	 */
	public function render_code_details(): void {
		echo '<details class="acrossai-mcp-pom__details">';
		echo '<summary>' . esc_html__( 'Prefer to use code? Hook the WordPress filter this plugin uses.', 'acrossai-mcp-manager' ) . '</summary>';

		echo '<p>';
		printf(
			/* translators: 1: WP core filter name, 2: F030 filter priority. */
			esc_html__( 'This plugin registers on the WordPress core filter %1$s at priority %2$d. Hook the same filter at a higher priority to override the override for a specific slug, server, or user — no additional plugin required.', 'acrossai-mcp-manager' ),
			'<code>wp_register_ability_args</code>',
			(int) PermissionOverrideProcessor::PRIORITY
		);
		echo '</p>';

		$snippet_priority = (int) PermissionOverrideProcessor::PRIORITY + 1;
		$snippet          = "<?php\n"
			. "// Override the F030 override for one specific ability slug.\n"
			. "add_filter( 'wp_register_ability_args', function ( array \$args, string \$slug ): array {\n"
			. "    if ( 'my-plugin/my-ability' !== \$slug ) {\n"
			. "        return \$args;\n"
			. "    }\n"
			. "    \$args['permission_callback'] = static function (): bool {\n"
			. "        return current_user_can( 'manage_options' );\n"
			. "    };\n"
			. "    return \$args;\n"
			. "}, {$snippet_priority}, 2 );";

		echo '<pre><code>' . esc_html( $snippet ) . '</code></pre>';

		echo '</details>';
	}

	/**
	 * Compute the sibling plugin's install/active state.
	 *
	 * Returns one of:
	 *   - 'active'   — plugin file present AND is_plugin_active() true
	 *   - 'inactive' — plugin file present but not active
	 *   - 'missing'  — plugin file not present in wp-content/plugins
	 *
	 * @return string
	 */
	public function resolve_state(): string {
		// ACTIVE is resolved by ServerTypes, never re-derived here (B32). That
		// class owns the one implementation the enablement gate and the runtime
		// composer both consult, so this notice cannot tell the operator the
		// add-on is missing while the gate considers the requirement satisfied.
		// The two used to disagree: this card matched by DIRECTORY while
		// ServerTypes assumed `slug/slug.php`, and they agreed only because the
		// sibling's filename happens to match its folder.
		if ( ServerTypes::plugin_is_active( self::SIBLING_SLUG ) ) {
			return 'active';
		}

		// Only the MISSING-vs-INACTIVE distinction is this card's own business,
		// because only the admin has to word the remedy differently ("install"
		// versus "activate"). Both are equally unmet to the gate.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return null === $this->find_sibling_plugin_file() ? 'missing' : 'inactive';
	}

	/**
	 * Resolve the sibling plugin's plugin-file identifier ("slug/slug.php")
	 * from wp-content/plugins. Prefers main-menu's `AddonsInstaller` helper
	 * when present; falls back to a slug-folder scan.
	 *
	 * @return string|null
	 */
	private function find_sibling_plugin_file(): ?string {
		if ( class_exists( '\\AcrossAI_Main_Menu\\AddonsInstaller' ) ) {
			$installer = new \AcrossAI_Main_Menu\AddonsInstaller();
			$addon     = array( 'slug' => self::SIBLING_SLUG );
			$found     = $installer->find_plugin_file( $addon );
			return null === $found ? null : (string) $found;
		}

		// Vendor absent — fallback direct lookup.
		foreach ( (array) get_plugins() as $file => $data ) {
			if ( 0 === strpos( $file, self::SIBLING_SLUG . '/' ) ) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Render the slim inline "add-on missing" notice.
	 *
	 * Extracted per Constitution §VI — this markup was ALREADY duplicated in
	 * `AbilitiesTab` and `ToolsTab`, each with its own hardcoded
	 * `is_plugin_active()` path literal, while this class already owned
	 * `SIBLING_SLUG` and the three-state `resolve_state()`. F090 would have made
	 * it a third copy.
	 *
	 * Lives here rather than in `includes/Utilities/` despite §VI naming that
	 * directory: the unit renders admin HTML and links to an admin page, and A3
	 * forbids admin-specific logic in `includes/`. A3 is the harder rule, and
	 * §VI's intent — one source of truth, no duplication — is satisfied either
	 * way. Documented as a deviation in `specs/090-server-types/plan.md`.
	 *
	 * Renders nothing when the sibling is active. "Not installed" and
	 * "installed but deactivated" deliberately share one message: the Add-ons
	 * page handles both transitions, so splitting the copy would add words
	 * without adding a decision.
	 *
	 * @since 0.1.0 (Feature 090)
	 * @param string $message Context sentence, already translated. Rendered
	 *                        after the bolded plugin name.
	 * @return void
	 */
	public function render_inline_notice( string $message ): void {
		if ( 'active' === $this->resolve_state() ) {
			return;
		}

		printf(
			'<div class="notice notice-info inline"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'AcrossAI Abilities Manager', 'acrossai-mcp-manager' ),
			esc_html( $message ),
			esc_url( add_query_arg( array( 'page' => self::ADDONS_PAGE_SLUG ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Get it from the Add-ons page →', 'acrossai-mcp-manager' )
		);
	}
}
