<?php
/**
 * Feature 074 — filter-extensible QA rule registry for the block content layer.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Rule registry backing validate-content, audit-content, evaluate-design, and
 * evaluate-copy. Rules are pure callables that receive the parsed block tree
 * and return an issue list. Other plugins can extend via the
 * `acrossai_block_qa_rules` filter.
 */
class Block_QA_Rules {

	public const KIND_VALIDATE = 'validate';
	public const KIND_AUDIT    = 'audit';
	public const KIND_DESIGN   = 'design';
	public const KIND_COPY     = 'copy';

	/**
	 * Run every rule of a given kind against a block tree.
	 *
	 * @param string                         $kind   One of the KIND_* constants.
	 * @param array<int,array<string,mixed>> $blocks Parsed block tree.
	 * @return array<int,array<string,mixed>>       Issues.
	 */
	public static function run( string $kind, array $blocks ): array {
		$rules  = self::rules( $kind );
		$issues = array();
		foreach ( $rules as $rule ) {
			$emitted = call_user_func( $rule, $blocks );
			if ( is_array( $emitted ) ) {
				foreach ( $emitted as $issue ) {
					if ( is_array( $issue ) ) {
						$issues[] = $issue;
					}
				}
			}
		}
		return $issues;
	}

	/**
	 * Return every registered rule of a given kind, after filter extension.
	 *
	 * @param string $kind Kind name.
	 * @return array<int,callable>
	 */
	private static function rules( string $kind ): array {
		$builtin = self::builtins( $kind );
		$filtered = apply_filters( 'acrossai_block_qa_rules', $builtin, $kind );
		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_callable' ) ) : $builtin;
	}

	/**
	 * Built-in minimal rule set per kind.
	 *
	 * @param string $kind Kind name.
	 * @return array<int,callable>
	 */
	private static function builtins( string $kind ): array {
		switch ( $kind ) {
			case self::KIND_VALIDATE:
				return array( array( self::class, 'rule_valid_block_names' ) );
			case self::KIND_AUDIT:
				return array(
					array( self::class, 'rule_image_missing_alt' ),
					array( self::class, 'rule_button_missing_url' ),
					array( self::class, 'rule_empty_heading' ),
				);
			case self::KIND_DESIGN:
				return array( array( self::class, 'rule_card_monotony' ) );
			case self::KIND_COPY:
				return array( array( self::class, 'rule_bare_label_chips' ) );
			default:
				return array();
		}
	}

