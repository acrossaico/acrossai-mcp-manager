<?php
/**
 * Runtime override processor for Abilities module override management.
 *
 * Bridges DB-stored ability overrides (managed via the Abilities Manager UI) into live
 * WordPress ability registrations at request boot time.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Modules/Abilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Modules\Abilities;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Query;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Row;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// REST namespace used for PATH A (Manager request) detection.
// Override via wp-config.php constant or the 'acrossai_manager_rest_namespace' filter.
defined( 'ACROSSAI_MANAGER_REST_NAMESPACE' ) || define( 'ACROSSAI_MANAGER_REST_NAMESPACE', 'acrossai/v1' );

/**
 * Bridges DB-stored ability overrides into live WordPress ability registrations.
 *
 * PATH A (Manager REST requests): registers no hooks — Manager UI sees pure WP registry values.
 * PATH B (all other requests): injects non-null DB override fields via wp_register_ability_args
 * filter and unregisters abilities with site_allowed = false after all registrations complete.
 *
 * HOOK WIRING PATTERN (ARCH-ADV-001):
 * Only two hooks go through Main.php / Loader — plugins_loaded P20 (boot_hook) and
 * acrossai_abilities_after_create/update/delete (bust_cache_hook). The Loader always registers hooks
 * unconditionally, so it cannot express the PATH A / PATH B split. All downstream hooks
 * (wp_register_ability_args, wp_abilities_api_init, mcp_adapter_tool_call_result,
 * mcp_adapter_pre_tool_call) are registered conditionally inside boot() only when
 * is_manager_rest_request() returns false. This is an accepted deviation from the Boot Flow Rule.
 *
 * All logic is static. The singleton instance exists solely as a Loader-compatible hook target.
 * Direct static calls (e.g. AcrossAI_Ability_Override_Processor::bust_cache()) remain valid.
 *
 * @since 0.1.0
 */
final class AcrossAI_Ability_Override_Processor {

	/**
	 * Singleton instance.
	 *
	 * @var AcrossAI_Ability_Override_Processor|null
	 */
	protected static $instance = null;

	/**
	 * In-memory cache: slug → AcrossAI_Abilities_Row. Null means not yet loaded.
	 *
	 * @var AcrossAI_Abilities_Row[]|null
	 */
	protected static $overrides_cache = null;


	/**
	 * Whether is_manager_rest_request() has already been evaluated this request.
	 *
	 * @var bool
	 */
	protected static $checked = false;

	/**
	 * Memoized result of is_manager_rest_request().
	 *
	 * @var bool
	 */
	protected static $is_manager = false;

	// -------------------------------------------------------------------------
	// Singleton
	// -------------------------------------------------------------------------

	/**
	 * Get or create the singleton instance.
	 *
	 * @since  0.1.0
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Capability required by an ability with no rule of its own.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Option that can move the default floor site-wide.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const DEFAULT_CAPABILITY_OPTION = 'acrossai_default_ability_capability';

	/**
	 * Slug prefixes whose permission callback must never be replaced.
	 *
	 * @since 0.0.34
	 * @var   string[]
	 */
	private const ROUTER_PREFIXES = array( 'toolset/', 'mcp-adapter/' );

