<?php
/**
 * Feature 122 - sets a product categories and tags.
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
 * sets a product categories and tags.
 *
 * @since 0.0.34
 */
final class Set_Product_Taxonomy extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/set-product-taxonomy';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Set Product Categories and Tags', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Set which categories and tags a product belongs to, replacing what is there or adding to it. Goes through WooCommerce own save path, which applies the default category when the list would otherwise empty and refreshes the hidden terms the catalogue filters on - neither of which happens if the terms are written directly.', 'acrossai-abilities-manager' );
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
			'id' => array( 'type' => 'integer', 'description' => __( 'Product id.', 'acrossai-abilities-manager' ) ),
			'category_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Product category term ids.', 'acrossai-abilities-manager' ) ),
			'tag_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Product tag term ids.', 'acrossai-abilities-manager' ) ),
			'mode' => array( 'type' => 'string', 'enum' => array( 'replace', 'append' ), 'default' => 'replace', 'description' => __( 'Whether to replace the current terms or add to them.', 'acrossai-abilities-manager' ) ),
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
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$fields = $input;
		unset( $fields['id'] );

		return Product_Repository::set_taxonomy( (int) $input['id'], $fields );
	}
}
