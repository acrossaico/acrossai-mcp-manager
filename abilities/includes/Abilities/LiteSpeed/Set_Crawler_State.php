<?php
/**
 * Feature 104 — Enable Or Disable A Crawler.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LiteSpeed;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed\Crawler_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * litespeed/set-crawler-state — Enable Or Disable A Crawler.
 */
final class Set_Crawler_State extends Base_LiteSpeed_Ability {

	protected function slug(): string {
		return 'set-crawler-state';
	}

	protected function ability_label(): string {
		return __( 'Enable Or Disable A Crawler', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Enable or disable one crawler variant by index, as reported by litespeed/list-crawlers. An index that does not exist is refused with unknown_crawler and the valid indexes, rather than silently doing nothing.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'ls-crawler';
	}

	protected function input_properties(): array {
		return array(
			'index'   => array(
				'type'        => 'integer',
				'description' => __( 'Crawler index from litespeed/list-crawlers.', 'acrossai-abilities-manager' ),
			),

			'enabled' => array(
				'type'        => 'boolean',
				'description' => __( 'Desired state.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'index',
			'enabled',
		);
	}

	protected function output_properties(): array {
		return array(
			'index'   => array( 'type' => 'integer' ),

			'enabled' => array( 'type' => 'boolean' ),
		);
	}

	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	protected function run( array $input ) {
		$index = isset( $input['index'] ) ? (int) $input['index'] : -1;
		$state = Crawler_Repository::set_state( $index, ! empty( $input['enabled'] ) );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return array(
			'index'   => $index,
			'enabled' => $state,
			'message' => $state
				? sprintf( /* translators: %d: crawler index */ __( 'Crawler %d enabled.', 'acrossai-abilities-manager' ), $index )
				: sprintf( /* translators: %d: crawler index */ __( 'Crawler %d disabled.', 'acrossai-abilities-manager' ), $index ),
		);
	}
}
