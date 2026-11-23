<?php
/**
 * Feature 116 - lists every plugin, theme and core bundle Loco can translate.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * lists every plugin, theme and core bundle Loco can translate.
 *
 * @since 0.0.34
 */
final class List_Bundles extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/list-bundles';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'List Translation Bundles', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'List every plugin, theme and core bundle Loco Translate can see, with its text domains and how many translation files each already has. This is not a directory listing: a bundle\'s text domains and where its translations belong come from Loco\'s configuration, so bundles with no translations yet appear here too, which is usually the set you care about. Filter by type to narrow it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'discovery';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'type'     => array(
				'type'        => 'string',
				'enum'        => Bundle_Repository::TYPES,
				'description' => __( 'Only plugin, theme or core bundles.', 'acrossai-abilities-manager' ),
			),
			'search'   => array(
				'type'        => 'string',
				'description' => __( 'Case-insensitive match against name, handle or slug.', 'acrossai-abilities-manager' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 300,
				'default'     => 100,
				'description' => __( 'Maximum rows to return. This site has around 150 bundles.', 'acrossai-abilities-manager' ),
			),
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
			'bundles' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'count'   => array( 'type' => 'integer' ),
			'total'   => array(
				'type'        => 'integer',
				'description' => __( 'How many bundles exist before per_page was applied.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$bundles = Bundle_Repository::all( isset( $input['type'] ) ? (string) $input['type'] : '' );

		if ( is_wp_error( $bundles ) ) {
			return $bundles;
		}

		$needle = isset( $input['search'] ) ? strtolower( trim( (string) $input['search'] ) ) : '';
		$limit  = isset( $input['per_page'] ) ? (int) $input['per_page'] : 100;
		$rows   = array();
		$total  = 0;

		foreach ( $bundles as $bundle ) {
			$row = Bundle_Repository::shape( $bundle );

			if ( '' !== $needle ) {
				$hay = strtolower( $row['name'] . ' ' . $row['handle'] . ' ' . $row['slug'] );

				if ( false === strpos( $hay, $needle ) ) {
					continue;
				}
			}

			++$total;

			if ( count( $rows ) < $limit ) {
				$rows[] = $row;
			}
		}

		return array(
			'bundles' => $rows,
			'count'   => count( $rows ),
			'total'   => $total,
		);
	}
}
