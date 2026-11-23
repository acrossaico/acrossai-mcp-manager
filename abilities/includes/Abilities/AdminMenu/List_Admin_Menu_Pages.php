<?php
/**
 * Feature 055 — enumerate every registered admin menu page + submenu.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\AdminMenu
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\AdminMenu;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * List every top-level admin menu entry plus its submenus, from the `$menu` / `$submenu` globals.
 *
 * Those globals only exist in an admin request. Outside one — which is every REST and therefore
 * every MCP call — they are null, and the menu has to be assembled before it can be read. How, and
 * why not by loading WordPress's own admin menu script, is the whole story of
 * {@see ensure_menu_built()}: that script crashed here on every call until 0.0.42, and repairing
 * the crash would have traded it for a `wp_die()` mid-request.
 *
 * Reassembly cannot recover WordPress's built-in entries, so a response says which kind of answer
 * it is: `complete: true` from a real admin request, `complete: false` plus a notice when rebuilt.
 * An incomplete list is useful; one that looks complete and is not would be worse than an error.
 */
class List_Admin_Menu_Pages extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'admin-menu/list-admin-menu-pages',
			'args' => array(
				'label'               => __( 'List Admin Menu Pages', 'acrossai-abilities-manager' ),
				'description'         => __( 'Enumerate every top-level admin menu entry plus its submenus, with each entry\'s slug, required capability and URL. Called from an admin request this is the real menu and `complete` is true. Called over REST or MCP, where WordPress has not built its menu, it is reassembled by firing admin_menu — which yields pages added by plugins and themes but NOT WordPress\'s own built-in entries such as Dashboard, Posts and Settings, because those are declared by a core file that cannot safely be loaded outside the admin. In that case `complete` is false and a notice says so: treat a missing core page as unknown rather than absent. Read-only.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-admin-menu',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,

					/*
					 * Load-bearing, and the second thing that made this ability unreachable over
					 * REST. Core applies only the TOP-LEVEL default and rejects null once a schema
					 * exists, so without this every zero-argument call fails with
					 * `input is not of type object`.
					 *
					 * There is no way for a caller to work around it either. This ability is
					 * `readonly`, so core's run endpoint requires GET
					 * (`WP_REST_Abilities_V1_Run_Controller`), and on GET it reads `input` from the
					 * query string — where every value is a string. `?input={}` arrives as the
					 * two-character string "{}", not an object, and bracket syntax cannot express an
					 * empty one. The default is the only route in.
					 *
					 * Same note as `Toolset\Guide::register()`, which hit this first.
					 */
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'  => array( 'type' => 'boolean' ),
						'items'    => array( 'type' => 'array' ),
						'message'  => array( 'type' => 'string' ),

						/*
						 * False when the menu was rebuilt outside an admin request, which means
						 * WordPress's own built-in pages are absent — see `ensure_menu_built()`.
						 * Declared because this schema is `additionalProperties: false`: a key the
						 * response carries but the schema does not name fails the whole call.
						 */
						'complete' => array( 'type' => 'boolean' ),
						'notice'   => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'configuration',
						'sub_group'       => 'admin-menu',
						'sub_group_label' => __( 'Admin Menu', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		unset( $input );
		global $menu, $submenu;

		$reconstructed = $this->ensure_menu_built();

		// Feature 055 hardening — hard-cap title length so a registrar can't
		// bloat responses; sanitize + limit every emitted field.
		$title_max      = 200;
		$sanitize_title = static function ( string $raw ) use ( $title_max ): string {
			$t = sanitize_text_field( wp_strip_all_tags( $raw ) );
			return strlen( $t ) > $title_max ? rtrim( substr( $t, 0, $title_max ) ) . '...' : $t;
		};

		$items = array();
		if ( is_array( $menu ) ) {
			foreach ( $menu as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$title      = $sanitize_title( (string) ( $entry[0] ?? '' ) );
				$capability = sanitize_key( (string) ( $entry[1] ?? '' ) );
				$slug       = sanitize_text_field( (string) ( $entry[2] ?? '' ) );
				if ( '' === $slug ) {
					continue;
				}
				// Only surface menu entries the current user could actually reach.
				if ( '' !== $capability && ! current_user_can( $capability ) ) {
					continue;
				}
				$sub = array();
				if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
					foreach ( $submenu[ $slug ] as $sub_entry ) {
						if ( ! is_array( $sub_entry ) ) {
							continue;
						}
						$sub_cap  = sanitize_key( (string) ( $sub_entry[1] ?? '' ) );
						$sub_slug = sanitize_text_field( (string) ( $sub_entry[2] ?? '' ) );
						if ( '' !== $sub_cap && ! current_user_can( $sub_cap ) ) {
							continue;
						}
						$sub[] = array(
							'title'      => $sanitize_title( (string) ( $sub_entry[0] ?? '' ) ),
							'capability' => $sub_cap,
							'slug'       => $sub_slug,
						);
					}
				}
				$items[] = array(
					'title'      => $title,
					'capability' => $capability,
					'slug'       => $slug,
					'url'        => esc_url_raw( (string) menu_page_url( $slug, false ) ),
					'submenu'    => $sub,
				);
			}
		}

		$result = array(
			'success'  => true,
			'items'    => $items,
			'complete' => ! $reconstructed,
			/* translators: %d: item count */
			'message'  => sprintf( __( '%d top-level admin menu entries.', 'acrossai-abilities-manager' ), count( $items ) ),
		);

		if ( $reconstructed ) {
			$result['notice'] = __( "Rebuilt outside an admin request, so this lists pages added by plugins and themes only — WordPress's own built-in entries (Dashboard, Posts, Media, Settings and the rest) are declared by wp-admin/menu.php, which is not loaded here. Treat a missing core page as unknown rather than absent.", 'acrossai-abilities-manager' );
		}

		return $result;
	}

	/**
	 * Make sure `$menu` / `$submenu` hold something, and say whether we had to build them.
	 *
	 * **Does not load WordPress's admin menu scripts, and must not start.** Until 0.0.42 this
	 * `require_once`-ed `wp-admin/includes/menu.php`, which is a procedural script titled "Build
	 * Administration Menu", not an include-safe helper. Outside an admin request that threw from
	 * inside core — `array_keys(): Argument #1 ($array) must be of type array, null given` at
	 * `menu.php:368`, because `$menu` was still null by the time core tried to trim its trailing
	 * separator. Every REST call failed, which is every MCP call. See issue #268.
	 *
	 * Loading it is unsafe for a worse reason than the crash, and that is why this does not simply
	 * fix the null. Thirty lines further on, `menu.php:376-385` calls `user_can_access_admin_page()`
	 * and `wp_die( 403 )` when it returns false — and that function reads `$pagenow` and
	 * `$plugin_page`, which no REST request sets. The old code failed early and loudly; repairing
	 * only the TypeError would have traded it for `wp_die()` killing the response mid-REST.
	 * `wp-admin/menu.php` is no better: it ends by requiring that same file.
	 *
	 * So the menu is assembled here instead. `$menu`, `$submenu` and the four companions
	 * `add_menu_page()` and `add_submenu_page()` write to are initialised to arrays, then
	 * `admin_menu` is fired once so plugins and themes register as they normally would. Firing it
	 * is reasonable: the callbacks are already attached in a REST request —
	 * `has_action( 'admin_menu' )` is true there — WordPress simply never fires them, and almost
	 * all of them do nothing but call `add_menu_page()`.
	 *
	 * **What this cannot recover is core's own entries.** Dashboard, Posts, Media and the rest are
	 * declared as direct `$menu[] = …` writes in `wp-admin/menu.php`, so they only exist if that
	 * file is loaded — which is the thing that is unsafe. The caller is told through `complete:
	 * false` and a notice rather than being handed a short list that looks whole.
	 *
	 * @since  0.0.42
	 * @return bool True when the menu was rebuilt here, so the result is plugin pages only.
	 */
	private function ensure_menu_built(): bool {
		// A real admin request has already built it, and built it properly. Nothing to do, and the
		// answer is complete.
		if ( did_action( 'admin_menu' ) ) {
			return false;
		}

		if ( ! function_exists( 'do_action' ) ) {
			return false;
		}

		// `add_menu_page()` needs $menu, $admin_page_hooks, $_registered_pages and $_parent_pages;
		// `add_submenu_page()` additionally needs $submenu and $_wp_real_parent_file. Any left null
		// makes core append to null, which is a fatal in PHP 8.
		foreach ( array( 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_real_parent_file' ) as $global ) {
			if ( ! isset( $GLOBALS[ $global ] ) || ! is_array( $GLOBALS[ $global ] ) ) {
				$GLOBALS[ $global ] = array();
			}
		}

		try {
			do_action( 'admin_menu', '' );
		} catch ( \Throwable $e ) {
			/*
			 * Somebody else's callback threw. Whatever registered before it still stands, so the
			 * partial list is returned rather than discarded — and `complete: false` already says
			 * the answer is incomplete, which is now true for one more reason.
			 */
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf( 'AcrossAI list-admin-menu-pages: an admin_menu callback threw: %s', $e->getMessage() ) );
			}
		}

		return true;
	}
}
