<?php
/**
 * Feature 112 - sets the rules that decide when a WPCode snippet loads.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\WPCode
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\WPCode;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\Snippet_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\WPCode\WPCode_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * sets the rules that decide when a WPCode snippet loads.
 *
 * @since 0.0.34
 */
final class Set_Conditional_Logic extends Base_WPCode_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'snippets/set-conditional-logic';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Set Snippet Conditional Logic', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Set the conditional-logic rules that decide when a WPCode snippet loads, or switch the rules off entirely. This site runs WPCode Lite, which can evaluate page and user rules only; a Pro rule type such as device, schedule or WooCommerce is refused by name rather than saved, because Lite stores it happily and then never matches it, so the snippet silently stops appearing with no error anywhere.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'placement';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id'      => array(
				'type'        => 'integer',
				'description' => __( 'Snippet id.', 'acrossai-abilities-manager' ),
			),
			'enabled' => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the rules apply at all. Set false to keep the rules but load the snippet unconditionally.', 'acrossai-abilities-manager' ),
			),
			'groups'  => array(
				'type'        => 'array',
				'items'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				'description' => __( 'WPCode rule groups: a list of groups, each a list of rules with type, option and value keys. Groups are OR-ed together; rules inside a group are AND-ed. Supported types on Lite: page, user.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'id', 'enabled' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'snippet'           => array( 'type' => 'object', 'additionalProperties' => true ),
			'conditional_logic' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
				'description' => __( 'The rule groups as stored, read back.', 'acrossai-abilities-manager' ),
			),
			'enabled'           => array( 'type' => 'boolean' ),
			'safe_mode'         => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$snippet = Snippet_Repository::find( (int) ( $input['id'] ?? 0 ) );

		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}

		$enabled = (bool) ( $input['enabled'] ?? false );
		$groups  = isset( $input['groups'] ) && is_array( $input['groups'] )
			? array_values( $input['groups'] )
			: (array) $snippet->get_conditional_rules();

		$saved = Snippet_Repository::set_rules( $snippet, $enabled, $groups );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array(
			'snippet'           => $saved,
			'conditional_logic' => $saved['conditional_logic'],
			'enabled'           => $enabled,
			'safe_mode'         => WPCode_Guard::safe_mode(),
		);
	}
}
