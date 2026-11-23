<?php
/**
 * Feature 120 — the single place this plugin builds Site Kit's object graph.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit;

defined( 'ABSPATH' ) || exit;

/**
 * Memoised factory for Site Kit's Context, Authentication and Modules.
 *
 * Site Kit builds its own object graph inside Plugin::register() and exposes none of
 * it: Plugin::instance() hands out the Context and nothing else. So a consumer has to
 * construct its own Authentication and Modules on top of that shared Context, which is
 * exactly what Site Kit's own WP-CLI commands do (Core/CLI/Authentication_CLI_Command)
 * — this is the supported route, not a workaround.
 *
 * Everything is memoised per request. Modules::get_available_modules() instantiates
 * every module class on first call and builds the dependency graph, so rebuilding it
 * per ability would repeat that work for no gain.
 *
 * IMPORTANT — the objects here are bound to the CURRENT USER. Site Kit stores each
 * administrator's Google token in user meta, so Authentication and every module's API
 * client resolve against whoever is executing the ability. That is why nothing is
 * cached beyond the request and why an ability can be authorised by WordPress and
 * still have no Google credentials: the two are genuinely independent.
 */
final class Site_Kit_Context {

	/**
	 * Memoised objects, keyed by role.
	 *
	 * @var array<string,object|null>
	 */
	private static array $memo = array();

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Site Kit is loaded.
	 *
	 * Plugin is the probe rather than a version constant because it is the class every
	 * other lookup here goes through, so its presence also proves the autoloader is live.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( '\Google\Site_Kit\Plugin' )
			&& class_exists( '\Google\Site_Kit\Core\Modules\Modules' );
	}

	/**
	 * Forget everything built this request.
	 *
	 * Only tests and a deliberate user switch need this; nothing in normal execution
	 * does, because the whole graph is already per-request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$memo = array();
	}

	/**
	 * Site Kit's shared Context.
	 *
	 * @return object|null
	 */
	public static function context(): ?object {
		return self::memo(
			'context',
			static function (): ?object {
				$plugin = \Google\Site_Kit\Plugin::instance();
				return method_exists( $plugin, 'context' ) ? $plugin->context() : null;
			}
		);
	}

	/**
	 * Site-scoped option storage.
	 *
	 * @return object|null
	 */
	public static function options(): ?object {
		return self::memo(
			'options',
			static function (): ?object {
				$context = self::context();
				return null === $context ? null : new \Google\Site_Kit\Core\Storage\Options( $context );
			}
		);
	}

	/**
	 * User-scoped option storage for the current user.
	 *
	 * @return object|null
	 */
	public static function user_options(): ?object {
		return self::memo(
			'user_options',
			static function (): ?object {
				$context = self::context();
				return null === $context ? null : new \Google\Site_Kit\Core\Storage\User_Options( $context );
			}
		);
	}

	/**
	 * Authentication bound to the current user.
	 *
	 * @return object|null
	 */
	public static function authentication(): ?object {
		return self::memo(
			'authentication',
			static function (): ?object {
				$context = self::context();
				if ( null === $context ) {
					return null;
				}
				return new \Google\Site_Kit\Core\Authentication\Authentication(
					$context,
					self::options(),
					self::user_options(),
					new \Google\Site_Kit\Core\Storage\Transients( $context )
				);
			}
		);
	}

	/**
	 * The module registry.
	 *
	 * @return object|null
	 */
	public static function modules(): ?object {
		return self::memo(
			'modules',
			static function (): ?object {
				$context = self::context();
				if ( null === $context ) {
					return null;
				}
				return new \Google\Site_Kit\Core\Modules\Modules(
					$context,
					self::options(),
					self::user_options(),
					self::authentication()
				);
			}
		);
	}

	/**
	 * The current user's Key Metrics store, or null when this build has none.
	 *
	 * Per user: Key_Metrics_Settings extends User_Setting, so it resolves against
	 * whoever is executing, exactly like authentication does.
	 *
	 * NOT memoised. The others are request-scoped singletons because they are
	 * expensive to build; this one is a thin wrapper over user meta, and memoising it
	 * would quietly return a stale store after a write.
	 *
	 * @return object|null
	 */
	public static function key_metrics_settings(): ?object {
		$user_options = self::user_options();

		if ( null === $user_options || ! class_exists( '\Google\Site_Kit\Core\Key_Metrics\Key_Metrics_Settings' ) ) {
			return null;
		}

		try {
			return new \Google\Site_Kit\Core\Key_Metrics\Key_Metrics_Settings( $user_options );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The site-wide record of who completed Key Metrics setup, or null.
	 *
	 * @return object|null
	 */
	public static function key_metrics_setup_completed_by(): ?object {
		$options = self::options();

		if ( null === $options || ! class_exists( '\Google\Site_Kit\Core\Key_Metrics\Key_Metrics_Setup_Completed_By' ) ) {
			return null;
		}

		try {
			return new \Google\Site_Kit\Core\Key_Metrics\Key_Metrics_Setup_Completed_By( $options );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Site Kit's version string, or '' when it cannot be determined.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'GOOGLESITEKIT_VERSION' ) ? (string) GOOGLESITEKIT_VERSION : '';
	}

	/**
	 * Build once, reuse for the rest of the request.
	 *
	 * A constructor that throws — Site Kit deactivated mid-request, a shape change
	 * between versions — yields null rather than a fatal, which is what lets every
	 * ability degrade to a message instead of a 500.
	 *
	 * @param string   $key     Memo key.
	 * @param callable $factory Builder, run at most once.
	 * @return object|null
	 */
	private static function memo( string $key, callable $factory ): ?object {
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}

		$built = null;
		if ( self::available() ) {
			try {
				$built = $factory();
			} catch ( \Throwable $e ) {
				$built = null;
			}
		}

		self::$memo[ $key ] = $built;

		return $built;
	}
}
