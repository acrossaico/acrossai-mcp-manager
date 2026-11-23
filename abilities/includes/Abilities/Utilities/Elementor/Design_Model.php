<?php
/**
 * Feature 067 / issue #243 — a normalised view of an Elementor document.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Elementor
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses an Elementor tree once into the shape the design audits actually reason over.
 *
 * Every audit previously had to re-derive the same handful of facts — how many lanes a
 * row has, what ratio they sit in, which widgets are in it, whether two rows look the
 * same. Doing that 27 times would have produced 27 slightly different definitions of
 * "a row", which is how design tools end up disagreeing with themselves.
 *
 * Elementor has two layout generations and both are live on real sites: the legacy
 * section > column > widget nesting, and the newer flex/grid container. They are
 * normalised to the same "row with lanes" vocabulary here so an audit never has to
 * branch on which era built the page.
 */
final class Design_Model {

	/**
	 * Widgets that are furniture rather than content — repeated appearances of these
	 * are a signal, repeated headings are not.
	 */
	public const FURNITURE_WIDGETS = array( 'button', 'divider', 'spacer', 'icon', 'icon-box', 'image-box' );

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Build the model for a post, optionally scoped to one subtree.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $subtree_id Element id to scope to, or '' for the whole document.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function build( int $post_id, string $subtree_id = '' ) {
		$document = Document_Repository::load_document( $post_id, 'read' );
		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$data = isset( $document['data'] ) && is_array( $document['data'] ) ? $document['data'] : array();

		if ( '' !== $subtree_id ) {
			$found = Document_Repository::find_element_by_id( $data, $subtree_id );
			if ( null === $found ) {
				return new WP_Error(
					'subtree_not_found',
					sprintf(
						/* translators: 1: element id, 2: post id */
						__( 'No element with id "%1$s" exists on post %2$d.', 'acrossai-abilities-manager' ),
						$subtree_id,
						$post_id
					)
				);
			}
			$element = isset( $found['element'] ) && is_array( $found['element'] ) ? $found['element'] : array();
			$data    = array( $element );
		}

		$model = self::model( $data, $post_id, $subtree_id );

		// The mutating abilities need the whole document to write back, not just the
		// slice they were scoped to, so both are carried.
		$model['scoped_data'] = $data;
		$model['document']    = isset( $document['data'] ) && is_array( $document['data'] ) ? $document['data'] : array();

		return $model;
	}

	/**
	 * Build the model from an already-loaded tree.
	 *
	 * Separate from build() so the audits can be unit-tested against a literal tree
	 * without a database.
	 *
	 * @param array<int,array<string,mixed>> $data       Elementor elements.
	 * @param int                            $post_id    Post id, for reporting only.
	 * @param string                         $subtree_id Subtree scope, for reporting only.
	 * @return array<string,mixed>
	 */
	public static function model( array $data, int $post_id = 0, string $subtree_id = '' ): array {
		$rows    = array();
		$widgets = array();

		foreach ( $data as $top ) {
			if ( ! is_array( $top ) ) {
				continue;
			}
			$rows[] = self::row( $top );
		}

		Document_Repository::walk_tree(
			$data,
			static function ( array $element ) use ( &$widgets ): void {
				if ( 'widget' !== ( $element['elType'] ?? '' ) ) {
					return;
				}
				$widgets[] = array(
					'id'   => (string) ( $element['id'] ?? '' ),
					'type' => (string) ( $element['widgetType'] ?? '' ),
				);
			}
		);

		$types = array_map( static fn( array $w ): string => $w['type'], $widgets );

		return array(
			'post_id'      => $post_id,
			'subtree_id'   => $subtree_id,
			'scoped_data'  => $data,
			'document'     => $data,
			'rows'         => $rows,
			'row_count'    => count( $rows ),
			'widgets'      => $widgets,
			'widget_count' => count( $widgets ),
			'widget_types' => array_count_values( array_filter( $types ) ),
			'empty'        => array() === $rows,
		);
	}

