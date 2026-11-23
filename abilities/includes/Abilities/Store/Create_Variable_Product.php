<?php
/**
 * Feature 122 - creates a variable product.
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
 * creates a variable product.
 *
 * @since 0.0.34
 */
final class Create_Variable_Product extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/create-variable-product';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Create Variable Product', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Create a product that comes in variations - sizes, colours and so on. WooCommerce own product-create cannot make one: its type list covers simple, virtual, downloadable, external and grouped products only. Supply the attributes that will vary here, then call store/generate-variations to build the combinations. Created as a draft unless a status is given, so nothing appears in the shop half-built.', 'acrossai-abilities-manager' );
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
			'name' => array( 'type' => 'string', 'description' => __( 'Product name.', 'acrossai-abilities-manager' ) ),
			'sku' => array( 'type' => 'string', 'description' => __( 'Stock keeping unit. Optional.', 'acrossai-abilities-manager' ) ),
			'status' => array( 'type' => 'string', 'enum' => array( 'draft', 'publish', 'private', 'pending' ), 'default' => 'draft', 'description' => __( 'Publication status. Defaults to draft.', 'acrossai-abilities-manager' ) ),
			'attributes' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ), 'description' => __( 'Attributes to vary along; each needs name, options and for_variations: true.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'name' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'product' => array( 'type' => 'object', 'additionalProperties' => true ),
			'lookup_state' => array( 'type' => 'object', 'additionalProperties' => true ),
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
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Product_Repository::create_variable( $input );
	}
}
