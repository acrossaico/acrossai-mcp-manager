<?php
/**
 * REST controller for the File Manager settings tab (Feature 092).
 *
 * Exposes three GET/POST pairs under acrossai/v1:
 *
 *   GET/POST /file-manager-settings/write-allowlist
 *   GET/POST /file-manager-settings/read-allowlist
 *   GET/POST /file-manager-settings/redaction
 *
 * All routes require manage_options + a valid X-WP-Nonce; both checks are
 * delegated to AcrossAI_Abilities_Rest_Controller::check_permission() so
 * the security contract stays in one place.
 *
 * The two GET allowlist routes include enumeration data (core dirs under
 * ABSPATH, installed plugins via get_plugins(), installed themes via
 * wp_get_themes()) alongside the current allowlist so the React admin UI
 * can render the folder picker without a second round-trip.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Rest
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Rest;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Rest\AcrossAI_Abilities_Rest_Controller;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Audit_Trail;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Secret_Redactor;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Hardening_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * File_Manager_Settings_Controller.
 */
final class File_Manager_Settings_Controller {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton.
	 *
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
	 */
	private function __construct() {}

	/**
	 * Base URL fragment for this feature's routes.
	 */
	public const REST_BASE = 'file-manager-settings';

	/**
	 * Register routes on rest_api_init.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$permission = array( AcrossAI_Abilities_Rest_Controller::instance(), 'check_permission' );

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/write-allowlist',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_write_allowlist' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_write_allowlist' ),
					'permission_callback' => $permission,
					'args'                => array(
						'allowed_paths' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/read-allowlist',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_read_allowlist' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_read_allowlist' ),
					'permission_callback' => $permission,
					'args'                => array(
						'allowed_paths' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/redaction',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_redaction' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_redaction' ),
					'permission_callback' => $permission,
					'args'                => array(
						'patterns'        => array( 'type' => 'object' ),
						'custom_literals' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/content-filters',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content_filters' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_content_filters' ),
					'permission_callback' => $permission,
					'args'                => array(
						'dangerous_extensions'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'block_double_extensions' => array( 'type' => 'boolean' ),
						'htaccess_directive_scan' => array( 'type' => 'boolean' ),
						'sanitize_filename_check' => array( 'type' => 'boolean' ),
						'write_max_bytes'         => array( 'type' => 'integer' ),
						'sensitive_read_denylist' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
						'strict_filename_filter'  => array( 'type' => 'boolean' ),
						'mime_type_check'         => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/backup-audit',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_backup_audit' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_backup_audit' ),
					'permission_callback' => $permission,
					'args'                => array(
						'audit_log_enabled'        => array( 'type' => 'boolean' ),
						'audit_log_retention_days' => array( 'type' => 'integer' ),
						'backup_enabled'           => array( 'type' => 'boolean' ),
						'backup_retention_days'    => array( 'type' => 'integer' ),
					),
				),
			)
		);

		// Feature 094: point-in-time stats for the Backup & Audit panel info line.
		register_rest_route(
			AcrossAI_Abilities_Rest_Controller::REST_NAMESPACE,
			'/' . self::REST_BASE . '/backup-audit-stats',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_backup_audit_stats' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/* -------------------------------------------------------------------- */
	/* Write allowlist                                                       */
	/* -------------------------------------------------------------------- */

