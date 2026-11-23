<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only helpers around WP_Block_Type_Registry. Normalises WP_Block_Type
 * objects into JSON-shaped responses, classifies source (core / plugin /
 * theme / custom), and slices out individual detail sections.
 *
 * No file or DB IO — every method is a thin wrapper over the registry.
 */
final class Block_Info {

	public const CATEGORIES = array( 'text', 'media', 'design', 'widgets', 'theme', 'embed' );

	public const SOURCES = array( 'core', 'plugin', 'theme', 'custom' );

	public const SECTIONS = array( 'settings', 'supports', 'attributes', 'example', 'variations', 'styles', 'transforms' );

	/**
	 * Registry available.
	 *
	 * @return bool
	 */
	public static function registry_available(): bool {
		return class_exists( '\WP_Block_Type_Registry' );
	}

	/**
	 * @return WP_Block_Type[]
	 */
	public static function all_blocks(): array {
		if ( ! self::registry_available() ) {
			return array();
		}
		$registered = \WP_Block_Type_Registry::get_instance()->get_all_registered();
		return is_array( $registered ) ? $registered : array();
	}

	/**
	 * Get block.
	 *
	 * @param string $name
	 * @return ?\WP_Block_Type
	 */
	public static function get_block( string $name ): ?\WP_Block_Type {
		if ( ! self::registry_available() ) {
			return null;
		}
		$block = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
		return $block instanceof \WP_Block_Type ? $block : null;
	}

	/**
	 * Valid category.
	 *
	 * @param string $category
	 * @return bool
	 */
	public static function valid_category( string $category ): bool {
		return in_array( $category, self::CATEGORIES, true );
	}

	/**
	 * Valid source.
	 *
	 * @param string $source
	 * @return bool
	 */
	public static function valid_source( string $source ): bool {
		return in_array( $source, self::SOURCES, true );
	}

	/**
	 * Valid section.
	 *
	 * @param string $section
	 * @return bool
	 */
	public static function valid_section( string $section ): bool {
		return in_array( $section, self::SECTIONS, true );
	}

	/**
	 * Best-effort source classification. WordPress doesn't track which plugin
	 * or theme registered a block, so we infer from the namespace and
	 * textdomain. core/* is the only fully reliable signal.
	 */
	public static function classify_source( \WP_Block_Type $block ): string {
		$name = (string) $block->name;
		if ( 0 === strpos( $name, 'core/' ) ) {
			return 'core';
		}

		$namespace = (string) strstr( $name, '/', true );
		if ( '' === $namespace ) {
			return 'custom';
		}

		if ( $namespace === (string) get_stylesheet() || $namespace === (string) get_template() ) {
			return 'theme';
		}

		if ( self::namespace_matches_plugin( $namespace ) ) {
			return 'plugin';
		}

		// Fall back to textdomain matching.
		$textdomain = isset( $block->textdomain ) ? (string) $block->textdomain : '';
		if ( '' !== $textdomain ) {
			if ( $textdomain === (string) get_stylesheet() || $textdomain === (string) get_template() ) {
				return 'theme';
			}
			if ( self::namespace_matches_plugin( $textdomain ) ) {
				return 'plugin';
			}
		}

		return 'custom';
	}

