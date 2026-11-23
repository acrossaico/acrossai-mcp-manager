<?php
/**
 * Feature 105 — Reorder Repeater Rows.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Acf;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Field_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf\Acf_Target;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * custom-fields/reorder-acf-repeater-rows — Reorder Repeater Rows.
 */
final class Reorder_Acf_Repeater_Rows extends Base_Acf_Ability {

	protected function slug(): string {
		return 'custom-fields/reorder-acf-repeater-rows';
	}

	protected function ability_label(): string {
		return __( 'Reorder Repeater Rows', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Reorder a repeater or flexible-content field by supplying the new order as 1-based indexes — [3, 1, 2] moves the third row first. Must be a complete permutation of the current rows: every index exactly once, none missing or repeated, so a partial list is refused rather than silently dropping rows. Requires ACF PRO.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'acf-rows';
	}

	protected function has_target(): bool {
		return true;
	}

	protected function requires_pro(): bool {
		return true;
	}

	protected function required_field_types(): array {
		return array(
			'repeater',
		);
	}

	protected function suggested_abilities(): array {
		return array(
			'custom-fields/get-acf-field',
		);
	}

	protected function input_properties(): array {
		return array(
			'selector' => array(
				'type'        => 'string',
				'description' => __( 'Repeater or flexible-content field name (e.g. cards) or field key.', 'acrossai-abilities-manager' ),
			),

			'order'    => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'integer' ),
				'description' => __( 'The new order as 1-based indexes. Must be a permutation of 1..row_count.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'selector',
			'order',
		);
	}

	protected function output_properties(): array {
		return array(
			'order'     => array( 'type' => 'array' ),

			'row_count' => array( 'type' => 'integer' ),
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
		$target = Acf_Target::resolve( $input );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$selector = (string) $input['selector'];
		$typed    = Field_Repository::assert_type( $selector, $target, array( 'repeater', 'flexible_content' ) );

		if ( is_wp_error( $typed ) ) {
			return $typed;
		}

		$order = isset( $input['order'] ) && is_array( $input['order'] ) ? array_map( 'intval', $input['order'] ) : array();
		$rows  = Field_Repository::get( $selector, $target );
		$rows  = is_array( $rows ) ? array_values( $rows ) : array();
		$count = count( $rows );

		// A permutation, not merely the right length: [1,1,3] has three entries and would silently
		// duplicate one row and drop another.
		$expected = range( 1, max( $count, 1 ) );
		$sorted   = $order;
		sort( $sorted );

		if ( 0 === $count || $sorted !== $expected ) {
			return new WP_Error(
				'invalid_permutation',
				sprintf(
					/* translators: 1: row count, 2: the supplied order */
					__( 'order must list each of the %1$d row indexes exactly once. Received: [%2$s].', 'acrossai-abilities-manager' ),
					$count,
					implode( ', ', array_map( 'strval', $order ) )
				)
			);
		}

		$reordered = array();

		foreach ( $order as $position ) {
			$reordered[] = $rows[ $position - 1 ];
		}

		$written = Field_Repository::update( $selector, $reordered, $target );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'order'     => $order,
			'row_count' => $count,
			'message'   => sprintf(
				/* translators: %s: field name */
				__( 'Rows of "%s" reordered.', 'acrossai-abilities-manager' ),
				$selector
			),
		);
	}
}
