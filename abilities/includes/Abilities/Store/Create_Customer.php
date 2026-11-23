<?php
/**
 * Feature 123 - creates a customer account.
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
 * creates a customer account.
 *
 * @since 0.0.34
 */
final class Create_Customer extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/create-customer';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Create Customer', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Create a customer account with an email address and, optionally, a name and billing or shipping address. WooCommerce ships nothing for this. No password is accepted or set: an account whose password an assistant chose is not the customer account, so a random one is generated, never returned, and the customer sets their own through the site password reset.', 'acrossai-abilities-manager' );
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
			'email' => array( 'type' => 'string', 'description' => __( 'Email address. Must not already have an account.', 'acrossai-abilities-manager' ) ),
			'password' => array(
				'type'        => 'string',
				'description' => __( 'Not accepted. Declared only so that trying to set one returns an explanation rather than a bare schema rejection.', 'acrossai-abilities-manager' ),
			),
			'username' => array( 'type' => 'string', 'description' => __( 'Username. Defaults to the email address.', 'acrossai-abilities-manager' ) ),
			'first_name' => array( 'type' => 'string', 'description' => __( 'First name.', 'acrossai-abilities-manager' ) ),
			'last_name' => array( 'type' => 'string', 'description' => __( 'Last name.', 'acrossai-abilities-manager' ) ),
			'billing' => array( 'description' => __( 'Billing address fields, for example first_name, address_1, city, postcode, country.', 'acrossai-abilities-manager' ) ),
			'shipping' => array( 'description' => __( 'Shipping address fields, same shape as billing.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'email' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'customer' => array( 'type' => 'object', 'additionalProperties' => true ),
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
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		if ( isset( $input['password'] ) ) {
			return new WP_Error(
				'password_not_writable',
				__( 'A password cannot be set here. An account whose password an assistant chose is not the customer\'s account: a random one is generated instead, never returned, and the customer sets their own through the site\'s password reset.', 'acrossai-abilities-manager' )
			);
		}

		return Customer_Repository::create( $input );
	}
}
