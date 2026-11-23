<?php
/**
 * Feature 136 — moving an installed plugin or theme onto a chosen version.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Asset_Installer;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Lookup;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Versions;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Wp_Rollback_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * The write half of version targeting, shared by the plugin and theme abilities.
 *
 * These were deferred from the first pass of Feature 136 because the plan routed them through WP
 * Rollback's `/process-rollback/`, which executes **one step per HTTP call** — maintenance mode,
 * download, validate, backup, replace, cleanup — so a failure between two calls leaves a site in
 * maintenance mode with no process left to take it out. That hazard was real and the deferral was
 * right.
 *
 * It turned out to be avoidable rather than solvable. WordPress core has done the hard part since
 * 6.3: `WP_Upgrader::run()` moves the existing copy aside and restores it on `shutdown` if the
 * package fails to apply. {@see Asset_Installer} arms that and brackets maintenance mode, and the
 * whole move happens inside one `execute()`. So this drives core, not WP Rollback, and
 * `requires_host()` stays false.
 *
 * WP Rollback is still worth having and is still suggested, for the one thing core cannot do:
 * it keeps **local archives** of versions it has installed before, which is the only route to an
 * earlier version of an asset the directory does not host. That path is used when present and its
 * absence is explained when it is not.
 *
 * @since 0.0.45
 */
abstract class Base_Asset_Rollback extends Base_Version_Ability {

