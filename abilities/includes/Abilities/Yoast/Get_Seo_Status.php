<?php
/**
 * Feature 106 — Get Yoast Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Tools_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-seo-status — Get Yoast Status.
 */
final class Get_Seo_Status extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-seo-status';
	}

	protected function ability_label(): string {
		return __( 'Get Yoast Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Yoast\'s version and edition, whether sitemaps and the content analyses are on, whether indexables have been built, and the WordPress environment type. The orientation call, and the fastest answer to "why is Yoast not doing X" — most of those questions turn out to be the environment or an unbuilt index.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-tools';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-indexing-status',
		);
	}

	protected function input_properties(): array {
		return array(
		);
	}

	protected function required_input(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'status' => array( 'type' => 'object' ),
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
		$status = Tools_Repository::status();

		return array(
			'status'  => $status,
			'message' => sprintf(
				/* translators: 1: Yoast version, 2: environment */
				__( 'Yoast SEO %1$s on a %2$s environment.', 'acrossai-abilities-manager' ),
				$status['version'],
				'' !== $status['environment'] ? $status['environment'] : 'unknown'
			),
		);
	}
}