	/**
	 * Rule: reject unknown block names.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rule_valid_block_names( array $blocks ): array {
		$registered = class_exists( '\WP_Block_Type_Registry' )
			? array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() )
			: array();
		$issues     = array();
		self::walk(
			$blocks,
			array(),
			static function ( array $block, array $path ) use ( &$issues, $registered ): void {
				$name = (string) ( $block['blockName'] ?? '' );
				if ( '' !== $name && ! in_array( $name, $registered, true ) ) {
					$issues[] = array(
						'severity'   => 'error',
						'code'       => 'unknown_block_name',
						'path'       => $path,
						'block_name' => $name,
						'message'    => sprintf( 'Unknown block name: %s', $name ),
					);
				}
			}
		);
		return $issues;
	}

	/**
	 * Rule: core/image missing alt.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rule_image_missing_alt( array $blocks ): array {
		$issues = array();
		self::walk(
			$blocks,
			array(),
			static function ( array $block, array $path ) use ( &$issues ): void {
				if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
					$alt = (string) ( $block['attrs']['alt'] ?? '' );
					if ( '' === trim( $alt ) ) {
						$issues[] = array(
							'severity'   => 'warning',
							'code'       => 'image_missing_alt',
							'path'       => $path,
							'block_name' => 'core/image',
							'message'    => 'Image missing alt text.',
						);
					}
				}
			}
		);
		return $issues;
	}

	/**
	 * Rule: core/button with empty URL.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rule_button_missing_url( array $blocks ): array {
		$issues = array();
		self::walk(
			$blocks,
			array(),
			static function ( array $block, array $path ) use ( &$issues ): void {
				if ( 'core/button' === ( $block['blockName'] ?? '' ) ) {
					$url = (string) ( $block['attrs']['url'] ?? '' );
					if ( '' === trim( $url ) ) {
						$issues[] = array(
							'severity'   => 'warning',
							'code'       => 'button_missing_url',
							'path'       => $path,
							'block_name' => 'core/button',
							'message'    => 'Button has no destination URL.',
						);
					}
				}
			}
		);
		return $issues;
	}

	/**
	 * Rule: core/heading with empty innerHTML.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rule_empty_heading( array $blocks ): array {
		$issues = array();
		self::walk(
			$blocks,
			array(),
			static function ( array $block, array $path ) use ( &$issues ): void {
				if ( 'core/heading' === ( $block['blockName'] ?? '' ) ) {
					$html = trim( (string) ( $block['innerHTML'] ?? '' ) );
					$text = trim( (string) wp_strip_all_tags( $html ) );
					if ( '' === $text ) {
						$issues[] = array(
							'severity'   => 'warning',
							'code'       => 'heading_empty',
							'path'       => $path,
							'block_name' => 'core/heading',
							'message'    => 'Heading has no visible text.',
						);
					}
				}
			}
		);
		return $issues;
	}

	/**
	 * Rule: card monotony — flag pages that overuse boxed section treatment.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rule_card_monotony( array $blocks ): array {
		$boxed = 0;
		$total = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( 'core/group' === ( $block['blockName'] ?? '' ) || 'core/cover' === ( $block['blockName'] ?? '' ) ) {
				++$total;
				$class = (string) ( $block['attrs']['className'] ?? '' );
				if ( isset( $block['attrs']['style']['border']['radius'] ) || preg_match( '/(?:^|\s)(card|boxed|rounded)(?:\s|$)/i', $class ) ) {
					++$boxed;
				}
			}
		}
		if ( $total >= 4 && $boxed >= (int) ceil( $total * 0.75 ) ) {
			return array(
				array(
					'severity' => 'warning',
					'code'     => 'card_monotony_risk',
					'path'     => array(),
					'message'  => sprintf( 'Card monotony risk: %d of %d top-level sections use boxed/card treatment.', $boxed, $total ),
				),
			);
		}
		return array();
	}

	/**
	 * Rule: bare-label chips in a proof-row layout.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rule_bare_label_chips( array $blocks ): array {
		$issues = array();
		self::walk(
			$blocks,
			array(),
			static function ( array $block, array $path ) use ( &$issues ): void {
				if ( 'core/buttons' !== ( $block['blockName'] ?? '' ) ) {
					return;
				}
				$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
				$bare     = 0;
				foreach ( $children as $child ) {
					if ( 'core/button' === ( $child['blockName'] ?? '' ) ) {
						$url = (string) ( $child['attrs']['url'] ?? '' );
						if ( '' === trim( $url ) ) {
							++$bare;
						}
					}
				}
				if ( count( $children ) >= 3 && $bare === count( $children ) ) {
					$issues[] = array(
						'severity' => 'notice',
						'code'     => 'noninteractive_control_affordance_risk',
						'path'     => $path,
						'message'  => 'Proof row contains buttons with no destination URL — appears clickable but is inert.',
					);
				}
			}
		);
		return $issues;
	}

	/**
	 * Depth-first walk with path tracking.
	 *
	 * @param array<int,array<string,mixed>> $blocks  Parsed blocks.
	 * @param int[]                          $prefix  Path prefix.
	 * @param callable                       $visitor Visitor.
	 * @return void
	 */
	private static function walk( array $blocks, array $prefix, callable $visitor ): void {
		foreach ( $blocks as $i => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$path = array_merge( $prefix, array( (int) $i ) );
			$visitor( $block, $path );
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::walk( $block['innerBlocks'], $path, $visitor );
			}
		}
	}
}
