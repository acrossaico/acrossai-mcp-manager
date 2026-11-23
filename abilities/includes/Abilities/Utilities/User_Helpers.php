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

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Slash_Input;

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers for resolving and formatting users.
 */
class User_Helpers {

	/**
	 * Resolve a user identifier (ID, login, email, or slug) to a WP_User.
	 *
	 * Tries in order: numeric ID, email (if contains @), login, slug.
	 *
	 * @param mixed $identifier User ID (int/string), login, email, or slug.
	 * @return \WP_User|null
	 */
	public static function resolve_user( $identifier ): ?\WP_User {
		if ( is_numeric( $identifier ) ) {
			$user = get_user_by( 'id', (int) $identifier );
			if ( $user ) {
				return $user;
			}
		}

		if ( ! is_string( $identifier ) ) {
			return null;
		}

		$identifier = trim( $identifier );
		if ( '' === $identifier ) {
			return null;
		}

		if ( str_contains( $identifier, '@' ) ) {
			$user = get_user_by( 'email', $identifier );
			if ( $user ) {
				return $user;
			}
		}

		$user = get_user_by( 'login', $identifier );
		if ( $user ) {
			return $user;
		}

		$user = get_user_by( 'slug', $identifier );
		if ( $user ) {
			return $user;
		}

		return null;
	}

	/**
	 * Format a WP_User as an array of safe public fields.
	 *
	 * Pass $opts['include_meta'] = true to attach a "meta" map of unserialized
	 * user_meta values. Optionally restrict to specific $opts['meta_keys'].
	 *
	 * @param \WP_User                                                   $user
	 * @param array{include_meta?: bool, meta_keys?: array<int, string>} $opts
	 * @return array
	 */
	public static function format_user( \WP_User $user, array $opts = array() ): array {
		$data = array(
			'id'           => (int) $user->ID,
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => (string) $user->first_name,
			'last_name'    => (string) $user->last_name,
			'slug'         => $user->user_nicename,
			'url'          => $user->user_url,
			'registered'   => $user->user_registered,
			'roles'        => array_values( (array) $user->roles ),
		);

		if ( ! empty( $opts['include_meta'] ) ) {
			$keys         = isset( $opts['meta_keys'] ) && is_array( $opts['meta_keys'] ) ? $opts['meta_keys'] : array();
			$data['meta'] = self::get_all_meta( (int) $user->ID, $keys );
		}

		if ( ! empty( $opts['include_sessions'] ) ) {
			$data['sessions'] = self::get_sessions( (int) $user->ID );
		}

		return $data;
	}

	/**
	 * Returns a flat map of user_meta values, automatically unserialized.
	 * If $keys is non-empty, only those keys are returned (single value each).
	 *
	 * @param int      $user_id
	 * @param string[] $keys
	 * @return array<string, mixed>
	 */
	public static function get_all_meta( int $user_id, array $keys = array() ): array {
		if ( ! empty( $keys ) ) {
			$out = array();
			foreach ( $keys as $key ) {
				$key = sanitize_text_field( (string) $key );
				if ( '' === $key ) {
					continue;
				}
				$out[ $key ] = get_user_meta( $user_id, $key, true );
			}
			return $out;
		}

		$all  = get_user_meta( $user_id );
		$flat = array();
		if ( ! is_array( $all ) ) {
			return $flat;
		}
		foreach ( $all as $key => $values ) {
			if ( '' === $key ) {
				continue;
			}
			$values = (array) $values;
			if ( 1 === count( $values ) ) {
				$flat[ $key ] = maybe_unserialize( $values[0] );
			} else {
				$flat[ $key ] = array_map( 'maybe_unserialize', $values );
			}
		}
		return $flat;
	}

	/**
	 * Bulk-apply a "meta" map ($key => $value) using update_user_meta.
	 * String values that look like JSON are decoded.
	 *
	 * @param int                  $user_id
	 * @param array<string, mixed> $meta
	 * @param array<mixed,mixed>   $input Ability input payload; forwarded to Slash_Input::slash() so the caller's apply_wp_slash flag governs whether values are slashed. Defaults to empty (= slash on, matches WP core wp_unslash contract).
	 * @return array{updated: string[], failed: string[]}
	 */
	public static function apply_meta( int $user_id, array $meta, array $input = array() ): array {
		$updated = array();
		$failed  = array();
		foreach ( $meta as $key => $value ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$value  = self::maybe_decode_json( $value );
			$result = update_user_meta( $user_id, $key, Slash_Input::slash( $value, $input ) );
			if ( false === $result ) {
				$failed[] = $key;
			} else {
				$updated[] = $key;
			}
		}
		return array(
			'updated' => $updated,
			'failed'  => $failed,
		);
	}

	/**
	 * Bulk-delete user_meta by key list.
	 *
	 * @param int      $user_id
	 * @param string[] $keys
	 * @return array{deleted: string[], failed: string[]}
	 */
	public static function delete_meta( int $user_id, array $keys ): array {
		$deleted = array();
		$failed  = array();
		foreach ( $keys as $key ) {
			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( delete_user_meta( $user_id, $key ) ) {
				$deleted[] = $key;
			} else {
				$failed[] = $key;
			}
		}
		return array(
			'deleted' => $deleted,
			'failed'  => $failed,
		);
	}

	/**
	 * Returns the active session tokens for a user as a flat array of
	 * { login, expiration, ip, ua } rows. Each row reflects what
	 * WP_Session_Tokens stores per session.
	 *
	 * @return array<int, array{login:int, expiration:int, ip:string, ua:string}>
	 */
	public static function get_sessions( int $user_id ): array {
		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$out     = array();
		foreach ( $manager->get_all() as $session ) {
			$out[] = array(
				'login'      => isset( $session['login'] ) ? (int) $session['login'] : 0,
				'expiration' => isset( $session['expiration'] ) ? (int) $session['expiration'] : 0,
				'ip'         => isset( $session['ip'] ) ? (string) $session['ip'] : '',
				'ua'         => isset( $session['ua'] ) ? (string) $session['ua'] : '',
			);
		}
		return $out;
	}

	/**
	 * Destroys every active session for a user. Returns the count that was
	 * killed (looked up before the destroy call).
	 */
	public static function destroy_sessions( int $user_id ): int {
		$manager = \WP_Session_Tokens::get_instance( $user_id );
		$count   = count( $manager->get_all() );
		$manager->destroy_all();
		return $count;
	}

	/**
	 * Decode a meta value if it looks like JSON, otherwise return as-is.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function maybe_decode_json( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}

		$trimmed = ltrim( $value );
		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return $value;
		}

		$decoded = json_decode( $value, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $value;
		}

		return $decoded;
	}
}
