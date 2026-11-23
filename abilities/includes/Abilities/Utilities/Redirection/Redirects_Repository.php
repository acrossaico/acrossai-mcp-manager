<?php
/**
 * Feature 130 — the sole bridge between the Redirection ability suite and the
 * Redirection plugin's model layer.
 *
 * Every \Red_Item, \Red_Group, \Red_404_Log and \Red_Redirect_Log call lives
 * here. The ability classes never name a \Red_* symbol; they call this
 * repository, so the whole third-party surface is in one file that degrades to
 * WP_Error instead of fatally dereferencing a model that moved.
 *
 * Redirects do not live in wp_posts: a redirect is a sanitized source URL, match
 * flags, an action + HTTP code, a serialized action payload and a cache flush,
 * all of which Red_Item::create() does and a raw INSERT does not. So this
 * repository calls the model, never the table.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Redirection
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Redirection;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only model wrapper for the Redirection suite.
 */
final class Redirects_Repository {

	/**
	 * Action types Redirection understands.
	 *
	 * url    — redirect to a target URL (needs a 3xx code and a target).
	 * error  — return an HTTP error (404 / 410), no target.
	 * pass   — pass the request through to the target, keeping the URL.
	 * random — redirect to a random published post.
	 * nothing — do nothing but log the hit.
	 */
	public const ACTION_TYPES = array( 'url', 'error', 'pass', 'random', 'nothing' );

	/**
	 * Match types Redirection understands. 'url' is the plain case.
	 */
	public const MATCH_TYPES = array(
		'url',
		'referrer',
		'agent',
		'cookie',
		'header',
		'custom',
		'ip',
		'server',
		'page',
		'language',
		'login',
		'role',
	);

	/**
	 * Valid HTTP codes for a 'url' (redirect) action.
	 */
	public const REDIRECT_CODES = array( 301, 302, 303, 304, 307, 308 );

	/**
	 * Valid HTTP codes for an 'error' action.
	 */
	public const ERROR_CODES = array( 400, 401, 403, 404, 410, 418, 451, 500 );

	/**
	 * Private constructor — this class is static-only.
	 */
	private function __construct() {}

