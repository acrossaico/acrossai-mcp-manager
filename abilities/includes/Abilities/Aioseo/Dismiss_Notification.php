<?php
/**
 * Feature 132 — dismiss an AIOSEO notification.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Status_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability — aioseo/dismiss-notification.
 *
 * The missing half of AIOSEO's own `aioseo-notifications/list`: it can report an alert but not clear
 * one, so a notification whose cause has been fixed keeps appearing in every listing and keeps
 * reading as outstanding work.
 *
 * Reversible in the sense that matters — AIOSEO re-raises a notification if the condition behind it
 * recurs — so no confirm gate. Dismissing is not fixing, and the message says so.
 *
 * @since 0.0.44
 */
class Dismiss_Notification extends Base_Aioseo_Ability {

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function slug(): string {
		return 'dismiss-notification';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Dismiss All in One SEO Notification', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Dismiss one All in One SEO notification by name, so a resolved alert stops appearing in every listing. AIOSEO can list its notifications with aioseo-notifications/list but cannot clear one. Dismissing hides the alert, it does not fix what caused it, and AIOSEO will raise it again if the condition recurs. A notification that was already dismissed is reported as such rather than treated as an error.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function sub_group(): string {
		return 'aioseo-status';
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function aioseo_cap(): string {
		return 'aioseo_general_settings';
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'name' => array(
				'type'        => 'string',
				'description' => __( 'Notification name, as reported by aioseo-notifications/list.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'notification'  => array( 'type' => 'string' ),
			'dismissed'     => array( 'type' => 'boolean' ),
			'was_dismissed' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'name' );
	}

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Status_Repository::dismiss_notification( self::scalar( $input['name'] ?? '' ) );
	}
}