	/**
	 * Private constructor — instantiation via instance() only.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	// -------------------------------------------------------------------------
	// Loader-compatible instance wrappers (SEC-PLAN-002)
	// -------------------------------------------------------------------------
	//
	// The Loader in Main.php passes array( $component, $callback ) to WordPress where
	// $component must be an object (PHPStan L8 requires this). These two instance methods
	// are the only hooks wired through Main.php/Loader — they delegate immediately to their
	// static counterparts. All other hooks are registered conditionally inside boot() because
	// they must be absent on Manager REST requests (PATH A). See ARCH-ADV-001 in plan.md.

	/**
	 * Loader-compatible wrapper for boot(). Wired at plugins_loaded P20 via Main.php.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function boot_hook(): void {
		self::boot();
	}

	/**
	 * Loader-compatible wrapper for bust_cache(). Wired at acrossai_abilities_after_create/update/delete via Main.php.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public function bust_cache_hook(): void {
		self::bust_cache();
	}

	// -------------------------------------------------------------------------
	// Static core methods
	// -------------------------------------------------------------------------

	/**
	 * Boot the override processor at plugins_loaded P20.
	 *
	 * On PATH A (Manager REST requests) returns immediately without registering any hooks —
	 * the Manager UI always sees pure WP registry values for the _registry layer (FR-003).
	 * On PATH B registers all downstream hooks directly via add_filter()/add_action().
	 *
	 * WHY HOOKS ARE REGISTERED HERE (ARCH-ADV-001):
	 * The Loader in Main.php always registers hooks unconditionally. Because these hooks
	 * must be completely absent on PATH A (Manager REST), they cannot go through the Loader —
	 * conditional wiring cannot be expressed there. boot() is the only place where the
	 * PATH A / PATH B decision has been made and acted on.
	 *
	 * Hooks registered here (PATH B only):
	 *   wp_register_ability_args P100000   — inject_override_args()
	 *   wp_abilities_api_init    P100001   — unregister_blocked_abilities()
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public static function boot(): void {
		// FR-003 / SEC-PLAN-001: PATH A — Manager REST skips override injection entirely.
		if ( self::is_manager_rest_request() ) {
			return;
		}

		// PATH B — all downstream hooks registered here, not in Main.php, because they must
		// be skipped entirely on PATH A. See ARCH-ADV-001 in plan.md and the docblock above.

		// Inject non-null DB override values into each ability's args during registration.
		add_filter( 'wp_register_ability_args', array( __CLASS__, 'inject_override_args' ), 100000, 2 );

		// Unregister abilities with site_allowed = false after all plugin registrations complete.
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'unregister_blocked_abilities' ), 100001 );
	}

	/**
	 * Detect whether the current request targets the Manager's own REST namespace.
	 *
	 * Performance optimisation only — NOT an access-control gate. Even if a spoofed URI
	 * triggers PATH A treatment the only consequence is that override injection is skipped;
	 * all REST routes remain protected by check_permission() independently.
	 *
	 * Detection is URI-path-based only. REQUEST_METHOD is NOT used as a gate (SEC-PLAN-001)
	 * so that Manager GET requests are correctly classified as PATH A.
	 *
	 * Memoized across repeated calls within the same request lifecycle.
	 *
	 * @since  0.1.0
	 * @return bool True when the current request is a Manager REST API request (PATH A).
	 */
	public static function is_manager_rest_request(): bool {
		if ( self::$checked ) {
			return self::$is_manager;
		}

		self::$checked = true;

		// Non-HTTP contexts are never Manager REST requests.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			self::$is_manager = false;
			return false;
		}

		if ( wp_doing_cron() ) {
			self::$is_manager = false;
			return false;
		}

		if ( wp_doing_ajax() ) {
			self::$is_manager = false;
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		$namespace        = (string) apply_filters( 'acrossai_manager_rest_namespace', ACROSSAI_MANAGER_REST_NAMESPACE );
		self::$is_manager = ( '' !== $uri ) &&
			false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' . $namespace . '/' );

		return self::$is_manager;
	}

