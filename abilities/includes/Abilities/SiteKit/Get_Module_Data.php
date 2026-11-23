<?php
/**
 * Feature 120 — call any GET datapoint on any Site Kit module.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteKit;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit\Report_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #11 — site-kit/get-module-data.
 *
 * Site Kit exposes roughly sixty datapoints across its modules, each with its own
 * request shape, and it ships weekly. Wrapping every one would be a treadmill, so the
 * named abilities cover what people actually ask for and this covers the rest.
 *
 * GET only, deliberately. Site Kit's POST datapoints create Analytics properties,
 * rewrite tag configuration and sync audiences; those belong behind named,
 * confirm-gated abilities rather than a generic passthrough that an AI client could
 * reach for by guessing a name.
 */
class Get_Module_Data extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-module-data';
	}

	protected function ability_label(): string {
		return __( 'Get Site Kit Module Data', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Call one of a Site Kit module\'s own read datapoints directly and return what it returns, for anything the named abilities in this suite do not cover — listing Analytics properties and web data streams, Tag Manager containers, AdSense ad units and alerts, Search Console site lists, and so on. Discover the names with site-kit/list-module-datapoints, then pass the parameters that datapoint expects. Read-only: this reaches Site Kit\'s GET datapoints only, never the ones that create properties or rewrite tag configuration. Prefer site-kit/get-search-analytics, site-kit/get-analytics-report, site-kit/get-pagespeed-insights and site-kit/get-adsense-report where they fit — they validate their inputs and explain their failures, and this does not.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'site-kit-data';
	}

	protected function site_kit_cap(): string {
		return '';
	}

	protected function requires_authentication(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array(
			'module'    => array(
				'type'        => 'string',
				'description' => __( 'Module slug, e.g. "analytics-4" or "search-console". List them with site-kit/list-modules.', 'acrossai-abilities-manager' ),
			),
			'datapoint' => array(
				'type'        => 'string',
				'description' => __( 'Datapoint name without the GET: prefix, e.g. "properties" or "webdatastreams". List them with site-kit/list-module-datapoints.', 'acrossai-abilities-manager' ),
			),
			'params'    => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => true,
				'description' => __( 'Parameters for the datapoint, passed through to Site Kit unchanged. Which ones apply depends entirely on the datapoint.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'module'    => array( 'type' => 'string' ),
			'datapoint' => array( 'type' => 'string' ),
			'data'      => array( 'type' => array( 'object', 'array', 'string', 'number', 'boolean', 'null' ) ),
		);
	}

	protected function required_input(): array {
		return array( 'module', 'datapoint' );
	}

	/**
	 * Not idempotent as a class: the datapoint reached is caller-chosen, and while
	 * every GET datapoint is a read, several are reads of live figures that move.
	 * Claiming idempotent here would be a promise this ability cannot keep.
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => false );
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

		$datapoint = isset( $input['datapoint'] ) ? (string) $input['datapoint'] : '';
		// Datapoint names are camelCase or hyphenated ('searchanalytics',
		// 'account-summaries', 'webdatastreams-batch'), so sanitize_key would mangle
		// them. Constrain the shape instead of normalising it.
		if ( '' === $datapoint || ! preg_match( '/^[A-Za-z0-9-]{1,64}$/', $datapoint ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'datapoint is required and must be a plain datapoint name such as "properties" or "account-summaries". Do not include the GET: prefix. List them with site-kit/list-module-datapoints.', 'acrossai-abilities-manager' )
			);
		}

		$params = isset( $input['params'] ) && is_array( $input['params'] ) ? $input['params'] : array();

		$data = Report_Repository::module_data( $slug, $datapoint, $params );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'module'    => $slug,
			'datapoint' => $datapoint,
			'data'      => $data,
			'message'   => sprintf(
				/* translators: 1: datapoint name, 2: module slug */
				__( 'Read "%1$s" from the Site Kit "%2$s" module.', 'acrossai-abilities-manager' ),
				$datapoint,
				$slug
			),
		);
	}
}