	/**
	 * Normalise one top-level element into a row.
	 *
	 * @param array<string,mixed> $element Top-level element.
	 * @return array<string,mixed>
	 */
	private static function row( array $element ): array {
		$type     = (string) ( $element['elType'] ?? '' );
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? array_values( $element['elements'] ) : array();

		$lanes = array();
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			// A legacy section's children are columns; a container's children may be
			// nested containers acting as lanes, or widgets sitting directly in it.
			$child_type = (string) ( $child['elType'] ?? '' );
			if ( 'column' !== $child_type && 'container' !== $child_type ) {
				continue;
			}
			$lanes[] = self::lane( $child );
		}

		$widget_types = array();
		Document_Repository::walk_tree(
			array( $element ),
			static function ( array $node ) use ( &$widget_types ): void {
				if ( 'widget' === ( $node['elType'] ?? '' ) ) {
					$widget_types[] = (string) ( $node['widgetType'] ?? '' );
				}
			}
		);

		$ratio = array_map(
			static fn( array $lane ): int => (int) round( (float) $lane['size'] ),
			$lanes
		);

		return array(
			'id'            => (string) ( $element['id'] ?? '' ),
			'el_type'       => $type,
			'lane_count'    => count( $lanes ),
			'lanes'         => $lanes,
			// Canonical "50/50", "33/33/33", "70/30" — the string two rows are compared
			// on when asking whether a page repeats a shape.
			'ratio'         => implode( '/', $ratio ),
			'widget_types'  => array_values( array_filter( $widget_types ) ),
			'widget_count'  => count( array_filter( $widget_types ) ),
			'has_background' => self::has_background( $settings ),
			'settings'      => $settings,
		);
	}

	/**
	 * Normalise one column or lane container.
	 *
	 * @param array<string,mixed> $child Column or container element.
	 * @return array<string,mixed>
	 */
	private static function lane( array $child ): array {
		$settings = isset( $child['settings'] ) && is_array( $child['settings'] ) ? $child['settings'] : array();

		// Legacy columns carry _column_size as a percentage. Containers carry a width
		// control whose shape varies by Elementor version, so the percentage is read
		// where present and an equal share assumed otherwise — an unknown width is not
		// evidence of imbalance.
		$size = null;
		if ( isset( $settings['_column_size'] ) && is_numeric( $settings['_column_size'] ) ) {
			$size = (float) $settings['_column_size'];
		} elseif ( isset( $settings['width']['size'] ) && is_numeric( $settings['width']['size'] ) ) {
			$size = (float) $settings['width']['size'];
		}

		$widgets = array();
		Document_Repository::walk_tree(
			array( $child ),
			static function ( array $node ) use ( &$widgets ): void {
				if ( 'widget' === ( $node['elType'] ?? '' ) ) {
					$widgets[] = (string) ( $node['widgetType'] ?? '' );
				}
			}
		);

		return array(
			'id'           => (string) ( $child['id'] ?? '' ),
			'size'         => null === $size ? 0.0 : $size,
			'size_known'   => null !== $size,
			'widget_types' => array_values( array_filter( $widgets ) ),
			'widget_count' => count( array_filter( $widgets ) ),
			'settings'     => $settings,
		);
	}

	/**
	 * Whether an element carries a visible background treatment.
	 *
	 * @param array<string,mixed> $settings Element settings.
	 * @return bool
	 */
	private static function has_background( array $settings ): bool {
		foreach ( array( 'background_background', 'background_color', 'background_image' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fill in equal lane sizes where Elementor stored none.
	 *
	 * A container that never had its width controls touched reports nothing, which is
	 * not the same as reporting zero. Audits that compare ratios need the implied
	 * equal split rather than a row that looks collapsed.
	 *
	 * @param array<string,mixed> $row Row from the model.
	 * @return float[] Lane sizes as percentages.
	 */
	public static function lane_sizes( array $row ): array {
		$lanes = isset( $row['lanes'] ) && is_array( $row['lanes'] ) ? $row['lanes'] : array();
		if ( array() === $lanes ) {
			return array();
		}

		$known = array_filter( $lanes, static fn( array $l ): bool => ! empty( $l['size_known'] ) );
		if ( array() === $known ) {
			$equal = 100 / count( $lanes );
			return array_fill( 0, count( $lanes ), $equal );
		}

		return array_map( static fn( array $l ): float => (float) $l['size'], $lanes );
	}
}
