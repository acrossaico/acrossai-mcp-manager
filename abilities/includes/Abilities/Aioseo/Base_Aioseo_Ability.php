<?php
/**
 * Feature 132 — the sole ability assembler for the All in One SEO suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Aioseo;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo\Aioseo_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability in this suite runs through here.
 *
 * Sole assembler of `ability()` and sole enforcer of the guard order — available → confirmation →
 * `run()` → envelope. Subclasses implement `run()` and the metadata accessors; they MUST NOT override
 * `ability()` or `execute()`, and MUST NOT name an `AIOSEO\*` symbol, `aioseo()` or `AIOSEO_VERSION`.
 * All host access belongs in `Includes\Abilities\Utilities\Aioseo`.
 *
 * **The namespace is `aioseo/`, and that is a decision with history.** AIOSEO ships thirteen
 * abilities of its own under five area namespaces — `aioseo-posts/`, `aioseo-settings/`,
 * `aioseo-robots/`, `aioseo-audit/`, `aioseo-notifications/` — and none of them uses the bare
 * `aioseo/` prefix. `seo/` was unavailable: this plugin's 58 Yoast abilities hold it, and the two SEO
 * plugins can be active together, so sharing it would mean one suite losing names to the other by
 * load order.
 *
 * The standing rule, learned the hard way in 0.0.44 when Rank Math claimed `rank-math/get-settings`
 * and `rank-math/get-sitemap-status` out from under two of ours: **the host plugin owns its
 * namespace.** If AIOSEO ever registers an `aioseo/*` name that collides, ours moves or retires. It
 * does not shadow.
 *
 * **Nothing here duplicates AIOSEO's own thirteen.** No single-post SEO read or write, no
 * posts-missing-SEO or TruSEO-score listing, no robots.txt rules, no notification listing, no raw
 * settings tree read. Where this suite comes close it points at theirs by name.
 *
 * The floor is `manage_options` and final, composed with the AIOSEO capability for the area the
 * ability touches. `Slash_Input` is deliberately ABSENT: nothing here writes post content.
 *
 * @since 0.0.44
 */
abstract class Base_Aioseo_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 *
	 * @since 0.0.44
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-all-in-one-seo';

	/**
	 * Toolset key. Must equal `Integrations\All_In_One_Seo::TAB_GROUP`, or this suite and AIOSEO's own
	 * abilities land in different groups and the dispatcher serves only one of them.
	 *
	 * @since 0.0.44
	 * @var   string
	 */
	protected const TAB_GROUP = 'all-in-one-seo';

	/**
	 * Slug suffix, appended to 'aioseo/'.
	 *
	 * @since  0.0.44
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * @since  0.0.44
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * @since  0.0.44
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * @since  0.0.44
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * AIOSEO capability composed onto the floor, e.g. 'aioseo_sitemap_settings'.
	 *
	 * Return '' for an ability whose required area depends on its input — those assert the capability
	 * inside `run()` instead, because `permission_callback` receives no input.
	 *
	 * @since  0.0.44
	 * @return string
	 */
	abstract protected function aioseo_cap(): string;

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * @since  0.0.44
	 * @return array<int, string>
	 */
	abstract protected function required_input(): array;

	/**
	 * @since  0.0.44
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	abstract protected function annotations(): array;

	/**
	 * Domain work. Returns the payload WITHOUT the 'success' key, or a WP_Error.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * Administrator, and not overridable by a subclass.
	 *
	 * AIOSEO's own abilities use a lower bar — `canEditPosts` for the post four — which is right for
	 * them: those act on one post the caller can already edit. Everything in this suite is site-wide
	 * configuration, so it matches the floor every other settings suite in this plugin uses. Keeping
	 * it final is what stops a later ability from quietly lowering it for convenience.
	 *
	 * @since  0.0.44
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * @since  0.0.44
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * Display label per sub-group, in one place.
	 *
	 * @since  0.0.44
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'aioseo-settings' => __( 'Settings', 'acrossai-abilities-manager' ),
			'aioseo-sitemap'  => __( 'Sitemaps', 'acrossai-abilities-manager' ),
			'aioseo-content'  => __( 'Content & SEO Meta', 'acrossai-abilities-manager' ),
			'aioseo-status'   => __( 'Status & Backups', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.44
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Operation completed.', 'acrossai-abilities-manager' );
	}

	/**
	 * One caller value, coerced to a string without PHP raising a notice about it.
	 *
	 * `(string) $input['panel']` is the obvious spelling and it is wrong: an array arrives here when
	 * something calls `execute()` without going through core's schema validation, and casting one
	 * emits "Array to string conversion" before the ability gets to refuse it politely. Found by an
	 * adversarial pass — the refusal was already correct, the warning beside it was not.
	 *
	 * A non-scalar becomes '', which every caller of this already treats as "not given" and reports
	 * with the list of what IS valid.
	 *
	 * @since  0.0.44
	 * @param  mixed $value Caller value.
	 * @return string
	 */
	final protected static function scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @since  0.0.44
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
				'description' => __( 'Must be true to perform this irreversible operation.', 'acrossai-abilities-manager' ),
			);

			// 'confirm' must NOT be schema-required: core validates input_schema before execute()
			// runs, so a required confirm fails with a generic ability_invalid_input and the caller
			// never sees confirmation_required or the message naming the flag. Stripped defensively
			// so no subclass can reintroduce it.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => 'aioseo/' . $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Aioseo_Guard::can( $this->aioseo_cap(), $this->permission_floor() ),
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
	 * Execute the ability.
	 *
	 * The guard order is mandatory and is the reason subclasses must not override this.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $input Ability input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Aioseo_Guard::assert_available();
		if ( is_wp_error( $available ) ) {
			return Aioseo_Guard::fail( $available );
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = Aioseo_Guard::assert_confirmed( $input, $this->confirmation_message() );
			if ( is_wp_error( $confirmed ) ) {
				return Aioseo_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );
		if ( is_wp_error( $result ) ) {
			return Aioseo_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Aioseo_Guard::ok( $result, $message );
	}
}
