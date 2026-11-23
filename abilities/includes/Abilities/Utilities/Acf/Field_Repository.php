<?php
/**
 * Feature 105 — every ACF field read and write the suite performs.
 *
 * All access goes through ACF's public template API — `get_field()`, `update_field()`,
 * `delete_field()`, `add_row()`, `update_row()`, `delete_row()` — and never through post meta.
 *
 * That distinction is the whole reason this suite exists. ACF stores a complex field as a value row
 * PLUS a `_`-prefixed field-key reference row, and a repeater additionally stores one row per index
 * per sub-field with its own key rows. `update_post_meta()` writes the value row alone, leaving ACF
 * unable to read its own data back — a corruption that reports success. Only ACF's own writers keep
 * the two in step.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Acf
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Acf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over ACF's field API.
 */
final class Field_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Read one field value.
	 *
	 * `format_value` left at ACF's default true so relationships hydrate to post objects and dates
	 * come back formatted — the shape a caller expects from ACF rather than the raw stored scalar.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int|string $target   Resolved ACF target.
	 * @return mixed
	 */
	public static function get( string $selector, $target ) {
		return \get_field( $selector, $target );
	}

	/**
	 * Resolve a field definition on a target, whether or not it currently holds a value.
	 *
	 * `get_field_object()` resolves a field NAME through its reference row — the `_`-prefixed meta
	 * row ACF writes alongside a value — so it returns nothing for a field that has never been
	 * written. That makes the most common case of all look like an error: adding the first row to an
	 * empty repeater reported `unknown_field` even though the field plainly exists on the post.
	 * Found live; no unit test would have caught it, because it only appears against a real ACF
	 * install with a field that has no value yet.
	 *
	 * So: try the value-aware lookup first, because it returns the hydrated value too, and fall back
	 * to ACF's own loose name lookup (`acf_maybe_get_field( …, $strict = false )`) for the definition
	 * alone. A field that resolves through neither genuinely does not exist.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int|string $target   Resolved ACF target.
	 * @return array<string,mixed>|null
	 */
	private static function resolve( string $selector, $target ): ?array {
		$object = \get_field_object( $selector, $target );

		if ( is_array( $object ) && ! empty( $object['key'] ) ) {
			return $object;
		}

		$loose = \acf_maybe_get_field( $selector, $target, false );

		return is_array( $loose ) && ! empty( $loose['key'] ) ? $loose : null;
	}

	/**
	 * Whether a field exists on the target.
	 *
	 * `get_field()` returns null both for "no such field" and for "field with no value", so an
	 * ability that only called it would report a clean read of nothing for a typo'd name.
	 * `get_field_object()` distinguishes the two.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int|string $target   Resolved ACF target.
	 * @return bool
	 */
	public static function exists( string $selector, $target ): bool {
		return null !== self::resolve( $selector, $target );
	}

	/**
	 * Describe one field, or a WP_Error when it is not attached to the target.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int|string $target   Resolved ACF target.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function describe( string $selector, $target ) {
		$object = self::resolve( $selector, $target );

		if ( null === $object ) {
			return new WP_Error(
				'unknown_field',
				sprintf(
					/* translators: %s: field name */
					__( 'No ACF field "%s" is attached to that target. Call custom-fields/get-acf-fields to see what is.', 'acrossai-abilities-manager' ),
					$selector
				)
			);
		}

		return self::row( $object );
	}

	/**
	 * Every field on a target, as ROWS.
	 *
	 * A list, not the name-keyed map `get_fields()` returns: an associative array encodes as a JSON
	 * object, so an output property declared `type => array` fails the ability's own output schema
	 * after the work is done (BUG-ARRAY-TYPED-OUTPUT-IS-A-JSON-OBJECT). The name travels inside each
	 * row instead, which also leaves room for the key and type a caller needs to do anything useful
	 * with the value.
	 *
	 * @since  0.0.34
	 * @param  int|string $target Resolved ACF target.
	 * @return array<int, array<string,mixed>>
	 */
	public static function all( $target ): array {
		$values = \get_fields( $target );
		$rows   = array();

		if ( ! is_array( $values ) ) {
			return $rows;
		}

		foreach ( $values as $name => $value ) {
			$object = \get_field_object( (string) $name, $target );

			$rows[] = is_array( $object ) && ! empty( $object['key'] )
				? self::row( $object )
				: array(
					'name'  => (string) $name,
					'key'   => '',
					'type'  => '',
					'label' => '',
					'value' => $value,
				);
		}

		return $rows;
	}

	/**
	 * Shape one ACF field object into the row every reader returns.
	 *
	 * @since  0.0.34
	 * @param  array<string,mixed> $object ACF field object.
	 * @return array<string,mixed>
	 */
	private static function row( array $field ): array {
		return array(
			'name'  => isset( $field['name'] ) ? (string) $field['name'] : '',
			'key'   => isset( $field['key'] ) ? (string) $field['key'] : '',
			'type'  => isset( $field['type'] ) ? (string) $field['type'] : '',
			'label' => isset( $field['label'] ) ? (string) $field['label'] : '',
			'value' => $field['value'] ?? null,
		);
	}

	/**
	 * Write one field value.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  mixed      $value    New value, already slashed by the caller via Slash_Input.
	 * @param  int|string $target   Resolved ACF target.
	 * @return true|WP_Error
	 */
	public static function update( string $selector, $value, $target ) {
		if ( ! \update_field( $selector, $value, $target ) ) {
			// update_field() also returns false when the new value equals the old one, so confirm
			// against the stored value rather than reporting a spurious failure.
			if ( \get_field( $selector, $target ) === $value ) {
				return true;
			}

			return new WP_Error(
				'update_failed',
				sprintf(
					/* translators: %s: field name */
					__( 'Advanced Custom Fields refused the write to "%s".', 'acrossai-abilities-manager' ),
					$selector
				)
			);
		}

		return true;
	}

	/**
	 * Clear one field value.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int|string $target   Resolved ACF target.
	 * @return true|WP_Error
	 */
	public static function delete( string $selector, $target ) {
		if ( ! \delete_field( $selector, $target ) ) {
			return new WP_Error(
				'delete_failed',
				sprintf(
					/* translators: %s: field name */
					__( 'Could not clear "%s". It may already be empty.', 'acrossai-abilities-manager' ),
					$selector
				)
			);
		}

		return true;
	}

	/**
	 * How many rows a repeater or flexible-content field currently holds.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int|string $target   Resolved ACF target.
	 * @return int
	 */
	public static function row_count( string $selector, $target ): int {
		$value = \get_field( $selector, $target );

		return is_array( $value ) ? count( $value ) : 0;
	}

	/**
	 * Assert a field exists AND is of the expected type.
	 *
	 * Used by the row abilities: `add_row()` on a text field silently does nothing, so the type has
	 * to be checked before the call rather than inferred from its return value.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int|string $target   Resolved ACF target.
	 * @param  string[]   $types    Acceptable field types.
	 * @return true|WP_Error
	 */
	public static function assert_type( string $selector, $target, array $types ) {
		$object = self::resolve( $selector, $target );

		if ( null === $object ) {
			return new WP_Error(
				'unknown_field',
				sprintf(
					/* translators: %s: field name */
					__( 'No ACF field "%s" is attached to that target.', 'acrossai-abilities-manager' ),
					$selector
				)
			);
		}

		$type = isset( $object['type'] ) ? (string) $object['type'] : '';

		if ( ! in_array( $type, $types, true ) ) {
			return new WP_Error(
				'wrong_field_type',
				sprintf(
					/* translators: 1: field name, 2: actual type, 3: comma-separated accepted types */
					__( '"%1$s" is a %2$s field; this ability needs one of: %3$s.', 'acrossai-abilities-manager' ),
					$selector,
					'' !== $type ? $type : 'unknown',
					implode( ', ', $types )
				)
			);
		}

		return true;
	}

	/**
	 * Append, or insert at a 1-based position, one row into a repeater or flexible-content field.
	 *
	 * **ACF has no positional-insert API.** `add_row()` only ever appends — its body is literally
	 * `$value[] = $row`. So an append goes through `add_row()`, which is a single targeted write,
	 * while an insert has to read the array, splice, and write the whole thing back through
	 * `update_field()`.
	 *
	 * That read-modify-write is the very thing these row abilities exist to spare the caller, and it
	 * is worth being clear that it has only moved rather than disappeared: it now happens once inside
	 * one PHP request instead of across three round trips to an AI client, which is where the cost
	 * actually mattered. Appending stays cheap; inserting is the expensive case by nature.
	 *
	 * @since  0.0.34
	 * @param  string              $selector Field name or key.
	 * @param  array<string,mixed> $row      Sub-field values.
	 * @param  int|string          $target   Resolved ACF target.
	 * @param  int|null            $position 1-based insert position, or null to append.
	 * @return int|WP_Error The 1-based index the row now occupies.
	 */
	public static function add_row( string $selector, array $row, $target, ?int $position = null ) {
		if ( null === $position ) {
			$added = \add_row( $selector, $row, $target );

			if ( ! $added ) {
				return new WP_Error(
					'row_add_failed',
					__( 'Advanced Custom Fields refused to add the row.', 'acrossai-abilities-manager' )
				);
			}

			return (int) $added;
		}

		$rows = \get_field( $selector, $target );
		$rows = is_array( $rows ) ? array_values( $rows ) : array();

		// Clamp rather than reject: an insert position one past the end is an append, and anything
		// beyond that is unambiguously an append too.
		$position = max( 1, min( $position, count( $rows ) + 1 ) );

		array_splice( $rows, $position - 1, 0, array( $row ) );

		$written = self::update( $selector, $rows, $target );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return $position;
	}

	/**
	 * Patch one row by 1-based index.
	 *
	 * @since  0.0.34
	 * @param  string              $selector Field name or key.
	 * @param  int                 $index    1-based row index.
	 * @param  array<string,mixed> $row      Sub-field values to merge.
	 * @param  int|string          $target   Resolved ACF target.
	 * @return true|WP_Error
	 */
	public static function update_row( string $selector, int $index, array $row, $target ) {
		if ( ! \update_row( $selector, $index, $row, $target ) ) {
			return new WP_Error(
				'row_update_failed',
				__( 'Advanced Custom Fields refused the row update.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Remove one row by 1-based index.
	 *
	 * @since  0.0.34
	 * @param  string     $selector Field name or key.
	 * @param  int        $index    1-based row index.
	 * @param  int|string $target   Resolved ACF target.
	 * @return true|WP_Error
	 */
	public static function delete_row( string $selector, int $index, $target ) {
		if ( ! \delete_row( $selector, $index, $target ) ) {
			return new WP_Error(
				'row_delete_failed',
				__( 'Advanced Custom Fields refused the row delete.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Assert a 1-based row index is within range.
	 *
	 * ACF's row functions take 1-based indexes and return false for anything out of range, which is
	 * indistinguishable from a genuine failure. Checking first turns that into a typed error naming
	 * the valid range.
	 *
	 * @since  0.0.34
	 * @param  int $index 1-based index.
	 * @param  int $count Current row count.
	 * @return true|WP_Error
	 */
	public static function assert_index( int $index, int $count ) {
		if ( $index < 1 || $index > $count ) {
			return new WP_Error(
				'row_out_of_range',
				sprintf(
					/* translators: 1: requested index, 2: row count */
					__( 'Row %1$d does not exist. Rows are 1-based and this field currently has %2$d.', 'acrossai-abilities-manager' ),
					$index,
					$count
				)
			);
		}

		return true;
	}
}
