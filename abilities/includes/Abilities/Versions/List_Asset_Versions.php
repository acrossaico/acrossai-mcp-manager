<?php
/**
 * Feature 136 — every version WordPress.org published for a plugin or theme.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Lookup;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Versions;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Wp_Rollback_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * The release history for one installed plugin or theme, newest first and paginated.
 *
 * **Works without WP Rollback.** Version history is `plugins_api()` / `themes_api()`, which is
 * WordPress core. Gating this on a plugin it does not use would be a restriction invented here.
 *
 * Paginated from the first line rather than after someone's context window fills: measured,
 * `akismet` alone publishes 94 entries, so a large plugin runs to several hundred.
 *
 * @since 0.0.45
 */
class List_Asset_Versions extends Base_Version_Ability {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'list-asset-versions';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Asset Versions', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List every version WordPress.org published for an installed plugin or theme, newest first, with the installed version marked and each entry labelled older, same or newer than it. Paginated — akismet alone publishes 94 releases. The "trunk" entry WordPress.org includes is excluded because it is not a release. An asset the directory does not publish returns not_on_wordpress_org, and any version this site holds a local archive of is listed separately. Works whether or not WP Rollback is installed; read-only.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function tab_group(): string {
		return 'wp-rollback';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function sub_group(): string {
		return 'versions-read';
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array(
			'type'   => array(
				'type'        => 'string',
				'enum'        => array( 'plugin', 'theme' ),
				'description' => __( 'Which kind of asset. WP Rollback supports these two and not WordPress core.', 'acrossai-abilities-manager' ),
			),
			'plugin' => array(
				'type'        => 'string',
				'description' => __( 'Plugin file, folder name, display name or partial match. Required when type is plugin.', 'acrossai-abilities-manager' ),
			),
			'stylesheet' => array(
				'type'        => 'string',
				'description' => __( 'Theme directory name. Required when type is theme.', 'acrossai-abilities-manager' ),
			),
			'limit'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => Asset_Versions::MAX_LIMIT,
				'default'     => Asset_Versions::DEFAULT_LIMIT,
				'description' => __( 'Page size, newest first.', 'acrossai-abilities-manager' ),
			),
			'offset' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'default'     => 0,
				'description' => __( 'Zero-based offset into the release list.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.45
	 * @return array<int,string>
	 */
	protected function required_input(): array {
		return array( 'type' );
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'type'              => array( 'type' => 'string' ),
			'slug'              => array( 'type' => 'string' ),
			'name'              => array( 'type' => 'string' ),
			'installed_version' => array( 'type' => 'string' ),
			'versions'          => array( 'type' => 'array' ),
			'total'             => array( 'type' => 'integer' ),
			'limit'             => array( 'type' => 'integer' ),
			'offset'            => array( 'type' => 'integer' ),
			'has_more'          => array( 'type' => 'boolean' ),
			'archived_versions' => array( 'type' => 'array' ),
		);
	}

	/**
	 * @since  0.0.45
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.45
	 * @param  array<string,mixed> $input Caller input.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function run( array $input ) {
		$type       = self::scalar( $input['type'] ?? '' );
		$identifier = 'theme' === $type
			? self::scalar( $input['stylesheet'] ?? '' )
			: self::scalar( $input['plugin'] ?? '' );

		$asset = Asset_Lookup::resolve( $type, $identifier );

		if ( is_wp_error( $asset ) ) {
			return $asset;
		}

		// Local archives are reported even when WordPress.org has nothing, because they are the one
		// route by which a premium asset can still be rolled back.
		$archived = Wp_Rollback_Guard::archived_versions( $asset['slug'] );

		$published = Asset_Lookup::published_versions( $type, $asset['slug'] );

		if ( is_wp_error( $published ) ) {
			if ( array() !== $archived ) {
				// Not on WordPress.org, but this site holds archives — a materially different
				// answer from "no versions at all", and the caller needs to know which.
				$archive_page = Asset_Versions::page(
					Asset_Versions::releases( $archived ),
					(int) ( $input['limit'] ?? Asset_Versions::DEFAULT_LIMIT ),
					(int) ( $input['offset'] ?? 0 )
				);

				return array_merge(
					array(
						'type'              => $asset['type'],
						'slug'              => $asset['slug'],
						'name'              => $asset['name'],
						'installed_version' => $asset['installed_version'],
						'archived_versions' => array_keys( $archived ),
						'message'           => sprintf(
							/* translators: 1: asset name, 2: archive count */
							__( '"%1$s" is not published on WordPress.org, but this site holds %2$d locally archived version(s) that WP Rollback can restore.', 'acrossai-abilities-manager' ),
							$asset['name'],
							count( $archived )
						),
					),
					$this->decorate( $archive_page, $asset['installed_version'] )
				);
			}

			return $published;
		}

		$page = Asset_Versions::page(
			Asset_Versions::releases( $published ),
			(int) ( $input['limit'] ?? Asset_Versions::DEFAULT_LIMIT ),
			(int) ( $input['offset'] ?? 0 )
		);

		return array_merge(
			array(
				'type'              => $asset['type'],
				'slug'              => $asset['slug'],
				'name'              => $asset['name'],
				'installed_version' => $asset['installed_version'],
				'archived_versions' => array_keys( $archived ),
				'message'           => sprintf(
					/* translators: 1: total, 2: asset name, 3: installed version */
					__( 'WordPress.org publishes %1$d release(s) for "%2$s"; %3$s is installed.', 'acrossai-abilities-manager' ),
					$page['total'],
					$asset['name'],
					'' !== $asset['installed_version'] ? $asset['installed_version'] : __( 'an unknown version', 'acrossai-abilities-manager' )
				),
			),
			$this->decorate( $page, $asset['installed_version'] )
		);
	}

	/**
	 * Turn a page of version strings into rows that answer "is this a downgrade?".
	 *
	 * @since  0.0.45
	 * @param  array<string,mixed> $page      Page from {@see Asset_Versions::page()}.
	 * @param  string              $installed Installed version.
	 * @return array<string,mixed>
	 */
	private function decorate( array $page, string $installed ): array {
		$rows = array();

		foreach ( $page['versions'] as $version ) {
			$rows[] = array(
				'version'   => $version,
				'relation'  => Asset_Versions::relation( $version, $installed ),
				'installed' => $version === $installed,
			);
		}

		return array(
			'versions' => $rows,
			'total'    => $page['total'],
			'limit'    => $page['limit'],
			'offset'   => $page['offset'],
			'has_more' => $page['has_more'],
		);
	}
}
