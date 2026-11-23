<?php
/**
 * Feature 130 — abstract base for every Redirection ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Redirection
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Redirection;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Redirection\Redirection_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the ability spec and enforces the execute() ordering for every
 * Redirection ability.
 *
 * This class is the SOLE assembler of ability(), so every ability carries the
 * right category and meta.acrossai.tab_group, and the SOLE enforcer of the
 * guard order so it is a structural property rather than something each code
 * review must catch:
 *
 *   1 availability   Redirection loaded
 *   2 confirmation   confirm:true for destructive operations
 *   3 run()          per-field validation and domain work
 *   4 envelope       ok() / fail()
 *
 * Subclasses implement run() and the metadata accessors. They MUST NOT override
 * ability() or execute(), and MUST NOT name a \Red_* symbol — all third-party
 * access belongs in Includes\Abilities\Utilities\Redirection.
 */
abstract class Base_Redirection_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 */
	protected const CATEGORY = 'acrossai-redirects';

	/**
	 * Admin Integrations tab. The visible "Redirection" label is derived from
	 * this by the React layer. Must equal Integrations\Redirection::TAB_GROUP.
	 */
	protected const TAB_GROUP = 'redirection';

	/**
	 * Slug suffix, appended to 'redirects/'.
	 *
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * Translated ability label.
	 *
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * Translated ability description.
	 *
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * Library sub-group slug, e.g. 'redirects-manage'.
	 *
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * JSON-Schema properties for the ability input, excluding 'confirm'.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * JSON-Schema properties for the ability output payload, excluding the
	 * envelope keys success / message / error_code.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * Required input property names.
	 *
	 * @return string[]
	 */
	abstract protected function required_input(): array;

	/**
	 * Annotation triple: readonly, destructive, idempotent.
	 *
	 * @return array{readonly:bool,destructive:bool,idempotent:bool}
	 */
	abstract protected function annotations(): array;

	/**
	 * Domain work. Returns the payload WITHOUT the 'success' key, or a WP_Error.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * WordPress capability floor. Redirection's own default is manage_options.
	 *
	 * @return string
	 */
	protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * Whether this ability requires confirm:true. Must be true for every ability
	 * whose annotations declare destructive:true.
	 *
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * Optional per-ability sub-group label override.
	 *
	 * @return string
	 */
	protected function sub_group_label(): string {
		return '';
	}

	/**
	 * Display label for every Redirection sub-group, in one place.
	 *
	 * @return array<string,string> Sub-group slug => label.
	 */
	protected function sub_group_labels(): array {
		return array(
			'redirects-manage'   => __( 'Redirects', 'acrossai-abilities-manager' ),
			'redirects-logs'     => __( '404s & Hit Log', 'acrossai-abilities-manager' ),
			'redirects-settings' => __( 'Groups & Settings', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Success message. Subclasses may override; a payload 'message' wins.
	 *
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Operation completed.', 'acrossai-abilities-manager' );
	}

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		$sub_group = $this->sub_group();
		$acrossai  = array(
			'tab_group' => self::TAB_GROUP,
			'sub_group' => $sub_group,
		);
		$label = $this->sub_group_label();
		if ( '' === $label ) {
			$label = $this->sub_group_labels()[ $sub_group ] ?? '';
		}
		if ( '' !== $label ) {
			$acrossai['sub_group_label'] = $label;
		}

		$properties = $this->input_properties();
		$required   = $this->required_input();

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this irreversible operation.', 'acrossai-abilities-manager' ),
			);

			// 'confirm' must NOT be schema-required: WP core validates input_schema
			// before execute() runs, so a required confirm makes an unconfirmed call
			// fail with a generic ability_invalid_input and assert_confirmed() never
			// fires, hiding the confirmation_required message that names the flag.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => 'redirects/' . $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Redirection_Guard::can( $this->permission_floor() ),
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
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => $this->annotations(),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * The guard order below is mandatory and is the reason subclasses must not
	 * override this method.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Redirection_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return Redirection_Guard::fail( $available );
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = Redirection_Guard::assert_confirmed( $input );
			if ( is_wp_error( $confirmed ) ) {
				return Redirection_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );
		if ( is_wp_error( $result ) ) {
			return Redirection_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Redirection_Guard::ok( $result, $message );
	}
}
