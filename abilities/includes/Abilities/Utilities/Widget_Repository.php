<?php
/**
 * Feature 111 — the widget read/write boundary.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Everything this plugin does to widgets goes through here.
 *
 * A widget lives in two places at once. Its settings are in `widget_{id_base}`, keyed by instance
 * number; its placement is in `sidebars_widgets`, keyed by sidebar. Editing either directly is
 * possible and wrong for two different reasons:
 *
 *   - **Settings.** Each widget type sanitises its own input in `WP_Widget::update()` — the text
 *     widget runs its title through `sanitize_text_field()` and its body through `wp_kses_post()`
 *     for anyone without `unfiltered_html`; others cast, trim and validate. Note that an
 *     administrator on single-site DOES hold `unfiltered_html`, so raw markup is stored for them,
 *     exactly as it is from the widgets screen — going through `update()` inherits core's rules
 *     rather than inventing stricter ones. What it avoids is being the one path into a site's
 *     widgets that applies no sanitisation at all, and it lets a widget refuse an update outright.
 *   - **Placement.** `sidebars_widgets` is not purely a map of sidebars. It also carries
 *     `array_version`, and `wp_inactive_widgets` is a real key that is not a registered sidebar.
 *     Iterating it as though every key were a sidebar corrupts the option.
 *
 * `wp_set_sidebars_widgets()` restores `array_version` for us, so placement writes go through it and
 * through `wp_assign_widget_to_sidebar()` rather than `update_option()`.
 *
 * @since 0.0.34
 */
final class Widget_Repository {

	/**
	 * The pseudo-sidebar holding widgets that are configured but not displayed.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const INACTIVE = 'wp_inactive_widgets';

	/**
	 * Keys inside `sidebars_widgets` that are not sidebars.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	public const NON_SIDEBAR_KEYS = array( 'array_version' );

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The sidebar map, with the bookkeeping keys removed.
	 *
	 * @since  0.0.34
	 * @return array<string, array<int, string>>
	 */
	public static function placements(): array {
		$raw = wp_get_sidebars_widgets();
		$out = array();

		foreach ( (array) $raw as $sidebar => $widget_ids ) {
			if ( in_array( (string) $sidebar, self::NON_SIDEBAR_KEYS, true ) ) {
				continue;
			}

			$out[ (string) $sidebar ] = array_values( array_map( 'strval', (array) $widget_ids ) );
		}

		return $out;
	}

	/**
	 * Which sidebar a widget instance sits in, and where.
	 *
	 * @since  0.0.34
	 * @param  string $widget_id Instance id, e.g. "text-3".
	 * @return array{sidebar: string, position: int}|null
	 */
	public static function locate( string $widget_id ): ?array {
		foreach ( self::placements() as $sidebar => $widget_ids ) {
			$position = array_search( $widget_id, $widget_ids, true );

			if ( false !== $position ) {
				return array(
					'sidebar'  => (string) $sidebar,
					'position' => (int) $position,
				);
			}
		}

		return null;
	}

	/**
	 * Split an instance id into its type and number.
	 *
	 * @since  0.0.34
	 * @param  string $widget_id Instance id.
	 * @return array{id_base: string, number: int}|null
	 */
	public static function parse_id( string $widget_id ): ?array {
		if ( ! preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
			return null;
		}

		return array(
			'id_base' => (string) $matches[1],
			'number'  => (int) $matches[2],
		);
	}

	/**
	 * The WP_Widget object for a type, which is what knows how to sanitise its own settings.
	 *
	 * @since  0.0.34
	 * @param  string $id_base Widget type base, e.g. "text".
	 * @return \WP_Widget|null
	 */
	public static function widget_object( string $id_base ) {
		if ( ! isset( $GLOBALS['wp_widget_factory'] ) || ! is_object( $GLOBALS['wp_widget_factory'] ) ) {
			return null;
		}

		$factory = $GLOBALS['wp_widget_factory'];

		if ( ! method_exists( $factory, 'get_widget_object' ) ) {
			return null;
		}

		$object = $factory->get_widget_object( $id_base );

		return $object instanceof \WP_Widget ? $object : null;
	}

