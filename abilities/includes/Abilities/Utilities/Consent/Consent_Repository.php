<?php
/**
 * Feature 118 — the only place consent data is read or written.
 *
 * Everything routes through the consent plugin's own controllers, never through `$wpdb`, and the
 * reason is the whole justification for this suite. The banner a visitor sees is rendered HTML held
 * in the `cky_banner_template` option, and that cache is invalidated by ACTIONS, not by writes
 * (`lite/admin/modules/banners/includes/class-template.php:139-142`):
 *
 *     add_action( 'cky_after_update_cookie',          array( $this, 'clear_template' ) );
 *     add_action( 'cky_after_update_cookie_category', array( $this, 'clear_template' ) );
 *     add_action( 'cky_after_update_banner',          array( $this, 'clear_template' ) );
 *
 * Nothing watches the tables. A row written with `database/*` inserts correctly, reads back
 * correctly, and the visitor keeps seeing the previous banner and the previous preference centre —
 * BUG-WRITE-REPORTED-WITHOUT-READ-BACK, after the WPCode snippet cache and Loco's four artefacts.
 * So every write here goes through a controller and then PROVES the template moved.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Consent
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes for cookies, categories and banners.
 *
 * @since 0.0.34
 */
final class Consent_Repository {

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Languages the banner is built for.
	 *
	 * Every multilingual field is rebuilt against this list by the plugin's own setters, which fill
	 * a missing language with an empty string — so a partial write silently blanks the others. Read
	 * it, merge into it, never replace it.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public static function languages(): array {
		$languages = function_exists( 'cky_selected_languages' ) ? cky_selected_languages() : array( 'en' );

		return is_array( $languages ) && array() !== $languages ? array_values( $languages ) : array( 'en' );
	}

	/**
	 * @since  0.0.34
	 * @return \CookieYes\Lite\Admin\Modules\Cookies\Includes\Cookie_Controller
	 */
	private static function cookie_controller() {
		return \CookieYes\Lite\Admin\Modules\Cookies\Includes\Cookie_Controller::get_instance();
	}

	/**
	 * @since  0.0.34
	 * @return \CookieYes\Lite\Admin\Modules\Cookies\Includes\Category_Controller
	 */
	private static function category_controller() {
		return \CookieYes\Lite\Admin\Modules\Cookies\Includes\Category_Controller::get_instance();
	}

	/**
	 * @since  0.0.34
	 * @return \CookieYes\Lite\Admin\Modules\Banners\Includes\Controller
	 */
	private static function banner_controller() {
		return \CookieYes\Lite\Admin\Modules\Banners\Includes\Controller::get_instance();
	}

	/**
	 * A stable signature of the rendered banner, per language.
	 *
	 * Hashed rather than returned: the template is tens of kilobytes of HTML per language, and the
	 * only question asked of it is "did this change".
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function template_fingerprint(): array {
		$template = get_option( Consent_Guard::TEMPLATE_OPTION, array() );

		if ( ! is_array( $template ) ) {
			return array( '_raw' => md5( (string) $template ) );
		}

		$out = array();

		foreach ( $template as $lang => $html ) {
			$out[ (string) $lang ] = md5( is_array( $html ) ? (string) wp_json_encode( $html ) : (string) $html );
		}

		return $out;
	}

	/**
	 * State of the rendered banner.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function template_state(): array {
		$template = get_option( Consent_Guard::TEMPLATE_OPTION, array() );
		$langs    = array();

		if ( is_array( $template ) ) {
			foreach ( $template as $lang => $html ) {
				$langs[ (string) $lang ] = strlen( is_array( $html ) ? (string) wp_json_encode( $html ) : (string) $html );
			}
		}

		return array(
			'languages'      => $langs,
			'rendered'       => array() !== $langs,
			'pending_rebuild' => array() === $langs || 0 === (int) array_sum( $langs ),
		);
	}

	/**
	 * Confirm a write actually reached the thing a visitor sees.
	 *
	 * The plugin clears the template on `cky_after_update_*`, so a successful write leaves the
	 * fingerprint DIFFERENT — either emptied pending rebuild, or already rebuilt. Unchanged means
	 * the action never fired, which is exactly the silent failure this suite exists to prevent.
	 *
	 * @since  0.0.34
	 * @param  array<string, string> $before Fingerprint taken before the write.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function prove_template_moved( array $before ) {
		$state = self::template_state();

		/*
		 * A cleared template IS the proof: `clear_template()` empties the option and the banner is
		 * rebuilt on next need, so "pending rebuild" means the hook fired.
		 *
		 * Checked before the fingerprint comparison, and that order is load-bearing. The first write
		 * clears the template; a second write in the same request then compares empty against empty,
		 * finds them identical, and a fingerprint-only test reports a perfectly good write as a
		 * failure. Measured: create-then-update returned `banner_not_refreshed` on the update.
		 */
		if ( true === $state['pending_rebuild'] ) {
			return array_merge( array( 'banner_refreshed' => true ), $state );
		}

