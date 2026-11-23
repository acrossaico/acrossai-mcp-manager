<?php
/**
 * Feature 120 — PageSpeed Insights through Site Kit.
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
 * Ability #9 — site-kit/get-pagespeed-insights.
 *
 * Authenticated like the rest of the suite. The PageSpeed Insights API is public and
 * it is tempting to assume Site Kit reaches it with an API key — it does not.
 * PageSpeed_Insights::setup_services() builds its service on the same
 * Google_Site_Kit_Client every other module uses, and requests the 'openid' scope, so
 * an unauthenticated user gets a 401 rather than a report.
 */
class Get_Pagespeed_Insights extends Base_Site_Kit_Ability {

	protected function slug(): string {
		return 'get-pagespeed-insights';
	}

	protected function ability_label(): string {
		return __( 'Get PageSpeed Insights', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Run Google PageSpeed Insights on one URL through Site Kit and return the full Lighthouse result: performance, accessibility, best-practices and SEO scores, the Core Web Vitals lab measurements, and — where Google has enough real traffic for the URL — the field data from the Chrome UX Report. Choose strategy "mobile" or "desktop"; they are separate tests and routinely disagree. Defaults to the site\'s home URL. By default this returns a summary — the category scores as percentages, the Core Web Vitals, any field data Google holds for the URL, and only the audits that failed — because the raw Lighthouse payload is around half a megabyte and mostly a base64 screenshot. Pass detail "audits" for every audit\'s score without its detail tables, or "full" for Google\'s whole response. A run takes ten to sixty seconds and may exceed a client\'s request timeout; retrying costs another full run. The URL must be publicly reachable by Google, so this returns nothing useful for a local or password-protected site.', 'acrossai-abilities-manager' );
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

	protected function required_module(): string {
		return Report_Repository::MODULE_PAGESPEED;
	}

	protected function input_properties(): array {
		return array(
			'url'      => array(
				'type'        => 'string',
				'description' => __( 'Public URL to test. Defaults to the site\'s own home URL.', 'acrossai-abilities-manager' ),
			),
			'strategy' => array(
				'type'        => 'string',
				'enum'        => array( 'mobile', 'desktop' ),
				'default'     => 'mobile',
				'description' => __( 'Which test to run. Mobile is the one Google ranks on.', 'acrossai-abilities-manager' ),
			),
			'detail'   => array(
				'type'        => 'string',
				'enum'        => Report_Repository::PAGESPEED_DETAIL,
				'default'     => 'summary',
				'description' => __( 'How much to return. "summary" is scores, Core Web Vitals, field data and failing audits only. "audits" adds every audit\'s score and display value without its detail tables. "full" adds Google\'s entire response, which is large enough to overflow a reply on its own. The full-page screenshot is stripped at every level — it is a base64 image no client can show.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function output_properties(): array {
		return array(
			'strategy'       => array( 'type' => 'string' ),
			'url'            => array( 'type' => 'string' ),
			'detail'         => array( 'type' => 'string' ),
			'scores'         => array(
				'type'        => 'object',
				'description' => __( 'Category scores as percentages: performance, accessibility, best-practices, seo.', 'acrossai-abilities-manager' ),
			),
			'metrics'        => array(
				'type'        => 'object',
				'description' => __( 'Core Web Vitals and their lab companions, each with a display value and the raw number.', 'acrossai-abilities-manager' ),
			),
			'field_data'     => array(
				'type'        => 'object',
				'description' => __( 'Real-user data from the Chrome UX Report. available is false for URLs Google has too little traffic for, which is normal rather than an error.', 'acrossai-abilities-manager' ),
			),
			'fetched_at'     => array( 'type' => 'string' ),
			'final_url'      => array( 'type' => 'string' ),
			'audits_total'   => array( 'type' => 'integer' ),
			'audits_failing' => array( 'type' => 'array' ),
			'audits'         => array( 'type' => 'object' ),
			'raw'            => array( 'type' => array( 'object', 'array' ) ),
		);
	}

	protected function required_input(): array {
		return array();
	}

	/**
	 * Not idempotent: a Lighthouse run measures a live page at a moment in time, so
	 * repeating it returns different numbers even with identical input.
	 */
	protected function annotations(): array {
		return array( 'readonly' => true, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$url      = isset( $input['url'] ) ? (string) $input['url'] : '';
		$strategy = isset( $input['strategy'] ) ? (string) $input['strategy'] : 'mobile';
		$detail   = isset( $input['detail'] ) ? (string) $input['detail'] : 'summary';

		$result = Report_Repository::pagespeed( $url, $strategy, $detail );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$performance = $result['scores']['performance'] ?? null;

		$failing = count( $result['audits_failing'] );

		$result['message'] = sprintf(
			/* translators: 1: strategy, 2: URL tested or the site default, 3: performance score or "not scored", 4: number of failing audits */
			_n(
				'Ran the %1$s PageSpeed test on %2$s. Performance %3$s, %4$d audit below passing.',
				'Ran the %1$s PageSpeed test on %2$s. Performance %3$s, %4$d audits below passing.',
				$failing,
				'acrossai-abilities-manager'
			),
			$strategy,
			'' !== $result['url'] ? $result['url'] : __( 'the site home URL', 'acrossai-abilities-manager' ),
			null === $performance ? __( 'not scored', 'acrossai-abilities-manager' ) : $performance . '/100',
			$failing
		);

		return $result;
	}
}
