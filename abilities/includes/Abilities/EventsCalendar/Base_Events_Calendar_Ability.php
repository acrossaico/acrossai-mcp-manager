<?php
/**
 * Feature 109 — the sole ability assembler for The Events Calendar suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\EventsCalendar
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\EventsCalendar;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\EventsCalendar\Events_Calendar_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability in this suite runs through here.
 *
 * The base owns the category, the tab group, the capability floor, the guard order and the
 * envelope. What it deliberately does NOT own is the write path: every mutation goes through
 * Event_Repository, which is the only place that touches The Events Calendar's ORM. An event's
 * timing lives in four stores at once and only the ORM keeps them in step.
 *
 * No `Slash_Input`. Free-text fields (title, description, venue address) are handed to the ORM,
 * which runs them through the plugin's own sanitisation on the way to wp_insert_post(); adding a
 * slashing flag on top would advertise a control this suite does not apply.
 */
abstract class Base_Events_Calendar_Ability extends Ability_Definition {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-events-calendar';

	/**
	 * Must equal Integrations\Events_Calendar::TAB_GROUP, or the abilities land in one group and the
	 * dispatcher serves another.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'events-calendar';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	abstract protected function required_input(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	abstract protected function annotations(): array;

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * The floor, and not overridable.
	 *
	 * Classic Editor gates its own network save on `manage_network_options` and its per-user save on
	 * `edit_user`, which lets an Editor change another user's editor from wp-admin. This suite is
	 * stricter on purpose: an ability is reachable by an AI client and wp-admin is not.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * Whether THIS call needs confirmation, as opposed to whether the ability ever does.
	 *
	 * `requires_confirmation()` governs the input schema — it is what puts `confirm` in the
	 * properties, and without it `additionalProperties: false` would reject the key. This governs
	 * the runtime gate. They are separate because an ability can have one input that warrants a
	 * confirmation and another that does not, and gating the harmless one is friction with no risk
	 * behind it.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		unset( $input );

		return $this->requires_confirmation();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'events'      => __( 'Events', 'acrossai-abilities-manager' ),
			'venues'      => __( 'Venues', 'acrossai-abilities-manager' ),
			'organizers'  => __( 'Organizers', 'acrossai-abilities-manager' ),
			'categories'  => __( 'Event Categories', 'acrossai-abilities-manager' ),
			'calendar'    => __( 'Calendar Settings', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Done.', 'acrossai-abilities-manager' );
	}

	/**
	 * Assemble the ability definition.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function ability(): array {
		$sub_group = $this->sub_group();
		$acrossai  = array(
			'tab_group' => self::TAB_GROUP,
			'sub_group' => $sub_group,
		);

		$label = $this->sub_group_labels()[ $sub_group ] ?? '';

		if ( '' !== $label ) {
			$acrossai['sub_group_label'] = $label;
		}

		$properties = $this->input_properties();
		$required   = $this->required_input();

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this operation.', 'acrossai-abilities-manager' ),
			);

			// Never schema-required: core validates input_schema before execute() runs, so a
			// required confirm yields a generic ability_invalid_input and the gate never fires.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Events_Calendar_Guard::can( $this->permission_floor() ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'success' => array( 'type' => 'boolean' ) ),
						$this->output_properties(),
						array(
							'message'    => array( 'type' => 'string' ),
							'error_code' => array( 'type' => 'string' ),
						)
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => $acrossai,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => $this->annotations(),
				),
			),
		);
	}

	/**
	 * Guards, then the ability, then the envelope.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Events_Calendar_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return Events_Calendar_Guard::fail( $available );
		}

		if ( $this->needs_confirmation_for( $input ) ) {
			$confirmed = Events_Calendar_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return Events_Calendar_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return Events_Calendar_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Events_Calendar_Guard::ok( $result, $message );
	}
}
