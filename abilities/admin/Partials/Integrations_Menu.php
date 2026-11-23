<?php
/**
 * The Integrations screen: a directory of every supported integration, and the switch for each.
 *
 * Feature 102 retired the Ability Integrations page and left a short list of checkboxes here — one
 * per plugin that gates its own abilities. Feature 117 then made every integration default to on,
 * and #263 grew the list from three rows to every integration on the site, at which point
 * checkboxes stopped being enough: two dozen rows with no description, no grouping and no search is
 * a list you cannot answer "is Rank Math supported?" from.
 *
 * So it is now a React screen (Feature 120), and a submenu of its own rather than a settings tab.
 * It was briefly the latter, which was wrong for what it became: a settings tab is where you go to
 * change a preference you already know you want, and this is a catalogue you go to in order to find
 * out what exists. It sits directly after Abilities, because the two answer consecutive questions —
 * "what can my site do?" and "what else could it do?"
 *
 * `register_setting()` and `sanitize_option()` are still here even though no form posts to them.
 * `sanitize_option()` is a `sanitize_option_{$option}` callback, so it runs for anything that writes
 * this option directly — it is the authorization on the stored value, not a form handler, and it
 * outlived the form it was written for.
 *
 * The descriptions, categories and free/Pro labels are not stored here. They come from the
 * catalogue on acrossai.co via `AcrossAI_Integration_Directory`, because they change when an
 * integration page is written rather than when this plugin is released.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/admin/Partials
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Admin\Partials;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Integrations submenu and authorises writes to the opt-in option.
 */
class Integrations_Menu {

