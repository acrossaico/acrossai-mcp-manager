<?php
/**
 * Feature 121 — the sole ability assembler for the store suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Store
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Store;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Store\Store_Guard;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Every ability in this suite runs through here.
 *
 * The base owns what must not vary: the category, the tab group, the capability floor, the guard
 * order and the envelope.
 *
 * This tab holds TWO permission models and that is worth stating rather than papering over.
 * WooCommerce's own seven abilities check `wc_rest_check_post_permissions()` and admit a Shop
 * Manager; everything here requires an administrator, because `permission_floor()` is final at
 * `manage_options`. An operator looking at one "WooCommerce" tab would otherwise assume one answer.
 *
 * Our abilities live under `store/` and can never move. WooCommerce reserves the whole `woocommerce/`
 * prefix — `AbilitiesLoader::is_reserved_woocommerce_ability_name()` skips a third-party class using
 * it, and if one of its own seven names is shadowed it calls `wp_unregister_ability()` and takes the
 * name back.
 *
 * `Slash_Input` is deliberately ABSENT. Every write in this suite goes through WooCommerce's CRUD
 * classes, which sanitise on the way in and do not unslash, so slashing here would add a level
 * nothing removes — the mistake Feature 116 measured twice.
 */
abstract class Base_Store_Ability extends Ability_Definition {

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	protected const CATEGORY = 'acrossai-store';

	/**
	 * @since 0.0.34
	 * @var   string
	 */
	protected const TAB_GROUP = 'woocommerce';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function slug(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_label(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function ability_description(): string;

	/**
	 * @since  0.0.34
	 * @return string
	 */
	abstract protected function sub_group(): string;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function input_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	abstract protected function output_properties(): array;

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	abstract protected function required_input(): array;

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	abstract protected function annotations(): array;

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	abstract protected function run( array $input );

	/**
	 * Administrator, and not overridable by a subclass.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	final protected function permission_floor(): string {
		return 'manage_options';
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return false;
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return bool
	 */
	protected function needs_confirmation_for( array $input ): bool {
		return $this->requires_confirmation();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	protected function sub_group_labels(): array {
		return array(
			'diagnostics' => __( 'Store health', 'acrossai-abilities-manager' ),
			'catalog'     => __( 'Catalogue', 'acrossai-abilities-manager' ),
			'pricing'     => __( 'Pricing', 'acrossai-abilities-manager' ),
			'inventory'   => __( 'Inventory', 'acrossai-abilities-manager' ),
			'orders'      => __( 'Orders', 'acrossai-abilities-manager' ),
			'customers'   => __( 'Customers', 'acrossai-abilities-manager' ),
			'insight'     => __( 'Insight', 'acrossai-abilities-manager' ),
			'marketing'   => __( 'Coupons', 'acrossai-abilities-manager' ),
			'configuration' => __( 'Store configuration', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function success_message(): string {
		return __( 'Done.', 'acrossai-abilities-manager' );
	}

	/**
	 * Assemble the ability definition.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function ability(): array {
		$sub_group = $this->sub_group();
		$acrossai  = array(
			'tab_group' => self::TAB_GROUP,
			'sub_group' => $sub_group,
		);

		$label = $this->sub_group_labels()[ $sub_group ] ?? '';

		if ( '' !== $label ) {
			$acrossai['sub_group_label'] = $label;
		}

		$properties = $this->input_properties();
		$required   = $this->required_input();

		if ( $this->requires_confirmation() ) {
			$properties['confirm'] = array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Must be true to perform this operation.', 'acrossai-abilities-manager' ),
			);

			// Never schema-required: core validates input_schema before execute() runs, so a
			// required confirm yields a generic ability_invalid_input and the gate never fires.
			$required = array_values( array_diff( $required, array( 'confirm' ) ) );
		}

		return array(
			'name' => $this->slug(),
			'args' => array(
				'label'               => $this->ability_label(),
				'description'         => $this->ability_description(),
				'category'            => self::CATEGORY,
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => Store_Guard::can( $this->permission_floor() ),
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $properties,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'success' => array( 'type' => 'boolean' ) ),
						$this->output_properties(),
						array(
							'message'    => array( 'type' => 'string' ),
							'error_code' => array( 'type' => 'string' ),
						)
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => $acrossai,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => $this->annotations(),
				),
			),
		);
	}

	/**
	 * Guards, then the ability, then the envelope.
	 *
	 * The account gate sits BEFORE the confirmation gate on purpose: being asked to confirm an
	 * operation that cannot run either way wastes a round trip and reads as though confirming would
	 * help.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input = array() ): array {
		$available = Store_Guard::assert_available();

		if ( is_wp_error( $available ) ) {
			return Store_Guard::fail( $available );
		}

		if ( $this->needs_confirmation_for( $input ) ) {
			$confirmed = Store_Guard::assert_confirmed( $input, $this->confirmation_message() );

			if ( is_wp_error( $confirmed ) ) {
				return Store_Guard::fail( $confirmed );
			}
		}

		$result = $this->run( $input );

		if ( is_wp_error( $result ) ) {
			return Store_Guard::fail( $result );
		}

		$message = isset( $result['message'] ) ? (string) $result['message'] : $this->success_message();
		unset( $result['message'] );

		return Store_Guard::ok( $result, $message );
	}
}
