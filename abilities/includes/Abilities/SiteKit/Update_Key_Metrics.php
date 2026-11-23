<?php
/**
 * Feature 120 — change the current user's Key Metrics selection.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Key_Metrics_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #14 — site-kit/update-key-metrics.
 *
 * Not confirm-gated, deliberately. It changes which tiles one user sees on one admin
 * screen: nothing about the site, its measurement or its data moves, and writing the
 * previous selection back undoes it exactly. Gating this the way module settings are
 * gated would make the gate mean less where it matters.
 */
class Update_Key_Metrics extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'update-key-metrics';
	}

	protected function ability_label(): string {
		return __( 'Update Site Kit Key Metrics', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Choose which Key Metrics tiles appear at the top of the current user\'s Site Kit dashboard, or hide the row entirely. widget_slugs REPLACES the selection rather than adding to it, so read the current one with site-kit/get-key-metrics and send the full list you want. Site Kit shows at most eight. The selection is per WordPress user, so this changes only what the user running this ability sees — no other user\'s dashboard moves, and nothing about the site, its tracking or its data changes. A slug this plugin does not recognise is still saved, because Site Kit defines its tiles in JavaScript and adds new ones in ordinary releases; it is reported back so a typo is still visible, but a tile that renders nothing is the only cost.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-status';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array(
			'widget_slugs'     => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'maxItems'    => Key_Metrics_Repository::MAX_SLUGS,
				'description' => __( 'The complete tile selection, replacing whatever is there. Get the recognised slugs from site-kit/get-key-metrics. Omit to leave the selection alone and only change hide_widget_area.', 'acrossai-abilities-manager' ),
			),
			'hide_widget_area' => array(
				'type'        => 'boolean',
				'description' => __( 'True to hide the Key Metrics row on this user\'s dashboard, false to show it. Omit to leave it as it is.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'widget_slugs'       => array( 'type' => 'array' ),
			'widget_area_hidden' => array( 'type' => 'boolean' ),
			'previous_slugs'     => array( 'type' => 'array' ),
			'changed'            => array( 'type' => 'boolean' ),
			'unrecognised_slugs' => array( 'type' => 'array' ),
			'current_user_id'    => array( 'type' => 'integer' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$slugs = null;
		if ( array_key_exists( 'widget_slugs', $input ) ) {
			if ( ! is_array( $input['widget_slugs'] ) ) {
				return new WP_Error( 'invalid_input', __( 'widget_slugs must be an array of tile slugs.', 'acrossai-abilities-manager' ) );
			}
			$slugs = $input['widget_slugs'];
		}

		$hidden = array_key_exists( 'hide_widget_area', $input ) ? (bool) $input['hide_widget_area'] : null;

		$result = Key_Metrics_Repository::update( $slugs, $hidden );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parts = array();

		$parts[] = $result['changed']
			? sprintf(
				/* translators: %d: number of tiles now selected */
				__( 'This user\'s Site Kit dashboard now shows %d Key Metrics tiles.', 'acrossai-abilities-manager' ),
				count( $result['widget_slugs'] )
			)
			: __( 'Nothing changed — the selection already matched.', 'acrossai-abilities-manager' );

		if ( $result['widget_area_hidden'] ) {
			$parts[] = __( 'The Key Metrics row is hidden, so the tiles are saved but not displayed.', 'acrossai-abilities-manager' );
		}

		if ( array() !== $result['unrecognised_slugs'] ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated list of unrecognised slugs */
				__( 'Saved but not recognised by this plugin: %s. Either a tile from a newer Site Kit, or a typo — a tile Site Kit does not know renders nothing.', 'acrossai-abilities-manager' ),
				implode( ', ', $result['unrecognised_slugs'] )
			);
		}

		$result['message'] = implode( ' ', $parts );

		return $result;
	}
}
