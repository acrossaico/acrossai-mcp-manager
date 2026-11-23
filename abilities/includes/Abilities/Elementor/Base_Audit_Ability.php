<?php
/**
 * Feature 067 — abstract base for individual design-audit abilities.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Design_Audit_Runner;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Design_Model;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Shared skeleton for the 27 individual design-audit and subtree-op
 * abilities. Each concrete subclass supplies:
 *   - audit_slug() — the ability slug body (e.g. 'audit-column-balance')
 *   - audit_label() — human-readable label
 *   - audit_description() — long description
 *   - analyze( $post_id, $subtree_id ) — returns array with findings/recommendations/score keys
 *
 * The base handles wp_register_ability registration, permission gate,
 * Elementor presence check, standard response envelope, and standard
 * input/output schemas.
 *
 * By default an audit is read-only + idempotent. Subclasses that mutate
 * the document can override is_destructive() to change annotations.
 */
abstract class Base_Audit_Ability extends Ability_Definition { // phpcs:ignore

	/**
	 * Register the ability, and — for read-only audits — enrol it in the aggregate.
	 *
	 * Issue #243: Design_Audit_Runner's registry was only ever populated from the test
	 * suite, so evaluate-design and suggest-design-fixes composed nothing on a real
	 * site. Enrolling here means an audit cannot exist without the aggregate knowing
	 * about it, which is what made the two drift apart in the first place.
	 *
	 * Mutating abilities are deliberately NOT enrolled. evaluate-design is read-only
	 * and is called to find out what is wrong; an aggregate that rewrote the document
	 * as a side effect of being asked a question would be indefensible.
	 */
	public function __construct() {
		parent::__construct();

		if ( $this->is_destructive() ) {
			return;
		}

		Design_Audit_Runner::register_audit(
			$this->audit_slug(),
			function ( int $post_id, string $subtree_id = '' ): array {
				$model = Design_Model::build( $post_id, $subtree_id );
				if ( is_wp_error( $model ) ) {
					return array(
						'findings'        => array(),
						'recommendations' => array(),
						'error'           => (string) $model->get_error_code(),
					);
				}

				if ( ! empty( $model['empty'] ) ) {
					return array( 'findings' => array(), 'recommendations' => array() );
				}

				return $this->analyze( $model, $post_id, $subtree_id );
			}
		);
	}

	/** @return string slug body — e.g. "audit-column-balance" */
	abstract protected function audit_slug(): string;

	/** @return string human-readable label */
	abstract protected function audit_label(): string;

	/** @return string long description */
	abstract protected function audit_description(): string;

	/**
	 * Perform the analysis.
	 *
	 * Receives the model already built and validated, so no subclass repeats the
	 * load-decode-scope-or-error dance, and every audit reasons over the same
	 * definition of a row, a lane and a ratio.
	 *
	 * @param array<string,mixed> $model      {@see Design_Model::build()}.
	 * @param int                 $post_id    Post id.
	 * @param string              $subtree_id Subtree scope, or ''.
	 * @return array<string,mixed> { findings, recommendations, score?, extras? }
	 */
	abstract protected function analyze( array $model, int $post_id, string $subtree_id ): array;

	/** @return bool default: read-only */
	protected function is_destructive(): bool {
		return false;
	}

	/**
	 * Input properties, with the confirm flag added for mutating abilities.
	 *
	 * confirm is deliberately NOT in `required`: WP core validates input_schema before
	 * execute() runs, so a required confirm fails with a generic ability_invalid_input
	 * and the gate's own message — the one naming the flag — never fires.
	 *
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		$properties = array(
			'post_id'    => array( 'type' => 'integer', 'minimum' => 1 ),
			'subtree_id' => array( 'type' => 'string' ),
		);

		if ( $this->is_destructive() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true. This rewrites the Elementor document on a live page.', 'acrossai-abilities-manager' ),
			);
		}

		return $properties;
	}

	/**
	 * Library sub-group slug.
	 *
	 * Read-only audits and write-mode subtree fixes are two different jobs, so
	 * they land in two different sub-groups: `discover(sub_group=...)` on the
	 * Elementor Toolset can then return "what can I inspect" without also
	 * returning "what can mutate the document". The destructive flag is the
	 * only thing that separates them, so derive from it rather than asking
	 * every subclass to repeat itself.
	 *
	 * @return string
	 */
	protected function sub_group(): string {
		return $this->is_destructive() ? 'elementor-design-fixes' : 'elementor-design-audit';
	}

