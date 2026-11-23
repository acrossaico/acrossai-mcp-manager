<?php
/**
 * Feature 067 — Elementor widget schema summariser.
 *
 * Read-only wrapper around Elementor's WidgetsManager. Fetches a widget
 * type and returns its controls in a schema-safe format suitable for
 * ability response payloads.
 *
 * All methods are pure static — no instantiation.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Elementor
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Widget-schema helpers.
 */
final class Widget_Controls {

	/**
	 * Fetch a registered widget by its type name.
	 *
	 * @param string $widget_type Widget slug (e.g. 'heading', 'nav-menu').
	 * @return \Elementor\Widget_Base|null
	 */
	public static function get_type( string $widget_type ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}
		$instance        = \Elementor\Plugin::$instance;
		$widgets_manager = $instance->widgets_manager ?? null;
		if ( null === $widgets_manager || ! method_exists( $widgets_manager, 'get_widget_types' ) ) {
			return null;
		}
		$widget = $widgets_manager->get_widget_types( $widget_type );
		if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_controls' ) ) {
			return null;
		}
		return $widget;
	}

	/**
	 * Summarise a widget's controls into schema-safe response entries.
	 *
	 * @param array<string, array<string, mixed>> $controls Raw Elementor controls.
	 * @param string                              $search   Optional case-insensitive filter across name/label/description/section/type.
	 * @return array<int, array<string, mixed>>
	 */
	public static function summarize( array $controls, string $search = '' ): array {
		$search = strtolower( trim( $search ) );
		$out    = array();
		foreach ( $controls as $name => $control ) {
			if ( ! is_array( $control ) ) {
				continue;
			}
			$entry = array(
				'name'    => (string) $name,
				'label'   => isset( $control['label'] ) ? (string) $control['label'] : '',
				'type'    => isset( $control['type'] ) ? (string) $control['type'] : '',
				'section' => isset( $control['section'] ) ? (string) $control['section'] : '',
			);
			if ( isset( $control['description'] ) && '' !== $control['description'] ) {
				$entry['description'] = (string) $control['description'] ;
			}
			if ( array_key_exists( 'default', $control ) ) {
				$entry['default'] = $control['default'];
			}
			if ( '' !== $search && ! self::entry_matches_search( $entry, $search ) ) {
				continue;
			}
			$out[] = $entry;
		}
		return $out;
	}

	/**
	 * Full widget summary: name + title + icon + categories + controls.
	 *
	 * @param string $widget_type Widget slug.
	 * @param string $search      Optional controls filter.
	 * @return array<string, mixed>|null
	 */
	public static function full_summary( string $widget_type, string $search = '' ): ?array {
		$widget = self::get_type( $widget_type );
		if ( null === $widget ) {
			return null;
		}
		$controls = method_exists( $widget, 'get_controls' ) ? $widget->get_controls() : array();
		$controls = is_array( $controls ) ? $controls : array();

		return array(
			'name'       => method_exists( $widget, 'get_name' ) ? (string) $widget->get_name() : $widget_type,
			'title'      => method_exists( $widget, 'get_title' ) ? (string) $widget->get_title() : $widget_type,
			'icon'       => method_exists( $widget, 'get_icon' ) ? (string) $widget->get_icon() : '',
			'categories' => method_exists( $widget, 'get_categories' ) ? (array) $widget->get_categories() : array(),
			'controls'   => self::summarize( $controls, $search ),
		);
	}

	/**
	 * True when the summary entry matches the search term (case-insensitive).
	 *
	 * @param array<string, mixed> $entry
	 * @param string               $search
	 * @return bool
	 */
	private static function entry_matches_search( array $entry, string $search ): bool {
		foreach ( array( 'name', 'label', 'description', 'section', 'type' ) as $field ) {
			$value = isset( $entry[ $field ] ) ? strtolower( (string) $entry[ $field ] ) : '';
			if ( '' !== $value && false !== strpos( $value, $search ) ) {
				return true;
			}
		}
		return false;
	}
}
