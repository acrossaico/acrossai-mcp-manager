<?php
/**
 * Feature 123 - reads one customer.
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
 * reads one customer.
 *
 * @since 0.0.34
 */
final class Get_Customer extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/get-customer';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Customer', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read one customer: how many orders they have placed and what they have spent. Their name, email, username and addresses are withheld unless include_personal_data is set. Password hashes, session tokens and payment provider customer references are never returned under any circumstances.', 'acrossai-abilities-manager' );
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
		return array(
			'id' => array( 'type' => 'integer', 'description' => __( 'Customer user id.', 'acrossai-abilities-manager' ) ),
			'include_personal_data' => array( 'type' => 'boolean', 'default' => false, 'description' => __( 'Include name, email, username and addresses.', 'acrossai-abilities-manager' ) ),
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
			'customer' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		$customer = Customer_Repository::get( (int) $input['id'], ! empty( $input['include_personal_data'] ) );

		return is_wp_error( $customer ) ? $customer : array( 'customer' => $customer );
	}
}
