<?php
/**
 * Feature 122 - changes prices across a filtered set of products.
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
 * changes prices across a filtered set of products.
 *
 * @since 0.0.34
 */
final class Bulk_Update_Prices extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/bulk-update-prices';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Bulk Update Prices', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change the regular price across a set of products, either to a fixed amount or by a percentage. Reports what it would do and changes nothing unless apply is true, and every row comes back with the product, its old price and its new one so the whole change can be read before and after. Requires an explicit list of products or a category - there is deliberately no way to reprice the entire store in one call - and is capped per call.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'pricing';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'product_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ), 'description' => __( 'Products to change.', 'acrossai-abilities-manager' ) ),
			'category_id' => array( 'type' => 'integer', 'description' => __( 'Change every product in this category instead.', 'acrossai-abilities-manager' ) ),
			'mode' => array( 'type' => 'string', 'enum' => array( 'set', 'increase_by_percent', 'decrease_by_percent' ), 'description' => __( 'How to apply the amount.', 'acrossai-abilities-manager' ) ),
			'amount' => array( 'type' => 'number', 'description' => __( 'The new price, or the percentage to move by.', 'acrossai-abilities-manager' ) ),
			'apply' => array( 'type' => 'boolean', 'default' => false, 'description' => __( 'Make the change. Without this the call only reports what it would do.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'mode', 'amount' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'dry_run' => array( 'type' => 'boolean' ),
			'mode' => array( 'type' => 'string' ),
			'amount' => array( 'type' => 'number' ),
			'count' => array( 'type' => 'integer' ),
			'changes' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
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
		return __( 'This changes the price of every product matched, which customers will see immediately. Run it once without apply to read the before and after, then pass confirm: true together with apply: true.', 'acrossai-abilities-manager' );
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
		return Product_Repository::bulk_update_prices( $input );
	}
}
