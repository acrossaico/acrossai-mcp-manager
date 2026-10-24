<?php
/**
 * Request-scoped holder for resolved OAuth authentication context.
 *
 * `OAuth\TokenValidator::authenticate()` runs on `determine_current_user @ 20`,
 * resolves the bearer token's client row — and then discards it. By the time
 * the adapter's observability handler fires, nothing knows an OAuth client was
 * involved, so `connection_type` could never be better than `unknown`.
 *
 * This holder closes that gap. `TokenValidator` fires one scoped, fire-and-forget
 * action after a successful validation (D19); this listens and keeps the result
 * for the length of the request.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin-wide singleton per A11, in the shape of
 * `Includes\Abilities\CurrentServerHolder` — the canonical A17 implementation.
 *
 * A17's lifecycle is THREE stages, not two:
 *
 *   1. `rest_pre_dispatch`  @ 5    — reset, before any short-circuiting handler
 *   2. `rest_post_dispatch` @ 999  — clear on the normal path
 *   3. `shutdown`           @ 999  — clear again, as a safety net
 *
 * Stage 3 is not optional. A17 names its omission "the number-one silent bug in
 * ad-hoc versions of this pattern": on a long-lived process (FrankenPHP,
 * Roadrunner, wp-env with keep-alive) a fatal that kills the request before
 * `rest_post_dispatch` leaves the captured identity in memory, and the NEXT
 * request is then attributed to the previous request's client. In a log whose
 * entire purpose is attribution, that is the worst failure available.
 *
 * Hook wiring lives in `Includes\Main::define_admin_hooks()` per A1.
 */
final class AuthContextHolder {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Connector slug from the resolved OAuth client row, or '' when none.
	 *
	 * @var string
	 */
	private string $connector_slug = '';

	/**
	 * Client name from the resolved OAuth client row, or '' when none.
	 *
	 * @var string
	 */
	private string $client_name = '';

	/**
	 * Whether an OAuth bearer token authenticated this request at all.
	 *
	 * Tracked separately from `$connector_slug` because the two answer
	 * different questions: a bare DCR client authenticates by bearer token and
	 * has no connector profile, which is `oauth` rather than `ai-connector`.
	 * Collapsing them would make every bare client look like no client.
	 *
	 * @var bool
	 */
	private bool $is_oauth = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private — use ::instance(). Hook wiring lives in Main.php per A1.
	 */
	private function __construct() {}

	/**
	 * Listener for the scoped action `TokenValidator` fires after a successful
	 * bearer validation.
	 *
	 * Fire-and-forget per D19: the return value is ignored and nothing here may
	 * throw, because the validator's own flow must complete regardless. If this
	 * ever needs fail-forward semantics across multiple listeners, D39 requires
	 * per-listener isolation rather than native `do_action()`.
	 *
	 * Both params are typed `mixed` deliberately. `do_action()` passes whatever
	 * the caller passes, and a third party can re-fire this hook with anything.
	 * Declaring them `string` would make PHPStan treat the `is_string()` guards
	 * below as redundant — backwards, since those guards are what keeps a
	 * malformed payload from poisoning `connection_type` for the whole request.
	 *
	 * @param mixed $connector_slug Connector slug, or '' for a bare DCR client.
	 * @param mixed $client_name    Client display name, or ''.
	 */
	public function capture_oauth_context( $connector_slug = '', $client_name = '' ): void {
		$this->is_oauth       = true;
		$this->connector_slug = is_string( $connector_slug ) ? $connector_slug : '';
		$this->client_name    = is_string( $client_name ) ? $client_name : '';
	}

	/**
	 * Did an OAuth bearer token authenticate this request?
	 */
	public function is_oauth(): bool {
		return $this->is_oauth;
	}

	/**
	 * Connector slug, or '' when this is not a connector-backed client.
	 */
	public function get_connector_slug(): string {
		return $this->connector_slug;
	}

	/**
	 * Client name from the OAuth client row, or ''.
	 */
	public function get_client_name(): string {
		return $this->client_name;
	}

	/**
	 * Callback for `rest_pre_dispatch` @ priority 5 — A17 stage 1.
	 *
	 * Resets before dispatch. Priority 5 so it runs ahead of any handler that
	 * short-circuits at the default 10. Returns `$result` unchanged.
	 *
	 * @param mixed $result      Pre-dispatch short-circuit value.
	 * @param mixed $rest_server REST server (unused).
	 * @param mixed $request     The incoming request (unused).
	 * @return mixed
	 */
	public function reset_for_request( $result = null, $rest_server = null, $request = null ) {
		unset( $rest_server, $request );
		$this->wipe();
		return $result;
	}

	/**
	 * Callback for `rest_post_dispatch` @ 999 and `shutdown` @ 999 — A17 stages
	 * 2 and 3.
	 *
	 * Takes and returns a passthrough so one method can serve both a filter and
	 * an action.
	 *
	 * @param mixed $passthrough Value to return unchanged.
	 * @return mixed
	 */
	public function clear( $passthrough = null ) {
		$this->wipe();
		return $passthrough;
	}

	/**
	 * Drop every captured value.
	 */
	private function wipe(): void {
		$this->is_oauth       = false;
		$this->connector_slug = '';
		$this->client_name    = '';
	}
}
