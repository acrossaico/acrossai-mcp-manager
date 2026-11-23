<?php
/**
 * REST routes behind the Integrations screen.
 *
 *   GET  /integrations            — the joined directory
 *   POST /integrations/{slug}     — turn one integration on or off
 *
 * The GET is the join, and the direction of that join is the point. Local state is authoritative
 * and the catalogue only decorates it: a row exists because `AcrossAI_Integration_Settings` knows
 * about it or because the catalogue lists it as something this site *could* have, and the two cases
 * are visibly different in the response. The catalogue can therefore never invent an integration
 * that is secretly active, nor report one as enabled when the stored option says otherwise —
 * `enabled` is read from the option every time, never from the feed.
 *
 * This is also the only place the remote fetch is reachable from, which is what keeps the promise in
 * `AcrossAI_Integration_Directory`: the request happens on a `manage_options` REST call made by the
 * admin screen, and nowhere else in the plugin's lifecycle.
 *
 * The POST writes through the same option the settings form used, so nothing about the meaning of
 * "enabled" changes — see `AcrossAI_Integration_Access` for what being off actually does. It does
 * not install, activate, or configure anything: the screen's blocked rows link out to core's own
 * plugin screens instead, both because that is the stated scope and because a plugin distributed on
 * WordPress.org must not install software from anywhere else (detailed guideline 8).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Rest
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Rest;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest\AcrossAI_Abilities_Rest_Controller;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Directory;
use AcrossAI_Abilities_Manager\Includes\Utilities\AcrossAI_Ability_Group;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the Integrations directory and its toggles.
 */
final class Integrations_Directory_Controller {

	/**
	 * Route base under the plugin's REST namespace.
	 *
	 * @var string
	 */
	public const REST_BASE = 'integrations';

	/**
	 * Plugin file of the paid add-on, used only to decide whether Pro rows are unlockable.
	 *
	 * @var string
	 */
	public const PRO_PLUGIN = 'acrossai-pro/acrossai-pro.php';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @since  0.0.42
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.0.42
	 */
	private function __construct() {}

