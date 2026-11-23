<?php
/**
 * Feature 136 — stop holding WordPress core on a chosen version.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WpDowngrade\Wp_Downgrade_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Empties WP Downgrade's target-version option, restoring normal update behaviour.
 *
 * Emptying rather than deleting, because that is what the plugin documents as "off" and what its own
 * settings form produces when the field is cleared. Its filter tests `$dg_version == ''` and returns
 * the offers untouched, so an empty string is the state it is built to read.
 *
 * No confirmation: this only restores WordPress's default behaviour, and refusing to let a caller
 * undo a pin without ceremony would be the wrong way round — the pin is the committing act.
 *
 * @since 0.0.45
 */
class Clear_Core_Pin extends Base_Version_Ability {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'clear-core-pin';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Clear Core Version Pin', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Remove WP Downgrade core version pin, so WordPress offers the latest release again. Does not change the version currently installed — if core was already moved onto an older version, it stays there and WordPress will now offer an upgrade. Idempotent: clearing when nothing is pinned reports success and changes nothing.', 'acrossai-abilities-manager' );
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
		return array(
			// Accepted and ignored, on purpose. This ability needs no confirmation — it restores
			// WordPress's default update behaviour — but `additionalProperties` is false, so a
			// caller that learned `confirm: true` from versions/set-core-pin had its clear call
			// rejected outright as ability_invalid_input. That is the escape hatch refusing the
			// flag the caller just learned, while the pin it would have removed is actively
			// blocking core/reinstall-wp-core. Found by hitting it mid-test and being left pinned.
			'confirm' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Not required — clearing a pin restores WordPress\'s normal update behaviour. Accepted so a caller that passes it by habit is not refused.', 'acrossai-abilities-manager' ),
			),
		);
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
			'previous_pin'        => array( 'type' => 'string' ),
			'was_pinned'          => array( 'type' => 'boolean' ),
			'running_version'     => array( 'type' => 'string' ),
			'core_moved'          => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @since  0.0.45
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.45
	 * @param  array<string,mixed> $input Unused.
	 * @return array<string,mixed>|\WP_Error
	 */
	protected function run( array $input ) {
		$previous = Wp_Downgrade_Guard::pinned_version();
		$running  = (string) get_bloginfo( 'version' );

		if ( '' === $previous ) {
			return array(
					'previous_pin'        => '',
				'was_pinned'          => false,
				'running_version'     => $running,
				'core_moved'          => false,
				'message'             => __( 'No core version was pinned; nothing to clear.', 'acrossai-abilities-manager' ),
			);
		}

		update_option( Wp_Downgrade_Guard::PIN_OPTION, '' );

		// Read back: update_option() returns false both on failure and when the value was already
		// identical, so it cannot distinguish the two.
		if ( '' !== Wp_Downgrade_Guard::pinned_version() ) {
			return new \WP_Error(
				'pin_not_cleared',
				__( 'The pin is still set after the write. Something is filtering or blocking the option.', 'acrossai-abilities-manager' )
			);
		}

		return array(
			'previous_pin'        => $previous,
			'was_pinned'          => true,
			'running_version'     => $running,
			'core_moved'          => false,
			'message'             => sprintf(
				/* translators: 1: previous pin, 2: running version */
				__( 'Cleared the pin on %1$s. WordPress %2$s is still installed and update checks now offer the latest release again.', 'acrossai-abilities-manager' ),
				$previous,
				$running
			),
		);
	}
}
