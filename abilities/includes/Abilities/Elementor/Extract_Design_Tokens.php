<?php
/**
 * Feature 067 / issue #243 — extract recurring design tokens.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Design_Model;

defined( 'ABSPATH' ) || exit;

/**
 * Collects the values set ON elements, with frequency.
 *
 * Reads the document only: values inherited from the kit are invisible here, so an
 * empty result means nothing was overridden locally, NOT that the page has no styling.
 * The output says so, because the opposite reading is the tempting one.
 */
class Extract_Design_Tokens extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'extract-design-tokens';
	}

	protected function audit_label(): string {
		return __( 'Extract Elementor Design Tokens', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Extract the recurring values a page actually uses — colours, font sizes, and spacing — with a count for each, so you can see how many distinct values are in play and which are one-offs. A page using eleven greys usually means eleven decisions nobody made on purpose. Reads values set on elements; it does not read the site kit defaults they inherit from.', 'acrossai-abilities-manager' );
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$colors  = array();
		$fonts   = array();
		$spacing = array();

		$collect = static function ( array $settings ) use ( &$colors, &$fonts, &$spacing ): void {
			foreach ( $settings as $key => $value ) {
				$key = (string) $key;

				if ( is_string( $value ) && 1 === preg_match( '/^#[0-9a-fA-F]{3,8}$/', $value ) ) {
					$colors[ strtolower( $value ) ] = ( $colors[ strtolower( $value ) ] ?? 0 ) + 1;
					continue;
				}

				if ( ! is_array( $value ) || ! isset( $value['size'] ) || ! is_numeric( $value['size'] ) ) {
					continue;
				}

				$token = $value['size'] . (string) ( $value['unit'] ?? '' );

				if ( str_contains( $key, 'font_size' ) || str_contains( $key, 'typography_font_size' ) ) {
					$fonts[ $token ] = ( $fonts[ $token ] ?? 0 ) + 1;
				} elseif ( str_contains( $key, 'padding' ) || str_contains( $key, 'margin' ) || str_contains( $key, 'gap' ) || str_contains( $key, 'space' ) ) {
					$spacing[ $token ] = ( $spacing[ $token ] ?? 0 ) + 1;
				}
			}
		};

		foreach ( (array) $model['scoped_data'] as $root ) {
			if ( ! is_array( $root ) ) {
				continue;
			}
			\AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository::walk_tree(
				array( $root ),
				static function ( array $element ) use ( $collect ): void {
					$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
					if ( array() !== $settings ) {
						$collect( $settings );
					}
				}
			);
		}

		arsort( $colors );
		arsort( $fonts );
		arsort( $spacing );

		$findings = array();
		if ( count( $colors ) > 6 ) {
			$findings[] = array(
				'type'     => 'color_sprawl',
				'count'    => count( $colors ),
				'severity' => 'low',
				'message'  => sprintf(
					/* translators: %d: number of distinct colours */
					__( '%d distinct colours are set directly on elements. Values set per element drift away from the kit and from each other.', 'acrossai-abilities-manager' ),
					count( $colors )
				),
			);
		}
		if ( count( $spacing ) > 8 ) {
			$findings[] = array(
				'type'     => 'spacing_sprawl',
				'count'    => count( $spacing ),
				'severity' => 'low',
				'message'  => sprintf(
					/* translators: %d: number of distinct spacing values */
					__( '%d distinct spacing values are in use, which is more than a page can hold to deliberately.', 'acrossai-abilities-manager' ),
					count( $spacing )
				),
			);
		}

		return array(
			'findings'        => $findings,
			'recommendations' => array() === $findings ? array() : array(
				array( 'suggestion' => __( 'Move the values you actually want into the site kit and clear the per-element overrides, so there is one place to change them.', 'acrossai-abilities-manager' ) ),
			),
			'score'           => null,
			'extras'          => array(
				'colors'  => $colors,
				'fonts'   => $fonts,
				'spacing' => $spacing,
				'basis'   => __( 'Values set on elements only. Anything inherited from the site kit is not visible here, so an empty result means nothing was overridden locally.', 'acrossai-abilities-manager' ),
			),
		);
	}
}
