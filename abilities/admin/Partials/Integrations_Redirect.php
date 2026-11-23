<?php
/**
 * Permanent redirect from the retired Ability Integrations page.
 *
 * Feature 102 removed that page. Its registration gate is gone, its task-family tabs became a
 * filter on the abilities list, and its third-party opt-ins moved to the settings screen. External
 * documentation and bookmarks still point at the old slug, so it 301s to the abilities page with
 * any `tab` argument carried across — the tab names are the same values the strip now filters by,
 * so a deep link keeps landing where the reader expected.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/admin/Partials
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Admin\Partials;

defined( 'ABSPATH' ) || exit;

/**
 * Sends the legacy Integrations URL to the merged abilities screen.
 */
class Integrations_Redirect {

	/**
	 * Singleton instance.
	 *
	 * @since 0.0.34
	 * @var   self|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since  0.0.34
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Private constructor — use instance().
	 *
	 * @since 0.0.34
	 */
	private function __construct() {}

	/**
	 * Redirect the legacy slug, if that is what is being requested.
	 *
	 * Hooked to **`admin_page_access_denied`**, and to `admin_init` at priority 1.
	 *
	 * `admin_init` alone does not work, and the reason is worth recording because it is easy to
	 * assume otherwise. Core denies an unregistered `page` argument inside
	 * `wp-admin/includes/menu.php:384`, which is reached from the
	 * `require ABSPATH . 'wp-admin/menu.php'` at `wp-admin/admin.php:163` — **seventeen lines
	 * before `do_action( 'admin_init' )` at line 180.** Once this submenu was deleted the old URL
	 * became exactly such a page, so no `admin_init` priority is early enough: the visitor gets
	 * "Sorry, you are not allowed to access this page" and the hook never runs.
	 * `admin_page_access_denied` fires at `menu.php:382`, immediately before that `wp_die()`, and
	 * exists for precisely this interception.
	 *
	 * `admin_init` priority 1 is kept as well, for the case where something else still registers
	 * the legacy slug — then access is *not* denied, the access hook never fires, and the stale
	 * page would render instead of redirecting. Registering both is safe: the method exits, and
	 * `redirect_target()` returns null for every other request.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public function maybe_redirect(): void {
		$target = $this->redirect_target();

		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Resolve the destination for the current request.
	 *
	 * Split from maybe_redirect() so the URL construction and the guards are testable without a
	 * real redirect and without `exit`.
	 *
	 * @since  0.0.34
	 * @return string|null Absolute admin URL, or null when this request is not ours.
	 */
	public function redirect_target(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading the page argument to route a GET, no state change.
		$page = $this->request_key( $_GET['page'] ?? null );

		if ( Menu::INTEGRATIONS_LEGACY_SLUG !== $page ) {
			return null;
		}

		$query = 'admin.php?page=' . Menu::ABILITIES_SLUG;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ditto.
		$tab = $this->request_key( $_GET['tab'] ?? null );

		if ( '' !== $tab ) {
			$query .= '&tab=' . $tab;
		}

		// Always rebuilt from admin_url() with a sanitised tab appended, so there is no
		// caller-supplied host or path anywhere in the destination — no open-redirect surface,
		// whatever arrives in the query string.
		return admin_url( $query );
	}

	/**
	 * Reduce one raw request value to a key, or to an empty string.
	 *
	 * `sanitize_key()` takes a string. Pass it `?page[]=x` and it raises a TypeError — which on
	 * this hook, at priority 1, would be a fatal on an arbitrary admin request rather than a
	 * failed redirect. So non-scalars are discarded before they get there.
	 *
	 * @since  0.0.34
	 * @param  mixed $value Raw value from the request.
	 * @return string Sanitised key, or '' when the value was absent or not scalar.
	 */
	private function request_key( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_key( (string) wp_unslash( $value ) );
	}
}
