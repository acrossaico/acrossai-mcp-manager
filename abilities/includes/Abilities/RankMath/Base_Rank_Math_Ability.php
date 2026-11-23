<?php
/**
 * Feature 069 — abstract base for every Rank Math ability.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Rank_Math_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Assembles the ability spec and enforces the execute() ordering for all 61
 * Rank Math abilities.
 *
 * This class is the SOLE assembler of ability(). Centralising it is what
 * guarantees every ability carries the right category and
 * meta.acrossai.tab_group — Feature 078 existed only because a suite shipped
 * with tab_group => 'core' and rendered under the wrong admin tab.
 *
 * It is also the sole enforcer of the mandatory guard order, so the ordering is
 * a structural property rather than something 61 code reviews have to catch:
 *
 *   1 availability   Rank Math loaded
 *   2 module         required Rank Math module active
 *   3 confirmation   confirm:true for destructive operations
 *   4 run()          per-field validation and domain work
 *   5 envelope       ok() / fail()
 *
 * Subclasses implement run() and the metadata accessors. They MUST NOT override
 * ability() or execute(), and MUST NOT name a \RankMath\* symbol — all
 * third-party access belongs in Includes\Abilities\Utilities\RankMath.
 *
 * Only the eight keys permitted by
 * AcrossAI_Ability_Library_Registry::ALLOWED_ARGS_FIELDS are emitted; anything
 * else would be silently stripped at the Registry boundary.
 */
abstract class Base_Rank_Math_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 */
	protected const CATEGORY = 'acrossai-rank-math';

	/**
	 * Admin Integrations tab. The visible "Rank Math" label is derived from this
	 * by titleCaseTabLabel() in the React layer — nothing declares the string.
	 */
	protected const TAB_GROUP = 'rank-math';

	/**
	 * Slug suffix, appended to 'rank-math/'.
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
	 * Library sub-group slug, e.g. 'rank-math-redirections'.
	 *
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * Rank Math capability suffix composed onto the floor, e.g. 'titles'.
	 *
	 * Return '' for abilities Rank Math itself gates on manage_options only.
	 *
	 * @return string
	 */
	abstract protected function rank_math_cap(): string;

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
	 * Per-field validation belongs here, after the base guards have run.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * WordPress capability floor for the entire suite.
	 *
	 * DELIBERATELY final. Every Rank Math ability requires manage_options, matching
	 * the convention across the rest of includes/Abilities/. An earlier revision
	 * lowered the floor to 'edit_posts' for the ten post-scoped abilities on the
	 * reasoning that an editor should be able to manage their own posts' SEO. That
	 * was wrong for two reasons:
	 *
	 * 1. It broke consistency with every other ability suite in the plugin, so the
	 *    capability an integrator must grant differed per ability.
	 * 2. It opened a real authorisation hole. Rank Math grants
	 *    rank_math_onpage_snippet to the author and editor roles by default, so with
	 *    an edit_posts floor an Author passed the permission_callback for
	 *    update-post-schemas — an ability that reaches Rank Math's schema writer,
	 *    which addresses rows by meta_id and ignores the object id entirely.
	 *
	 * Per-object current_user_can( 'edit_post', $id ) checks remain inside run() as
	 * defence in depth; they are no longer the only thing standing between a
	 * lower-privileged role and a cross-object write.
	 *
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * Whether this ability requires confirm:true. Must be true for every
	 * ability whose annotations declare destructive:true.
	 *
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * Rank Math module that must be active, or '' when none is required.
	 *
	 * @return string
	 */
	protected function required_module(): string {
		return '';
	}

	/**
	 * Optional per-ability sub-group label override.
	 *
	 * Empty string falls back to {@see self::sub_group_labels()}, and then to
	 * the auto-derived ucwords(str_replace('-', ' ', sub_group)) form.
	 *
	 * @return string
	 */
	protected function sub_group_label(): string {
		return '';
	}

	/**
	 * Display label for every Rank Math sub-group, in one place.
	 *
	 * The derived form would read "Rank Math Redirections" inside a panel that
	 * already says Rank Math, and it cannot express a sub-group that merged two
	 * of Rank Math's own modules (404 logs sit with redirections because that is
	 * one workflow, not two). A slug missing here still renders — it just falls
	 * back to the derived label.
	 *
	 * @return array<string, string> Sub-group slug => label.
	 */
	protected function sub_group_labels(): array {
		return array(
			'rank-math-content'          => __( 'Content & SEO Meta', 'acrossai-abilities-manager' ),
			'rank-math-redirections'     => __( 'Redirections & 404s', 'acrossai-abilities-manager' ),
			'rank-math-status'           => __( 'Status & Backups', 'acrossai-abilities-manager' ),
			'rank-math-content-ai'       => __( 'Content AI & Visibility', 'acrossai-abilities-manager' ),
			'rank-math-settings'         => __( 'Settings', 'acrossai-abilities-manager' ),
			'rank-math-instant-indexing' => __( 'Instant Indexing', 'acrossai-abilities-manager' ),
			'rank-math-sitemap'          => __( 'Sitemaps & Routes', 'acrossai-abilities-manager' ),
			'rank-math-analytics'        => __( 'Analytics', 'acrossai-abilities-manager' ),
			'rank-math-admin'            => __( 'Modules & Roles', 'acrossai-abilities-manager' ),
			'rank-math-schema'           => __( 'Schema', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Success message. Subclasses may override for a richer summary; the payload
	 * may also carry its own 'message', which wins.
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
		$label     = $this->sub_group_label();
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

			// 'confirm' must NOT be schema-required. WP core validates input_schema
			// before execute() runs, so a required confirm makes an unconfirmed call
			// fail with a generic ability_invalid_input ("confirm is a required
			// property") and assert_confirmed() never fires. The caller then never
			// sees confirmation_required or the message naming the flag, which is
			// the entire point of the gate. Strip it defensively so no subclass can
			// reintroduce the bug.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => 'rank-math/' . $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Rank_Math_Guard::can( $this->rank_math_cap(), $this->permission_floor() ),
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
		$available = Rank_Math_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return Rank_Math_Guard::fail( $available );
		}

		$module = Rank_Math_Guard::assert_module( $this->required_module() );
		if ( is_wp_error( $module ) ) {
			return Rank_Math_Guard::fail( $module );
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = Rank_Math_Guard::assert_confirmed( $input );
			if ( is_wp_error( $confirmed ) ) {
				return Rank_Math_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );
		if ( is_wp_error( $result ) ) {
			return Rank_Math_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Rank_Math_Guard::ok( $result, $message );
	}
}
