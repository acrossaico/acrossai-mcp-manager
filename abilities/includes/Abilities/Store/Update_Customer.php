<?php
/**
 * Feature 123 - changes a customer details.
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
 * changes a customer details.
 *
 * @since 0.0.34
 */
final class Update_Customer extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/update-customer';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Customer', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Change a customer name, email address or billing and shipping details. Passwords cannot be set here and the attempt is refused by name: send the customer the site own password reset instead. Changing an email address to one already in use is refused rather than silently failing.', 'acrossai-abilities-manager' );
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
			'password' => array(
				'type'        => 'string',
				'description' => __( 'Not accepted. Declared only so that trying to set one returns an explanation rather than a bare schema rejection.', 'acrossai-abilities-manager' ),
			),
			'email' => array( 'type' => 'string', 'description' => __( 'New email address.', 'acrossai-abilities-manager' ) ),
			'first_name' => array( 'type' => 'string', 'description' => __( 'First name.', 'acrossai-abilities-manager' ) ),
			'last_name' => array( 'type' => 'string', 'description' => __( 'Last name.', 'acrossai-abilities-manager' ) ),
			'billing' => array( 'description' => __( 'Billing address fields to change.', 'acrossai-abilities-manager' ) ),
			'shipping' => array( 'description' => __( 'Shipping address fields to change.', 'acrossai-abilities-manager' ) ),
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

		return Customer_Repository::update( (int) $input['id'], $fields );
	}
}