	/**
	 * The caller key naming the asset — `plugin` or `stylesheet`.
	 *
	 * @since  0.0.45
	 * @return string
	 */
	abstract protected function identifier_key(): string;

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
		return 'versions-rollback';
	}

	/**
	 * @since  0.0.45
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.45
	 * @return bool
	 */
	protected function writes_files(): bool {
		return true;
	}

	/**
	 * @since  0.0.45
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			// Idempotent in the sense that matters: asking for the same version twice leaves the
			// same files. The second call reports `changed: false` rather than failing.
			'destructive' => true,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		$type = $this->asset_type();

		return array(
			$this->identifier_key() => array(
				'type'        => 'string',
				'description' => 'theme' === $type
					? __( 'Theme directory name (stylesheet), e.g. "twentytwenty".', 'acrossai-abilities-manager' )
					: __( 'Plugin file, folder name, display name or partial match.', 'acrossai-abilities-manager' ),
			),
			'version'               => array(
				'type'        => 'string',
				'description' => __( 'The version to move to. May be older or newer than the installed one; versions/list-asset-versions returns the valid values.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.45
	 * @return array<int,string>
	 */
	protected function required_input(): array {
		return array( $this->identifier_key(), 'version' );
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
			'previous_version'  => array( 'type' => 'string' ),
			'version'           => array( 'type' => 'string' ),
			'relation'          => array( 'type' => 'string' ),
			'changed'           => array( 'type' => 'boolean' ),
			'package_source'    => array( 'type' => 'string' ),
			'was_active'        => array( 'type' => 'boolean' ),
			'reactivated'       => array( 'type' => 'boolean' ),
			'verify_hint'       => array( 'type' => 'string' ),
			'warnings'          => array( 'type' => 'array' ),
		);
	}

	/**
	 * The package to apply for a requested version, and where it came from.
	 *
	 * WordPress.org first, then WP Rollback's local archive. Pure apart from the two lookups, and
	 * the ordering matters: the directory's copy is canonical and verifiable afterwards with
	 * `plugins/verify-plugin-checksums`, whereas an archive is whatever this site happened to keep.
	 *
	 * @since  0.0.45
	 * @param  string $type    `plugin` or `theme`.
	 * @param  string $slug    Asset slug.
	 * @param  string $version Requested version.
	 * @param  string $name    Display name, for messages.
	 * @return array{package:string,source:string,releases:string[]}|\WP_Error
	 */
	protected function locate_package( string $type, string $slug, string $version, string $name ) {
		$published = Asset_Lookup::published_versions( $type, $slug );
		$releases  = is_wp_error( $published ) ? array() : Asset_Versions::releases( (array) $published );

		if ( ! is_wp_error( $published ) ) {
			$map = (array) $published;

			if ( isset( $map[ $version ] ) && '' !== (string) $map[ $version ] ) {
				return array(
					'package'  => (string) $map[ $version ],
					'source'   => 'wordpress.org',
					'releases' => $releases,
				);
			}
		}

		// Fall back to a local archive. Core's download_package() accepts a path that is not a URL,
		// so an archived zip is applied by exactly the same route.
		$archived = Wp_Rollback_Guard::archived_versions( $slug );

		if ( isset( $archived[ $version ] ) && '' !== (string) $archived[ $version ] ) {
			return array(
				'package'  => (string) $archived[ $version ],
				'source'   => 'local_archive',
				'releases' => $releases,
			);
		}

		if ( array() !== $releases ) {
			return new \WP_Error(
				'version_not_available',
				sprintf(
					/* translators: 1: version, 2: asset name, 3: newest few published versions */
					__( 'Version %1$s is not published for "%2$s" on WordPress.org and is not archived on this site. Published versions include: %3$s. Use versions/list-asset-versions for the full list.', 'acrossai-abilities-manager' ),
					$version,
					$name,
					implode( ', ', array_slice( $releases, 0, 5 ) )
				)
			);
		}

		return new \WP_Error(
			'version_not_available',
			sprintf(
				/* translators: 1: asset name, 2: version */
				__( '"%1$s" is not published on WordPress.org, and this site holds no local archive of version %2$s. WP Rollback keeps such archives for assets it has installed before; without one there is no package for a privately-hosted asset.', 'acrossai-abilities-manager' ),
				$name,
				$version
			)
		);
	}

	/**
	 * @since  0.0.45
	 * @param  array<string,mixed> $input Caller input.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function run( array $input ) {
		$type    = $this->asset_type();
		$version = trim( self::scalar( $input['version'] ?? '' ) );

		if ( '' === $version ) {
			return new \WP_Error(
				'missing_version',
				__( 'A target version is required. versions/list-asset-versions returns the valid values.', 'acrossai-abilities-manager' )
			);
		}

		$asset = Asset_Lookup::resolve( $type, self::scalar( $input[ $this->identifier_key() ] ?? '' ) );

		if ( is_wp_error( $asset ) ) {
			return $asset;
		}

		$installed = (string) $asset['installed_version'];
		$relation  = Asset_Versions::relation( $version, $installed );

		// Already there. Reported as success rather than refused, because the caller's intent —
		// "be on this version" — is satisfied. Pointing at the reinstall ability matters: a caller
		// wanting to *repair* files at the current version would otherwise retry this one and keep
		// being told nothing happened.
		if ( $version === $installed ) {
			return array(
				'type'             => $type,
				'slug'             => (string) $asset['slug'],
				'name'             => (string) $asset['name'],
				'previous_version' => $installed,
				'version'          => $installed,
				'relation'         => 'same',
				'changed'          => false,
				'message'          => sprintf(
					/* translators: 1: asset name, 2: version, 3: ability name */
					__( '"%1$s" is already on version %2$s — nothing was changed. To re-apply that version\'s files and repair modified ones, use %3$s.', 'acrossai-abilities-manager' ),
					(string) $asset['name'],
					$installed,
					'theme' === $type ? 'themes/reinstall-theme' : 'plugins/reinstall-plugin'
				),
			);
		}

		$refusal = $this->guard_asset( $asset, $input );

		if ( null !== $refusal ) {
			return $refusal;
		}

		$located = $this->locate_package( $type, (string) $asset['slug'], $version, (string) $asset['name'] );

		if ( is_wp_error( $located ) ) {
			return $located;
		}

		$state   = $this->before_install( $asset );
		$outcome = Asset_Installer::install( $type, (string) $asset['slug'], $located['package'] );

		if ( is_wp_error( $outcome ) ) {
			return $outcome;
		}

		if ( ! $outcome['ok'] ) {
			return new \WP_Error(
				'rollback_failed',
				sprintf(
					/* translators: 1: asset name, 2: version, 3: failure reason */
					__( 'Could not move "%1$s" to version %2$s: %3$s The previous copy is restored automatically.', 'acrossai-abilities-manager' ),
					(string) $asset['name'],
					$version,
					$outcome['message']
				)
			);
		}

		$result = array(
			'type'             => $type,
			'slug'             => (string) $asset['slug'],
			'name'             => (string) $asset['name'],
			'previous_version' => $installed,
			'version'          => $version,
			'relation'         => $relation,
			'changed'          => true,
			'package_source'   => (string) $located['source'],
			'warnings'         => $this->warnings( $asset, $relation, (string) $located['source'] ),
			// Named rather than implied. WP Rollback free does not verify package integrity, and
			// neither does core; "files replaced" is not "files verified".
			'verify_hint'      => 'theme' === $type
				? __( 'Files were replaced, not verified. WordPress.org publishes no theme checksum manifest, so there is no equivalent of verify-plugin-checksums for themes.', 'acrossai-abilities-manager' )
				: __( 'Files were replaced, not verified. Run plugins/verify-plugin-checksums to confirm they match what WordPress.org publishes for this version.', 'acrossai-abilities-manager' ),
		);

		return array_merge( $result, $this->after_install( $asset, $state, $version ) );
	}

	/* ------------------------------------------------------------------ */
	/* Per-type behaviour                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * A type-specific refusal before anything is touched, or null to proceed.
	 *
	 * @since  0.0.45
	 * @param  array<string,string> $asset Resolved asset.
	 * @param  array<string,mixed>  $input Caller input.
	 * @return array<string,mixed>|\WP_Error|null
	 */
	protected function guard_asset( array $asset, array $input ) {
		return null;
	}

	/**
	 * State to capture before the files change.
	 *
	 * @since  0.0.45
	 * @param  array<string,string> $asset Resolved asset.
	 * @return array<string,mixed>
	 */
	protected function before_install( array $asset ): array {
		return array();
	}

	/**
	 * Fields to merge into the response, and any state to restore.
	 *
	 * @since  0.0.45
	 * @param  array<string,string> $asset   Resolved asset.
	 * @param  array<string,mixed>  $state   Whatever before_install() captured.
	 * @param  string               $version Version now installed.
	 * @return array<string,mixed>
	 */
	protected function after_install( array $asset, array $state, string $version ): array {
		return array();
	}

	/**
	 * Consequences worth stating after a successful move.
	 *
	 * @since  0.0.45
	 * @param  array<string,string> $asset    Resolved asset.
	 * @param  string               $relation older, newer, same or unknown.
	 * @param  string               $source   Where the package came from.
	 * @return string[]
	 */
	protected function warnings( array $asset, string $relation, string $source ): array {
		$warnings = array();

		if ( 'older' === $relation ) {
			$warnings[] = __( 'This is a downgrade. WordPress will offer the newer version again on the next update check, and any automatic updates setting will take it back — pin it or disable updates for this asset if the older version must stick.', 'acrossai-abilities-manager' );
		}

		if ( 'unknown' === $relation ) {
			$warnings[] = __( 'The two version strings could not be compared, so whether this was a downgrade or an upgrade is not established.', 'acrossai-abilities-manager' );
		}

		if ( 'local_archive' === $source ) {
			$warnings[] = __( 'Applied from a local archive kept by WP Rollback rather than from WordPress.org, so the package is whatever this site stored previously and cannot be checked against the directory.', 'acrossai-abilities-manager' );
		}

		if ( true === Wp_Rollback_Guard::used_on_other_sites( $this->asset_type(), (string) $asset['slug'] ) ) {
			$warnings[] = __( 'This is in use by other sites on this network, and they are all now on this version.', 'acrossai-abilities-manager' );
		}

		return $warnings;
	}
}
