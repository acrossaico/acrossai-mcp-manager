<?php
/**
 * Get_Environment ability (Feature 129).
 *
 * Slug: debugging/get-environment
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\QueryMonitor\Query_Monitor_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return Query Monitor's environment snapshot: PHP, database, WordPress and server.
 *
 * Richer than Site Health for this purpose — it carries PHP's loaded extensions,
 * the MySQL server variables, and the active constants Query Monitor surfaces.
 * Request-independent and read-only.
 */
class Get_Environment extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		$open_object = array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);

		return array(
			'name' => 'debugging/get-environment',
			'args' => array(
				'label'               => __( 'Get Environment (Query Monitor)', 'acrossai-abilities-manager' ),
				'description'         => __( 'Returns Query Monitor\'s environment snapshot — PHP version, loaded extensions and key limits; the MySQL server/client version and server variables; the WordPress version, environment type and active constants; and the server software. Read-only and request-independent. Requires the Query Monitor plugin to be active.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-debugging',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'active'  => array( 'type' => 'boolean' ),
						'message' => array( 'type' => 'string' ),
						'source'  => array( 'type' => 'string' ),
						'php'     => $open_object,
						'db'      => $open_object,
						'wp'      => $open_object,
						'server'  => $open_object,
					),
					'required'             => array( 'active' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'query-monitor',
						'sub_group'       => 'query-monitor',
						'sub_group_label' => __( 'Query Monitor', 'acrossai-abilities-manager' ),
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
	 * Execute — read Query Monitor's environment collector.
	 *
	 * @param array $input Ignored — this ability accepts no input.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		if ( ! Query_Monitor_Guard::is_active() ) {
			return Query_Monitor_Guard::not_active();
		}

		$data = Query_Monitor_Guard::collector_data( 'environment' );

		// Query Monitor's environment collector assembles its data from the
		// db_queries collector, which is not populated during an isolated REST or
		// MCP request — so over those transports its process() yields nothing. Fall
		// back to reading the same facts directly, which works in any context.
		if ( null === $data || empty( $data->php ) ) {
			return array( 'active' => true, 'source' => 'direct' ) + self::direct_snapshot();
		}

		return array(
			'active' => true,
			'source' => 'query-monitor',
			'php'    => isset( $data->php ) && is_array( $data->php ) ? $data->php : array(),
			'db'     => isset( $data->db ) && is_array( $data->db ) ? $data->db : array(),
			'wp'     => isset( $data->wp ) && is_array( $data->wp ) ? $data->wp : array(),
			'server' => isset( $data->server ) && is_array( $data->server ) ? $data->server : array(),
		);
	}

	/**
	 * Read the environment facts directly, without Query Monitor's collector.
	 *
	 * @return array{php:array,db:array,wp:array,server:array}
	 */
	private static function direct_snapshot(): array {
		global $wpdb, $wp_version;

		$constants = array();
		foreach ( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'WP_ENVIRONMENT_TYPE', 'WP_CACHE', 'FORCE_SSL_ADMIN' ) as $name ) {
			if ( defined( $name ) ) {
				$value              = constant( $name );
				$constants[ $name ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			} else {
				$constants[ $name ] = 'undefined';
			}
		}

		$db_version = '';
		if ( $wpdb instanceof \wpdb && method_exists( $wpdb, 'db_version' ) ) {
			$db_version = (string) $wpdb->db_version();
		}

		return array(
			'php'    => array(
				'version'    => phpversion(),
				'extensions' => get_loaded_extensions(),
				'limits'     => array(
					'memory_limit'        => ini_get( 'memory_limit' ),
					'max_execution_time'  => ini_get( 'max_execution_time' ),
					'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
					'post_max_size'       => ini_get( 'post_max_size' ),
					'max_input_vars'      => ini_get( 'max_input_vars' ),
				),
			),
			'db'     => array(
				'server_version' => $db_version,
			),
			'wp'     => array(
				'version'          => isset( $wp_version ) ? (string) $wp_version : get_bloginfo( 'version' ),
				'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
				'constants'        => $constants,
			),
			'server' => array(
				'software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			),
		);
	}
}
