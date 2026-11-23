<?php
/**
 * Feature 124 - adds a tax rate.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Config_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * adds a tax rate.
 *
 * @since 0.0.34
 */
final class Add_Tax_Rate extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/add-tax-rate';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Add Tax Rate', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Add a tax rate for a country, optionally narrowed to a state, in a given tax class. Says plainly if tax calculation is switched off for the store, because the rate is then stored and never applied. What rate to set is a decision about the business and its obligations; this records the decision rather than making it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'configuration';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'country' => array( 'type' => 'string', 'description' => __( 'Two-letter country code, for example GB.', 'acrossai-abilities-manager' ) ),
			'rate' => array( 'type' => 'string', 'description' => __( 'The percentage, for example 20 for twenty per cent.', 'acrossai-abilities-manager' ) ),
			'state' => array( 'type' => 'string', 'description' => __( 'State or county code. Optional.', 'acrossai-abilities-manager' ) ),
			'name' => array( 'type' => 'string', 'description' => __( 'Label shown at checkout, for example VAT.', 'acrossai-abilities-manager' ) ),
			'class' => array( 'type' => 'string', 'description' => __( 'Tax class. Defaults to standard.', 'acrossai-abilities-manager' ) ),
			'priority' => array( 'type' => 'integer', 'description' => __( 'Priority; only one rate per priority applies.', 'acrossai-abilities-manager' ) ),
			'shipping' => array( 'type' => 'boolean', 'description' => __( 'Whether the rate also applies to shipping.', 'acrossai-abilities-manager' ) ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'country', 'rate' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'tax_rate_id' => array( 'type' => 'integer' ),
			'taxes' => array( 'type' => 'object', 'additionalProperties' => true ),
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
		return __( 'A tax rate changes what customers are charged at checkout. Confirm that this rate is correct for the business before adding it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Config_Repository::add_tax_rate( $input );
	}
}
