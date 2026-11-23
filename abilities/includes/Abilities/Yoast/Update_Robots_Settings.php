<?php
/**
 * Feature 106 — Update Robots Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-robots-settings — Update Robots Settings.
 */
final class Update_Robots_Settings extends Base_Yoast_Ability {

	protected function slug(): string {
		return 'seo/update-robots-settings';
	}

	protected function ability_label(): string {
		return __( 'Update Robots Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Change whether WordPress discourages search engines site-wide. This is the single switch that overrides every other SEO setting: with it on, Yoast emits noindex for the whole site regardless of any per-type configuration. Turning it on is confirm-gated for that reason.',
			'acrossai-abilities-manager'
		);
	}

	protected function sub_group(): string {
		return 'yoast-tools';
	}

	protected function suggested_abilities(): array {
		return array(
			'seo/get-robots-settings',
		);
	}

	protected function input_properties(): array {
		return array(
			'discourage_search_engines' => array(
				'type'        => 'boolean',
				'description' => __( 'True to discourage crawlers site-wide, false to allow them.', 'acrossai-abilities-manager' ),
			),
			'confirm' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Required only when turning discouragement ON.', 'acrossai-abilities-manager' ),
			),
		);
	}

	protected function required_input(): array {
		return array(
			'discourage_search_engines',
		);
	}

	protected function output_properties(): array {
		return array(
			'discourage_search_engines' => array( 'type' => 'boolean' ),

			'changed' => array( 'type' => 'boolean' ),
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
		$discourage = ! empty( $input['discourage_search_engines'] );
		$current    = '0' === (string) get_option( 'blog_public' );

		// Only the direction that hides the site needs confirmation; allowing crawlers back protects
		// nothing and gating it would just be friction.
		if ( $discourage && ! $current && empty( $input['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				__( 'Discouraging search engines noindexes the ENTIRE site, overriding every other SEO setting. Pass confirm: true to proceed.', 'acrossai-abilities-manager' )
			);
		}

		update_option( 'blog_public', $discourage ? '0' : '1' );

		return array(
			'discourage_search_engines' => $discourage,
			'changed'                   => $discourage !== $current,
			'message'                   => $discourage
				? __( 'Search engines are now discouraged. The whole site is noindexed.', 'acrossai-abilities-manager' )
				: __( 'Search engines are allowed again.', 'acrossai-abilities-manager' ),
		);
	}
}
