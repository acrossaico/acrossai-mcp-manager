<?php
/**
 * Feature 118 - reads banner pageview figures from the consent service.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Consent
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Consent;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent\Consent_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * reads banner pageview figures from the consent service.
 *
 * @since 0.0.34
 */
final class Get_Pageview_Statistics extends Base_Consent_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'consent/get-pageview-statistics';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Get Pageview Statistics', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Read how many pageviews the consent banner was shown on, which is what a consent plan is normally metered by. Counted by the consent service, so this needs the site linked to a consent account.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'reporting';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array();
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
			'pageviews' => array( 'type' => 'object', 'additionalProperties' => true ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	protected function requires_account(): bool {
		return true;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function account_subject(): string {
		return __( 'Banner pageview figures', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$class = '\\CookieYes\\Lite\\Admin\\Modules\\Pageviews\\Includes\\Controller';

		if ( ! class_exists( $class ) || ! method_exists( $class, 'get_pageviews' ) ) {
			return new WP_Error(
				'consent_feature_unavailable',
				__( 'This edition of the consent plugin does not expose pageview figures.', 'acrossai-abilities-manager' )
			);
		}

		return array( 'pageviews' => (array) $class::get_instance()->get_pageviews() );
	}
}
