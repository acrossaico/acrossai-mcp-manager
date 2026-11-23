<?php
/**
 * Secret redactor for file-manager read responses (Feature 092).
 *
 * Scrubs sensitive content from `read-file` and `read-debug-log` responses
 * before returning them to the caller.
 *
 * Ships ONE built-in pattern by default (WordPress credentials — DB
 * password, DB user, all 8 auth keys/salts, SECRET_KEY). Everything else
 * is admin-supplied via the custom-literals list on the File Manager
 * settings tab. This deliberately does NOT ship third-party API-key
 * regexes; assumptions about someone else's key format belong in the
 * user's own config, not in this plugin.
 *
 * Automatic integration with the WordPress "AI" plugin (v1.x): any API
 * key stored in the well-known `connectors_ai_<provider>_api_key`
 * options (OpenAI, Anthropic, Google) is added to the literal-scrub list
 * transparently — the admin does not have to copy those keys into the
 * custom-literals textarea. If the AI plugin is not installed those
 * options don't exist and the integration is a no-op.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Secret_Redactor utility.
 */
final class Secret_Redactor {

	/**
	 * Option name for the redaction config.
	 */
	public const OPTION = 'acrossai_file_manager_redaction_config';

	/**
	 * The literal replacement token used everywhere.
	 */
	public const TOKEN = '***REDACTED***';

	/**
	 * WordPress AI plugin's connector API-key option-name map (feature 092
	 * refinement — auto-scrub keys stored by that plugin). Non-existent
	 * options resolve to empty strings and are skipped at scrub time.
	 *
	 * Source: `wp-content/plugins/ai/includes/Admin/Upgrades/V0_5_0.php`.
	 *
	 * @var array<string,string>
	 */
	private const AI_CONNECTOR_OPTIONS = array(
		'openai'    => 'connectors_ai_openai_api_key',
		'anthropic' => 'connectors_ai_anthropic_api_key',
		'google'    => 'connectors_ai_google_api_key',
	);

	/**
	 * WordPress core secret constant names covered by the wp_credentials
	 * pattern.
	 *
	 * @var array<int,string>
	 */
	private const WP_SECRET_CONSTANTS = array(
		'DB_PASSWORD',
		'DB_USER',
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
		'SECRET_KEY',
	);

	/**
	 * Default redaction config applied on activation.
	 *
	 * Only wp_credentials ships default-on. Third-party API-key patterns
	 * are the user's call — add them as custom literals via the settings
	 * UI when needed.
	 *
	 * @return array{patterns: array<string,bool>, custom_literals: array<int,string>}
	 */
	public static function default_config(): array {
		return array(
			'patterns'        => array(
				'wp_credentials' => true,
			),
			'custom_literals' => array(),
		);
	}

	/* -------------------------------------------------------------------- */
	/* Config accessors                                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Read current config, merged over the defaults so any missing keys are
	 * always populated with the shipping default.
	 *
	 * @return array{patterns: array<string,bool>, custom_literals: array<int,string>}
	 */
	public static function get_config(): array {
		$raw     = get_option( self::OPTION, array() );
		$default = self::default_config();

		if ( ! is_array( $raw ) ) {
			return $default;
		}

		$patterns = isset( $raw['patterns'] ) && is_array( $raw['patterns'] ) ? $raw['patterns'] : array();
		$literals = isset( $raw['custom_literals'] ) && is_array( $raw['custom_literals'] ) ? $raw['custom_literals'] : array();

		// Merge over the default map so a newly-added pattern gets its
		// shipping default until the admin saves.
		$merged_patterns = $default['patterns'];
		foreach ( $patterns as $id => $enabled ) {
			if ( isset( $default['patterns'][ $id ] ) ) {
				$merged_patterns[ $id ] = (bool) $enabled;
			}
		}

		return array(
			'patterns'        => $merged_patterns,
			'custom_literals' => self::sanitize_literals( $literals ),
		);
	}

