<?php
/**
 * Feature 136 — hold WordPress core on a chosen version.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpDowngrade\Wp_Downgrade_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpRollback\Asset_Versions;

defined( 'ABSPATH' ) || exit;

/**
 * Writes WP Downgrade's target-version option.
 *
 * **This does not move core.** WP Downgrade filters `site_transient_update_core` so WordPress
 * believes the pinned version is the one on offer; the move happens on the next core update run.
 * Every response says so and names `core/update-wp-core`, because an ability that wrote the option
 * and reported "downgraded" would be stating something untrue.
 *
 * Confirm-gated. Pinning an *older* version means the next core update — including an automatic one
 * — moves the site backwards, which is a consequence worth making deliberate even though the write
 * itself is trivially reversible.
 *
 * @since 0.0.45
 */
class Set_Core_Pin extends Base_Version_Ability {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'set-core-pin';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Set Core Version Pin', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Pin WordPress core to a specific version through WP Downgrade, so update checks keep offering that version instead of the latest. IMPORTANT: this does not move core — it only repoints the update offer\'s package at the pinned release. Run core/update-wp-core with confirm: true afterwards to actually apply it, and note that core/reinstall-wp-core cannot run while a pin is set. The target package must exist on WordPress.org, and pinning the version already running is refused because WP Downgrade ignores such a pin. Refused outright while a caller-supplied download URL is enabled, since the pin would then resolve through an archive this plugin did not choose. Requires confirm: true.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function tab_group(): string {
		return 'wp-downgrade';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function sub_group(): string {
		return 'versions-core-pin';
	}

	/**
	 * @since  0.0.45
	 * @return bool
	 */
	protected function requires_host(): bool {
		return true;
	}