	/**
	 * Human-readable label for {@see self::sub_group()}.
	 *
	 * @return string
	 */
	protected function sub_group_label(): string {
		return $this->is_destructive()
			? __( 'Design Fixes', 'acrossai-abilities-manager' )
			: __( 'Design Audit', 'acrossai-abilities-manager' );
	}

	protected function ability(): array {
		$readonly    = ! $this->is_destructive();
		$destructive = $this->is_destructive();
		return array(
			'name' => 'elementor/' . $this->audit_slug(),
			'args' => array(
				'label'               => $this->audit_label(),
				'description'         => $this->audit_description(),
				'category'            => 'acrossai-elementor',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' ) && current_user_can( 'edit_posts' );
				},
				'input_schema'        => array(
					'type' => 'object',
					'properties' => $this->input_properties(),
					'required' => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema' => array(
					'type' => 'object',
					'properties' => array(
						'success'         => array( 'type' => 'boolean' ),
						'post_id'         => array( 'type' => 'integer' ),
						'findings'        => array( 'type' => 'array' ),
						'recommendations' => array( 'type' => 'array' ),
						'score'           => array( 'type' => array( 'number', 'null' ) ),
						'changed'         => array( 'type' => 'array' ),
						'applied'         => array( 'type' => 'boolean' ),
						// analyze() returns evidence under 'extras' — ratio counts, section
						// weights, score components. Undeclared it fails output validation
						// with additionalProperties:false, the same way the missing keys on
						// evaluate-design did.
						'extras'          => array( 'type' => 'object' ),
						'source_policy'   => array( 'type' => 'string' ),
						'guidance_basis'  => array( 'type' => 'string' ),
						'message'         => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required' => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta' => array(
					'acrossai'     => array( 'tab_group' => 'elementor', 'sub_group' => $this->sub_group(), 'sub_group_label' => $this->sub_group_label() ),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => $readonly, 'destructive' => $destructive, 'idempotent' => $readonly ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$check = Document_Repository::assert_elementor_available();
		if ( is_wp_error( $check ) ) {
			return array( 'success' => false, 'message' => (string) $check->get_error_message(), 'error_code' => (string) $check->get_error_code() );
		}
		$post_id    = absint( $input['post_id'] ?? 0 );
		$subtree_id = isset( $input['subtree_id'] ) ? (string) $input['subtree_id'] : '';
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => __( 'Post not found.', 'acrossai-abilities-manager' ), 'error_code' => 'post_not_found' );
		}
		// Confirmation before anything is read, let alone written: an operation that
		// mutates a live page must not do preparatory work it might act on.
		if ( $this->is_destructive() && empty( $input['confirm'] ) ) {
			return array(
				'success'    => false,
				'post_id'    => $post_id,
				'message'    => __( 'This rewrites the Elementor document on a live page. Pass confirm: true to proceed. Run the matching read-only audit first to see what it would change.', 'acrossai-abilities-manager' ),
				'error_code' => 'confirmation_required',
			);
		}

		$model = Design_Model::build( $post_id, $subtree_id );
		if ( is_wp_error( $model ) ) {
			return array( 'success' => false, 'post_id' => $post_id, 'message' => (string) $model->get_error_message(), 'error_code' => (string) $model->get_error_code() );
		}

		if ( ! empty( $model['empty'] ) ) {
			return array(
				'success'         => true,
				'post_id'         => $post_id,
				'findings'        => array(),
				'recommendations' => array(),
				'score'           => null,
				'source_policy'   => 'elementor_docs_first',
				'message'         => __( 'This page has no Elementor layout to examine, so nothing was checked. A null score means "not assessed", not "perfect".', 'acrossai-abilities-manager' ),
			);
		}

		$result = $this->analyze( $model, $post_id, $subtree_id );

		return array_merge(
			array(
				'success'        => true,
				'post_id'        => $post_id,
				'findings'       => array(),
				'recommendations' => array(),
				'score'          => null,
				'source_policy'  => 'elementor_docs_first',
				'guidance_basis' => 'grounded in Elementor.com official documentation',
				'message'        => sprintf( /* translators: %s: audit name */ __( 'Ran audit: %s.', 'acrossai-abilities-manager' ), $this->audit_slug() ),
			),
			$result
		);
	}
}
