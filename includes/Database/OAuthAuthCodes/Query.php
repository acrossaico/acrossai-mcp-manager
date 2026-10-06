<?php
/**
 * BerlinDB Query for the OAuthAuthCodes module (Feature 021).
 *
 * Bespoke methods:
 *   - consume_atomic()      — B10 CAS single-use pattern (mirrors CliAuthLog::redeem_atomic)
 *   - delete_expired()      — daily cron cleanup
 *   - delete_by_user_id()   — FR-042 cascade (site-wide by design — F032 US4 regression)
 *
 * F032 (T034) — no client_id-mutating helpers on this table (auth codes are
 * consumed atomically by `code_hash`, not by `client_id`). The `add_item`
 * write path already accepts `server_id` in the payload (Row now has the
 * property); persistence is handled inline by callers (AuthCodeRepository).
 * `delete_by_user_id` stays server-neutral per FR-042.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\OAuthAuthCodes
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes;

defined( 'ABSPATH' ) || exit;

class Query extends \BerlinDB\Database\Kern\Query {

	/** @var string */
	protected $table_name = 'acrossai_mcp_oauth_auth_codes';

	/** @var string */
	protected $table_alias = 'oac_c';

	/** @var string */
	protected $table_schema = Schema::class;

	/** @var string */
	protected $item_name = 'oauth_auth_code';

	/** @var string */
	protected $item_name_plural = 'oauth_auth_codes';

	/** @var string */
	protected $item_shape = Row::class;

	/** @var Query|null */
	protected static $instance = null;

	/**
	 * Private constructor enforces singleton pattern (A2/S6).
	 */
	private function __construct() { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- visibility override enforces singleton.
		parent::__construct();
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * B10 CAS single-use pattern.
	 *
	 * Atomically flip `used=0` to `used=1` for exactly one row where
	 * `code_hash = %s AND used = 0 AND expires_at > %s`. If rows_affected
	 * is 1 the caller wins; SELECT + return the row. Otherwise return null.
	 *
	 * Mirrors CliAuthLog\Query::redeem_atomic (B10) exactly.
	 *
	 * @param string $code_hash SHA-256 hex of the raw code.
	 * @param string $now       Current GMT time in mysql format.
	 * @return Row|null
	 */
	public function consume_atomic( string $code_hash, string $now ): ?Row {
		global $wpdb;

		if ( '' === $code_hash ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET used = 1 WHERE code_hash = %s AND used = 0 AND expires_at > %s',
				$wpdb->prefix . $this->table_name,
				$code_hash,
				$now
			)
		);

		if ( 1 !== (int) $wpdb->rows_affected ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE code_hash = %s',
				$wpdb->prefix . $this->table_name,
				$code_hash
			)
		);

		return $row ? new Row( $row ) : null;
	}

	/**
	 * Daily cron cleanup: rows past expiry OR already used.
	 *
	 * @param string $now Current GMT time (mysql format).
	 * @return int Rows deleted.
	 */
	public function delete_expired( string $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s OR used = 1',
				$wpdb->prefix . $this->table_name,
				$now
			)
		);

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * FR-042 cascade: delete every pending auth code for the deleted user.
	 *
	 * @param int $user_id WordPress user id.
	 * @return int Rows deleted.
	 */
	public function delete_by_user_id( int $user_id ): int {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE user_id = %d',
				$wpdb->prefix . $this->table_name,
				$user_id
			)
		);

		return is_int( $result ) ? $result : 0;
	}
}