	/**
	 * List redirects.
	 *
	 * @param array<string,mixed> $params Already-sanitized listing params:
	 *     per_page, page (1-based), orderby, direction, and a filterBy map.
	 * @return array{items:array<int,array<string,mixed>>,total:int}|WP_Error
	 */
	public static function list_redirects( array $params ) {
		if ( ! Redirection_Guard::is_active() ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		try {
			return \Red_Item::get_filtered( self::listing_params( $params ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'redirection_read_failed', $e->getMessage() );
		}
	}

	/**
	 * Create a redirect.
	 *
	 * @param array<string,mixed> $details Redirect details (url, match_type,
	 *     action_type, action_code, action_data, group_id, title, regex, enabled).
	 * @return array<string,mixed>|WP_Error The created redirect as JSON, or an error.
	 */
	public static function create( array $details ) {
		if ( ! Redirection_Guard::is_active() ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		try {
			$item = \Red_Item::create( $details );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'redirection_create_failed', $e->getMessage() );
		}

		if ( is_wp_error( $item ) ) {
			return $item;
		}
		if ( ! is_object( $item ) || ! method_exists( $item, 'to_json' ) ) {
			return new WP_Error( 'redirection_create_failed', __( 'Redirection did not return the new redirect.', 'acrossai-abilities-manager' ) );
		}

		return $item->to_json();
	}

	/**
	 * Fetch one redirect as JSON.
	 *
	 * @param int $id Redirect id.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		if ( ! Redirection_Guard::is_active() ) {
			return null;
		}
		$item = \Red_Item::get_by_id( $id );
		return ( is_object( $item ) && method_exists( $item, 'to_json' ) ) ? $item->to_json() : null;
	}

	/**
	 * Update one redirect.
	 *
	 * @param int                 $id      Redirect id.
	 * @param array<string,mixed> $details Fields to change.
	 * @return array<string,mixed>|WP_Error The updated redirect as JSON, or an error.
	 */
	public static function update( int $id, array $details ) {
		if ( ! Redirection_Guard::is_active() ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		$item = \Red_Item::get_by_id( $id );
		if ( ! is_object( $item ) ) {
			return new WP_Error( 'redirect_not_found', sprintf( /* translators: %d: redirect id */ __( 'No redirect with id %d.', 'acrossai-abilities-manager' ), $id ) );
		}

		try {
			$result = $item->update( $details );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'redirection_update_failed', $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$fresh = self::get( $id );
		return null === $fresh ? new WP_Error( 'redirection_update_failed', __( 'The redirect could not be re-read after the update.', 'acrossai-abilities-manager' ) ) : $fresh;
	}

	/**
	 * Enable or disable one redirect.
	 *
	 * @param int  $id      Redirect id.
	 * @param bool $enabled Target state.
	 * @return array<string,mixed>|WP_Error The redirect as JSON, or an error.
	 */
	public static function set_enabled( int $id, bool $enabled ) {
		if ( ! Redirection_Guard::is_active() ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		$item = \Red_Item::get_by_id( $id );
		if ( ! is_object( $item ) ) {
			return new WP_Error( 'redirect_not_found', sprintf( /* translators: %d: redirect id */ __( 'No redirect with id %d.', 'acrossai-abilities-manager' ), $id ) );
		}

		try {
			if ( $enabled ) {
				$item->enable();
			} else {
				$item->disable();
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'redirection_update_failed', $e->getMessage() );
		}

		$fresh = self::get( $id );
		return null === $fresh ? new WP_Error( 'redirection_update_failed', __( 'The redirect could not be re-read.', 'acrossai-abilities-manager' ) ) : $fresh;
	}

	/**
	 * Delete redirects by id, one model call each so the outcome is per-id.
	 *
	 * @param int[] $ids Redirect ids.
	 * @return array{deleted:int[],missing:int[]}|WP_Error
	 */
	public static function delete( array $ids ) {
		if ( ! Redirection_Guard::is_active() ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		$deleted = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$item = \Red_Item::get_by_id( $id );
			if ( ! is_object( $item ) ) {
				$missing[] = $id;
				continue;
			}
			try {
				$item->delete();
				$deleted[] = $id;
			} catch ( \Throwable $e ) {
				$missing[] = $id;
			}
		}

		return array( 'deleted' => $deleted, 'missing' => $missing );
	}

	/**
	 * List groups.
	 *
	 * @param array<string,mixed> $params Listing params.
	 * @return array{items:array<int,array<string,mixed>>,total:int}|WP_Error
	 */
	public static function list_groups( array $params ) {
		if ( ! Redirection_Guard::is_active() ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		try {
			return \Red_Group::get_filtered( self::listing_params( $params, array( 'name', 'id' ), 'name' ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'redirection_read_failed', $e->getMessage() );
		}
	}

	/**
	 * List the 404 error log, grouped by URL with a hit count by default.
	 *
	 * @param array<string,mixed> $params Listing params + optional group_by.
	 * @return array{items:array<int,mixed>,total:int}|WP_Error
	 */
	public static function list_404( array $params ) {
		if ( ! class_exists( '\Red_404_Log' ) ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		$group_by = isset( $params['group_by'] ) ? (string) $params['group_by'] : 'url';

		try {
			if ( '' !== $group_by && 'none' !== $group_by ) {
				return \Red_404_Log::get_grouped( $group_by, self::listing_params( $params ) );
			}
			return \Red_404_Log::get_filtered( self::listing_params( $params ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'redirection_read_failed', $e->getMessage() );
		}
	}

	/**
	 * List the redirect hit log.
	 *
	 * @param array<string,mixed> $params Listing params.
	 * @return array{items:array<int,mixed>,total:int}|WP_Error
	 */
	public static function list_hits( array $params ) {
		if ( ! class_exists( '\Red_Redirect_Log' ) ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		try {
			return \Red_Redirect_Log::get_filtered( self::listing_params( $params ) );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'redirection_read_failed', $e->getMessage() );
		}
	}

	/**
	 * Read Redirection's options, excluding secrets.
	 *
	 * The API token is never returned — it authenticates write access to the
	 * whole redirect table and has no business in an ability payload.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function settings() {
		if ( ! function_exists( 'red_get_options' ) ) {
			return new WP_Error( 'redirection_missing', __( 'Redirection is not active.', 'acrossai-abilities-manager' ) );
		}

		$options = red_get_options();
		if ( ! is_array( $options ) ) {
			return new WP_Error( 'redirection_read_failed', __( 'Redirection returned no options.', 'acrossai-abilities-manager' ) );
		}

		$safe = $options;
		unset( $safe['token'], $safe['support'] );

		return $safe;
	}

	/**
	 * Normalise ability listing params into the model's filter shape.
	 *
	 * The model expects a 0-based page multiplier; abilities take a 1-based page,
	 * which reads naturally and matches every other list ability in the plugin.
	 *
	 * @param array<string,mixed> $params        Ability params.
	 * @param string[]            $allowed_order Allowed orderby columns.
	 * @param string              $default_order Default orderby column.
	 * @return array<string,mixed>
	 */
	private static function listing_params( array $params, array $allowed_order = array( 'source', 'last_count', 'last_access', 'position' ), string $default_order = 'last_access' ): array {
		$per_page = isset( $params['per_page'] ) ? max( 5, min( 200, (int) $params['per_page'] ) ) : 25;
		$page     = isset( $params['page'] ) ? max( 1, (int) $params['page'] ) : 1;

		$out = array(
			'per_page' => $per_page,
			'page'     => $page - 1,
		);

		$orderby        = isset( $params['orderby'] ) ? (string) $params['orderby'] : $default_order;
		$out['orderby'] = in_array( $orderby, $allowed_order, true ) ? $orderby : $default_order;

		$direction        = isset( $params['direction'] ) ? strtolower( (string) $params['direction'] ) : 'desc';
		$out['direction'] = in_array( $direction, array( 'asc', 'desc' ), true ) ? $direction : 'desc';

		if ( isset( $params['filterBy'] ) && is_array( $params['filterBy'] ) ) {
			$out['filterBy'] = $params['filterBy'];
		}

		return $out;
	}
}