	/**
	 * Persist a config. Ignores unknown pattern IDs so an attacker can't
	 * seed junk keys through the REST endpoint.
	 *
	 * @param array{patterns?: array<string,bool>, custom_literals?: array<int,string>} $config New config.
	 * @return bool
	 */
	public static function set_config( array $config ): bool {
		$default = self::default_config();
		$out     = array(
			'patterns'        => $default['patterns'],
			'custom_literals' => array(),
		);
		if ( isset( $config['patterns'] ) && is_array( $config['patterns'] ) ) {
			foreach ( $config['patterns'] as $id => $enabled ) {
				if ( isset( $default['patterns'][ $id ] ) ) {
					$out['patterns'][ $id ] = (bool) $enabled;
				}
			}
		}
		if ( isset( $config['custom_literals'] ) && is_array( $config['custom_literals'] ) ) {
			$out['custom_literals'] = self::sanitize_literals( $config['custom_literals'] );
		}
		return (bool) update_option( self::OPTION, $out );
	}

	/**
	 * Enumerate every built-in pattern class for the admin UI.
	 *
	 * @return array<string,array{label: string, description: string, default_enabled: bool}>
	 */
	public static function available_patterns(): array {
		return array(
			'wp_credentials' => array(
				'label'           => __( 'WordPress credentials (wp-config.php)', 'acrossai-abilities-manager' ),
				'description'     => __( "Replaces the value of define('DB_PASSWORD', …), define('DB_USER', …) and all eight WordPress auth keys/salts.", 'acrossai-abilities-manager' ),
				'default_enabled' => true,
			),
		);
	}

	/**
	 * Enumerate the AI-connector auto-scrub sources for the admin UI.
	 *
	 * Each entry reports whether the option currently has a non-empty
	 * value on this install — the UI can render a checklist so the admin
	 * knows the redactor will pick up those keys automatically.
	 *
	 * @return array<string,array{label: string, option: string, present: bool}>
	 */
	public static function available_connector_sources(): array {
		$labels = array(
			'openai'    => __( 'OpenAI', 'acrossai-abilities-manager' ),
			'anthropic' => __( 'Anthropic (Claude)', 'acrossai-abilities-manager' ),
			'google'    => __( 'Google (Gemini / Imagen)', 'acrossai-abilities-manager' ),
		);
		$out = array();
		foreach ( self::AI_CONNECTOR_OPTIONS as $id => $option_name ) {
			$value          = (string) get_option( $option_name, '' );
			$out[ $id ]     = array(
				'label'   => $labels[ $id ] ?? $id,
				'option'  => $option_name,
				'present' => '' !== trim( $value ),
			);
		}
		return $out;
	}

	/* -------------------------------------------------------------------- */
	/* Scrubbing                                                             */
	/* -------------------------------------------------------------------- */

	/**
	 * Scrub sensitive content from arbitrary text.
	 *
	 * Applies (in order):
	 *   1. The wp_credentials regex (if enabled) — replaces values of
	 *      well-known WordPress secret constants inside define() calls.
	 *   2. Any non-empty API-key value stored in the WordPress AI plugin's
	 *      connectors_ai_<provider>_api_key options (transparent
	 *      integration; no admin action required).
	 *   3. Every admin-supplied custom literal — case-sensitive string
	 *      match.
	 *
	 * @param string $content Original file content (text; do not call on binary bytes).
	 * @return array{text: string, redacted: bool, redaction_count: int}
	 */
	public static function scrub( string $content ): array {
		if ( '' === $content ) {
			return array(
				'text'            => '',
				'redacted'        => false,
				'redaction_count' => 0,
			);
		}

		$config = self::get_config();
		$count  = 0;
		$text   = $content;

		if ( ! empty( $config['patterns']['wp_credentials'] ) ) {
			$text = self::apply_wp_credentials( $text, $count );
		}

		// Auto-scrub AI-connector API keys. Read the current values every
		// scrub call so newly-added connector keys take effect without a
		// plugin reload.
		foreach ( self::collect_connector_key_values() as $literal ) {
			$text = self::apply_literal( $text, $literal, $count );
		}

		foreach ( $config['custom_literals'] as $literal ) {
			$text = self::apply_literal( $text, $literal, $count );
		}

		return array(
			'text'            => $text,
			'redacted'        => $count > 0,
			'redaction_count' => $count,
		);
	}

