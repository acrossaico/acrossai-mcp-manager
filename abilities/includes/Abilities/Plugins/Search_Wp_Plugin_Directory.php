<?php
/**
 * Feature 064 — Search the WordPress.org plugin directory.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Plugins
 * @since      0.0.23
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Plugins;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Query the WordPress.org plugin directory via plugins_api( 'query_plugins',
 * ... ). Explicitly requests only the display-relevant fields so payload size
 * stays bounded (~1KB per plugin). Sanitizes `short_description` through
 * wp_kses_post() because WordPress.org serves HTML there.
 *
 * When the underlying WordPress.org call fails, the ability still returns
 * success:true with an empty plugins list and the transport error in the
 * message field — callers distinguish "no results" from "unreachable API"
 * by inspecting the message string.
 */
class Search_Wp_Plugin_Directory extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'plugins/search-wp-plugin-directory',
			'args' => array(
				'label'               => __( 'Search WordPress.org Plugin Directory', 'acrossai-abilities-manager' ),
				'description'         => __( 'Search the WordPress.org plugin directory. Returns slug, name, short_description, rating, active_installs, homepage, and download_link for each hit, plus pagination metadata.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-plugins',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'    => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
							'default' => 10,
						),
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'default' => 1,
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success' => array( 'type' => 'boolean' ),
						'plugins' => array( 'type' => 'array' ),
						'info'    => array( 'type' => 'object' ),
						'message' => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'updates',
						'sub_group'       => 'lifecycle',
						'sub_group_label' => __( 'Lifecycle', 'acrossai-abilities-manager' ),
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
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$query = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		if ( '' === $query ) {
			return array(
				'success' => false,
				'message' => __( 'query is required.', 'acrossai-abilities-manager' ),
			);
		}

		$per_page = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$result = plugins_api(
			'query_plugins',
			array(
				'search'   => $query,
				'per_page' => $per_page,
				'page'     => $page,
				'fields'   => array(
					'slug'              => true,
					'name'              => true,
					'short_description' => true,
					'rating'            => true,
					'active_installs'   => true,
					'homepage'          => true,
					'download_link'     => true,
					'sections'          => false,
					'screenshots'       => false,
					'banners'           => false,
					'icons'             => false,
					'compatibility'     => false,
					'tested'            => false,
					'requires'          => false,
					'requires_php'      => false,
					'tags'              => false,
					'contributors'      => false,
					'last_updated'      => false,
					'downloaded'        => false,
					'added'             => false,
					'author'            => false,
					'author_profile'    => false,
					'versions'          => false,
					'donate_link'       => false,
					'reviews'           => false,
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => true,
				'plugins' => array(),
				'info'    => array(
					'page'    => $page,
					'pages'   => 0,
					'results' => 0,
				),
				'message' => sprintf(
					/* translators: 1: query, 2: error message */
					__( 'WordPress.org search for "%1$s" failed: %2$s', 'acrossai-abilities-manager' ),
					$query,
					$result->get_error_message()
				),
			);
		}

		$plugins_raw = array();
		if ( is_object( $result ) && isset( $result->plugins ) && is_array( $result->plugins ) ) {
			$plugins_raw = $result->plugins;
		}

		$plugins = array();
		foreach ( $plugins_raw as $entry ) {
			$row = is_array( $entry ) ? $entry : (array) $entry;

			$plugins[] = array(
				'slug'              => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'name'              => isset( $row['name'] ) ? wp_strip_all_tags( (string) $row['name'] ) : '',
				'short_description' => isset( $row['short_description'] ) ? wp_kses_post( (string) $row['short_description'] ) : '',
				'rating'            => isset( $row['rating'] ) ? (int) $row['rating'] : 0,
				'active_installs'   => isset( $row['active_installs'] ) ? (int) $row['active_installs'] : 0,
				'homepage'          => isset( $row['homepage'] ) ? esc_url_raw( (string) $row['homepage'] ) : '',
				'download_link'     => isset( $row['download_link'] ) ? esc_url_raw( (string) $row['download_link'] ) : '',
			);
		}

		$info = array(
			'page'    => $page,
			'pages'   => is_object( $result ) && isset( $result->info['pages'] ) ? (int) $result->info['pages'] : 1,
			'results' => is_object( $result ) && isset( $result->info['results'] ) ? (int) $result->info['results'] : count( $plugins ),
		);

		return array(
			'success' => true,
			'plugins' => $plugins,
			'info'    => $info,
			'message' => sprintf(
				/* translators: 1: number of results, 2: query */
				__( 'Returned %1$d plugin(s) for "%2$s".', 'acrossai-abilities-manager' ),
				count( $plugins ),
				$query
			),
		);
	}
}