	/**
	 * Load override rows from transient or DB into the in-memory static cache.
	 *
	 * Transient key: acrossai_ability_overrides_cache, TTL: 12h.
	 * Returns immediately if cache is already populated for this request.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	private static function load_overrides_cache(): void {
		if ( null !== self::$overrides_cache ) {
			return;
		}

		$cached = get_transient( 'acrossai_ability_overrides_cache' );

		// SEC-PLAN-003: Validate transient output before use — treat non-array as cache miss
		// (guards against corrupted object-cache entries or DB row corruption).
		if ( ! is_array( $cached ) ) {
			$cached = null;
		}

		if ( null === $cached ) {
			$cached = AcrossAI_Abilities_Query::instance()->get_all_overrides();
			set_transient( 'acrossai_ability_overrides_cache', $cached, 12 * HOUR_IN_SECONDS );
		}

		self::$overrides_cache = $cached;
	}

	/**
	 * Filter callback: inject non-null DB override values into each ability's registration args.
	 *
	 * Registered at wp_register_ability_args P10 on PATH B only. Null DB values are skipped —
	 * null means "Inherit" and the registration-time default is preserved (FR-006).
	 *
	 * Field path map (FR-009):
	 *   site_allowed              → $args['site_allowed']  (top-level WP Abilities API field)
	 *   label                     → $args['label']           (top-level WP Abilities API field)
	 *   description               → $args['description']     (top-level WP Abilities API field)
	 *   category                  → $args['category']        (top-level WP Abilities API field)
	 *   readonly/destructive/
	 *     idempotent              → $args['meta']['annotations']['<key>']
	 *   show_in_rest              → $args['meta']['show_in_rest']
	 *   show_in_mcp               → $args['meta']['mcp']['public']   (plugin-specific)
	 *   mcp_type                  → $args['meta']['mcp']['type']     (plugin-specific)
	 *   permission_callback       → $args['permission_callback']     (runtime AC enforcement;
	 *                               injected only when an access-control rule is stored in
	 *                               RuleQuery for this slug — checked independently of the
	 *                               override row)
	 *
	 * meta['mcp'] is not a WP core Abilities API field. It is consumed by the MCP integration
	 * layer of this plugin only. See FR-009 and the Constraints Assumption in spec.md.
	 *
	 * @since  0.1.0
	 * @param  array  $args Ability registration args.
	 * @param  string $slug Ability slug.
	 * @return array Modified args.
	 */
	public static function inject_override_args( array $args, string $slug ): array {
		self::load_overrides_cache();

		// Inject DB override fields when a record exists for this slug.
		if ( isset( self::$overrides_cache[ $slug ] ) ) {
			$row = self::$overrides_cache[ $slug ];

			// Top-level fields — skip null/empty to preserve Inherit semantics (FR-006).
			if ( null !== $row->site_allowed ) {
				$args['site_allowed'] = $row->site_allowed;
			}
			if ( null !== $row->label && '' !== $row->label ) {
				$args['label'] = $row->label;
			}
			if ( null !== $row->description && '' !== $row->description ) {
				$args['description'] = $row->description;
			}
			if ( null !== $row->category && '' !== $row->category ) {
				$args['category'] = $row->category;
			}

			// Initialize meta array once if any nested override is present.
			$needs_meta = null !== $row->readonly || null !== $row->destructive || null !== $row->idempotent
				|| null !== $row->show_in_rest || null !== $row->show_in_mcp || null !== $row->mcp_type;

			if ( $needs_meta && ( ! isset( $args['meta'] ) || ! is_array( $args['meta'] ) ) ) {
				$args['meta'] = array();
			}

			// Annotations → $args['meta']['annotations']['<key>'].
			if ( null !== $row->readonly || null !== $row->destructive || null !== $row->idempotent ) {
				if ( ! isset( $args['meta']['annotations'] ) || ! is_array( $args['meta']['annotations'] ) ) {
					$args['meta']['annotations'] = array();
				}
				if ( null !== $row->readonly ) {
					$args['meta']['annotations']['readonly'] = $row->readonly;
				}
				if ( null !== $row->destructive ) {
					$args['meta']['annotations']['destructive'] = $row->destructive;
				}
				if ( null !== $row->idempotent ) {
					$args['meta']['annotations']['idempotent'] = $row->idempotent;
				}
			}

			// show_in_rest → $args['meta']['show_in_rest'].
			if ( null !== $row->show_in_rest ) {
				$args['meta']['show_in_rest'] = $row->show_in_rest;
			}

			// MCP block → $args['meta']['mcp']['<key>'] (plugin-specific; not WP core).
			if ( null !== $row->show_in_mcp || null !== $row->mcp_type ) {

				if ( ! isset( $args['meta']['mcp'] ) || ! is_array( $args['meta']['mcp'] ) ) {
					$args['meta']['mcp'] = array();
				}
				if ( null !== $row->show_in_mcp ) {
					$args['meta']['mcp']['public'] = $row->show_in_mcp;
				}
				if ( null !== $row->mcp_type ) {
					$args['meta']['mcp']['type'] = $row->mcp_type;
				}
			}
		}

		/*
		 * permission_callback: this plugin owns the lock on every ability, whoever registered it.
		 *
		 * Measured across the third-party abilities on one install: three registered with
		 * `__return_true` and no check at all, two at `read`, and a content WRITER at `edit_posts`.
		 * The author's callback is replaced rather than composed with, so the answer comes from one
		 * place an operator can see and change.
		 *
		 * Routers are the exception, for the reason on ROUTER_PREFIXES.
		 */
		if ( ! self::is_router( $slug ) ) {
			$args['permission_callback'] = self::build_permission_callback( $slug, $args['permission_callback'] ?? null );
		}

		return $args;
	}

