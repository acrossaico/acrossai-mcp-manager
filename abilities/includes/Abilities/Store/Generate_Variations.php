<?php
/**
 * Feature 122 - builds the missing variations of a variable product.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Product_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * builds the missing variations of a variable product.
 *
 * @since 0.0.34
 */
final class Generate_Variations extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/generate-variations';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Generate Variations', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Build the variations of a variable product from every combination of its variation attributes, skipping combinations that already exist. Reports what it would create and changes nothing unless apply is true, because a three-by-four-by-five attribute set is sixty products and a request that times out half way leaves a product that looks finished and is not. Capped per call; reduce the options or run it in batches if the cap is hit.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'catalog';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Variable product id.', 'acrossai-abilities-manager' ) ),
			'apply' => array( 'type' => 'boolean', 'default' => false, 'description' => __( 'Create them. Without this the call only reports what it would do.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'id' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'dry_run' => array( 'type' => 'boolean' ),
			'existing_count' => array( 'type' => 'integer' ),
			'would_create' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			'would_create_count' => array( 'type' => 'integer' ),
			'created' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			'note' => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This creates a product record for every attribute combination, which can be a large number at once. Pass confirm: true together with apply: true to build them.', 'acrossai-abilities-manager' );
	}


	/**
	 * Only the real run asks.
	 *
	 * A dry run changes nothing, so demanding confirmation for it is pure friction — and worse, it
	 * teaches a caller to pass confirm reflexively, which is exactly the habit the gate exists to
	 * prevent. Measured: the first version asked for confirmation on a preview.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		return ! empty( $input['apply'] );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Product_Repository::generate_variations( (int) $input['id'], ! empty( $input['apply'] ) );
	}
}
