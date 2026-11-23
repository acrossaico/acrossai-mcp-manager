<?php
/**
 * Feature 136 — what core version this site is pinned to, if any.
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
 * Reports the core version pin, and whether a custom package URL is overriding it.
 *
 * Works with WP Downgrade absent — then there is no pin by definition, and saying so is the useful
 * answer.
 *
 * @since 0.0.45
 */
class Get_Core_Pin extends Base_Version_Ability {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'get-core-pin';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Core Version Pin', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report whether WordPress core is pinned to a specific version through WP Downgrade: the pinned version, how it compares to the version running, the package URL WordPress would be pointed at, and whether a caller-supplied download URL is overriding the version entirely. A pin only changes what update checks offer; it does not move core on its own. Read-only.', 'acrossai-abilities-manager' );
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
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.45
	 * @return array<int,string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array(
			'wp_downgrade_active' => array( 'type' => 'boolean' ),
			'is_pinned'           => array( 'type' => 'boolean' ),
			'pinned_version'      => array( 'type' => 'string' ),
			'running_version'     => array( 'type' => 'string' ),
			'relation'            => array( 'type' => 'string' ),
			'pin_is_inert'        => array( 'type' => 'boolean' ),
			'package_url'         => array( 'type' => 'string' ),
			'custom_url_enabled'  => array( 'type' => 'boolean' ),
			'custom_url'          => array( 'type' => 'string' ),
			'warnings'            => array( 'type' => 'array' ),
			'next_step'           => array( 'type' => 'string' ),
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
	 * @param  array<string,mixed> $input Unused.
	 * @return array<string,mixed>
	 */
	protected function run( array $input ) {
		$active   = Wp_Downgrade_Guard::is_active();
		$pinned   = Wp_Downgrade_Guard::pinned_version();
		$running  = (string) get_bloginfo( 'version' );
		$custom   = Wp_Downgrade_Guard::custom_url_enabled();
		$warnings = array();

		if ( $custom ) {
			$warnings[] = __( 'A caller-supplied download URL is enabled in WP Downgrade. While it is on, the plugin ignores the pinned version and uses that URL, so the version reported here is not what WordPress would install. These abilities never set that URL; clear it in Settings then WP Downgrade if it was not intended.', 'acrossai-abilities-manager' );
		}

		if ( '' !== $pinned && ! Wp_Downgrade_Guard::host_ui_accepts( $pinned ) ) {
			$warnings[] = sprintf(
				/* translators: %s: pinned version */
				__( 'The pinned value "%s" is one WP Downgrade own settings form would refuse to re-save, because its validation pattern allows only a single digit in the middle segment. The pin still works, but that settings screen cannot be saved again without changing the value.', 'acrossai-abilities-manager' ),
				$pinned
			);
		}

		// Their filter returns early when the pin equals the running version, so such a pin changes
		// nothing. Worth stating rather than leaving a caller to wonder why nothing happened.
		$inert = '' !== $pinned && 0 === version_compare( $running, $pinned );

		if ( $inert ) {
			$warnings[] = __( 'The pin matches the version already running, so it has no effect until the running version changes.', 'acrossai-abilities-manager' );
		}

		return array(
			'is_pinned'           => '' !== $pinned,
			'pinned_version'      => $pinned,
			'running_version'     => $running,
			'relation'            => '' !== $pinned ? Asset_Versions::relation( $pinned, $running ) : 'unknown',
			'pin_is_inert'        => $inert,
			'package_url'         => '' !== $pinned ? Wp_Downgrade_Guard::package_url( $pinned ) : '',
			'custom_url_enabled'  => $custom,
			'custom_url'          => $custom ? Wp_Downgrade_Guard::custom_url() : '',
			'warnings'            => $warnings,
			'next_step'           => '' !== $pinned
				? __( 'A pin only changes what update checks offer. Run core/update-wp-core to actually move core onto the pinned version.', 'acrossai-abilities-manager' )
				: '',
			'message'             => $this->summary( $active, $pinned, $running ),
		);
	}

	/**
	 * One sentence a caller can act on.
	 *
	 * @since  0.0.45
	 * @param  bool   $active  Host plugin loaded.
	 * @param  string $pinned  Pinned version.
	 * @param  string $running Running version.
	 * @return string
	 */
	private function summary( bool $active, string $pinned, string $running ): string {
		if ( ! $active ) {
			return __( 'WP Downgrade is not active, so no core version is pinned. core/rollback-wp-core can still move core down once; a pin is what makes it stick.', 'acrossai-abilities-manager' );
		}

		if ( '' === $pinned ) {
			return sprintf(
				/* translators: %s: running version */
				__( 'No core version is pinned. WordPress %s is running and update checks behave normally.', 'acrossai-abilities-manager' ),
				$running
			);
		}

		return sprintf(
			/* translators: 1: pinned version, 2: running version */
			__( 'Core is pinned to %1$s; %2$s is running. Update checks will offer %1$s until the pin is cleared.', 'acrossai-abilities-manager' ),
			$pinned,
			$running
		);
	}
}