	/**
	 * Build the permission_callback that replaces an ability's own.
	 *
	 * WRAPS rather than discards. Our floor runs first and can only tighten; if it allows, the
	 * ability's registration-time callback is then consulted and can still refuse. Both must agree,
	 * so the floor stays absolute while the plugin's own rule survives.
	 *
	 * Replacing outright raised every weak lock, which was the point of Feature 115 — but it also
	 * threw away everything else a callback carried beyond a capability test. Measured across the
	 * abilities on one site: 26 third-party callbacks were being discarded, 9 of them per-object
	 * rules whose answer depends on the call's input. WPForms was the clearest case — its write
	 * kill-switch lives in its permission callback and its execute callback does not re-check, so
	 * the switch did nothing and its per-form capability checks collapsed into one site-wide
	 * `manage_options` (issue #210).
	 *
	 * Their WP_Error is passed through rather than flattened to false: `check_permissions()` accepts
	 * one and it carries a named reason, so a caller learns `wpforms_writes_disabled` instead of a
	 * bare refusal.
	 *
	 * On WordPress 7.1+ core offers `wp_ability_permission_result` for exactly this layering. The
	 * plugin supports 6.9, where that filter does not exist, so the wrapping is done here.
	 *
	 * @since  0.1.0
	 * @since  0.0.34 Wraps the original callback instead of discarding it.
	 * @param  string $slug     Ability slug.
	 * @param  mixed  $original The callback the ability registered with, if any.
	 * @return callable Closure returning bool|WP_Error.
	 */
	private static function build_permission_callback( string $slug, $original = null ): callable {
		return static function ( $input = null ) use ( $slug, $original ) {
			if ( ! AcrossAI_Ability_Override_Processor::user_has_ability_access( $slug, \get_current_user_id() ) ) {
				return false;
			}

			return AcrossAI_Ability_Override_Processor::defer_to_original( $slug, $original, $input );
		};
	}

