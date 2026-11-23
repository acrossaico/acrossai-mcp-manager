<?php
/**
 * Feature 067 / issue #243 — sync repeated widgets onto their majority styling.
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
 * Applies the MAJORITY styling of a repeated widget to its outliers.
 *
 * Content keys are excluded by name, not by guesswork: syncing that copied a button's
 * text across a page would replace what each one says, which is the one thing a caller
 * asking about styling certainly did not want.
 *
 * Mutating: confirm-gated by {@see Base_Audit_Ability}, writes through
 * {@see Design_Mutator}, and reports every setting it changed with its previous value.
 */
class Sync_Component_Variant extends Base_Audit_Ability {

	protected function audit_slug(): string {
		return 'sync-component-variant';
	}

	protected function audit_label(): string {
		return __( 'Sync Elementor Component Variant', 'acrossai-abilities-manager' );
	}

	protected function audit_description(): string {
		return __( 'Make repeated instances of the same widget type agree on their styling, by finding the most common variant among them and applying it to the rest. Useful where the same button or icon box has drifted apart across a page. Only styling settings are copied — never the text, link, image or any other content, so each instance keeps saying what it said.', 'acrossai-abilities-manager' );
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
		// Content keys never travel. Everything else about a repeated widget is styling.
		$content_keys = array( 'title', 'text', 'editor', 'link', 'url', 'image', 'selected_icon', 'icon', 'alt', 'caption', 'description_text', 'html' );

		$instances = array();
		\AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor\Document_Repository::walk_tree(
			(array) $model['scoped_data'],
			static function ( array $element ) use ( &$instances, $content_keys ): void {
				if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
					return;
				}
				$type = (string) ( $element['widgetType'] ?? '' );
				if ( '' === $type ) {
					return;
				}

				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$style    = array_diff_key( $settings, array_flip( $content_keys ) );
				ksort( $style );

				$instances[ $type ][] = array(
					'id'          => (string) ( $element['id'] ?? '' ),
					'style'       => $style,
					'fingerprint' => (string) wp_json_encode( $style ),
				);
			}
		);

		$plan = array();
		foreach ( $instances as $group ) {
			if ( count( $group ) < 3 ) {
				continue;
			}

			$tallies = array_count_values( array_map( static fn( array $i ): string => $i['fingerprint'], $group ) );
			arsort( $tallies );
			$winner = (string) array_key_first( $tallies );

			// A majority that is itself a minority is not a house style — it is a page where
			// every instance differs, and picking one would be arbitrary.
			if ( $tallies[ $winner ] < 2 || $tallies[ $winner ] / count( $group ) < 0.5 ) {
				continue;
			}

			$template = array();
			foreach ( $group as $instance ) {
				if ( $instance['fingerprint'] === $winner ) {
					$template = $instance['style'];
					break;
				}
			}

			foreach ( $group as $instance ) {
				if ( $instance['fingerprint'] !== $winner ) {
					$plan[ $instance['id'] ] = $template;
				}
			}
		}

		if ( array() === $plan ) {
			return Design_Mutator::report(
				array( 'changed' => array(), 'applied' => false ),
				'',
				__( 'No widget type in scope repeats often enough with a clear majority styling, so nothing was changed.', 'acrossai-abilities-manager' )
			);
		}

		$result = Design_Mutator::edit(
			$model,
			static function ( array &$settings, array $element ) use ( $plan ): array {
				$id = (string) ( $element['id'] ?? '' );
				if ( ! isset( $plan[ $id ] ) ) {
					return array();
				}

				$changes = array();
				foreach ( $plan[ $id ] as $key => $value ) {
					$changes = array_merge( $changes, Design_Mutator::set( $settings, (string) $key, $value ) );
				}

				return $changes;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return Design_Mutator::report(
			$result,
			__( 'Synced outlying widget instances onto the majority styling for their type. Text, links and images were not touched.', 'acrossai-abilities-manager' ),
			__( 'Every repeated widget already shared one styling, so nothing was changed.', 'acrossai-abilities-manager' )
		);
	}
}