	/* -------------------------------------------------------------------- */
	/* Internal helpers                                                      */
	/* -------------------------------------------------------------------- */

	/**
	 * Read the current API-key value from each configured connector option.
	 *
	 * @return array<int,string> Non-empty API-key strings.
	 */
	private static function collect_connector_key_values(): array {
		$out = array();
		foreach ( self::AI_CONNECTOR_OPTIONS as $option_name ) {
			$raw = get_option( $option_name, '' );
			if ( ! is_string( $raw ) ) {
				continue;
			}
			$trimmed = trim( $raw );
			// Short strings would produce absurd numbers of false-positive
			// matches; require at least 8 chars to treat a value as a real
			// key. WordPress AI plugin stores plaintext keys that are much
			// longer than this.
			if ( strlen( $trimmed ) < 8 ) {
				continue;
			}
			$out[] = $trimmed;
		}
		return $out;
	}

	/**
	 * Apply the WordPress-credentials pattern. Replaces the value in
	 * define('NAME', 'value') for every SECRET_CONSTANT.
	 *
	 * @param string $content Content to scrub.
	 * @param int    $count   Running total (by reference).
	 * @return string Scrubbed content.
	 */
	private static function apply_wp_credentials( string $content, int &$count ): string {
		$names = implode( '|', array_map( 'preg_quote', self::WP_SECRET_CONSTANTS ) );
		$regex = '/(define\s*\(\s*[\'"](?:' . $names . ')[\'"]\s*,\s*)([\'"])([^\'"\r\n]*)\2(\s*[,\)])/';
		return self::apply_regex(
			$content,
			$regex,
			'$1$2' . self::TOKEN . '$2$4',
			$count
		);
	}

	/**
	 * Apply a literal-string replacement. Increments $count by the number
	 * of occurrences replaced.
	 *
	 * @param string $content Content to scrub.
	 * @param string $literal Literal string to redact.
	 * @param int    $count   Running total (by reference).
	 * @return string
	 */
	private static function apply_literal( string $content, string $literal, int &$count ): string {
		if ( '' === $literal ) {
			return $content;
		}
		$replaced = str_replace( $literal, self::TOKEN, $content, $hits );
		if ( $hits > 0 ) {
			$count = $count + (int) $hits;
		}
		return $replaced;
	}

	/**
	 * Apply a regex replacement, incrementing $count by the number of
	 * substitutions made.
	 *
	 * @param string $content     Content to scrub.
	 * @param string $regex       PCRE pattern.
	 * @param string $replacement Replacement string.
	 * @param int    $count       Running total (by reference).
	 * @return string
	 */
	private static function apply_regex( string $content, string $regex, string $replacement, int &$count ): string {
		$replaced = preg_replace( $regex, $replacement, $content, -1, $hits );
		if ( null === $replaced ) {
			// PCRE error — return the original content untouched.
			return $content;
		}
		if ( $hits > 0 ) {
			$count = $count + (int) $hits;
		}
		return $replaced;
	}

	/**
	 * Sanitize the custom-literals list: string-only entries, trimmed, non-empty, deduped.
	 *
	 * @param array<int|string,mixed> $raw Raw input.
	 * @return array<int,string>
	 */
	private static function sanitize_literals( array $raw ): array {
		$out = array();
		foreach ( $raw as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}
			$trimmed = trim( $entry );
			if ( '' === $trimmed ) {
				continue;
			}
			$out[] = $trimmed;
		}
		return array_values( array_unique( $out ) );
	}
}
