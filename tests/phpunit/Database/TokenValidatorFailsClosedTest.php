<?php
/**
 * F095 — pins TokenValidator's fail-closed contract (SC-C3 / SEC-003).
 *
 * `authenticate()` is hooked to `determine_current_user` and decides, on every
 * request, whether a bearer token resolves to a WordPress user. It has eight
 * distinct refusal paths, and **every one of them returns the incoming
 * `$user_id` unchanged** — never a resolved user, never a truthy default.
 *
 * That property is the reason the F095 upgrade window is an availability
 * problem rather than an authentication bypass. Between an auto-update and the
 * operator's next wp-admin visit, the token table may be empty: the companion
 * has stood down and the migration has not yet run. If a lookup miss resolved
 * to anything other than "not authenticated", that window would hand out
 * access rather than withhold it.
 *
 * Fail-closed behaviour is easy to assert after the fact and very hard to
 * notice losing — a refactor that returns `0`, `null` or `false` on one branch
 * looks equivalent and is not. These tests exist so the contract cannot drift
 * silently through the namespace rewrite or any later edit.
 *
 * Harness notes:
 *   - `set_up()` / `tear_down()` MUST be public — this WP test library fatals
 *     on protected ones (B56).
 *   - No DDL here, so the per-test transaction rollback is intact.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Tests\PHPUnit\Database
 */

declare( strict_types = 1 );

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- descriptive names.

namespace AcrossAI_MCP_Manager\Tests\PHPUnit\Database;

use AcrossAI_MCP_Manager\Includes\OAuth\TokenValidator;
use WP_UnitTestCase;

/**
 * Every refusal path must return the caller's value untouched.
 */
class TokenValidatorFailsClosedTest extends WP_UnitTestCase {

	/** Sentinel distinguishable from 0, null and false. */
	private const ANONYMOUS = 0;

	public function set_up(): void {
		parent::set_up();
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	/**
	 * No Authorization header at all.
	 */
	public function test_absent_bearer_token_returns_the_incoming_value(): void {
		$this->assertSame(
			self::ANONYMOUS,
			TokenValidator::instance()->authenticate( self::ANONYMOUS ),
			'A request carrying no bearer token must pass the incoming value straight through.'
		);
	}

	/**
	 * A well-formed token that matches no stored digest.
	 *
	 * This is the upgrade-window case: the table exists but the migration has
	 * not populated it yet, so every lookup misses.
	 */
	public function test_unknown_token_does_not_authenticate(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat( 'a', 64 );

		$this->assertSame(
			self::ANONYMOUS,
			TokenValidator::instance()->authenticate( self::ANONYMOUS ),
			'A digest miss must yield "not authenticated". This is the state during the '
				. 'upgrade window, and resolving to anything else would hand out access '
				. 'rather than withhold it.'
		);
	}

	/**
	 * Garbage in the Authorization header.
	 *
	 * @dataProvider provideMalformedHeaders
	 */
	public function test_malformed_authorization_header_does_not_authenticate( string $header ): void {
		$_SERVER['HTTP_AUTHORIZATION'] = $header;

		$this->assertSame(
			self::ANONYMOUS,
			TokenValidator::instance()->authenticate( self::ANONYMOUS ),
			'Malformed credentials must never resolve to a user.'
		);
	}

	public static function provideMalformedHeaders(): array {
		return array(
			'empty'            => array( '' ),
			'scheme only'      => array( 'Bearer' ),
			'scheme and space' => array( 'Bearer ' ),
			'wrong scheme'     => array( 'Basic ' . base64_encode( 'a:b' ) ),
			'no scheme'        => array( str_repeat( 'a', 64 ) ),
			'sql-ish'          => array( "Bearer ' OR 1=1 --" ),
		);
	}

	/**
	 * An already-authenticated request is passed through untouched.
	 *
	 * The validator must not downgrade a user resolved by cookies or an
	 * application password just because no bearer token is present.
	 */
	public function test_existing_user_is_never_downgraded(): void {
		$user_id = self::factory()->user->create();

		$this->assertSame(
			$user_id,
			TokenValidator::instance()->authenticate( $user_id ),
			'An already-authenticated request must survive the validator unchanged.'
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat( 'b', 64 );

		$this->assertSame(
			$user_id,
			TokenValidator::instance()->authenticate( $user_id ),
			'An unknown bearer token must not displace an already-resolved user.'
		);
	}

	/**
	 * The refusal value is the CALLER's, not a hardcoded zero.
	 *
	 * A branch returning a literal `0` would pass every test above while
	 * silently discarding whatever the previous filter resolved.
	 */
	public function test_refusal_echoes_the_callers_value_rather_than_a_literal(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat( 'c', 64 );

		$this->assertNull(
			TokenValidator::instance()->authenticate( null ),
			'Refusal must return the incoming value verbatim — null in, null out. '
				. 'A hardcoded 0 would discard what an earlier filter resolved.'
		);
	}
}
