<?php
/**
 * Feature 067 / issue #243 — convert a lone image widget into a lane background.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Elementor
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Elementor;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Design_Mutator;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the image as a lane background and HIDES the widget rather than deleting it.
 *
 * Deleting would be tidier and unrecoverable. Hiding leaves every setting the widget
 * had — its image id, alt text, link — recoverable from the editor, which matters
 * because this is a judgement call that a caller may want to reverse.
 *
 * Applies only to a lane holding exactly one image and no existing background, so it
 * cannot overwrite a background somebody chose.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Image_Widget_To_Background_Container extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'image-widget-to-background-container';
	}

	protected function audit_label(): string {
		return __( 'Convert Elementor Image Widget To Container Background', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Move a lone decorative image out of its own lane and onto that lane as a background, which is what a full-bleed panel image usually wants to be. Only applies where a lane holds exactly one image widget and has no background of its own. The image widget is left in place and hidden rather than deleted, so the change can be undone by hand — nothing is destroyed.', 'acrossai-abilities-manager' );
	}

	protected function is_destructive(): bool {
		return true;
	}

	/**
	 * @param array<string,mixed> $model
	 * @param int                 $post_id
	 * @param string              $subtree_id
	 * @return array<string,mixed>
	 */
	protected function analyze( array $model, int $post_id, string $subtree_id ): array {
		$targets = array();

		foreach ( (array) $model['rows'] as $row ) {
			foreach ( (array) $row['lanes'] as $lane ) {
				$types = array_map( 'strval', (array) $lane['widget_types'] );
				if ( array( 'image' ) !== $types ) {
					continue;
				}

				$settings = (array) $lane['settings'];
				if ( ! empty( $settings['background_background'] ) || ! empty( $settings['background_image'] ) ) {
					continue;
				}

				$targets[] = (string) $lane['id'];
			}
		}

		if ( array() === $targets ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'No lane in scope holds a single image widget without already having a background of its own, so nothing was changed.', 'acrossai-abilities-manager' )
			);
		}

		// Read the image out of each target lane before writing, so the background is set
		// from the widget that is about to be hidden rather than from a half-edited tree.
		$images = array();
		\AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository::walk_tree(
			(array) $model['scoped_data'],
			static function ( array $element, array $path ) use ( &$images, $targets ): void {
				if ( 'widget' !== ( $element['elType'] ?? '' ) || 'image' !== ( $element['widgetType'] ?? '' ) ) {
					return;
				}
				foreach ( $path as $ancestor ) {
					if ( in_array( (string) $ancestor, $targets, true ) ) {
						$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
						if ( isset( $settings['image'] ) ) {
							$images[ (string) $ancestor ] = array(
								'image'     => $settings['image'],
								'widget_id' => (string) ( $element['id'] ?? '' ),
							);
						}
						return;
					}
				}
			}
		);

		if ( array() === $images ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'The candidate lanes hold image widgets with no image selected, so there is nothing to move to a background.', 'acrossai-abilities-manager' )
			);
		}

		$widget_ids = array_map( static fn( array $i ): string => $i['widget_id'], $images );

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $images, $widget_ids ): array {
				$id = (string) ( $element['id'] ?? '' );

				if ( isset( $images[ $id ] ) ) {
					$changes = Design_Mutator::set( $settings, 'background_background', 'classic' );
					$changes = array_merge( $changes, Design_Mutator::set( $settings, 'background_image', $images[ $id ]['image'] ) );
					$changes = array_merge( $changes, Design_Mutator::set( $settings, 'background_size', 'cover' ) );

					return $changes;
				}

				if ( in_array( $id, $widget_ids, true ) ) {
					// Hidden, not removed — every setting stays recoverable in the editor.
					return Design_Mutator::set( $settings, 'hide_desktop', 'hidden-desktop' );
				}

				return array();
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			__( 'Moved lone images onto their lane as a cover background and hid the original widgets rather than deleting them, so the change can be reversed by hand.', 'acrossai-abilities-manager' ),
			__( 'Nothing matched, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