	/**
	 * Namespace matches plugin.
	 *
	 * @param string $candidate
	 * @return bool
	 */
	private static function namespace_matches_plugin( string $candidate ): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $rel ) {
			$slug = explode( '/', $rel )[0] ?? '';
			if ( $slug === $candidate ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Short list-card form: name, title, description, category, icon, source.
	 */
	public static function summary( \WP_Block_Type $block ): array {
		return array(
			'name'        => (string) $block->name,
			'title'       => (string) $block->title,
			'description' => (string) $block->description,
			'category'    => (string) $block->category,
			'icon'        => self::format_icon( $block->icon ?? null ),
			'source'      => self::classify_source( $block ),
			'keywords'    => self::as_array( $block->keywords ?? array() ),
		);
	}

	/**
	 * Full read-block response — every section the registry can expose.
	 */
	public static function full( \WP_Block_Type $block ): array {
		return array(
			'settings'   => self::section_settings( $block ),
			'supports'   => self::section_supports( $block ),
			'attributes' => self::section_attributes( $block ),
			'example'    => self::section_example( $block ),
			'variations' => self::section_variations( $block ),
			'styles'     => self::section_styles( $block ),
			'transforms' => self::section_transforms( $block ),
		);
	}

	/**
	 * Returns one section's data plus a presence flag so callers can render
	 * "no variations registered for this block" cleanly (Scenarios 20–23).
	 *
	 * @return array{section: string, available: bool, data: mixed}
	 */
	public static function section( \WP_Block_Type $block, string $section ): array {
		$section = strtolower( trim( $section ) );
		switch ( $section ) {
			case 'settings':
				$data = self::section_settings( $block );
				return array(
					'section'   => 'settings',
					'available' => true,
					'data'      => $data,
				);

			case 'supports':
				$data = self::section_supports( $block );
				return array(
					'section'   => 'supports',
					'available' => ! empty( $data ),
					'data'      => (object) $data,
				);

			case 'attributes':
				$data = self::section_attributes( $block );
				return array(
					'section'   => 'attributes',
					'available' => ! empty( $data ),
					'data'      => (object) $data,
				);

			case 'example':
				$data = self::section_example( $block );
				return array(
					'section'   => 'example',
					'available' => null !== $data,
					'data'      => null === $data ? new \stdClass() : (object) $data,
				);

			case 'variations':
				$data = self::section_variations( $block );
				return array(
					'section'   => 'variations',
					'available' => ! empty( $data ),
					'data'      => $data,
				);

			case 'styles':
				$data = self::section_styles( $block );
				return array(
					'section'   => 'styles',
					'available' => ! empty( $data ),
					'data'      => $data,
				);

			case 'transforms':
				$data = self::section_transforms( $block );
				return array(
					'section'   => 'transforms',
					'available' => ! empty( $data ),
					'data'      => (object) $data,
				);
		}

		return array(
			'section'   => $section,
			'available' => false,
			'data'      => null,
		);
	}

	// -------------------------------------------------------------------------
	// Section builders
	// -------------------------------------------------------------------------

	/**
	 * Section settings.
	 *
	 * @param \WP_Block_Type $block
	 * @return array
	 */
	private static function section_settings( \WP_Block_Type $block ): array {
		return array(
			'name'        => (string) $block->name,
			'title'       => (string) $block->title,
			'description' => (string) $block->description,
			'category'    => (string) $block->category,
			'icon'        => self::format_icon( $block->icon ?? null ),
			'keywords'    => self::as_array( $block->keywords ?? array() ),
			'textdomain'  => isset( $block->textdomain ) ? (string) $block->textdomain : '',
			'api_version' => isset( $block->api_version ) ? (int) $block->api_version : 0,
			'parent'      => self::as_array( $block->parent ?? array() ),
			'ancestor'    => self::as_array( $block->ancestor ?? array() ),
			'source'      => self::classify_source( $block ),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function section_supports( \WP_Block_Type $block ): array {
		return self::as_assoc( $block->supports ?? array() );
	}

	/**
	 * Normalises attribute schemas into a stable JSON shape.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function section_attributes( \WP_Block_Type $block ): array {
		$attributes = self::as_assoc( $block->attributes ?? array() );
		$out        = array();
		foreach ( $attributes as $name => $schema ) {
			$schema = is_array( $schema ) ? $schema : array();
			$entry  = array(
				'name'    => (string) $name,
				'type'    => isset( $schema['type'] ) ? $schema['type'] : null,
				'default' => array_key_exists( 'default', $schema ) ? $schema['default'] : null,
				'source'  => isset( $schema['source'] ) ? (string) $schema['source'] : null,
			);
			if ( isset( $schema['selector'] ) ) {
				$entry['selector'] = (string) $schema['selector'];
			}
			if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
				$entry['enum'] = array_values( $schema['enum'] );
			}
			if ( isset( $schema['attribute'] ) ) {
				$entry['attribute'] = (string) $schema['attribute'];
			}
			if ( isset( $schema['query'] ) ) {
				$entry['query'] = $schema['query'];
			}
			$out[ (string) $name ] = $entry;
		}
		return $out;
	}

	/**
	 * @return array|null Null when no example is registered (Scenario 20).
	 */
	private static function section_example( \WP_Block_Type $block ): ?array {
		$example = $block->example ?? null;
		if ( null === $example ) {
			return null;
		}
		return is_array( $example ) ? $example : array( 'value' => $example );
	}

	/**
	 * Section variations.
	 *
	 * @param \WP_Block_Type $block
	 * @return array
	 */
	private static function section_variations( \WP_Block_Type $block ): array {
		$variations = $block->variations ?? array();
		if ( ! is_array( $variations ) ) {
			return array();
		}
		$out = array();
		foreach ( $variations as $variation ) {
			if ( ! is_array( $variation ) ) {
				continue;
			}
			$out[] = array(
				'name'        => (string) ( $variation['name'] ?? '' ),
				'title'       => (string) ( $variation['title'] ?? '' ),
				'description' => (string) ( $variation['description'] ?? '' ),
				'icon'        => isset( $variation['icon'] ) ? self::format_icon( $variation['icon'] ) : null,
				'isDefault'   => ! empty( $variation['isDefault'] ),
				'attributes'  => isset( $variation['attributes'] ) && is_array( $variation['attributes'] ) ? $variation['attributes'] : array(),
				'innerBlocks' => isset( $variation['innerBlocks'] ) && is_array( $variation['innerBlocks'] ) ? $variation['innerBlocks'] : array(),
				'scope'       => isset( $variation['scope'] ) && is_array( $variation['scope'] ) ? array_values( $variation['scope'] ) : array(),
			);
		}
		return $out;
	}

	/**
	 * Section styles.
	 *
	 * @param \WP_Block_Type $block
	 * @return array
	 */
	private static function section_styles( \WP_Block_Type $block ): array {
		$styles = $block->styles ?? array();
		if ( ! is_array( $styles ) ) {
			return array();
		}
		$out = array();
		foreach ( $styles as $style ) {
			if ( ! is_array( $style ) ) {
				continue;
			}
			$out[] = array(
				'name'      => (string) ( $style['name'] ?? '' ),
				'label'     => (string) ( $style['label'] ?? '' ),
				'isDefault' => ! empty( $style['isDefault'] ),
			);
		}
		return $out;
	}

	/**
	 * Server-side transforms (rarely populated — most live in JS).
	 * Returns { from: [...], to: [...] } when present.
	 */
	private static function section_transforms( \WP_Block_Type $block ): array {
		$transforms = property_exists( $block, 'transforms' ) ? $block->transforms : null;
		if ( ! is_array( $transforms ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'from', 'to' ) as $direction ) {
			if ( isset( $transforms[ $direction ] ) && is_array( $transforms[ $direction ] ) ) {
				$out[ $direction ] = array_values( $transforms[ $direction ] );
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Filter helpers used by List
	// -------------------------------------------------------------------------

	/**
	 * Matches keyword.
	 *
	 * @param \WP_Block_Type $block
	 * @param string $keyword
	 * @return bool
	 */
	public static function matches_keyword( \WP_Block_Type $block, string $keyword ): bool {
		$needle = strtolower( trim( $keyword ) );
		if ( '' === $needle ) {
			return true;
		}
		$haystacks = array(
			(string) $block->name,
			(string) $block->title,
			(string) $block->description,
		);
		foreach ( self::as_array( $block->keywords ?? array() ) as $kw ) {
			$haystacks[] = (string) $kw;
		}
		foreach ( $haystacks as $hay ) {
			if ( false !== strpos( strtolower( $hay ), $needle ) ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Internal utilities
	// -------------------------------------------------------------------------

	/**
	 * Format icon.
	 *
	 * @param mixed $icon
	 */
	private static function format_icon( $icon ) {
		if ( null === $icon || '' === $icon ) {
			return null;
		}
		if ( is_string( $icon ) ) {
			return array(
				'type'  => 'string',
				'value' => $icon,
			);
		}
		if ( is_array( $icon ) ) {
			return array(
				'type'       => 'object',
				'src'        => isset( $icon['src'] ) && is_string( $icon['src'] ) ? $icon['src'] : null,
				'background' => isset( $icon['background'] ) ? (string) $icon['background'] : null,
				'foreground' => isset( $icon['foreground'] ) ? (string) $icon['foreground'] : null,
			);
		}
		return array( 'type' => 'unknown' );
	}

	/**
	 * @return array<int, mixed>
	 */
	private static function as_array( $value ): array {
		if ( is_array( $value ) ) {
			return array_values( $value );
		}
		if ( null === $value || '' === $value ) {
			return array();
		}
		return array( $value );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function as_assoc( $value ): array {
		return is_array( $value ) ? $value : array();
	}
}