	/**
	 * @since  0.0.45
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * This suite's host is WP Downgrade, not WP Rollback.
	 *
	 * @since  0.0.45
	 * @return bool
	 */
	protected function host_is_active(): bool {
		return Wp_Downgrade_Guard::is_active();
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function host_not_active(): array {
		return Wp_Downgrade_Guard::not_active();
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function host_active_key(): string {
		return 'wp_downgrade_active';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function confirmation_message(): string {
		// Accurate for this ability: nothing is written to disk and the pin is reversible with
		// clear-core-pin. What makes it worth confirming is that a pin older than the running
		// version turns the next core update -- including an automatic one -- into a downgrade.
		return __( 'This changes which WordPress version update checks offer, so a later core update may move this site to a different version than it would have. It writes one option and versions/clear-core-pin undoes it; core itself is not touched now. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array(
			'version' => array(
				'type'        => 'string',
				'minLength'   => 1,
				'description' => __( 'The WordPress core version to hold, for example 7.1.3. Must be a release WordPress.org publishes a package for.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.45
	 * @return array<int,string>
	 */
	protected function required_input(): array {
		return array( 'version' );
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'wp_downgrade_active' => array( 'type' => 'boolean' ),
			'pinned_version'      => array( 'type' => 'string' ),
			'previous_pin'        => array( 'type' => 'string' ),
			'running_version'     => array( 'type' => 'string' ),
			'relation'            => array( 'type' => 'string' ),
			'package_url'         => array( 'type' => 'string' ),
			'core_moved'          => array( 'type' => 'boolean' ),
			'next_step'           => array( 'type' => 'string' ),
			'warnings'            => array( 'type' => 'array' ),
		);
	}

	/**
	 * @since  0.0.45
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		// Not destructive: nothing is deleted or overwritten on disk, and clear-core-pin undoes it
		// exactly. Idempotent: writing the same pin twice leaves the same state.
		return array(
			'readonly'    => false,
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
		$version = trim( self::scalar( $input['version'] ?? '' ) );
		$running = (string) get_bloginfo( 'version' );

		if ( '' === $version ) {
			return new \WP_Error(
				'missing_version',
				__( 'A version is required. To remove a pin instead, use versions/clear-core-pin.', 'acrossai-abilities-manager' )
			);
		}

		// Refused rather than worked around: while the custom URL is on, wpdg_get_url() ignores the
		// version entirely, so a pin set here would resolve through an archive this plugin did not
		// choose. Writing that option is deliberately outside this suite's scope.
		if ( Wp_Downgrade_Guard::custom_url_enabled() ) {
			return new \WP_Error(
				'custom_download_url_enabled',
				sprintf(
					/* translators: %s: the configured URL */
					__( 'WP Downgrade is configured to use a caller-supplied download URL (%s). While that is enabled it ignores the pinned version, so setting a pin here would not control what gets installed. Clear that URL in Settings then WP Downgrade first. These abilities never write it.', 'acrossai-abilities-manager' ),
					Wp_Downgrade_Guard::custom_url()
				)
			);
		}

		// Shape check before the network call, so an obvious typo fails fast and locally.
		if ( 1 !== preg_match( '/^\d+(\.\d+){1,2}$/', $version ) ) {
			return new \WP_Error(
				'invalid_version',
				sprintf(
					/* translators: %s: caller value */
					__( '"%s" is not a WordPress version number. Expected something like 7.1 or 7.1.3.', 'acrossai-abilities-manager' ),
					$version
				)
			);
		}

		// WP Downgrade's filter returns early when the pin equals the running version, so such a
		// pin is inert. Refusing is kinder than storing something that does nothing.
		if ( 0 === version_compare( $running, $version ) ) {
			return new \WP_Error(
				'pin_would_be_inert',
				sprintf(
					/* translators: %s: version */
					__( 'WordPress %s is already running, and WP Downgrade ignores a pin that matches the running version. Pin a different version, or use versions/clear-core-pin to remove an existing pin.', 'acrossai-abilities-manager' ),
					$version
				)
			);
		}

		$url = Wp_Downgrade_Guard::package_url( $version );

		if ( ! Wp_Downgrade_Guard::package_exists( $url ) ) {
			return new \WP_Error(
				'package_not_found',
				sprintf(
					/* translators: 1: version, 2: URL */
					__( 'WordPress.org has no downloadable package for version %1$s (checked %2$s). Pinning it would make the next core update fail.', 'acrossai-abilities-manager' ),
					$version,
					$url
				)
			);
		}

		$previous = Wp_Downgrade_Guard::pinned_version();

		update_option( Wp_Downgrade_Guard::PIN_OPTION, $version );

		// Read back rather than trusting the write: update_option() returns false both when the
		// write fails and when the value was already identical, so its return value cannot tell
		// success from no-op.
		$stored = Wp_Downgrade_Guard::pinned_version();

		if ( $stored !== $version ) {
			return new \WP_Error(
				'pin_not_stored',
				__( 'The pin did not persist. Something is filtering or blocking the option write.', 'acrossai-abilities-manager' )
			);
		}

		$warnings = array();

		// Stated because it is surprising and was found by testing rather than by reading. WP
		// Downgrade's filter rewrites the offer's `current` to the pinned version, and
		// find_core_update() matches on `current` — so while a pin is set, reinstalling the
		// running version cannot resolve an offer at all. core/reinstall-wp-core now says so
		// rather than blaming a stale transient, but a caller setting a pin should know up front.
		$warnings[] = __( 'While this pin is set, core/reinstall-wp-core will refuse: WP Downgrade rewrites the update offer to the pinned release, so there is no offer for the running version to reinstall. Clear the pin first if you need to reinstall.', 'acrossai-abilities-manager' );
		$relation = Asset_Versions::relation( $version, $running );

		if ( 'older' === $relation ) {
			$warnings[] = __( 'This pin is older than the version running, so the next core update — including an automatic one — will move this site backwards. Take a backup before applying it.', 'acrossai-abilities-manager' );
		}

		if ( ! Wp_Downgrade_Guard::host_ui_accepts( $version ) ) {
			$warnings[] = sprintf(
				/* translators: %s: version */
				__( 'Stored, and the pin works, but WP Downgrade own settings form would refuse to re-save "%s" — its validation pattern allows only a single digit in the middle segment. Editing that screen by hand will require changing the value.', 'acrossai-abilities-manager' ),
				$version
			);
		}

		return array(
			'pinned_version'      => $stored,
			'previous_pin'        => $previous,
			'running_version'     => $running,
			'relation'            => $relation,
			'package_url'         => $url,
			// The single most important field here. Stated explicitly so no caller can read a
			// success as "core is now on that version".
			'core_moved'          => false,
			'next_step'           => __( 'Core has NOT changed. Run core/update-wp-core with confirm: true to apply the pinned version — it is a downgrade, so it asks for confirmation.', 'acrossai-abilities-manager' ),
			'warnings'            => $warnings,
			'message'             => sprintf(
				/* translators: 1: pinned version, 2: running version */
				__( 'Core is now pinned to %1$s. WordPress %2$s is still running — the pin only changes which package the update offer points at, not the version or response it reports, so nothing has moved yet. Run core/update-wp-core with confirm: true to apply it.', 'acrossai-abilities-manager' ),
				$stored,
				$running
			),
		);
	}
}
