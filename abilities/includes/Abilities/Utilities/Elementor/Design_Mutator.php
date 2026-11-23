<?php
/**
 * Feature 067 / issue #243 — the single write path for the design-fix abilities.
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
 * Edits an Elementor document by walking it, recording every change, then saving once.
 *
 * Eleven abilities mutate documents. Each doing its own tree walk and its own save
 * would be eleven chances to write a half-modified document, so they all come through
 * here and the contract is the same for every one:
 *
 *   - the whole document is rewritten, never the scoped slice, so a subtree edit cannot
 *     truncate the page around it;
 *   - every change is recorded as element / setting / from / to BEFORE the save, so the
 *     response can state exactly what moved and a human can put it back;
 *   - a run that matches nothing saves nothing. Rewriting a document to identical bytes
 *     still bumps its revision and invalidates its cache for no reason.
 *
 * These are not previews. The confirm gate in Base_Audit_Ability is what stands between
 * a caller and a live page; by the time anything here runs, that has been passed.
 */
final class Design_Mutator {

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Apply a per-element edit across a scope and save once.
	 *
	 * @param array<string,mixed> $model  Model from {@see Design_Model::build()}.
	 * @param callable            $editor fn( array &$settings, array $element ): array<int,array{setting:string,from:mixed,to:mixed}>
	 *                                    Mutates $settings in place and returns what it changed.
	 * @return array<string,mixed>|WP_Error { changed, applied }
	 */
	public static function edit( array $model, callable $editor ) {
		$post_id  = (int) ( $model['post_id'] ?? 0 );
		$document = isset( $model['document'] ) && is_array( $model['document'] ) ? $model['document'] : array();
		$scope    = self::scope_ids( $model );

		$changed = array();
		$next    = self::walk( $document, $editor, $scope, $changed );

		if ( array() === $changed ) {
			return array( 'changed' => array(), 'applied' => false );
		}

		$saved = Document_Repository::save_data( $post_id, $next );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return array( 'changed' => $changed, 'applied' => true );
	}

	/**
	 * Element ids the edit is allowed to touch, or null for the whole document.
	 *
	 * @param array<string,mixed> $model Model.
	 * @return string[]|null
	 */
	private static function scope_ids( array $model ): ?array {
		$subtree = (string) ( $model['subtree_id'] ?? '' );
		if ( '' === $subtree ) {
			return null;
		}

		$ids = array();
		Document_Repository::walk_tree(
			(array) ( $model['scoped_data'] ?? array() ),
			static function ( array $element ) use ( &$ids ): void {
				$id = (string) ( $element['id'] ?? '' );
				if ( '' !== $id ) {
					$ids[] = $id;
				}
			}
		);

		return $ids;
	}

	/**
	 * Rebuild the tree, applying the editor to every in-scope element.
	 *
	 * Rebuilds rather than mutating by reference: a partially-applied edit then cannot
	 * reach the document, because the new tree is only handed to save_data() once the
	 * whole walk has finished.
	 *
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param callable                       $editor   Editor callback.
	 * @param string[]|null                  $scope    Allowed ids, or null for all.
	 * @param array<int,array<string,mixed>> $changed  Accumulator, by reference.
	 * @return array<int,array<string,mixed>>
	 */
	private static function walk( array $elements, callable $editor, ?array $scope, array &$changed ): array {
		$out = array();

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id = (string) ( $element['id'] ?? '' );

			if ( null === $scope || in_array( $id, $scope, true ) ) {
				$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
				$edits    = $editor( $settings, $element );

				if ( is_array( $edits ) && array() !== $edits ) {
					$element['settings'] = $settings;
					foreach ( $edits as $edit ) {
						$changed[] = array_merge( array( 'element_id' => $id ), (array) $edit );
					}
				}
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::walk( array_values( $element['elements'] ), $editor, $scope, $changed );
			}

			$out[] = $element;
		}

		return $out;
	}

	/**
	 * Remove a settings key, recording the removal.
	 *
	 * @param array<string,mixed> $settings Settings, by reference.
	 * @param string              $key      Key to clear.
	 * @return array<int,array<string,mixed>>
	 */
	public static function clear( array &$settings, string $key ): array {
		if ( ! array_key_exists( $key, $settings ) ) {
			return array();
		}

		$from = $settings[ $key ];
		unset( $settings[ $key ] );

		return array( array( 'setting' => $key, 'from' => $from, 'to' => null ) );
	}

	/**
	 * Set a settings key, recording the change and skipping a no-op.
	 *
	 * @param array<string,mixed> $settings Settings, by reference.
	 * @param string              $key      Key to set.
	 * @param mixed               $value    New value.
	 * @return array<int,array<string,mixed>>
	 */
	public static function set( array &$settings, string $key, $value ): array {
		$from = $settings[ $key ] ?? null;
		if ( $from === $value ) {
			return array();
		}

		$settings[ $key ] = $value;

		return array( array( 'setting' => $key, 'from' => $from, 'to' => $value ) );
	}

	/**
	 * A standard response for a mutator run.
	 *
	 * @param array<string,mixed> $result  Result of {@see self::edit()}.
	 * @param string              $applied Sentence used when something changed.
	 * @param string              $noop    Sentence used when nothing matched.
	 * @return array<string,mixed>
	 */
	public static function report( array $result, string $applied, string $noop ): array {
		$changes = (array) ( $result['changed'] ?? array() );

		return array(
			'findings'        => array(),
			'recommendations' => array(),
			'changed'         => $changes,
			'applied'         => ! empty( $result['applied'] ),
			'score'           => null,
			'message'         => array() === $changes
				? $noop
				: sprintf(
					/* translators: 1: sentence describing what was applied, 2: number of settings changed */
					__( '%1$s Changed %2$d settings; each is listed with its previous value.', 'acrossai-abilities-manager' ),
					$applied,
					count( $changes )
				),
		);
	}
}
