<?php
/**
 * Feature 103 — shared assembler for every Contact Form 7 ability.
 *
 * This class is the SOLE assembler of ability(). Centralising it is what guarantees every ability
 * carries the right category and meta.acrossai.tab_group — Feature 078 existed only because a suite
 * shipped with tab_group => 'core' and rendered under the wrong tab, and nothing failed.
 *
 * It is also the sole enforcer of the guard order, so the ordering is a structural property rather
 * than something 25 code reviews have to catch:
 *
 *   1 availability   CF7 loaded
 *   2 confirmation   confirm:true for irreversible operations
 *   3 run()          per-field validation and domain work
 *   4 envelope       ok() / fail()
 *
 * Subclasses implement run() and the metadata accessors. They MUST NOT override ability() or
 * execute(), and MUST NOT name a WPCF7_* symbol — all host access belongs in
 * Includes\Abilities\Utilities\ContactForm7. Test_Contact_Form_7_Architecture asserts both.
 *
 * Only the eight keys permitted by AcrossAI_Ability_Library_Registry::ALLOWED_ARGS_FIELDS are
 * emitted; anything else is silently stripped at the Registry boundary.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContactForm7
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContactForm7;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\ContactForm7\Contact_Form_7_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the Contact Form 7 suite.
 */
abstract class Base_Contact_Form_7_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-contact-form-7';

	/**
	 * Toolset key. The visible label comes from the integration declaration, because the derived
	 * form of this key reads badly — see Integrations\Contact_Form_7::toolset_label().
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'contact-form-7';

	/**
	 * Ability slug suffix, without the `contact-form-7/` prefix.
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
	 * CF7 capability suffix, without `wpcf7_`. '' skips the CF7 check but never the floor.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function cf7_cap(): string;

	/**
	 * Input schema properties, excluding `confirm`.
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
	 * DELIBERATELY final. CF7 maps its own capabilities onto `publish_pages` and `edit_posts`, so
	 * gating on the CF7 capability alone would let an Editor drive every ability here. That is the
	 * same shape that opened a real authorisation hole in the Rank Math suite when its floor was
	 * lowered to `edit_posts` — see Base_Rank_Math_Ability::permission_floor().
	 *
	 * The cost is stated rather than hidden: an Editor who can manage forms in wp-admin today cannot
	 * manage them through an ability. That is intended — an ability is reachable by an AI client and
	 * wp-admin is not.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
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
	 * Per-ability sub-group label override.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group_label(): string {
		return '';
	}

	/**
	 * Display label for every sub-group, in one place.
	 *
	 * The derived form would read "Cf7 Forms" — an acronym the label rule cannot know about, inside a
	 * panel that already says Contact Form 7.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'cf7-forms'    => __( 'Forms', 'acrossai-abilities-manager' ),
			'cf7-fields'   => __( 'Fields & Template', 'acrossai-abilities-manager' ),
			'cf7-mail'     => __( 'Mail & Notifications', 'acrossai-abilities-manager' ),
			'cf7-messages' => __( 'Validation Messages', 'acrossai-abilities-manager' ),
			'cf7-settings' => __( 'Settings & Validation', 'acrossai-abilities-manager' ),
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

			// 'confirm' must NOT be schema-required. WP core validates input_schema before execute()
			// runs, so a required confirm makes an unconfirmed call fail with a generic
			// ability_invalid_input ("confirm is a required property") and assert_confirmed() never
			// fires. The caller then never sees confirmation_required or the message naming the flag,
			// which is the entire point of the gate. Stripped defensively so no subclass can
			// reintroduce the bug.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => 'contact-form-7/' . $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Contact_Form_7_Guard::can( $this->cf7_cap(), $this->permission_floor() ),
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
	 * Run the guards, then the ability, then wrap the result.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Contact_Form_7_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return Contact_Form_7_Guard::fail( $available );
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = Contact_Form_7_Guard::assert_confirmed( $input );

			if ( is_wp_error( $confirmed ) ) {
				return Contact_Form_7_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return Contact_Form_7_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Contact_Form_7_Guard::ok( $result, $message );
	}
}
