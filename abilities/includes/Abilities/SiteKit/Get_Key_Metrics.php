<?php
/**
 * Feature 120 — read the current user's Key Metrics selection.
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
 * Ability #13 — site-kit/get-key-metrics.
 */
class Get_Key_Metrics extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-key-metrics';
	}

	protected function ability_label(): string {
		return __( 'Get Site Kit Key Metrics', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Report which Key Metrics tiles the current user has chosen for the top of their Site Kit dashboard — things like new visitors, most popular content, or top traffic source — plus whether the row is hidden and who completed Key Metrics setup for the site. The selection is stored per WordPress user, so this describes what the user running this ability sees, not a site-wide setting. Also returns the tile slugs known to this plugin, which is what site-kit/update-key-metrics expects, and flags any chosen slug it does not recognise — usually a tile from a newer Site Kit than this list.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-status';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'widget_slugs'       => array( 'type' => 'array' ),
			'widget_area_hidden' => array( 'type' => 'boolean' ),
			'unrecognised_slugs' => array( 'type' => 'array' ),
			'setup_completed_by' => array( 'type' => 'object' ),
			'current_user_id'    => array( 'type' => 'integer' ),
			'max_slugs'          => array( 'type' => 'integer' ),
			'known_slugs'        => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Key_Metrics_Repository::get();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = array() === $result['widget_slugs']
			? __( 'This user has chosen no Key Metrics tiles, so Site Kit shows them its default selection rather than a saved one.', 'acrossai-abilities-manager' )
			: sprintf(
				/* translators: 1: number of tiles, 2: comma-separated tile slugs */
				__( 'This user tracks %1$d Key Metrics tiles: %2$s.', 'acrossai-abilities-manager' ),
				count( $result['widget_slugs'] ),
				implode( ', ', $result['widget_slugs'] )
			);

		return $result;
	}
}
