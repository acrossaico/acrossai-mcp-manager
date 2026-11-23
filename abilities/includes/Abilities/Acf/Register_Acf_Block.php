<?php
/**
 * Feature 105 — Register ACF Block.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Block_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * blocks/register-acf-block — Register ACF Block.
 */
final class Register_Acf_Block extends Base_Acf_Ability {

	protected function slug(): string {
		return 'blocks/register-acf-block';
	}

	protected function ability_label(): string {
		return __( 'Register ACF Block', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Create an ACF block type, so a Gutenberg block backed by ACF fields becomes available in the editor. Call blocks/list-acf-blocks first to avoid registering a name that already exists. Registration lasts for the current request only unless the calling code re-registers on every load — ACF blocks are declared in PHP, not stored in the database, so treat this as a way to define and test a block rather than to install one permanently. Requires ACF PRO.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-blocks';
	}

	protected function requires_pro(): bool {
		return true;
	}

	protected function suggested_abilities(): array {
		return array(
			'blocks/list-acf-blocks',
			'blocks/get-acf-block-fields',
		);
	}

	protected function input_properties(): array {
		return array(
			'name'        => array(
				'type'        => 'string',
				'description' => __( 'Block name. The acf/ prefix is added if omitted.', 'acrossai-abilities-manager' ),
			),

			'title'       => array(
				'type'        => 'string',
				'description' => __( 'Human-readable title shown in the block inserter.', 'acrossai-abilities-manager' ),
			),

			'description' => array(
				'type'        => 'string',
				'description' => __( 'What the block is for, shown in the inserter.', 'acrossai-abilities-manager' ),
			),

			'category'    => array(
				'type'        => 'string',
				'description' => __( 'Block category, e.g. formatting, layout, widgets.', 'acrossai-abilities-manager' ),
			),

			'icon'        => array(
				'type'        => 'string',
				'description' => __( 'Dashicon name or inline SVG.', 'acrossai-abilities-manager' ),
			),

			'keywords'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Search keywords for the inserter.', 'acrossai-abilities-manager' ),
			),

			'supports'    => array(
				'type'        => 'object',
				'description' => __( 'Block supports, passed through to ACF unchanged.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'name',
			'title',
		);
	}

	protected function output_properties(): array {
		return array(
			'block' => array( 'type' => 'object' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	protected function run( array $input ) {
		$name = Block_Repository::qualify( (string) $input['name'] );

		if ( ! is_wp_error( Block_Repository::get( $name ) ) ) {
			return new WP_Error(
				'block_exists',
				sprintf(
					/* translators: %s: block name */
					__( 'A block named "%s" is already registered. Call blocks/list-acf-blocks to see what exists.', 'acrossai-abilities-manager' ),
					$name
				)
			);
		}

		$settings = array(
			'name'  => $name,
			'title' => sanitize_text_field( (string) $input['title'] ),
		);

		foreach ( array( 'description', 'category', 'icon' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$settings[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		if ( isset( $input['keywords'] ) && is_array( $input['keywords'] ) ) {
			$settings['keywords'] = array_map( 'sanitize_text_field', array_map( 'strval', $input['keywords'] ) );
		}

		if ( isset( $input['supports'] ) && is_array( $input['supports'] ) ) {
			$settings['supports'] = $input['supports'];
		}

		$registered = Block_Repository::register( $settings );

		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		return array(
			'block'   => array(
				'name'  => $name,
				'title' => $settings['title'],
			),
			'message' => sprintf(
				/* translators: %s: block name */
				__( 'Registered "%s" for this request. Bind a field group to it with a block location rule to give it fields.', 'acrossai-abilities-manager' ),
				$name
			),
		);
	}
}
