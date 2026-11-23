<?php
/**
 * Feature 105 — shared assembler for every ACF ability.
 *
 * Sole assembler of ability() and sole enforcer of the guard order:
 *
 *   1 availability   ACF loaded
 *   2 edition        ACF Pro, for the abilities that need it
 *   3 confirmation   confirm:true for destructive operations
 *   4 run()          per-field validation and domain work
 *   5 envelope       ok() / fail()
 *
 * Subclasses implement run() and the metadata accessors. They MUST NOT override ability() or
 * execute(), and MUST NOT name an ACF symbol — all host access belongs in Utilities\Acf.
 *
 * **This is the first suite to join an existing toolset rather than create one.** `TAB_GROUP` is
 * `acf`, matching `Integrations\ACF::TAB_GROUP`, so these land on the same tab and in the same MCP
 * dispatcher as ACF's own abilities. There is no new Integrations file and no built_in() change.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Acf_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the ACF suite.
 */
abstract class Base_Acf_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-acf';

	/**
	 * Toolset key. Must equal Integrations\ACF::TAB_GROUP — these join that toolset.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'acf';

	/**
	 * Ability slug INCLUDING its namespace, e.g. `custom-fields/get-acf-field`.
	 *
	 * Unlike the Contact Form 7 and LiteSpeed suites, this one spans two slug namespaces —
	 * `custom-fields/` for field data and `blocks/` for block operations — because a slug names the
	 * resource acted on, while the toolset names where an operator finds it
	 * (DEC-TOOLSET-SLUG-NAMESPACE). So subclasses give the whole slug rather than a suffix.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * Human-readable label.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * Description an AI client reads when choosing this ability.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * Sub-group (card) this ability belongs to.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * Input schema properties, excluding `confirm` and the shared fragments.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * Output payload properties, excluding success/message/error_code.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * Required input keys.
	 *
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	abstract protected function required_input(): array;

	/**
	 * The full annotation triple.
	 *
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	abstract protected function annotations(): array;

	/**
	 * Do the work. Return the payload without `success`, or a WP_Error.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * Capability floor for the whole suite.
	 *
	 * DELIBERATELY final, for the same reason as the Contact Form 7 and LiteSpeed suites: it stops a
	 * future subclass quietly lowering the floor. Per-target capability checks — `edit_post` on the
	 * post whose field is being written — are a deliberate deferral, noted in the brief.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * Whether this ability needs ACF Pro rather than the free edition.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_pro(): bool {
		return false;
	}

	/**
	 * Field types this ability needs registered, checked before run().
	 *
	 * This — not `function_exists()` — is the gate for repeater and flexible-content abilities.
	 * `add_row()` and friends ship in BOTH editions, so a function check passes on free ACF where the
	 * field type does not exist and no such field can be created.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	protected function required_field_types(): array {
		return array();
	}

	/**
	 * Whether this ability requires `confirm: true`.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * Operation-specific confirmation message. '' uses the generic one.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return '';
	}

	/**
	 * Whether this ability writes and therefore carries the apply_wp_slash opt-out.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_writer(): bool {
		return false;
	}

	/**
	 * Whether this ability addresses a target and therefore takes the shared target pair.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function has_target(): bool {
		return false;
	}

	/**
	 * Display label for every sub-group, in one place.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'acf-fields' => __( 'Field Values', 'acrossai-abilities-manager' ),
			'acf-rows'   => __( 'Repeater & Flexible Content', 'acrossai-abilities-manager' ),
			'acf-blocks' => __( 'ACF Blocks', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Default success message.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Operation completed.', 'acrossai-abilities-manager' );
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

		if ( $this->has_target() ) {
			$properties = array_merge( \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Acf_Target::schema_fragment(), $properties );
		}

		if ( $this->is_writer() ) {
			$properties = array_merge( $properties, \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input::schema_fragment() );
		}

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this irreversible operation.', 'acrossai-abilities-manager' ),
			);

			// 'confirm' must NOT be schema-required: core validates input_schema before execute()
			// runs, so a required confirm yields a generic ability_invalid_input and the gate never
			// fires. Stripped defensively so no subclass can reintroduce the bug.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		$meta = array(
			'acrossai'     => $acrossai,
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => false,
				'type'   => 'tool',
			),
			'annotations'  => $this->annotations(),
		);

		if ( $this->is_writer() ) {
			$meta['input_flags'] = \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input::meta_flags();
		}

		return array(
			'name' => $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Acf_Guard::can( $this->permission_floor() ),
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
				'meta'                => $meta,
			),
		);
	}

	/**
	 * Run the guards, then the ability, then wrap the result.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Acf_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return Acf_Guard::fail( $available );
		}

		if ( $this->requires_pro() ) {
			$pro = Acf_Guard::assert_pro( $this->ability_label() );

			if ( is_wp_error( $pro ) ) {
				return Acf_Guard::fail( $pro );
			}
		}

		foreach ( $this->required_field_types() as $type ) {
			$has = Acf_Guard::assert_field_type( $type );

			if ( is_wp_error( $has ) ) {
				return Acf_Guard::fail( $has );
			}
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = Acf_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return Acf_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return Acf_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Acf_Guard::ok( $result, $message );
	}
}