	/**
	 * Consult the ability's own permission callback, after our floor has already allowed.
	 *
	 * Fails CLOSED when the callback throws. Before Feature 115 a throw here was the registering
	 * plugin's own problem on its own callback; now we are the caller, and "we could not work out
	 * the answer" has to mean denied — the same reasoning that made the branches above fail closed.
	 * Catching also stops one third-party bug from fataling every ability call on the site
	 * (Constitution §V Integration Resilience, SEC-001).
	 *
	 * @internal public by necessity — called from the closure above.
	 *
	 * @since  0.0.34
	 * @param  string $slug     Ability slug.
	 * @param  mixed  $original The callback the ability registered with, if any.
	 * @param  mixed  $input    Input passed to the permission check.
	 * @return bool|\WP_Error
	 */
	public static function defer_to_original( string $slug, $original, $input = null ) {
		if ( ! is_callable( $original ) ) {
			return true;
		}

		try {
			$theirs = $original( $input );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'AcrossAI: permission callback for [%s] threw, refusing: %s',
						$slug,
						$e->getMessage()
					)
				);
			}

			return false;
		}

		if ( \is_wp_error( $theirs ) ) {
			return $theirs;
		}

		return (bool) $theirs;
	}

	/**
	 * Whether the current user clears the default floor.
	 *
	 * Its own method so the decision is testable without the access-control manager. The manager
	 * sits on BerlinDB and therefore on a database, which the unit harness does not have — so a test
	 * calling through `user_has_ability_access()` cannot reach either fallback branch without a full
	 * WordPress. The wiring is asserted at source level instead, and the whole path is exercised
	 * live.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function floor_allows(): bool {
		return \current_user_can( self::default_capability() );
	}

	/**
	 * The capability an ability requires when no rule names something else.
	 *
	 * `manage_options`, matching every first-party suite. Filterable and option-backed so a site that
	 * genuinely needs a lower floor can move it once rather than writing a rule per ability.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public static function default_capability(): string {
		$stored = (string) get_option( self::DEFAULT_CAPABILITY_OPTION, '' );
		$floor  = '' !== $stored ? $stored : self::DEFAULT_CAPABILITY;

		/**
		 * Filters the capability required by an ability with no access rule of its own.
		 *
		 * @since 0.0.34
		 * @param string $floor Default capability.
		 */
		$filtered = (string) apply_filters( 'acrossai_default_ability_capability', $floor );

		return '' !== $filtered ? $filtered : self::DEFAULT_CAPABILITY;
	}

	/**
	 * Whether this slug is a router rather than a door.
	 *
	 * Routers do structural work in their permission callback — the
	 * `acrossai_toolset_ability_not_exposed` 403 for an ability hidden from this server, and a
	 * pre-check of the target — none of which a capability test can express. Replacing it would make
	 * hidden abilities reachable again, collapsing the per-server EXPOSURE layer into the permission
	 * layer. They answer two different questions and must stay two.
	 *
	 * Nothing is lost by exempting them: every ability they route to is gated here.
	 *
	 * @since  0.0.34
	 * @param  string $slug Ability slug.
	 * @return bool
	 */
	private static function is_router( string $slug ): bool {
		foreach ( self::ROUTER_PREFIXES as $prefix ) {
			if ( 0 === strpos( $slug, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Action callback: unregister all abilities with site_allowed = false.
	 *
	 * Registered at wp_abilities_api_init P100001 on PATH B only — fires after all plugin
	 * registrations are complete so no subsequent registration can restore a blocked ability.
	 * Abilities with site_allowed = null (Inherit) are not touched.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public static function unregister_blocked_abilities(): void {
		self::load_overrides_cache();

		foreach ( self::$overrides_cache as $slug => $row ) {
			if ( \wp_has_ability( $slug ) && false === $row->site_allowed ) {
				\wp_unregister_ability( $slug );
			}
		}
	}

	/**
	 * Check whether the given user has access to an ability per AC rules.
	 *
	 * Fail-CLOSED: when the AC library is absent or no rule is configured the answer falls back to
	 * the default capability floor, not to true. The summary here said "fail-open" until Feature 115,
	 * describing the behaviour Feature 115 replaced — directly above a body comment saying the
	 * opposite.
	 *
	 * Any caller relying on this helper alone must pair it with WP_Ability::check_permissions()
	 * as the authoritative gate — AC rules are fail-open in absence
	 * (BUG-INJECT-MCP-TOOLS-PERMISSION-BYPASS).
	 *
	 * @since  0.1.0
	 * @param  string $slug    Ability slug.
	 * @param  int    $user_id WordPress user ID.
	 * @return bool True when access is granted or no rule applies.
	 */
	public static function user_has_ability_access( string $slug, int $user_id ): bool {
		if ( ! self::resolve_access( $slug, $user_id ) ) {
			return false;
		}

		/**
		 * Filters whether an otherwise-permitted ability is refused by an integration.
		 *
		 * DENY-ONLY, and deliberately so: it runs only after access has already been granted, and a
		 * callback can turn an allow into a deny but never a deny into an allow. Same direction as
		 * PATTERN-FILTERABLE-CAPABILITY-RAISE-ONLY, and for the same reason — a filter that could
		 * widen access would hand any plugin on the site the power to unlock every ability.
		 *
		 * It exists because this method is now the ONLY gate. Replacing each ability's own
		 * permission callback (Feature 115) also discarded the conditions those callbacks carried
		 * beyond a capability test — a plugin's kill-switch, a per-object check, a beta guard. An
		 * integration that knows about such a condition has nowhere else to enforce it, so its
		 * switch would appear to work while doing nothing. See issue #210 for the general question
		 * of wrapping rather than replacing.
		 *
		 * @since 0.0.34
		 * @param bool   $refused Whether to refuse. Always false at this point.
		 * @param string $slug    Ability slug.
		 * @param int    $user_id WordPress user ID.
		 */
		return ! (bool) apply_filters( 'acrossai_ability_access_refused', false, $slug, $user_id );
	}

	/**
	 * The access decision itself, before any integration refusal.
	 *
	 * @since  0.0.34
	 * @param  string $slug    Ability slug.
	 * @param  int    $user_id WordPress user ID.
	 * @return bool
	 */
	private static function resolve_access( string $slug, int $user_id ): bool {
		$manager = AcrossAI_Abilities_Access_Control::instance()->get_manager();

		/*
		 * Fail CLOSED, in both branches. This returned true in each case, which was defensible while
		 * the ability's own callback was still the real gate — it no longer is. Once this is the only
		 * lock on the door, "we could not work out the answer" has to mean denied, or an absent
		 * library silently opens every ability on the site.
		 */
		if ( null === $manager ) {
			return self::floor_allows();
		}

		$rule = $manager->get_query()->get_rule( 'acrossai-abilities', $slug );

		if ( '' === $rule['key'] ) {
			return self::floor_allows();
		}

		return $manager->user_has_access( $user_id, 'acrossai-abilities', $slug );
	}

	/**
	 * Clear the in-memory cache and the transient.
	 *
	 * Called directly from REST controllers after delete/reset operations that do not
	 * fire Abilities lifecycle hooks (acrossai_abilities_after_create/update/delete, W-001 resolution). Also wired as the
	 * bust_cache_hook() instance wrapper target for the Loader action.
	 *
	 * @internal public by necessity — hook callback + cross-controller direct call.
	 *
	 * @since  0.1.0
	 * @return void
	 */
	public static function bust_cache(): void {
		delete_transient( 'acrossai_ability_overrides_cache' );
		self::$overrides_cache = null;
	}
}
