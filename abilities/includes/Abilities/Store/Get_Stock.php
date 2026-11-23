<?php
/**
 * Feature 122 - reports the stock level and, crucially, where it is kept.
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
 * reports the stock level and, crucially, where it is kept.
 *
 * @since 0.0.34
 */
final class Get_Stock extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/get-stock';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Stock', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report a product stock level, status and backorder setting - and which record actually holds the number. That last part matters: a variation stock can be managed on its parent product, in which case the variation own stock row is read by nothing, and writing to it is the most common silent inventory mistake there is. The reported quantity is always the one the shop actually uses; own_row_quantity shows the raw field for comparison.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'inventory';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Product or variation id.', 'acrossai-abilities-manager' ) ),
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
			'id' => array( 'type' => 'integer' ),
			'type' => array( 'type' => 'string' ),
			'manage_stock' => array( 'type' => 'boolean' ),
			'stock_quantity' => array( 'type' => array( 'integer', 'null' ) ),
			'own_row_quantity' => array( 'type' => array( 'integer', 'null' ) ),
			'stock_status' => array( 'type' => 'string' ),
			'backorders' => array( 'type' => 'string' ),
			'managed_by_id' => array( 'type' => 'integer' ),
			'managed_here' => array( 'type' => 'boolean' ),
			'authoritative_stock' => array( 'type' => array( 'integer', 'null' ) ),
			'note' => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
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
		return Product_Repository::stock_state( (int) $input['id'] );
	}
}
