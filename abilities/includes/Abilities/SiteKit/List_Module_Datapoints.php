<?php
/**
 * Feature 120 — list the datapoints one Site Kit module exposes.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Module_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #6 — site-kit/list-module-datapoints.
 *
 * The discovery half of site-kit/get-module-data.
 */
class List_Module_Datapoints extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'list-module-datapoints';
	}

	protected function ability_label(): string {
		return __( 'List Site Kit Module Datapoints', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'List the datapoints one Site Kit module can serve — the named requests its own dashboard makes, such as "report", "searchanalytics", "properties" or "webdatastreams". Pair it with site-kit/get-module-data to reach anything the named abilities in this suite do not already cover. The names are read from the installed Site Kit build, so they describe this site rather than a documented set that may differ by version.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-modules';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function input_properties(): array {
		return array(
			'module' => array(
				'type'        => 'string',
				'description' => __( 'Module slug, e.g. "analytics-4" or "search-console". List them with site-kit/list-modules.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'slug'       => array( 'type' => 'string' ),
			'datapoints' => array( 'type' => 'array' ),
		);
	}

	protected function required_input(): array {
		return array( 'module' );
	}

	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$slug = isset( $input['module'] ) ? sanitize_key( (string) $input['module'] ) : '';
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_input', __( 'module is required. List the available slugs with site-kit/list-modules.', 'acrossai-abilities-manager' ) );
		}

		$result = Module_Repository::list_datapoints( $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: 1: number of datapoints, 2: module slug */
			__( 'The Site Kit "%2$s" module serves %1$d datapoints.', 'acrossai-abilities-manager' ),
			count( $result['datapoints'] ),
			$slug
		);

		return $result;
	}
}
