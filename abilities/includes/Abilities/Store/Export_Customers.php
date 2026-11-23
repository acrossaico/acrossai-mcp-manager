<?php
/**
 * Feature 123 - releases named customer records, under explicit conditions.
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
 * releases named customer records, under explicit conditions.
 *
 * @since 0.0.34
 */
final class Export_Customers extends Base_Store_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'store/export-customers';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Export Customers', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Release customer records including names, email addresses and addresses. This is personal data by definition, so it is asked for explicitly rather than by default: it needs include_personal_data, a written reason that is recorded, and a confirmation - and it needs a capability beyond being an administrator, because administering a site is not on its own a reason to release a list of named people. Always paginated and hard-capped, and the response says how many records it released and what it withheld. Password hashes, session tokens and payment provider customer references are never included. If you only need numbers, store/list-customers answers without identifying anybody.', 'acrossai-abilities-manager' );
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
			'include_personal_data' => array( 'type' => 'boolean', 'default' => false, 'description' => __( 'Required. Without it this refuses.', 'acrossai-abilities-manager' ) ),
			'reason' => array( 'type' => 'string', 'description' => __( 'Why the export is needed. Recorded with who asked and how many records were released.', 'acrossai-abilities-manager' ) ),
			'format' => array( 'type' => 'string', 'enum' => array( 'json', 'csv' ), 'default' => 'json', 'description' => __( 'Return structured rows or a CSV payload.', 'acrossai-abilities-manager' ) ),
			'limit' => array( 'type' => 'integer', 'default' => 100, 'minimum' => 1, 'maximum' => 500, 'description' => __( 'How many records to release.', 'acrossai-abilities-manager' ) ),
			'offset' => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => __( 'Where to start, for paging.', 'acrossai-abilities-manager' ) ),
		);
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
			'format' => array( 'type' => 'string' ),
			'customers' => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
			'csv' => array( 'type' => 'string' ),
			'disclosed_count' => array( 'type' => 'integer' ),
			'total_matching' => array( 'type' => 'integer' ),
			'offset' => array( 'type' => 'integer' ),
			'has_more' => array( 'type' => 'boolean' ),
			'reason' => array( 'type' => 'string' ),
			'personal_data_included' => array( 'type' => 'boolean' ),
			'withheld' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
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
		return __( 'This releases named customer records with their email addresses and postal addresses. The disclosure is recorded. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		return Customer_Repository::export( $input );
	}
}
