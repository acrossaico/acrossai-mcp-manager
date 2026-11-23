<?php
/**
 * Feature 136 — everything worth knowing before moving a plugin or theme's version.
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
 * Pre-flight for a version move: can it be done, and what will it affect.
 *
 * Reads only. The point is that every refusal a rollback would hit is discoverable *before* files
 * are replaced — and that the two consequences a human forgets are stated: a network-activated
 * plugin affects every site on a multisite, and rolling back the active theme changes what visitors
 * see immediately.
 *
 * @since 0.0.45
 */
class Check_Asset_Rollback extends Base_Version_Ability {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'check-asset-rollback';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Check Asset Rollback', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Pre-flight a version move for an installed plugin or theme without touching anything: whether WP Rollback is loaded, whether WordPress.org publishes the asset and that exact version, whether this site holds a local archive of it, whether the target is older or newer than what is installed, and the two consequences worth knowing first — on multisite, whether other sites use it, and for a theme, whether it is the active one. Read-only.', 'acrossai-abilities-manager' );
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
			'type'       => array(
				'type' => 'string',
				'enum' => array( 'plugin', 'theme' ),
			),
			'plugin'     => array(
				'type'        => 'string',
				'description' => __( 'Plugin file, folder name, display name or partial match. Required when type is plugin.', 'acrossai-abilities-manager' ),
			),
			'stylesheet' => array(
				'type'        => 'string',
				'description' => __( 'Theme directory name. Required when type is theme.', 'acrossai-abilities-manager' ),
			),
			'version'    => array(
				'type'        => 'string',
				'description' => __( 'The version you intend to move to. Omit to check only whether the asset is eligible at all.', 'acrossai-abilities-manager' ),
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
			'type'                 => array( 'type' => 'string' ),
			'slug'                 => array( 'type' => 'string' ),
			'name'                 => array( 'type' => 'string' ),
			'installed_version'    => array( 'type' => 'string' ),
			'target_version'       => array( 'type' => 'string' ),
			'can_roll_back'        => array( 'type' => 'boolean' ),
			'reasons'              => array( 'type' => 'array' ),
			'on_wordpress_org'     => array( 'type' => 'boolean' ),
			'target_published'     => array( 'type' => 'boolean' ),
			'target_archived'      => array( 'type' => 'boolean' ),
			'relation'             => array( 'type' => 'string' ),
			'used_on_other_sites'  => array( 'type' => 'string' ),
			'is_active_theme'      => array( 'type' => 'boolean' ),
			'warnings'             => array( 'type' => 'array' ),
			'validation_note'      => array( 'type' => 'string' ),
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
		$type   = self::scalar( $input['type'] ?? '' );
		$target = self::scalar( $input['version'] ?? '' );

		$asset = Asset_Lookup::resolve(
			$type,
			'theme' === $type ? self::scalar( $input['stylesheet'] ?? '' ) : self::scalar( $input['plugin'] ?? '' )
		);

		if ( is_wp_error( $asset ) ) {
			return $asset;
		}

		$reasons  = array();
		$warnings = array();

		// Not a blocking reason any more. versions/rollback-plugin and versions/rollback-theme drive
		// WordPress core's own upgrader, so a version move needs no host plugin. WP Rollback still
		// matters for one thing — the local archives that are the only route to a version of an
		// asset WordPress.org does not host — and that is reported where it applies, below.
		if ( ! Wp_Rollback_Guard::is_active() ) {
			$warnings[] = __( 'WP Rollback is not active. Version moves still work without it, using packages from WordPress.org; what is unavailable is its local archive of previously installed versions, which is the only source for an asset the directory does not host.', 'acrossai-abilities-manager' );
		}

		$archived  = Wp_Rollback_Guard::archived_versions( $asset['slug'] );
		$published = Asset_Lookup::published_versions( $type, $asset['slug'] );
		$on_wporg  = ! is_wp_error( $published );
		$releases  = $on_wporg ? Asset_Versions::releases( (array) $published ) : array();

		if ( ! $on_wporg ) {
			if ( array() === $archived ) {
				$reasons[] = sprintf(
					/* translators: %s: asset name */
					__( '"%s" is not published on WordPress.org and this site holds no local archive of an earlier version, so there is nothing to roll back to.', 'acrossai-abilities-manager' ),
					$asset['name']
				);
			} else {
				$warnings[] = __( 'Not published on WordPress.org. Only versions this site has locally archived can be restored.', 'acrossai-abilities-manager' );
			}
		}

		$target_published = '' !== $target && Asset_Versions::is_published( $target, $releases );
		$target_archived  = '' !== $target && isset( $archived[ $target ] );

		if ( '' !== $target && ! $target_published && ! $target_archived ) {
			$reasons[] = sprintf(
				/* translators: 1: version, 2: asset name */
				__( 'Version %1$s is neither published for "%2$s" on WordPress.org nor archived on this site.', 'acrossai-abilities-manager' ),
				$target,
				$asset['name']
			);
		}

		$relation = '' !== $target ? Asset_Versions::relation( $target, $asset['installed_version'] ) : 'unknown';

		if ( 'same' === $relation ) {
			$warnings[] = __( 'That is the version already installed, so the move would change nothing.', 'acrossai-abilities-manager' );
		}

		$used_elsewhere = Wp_Rollback_Guard::used_on_other_sites( $type, $asset['slug'] );

		if ( true === $used_elsewhere ) {
			$warnings[] = __( 'This is in use by other sites on this network. Rolling it back affects all of them, not just this site.', 'acrossai-abilities-manager' );
		}

		$is_active_theme = 'theme' === $type && get_stylesheet() === $asset['slug'];

		if ( $is_active_theme ) {
			$warnings[] = __( 'This is the active theme. Rolling it back changes what visitors see as soon as the files are replaced.', 'acrossai-abilities-manager' );
		}

		return array(
			'type'                => $asset['type'],
			'slug'                => $asset['slug'],
			'name'                => $asset['name'],
			'installed_version'   => $asset['installed_version'],
			'target_version'      => $target,
			'can_roll_back'       => array() === $reasons,
			'reasons'             => $reasons,
			'on_wordpress_org'    => $on_wporg,
			'target_published'    => $target_published,
			'target_archived'     => $target_archived,
			'relation'            => $relation,
			// A string, not a boolean, because there are three answers and false would conflate two
			// of them: single-site and "the service could not be asked" are not "no".
			'used_on_other_sites' => null === $used_elsewhere ? 'unknown' : ( $used_elsewhere ? 'yes' : 'no' ),
			'is_active_theme'     => $is_active_theme,
			'warnings'            => $warnings,
			// Stated because it would otherwise be assumed. In the free WP Rollback the
			// validate-package step is UpsellValidatePackage, which returns a marketing message
			// instead of validating; real package integrity scanning is a Pro feature.
			'validation_note'     => __( 'WP Rollback free does not verify package integrity — its validate step returns an upgrade prompt rather than a check. Treat a successful rollback as "files replaced", not "files verified"; plugins/verify-plugin-checksums can verify afterwards for a WordPress.org plugin.', 'acrossai-abilities-manager' ),
			'message'             => array() === $reasons
				? __( 'Eligible. Read reasons and warnings before proceeding.', 'acrossai-abilities-manager' )
				: __( 'Not eligible yet — see reasons.', 'acrossai-abilities-manager' ),
		);
	}
}
