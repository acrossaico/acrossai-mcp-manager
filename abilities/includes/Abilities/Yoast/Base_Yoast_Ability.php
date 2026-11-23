<?php
/**
 * Feature 106 — shared assembler for every Yoast SEO ability.
 *
 * Sole assembler of ability() and sole enforcer of the guard order:
 * available → confirmation → run() → envelope.
 *
 * Subclasses implement run() and the metadata accessors. They MUST NOT override ability() or
 * execute(), and MUST NOT name a Yoast symbol — all host access belongs in Utilities\Yoast, in
 * either its legacy `WPSEO_*` or modern `Yoast\WP\SEO\*` spelling.
 *
 * **Two things differ from the earlier suites.**
 *
 * 1. `TAB_GROUP` is `yoast-seo`, matching `Integrations\Yoast_Seo`, and that integration CLAIMS the
 *    `yoast-seo` ability prefix so Yoast's own five abilities are adopted into the same tab. Our
 *    slugs therefore live under `seo/` and `taxonomies/` — never `yoast-seo/`, which would shadow
 *    one of Yoast's. Subclasses give the whole slug, not a suffix.
 *
 * 2. **No environment gating.** Yoast unregisters its own abilities whenever `is_production_mode()`
 *    is false, because indexables record permalinks and building them on staging bakes in the wrong
 *    ones. That is right for indexables and wrong for settings, terms, sitemaps and tools, so this
 *    suite registers on every environment. Only the indexable abilities care, and they report an
 *    empty index rather than vanishing. Test_Yoast_Architecture forbids referencing
 *    is_production_mode, should_index_indexables or wp_get_environment_type anywhere here.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Yoast_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the Yoast SEO suite.
 */
abstract class Base_Yoast_Ability extends Ability_Definition {

	/**
	 * Ability category shared by the whole suite.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-yoast-seo';

	/**
	 * Toolset key. Must equal Integrations\Yoast_Seo::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'yoast-seo';

	/**
	 * Ability slug INCLUDING its namespace, e.g. `seo/get-seo-settings`.
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
	 * Input schema properties, excluding `confirm` and the slash fragment.
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
	 * Capability floor for the whole suite. DELIBERATELY final.
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
	 * Whether this ability slashes a caller-supplied string.
	 *
	 * Per the Feature 105 decision, this must mean the ability ACTUALLY calls Slash_Input::slash().
	 * A flag advertising a control that does nothing is worse than no flag.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_writer(): bool {
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
			'yoast-settings'      => __( 'Settings', 'acrossai-abilities-manager' ),
			'yoast-content-types' => __( 'Content Types', 'acrossai-abilities-manager' ),
			'yoast-terms'         => __( 'Terms & Taxonomies', 'acrossai-abilities-manager' ),
			'yoast-indexables'    => __( 'Indexables & Archives', 'acrossai-abilities-manager' ),
			'yoast-sitemap'       => __( 'XML Sitemaps', 'acrossai-abilities-manager' ),
			'yoast-indexing'      => __( 'Indexation', 'acrossai-abilities-manager' ),
			'yoast-content'       => __( 'Content Analysis', 'acrossai-abilities-manager' ),
			'yoast-tools'         => __( 'Status & Tools', 'acrossai-abilities-manager' ),
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

		if ( $this->is_writer() ) {
			$properties = array_merge( $properties, Slash_Input::schema_fragment() );
		}

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this irreversible operation.', 'acrossai-abilities-manager' ),
			);

			// Never schema-required: core validates input_schema before execute() runs, so a required
			// confirm yields a generic ability_invalid_input and the gate never fires.
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
			$meta['input_flags'] = Slash_Input::meta_flags();
		}

		return array(
			'name' => $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Yoast_Guard::can( $this->permission_floor() ),
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
		$available = Yoast_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return Yoast_Guard::fail( $available );
		}

		if ( $this->requires_confirmation() ) {
			$confirmed = Yoast_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return Yoast_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return Yoast_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Yoast_Guard::ok( $result, $message );
	}
}
