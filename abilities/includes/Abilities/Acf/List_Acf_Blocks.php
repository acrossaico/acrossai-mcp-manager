<?php
/**
 * Feature 105 — List ACF Blocks.
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
 * blocks/list-acf-blocks — List ACF Blocks.
 */
final class List_Acf_Blocks extends Base_Acf_Ability {

	protected function slug(): string {
		return 'blocks/list-acf-blocks';
	}

	protected function ability_label(): string {
		return __( 'List ACF Blocks', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Every ACF block registered on this site, with its name, title, category, the field groups bound to it and how many fields those hold. The orientation call before registering a new block or inserting an existing one. Requires ACF PRO.',
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
		return array();
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'blocks' => array( 'type' => 'array' ),

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
		$rows = Block_Repository::all();

		return array(
			'blocks'  => $rows,
			'count'   => count( $rows ),
			'message' => sprintf(
				/* translators: %d: number of blocks */
				_n( '%d ACF block.', '%d ACF blocks.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
