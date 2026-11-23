<?php
/**
 * Feature 106 — Get SEO Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-seo-settings — Get SEO Settings.
 */
final class Get_Seo_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-seo-settings';
	}

	protected function ability_label(): string {
		return __( 'Get SEO Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Read Yoast\'s site-wide settings as rows, each carrying the key, its current value, its type and the option group it belongs to. Pass area to narrow to one screen\'s worth: general, crawl, webmaster, integrations, title-templates, archives, breadcrumbs, knowledge-graph, social-defaults, schema, rss, social-profiles, llms or advanced. 214 keys are readable and writable; Yoast\'s internal bookkeeping is deliberately excluded.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-settings';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/list-settings-areas',
		);
	}

	protected function input_properties(): array {
		return array(
			'area' => array(
				'type'        => 'string',
				'description' => __( 'Narrow to one settings area. Omit for every area. Call seo/list-settings-areas to see them.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'settings' => array( 'type' => 'array' ),

			'count' => array( 'type' => 'integer' ),
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
		$area  = isset( $input['area'] ) ? (string) $input['area'] : '';
		$areas = Settings_Repository::areas();

		if ( '' !== $area && ! isset( $areas[ $area ] ) ) {
			return new WP_Error(
				'unknown_settings_area',
				sprintf(
					/* translators: 1: requested area, 2: comma-separated known areas */
					__( '"%1$s" is not a settings area. Known areas: %2$s.', 'acrossai-abilities-manager' ),
					$area,
					implode( ', ', array_keys( $areas ) )
				)
			);
		}

		$rows = array();

		foreach ( '' !== $area ? array( $area ) : array_keys( $areas ) as $one ) {
			foreach ( Settings_Repository::describe_area( (string) $one ) as $row ) {
				$row['area'] = (string) $one;
				$rows[]      = $row;
			}
		}

		return array(
			'settings' => $rows,
			'count'    => count( $rows ),
			'message'  => sprintf(
				/* translators: %d: number of settings */
				_n( '%d setting.', '%d settings.', count( $rows ), 'acrossai-abilities-manager' ),
				count( $rows )
			),
		);
	}
}
