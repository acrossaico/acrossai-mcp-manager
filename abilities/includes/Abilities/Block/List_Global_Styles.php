<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Db;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_File;

defined( 'ABSPATH' ) || exit;

/**
 * Lists Global Styles across every storage layer (Scenario 26).
 *  - source=db      → every wp_global_styles row, with the theme it belongs to
 *                     and which sections are customised vs using theme.json defaults
 *  - source=theme   → /theme.json files in the active or named theme
 *  - source=plugin  → /theme.json files in installed plugins
 *  - source=all     → union (default)
 *
 * Each DB row carries is_active_theme so the caller can pick out the record
 * WordPress is currently using. Each row also carries an "effective" flag —
 * for any given theme, exactly one location is marked effective (DB → child
 * → parent → plugin).
 */
class List_Global_Styles extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/list-global-styles',
			'args' => array(
				'label'               => __( 'List Global Styles', 'acrossai-abilities-manager' ),
				'description'         => __( 'Lists Global Styles records across the database (wp_global_styles) and theme.json files in themes and plugins. Each record reports its theme association, which sections are customised, whether WordPress is actually applying it (applied_by_wordpress), and whether it is the copy WordPress is currently serving.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'source'          => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'db', 'theme', 'plugin' ),
							'default' => 'all',
						),
						'theme_type'      => array(
							'type'    => 'string',
							'enum'    => array( '', 'child', 'parent', 'theme' ),
							'default' => '',
						),
						'theme_slug'      => array(
							'type'        => 'string',
							'default'     => '',
							'description' => __( 'Restrict to a specific theme. Defaults to all themes (for DB) / active theme (for files).', 'acrossai-abilities-manager' ),
						),
						'plugin_slug'     => array(
							'type'    => 'string',
							'default' => '',
						),
						'include_content' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'      => array( 'type' => 'boolean' ),
						'records'      => array( 'type' => 'array' ),
						'total'        => array( 'type' => 'integer' ),
						'active_theme' => array( 'type' => 'string' ),
						'warnings'     => array( 'type' => 'array' ),
						'message'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'global-styles',
						'sub_group_label' => __( 'Global Styles', 'acrossai-abilities-manager' ),
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
	 * Feature 095 follow-up — hint that active-theme reads should skip the
	 * cross-source enumeration.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'blocks/read-global-style',
				'reason' => __( 'If you want the active theme\'s effective styles rather than every theme.json across every theme/plugin, a targeted read-global-style is far cheaper.', 'acrossai-abilities-manager' ),
				'saves'  => __( '~10x fewer tokens for active-theme reads', 'acrossai-abilities-manager' ),
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
		$source          = sanitize_text_field( $input['source'] ?? 'all' );
		$theme_type      = sanitize_text_field( $input['theme_type'] ?? '' );
		$theme_slug      = sanitize_key( $input['theme_slug'] ?? '' );
		$plugin_slug     = sanitize_key( $input['plugin_slug'] ?? '' );
		$include_content = ! empty( $input['include_content'] );

		$rows = array();

		if ( 'all' === $source || 'db' === $source ) {
			$rows = array_merge( $rows, $this->collect_db( $theme_slug, $include_content ) );
		}

		if ( 'all' === $source || 'theme' === $source ) {
			$rows = array_merge( $rows, $this->collect_theme( $theme_type, $theme_slug, $include_content ) );
		}

		if ( 'all' === $source || 'plugin' === $source ) {
			$rows = array_merge( $rows, $this->collect_plugins( $plugin_slug, $include_content ) );
		}

		$rows = $this->mark_effective( $rows );

		$warnings = array();
		if ( is_multisite() ) {
			$warnings[] = __( 'On multisite, DB Global Styles records are scoped to the current site only; theme.json files are shared across all sites.', 'acrossai-abilities-manager' );
		}

		// Surface the per-record problems at the top level too: a caller scanning only the summary
		// would otherwise see an inert record listed as an ordinary one.
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['warnings'] ?? array() ) as $row_warning ) {
				$warnings[] = sprintf(
					/* translators: 1: post ID, 2: warning text. */
					__( 'wp_global_styles #%1$d: %2$s', 'acrossai-abilities-manager' ),
					(int) ( $row['post_id'] ?? 0 ),
					(string) $row_warning
				);
			}
		}

		return array(
			'success'      => true,
			'records'      => $rows,
			'total'        => count( $rows ),
			'active_theme' => (string) get_stylesheet(),
			'warnings'     => $warnings,
		);
	}

	/**
	 * Collect db.
	 *
	 * @param string $theme_slug
	 * @param bool $include_content
	 * @return array
	 */
	private function collect_db( string $theme_slug, bool $include_content ): array {
		$posts = Global_Styles_Db::list_all( 500 );
		$rows  = array();
		foreach ( $posts as $post ) {
			$theme = Global_Styles_Db::get_post_theme( $post );
			if ( '' !== $theme_slug && $theme !== $theme_slug ) {
				continue;
			}
			$rows[] = Global_Styles_Db::to_row( $post, $include_content );
		}
		return $rows;
	}

	/**
	 * Collect theme.
	 *
	 * @param string $theme_type
	 * @param string $theme_slug
	 * @param bool $include_content
	 * @return array
	 */
	private function collect_theme( string $theme_type, string $theme_slug, bool $include_content ): array {
		$rows      = array();
		$child_dir = Global_Styles_File::get_child_theme_dir();
		$is_child  = null !== $child_dir;

		if ( '' === $theme_type || 'child' === $theme_type ) {
			if ( $is_child ) {
				$row = $this->theme_row( Global_Styles_File::theme_json_path( $child_dir ), 'child', basename( $child_dir ), $include_content );
				if ( null !== $row ) {
					$rows[] = $row;
				}
			}
		}

		if ( '' === $theme_type || 'parent' === $theme_type || 'theme' === $theme_type ) {
			$parent_dir = '' !== $theme_slug
				? get_theme_root() . '/' . $theme_slug
				: Global_Styles_File::get_parent_theme_dir();
			$tt         = $is_child ? 'parent' : 'theme';
			$row        = $this->theme_row( Global_Styles_File::theme_json_path( $parent_dir ), $tt, basename( $parent_dir ), $include_content );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Collect plugins.
	 *
	 * @param string $plugin_slug
	 * @param bool $include_content
	 * @return array
	 */
	private function collect_plugins( string $plugin_slug, bool $include_content ): array {
		$rows = array();
		foreach ( Global_Styles_File::scan_plugins_with_theme_json() as $plugin ) {
			if ( '' !== $plugin_slug && $plugin['slug'] !== $plugin_slug ) {
				continue;
			}
			$row = array(
				'source'        => 'plugin',
				'plugin'        => $plugin['slug'],
				'plugin_active' => (bool) $plugin['active'],
				'path'          => $plugin['path'],
				'writable'      => is_writable( $plugin['path'] ),
			);
			if ( $include_content ) {
				$data = Global_Styles_File::read_json( $plugin['path'] );
				if ( ! is_wp_error( $data ) ) {
					$row['data'] = $data;
				}
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Theme row.
	 *
	 * @param string $path
	 * @param string $theme_type
	 * @param string $theme
	 * @param bool $include_content
	 * @return ?array
	 */
	private function theme_row( string $path, string $theme_type, string $theme, bool $include_content ): ?array {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$row = array(
			'source'          => 'theme',
			'theme_type'      => $theme_type,
			'theme'           => $theme,
			'path'            => $path,
			'writable'        => is_writable( $path ),
			'is_active_theme' => $theme === (string) get_stylesheet() || $theme === (string) get_template(),
		);
		if ( $include_content ) {
			$data = Global_Styles_File::read_json( $path );
			if ( ! is_wp_error( $data ) ) {
				$row['data'] = $data;
			}
		}
		return $row;
	}

	/**
	 * Per-theme, marks the highest-priority location (DB → child → parent →
	 * plugin) as effective and the rest as overridden.
	 */
	private function mark_effective( array $rows ): array {
		$by_theme = array();
		foreach ( $rows as $row ) {
			$theme = (string) ( $row['theme'] ?? ( $row['plugin'] ?? '' ) );
			if ( 'plugin' === ( $row['source'] ?? '' ) ) {
				$theme = 'plugin:' . ( $row['plugin'] ?? '' );
			}
			$by_theme[ $theme ][] = $row;
		}

		$priority = static function ( array $row ): int {
			if ( 'db' === ( $row['source'] ?? '' ) ) {
				// A DB record WordPress is ignoring — no isGlobalStylesUserThemeJSON, or content that
				// does not decode — outranks nothing. It used to be marked effective regardless,
				// which told the caller the site was using styles it was not. Ranked last instead,
				// so the theme.json copy the site really is serving is the one flagged.
				return false === ( $row['applied_by_wordpress'] ?? true ) ? 9 : 0;
			}
			if ( 'theme' === ( $row['source'] ?? '' ) ) {
				return 'child' === ( $row['theme_type'] ?? '' ) ? 1 : 2;
			}
			return 3;
		};

		$out = array();
		foreach ( $by_theme as $group ) {
			usort(
				$group,
				static function ( $a, $b ) use ( $priority ): int {
					return $priority( $a ) <=> $priority( $b );
				}
			);
			foreach ( $group as $i => $row ) {
				$row['effective'] = ( 0 === $i ) && false !== ( $row['applied_by_wordpress'] ?? true );
				if ( false === $row['effective'] ) {
					$winner               = $group[0];
					$row['overridden_by'] = ( $winner['source'] ?? '' ) . ( isset( $winner['theme_type'] ) ? ':' . $winner['theme_type'] : '' );
				}
				$out[] = $row;
			}
		}
		return $out;
	}
}