		if ( $before === self::template_fingerprint() ) {
			return new WP_Error(
				'banner_not_refreshed',
				__( 'The record was written but the rendered banner still holds the previous version, so visitors would keep seeing the old one. The consent plugin refreshes the banner on its own save hooks; this write did not reach them.', 'acrossai-abilities-manager' )
			);
		}

		return array_merge( array( 'banner_refreshed' => true ), $state );
	}

	/**
	 * Merge a multilingual field without blanking the languages not supplied.
	 *
	 * @since  0.0.34
	 * @param  mixed                $existing Current value, array keyed by language or a raw string.
	 * @param  array<string,string> $changes  Language => value.
	 * @return array<string, string>
	 */
	public static function merge_multilingual( $existing, array $changes ): array {
		$out = array();

		foreach ( self::languages() as $lang ) {
			if ( array_key_exists( $lang, $changes ) ) {
				$out[ $lang ] = (string) $changes[ $lang ];
				continue;
			}

			if ( is_array( $existing ) && isset( $existing[ $lang ] ) ) {
				$out[ $lang ] = (string) $existing[ $lang ];
				continue;
			}

			$out[ $lang ] = is_string( $existing ) ? $existing : '';
		}

		return $out;
	}

	/**
	 * Decode a stored multilingual column.
	 *
	 * @since  0.0.34
	 * @param  mixed $value Raw column value.
	 * @return array<string, string>|string
	 */
	public static function decode( $value ) {
		if ( is_array( $value ) ) {
			return $value;
		}

		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : (string) $value;
	}

	/* ---------------------------------------------------------------- cookies */

	/**
	 * @since  0.0.34
	 * @param  int|null $category Optional category id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_cookies( ?int $category = null ): array {
		$args = array();

		if ( null !== $category && $category > 0 ) {
			$args['category'] = $category;
		}

		$rows = self::cookie_controller()->get_item_from_db( $args );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[] = self::shape_cookie( $row );
		}

		return $out;
	}

	/**
	 * @since  0.0.34
	 * @param  int $id Cookie id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_cookie( int $id ) {
		$rows = self::cookie_controller()->get_item_from_db( array( 'id' => $id ) );
		$row  = is_array( $rows ) ? reset( $rows ) : $rows;

		if ( empty( $row ) || ! isset( $row->cookie_id ) ) {
			return new WP_Error(
				'unknown_cookie',
				sprintf(
					/* translators: %d: cookie id. */
					__( 'No cookie with id %d. Call consent/list-cookies for the ids.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		return self::shape_cookie( $row );
	}

	/**
	 * @since  0.0.34
	 * @param  object $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function shape_cookie( $row ): array {
		return array(
			'id'          => isset( $row->cookie_id ) ? (int) $row->cookie_id : 0,
			'name'        => isset( $row->name ) ? (string) $row->name : '',
			'slug'        => isset( $row->slug ) ? (string) $row->slug : '',
			'description' => isset( $row->description ) ? self::decode( $row->description ) : array(),
			'duration'    => isset( $row->duration ) ? self::decode( $row->duration ) : array(),
			'domain'      => isset( $row->domain ) ? (string) $row->domain : '',
			'category_id' => isset( $row->category ) ? (int) $row->category : 0,
			'type'        => isset( $row->type ) ? (int) $row->type : 0,
			'discovered'  => isset( $row->discovered ) && (int) $row->discovered > 0,
			'url_pattern' => isset( $row->url_pattern ) ? (string) $row->url_pattern : '',
		);
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Cookie fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function create_cookie( array $fields ) {
		$before = self::template_fingerprint();
		$cookie = new \CookieYes\Lite\Admin\Modules\Cookies\Includes\Cookie();

		$cookie->set_multi_item_data( self::cookie_payload( $fields, null ) );
		self::cookie_controller()->create_item( $cookie );

		$id = (int) $cookie->get_id();

		if ( 0 === $id ) {
			$made = self::find_cookie_by_slug( (string) ( $fields['slug'] ?? sanitize_title( (string) ( $fields['name'] ?? '' ) ) ) );
			$id   = $made > 0 ? $made : 0;
		}

		if ( 0 === $id ) {
			return new WP_Error(
				'create_failed',
				__( 'The consent plugin reported no id after creating the cookie, so it cannot be confirmed as saved.', 'acrossai-abilities-manager' )
			);
		}

		$proof = self::prove_template_moved( $before );

		if ( is_wp_error( $proof ) ) {
			return $proof;
		}

		$saved = self::get_cookie( $id );

		return is_wp_error( $saved ) ? $saved : array_merge( array( 'cookie' => $saved ), $proof );
	}

	/**
	 * @since  0.0.34
	 * @param  string $slug Cookie slug.
	 * @return int
	 */
	private static function find_cookie_by_slug( string $slug ): int {
		if ( '' === $slug ) {
			return 0;
		}

		foreach ( self::list_cookies() as $cookie ) {
			if ( $cookie['slug'] === sanitize_title( $slug ) ) {
				return (int) $cookie['id'];
			}
		}

		return 0;
	}

	/**
	 * @since  0.0.34
	 * @param  int                  $id     Cookie id.
	 * @param  array<string, mixed> $fields Changed fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_cookie( int $id, array $fields ) {
		$current = self::get_cookie( $id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$before = self::template_fingerprint();
		$cookie = new \CookieYes\Lite\Admin\Modules\Cookies\Includes\Cookie( $id );

		$cookie->set_multi_item_data( self::cookie_payload( $fields, $current ) );
		self::cookie_controller()->update_item( $cookie );

		$proof = self::prove_template_moved( $before );

		if ( is_wp_error( $proof ) ) {
			return $proof;
		}

		$saved = self::get_cookie( $id );

		return is_wp_error( $saved ) ? $saved : array_merge( array( 'cookie' => $saved ), $proof );
	}

	/**
	 * @since  0.0.34
	 * @param  int $id Cookie id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function delete_cookie( int $id ) {
		$current = self::get_cookie( $id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$before = self::template_fingerprint();
		$cookie = new \CookieYes\Lite\Admin\Modules\Cookies\Includes\Cookie( $id );

		self::cookie_controller()->delete_item( $cookie );

		$gone = self::get_cookie( $id );

		if ( ! is_wp_error( $gone ) ) {
			return new WP_Error(
				'delete_failed',
				sprintf(
					/* translators: %d: cookie id. */
					__( 'Cookie %d still exists after the delete reported success.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		$proof = self::prove_template_moved( $before );

		return is_wp_error( $proof ) ? $proof : array_merge( array( 'deleted' => $current ), $proof );
	}

	/**
	 * Build the setter payload, merging multilingual fields rather than replacing them.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed>      $fields  Supplied fields.
	 * @param  array<string, mixed>|null $current Existing cookie, when updating.
	 * @return array<string, mixed>
	 */
	private static function cookie_payload( array $fields, ?array $current ): array {
		$payload = array();

		if ( isset( $fields['name'] ) ) {
			$payload['name'] = (string) $fields['name'];
		}

		if ( isset( $fields['slug'] ) || null === $current ) {
			$payload['slug'] = (string) ( $fields['slug'] ?? ( $fields['name'] ?? '' ) );
		}

		foreach ( array( 'description', 'duration' ) as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			$changes = is_array( $fields[ $key ] )
				? $fields[ $key ]
				: array_fill_keys( self::languages(), (string) $fields[ $key ] );

			$payload[ $key ] = self::merge_multilingual( $current[ $key ] ?? array(), $changes );
		}

		foreach ( array( 'domain', 'url_pattern' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$payload[ $key ] = (string) $fields[ $key ];
			}
		}

		// An INTEGER, despite reading like a label. `set_type()` is `absint()`, so a string such as
		// "HTTP" silently becomes 0 — measured: it stored 0 and reported success. The plugin
		// publishes no mapping for the codes, in its REST schema or its admin bundle, so this is
		// passed through as the number it is rather than guessing at names for it.
		if ( isset( $fields['type'] ) ) {
			$payload['type'] = (int) $fields['type'];
		}

		if ( isset( $fields['category_id'] ) ) {
			$payload['category'] = (int) $fields['category_id'];
		}

		// Never claimed as discovered: that flag means the vendor's crawler found it, and an entry
		// typed in here did not come from a crawl.
		if ( null === $current ) {
			$payload['discovered'] = false;
		}

		return $payload;
	}

	/* ------------------------------------------------------------- categories */

	/**
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_categories(): array {
		$rows = self::category_controller()->get_item_from_db( array() );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[] = self::shape_category( $row );
		}

		return $out;
	}

	/**
	 * @since  0.0.34
	 * @param  int $id Category id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_category( int $id ) {
		foreach ( self::list_categories() as $category ) {
			if ( (int) $category['id'] === $id ) {
				return $category;
			}
		}

		return new WP_Error(
			'unknown_category',
			sprintf(
				/* translators: %d: category id. */
				__( 'No consent category with id %d. Call consent/list-categories for the ids.', 'acrossai-abilities-manager' ),
				$id
			)
		);
	}

	/**
	 * @since  0.0.34
	 * @param  object $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function shape_category( $row ): array {
		return array(
			'id'                 => isset( $row->category_id ) ? (int) $row->category_id : 0,
			'name'               => isset( $row->name ) ? self::decode( $row->name ) : array(),
			'slug'               => isset( $row->slug ) ? (string) $row->slug : '',
			'description'        => isset( $row->description ) ? self::decode( $row->description ) : array(),
			'prior_consent'      => isset( $row->prior_consent ) && (int) $row->prior_consent > 0,
			'visible'            => isset( $row->visibility ) && (int) $row->visibility > 0,
			'priority'           => isset( $row->priority ) ? (int) $row->priority : 0,
			'sell_personal_data' => isset( $row->sell_personal_data ) && (int) $row->sell_personal_data > 0,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  int                  $id     Category id.
	 * @param  array<string, mixed> $fields Changed fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_category( int $id, array $fields ) {
		$current = self::get_category( $id );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$before   = self::template_fingerprint();
		$category = new \CookieYes\Lite\Admin\Modules\Cookies\Includes\Cookie_Categories( $id );
		$payload  = array();

		foreach ( array( 'name', 'description' ) as $key ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			$changes = is_array( $fields[ $key ] )
				? $fields[ $key ]
				: array_fill_keys( self::languages(), (string) $fields[ $key ] );

			$payload[ $key ] = self::merge_multilingual( $current[ $key ], $changes );
		}

		if ( isset( $fields['visible'] ) ) {
			$payload['visibility'] = (bool) $fields['visible'];
		}

		if ( isset( $fields['priority'] ) ) {
			$payload['priority'] = (int) $fields['priority'];
		}

		if ( isset( $fields['sell_personal_data'] ) ) {
			$payload['sell_personal_data'] = (bool) $fields['sell_personal_data'];
		}

		/*
		 * `prior_consent` is deliberately not writable. It is what marks a category as strictly
		 * necessary — the one that loads before any consent is given — and flipping it turns a
		 * consent decision into a non-decision. That belongs to a person on the settings screen.
		 */

		$category->set_multi_item_data( $payload );
		self::category_controller()->update_item( $category );

		$proof = self::prove_template_moved( $before );

		if ( is_wp_error( $proof ) ) {
			return $proof;
		}

		$saved = self::get_category( $id );

		return is_wp_error( $saved ) ? $saved : array_merge( array( 'category' => $saved ), $proof );
	}

	/* ---------------------------------------------------------------- banners */

	/**
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_banners(): array {
		$rows = self::banner_controller()->get_item_from_db( array() );
		$out  = array();

		foreach ( (array) $rows as $row ) {
			$out[] = self::shape_banner( $row );
		}

		return $out;
	}

	/**
	 * @since  0.0.34
	 * @param  object $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function shape_banner( $row ): array {
		return array(
			'id'         => isset( $row->banner_id ) ? (int) $row->banner_id : 0,
			'name'       => isset( $row->name ) ? (string) $row->name : '',
			'slug'       => isset( $row->slug ) ? (string) $row->slug : '',
			'active'     => isset( $row->status ) && (int) $row->status > 0,
			'is_default' => isset( $row->banner_default ) && (int) $row->banner_default > 0,
		);
	}

	/**
	 * @since  0.0.34
	 * @param  int $id Banner id.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function get_banner( int $id ) {
		foreach ( self::list_banners() as $banner ) {
			if ( (int) $banner['id'] === $id ) {
				return $banner;
			}
		}

		return new WP_Error(
			'unknown_banner',
			sprintf(
				/* translators: %d: banner id. */
				__( 'No banner with id %d. Call consent/list-banners for the ids.', 'acrossai-abilities-manager' ),
				$id
			)
		);
	}

	/* --------------------------------------------------------------- settings */

	/**
	 * @since  0.0.34
	 * @return \CookieYes\Lite\Admin\Modules\Settings\Includes\Settings
	 */
	private static function settings_object() {
		return new \CookieYes\Lite\Admin\Modules\Settings\Includes\Settings();
	}

	/**
	 * Everything about the consent configuration that is safe to return.
	 *
	 * Credentials are stripped by {@see Consent_Guard::redact()} and the removal is reported, so a
	 * caller can tell "this is empty" apart from "you may not see this".
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function settings_snapshot(): array {
		$settings = self::settings_object();
		$redacted = Consent_Guard::redact( Consent_Guard::settings() );

		return array(
			'connected'          => Consent_Guard::is_connected(),
			'banner_enabled'     => (bool) self::banner_controller()->check_status(),
			'consent_log_status' => (bool) $settings->get_consent_log_status(),
			'default_language'   => (string) $settings->get_default_language(),
			'selected_languages' => array_values( (array) $settings->get_selected_languages() ),
			'plan'               => self::shape_plan( $settings->get_plan() ),
			'redacted'           => $redacted['redacted'],
			'onboarding_step'    => isset( $redacted['settings']['onboarding']['step'] ) ? (int) $redacted['settings']['onboarding']['step'] : 0,
		);
	}

	/**
	 * Normalise the stored plan.
	 *
	 * `account.plan` holds a bare STRING on a free account — measured, it is "free" — and an array on
	 * others. Coercing a non-array to `array()` threw the value away: the field was advertised in the
	 * output schema and came back empty on exactly the accounts it was most likely to be asked about.
	 * The richer plan object, with the scan and log limits, comes from the service and is returned by
	 * consent/get-scan-status.
	 *
	 * @since  0.0.34
	 * @param  mixed $plan Stored plan value.
	 * @return array<string, mixed>
	 */
	private static function shape_plan( $plan ): array {
		if ( is_array( $plan ) ) {
			return $plan;
		}

		$slug = (string) $plan;

		return '' === $slug ? array() : array( 'slug' => $slug );
	}

	/**
	 * Write a narrow, named set of settings.
	 *
	 * An allow-list rather than a passthrough: the option holds the API token and the website key
	 * beside ordinary settings, so a generic writer would let a caller set credentials, and a
	 * generic MERGE would let one silently blank them.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_settings( array $fields ) {
		$payload = array();

		if ( isset( $fields['consent_log_status'] ) ) {
			$payload['consent_logs'] = array( 'status' => (bool) $fields['consent_log_status'] );
		}

		if ( array() === $payload ) {
			return new WP_Error(
				'invalid_input',
				__( 'Nothing to change. Supply consent_log_status.', 'acrossai-abilities-manager' )
			);
		}

		self::settings_object()->update( $payload );

		return array( 'settings' => self::settings_snapshot() );
	}

	/* ------------------------------------------------------------- languages */

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function languages_state(): array {
		$settings = self::settings_object();

		return array(
			'selected' => array_values( (array) $settings->get_selected_languages() ),
			'default'  => (string) $settings->get_default_language(),
		);
	}

	/**
	 * @since  0.0.34
	 * @param  string[]    $selected Language codes to offer.
	 * @param  string|null $default  Language to fall back to.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function set_languages( array $selected, ?string $default = null ) {
		$selected = array_values( array_unique( array_filter( array_map( 'sanitize_key', $selected ) ) ) );

		if ( array() === $selected ) {
			return new WP_Error(
				'invalid_input',
				__( 'At least one language must stay selected, or the banner has no text to show.', 'acrossai-abilities-manager' )
			);
		}

		$fallback = null !== $default ? sanitize_key( $default ) : (string) self::settings_object()->get_default_language();

		if ( ! in_array( $fallback, $selected, true ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: 1: default language, 2: selected list. */
					__( 'The default language "%1$s" is not in the selected list (%2$s). The banner falls back to the default, so a default outside the list leaves it with nothing to show.', 'acrossai-abilities-manager' ),
					$fallback,
					implode( ', ', $selected )
				)
			);
		}

		$before = self::template_fingerprint();

		self::settings_object()->update(
			array(
				'languages' => array(
					'selected' => $selected,
					'default'  => $fallback,
				),
			)
		);

		return array_merge( array( 'languages' => self::languages_state() ), self::template_state() );
	}

	/* ----------------------------------------------------- google consent mode */

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function gcm_state(): array {
		$gcm = get_option( 'cky_gcm_settings', array() );

		return is_array( $gcm ) ? $gcm : array();
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $fields Supplied fields.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_gcm( array $fields ) {
		$current = self::gcm_state();
		$allowed = array( 'status', 'wait_for_update', 'url_passthrough', 'ads_data_redaction' );
		$changed = false;

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}

			$current[ $key ] = 'wait_for_update' === $key ? (int) $fields[ $key ] : (bool) $fields[ $key ];
			$changed         = true;
		}

		if ( ! $changed ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: accepted keys. */
					__( 'Nothing to change. Accepted keys are: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', $allowed )
				)
			);
		}

		update_option( 'cky_gcm_settings', $current );

		$saved = self::gcm_state();

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $fields ) && ( $saved[ $key ] ?? null ) !== $current[ $key ] ) {
				return new WP_Error(
					'update_failed',
					__( 'Google Consent Mode reported a successful save but the value read back differently.', 'acrossai-abilities-manager' )
				);
			}
		}

		return array( 'google_consent_mode' => $saved );
	}

	/**
	 * Rebuild the rendered banner.
	 *
	 * The repair for a site whose tables were written directly. Fires the plugin's own cache-clear
	 * action rather than touching the option, so anything else listening also runs.
	 *
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	public static function rebuild_template(): array {
		$before = self::template_fingerprint();

		// Clear through the action so anything else listening also runs, then RE-RENDER. Clearing
		// alone would leave the banner to rebuild on the next front-end request, which means an
		// ability called "rebuild" would return with nothing rebuilt and the site still serving
		// nothing until someone visited it. Measured: clearing alone left `rendered` false.
		do_action( 'cky_clear_cache' );

		/*
		 * Re-rendering needs the front-end context. `generate()` reaches through the consent plugin's
		 * shortcode module, which on a REST request has no banner object to read — measured, it
		 * fatals with "Call to a member function get_contents() on null" and takes the whole request
		 * with it. Caught rather than avoided, because it DOES work on a front-end request and
		 * rendering here is worth having when it can be had; when it cannot, the banner is still
		 * correctly cleared and rebuilds on the next page a visitor loads, which the caller is told.
		 */
		$template = '\CookieYes\Lite\Admin\Modules\Banners\Includes\Template';

		if ( class_exists( $template ) && method_exists( $template, 'generate' ) ) {
			try {
				$template::get_instance()->generate();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		$state = self::template_state();

		if ( true !== $state['rendered'] ) {
			return array_merge(
				array(
					'changed' => $before !== self::template_fingerprint(),
					'note'    => __( 'The banner was cleared but could not be re-rendered here; it will be rebuilt on the next page a visitor loads.', 'acrossai-abilities-manager' ),
				),
				$state
			);
		}

		return array_merge(
			array( 'changed' => $before !== self::template_fingerprint() ),
			$state
		);
	}
}
