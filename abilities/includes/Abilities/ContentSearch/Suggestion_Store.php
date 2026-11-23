<?php
/**
 * Feature 055 — option-backed store for internal-link suggestions.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\ContentSearch
 * @since      0.0.13
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\ContentSearch;

defined( 'ABSPATH' ) || exit;

/**
 * Simple option-backed store for internal-link suggestions.
 *
 * Data shape:
 *   [
 *     next_id => int,
 *     items   => [
 *       id => [id, post_id, target_url, anchor_text, status, notes, created_at],
 *       ...
 *     ],
 *   ]
 *
 * `status` is one of `pending` | `approved` | `rejected` | `applied`.
 */
final class Suggestion_Store {

	/**
	 * WP option name.
	 */
	public const OPTION = 'acrossai_abilities_manager_link_suggestions';

	/**
	 * Hard cap on the total number of stored suggestions.
	 */
	public const MAX_SUGGESTIONS = 500;

	/**
	 * Read the store.
	 *
	 * @return array{next_id:int, items: array<int, array{id:int,post_id:int,target_url:string,anchor_text:string,status:string,notes:string,created_at:int}>}
	 */
	public static function read(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'next_id' => isset( $raw['next_id'] ) ? max( 1, (int) $raw['next_id'] ) : 1,
			'items'   => isset( $raw['items'] ) && is_array( $raw['items'] ) ? $raw['items'] : array(),
		);
	}

	/**
	 * Persist the store.
	 *
	 * @param array{next_id:int, items: array<int, array<string,mixed>>} $store Store.
	 */
	public static function write( array $store ): void {
		update_option( self::OPTION, $store, false );
	}

	/**
	 * Insert a suggestion. Returns the newly-assigned id, or 0 when the
	 * cap is hit.
	 *
	 * @param array{post_id:int,target_url:string,anchor_text:string,notes?:string} $data Data.
	 * @return int
	 */
	public static function insert( array $data ): int {
		$store = self::read();
		if ( count( $store['items'] ) >= self::MAX_SUGGESTIONS ) {
			return 0;
		}
		$id = $store['next_id'];
		$store['items'][ $id ] = array(
			'id'          => $id,
			'post_id'     => (int) ( $data['post_id'] ?? 0 ),
			'target_url'  => esc_url_raw( (string) ( $data['target_url'] ?? '' ) ),
			'anchor_text' => sanitize_text_field( (string) ( $data['anchor_text'] ?? '' ) ),
			'status'      => 'pending',
			'notes'       => sanitize_text_field( (string) ( $data['notes'] ?? '' ) ),
			'created_at'  => time(),
		);
		$store['next_id'] = $id + 1;
		self::write( $store );
		return $id;
	}

	/**
	 * Update a suggestion's status + notes. Returns false when not found.
	 *
	 * @param int    $id    Suggestion id.
	 * @param string $status New status.
	 * @param string $notes Review notes.
	 */
	public static function update_status( int $id, string $status, string $notes = '' ): bool {
		$store = self::read();
		if ( ! isset( $store['items'][ $id ] ) ) {
			return false;
		}
		$store['items'][ $id ]['status'] = $status;
		if ( '' !== $notes ) {
			$store['items'][ $id ]['notes'] = sanitize_text_field( $notes );
		}
		self::write( $store );
		return true;
	}

	/**
	 * Get a single suggestion or null.
	 *
	 * @param int $id Suggestion id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		$store = self::read();
		return isset( $store['items'][ $id ] ) ? $store['items'][ $id ] : null;
	}

	/**
	 * List suggestions, optionally filtering by post_id + status.
	 *
	 * @param int|null    $post_id Optional post filter.
	 * @param string|null $status  Optional status filter.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list( ?int $post_id = null, ?string $status = null ): array {
		$store  = self::read();
		$result = array();
		foreach ( $store['items'] as $item ) {
			if ( null !== $post_id && (int) $item['post_id'] !== $post_id ) {
				continue;
			}
			if ( null !== $status && (string) $item['status'] !== $status ) {
				continue;
			}
			$result[] = $item;
		}
		return $result;
	}
}