	/**
	 * GET /file-manager-settings/write-allowlist
	 *
	 * @return \WP_REST_Response
	 */
	public function get_write_allowlist(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'allowed_paths' => Path_Allowlist_Guard::get_write_paths(),
				'available'     => $this->build_available(),
			),
			200
		);
	}

	/**
	 * POST /file-manager-settings/write-allowlist
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_write_allowlist( \WP_REST_Request $request ) {
		$raw = (array) $request->get_param( 'allowed_paths' );
		Path_Allowlist_Guard::set_write_paths( $raw );
		return $this->get_write_allowlist();
	}

	/* -------------------------------------------------------------------- */
	/* Read allowlist                                                        */
	/* -------------------------------------------------------------------- */

	/**
	 * GET /file-manager-settings/read-allowlist
	 *
	 * @return \WP_REST_Response
	 */
	public function get_read_allowlist(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'allowed_paths' => Path_Allowlist_Guard::get_read_paths(),
				'available'     => $this->build_available(),
			),
			200
		);
	}

	/**
	 * POST /file-manager-settings/read-allowlist
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_read_allowlist( \WP_REST_Request $request ) {
		$raw = (array) $request->get_param( 'allowed_paths' );
		Path_Allowlist_Guard::set_read_paths( $raw );
		return $this->get_read_allowlist();
	}

	/* -------------------------------------------------------------------- */
	/* Redaction                                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * GET /file-manager-settings/redaction
	 *
	 * @return \WP_REST_Response
	 */
	public function get_redaction(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'config'             => Secret_Redactor::get_config(),
				'available'          => Secret_Redactor::available_patterns(),
				'connector_sources'  => Secret_Redactor::available_connector_sources(),
			),
			200
		);
	}

	/**
	 * POST /file-manager-settings/redaction
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_redaction( \WP_REST_Request $request ) {
		$patterns = (array) $request->get_param( 'patterns' );
		$literals = (array) $request->get_param( 'custom_literals' );
		Secret_Redactor::set_config(
			array(
				'patterns'        => $patterns,
				'custom_literals' => $literals,
			)
		);
		return $this->get_redaction();
	}

	/* -------------------------------------------------------------------- */
	/* Content filters (feature 093 scope — scaffold only)                   */
	/* -------------------------------------------------------------------- */

	/**
	 * GET /file-manager-settings/content-filters
	 *
	 * Returns the current snapshot plus a `scaffold_only:true` marker so the
	 * React panel can render its "not yet enforced" banner without needing
	 * an out-of-band signal.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_content_filters(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'config'         => Hardening_Settings::get_content_filters(),
				// Feature 093 flipped this from true → false: every content-filter
				// option here is now consumed at runtime by Hardening_Enforcer.
				'scaffold_only'  => false,
				'follow_up_spec' => null,
				'limits'         => array(
					'write_max_bytes_min' => Hardening_Settings::MIN_WRITE_MAX_BYTES,
					'write_max_bytes_max' => Hardening_Settings::MAX_WRITE_MAX_BYTES,
				),
			),
			200
		);
	}

	/**
	 * POST /file-manager-settings/content-filters
	 *
	 * Response merges the freshly-persisted snapshot with any per-entry
	 * rejections the sanitiser reported (empty array when nothing was
	 * dropped). The React panel renders `skipped` as a warning notice so
	 * admins see exactly which entries didn't persist and why.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_content_filters( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		$result = Hardening_Settings::set_content_filters( (array) $payload );
		return new \WP_REST_Response(
			array(
				'config'         => $result['config'],
				'skipped'        => $result['skipped'],
				'scaffold_only'  => false,
				'follow_up_spec' => null,
				'limits'         => array(
					'write_max_bytes_min' => Hardening_Settings::MIN_WRITE_MAX_BYTES,
					'write_max_bytes_max' => Hardening_Settings::MAX_WRITE_MAX_BYTES,
				),
			),
			200
		);
	}

	/* -------------------------------------------------------------------- */
	/* Backup + audit (feature 094 — live)                                   */
	/* -------------------------------------------------------------------- */

	/**
	 * GET /file-manager-settings/backup-audit
	 *
	 * @return \WP_REST_Response
	 */
	public function get_backup_audit(): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'config'         => Hardening_Settings::get_backup_audit(),
				// Feature 094 flipped scaffold_only → false: every toggle
				// here is now consumed at runtime by Audit_Trail.
				'scaffold_only'  => false,
				'follow_up_spec' => null,
				'limits'         => array(
					'retention_days_min' => Hardening_Settings::MIN_RETENTION_DAYS,
					'retention_days_max' => Hardening_Settings::MAX_RETENTION_DAYS,
				),
			),
			200
		);
	}

	/**
	 * POST /file-manager-settings/backup-audit
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_backup_audit( \WP_REST_Request $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_params();
		}
		Hardening_Settings::set_backup_audit( (array) $payload );
		return $this->get_backup_audit();
	}

	/* -------------------------------------------------------------------- */
	/* Backup + audit stats (feature 094 — panel info line)                  */
	/* -------------------------------------------------------------------- */

	/**
	 * GET /file-manager-settings/backup-audit-stats
	 *
	 * Returns point-in-time backup dir + log stats for the panel's info line.
	 * Delegates to Audit_Trail::stats() which reads the filesystem via
	 * WP_Filesystem.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_backup_audit_stats(): \WP_REST_Response {
		return new \WP_REST_Response( Audit_Trail::stats(), 200 );
	}

	/* -------------------------------------------------------------------- */
	/* Enumeration                                                           */
	/* -------------------------------------------------------------------- */

	/**
	 * Build the enumeration payload the React admin UI needs to render the
	 * folder tree + plugin picker + theme picker.
	 *
	 * @return array{core: array<int,string>, plugins: array<int,array{slug:string,name:string}>, themes: array<int,array{stylesheet:string,name:string}>}
	 */
	private function build_available(): array {
		return array(
			'core'    => $this->enumerate_core_dirs(),
			'plugins' => $this->enumerate_plugins(),
			'themes'  => $this->enumerate_themes(),
		);
	}

	/**
	 * List immediate directories under ABSPATH.
	 *
	 * Filters out non-directories and dot entries. Also returns the well-known
	 * wp-content immediate children (plugins, themes, uploads, mu-plugins,
	 * languages, upgrade) so the UI can render a two-level tree without a
	 * second call.
	 *
	 * @return array<int,array{path:string,children:array<int,string>}>
	 */
	private function enumerate_core_dirs(): array {
		$base = rtrim( realpath( ABSPATH ) ?: ABSPATH, '/' );
		$out  = array();

		$entries = @scandir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return $out;
		}
		sort( $entries );

		foreach ( $entries as $entry ) {
			if ( '' === $entry || '.' === $entry[0] ) {
				continue;
			}
			$full = $base . '/' . $entry;
			if ( ! is_dir( $full ) ) {
				continue;
			}
			$children = array();
			if ( 'wp-content' === $entry ) {
				$sub_entries = @scandir( $full ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false !== $sub_entries ) {
					sort( $sub_entries );
					foreach ( $sub_entries as $sub ) {
						if ( '' === $sub || '.' === $sub[0] ) {
							continue;
						}
						if ( is_dir( $full . '/' . $sub ) ) {
							$children[] = 'wp-content/' . $sub;
						}
					}
				}
			}
			$out[] = array(
				'path'     => $entry,
				'children' => $children,
			);
		}

		return $out;
	}

	/**
	 * List installed plugins as {slug, name} pairs. Slug is the plugin
	 * directory (or file basename for single-file plugins).
	 *
	 * @return array<int,array{slug:string,name:string}>
	 */
	private function enumerate_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$out     = array();
		foreach ( $plugins as $file => $data ) {
			$slug  = str_contains( $file, '/' ) ? substr( $file, 0, strpos( $file, '/' ) ) : $file;
			$name  = isset( $data['Name'] ) ? (string) $data['Name'] : $slug;
			$out[] = array(
				'slug' => $slug,
				'name' => $name,
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * List installed themes as {stylesheet, name} pairs.
	 *
	 * @return array<int,array{stylesheet:string,name:string}>
	 */
	private function enumerate_themes(): array {
		if ( ! function_exists( 'wp_get_themes' ) ) {
			return array();
		}
		$themes = wp_get_themes();
		$out    = array();
		foreach ( $themes as $stylesheet => $theme ) {
			$out[] = array(
				'stylesheet' => (string) $stylesheet,
				'name'       => (string) $theme->get( 'Name' ),
			);
		}
		usort(
			$out,
			static function ( array $a, array $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);
		return $out;
	}
}