	/**
	 * Menu slug of the Integrations page.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const MENU_SLUG = 'acrossai-integrations';

	/**
	 * Settings group the opt-in option is registered against.
	 *
	 * There is no form, so this group is never rendered. It exists because `register_setting()` is
	 * what installs the `sanitize_option_{$option}` filter, and that filter is the authorization.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const OPTION_GROUP = 'acrossai_integrations_group';

	/**
	 * DOM id the React root mounts on.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const ROOT_ID = 'acrossai-integrations-root';

	/**
	 * Register the Integrations submenu under the shared `acrossai` parent.
	 *
	 * No `$position` is passed. Several plugins register into this parent at overlapping slots and
	 * WordPress shuffles collisions to fractional keys, so the final order is settled by
	 * `Menu::reorder_submenu()` on a late `admin_menu` pass instead — the same reason Quick Connect
	 * does not ask for one.
	 *
	 * @since  0.0.42
	 * @return void
	 */
	public function register_submenu(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Integrations', 'acrossai-abilities-manager' ),
			__( 'Integrations', 'acrossai-abilities-manager' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the page: a title and the React mount.
	 *
	 * @since  0.0.42
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'acrossai-abilities-manager' ) );
		}

		printf(
			'<div class="wrap"><div id="%1$s" class="acrossai-integrations-root"></div>'
			. '<noscript><p><strong>%2$s</strong></p></noscript></div>',
			esc_attr( self::ROOT_ID ),
			esc_html__( 'The Integrations screen requires JavaScript to be enabled.', 'acrossai-abilities-manager' )
		);
	}

	/**
	 * Capability every opt-in change requires, whatever the filter returns.
	 *
	 * The admin page is gated at `manage_options`, but `sanitize_option()` is a public
	 * `sanitize_option_{$option}` callback: any code that writes the option directly runs it without
	 * that gate. So the floor is layered here too, ahead of the filtered check, per
	 * PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY — the filter may raise the bar, never lower it.
	 *
	 * @var string
	 */
	public const CAPABILITY_FLOOR = 'manage_options';

	/**
	 * Singleton instance.
	 *
	 * @var Integrations_Menu|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Retrieve the singleton.
	 *
	 * @since  0.0.34
	 * @return Integrations_Menu
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.0.34
	 */
	private function __construct() {}

	/**
	 * Register the opt-in option.
	 *
	 * No settings page, section or field goes with it. `register_setting()` is called purely for
	 * its side effect of hooking `sanitize_option_{$option}`, which is where the capability check
	 * lives — see `sanitize_option()`.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			AcrossAI_Integration_Settings::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_option' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Enqueue the React bundle on this page.
	 *
	 * Hooked to `admin_enqueue_scripts`.
	 *
	 * @since  0.0.42
	 * @param  string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_integrations_page( $hook_suffix ) ) {
			return;
		}

		$build_url  = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_URL . 'build/'
			: plugins_url( 'build/', dirname( __DIR__, 3 ) . '/acrossai-mcp-manager.php' );
		$build_path = defined( 'ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH' )
			? \ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH . 'build/'
			: dirname( __DIR__, 3 ) . '/build/';

		$asset_file = $build_path . 'js/abilities/integrations.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			// Bundle not built — the noscript message stands in.
			return;
		}

		$asset   = require $asset_file;
		$version = (string) ( $asset['version'] ?? '0.0.0' );

		wp_enqueue_script(
			'acrossai-integrations',
			$build_url . 'js/abilities/integrations.js',
			(array) ( $asset['dependencies'] ?? array() ),
			$version,
			true
		);

		wp_localize_script(
			'acrossai-integrations',
			'acrossaiIntegrations',
			array(
				'restNamespace' => 'acrossai/v1',
				'restBase'      => 'integrations',
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'proUrl'        => 'https://acrossai.co/pricing/',
				'requestUrl'    => 'https://acrossai.co/request-integration',
				'abilitiesUrl'  => admin_url( 'admin.php?page=' . Menu::ABILITIES_SLUG ),
			)
		);

		if ( file_exists( $build_path . 'css/abilities/integrations.css' ) ) {
			wp_enqueue_style(
				'acrossai-integrations',
				$build_url . 'css/abilities/integrations.css',
				array( 'wp-components' ),
				$version
			);
		}
	}

	/**
	 * Whether the current admin request is this page.
	 *
	 * Matched on the hook suffix containing the menu slug rather than on an exact string: the
	 * prefix WordPress builds depends on the parent menu's own slug and sanitisation, which belongs
	 * to acrossai-co/main-menu and is not ours to predict.
	 *
	 * @since  0.0.42
	 * @param  string $hook_suffix Current admin page hook.
	 * @return bool
	 */
	private function is_integrations_page( string $hook_suffix ): bool {
		return false !== strpos( $hook_suffix, self::MENU_SLUG );
	}


	/**
	 * Sanitize and authorise the submitted opt-ins.
	 *
	 * The per-integration capability check sits here so that the filtered capability can only raise
	 * the bar (PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY). A denied change leaves the stored value
	 * untouched and fires `acrossai_integration_toggle_denied` so sites can audit it, matching what
	 * `Integrations_Directory_Controller::set_enabled()` does for the screen's own writes.
	 *
	 * Written for a checkbox form that no longer exists, and kept because it is the guard on the
	 * option itself: anything calling `update_site_option()` on it still runs through here. The
	 * absent-means-off reading of the input is a consequence of that origin — a caller handing this
	 * a partial array is treated as having unticked the rest.
	 *
	 * @since  0.0.34
	 * @param  mixed $input Raw value from the settings POST.
	 * @return array<string, bool>
	 */
	public function sanitize_option( $input ): array {
		$submitted = is_array( $input ) ? $input : array();
		$stored    = get_site_option( AcrossAI_Integration_Settings::OPTION_KEY, array() );
		$stored    = is_array( $stored ) ? $stored : array();
		$clean     = array();

		// Unconditional floor, ahead of the per-integration filtered capability. Fail closed by
		// handing back exactly what is stored, so a denied request changes nothing.
		if ( ! current_user_can( self::CAPABILITY_FLOOR ) ) {
			return $stored;
		}

		foreach ( array_keys( AcrossAI_Integration_Settings::discover() ) as $slug ) {
			$slug    = (string) $slug;
			$desired = ! empty( $submitted[ $slug ] );
			$current = ! empty( $stored[ $slug ] );

			if ( $desired === $current ) {
				$clean[ $slug ] = $current;

				continue;
			}

			$required_cap = AcrossAI_Integration_Settings::required_capability( $slug );

			if ( '' === $required_cap || ! current_user_can( $required_cap ) ) {
				/**
				 * Fires when a user is denied permission to change an integration opt-in.
				 *
				 * @since 0.1.0
				 * @param string $integration_slug Integration slug.
				 * @param string $required_cap     Capability that would have been required.
				 * @param int    $user_id          Current user ID (0 for guests).
				 */
				do_action(
					'acrossai_integration_toggle_denied',
					$slug,
					$required_cap,
					get_current_user_id()
				);

				add_settings_error(
					AcrossAI_Integration_Settings::OPTION_KEY,
					'acrossai_integration_forbidden_' . sanitize_key( $slug ),
					sprintf(
						/* translators: %s is an integration name. */
						esc_html__( 'You are not allowed to change the %s integration.', 'acrossai-abilities-manager' ),
						esc_html( $slug )
					),
					'error'
				);

				$clean[ $slug ] = $current;

				continue;
			}

			$clean[ $slug ] = $desired;
		}

		// Preserve opt-ins for integrations whose plugin is inactive right now, so deactivating a
		// plugin does not silently discard the operator's choice for when it returns.
		foreach ( $stored as $slug => $value ) {
			if ( ! array_key_exists( (string) $slug, $clean ) ) {
				$clean[ (string) $slug ] = (bool) $value;
			}
		}

		return $clean;
	}
}
