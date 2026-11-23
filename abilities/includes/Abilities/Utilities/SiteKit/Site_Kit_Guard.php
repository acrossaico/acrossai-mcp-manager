<?php
/**
 * Feature 120 — shared guards, permission factory and response envelope for the
 * Site Kit ability suite.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\SiteKit
 * @since      0.0.39
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\SiteKit;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only guard + envelope helpers.
 *
 * Site Kit needs four gates where most integrations need one, and collapsing them
 * would make every failure read the same:
 *
 *   available      the plugin is loaded
 *   setup          somebody completed the Site Kit setup flow for this site
 *   authenticated  THIS user holds a Google token — tokens are per-user, so an
 *                  administrator who never connected has none even on a fully
 *                  set-up site
 *   connected      the specific module (Analytics, Search Console…) is active and
 *                  has the settings its API calls need
 *
 * Each one is a different thing for the operator to go and do, so each gets its own
 * error code and its own sentence.
 */
final class Site_Kit_Guard {

	/**
	 * Filter name allowing site owners to relax the capability policy.
	 */
	public const PERMISSION_FILTER = 'acrossai_abilities_manager_site_kit_permission';

	/**
	 * Site Kit's own capability for reading dashboard data.
	 *
	 * @see Google\Site_Kit\Core\Permissions\Permissions::VIEW_DASHBOARD
	 */
	public const CAP_VIEW = 'googlesitekit_view_dashboard';

	/**
	 * Site Kit's own capability for changing module configuration.
	 *
	 * @see Google\Site_Kit\Core\Permissions\Permissions::MANAGE_OPTIONS
	 */
	public const CAP_MANAGE = 'googlesitekit_manage_options';

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Assert Site Kit is loaded.
	 *
	 * Called first by every execute() as defence in depth: the bootstrap already gates
	 * registration, but the plugin can be deactivated after the abilities were
	 * registered in the same request.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_available() {
		if ( ! Site_Kit_Context::available() ) {
			return new WP_Error(
				'site_kit_missing',
				__( 'Site Kit by Google is not active.', 'acrossai-abilities-manager' )
			);
		}
		if ( null === Site_Kit_Context::context() ) {
			return new WP_Error(
				'site_kit_missing',
				__( 'Site Kit by Google is active but did not finish loading, so its data is unavailable this request.', 'acrossai-abilities-manager' )
			);
		}
		return true;
	}

	/**
	 * Assert somebody has completed Site Kit's setup flow for this site.
	 *
	 * Site-wide state, distinct from whether the caller personally connected.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_setup() {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$auth = Site_Kit_Context::authentication();
		if ( null === $auth || ! method_exists( $auth, 'is_setup_completed' ) ) {
			return new WP_Error(
				'site_kit_not_set_up',
				__( 'This Site Kit version does not report its setup state, so no data can be read.', 'acrossai-abilities-manager' )
			);
		}

		if ( ! $auth->is_setup_completed() ) {
			return new WP_Error(
				'site_kit_not_set_up',
				__( 'Site Kit has not been set up on this site yet. An administrator must complete the setup flow at Site Kit → Dashboard before any Google data can be read.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Assert the CURRENT user holds a Google token.
	 *
	 * This is the gate that surprises people, so its message says plainly whose
	 * problem it is. Site Kit stores tokens in user meta: an administrator with every
	 * WordPress capability, on a site where a colleague completed setup, still cannot
	 * read Google data until they connect their own account.
	 *
	 * @return true|WP_Error
	 */
	public static function assert_authenticated() {
		$setup = self::assert_setup();
		if ( is_wp_error( $setup ) ) {
			return $setup;
		}

		$auth = Site_Kit_Context::authentication();
		if ( null === $auth || ! method_exists( $auth, 'is_authenticated' ) || ! $auth->is_authenticated() ) {
			return new WP_Error(
				'site_kit_not_authenticated',
				__( 'The WordPress user running this ability has not connected their own Google account to Site Kit. Site Kit stores a separate token per user, so another administrator having connected is not enough. Connect at Site Kit → Dashboard; site-kit/get-status reports the URL.', 'acrossai-abilities-manager' )
			);
		}

		return true;
	}

	/**
	 * Assert a named module is active and connected.
	 *
	 * Active and connected are different failures — one is a switch, the other is
	 * missing configuration — so they are reported separately.
	 *
	 * @param string $slug Site Kit module slug, e.g. 'search-console', 'analytics-4'.
	 * @return true|WP_Error
	 */
	public static function assert_module_connected( string $slug ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$modules = Site_Kit_Context::modules();
		if ( null === $modules ) {
			return new WP_Error(
				'site_kit_missing',
				__( 'Site Kit\'s module registry is unavailable this request.', 'acrossai-abilities-manager' )
			);
		}

		try {
			if ( ! $modules->module_exists( $slug ) ) {
				return new WP_Error(
					'site_kit_module_unknown',
					sprintf(
						/* translators: %s: Site Kit module slug */
						__( 'This Site Kit version has no "%s" module. List what it does have with site-kit/list-modules.', 'acrossai-abilities-manager' ),
						$slug
					)
				);
			}

			if ( ! $modules->is_module_active( $slug ) ) {
				return new WP_Error(
					'site_kit_module_inactive',
					sprintf(
						/* translators: %s: Site Kit module slug */
						__( 'The Site Kit "%s" module is not active. Activate it with site-kit/set-module-state.', 'acrossai-abilities-manager' ),
						$slug
					)
				);
			}

			if ( ! $modules->is_module_connected( $slug ) ) {
				return new WP_Error(
					'site_kit_module_not_connected',
					sprintf(
						/* translators: %s: Site Kit module slug */
						__( 'The Site Kit "%s" module is active but not connected — it is missing the account or property settings its API calls need. Finish connecting it at Site Kit → Settings.', 'acrossai-abilities-manager' ),
						$slug
					)
				);
			}
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'site_kit_module_unknown',
				sprintf(
					/* translators: 1: module slug, 2: error message */
					__( 'Site Kit could not report on the "%1$s" module: %2$s', 'acrossai-abilities-manager' ),
					$slug,
					$e->getMessage()
				)
			);
		}

