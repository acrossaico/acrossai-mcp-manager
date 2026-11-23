<?php
/**
 * Feature 106 — Get SEO Import Status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Settings_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Yoast\Tools_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * seo/get-import-status — Get SEO Import Status.
 */
final class Get_Import_Status extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/get-import-status';
	}

	protected function ability_label(): string {
		return __( 'Get SEO Import Status', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Whether SEO data has been imported from another plugin, and which other SEO plugins are present to import from. Yoast can take over metadata from AIOSEO, Rank Math and others; this reports what is available rather than starting anything.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-tools';
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
			'completed' => array( 'type' => 'boolean' ),

			'candidates' => array( 'type' => 'array' ),
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
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$candidates = array();
		$known      = array(
			'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
			'seo-by-rank-math/rank-math.php'             => 'Rank Math',
			'autodescription/autodescription.php'        => 'The SEO Framework',
			'seo-ultimate/seo-ultimate.php'              => 'SEO Ultimate',
		);

		foreach ( $known as $basename => $label ) {
			if ( is_plugin_active( $basename ) ) {
				$candidates[] = array(
					'plugin' => (string) $basename,
					'label'  => (string) $label,
				);
			}
		}

		return array(
			'completed'  => (bool) Settings_Repository::value( 'importing_completed' ),
			'candidates' => $candidates,
			'message'    => array() === $candidates
				? __( 'No other SEO plugin is active to import from.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of candidates */
					__( '%d other SEO plugin(s) active that Yoast could import from.', 'acrossai-abilities-manager' ),
					count( $candidates )
				),
		);
	}
}