	/**
	 * Registered widget types, as rows.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function types(): array {
		$rows = array();

		if ( ! isset( $GLOBALS['wp_widget_factory'] ) || ! is_object( $GLOBALS['wp_widget_factory'] ) ) {
			return $rows;
		}

		foreach ( (array) $GLOBALS['wp_widget_factory']->widgets as $widget ) {
			if ( ! $widget instanceof \WP_Widget ) {
				continue;
			}

			$options = (array) $widget->widget_options;

			$rows[] = array(
				'id_base'     => (string) $widget->id_base,
				'name'        => (string) $widget->name,
				'description' => (string) ( $options['description'] ?? '' ),
				'class'       => get_class( $widget ),
				'instances'   => count( self::instances_of( (string) $widget->id_base ) ),
			);
		}

		usort(
			$rows,
			static fn( array $a, array $b ): int => strcmp( $a['id_base'], $b['id_base'] )
		);

		return $rows;
	}

	/**
	 * Stored instances of one widget type, keyed by number.
	 *
	 * `_multiwidget` is bookkeeping, not an instance, and must survive every write.
	 *
	 * @since  0.0.34
	 * @param  string $id_base Widget type base.
	 * @return array<int, array<string, mixed>>
	 */
	public static function instances_of( string $id_base ): array {
		$widget = self::widget_object( $id_base );

		$settings = null !== $widget
			? (array) $widget->get_settings()
			: (array) get_option( 'widget_' . $id_base, array() );

		unset( $settings['_multiwidget'] );

		$out = array();

		foreach ( $settings as $number => $instance ) {
			if ( is_numeric( $number ) ) {
				$out[ (int) $number ] = (array) $instance;
			}
		}

		return $out;
	}

