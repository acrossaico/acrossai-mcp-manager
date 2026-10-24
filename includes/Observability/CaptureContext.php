<?php
/**
 * The ambient "we are inside ability X of request N" context (Feature 096).
 *
 * Two features need this and neither should own it. 096 reads it to set
 * `parent_id` on an ability event. **Feature 097 arms its change recorder from
 * the same context** — that is the entire interface between the two features,
 * and it is why this is a general class with a public reader rather than a
 * private field of the ability recorder.
 *
 * Lives in `Includes\Observability`, not `Includes\History`: that name
 * describes 097's domain, and a directory owned jointly by two features at
 * different maturity levels satisfies neither "singular purpose" nor
 * "replaceable without affecting sibling modules".
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Observability
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Observability;

defined( 'ABSPATH' ) || exit;

/**
 * Request-scoped capture context.
 *
 * Carries the A17 three-stage lifecycle like the other holders — including the
 * `shutdown` stage, whose omission A17 names "the number-one silent bug in
 * ad-hoc versions of this pattern". Here the consequence would be an ability
 * attributed to a request that ended in the *previous* HTTP cycle.
 *
 * Hook wiring lives in Main.php per A1.
 */
final class CaptureContext {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $_instance = null;

	/**
	 * Activity id of the MCP request currently in flight, or 0.
	 *
	 * @var int
	 */
	private int $activity_id = 0;

	/**
	 * Nesting depth of armed abilities.
	 *
	 * A depth counter rather than a boolean: an ability can legitimately invoke
	 * another (a toolset dispatcher calling a member ability is the common
	 * case), and a boolean would disarm on the inner one's completion while the
	 * outer was still running.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * The ability name at each depth, innermost last.
	 *
	 * @var array<int, string>
	 */
	private array $stack = array();

	/**
	 * Row ids of ability events written during this request, awaiting a parent.
	 *
	 * An ability event is written WHILE the request runs; the request's own row
	 * is written at the end, once its duration is known. So at the moment a
	 * child is stored its parent does not exist and has no id — which is why
	 * `set_activity_id()` alone could never have linked them, and why the ids
	 * are collected here and adopted afterwards instead.
	 *
	 * @var array<int, int>
	 */
	private array $child_ids = array();

	/**
	 * Hard cap on remembered children, so a pathological request cannot grow
	 * this without bound. Beyond it, further children stay unlinked rather than
	 * the request accumulating memory — losing a link is recoverable, exhausting
	 * memory inside a logging path is not.
	 */
	private const MAX_CHILDREN = 500;

	/** Singleton accessor. */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/** Private — wiring lives in Main.php per A1. */
	private function __construct() {}

	/**
	 * Run a callable with capture armed for one ability.
	 *
	 * **Both choke points delegate here rather than writing their own
	 * `try`/`finally`.** Two hand-written brackets around the same contract is
	 * where drift starts: a later fix to arming or disarming applied to one
	 * path and not the other produces a fault visible through only one
	 * dispatcher, which is the hardest version of this bug to find. It also
	 * means one test covers both paths.
	 *
	 * The `finally` is not optional. An ability that throws must still disarm,
	 * or every subsequent write in the request is attributed to an ability that
	 * already ended — and 097's change recorder inherits that attribution.
	 *
	 * @param string   $ability Ability name being executed.
	 * @param callable $run     The work to perform.
	 * @return mixed Whatever $run returns, untouched.
	 */
	public function around( string $ability, callable $run ) {
		$this->arm( $ability );

		try {
			return $run();
		} finally {
			$this->disarm();
		}
	}

	/**
	 * Arm capture for one ability.
	 *
	 * Public so a caller that genuinely cannot use `around()` — a generator, or
	 * code that must return before the ability completes — can still
	 * participate. Such a caller MUST pair this with `disarm()` in a `finally`.
	 * Prefer `around()`.
	 *
	 * @param string $ability Ability name.
	 */
	public function arm( string $ability ): void {
		++$this->depth;
		$this->stack[] = $ability;
	}

	/** Disarm one level. */
	public function disarm(): void {
		if ( $this->depth > 0 ) {
			--$this->depth;
			array_pop( $this->stack );
		}
	}

	/** Is an ability currently being captured? */
	public function is_armed(): bool {
		return $this->depth > 0;
	}

	/** Nesting depth; 0 when not armed. */
	public function depth(): int {
		return $this->depth;
	}

	/**
	 * The innermost armed ability name, or '' when not armed.
	 */
	public function current_ability(): string {
		if ( empty( $this->stack ) ) {
			return '';
		}

		return (string) $this->stack[ count( $this->stack ) - 1 ];
	}

	/**
	 * Record which MCP request is in flight.
	 *
	 * Set by the observability handler once it knows the request's row id, so
	 * ability events recorded afterwards can point at it.
	 *
	 * @param int $activity_id Request event id, or 0 to clear.
	 */
	public function set_activity_id( int $activity_id ): void {
		$this->activity_id = max( 0, $activity_id );
	}

	/**
	 * The MCP request event this ability belongs to, or 0 when there is none.
	 *
	 * 0 is a correct and common answer: an ability run over the REST abilities
	 * route, through WP-CLI or from the admin UI has no MCP parent, and
	 * `invoked_via` is what distinguishes those.
	 *
	 * **Feature 097 reads this** to attach its change rows to the same call.
	 */
	public function activity_id(): int {
		return $this->activity_id;
	}

	/**
	 * Remember one ability row written during this request.
	 *
	 * @param int $child_id Row id just written. Ignored when 0 (a failed write).
	 */
	public function remember_child( int $child_id ): void {
		if ( $child_id <= 0 || count( $this->child_ids ) >= self::MAX_CHILDREN ) {
			return;
		}

		$this->child_ids[] = $child_id;
	}

	/**
	 * Hand back the remembered children AND forget them.
	 *
	 * Take-and-clear rather than a plain getter: adopting is a one-shot
	 * transfer, and a request that emitted two `mcp.request` events must not
	 * re-adopt the first event's children into the second.
	 *
	 * @return array<int, int>
	 */
	public function take_children(): array {
		$ids             = $this->child_ids;
		$this->child_ids = array();

		return $ids;
	}

	/**
	 * How many children are waiting to be adopted. Reads without consuming.
	 */
	public function pending_children(): int {
		return count( $this->child_ids );
	}

	/**
	 * Callback for `rest_pre_dispatch` @ 5 — A17 stage 1.
	 *
	 * @param mixed $result      Pre-dispatch short-circuit value.
	 * @param mixed $rest_server REST server (unused).
	 * @param mixed $request     Request (unused).
	 * @return mixed
	 */
	public function reset_for_request( $result = null, $rest_server = null, $request = null ) {
		unset( $rest_server, $request );
		$this->wipe();
		return $result;
	}

	/**
	 * Callback for `rest_post_dispatch` @ 999 and `shutdown` @ 999 — stages 2 and 3.
	 *
	 * @param mixed $passthrough Value to return unchanged.
	 * @return mixed
	 */
	public function clear( $passthrough = null ) {
		$this->wipe();
		return $passthrough;
	}

	/** Drop all state. */
	private function wipe(): void {
		$this->activity_id = 0;
		$this->depth       = 0;
		$this->stack       = array();
		// A17 stage 3. Leaving these behind would have the NEXT request adopt
		// the previous one's ability rows — the exact cross-request attribution
		// this lifecycle exists to prevent.
		$this->child_ids = array();
	}
}
