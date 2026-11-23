<?php
/**
 * Feature 109 — Get Calendar Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventsCalendar
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventsCalendar;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventsCalendar\Event_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * events/get-calendar-settings — Get Calendar Settings.
 */
final class Get_Calendar_Settings extends Base_Events_Calendar_Ability {

	protected function slug(): string {
		return 'events/get-calendar-settings';
	}

	protected function ability_label(): string {
		return __( 'Get Calendar Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The calendar\'s stored settings, as rows. Credentials are redacted: the settings blob is shared by the calendar, the ticketing plugin and every add-on, and it holds map API keys and social access tokens alongside ordinary preferences. Redacted keys are returned with a null value and a redacted flag so a caller can see that a setting exists without receiving its value. Licence keys live in separate options and are never read here.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'calendar';
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array(  );
	}

	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'array' ),
			'count' => array( 'type' => 'integer' ),
			'redacted' => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$rows     = Event_Repository::settings();
		$redacted = 0;

		foreach ( $rows as $row ) {
			if ( ! empty( $row['redacted'] ) ) {
				++$redacted;
			}
		}

		return array(
			'settings' => $rows,
			'count'    => count( $rows ),
			'redacted' => $redacted,
			'message'  => sprintf(
				/* translators: 1: number of settings, 2: number redacted */
				__( '%1$d settings, %2$d of them redacted as credentials.', 'acrossai-abilities-manager' ),
				count( $rows ),
				$redacted
			),
		);
	}
}
