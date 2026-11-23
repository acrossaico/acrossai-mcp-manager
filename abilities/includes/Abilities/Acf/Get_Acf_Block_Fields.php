<?php
/**
 * Feature 105 — Get ACF Block Fields.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Block_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * blocks/get-acf-block-fields — Get ACF Block Fields.
 */
final class Get_Acf_Block_Fields extends Base_Acf_Ability {

	protected function slug(): string {
		return 'blocks/get-acf-block-fields';
	}

	protected function ability_label(): string {
		return __( 'Get ACF Block Fields', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'The fields behind an ACF block: name, key, type, label, whether required, and the default. Repeaters and groups nest their sub-fields, and flexible-content fields nest one entry per layout, so the whole shape is visible in one call. Call this before blocks/insert-acf-block so the data payload matches what the block actually expects. Requires ACF PRO.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-blocks';
	}

	protected function requires_pro(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'name' => array(
				'type'        => 'string',
				'description' => __( 'Block name, with or without the acf/ prefix.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'name',
		);
	}

	protected function output_properties(): array {
		return array(
			'name'   => array( 'type' => 'string' ),

			'fields' => array( 'type' => 'array' ),

			'count'  => array( 'type' => 'integer' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$name  = Block_Repository::qualify( (string) $input['name'] );
		$block = Block_Repository::get( $name );

		if ( is_wp_error( $block ) ) {
			return $block;
		}

		$rows = Block_Repository::fields( $name );

		return array(
			'name'    => $name,
			'fields'  => $rows,
			'count'   => count( $rows ),
			'message' => array() === $rows
				? __( 'That block has no field group bound to it yet.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: 1: number of fields, 2: block name */
					__( '%1$d field(s) on "%2$s".', 'acrossai-abilities-manager' ),
					count( $rows ),
					$name
				),
		);
	}
}