	/**
	 * Describe one widget instance.
	 *
	 * @since  0.0.34
	 * @param  string $widget_id Instance id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function describe( string $widget_id ) {
		$parsed = self::parse_id( $widget_id );

		if ( null === $parsed ) {
			return new WP_Error(
				'invalid_widget_id',
				sprintf(
					/* translators: %s: supplied id */
					__( '"%s" is not a widget instance id. They look like "text-3" — a type followed by an instance number.', 'acrossai-abilities-manager' ),
					$widget_id
				)
			);
		}

		$instances = self::instances_of( $parsed['id_base'] );

		if ( ! array_key_exists( $parsed['number'], $instances ) ) {
			return new WP_Error(
				'unknown_widget',
				sprintf( /* translators: %s: widget id */ __( 'No widget instance "%s".', 'acrossai-abilities-manager' ), $widget_id )
			);
		}

		$location = self::locate( $widget_id );
		$widget   = self::widget_object( $parsed['id_base'] );

		return array(
			'widget_id' => $widget_id,
			'id_base'   => $parsed['id_base'],
			'number'    => $parsed['number'],
			'type_name' => null !== $widget ? (string) $widget->name : $parsed['id_base'],
			'sidebar'   => null !== $location ? $location['sidebar'] : '',
			'position'  => null !== $location ? $location['position'] : -1,
			'active'    => null !== $location && self::INACTIVE !== $location['sidebar'],
			'settings'  => $instances[ $parsed['number'] ],
		);
	}

	/**
	 * Write an instance's settings through the widget's own sanitiser.
	 *
	 * @since  0.0.34
	 * @param  string               $id_base  Widget type.
	 * @param  int                  $number   Instance number.
	 * @param  array<string, mixed> $settings New settings, merged over the old.
	 * @return array<string, mixed>|WP_Error The stored settings.
	 */
	public static function save_instance( string $id_base, int $number, array $settings ) {
		$widget = self::widget_object( $id_base );

		if ( null === $widget ) {
			return new WP_Error(
				'unknown_widget_type',
				sprintf(
					/* translators: %s: widget type */
					__( 'No registered widget type "%s". Its plugin or theme may be inactive.', 'acrossai-abilities-manager' ),
					$id_base
				)
			);
		}

		$all = (array) $widget->get_settings();
		$old = isset( $all[ $number ] ) ? (array) $all[ $number ] : array();
		$new = array_merge( $old, $settings );

		/*
		 * The widget sanitises its own input. The core HTML widget runs KSES here; others cast and
		 * trim. Skipping this would make these abilities the only unsanitised route into a site's
		 * widgets. A widget may also reject the update outright by returning false.
		 */
		$sanitised = $widget->update( $new, $old );

		if ( false === $sanitised ) {
			return new WP_Error(
				'settings_rejected',
				sprintf(
					/* translators: %s: widget type */
					__( 'The "%s" widget rejected those settings. Its own validation refused them; nothing was saved.', 'acrossai-abilities-manager' ),
					$id_base
				)
			);
		}

		$all[ $number ] = (array) $sanitised;

		$widget->save_settings( $all );

		$stored = self::instances_of( $id_base );

		return $stored[ $number ] ?? array();
	}

	/**
	 * The next free instance number for a widget type.
	 *
	 * @since  0.0.34
	 * @param  string $id_base Widget type.
	 * @return int
	 */
	public static function next_number( string $id_base ): int {
		$numbers = array_keys( self::instances_of( $id_base ) );

		return $numbers ? ( max( $numbers ) + 1 ) : 2;
	}

	/**
	 * Whether a sidebar id is real.
	 *
	 * The inactive store counts, because moving a widget there is a legitimate operation.
	 *
	 * @since  0.0.34
	 * @param  string $sidebar_id Sidebar id.
	 * @return bool
	 */
	public static function sidebar_exists( string $sidebar_id ): bool {
		if ( self::INACTIVE === $sidebar_id ) {
			return true;
		}

		if ( self::is_registered_sidebar( $sidebar_id ) ) {
			return true;
		}

		/*
		 * A sidebar can exist in the data without being registered by the current theme. Measured on
		 * a block-theme site: wp_registered_sidebars was empty while sidebars_widgets still held
		 * sidebar-1 and sidebar-2 with five widgets between them, left behind by a previous theme.
		 * Refusing those outright would make the whole suite inert on any block theme, so they are
		 * accepted and reported as orphaned — which is also how the widgets screen treats them.
		 */
		return array_key_exists( $sidebar_id, self::placements() );
	}

	/**
	 * Whether the active theme actually registers this sidebar.
	 *
	 * @since  0.0.34
	 * @param  string $sidebar_id Sidebar id.
	 * @return bool
	 */
	public static function is_registered_sidebar( string $sidebar_id ): bool {
		$registered = isset( $GLOBALS['wp_registered_sidebars'] ) ? (array) $GLOBALS['wp_registered_sidebars'] : array();

		return array_key_exists( $sidebar_id, $registered );
	}

	/**
	 * Registered sidebar ids, plus the inactive store.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public static function sidebar_ids(): array {
		$registered = isset( $GLOBALS['wp_registered_sidebars'] ) ? array_keys( (array) $GLOBALS['wp_registered_sidebars'] ) : array();
		$ids        = array_merge( $registered, array_keys( self::placements() ), array( self::INACTIVE ) );

		return array_values( array_unique( array_map( 'strval', $ids ) ) );
	}

	/**
	 * Place a widget in a sidebar at a given position.
	 *
	 * `wp_assign_widget_to_sidebar()` only appends, so ordering is applied afterwards through
	 * `wp_set_sidebars_widgets()`, which is also what restores `array_version`.
	 *
	 * @since  0.0.34
	 * @param  string   $widget_id  Instance id.
	 * @param  string   $sidebar_id Target sidebar.
	 * @param  int|null $position   0-based position, or null to append.
	 * @return true|WP_Error
	 */
	public static function place( string $widget_id, string $sidebar_id, ?int $position = null ) {
		if ( ! self::sidebar_exists( $sidebar_id ) ) {
			return new WP_Error(
				'unknown_sidebar',
				sprintf(
					/* translators: 1: sidebar id, 2: comma-separated known ids */
					__( 'No sidebar "%1$s". This theme registers: %2$s.', 'acrossai-abilities-manager' ),
					$sidebar_id,
					implode( ', ', self::sidebar_ids() )
				)
			);
		}

		wp_assign_widget_to_sidebar( $widget_id, $sidebar_id );

		if ( null === $position ) {
			self::normalise_placements();

			return true;
		}

		$sidebars = wp_get_sidebars_widgets();
		$current  = array_values( array_filter( (array) ( $sidebars[ $sidebar_id ] ?? array() ), static fn( $id ): bool => $id !== $widget_id ) );
		$position = max( 0, min( count( $current ), $position ) );

		array_splice( $current, $position, 0, array( $widget_id ) );

		$sidebars[ $sidebar_id ] = $current;

		wp_set_sidebars_widgets( $sidebars );

		return true;
	}

	/**
	 * Rewrite the option with every sidebar's list reindexed.
	 *
	 * `wp_assign_widget_to_sidebar()` removes a widget from its old sidebar with `unset()` and does
	 * not reindex, so the stored array ends up sparse — measured: a sidebar left holding keys 0, 1
	 * and 3. WordPress tolerates that, but it is a core option other code reads, and anything doing
	 * positional arithmetic on the raw value would be wrong. Cheap to keep tidy.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private static function normalise_placements(): void {
		$sidebars = wp_get_sidebars_widgets();

		foreach ( $sidebars as $sidebar => $widget_ids ) {
			if ( in_array( (string) $sidebar, self::NON_SIDEBAR_KEYS, true ) ) {
				continue;
			}

			$sidebars[ $sidebar ] = array_values( (array) $widget_ids );
		}

		wp_set_sidebars_widgets( $sidebars );
	}

	/**
	 * Set the exact order of a sidebar.
	 *
	 * @since  0.0.34
	 * @param  string   $sidebar_id Sidebar id.
	 * @param  string[] $widget_ids The widgets, in the order wanted.
	 * @return true|WP_Error
	 */
	public static function reorder( string $sidebar_id, array $widget_ids ) {
		if ( ! self::sidebar_exists( $sidebar_id ) ) {
			return new WP_Error(
				'unknown_sidebar',
				sprintf(
					/* translators: 1: sidebar id, 2: comma-separated known ids */
					__( 'No sidebar "%1$s". This theme registers: %2$s.', 'acrossai-abilities-manager' ),
					$sidebar_id,
					implode( ', ', self::sidebar_ids() )
				)
			);
		}

		$current = self::placements()[ $sidebar_id ] ?? array();
		$wanted  = array_values( array_map( 'strval', $widget_ids ) );

		sort( $current );
		$check = $wanted;
		sort( $check );

		/*
		 * Refuse a partial list. Accepting one would silently drop every widget the caller left out,
		 * which looks like a reorder and is a deletion.
		 */
		if ( $current !== $check ) {
			return new WP_Error(
				'incomplete_order',
				sprintf(
					/* translators: 1: sidebar id, 2: comma-separated current widget ids */
					__( 'The order must name every widget currently in "%1$s", and only those. It holds: %2$s.', 'acrossai-abilities-manager' ),
					$sidebar_id,
					implode( ', ', $current ) ?: __( 'nothing', 'acrossai-abilities-manager' )
				)
			);
		}

		$sidebars                = wp_get_sidebars_widgets();
		$sidebars[ $sidebar_id ] = $wanted;

		wp_set_sidebars_widgets( $sidebars );

		return true;
	}

	/**
	 * Remove an instance entirely: its placement and its stored settings.
	 *
	 * @since  0.0.34
	 * @param  string $widget_id Instance id.
	 * @return true|WP_Error
	 */
	public static function delete( string $widget_id ) {
		$parsed = self::parse_id( $widget_id );

		if ( null === $parsed ) {
			return new WP_Error( 'invalid_widget_id', __( 'That is not a widget instance id.', 'acrossai-abilities-manager' ) );
		}

		// Placement first: passing an empty sidebar removes it from wherever it is.
		wp_assign_widget_to_sidebar( $widget_id, '' );
		self::normalise_placements();

		$widget = self::widget_object( $parsed['id_base'] );

		if ( null !== $widget ) {
			$all = (array) $widget->get_settings();
			unset( $all[ $parsed['number'] ] );
			$widget->save_settings( $all );
		}

		return true;
	}

	/**
	 * How widgets are managed on this site, and by what.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function management_status(): array {
		$block_editor = function_exists( 'wp_use_widgets_block_editor' ) ? (bool) wp_use_widgets_block_editor() : true;
		$block_theme  = function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false;
		$placements   = self::placements();

		$block_widgets   = 0;
		$classic_widgets = 0;

		foreach ( $placements as $sidebar => $widget_ids ) {
			foreach ( $widget_ids as $widget_id ) {
				$parsed = self::parse_id( (string) $widget_id );

				if ( null !== $parsed && 'block' === $parsed['id_base'] ) {
					++$block_widgets;
					continue;
				}

				++$classic_widgets;
			}
		}

		$orphaned = array();

		foreach ( array_keys( $placements ) as $sidebar ) {
			if ( self::INACTIVE !== $sidebar && ! self::is_registered_sidebar( (string) $sidebar ) ) {
				$orphaned[] = (string) $sidebar;
			}
		}

		return array(
			'registered_sidebars'          => count( isset( $GLOBALS['wp_registered_sidebars'] ) ? (array) $GLOBALS['wp_registered_sidebars'] : array() ),
			'orphaned_sidebars'            => $orphaned,
			'block_editor_manages_widgets' => $block_editor,
			'classic_widgets_plugin_active' => in_array( 'classic-widgets/classic-widgets.php', (array) get_option( 'active_plugins', array() ), true ),
			'block_theme'                  => $block_theme,
			'sidebars'                     => count( $placements ),
			'block_widgets'                => $block_widgets,
			'classic_widgets'              => $classic_widgets,
			'inactive_widgets'             => count( $placements[ self::INACTIVE ] ?? array() ),
		);
	}
}
