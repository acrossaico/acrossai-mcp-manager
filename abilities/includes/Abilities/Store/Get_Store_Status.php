<?php
/**
 * Feature 121 - reports where the store keeps its data and whether the derived copies are current.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Store_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Store health, and the answer to "why did that change not take effect".
 *
 * @since 0.0.34
 */
final class Get_Store_Status extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/get-store-status';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Store Status', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Report where this store keeps its data and whether the copies it derives from that data are current. Covers whether orders live in the dedicated order tables or the posts table, how the product lookup table compares with the catalogue, the state of the cached on-sale and featured lists, and which payment gateways are switched on. Run this first when a price, a sale or an order change appears not to have taken effect: the usual cause is that something wrote the records directly and the derived copies the shop actually reads were never refreshed. Reports gateway names and whether each is enabled, never their settings or keys.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'diagnostics';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'woocommerce_version'    => array( 'type' => 'string' ),
			'hpos_enabled'           => array(
				'type'        => 'boolean',
				'description' => __( 'True when orders are kept in the dedicated order tables rather than the posts table.', 'acrossai-abilities-manager' ),
			),
			'order_sync_enabled'     => array( 'type' => 'boolean' ),
			'order_placeholder_rows' => array( 'type' => 'integer' ),
			'product_lookup'         => array( 'type' => 'object', 'additionalProperties' => true ),
			'catalogue_transients'   => array( 'type' => 'object', 'additionalProperties' => true ),
			'payment_gateways'       => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'description' => __( 'Name and enabled state only. Gateway settings are never returned.', 'acrossai-abilities-manager' ),
			),
			'notes'                  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
		unset( $input );

		return Store_Repository::status();
	}
}
