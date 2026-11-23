<?php
/**
 * Feature 127 — the sole ability assembler for the All-in-One WP Migration suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\AllInOne
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\AllInOne;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\AllInOne\All_In_One_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability in this suite runs through here.
 *
 * The base owns what must not vary: the category, the tab group, the capability floor, the guard
 * order and the envelope.
 *
 * One suite, one plugin. An earlier version put UpdraftPlus and All-in-One WP Migration behind a
 * shared provider interface and a single "backups" tab. That made two genuinely different plugins
 * look interchangeable: All-in-One labels archives and needs a paid extension to restore, UpdraftPlus
 * schedules backups and restores them. Every real difference had to be reported as a capability
 * flag the caller then had to check. Separate suites let each one simply offer what its plugin can
 * do, which is also how every other integration here is arranged.
 *
 * The floor is `manage_options` and final. A backup is the recovery path for the whole site:
 * reading one reveals the shape of the install, and acting on one can replace or destroy it.
 *
 * `Slash_Input` is deliberately ABSENT. Nothing here writes post content.
 */
abstract class Base_All_In_One_Ability extends Ability_Definition {

	/**
	 * @since 0.0.35
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-all-in-one';

	/**
	 * @since 0.0.35
	 * @var   string
	 */
	protected const TAB_GROUP = 'all-in-one-wp-migration';

	/**
	 * @since  0.0.35
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * @since  0.0.35
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * @since  0.0.35
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * @since  0.0.35
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	abstract protected function required_input(): array;

	/**
	 * @since  0.0.35
	 * @return array<string, bool>
	 */
	abstract protected function annotations(): array;

	/**
	 * @since  0.0.35
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * Administrator, and not overridable by a subclass.
	 *
	 * @since  0.0.35
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * @since  0.0.35
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * @since  0.0.35
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		return $this->requires_confirmation();
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'state'     => __( 'Backup state', 'acrossai-abilities-manager' ),
			'inventory' => __( 'Backup sets', 'acrossai-abilities-manager' ),
			'security'  => __( 'Backup security', 'acrossai-abilities-manager' ),
			'operate'   => __( 'Taking backups', 'acrossai-abilities-manager' ),
			'recovery'  => __( 'Restoring', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Done.', 'acrossai-abilities-manager' );
	}

	/**
	 * Assemble the ability definition.
	 *
	 * @since  0.0.35
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
				'permission_callback' => All_In_One_Guard::can( $this->permission_floor() ),
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
	 * The account gate sits BEFORE the confirmation gate on purpose: being asked to confirm an
	 * operation that cannot run either way wastes a round trip and reads as though confirming would
	 * help.
	 *
	 * @since  0.0.35
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = All_In_One_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return All_In_One_Guard::fail( $available );
		}

		if ( $this->needs_confirmation_for( $input ) ) {
			$confirmed = All_In_One_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return All_In_One_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return All_In_One_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return All_In_One_Guard::ok( $result, $message );
	}
}
