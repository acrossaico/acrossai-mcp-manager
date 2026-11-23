<?php
/**
 * Feature 070 — normalized theme.json style guide.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Return a flat, cacheable summary of the active theme's design system:
 * spacing scale, palette, typography, layout widths, duotones, gradients.
 */
class Get_Style_Guide extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/get-style-guide',
			'args' => array(
				'label'               => __( 'Get Style Guide', 'acrossai-abilities-manager' ),
				'description'         => __( 'Return a normalized summary of the active theme\'s design system: spacing scale, color palette (theme + user), typography (families + font-sizes), layout widths (contentSize / wideSize), and root duotone/gradient sets.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => new \stdClass(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'    => array( 'type' => 'boolean' ),
						'spacing'    => array( 'type' => 'array' ),
						'palette'    => array( 'type' => 'array' ),
						'typography' => array( 'type' => 'object' ),
						'layout'     => array( 'type' => 'object' ),
						'duotone'    => array( 'type' => 'array' ),
						'gradients'  => array( 'type' => 'array' ),
						'message'    => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'appearance',
						'sub_group'       => 'site-editor',
						'sub_group_label' => __( 'Site Editor', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		unset( $input );

		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return array(
				'success'    => false,
				'spacing'    => array(),
				'palette'    => array(),
				'typography' => new \stdClass(),
				'layout'     => new \stdClass(),
				'duotone'    => array(),
				'gradients'  => array(),
				'message'    => __( 'WP_Theme_JSON_Resolver is unavailable.', 'acrossai-abilities-manager' ),
			);
		}

		$merged   = \WP_Theme_JSON_Resolver::get_merged_data();
		$settings = is_object( $merged ) && method_exists( $merged, 'get_settings' ) ? $merged->get_settings() : array();

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$spacing_sizes = $this->effective_presets( $settings['spacing']['spacingSizes'] ?? array() );
		$palette       = $this->effective_presets( $settings['color']['palette'] ?? array() );
		$font_families = $this->effective_presets( $settings['typography']['fontFamilies'] ?? array() );
		$font_sizes    = $this->effective_presets( $settings['typography']['fontSizes'] ?? array() );
		$duotone       = $this->effective_presets( $settings['color']['duotone'] ?? array() );
		$gradients     = $this->effective_presets( $settings['color']['gradients'] ?? array() );

		return array(
			'success'    => true,
			'spacing'    => $spacing_sizes,
			'palette'    => $palette,
			'typography' => array(
				'font_families' => $font_families,
				'font_sizes'    => $font_sizes,
			),
			'layout'     => array(
				'content_size' => sanitize_text_field( (string) ( $settings['layout']['contentSize'] ?? '' ) ),
				'wide_size'    => sanitize_text_field( (string) ( $settings['layout']['wideSize'] ?? '' ) ),
			),
			'duotone'    => $duotone,
			'gradients'  => $gradients,
			'message'    => __( 'Style guide returned from merged theme.json settings. Each preset carries the origin it came from; where a user preset reuses a theme slug, only the user one is listed, because that is the value the site renders.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Flatten core's origin-keyed preset structure into the list the site actually renders.
	 *
	 * `WP_Theme_JSON::get_settings()` returns presets grouped by origin — `default`, `theme`,
	 * `custom` — and a slug may appear in more than one. This used to be handled two different wrong
	 * ways in the same method: the palette concatenated `theme` and `custom` with no labels, so a
	 * user colour overriding `accent-1` was listed twice and read as a duplicate; while font
	 * families, font sizes, duotones and gradients read `theme` only, so user presets were missing
	 * from the style guide altogether.
	 *
	 * One preset per slug now, later origins winning exactly as core's CSS variable generation does,
	 * each tagged with the origin it came from and — where it replaced one — what it overrode.
	 *
	 * @since  0.0.41
	 * @param  mixed $group Origin-keyed preset group, or a plain list on older shapes.
	 * @return array<int, array<string, mixed>>
	 */
	private function effective_presets( $group ): array {
		if ( ! is_array( $group ) ) {
			return array();
		}

		// A plain list (no origin keys) — pass it through with no origin claimed.
		if ( array_key_exists( 0, $group ) ) {
			return array_values( $group );
		}

		$by_slug = array();
		// Theme and user presets only, as before — core's own defaults are deliberately left out so
		// this response stays the size it was.
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $group[ $origin ] ?? array() ) as $preset ) {
				if ( ! is_array( $preset ) ) {
					continue;
				}

				$slug            = isset( $preset['slug'] ) ? (string) $preset['slug'] : '';
				$preset['origin'] = $origin;

				if ( '' === $slug ) {
					$by_slug[] = $preset;
					continue;
				}

				if ( isset( $by_slug[ $slug ]['origin'] ) ) {
					$preset['overrides'] = (string) $by_slug[ $slug ]['origin'];
				}

				$by_slug[ $slug ] = $preset;
			}
		}

		return array_values( $by_slug );
	}
}