	/**
	 * Register the routes.
	 *
	 * @since  0.0.42
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' );

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_directory' ),
					'permission_callback' => $permission,
					'args'                => array(
						'refresh' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => 'Bypass the cached catalogue and re-fetch it.',
						),
					),
				),
			)
		);

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<slug>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_enabled' ),
					'permission_callback' => $permission,
					'args'                => array(
						'slug'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'enabled' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * The joined directory.
	 *
	 * @since  0.0.42
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_directory( \WP_REST_Request $request ): \WP_REST_Response {
		$catalogue = AcrossAI_Integration_Directory::get( (bool) $request->get_param( 'refresh' ) );
		$local     = AcrossAI_Integration_Settings::discover();
		$pro       = $this->pro_active();

		$rows = array();

		// Local first, so anything this site actually has is present regardless of what the
		// catalogue says — including an integration added in a release newer than the feed.
		foreach ( $local as $slug => $meta ) {
			$slug          = (string) $slug;
			$entry         = $catalogue['entries'][ $slug ] ?? array();
			$rows[ $slug ] = $this->row( $slug, $entry, $meta, true, $pro );
		}

		// Then catalogue rows with no local counterpart: supported, but not available here yet.
		foreach ( $catalogue['entries'] as $slug => $entry ) {
			if ( isset( $rows[ $slug ] ) ) {
				continue;
			}

			$rows[ $slug ] = $this->row( (string) $slug, $entry, null, false, $pro );
		}

		$rows = array_values( $rows );

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$by_category = strcasecmp( (string) $a['cat'], (string) $b['cat'] );

				return 0 !== $by_category ? $by_category : strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$pro_rows = array_filter(
			$rows,
			static fn( array $row ): bool => 'pro' === $row['product']
		);

		return new \WP_REST_Response(
			array(
				'integrations'  => $rows,
				/*
				 * True when the catalogue came from the bundled copy. The screen says so quietly
				 * rather than treating it as an error — an air-gapped site is a supported way to
				 * run this, not a broken one.
				 */
				'stale'         => (bool) $catalogue['stale'],
				'pro_active'    => $pro,
				'pro_count'     => count( $pro_rows ),
				'pro_abilities' => array_sum( array_column( $pro_rows, 'n' ) ),
			),
			200
		);
	}

	/**
	 * Build one row.
	 *
	 * @since  0.0.42
	 * @param  string                            $slug      Integration slug.
	 * @param  array<string, string>             $entry     Catalogue entry, possibly empty.
	 * @param  array{label: string, count: int}|null $meta  Local discovery data, or null when absent.
	 * @param  bool                              $available Whether the host plugin is active here.
	 * @param  bool                              $pro       Whether AcrossAI Pro is active.
	 * @return array<string, mixed>
	 */
	private function row( string $slug, array $entry, ?array $meta, bool $available, bool $pro ): array {
		// The local label wins when we have one: it is what the rest of the admin calls this
		// integration, and a mismatch between screens is worse than a less polished name.
		$name = '';

		if ( null !== $meta && '' !== (string) $meta['label'] ) {
			$name = (string) $meta['label'];
		} elseif ( isset( $entry['name'] ) ) {
			$name = (string) $entry['name'];
		}

		if ( '' === $name ) {
			$name = ucwords( str_replace( '-', ' ', $slug ) );
		}

		$product = ( $entry['product'] ?? 'free' ) === 'pro' ? 'pro' : 'free';

		$enabled = $available && AcrossAI_Integration_Settings::is_enabled( $slug );

		/*
		 * Local count when the plugin is here AND switched on; the catalogue's hint otherwise.
		 *
		 * Switching an integration off now unregisters its abilities, so the registry honestly
		 * reports zero for it — and "Elementor · 0 abilities" is the one number this row must not
		 * show, because the whole reason to look at a switched-off row is to find out what turning
		 * it back on would give you.
		 */
		$count = $enabled ? (int) $meta['count'] : (int) ( $entry['abilities'] ?? 0 );

		if ( 0 === $count && null !== $meta ) {
			$count = (int) $meta['count'];
		}

		return array(
			'slug'      => $slug,
			'name'      => $name,
			'desc'      => (string) ( $entry['description'] ?? '' ),
			'cat'       => (string) ( $entry['category'] ?? __( 'Other', 'acrossai-abilities-manager' ) ),
			'n'         => $count,
			'product'   => $product,
			'url'       => (string) ( $entry['url'] ?? '' ),
			'available' => $available,
			'enabled'   => $enabled,
			/*
			 * Abilities still live in this group while the switch is off — the host plugin's own.
			 *
			 * Not an Elementor special case. Several integrations sit on a plugin that publishes
			 * abilities under the same namespace and would do so whether this plugin existed or
			 * not: WooCommerce reserves `woocommerce/`, Akismet ships its own, WPForms and Yoast
			 * publish theirs behind their own switches. Our switch withdraws *our* contribution; it
			 * does not reach into theirs. Reported so the row can say "27 still available from
			 * Elementor" instead of implying the group is empty.
			 */
			'host_n'    => $enabled ? 0 : $this->live_count( $slug ),
			/*
			 * A setting in the HOST plugin that the site owner still has to turn on themselves,
			 * and where to do it. Null for almost every row.
			 *
			 * Separate from `enabled`, which is our switch, and from `available`, which is whether
			 * the host plugin is installed. This is the third state: the plugin is here, our row is
			 * on, our abilities are working — and something the host gates separately is still off.
			 *
			 * Elementor is the case it exists for. Its MCP server is site-wide exposure it ships
			 * off, and this plugin deliberately does not switch it on
			 * ({@see \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\Elementor_Opt_In}).
			 * Without this the owner enables our row, gets the abilities, and has nothing on this
			 * screen explaining why a client still cannot reach them over Elementor's own endpoint.
			 *
			 * Published through a filter rather than asked of the integration directly: the rows
			 * here are built from `AcrossAI_Toolset_Integrations` descriptors, which are a
			 * different registry from the opt-in classes that know about host settings. The filter
			 * is the seam between them, and keeps this generic — a second host needs no change
			 * here or in the UI.
			 */
			'host_todo' => $enabled ? $this->host_todo( $slug ) : null,
			'block'     => $this->block( $slug, $name, $entry, $available, $product, $pro ),
		);
	}

	/**
	 * A host-side setting this integration still needs, or null.
	 *
	 * @since  0.0.42
	 * @param  string $slug Integration slug.
	 * @return array{label: string, url: string}|null
	 */
	private function host_todo( string $slug ): ?array {
		/**
		 * Filters the outstanding host-side setting for one integration.
		 *
		 * Return `array( 'label' => <what the owner still has to do>, 'url' => <where> )` when the
		 * host plugin gates something behind its own setting and that setting is off. Return null
		 * — the default — when there is nothing for the owner to do.
		 *
		 * Answered per request, because the whole point is that it changes the moment the owner
		 * acts on it.
		 *
		 * @since 0.0.42
		 * @param array|null $todo Outstanding setting, or null.
		 * @param string     $slug The integration slug being described.
		 */
		$todo = apply_filters( 'acrossai_integration_host_todo', null, $slug );

		if ( ! is_array( $todo ) || empty( $todo['label'] ) || empty( $todo['url'] ) ) {
			return null;
		}

		return array(
			'label' => sanitize_text_field( (string) $todo['label'] ),
			'url'   => esc_url_raw( (string) $todo['url'] ),
		);
	}

	/**
	 * How many abilities this group currently holds in the registry.
	 *
	 * Asked only for a switched-off integration, where the gate has already removed everything we
	 * register — so whatever is left arrived without us.
	 *
	 * @since  0.0.42
	 * @param  string $group Integration group slug.
	 * @return int
	 */
	private function live_count( string $group ): int {
		$counts = AcrossAI_Ability_Group::counts();

		return isset( $counts[ $group ] ) ? (int) $counts[ $group ] : 0;
	}

	/**
	 * Why a row cannot be switched on, or null when it can.
	 *
	 * Every `action_url` here is a link to a screen WordPress already ships, never an action this
	 * plugin performs. "Install" opens core's plugin search; "Activate" opens the Plugins list. That
	 * is a deliberate limit, not an unfinished one.
	 *
	 * @since  0.0.42
	 * @param  string                $slug      Integration slug.
	 * @param  string                $name      Display name.
	 * @param  array<string, string> $entry     Catalogue entry.
	 * @param  bool                  $available Whether the host plugin is active here.
	 * @param  string                $product   'free' or 'pro'.
	 * @param  bool                  $pro       Whether AcrossAI Pro is active.
	 * @return array<string, string>|null
	 */
	private function block( string $slug, string $name, array $entry, bool $available, string $product, bool $pro ): ?array {
		if ( $available ) {
			return null;
		}

		if ( 'pro' === $product && ! $pro ) {
			return array(
				'kind'       => 'pro',
				'text'       => __( 'Requires AcrossAI Pro', 'acrossai-abilities-manager' ),
				'action'     => __( 'Get AcrossAI Pro', 'acrossai-abilities-manager' ),
				'action_url' => 'https://acrossai.co/pricing/',
			);
		}

		$wporg = (string) ( $entry['wporg_slug'] ?? '' );
		$state = $this->plugin_state( $wporg );

		if ( 'inactive' === $state ) {
			return array(
				'kind'       => 'dep',
				/* translators: %s is a plugin name. */
				'text'       => sprintf( __( '%s installed, inactive', 'acrossai-abilities-manager' ), $name ),
				'action'     => __( 'Activate', 'acrossai-abilities-manager' ),
				'action_url' => admin_url( 'plugins.php?s=' . rawurlencode( $name ) . '&plugin_status=inactive' ),
			);
		}

		return array(
			'kind'       => 'dep',
			/* translators: %s is a plugin name. */
			'text'       => sprintf( __( '%s not installed', 'acrossai-abilities-manager' ), $name ),
			'action'     => __( 'Install', 'acrossai-abilities-manager' ),
			'action_url' => '' !== $wporg
				? admin_url( 'plugin-install.php?s=' . rawurlencode( $wporg ) . '&tab=search&type=term' )
				: '',
		);
	}

	/**
	 * Whether a WordPress.org plugin slug is installed here, and whether it is active.
	 *
	 * Matched on the plugin's own directory name, which is what the wp.org slug is. Keyed per
	 * request because `get_plugins()` reads every plugin header off disk.
	 *
	 * @since  0.0.42
	 * @param  string $wporg_slug Plugin directory slug.
	 * @return string 'active', 'inactive' or 'missing'.
	 */
	private function plugin_state( string $wporg_slug ): string {
		if ( '' === $wporg_slug ) {
			return 'missing';
		}

		static $installed = null;

		if ( null === $installed ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$installed = array();

			foreach ( array_keys( (array) get_plugins() ) as $file ) {
				$file = (string) $file;
				$dir  = strtok( $file, '/' );

				// A single-file plugin has no directory; its slug is the basename.
				$installed[ false === $dir || '' === $dir ? basename( $file, '.php' ) : $dir ] = $file;
			}
		}

		if ( ! isset( $installed[ $wporg_slug ] ) ) {
			return 'missing';
		}

		return is_plugin_active( $installed[ $wporg_slug ] ) ? 'active' : 'inactive';
	}

	/**
	 * Whether the paid add-on is active.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	private function pro_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::PRO_PLUGIN );
	}

	/**
	 * Turn one integration on or off.
	 *
	 * @since  0.0.42
	 * @param  \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_enabled( \WP_REST_Request $request ) {
		$slug    = sanitize_key( (string) $request->get_param( 'slug' ) );
		$desired = (bool) $request->get_param( 'enabled' );
		$known   = AcrossAI_Integration_Settings::discover();

		// Only integrations this site actually has can be toggled. Accepting an arbitrary slug would
		// let the option accumulate rows for software that is not here, which is exactly the sparse
		// storage problem the settings class warns about.
		if ( ! isset( $known[ $slug ] ) ) {
			return new \WP_Error(
				'acrossai_unknown_integration',
				__( 'That integration is not available on this site.', 'acrossai-abilities-manager' ),
				array( 'status' => 404 )
			);
		}

		$required_cap = AcrossAI_Integration_Settings::required_capability( $slug );

		if ( '' === $required_cap || ! current_user_can( $required_cap ) ) {
			/** This action is documented in admin/Partials/Integrations_Menu.php */
			do_action( 'acrossai_integration_toggle_denied', $slug, $required_cap, get_current_user_id() );

			return new \WP_Error(
				'acrossai_integration_forbidden',
				__( 'You are not allowed to change this integration.', 'acrossai-abilities-manager' ),
				array( 'status' => 403 )
			);
		}

		$stored = get_site_option( AcrossAI_Integration_Settings::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored[ $slug ] = $desired;

		update_site_option( AcrossAI_Integration_Settings::OPTION_KEY, $stored );

		return new \WP_REST_Response(
			array(
				'slug'    => $slug,
				'enabled' => AcrossAI_Integration_Settings::is_enabled( $slug ),
				'n'       => (int) $known[ $slug ]['count'],
			),
			200
		);
	}
}
