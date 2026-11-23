<?php
/**
 * Feature 123 - summarises the customer base without identifying anyone.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Customer_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Insight_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Order_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * summarises the customer base without identifying anyone.
 *
 * @since 0.0.34
 */
final class List_Customers extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/list-customers';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Customers', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Summarise the customer base: how many are registered, how many have actually bought something, and how their order counts are distributed. Deliberately returns no names, emails or addresses - nothing here identifies anybody, and most questions a shop owner asks are answered at this level. When named records are genuinely needed, store/export-customers releases them under explicit conditions.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'customers';
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
			'registered_customers' => array( 'type' => 'integer' ),
			'purchasers' => array( 'type' => 'integer' ),
			'guest_orders_present' => array( 'type' => 'boolean' ),
			'orders_per_customer' => array( 'type' => 'object', 'additionalProperties' => true ),
			'personal_data_included' => array( 'type' => 'boolean' ),
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
		unset( $input );

		return Customer_Repository::summary();
	}
}
