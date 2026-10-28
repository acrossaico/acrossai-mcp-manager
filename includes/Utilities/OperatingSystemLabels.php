<?php
/**
 * Operating-system slugs and their display labels.
 *
 * Extracted from `Public\Renderers\MCPClientsBlock` when Feature 099 gave the
 * setup-prompt builder the same need. Constitution §VI requires shared logic to
 * move to `includes/Utilities/` BEFORE its second use, and A9 says constants
 * read by two or more modules live here rather than on a sibling feature class.
 *
 * The labels are proper nouns and are deliberately NOT translated: "macOS",
 * "Windows" and "Linux" are the same in every locale, and translating them
 * would let a locale rename a path heading that must match what the operator
 * sees in their own file system.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Utilities
 */

namespace AcrossAI_MCP_Manager\Includes\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical OS slug → label map.
 *
 * Final: extension happens by filtering the data that uses it, never by
 * subclassing the constant holder (D36).
 */
final class OperatingSystemLabels {

	/**
	 * Supported OS slugs in display order, mapped to their human labels.
	 *
	 * Order is load-bearing: both the renderer and the prompt list paths in
	 * this sequence, so an operator comparing the two sees them line up.
	 *
	 * @var array<string, string>
	 */
	public const LABELS = array(
		'macos'   => 'macOS',
		'windows' => 'Windows',
		'linux'   => 'Linux',
	);

	/**
	 * Order a partial OS→path map by the canonical display order.
	 *
	 * Client classes declare only the operating systems they support, and they
	 * write that array in whatever order suits the class. Callers must not
	 * depend on that order.
	 *
	 * @param array<string, string> $paths OS slug => path. May be partial.
	 * @return array<string, string> Ordered, with empty values dropped.
	 */
	public static function order( array $paths ): array {
		$ordered = array();

		foreach ( self::LABELS as $slug => $label ) {
			if ( isset( $paths[ $slug ] ) && '' !== $paths[ $slug ] ) {
				$ordered[ $slug ] = $paths[ $slug ];
			}
		}

		return $ordered;
	}

	/**
	 * Human label for an OS slug, falling back to the slug itself.
	 *
	 * @param string $slug OS slug.
	 * @return string
	 */
	public static function label( string $slug ): string {
		return self::LABELS[ $slug ] ?? $slug;
	}
}
