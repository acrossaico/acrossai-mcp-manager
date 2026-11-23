<?php
/**
 * Feature 104 — shared assembler for every LiteSpeed Cache ability.
 *
 * This class is the SOLE assembler of ability(). Centralising it is what guarantees every ability
 * carries the right category and meta.acrossai.tab_group — Feature 078 existed only because a suite
 * shipped with tab_group => 'core' and rendered under the wrong tab, and nothing failed.
 *
 * It is also the sole enforcer of the guard order, so the ordering is a structural property rather
 * than something 60 code reviews have to catch:
 *
 *   1 availability   LiteSpeed loaded
 *   2 confirmation   confirm:true for irreversible or site-wide operations
 *   3 run()          per-field validation and domain work
 *   4 envelope       ok() / fail()
 *
 * Subclasses implement run() and the metadata accessors. They MUST NOT override ability() or
 * execute(), and MUST NOT name a LiteSpeed\* symbol — all host access belongs in
 * Includes\Abilities\Utilities\LiteSpeed. Test_LiteSpeed_Architecture asserts both.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\LiteSpeed_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the LiteSpeed Cache suite.
 */
abstract class Base_LiteSpeed_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-litespeed-cache';

	/**
	 * Toolset key. The visible label comes from the integration declaration, because the derived
	 * form of this key reads badly — see Integrations\LiteSpeed_Cache::toolset_label().
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'litespeed-cache';

	/**
	 * Ability slug suffix, without the `litespeed/` prefix.
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
	 * DELIBERATELY final. LiteSpeed defines no granular capabilities — every one of its admin screens
	 * is `manage_options` — so unlike Contact Form 7 there is nothing to compose with. The `final`
	 * still matters: it stops a future subclass quietly lowering the floor, which is the shape that
	 * opened a real authorisation hole in the Rank Math suite.
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
	 * Operation-specific confirmation message. '' uses the generic one.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return '';
	}

	/**
	 * Display label for every sub-group, in one place.
	 *
	 * The derived form would read "Ls Purge" — an abbreviation the label rule cannot know about,
	 * inside a panel that already says LiteSpeed Cache.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'ls-purge'    => __( 'Purging & Cache Control', 'acrossai-abilities-manager' ),
			'ls-cache'    => __( 'Cache Settings', 'acrossai-abilities-manager' ),
			'ls-optimize' => __( 'Page Optimisation', 'acrossai-abilities-manager' ),
			'ls-media'    => __( 'Media & Lazy Load', 'acrossai-abilities-manager' ),
			'ls-crawler'  => __( 'Crawler', 'acrossai-abilities-manager' ),
			'ls-database' => __( 'Database', 'acrossai-abilities-manager' ),
			'ls-object'   => __( 'Object & Browser Cache', 'acrossai-abilities-manager' ),
			'ls-toolbox'  => __( 'Presets & Diagnostics', 'acrossai-abilities-manager' ),
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

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this operation.', 'acrossai-abilities-manager' ),
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
			'name' => 'litespeed/' . $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => LiteSpeed_Guard::can( $this->permission_floor() ),
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
		$available = LiteSpeed_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return LiteSpeed_Guard::fail( $available );
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = LiteSpeed_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return LiteSpeed_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return LiteSpeed_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return LiteSpeed_Guard::ok( $result, $message );
	}
}