		return true;
	}

	/**
	 * Assert the caller explicitly opted into a consequential operation.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return true|WP_Error
	 */
	public static function assert_confirmed( array $input ) {
		if ( empty( $input['confirm'] ) ) {
			return new WP_Error(
				'confirmation_required',
				__( 'This changes how Site Kit collects data on the live site. Pass confirm: true to proceed.', 'acrossai-abilities-manager' )
			);
		}
		return true;
	}

	/**
	 * Check one of Site Kit's own capabilities.
	 *
	 * Site Kit maps these dynamically in Core\Permissions\Permissions, keyed off
	 * authentication state and dashboard-sharing configuration, so they are asked for
	 * by name through current_user_can() rather than re-derived here.
	 *
	 * @param string $cap Full capability name, or '' when none applies.
	 * @return bool
	 */
	public static function has_cap( string $cap ): bool {
		if ( '' === $cap ) {
			return true;
		}
		return current_user_can( $cap );
	}

	/**
	 * Build the permission_callback for an ability.
	 *
	 * Site Kit's capabilities are DYNAMIC — Permissions::check_all_for_current_user()
	 * grants googlesitekit_view_dashboard partly on the basis of authentication and
	 * dashboard sharing, so on a site nobody has connected yet, nobody holds it. Making
	 * it a hard requirement would mean site-kit/get-status — the one ability whose job
	 * is to explain that nothing is connected — could not run on the sites that need it
	 * most.
	 *
	 * So the floor is what actually authorises, and Site Kit's capability widens rather
	 * than narrows: an administrator always passes, and a non-administrator passes only
	 * when Site Kit itself has granted them the named capability. That is the same
	 * either/or shape the Rank Math suite uses, for the same reason — two capability
	 * models that genuinely disagree about who should be let in.
	 *
	 * @param string $sk_cap Site Kit capability, or '' for floor only.
	 * @param string $floor  WordPress capability floor.
	 * @return callable(): bool
	 */
	public static function can( string $sk_cap, string $floor = 'manage_options' ): callable {
		return static function () use ( $sk_cap, $floor ): bool {
			$floor_ok = current_user_can( $floor );
			$cap_ok   = self::has_cap( $sk_cap );
			$allowed  = $floor_ok || ( '' !== $sk_cap && $cap_ok );

			/**
			 * Filter whether the current user may execute a Site Kit ability.
			 *
			 * The result is bounded below by "clears the floor, or holds the Site Kit
			 * capability this ability names", so a filter can substitute one model for
			 * the other but cannot admit a user holding neither.
			 *
			 * @param bool   $allowed Result of floor OR Site Kit capability.
			 * @param string $sk_cap  Site Kit capability, '' when none applies.
			 * @param string $floor   WordPress capability floor.
			 */
			$filtered = (bool) apply_filters( self::PERMISSION_FILTER, $allowed, $sk_cap, $floor );

			return $filtered && $allowed;
		};
	}

	/**
	 * Build a success envelope.
	 *
	 * @param array<string,mixed> $payload Ability-specific keys.
	 * @param string              $message Human-readable summary.
	 * @return array<string,mixed>
	 */
	public static function ok( array $payload, string $message ): array {
		unset( $payload['success'], $payload['message'], $payload['error_code'] );
		return array_merge( array( 'success' => true ), $payload, array( 'message' => $message ) );
	}

	/**
	 * Build a failure envelope from a WP_Error.
	 *
	 * @param WP_Error            $error   Error to unwrap.
	 * @param array<string,mixed> $context Optional identifying input to echo back.
	 * @return array<string,mixed>
	 */
	public static function fail( WP_Error $error, array $context = array() ): array {
		return self::error( (string) $error->get_error_code(), (string) $error->get_error_message(), $context );
	}

	/**
	 * Build a failure envelope from an explicit code and message.
	 *
	 * @param string              $code    Machine-readable error code.
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $context Optional identifying input to echo back.
	 * @return array<string,mixed>
	 */
	public static function error( string $code, string $message, array $context = array() ): array {
		// Context often echoes caller-supplied input back. Strip the reserved envelope
		// keys so a caller can never spoof success or the error code.
		unset( $context['success'], $context['message'], $context['error_code'] );

		return array_merge(
			array( 'success' => false ),
			$context,
			array(
				'message'    => $message,
				'error_code' => $code,
			)
		);
	}
}
