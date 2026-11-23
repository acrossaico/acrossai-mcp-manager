<?php
/**
 * Feature 132 — the typed field specification for every writable AIOSEO settings panel.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Aioseo
 * @since      0.0.44
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Aioseo;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Panels, their fields, and whether a caller's write is allowed to touch them.
 *
 * **Derived, not hand-authored, and that is the whole point.** AIOSEO already declares a typed
 * specification for every setting it owns — `Options::$defaults` gives each leaf a `type` of boolean,
 * string, number, array or html, usually a `default`, and sometimes `localized` or `preserveHtml` —
 * and exposes it through the public `getDefaults()`. This class reads that tree. The Rank Math
 * equivalent had to hand-maintain field specs for 20 panels against a plugin that publishes none, and
 * every Rank Math release could silently invalidate one. Here the specification cannot drift from the
 * plugin, because it *is* the plugin's.
 *
 * What this adds on top is the part AIOSEO has no equivalent of: panels. `aioseo-settings/get`
 * returns the whole tree and documents it as opaque; nothing says which keys are writable, what a key
 * accepts, or which AIOSEO capability governs it. A panel is a named, capability-scoped slice of that
 * tree, which is what makes a validated write possible at all.
 *
 * **Two stores.** `aioseo()->options` holds the site-wide settings; `aioseo()->dynamicOptions` holds
 * the per-object ones (search appearance per post type, per taxonomy, per archive). They are separate
 * option rows with separate defaults and separate save paths, so they are modelled separately —
 * `panels()` covers the first, {@see Object_Settings_Registry} the second. A single reader that
 * guessed between them would be wrong half the time.
 *
 * @since 0.0.44
 */
final class Settings_Registry {

	/**
	 * Top-level groups that are never a panel.
	 *
	 * `internal` is AIOSEO's own bookkeeping — migration flags, cached counts, license state. It is in
	 * `getDefaults()` as an empty array and is written by AIOSEO alone; exposing it would invite a
	 * caller to "fix" state that AIOSEO maintains. `deprecated` is the compatibility shadow of three
	 * groups that moved, and writing it changes nothing a visitor sees.
	 *
	 * @since 0.0.44
	 * @var   string[]
	 */
	private const EXCLUDED_GROUPS = array( 'internal', 'deprecated' );

	/**
	 * Fields this suite refuses to write, as `panel` => field ids.
	 *
	 * The registry's premise is that AIOSEO's declared types ARE the specification. This is the one
	 * field in AIOSEO 5.0.3 where that is not true. `advanced.emailSummary.recipients` is declared
	 * `type: array`, but `Options::sanitizeEmailSummary()` reads `$recipient['email']` and
	 * `$recipient['frequency']` off every entry unconditionally (`Options.php:817,831`) — so each
	 * member is a structured record with required sub-keys that appear nowhere in the type, and an
	 * entry missing one raises an undefined-key warning inside AIOSEO on save. An adversarial pass
	 * wrote `[ { email: … } ]` through here and tripped exactly that.
	 *
	 * Validating a shape the plugin does not declare would mean hand-maintaining the one thing this
	 * class exists to avoid, so the field is refused and the caller is sent to the screen that owns
	 * it. It stays readable.
	 *
	 * @since 0.0.44
	 * @var   array<string, string[]>
	 */
	private const REFUSED_FIELDS = array(
		'advanced' => array( 'emailSummary.recipients' ),
	);

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Every panel: slug => path into the options tree, AIOSEO capability, label.
	 *
	 * The slug is the caller-facing enum. It is kebab-case and stable; the path is AIOSEO's
	 * camelCase internals and is not. Nothing outside this class should know the path.
	 *
	 * Capabilities are AIOSEO's own, as listed by `aioseo()->access->getCapabilityList()`, matched to
	 * the admin screen each panel appears on. `tools-robots` is the one deliberate narrowing: its
	 * fields back AIOSEO's robots.txt editor, and AIOSEO ships `aioseo-robots/rules-add`,
	 * `rules-update` and `rules-delete` for exactly that, so this panel is readable here and refused
	 * on write — see {@see self::is_writable()}.
	 *
	 * @since  0.0.44
	 * @return array<string, array{path: string[], capability: string, label: string, writable?: bool}>
	 */
	public static function panels(): array {
		return array(
			'webmaster-tools'            => array(
				'path'       => array( 'webmasterTools' ),
				'capability' => 'aioseo_general_settings',
				'label'      => __( 'Webmaster verification codes', 'acrossai-abilities-manager' ),
			),
			'breadcrumbs'                => array(
				'path'       => array( 'breadcrumbs' ),
				'capability' => 'aioseo_general_settings',
				'label'      => __( 'Breadcrumbs', 'acrossai-abilities-manager' ),
			),
			'rss-content'                => array(
				'path'       => array( 'rssContent' ),
				'capability' => 'aioseo_general_settings',
				'label'      => __( 'RSS content before and after', 'acrossai-abilities-manager' ),
			),
			'advanced'                   => array(
				'path'       => array( 'advanced' ),
				'capability' => 'aioseo_general_settings',
				'label'      => __( 'Advanced and analysis toggles', 'acrossai-abilities-manager' ),
			),
			'ai-content'                 => array(
				'path'       => array( 'aiContent' ),
				'capability' => 'aioseo_general_settings',
				'label'      => __( 'AI content defaults', 'acrossai-abilities-manager' ),
			),
			'search-appearance-global'   => array(
				'path'       => array( 'searchAppearance', 'global' ),
				'capability' => 'aioseo_search_appearance_settings',
				'label'      => __( 'Search appearance — global', 'acrossai-abilities-manager' ),
			),
			'search-appearance-advanced' => array(
				'path'       => array( 'searchAppearance', 'advanced' ),
				'capability' => 'aioseo_search_appearance_settings',
				'label'      => __( 'Search appearance — advanced', 'acrossai-abilities-manager' ),
			),
			'search-appearance-archives' => array(
				'path'       => array( 'searchAppearance', 'archives' ),
				'capability' => 'aioseo_search_appearance_settings',
				'label'      => __( 'Search appearance — author and date archives', 'acrossai-abilities-manager' ),
			),
			'sitemap-general'            => array(
				'path'       => array( 'sitemap', 'general' ),
				'capability' => 'aioseo_sitemap_settings',
				'label'      => __( 'XML sitemap', 'acrossai-abilities-manager' ),
			),
			'sitemap-rss'                => array(
				'path'       => array( 'sitemap', 'rss' ),
				'capability' => 'aioseo_sitemap_settings',
				'label'      => __( 'RSS sitemap', 'acrossai-abilities-manager' ),
			),
			'sitemap-html'               => array(
				'path'       => array( 'sitemap', 'html' ),
				'capability' => 'aioseo_sitemap_settings',
				'label'      => __( 'HTML sitemap', 'acrossai-abilities-manager' ),
			),
			'sitemap-llms'               => array(
				'path'       => array( 'sitemap', 'llms' ),
				'capability' => 'aioseo_sitemap_settings',
				'label'      => __( 'llms.txt', 'acrossai-abilities-manager' ),
			),
			'social-profiles'            => array(
				'path'       => array( 'social', 'profiles' ),
				'capability' => 'aioseo_social_networks_settings',
				'label'      => __( 'Social profiles', 'acrossai-abilities-manager' ),
			),
			'social-facebook'            => array(
				'path'       => array( 'social', 'facebook' ),
				'capability' => 'aioseo_social_networks_settings',
				'label'      => __( 'Facebook / Open Graph', 'acrossai-abilities-manager' ),
			),
			'social-twitter'             => array(
				'path'       => array( 'social', 'twitter' ),
				'capability' => 'aioseo_social_networks_settings',
				'label'      => __( 'X / Twitter cards', 'acrossai-abilities-manager' ),
			),
			'search-statistics'          => array(
				'path'       => array( 'searchStatistics' ),
				'capability' => 'aioseo_search_statistics_settings',
				'label'      => __( 'Search statistics', 'acrossai-abilities-manager' ),
			),
			'writing-assistant'          => array(
				'path'       => array( 'writingAssistant' ),
				'capability' => 'aioseo_general_settings',
				'label'      => __( 'Writing assistant', 'acrossai-abilities-manager' ),
			),
			'tools-import-export'        => array(
				'path'       => array( 'tools', 'importExport' ),
				'capability' => 'aioseo_tools_settings',
				'label'      => __( 'Import and export', 'acrossai-abilities-manager' ),
			),
			'tools-seo-alerts'           => array(
				'path'       => array( 'tools', 'seoAlerts' ),
				'capability' => 'aioseo_tools_settings',
				'label'      => __( 'SEO alerts', 'acrossai-abilities-manager' ),
			),
			'tools-robots'               => array(
				'path'       => array( 'tools', 'robots' ),
				'capability' => 'aioseo_tools_settings',
				'label'      => __( 'robots.txt (read-only here)', 'acrossai-abilities-manager' ),
				'writable'   => false,
			),
		);
	}

	/**
	 * Panel slugs, for an input enum.
	 *
	 * @since  0.0.44
	 * @return string[]
	 */
	public static function panel_slugs(): array {
		return array_keys( self::panels() );
	}

	/**
	 * Writable panel slugs, for a writer's input enum.
	 *
	 * @since  0.0.44
	 * @return string[]
	 */
	public static function writable_panel_slugs(): array {
		return array_values(
			array_filter( self::panel_slugs(), array( self::class, 'is_writable' ) )
		);
	}

	/**
	 * Whether a panel accepts writes through this suite.
	 *
	 * @since  0.0.44
	 * @param  string $panel Panel slug.
	 * @return bool
	 */
	public static function is_writable( string $panel ): bool {
		$panels = self::panels();

		return isset( $panels[ $panel ] ) && false !== ( $panels[ $panel ]['writable'] ?? true );
	}

	/**
	 * Resolve a panel slug, or say why it cannot be.
	 *
	 * @since  0.0.44
	 * @param  string $panel Panel slug.
	 * @return array{path: string[], capability: string, label: string, writable?: bool}|WP_Error
	 */
	public static function resolve( string $panel ) {
		$panels = self::panels();

		if ( ! isset( $panels[ $panel ] ) ) {
			return new WP_Error(
				'unknown_panel',
				sprintf(
					/* translators: 1: requested panel slug, 2: comma-separated list of valid slugs */
					__( 'There is no AIOSEO settings panel called "%1$s". The panels are: %2$s.', 'acrossai-abilities-manager' ),
					$panel,
					implode( ', ', self::panel_slugs() )
				)
			);
		}

		return $panels[ $panel ];
	}

	/**
	 * The field specification for one panel, flattened to dotted ids.
	 *
	 * A panel can nest — `sitemap-general` holds `postTypes.all` and `postTypes.included` — and a
	 * caller writing a dotted id is unambiguous in a way a nested object is not. Each entry carries
	 * AIOSEO's own `type`, its default, the current stored value, and the modifiers that change how a
	 * value is treated on save (`localized`, `preserve_html`).
	 *
	 * @since  0.0.44
	 * @param  string $panel Panel slug.
	 * @return array<string, array<string, mixed>>|WP_Error Field id => spec.
	 */
	public static function fields( string $panel ) {
		$resolved = self::resolve( $panel );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$defaults = self::node( self::all_defaults(), $resolved['path'] );
		if ( ! is_array( $defaults ) ) {
			return new WP_Error(
				'panel_unavailable',
				sprintf(
					/* translators: %s: panel slug */
					__( 'The AIOSEO settings panel "%s" is not present in this AIOSEO version. It may have been renamed or moved to an addon.', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}

		$values = self::current_group( $resolved['path'] );

		return self::flatten( $defaults, is_array( $values ) ? $values : array() );
	}

	/**
	 * Validate a caller's field map against a panel, returning it keyed by dotted id.
	 *
	 * Rejects the WHOLE write on the first unknown id rather than applying what it recognises. A
	 * partially-applied settings write is the worst outcome available: the caller is told it
	 * succeeded, some fields changed, and nothing says which.
	 *
	 * Type coercion is deliberately NOT done here. AIOSEO's `sanitizeField()` casts on save and is
	 * the authority; duplicating it would mean two sanitisers that can disagree. What this does is
	 * refuse a value whose shape cannot survive that cast meaningfully — an array for a boolean, a
	 * scalar for an array — because those are the cases where the cast silently produces nonsense
	 * rather than failing.
	 *
	 * @since  0.0.44
	 * @param  string               $panel  Panel slug.
	 * @param  array<string, mixed> $fields Caller's field id => value map.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function validate( string $panel, array $fields ) {
		if ( ! self::is_writable( $panel ) ) {
			return new WP_Error(
				'panel_not_writable',
				sprintf(
					/* translators: %s: panel slug */
					__( 'The AIOSEO settings panel "%s" is not writable through this suite. Its robots.txt rules are managed by AIOSEO\'s own abilities: aioseo-robots/rules-add, aioseo-robots/rules-update and aioseo-robots/rules-delete, which keep the rule ids this panel\'s raw arrays do not expose.', 'acrossai-abilities-manager' ),
					$panel
				)
			);
		}

		// Before the spec read, because an unconditionally-refused field is refused whether or not
		// AIOSEO is readable, and "you can never write this" is the more useful answer than "the
		// panel could not be loaded".
		foreach ( array_keys( $fields ) as $id ) {
			if ( in_array( (string) $id, self::REFUSED_FIELDS[ $panel ] ?? array(), true ) ) {
				return new WP_Error(
					'field_not_writable',
					sprintf(
						/* translators: %s: field id */
						__( 'The field "%s" cannot be written through this suite, so nothing was written. Each of its entries is a structured record whose required keys AIOSEO does not declare in the field\'s type, and an incomplete one makes AIOSEO fail on save. Set it on the All in One SEO settings screen that owns it. It remains readable here.', 'acrossai-abilities-manager' ),
						$id
					)
				);
			}
		}

		$spec = self::fields( $panel );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		if ( array() === $fields ) {
			return new WP_Error(
				'no_fields',
				__( 'No fields were given, so there is nothing to write. Read the panel first to see its field ids.', 'acrossai-abilities-manager' )
			);
		}

		foreach ( $fields as $id => $value ) {
			$id = (string) $id;

			if ( ! isset( $spec[ $id ] ) ) {
				return new WP_Error(
					'unknown_field',
					sprintf(
						/* translators: 1: field id, 2: panel slug, 3: comma-separated field ids */
						__( 'The field "%1$s" does not belong to the AIOSEO panel "%2$s", so nothing was written. That panel\'s fields are: %3$s.', 'acrossai-abilities-manager' ),
						$id,
						$panel,
						implode( ', ', array_keys( $spec ) )
					)
				);
			}

			$shape = self::assert_shape( $id, $value, $spec[ $id ] );
			if ( is_wp_error( $shape ) ) {
				return $shape;
			}
		}

		return $fields;
	}

	/**
	 * Whether a value's shape can survive AIOSEO's cast for its declared type.
	 *
	 * Type coercion is deliberately NOT done here. AIOSEO's `sanitizeField()` casts on save and is
	 * the authority; duplicating it would mean two sanitisers that can disagree. What this does is
	 * refuse a value whose shape cannot survive that cast MEANINGFULLY — the cases where the cast
	 * silently produces nonsense and reports success.
	 *
	 * Three of the four rules below were added after an adversarial pass got nonsense past the first
	 * version:
	 *
	 * - `linksPerIndex: null` was accepted and stored as 0 — a sitemap with zero links per index —
	 *   because `intval( null )` is 0 and null was waved through. Null is refused for a number, and
	 *   still allowed for a string or boolean, where AIOSEO's cast means "clear it" and "off".
	 * - `postTypes.included: [["post"]]` was accepted and stored nested, because `sanitizeField()`
	 *   recurses into arrays. A nested member is refused for an array field whose own default is a
	 *   flat list. It is ALLOWED where the default is nested: `advanced.emailSummary.recipients` is
	 *   genuinely a list of objects, and it is the only one in AIOSEO 5.0.3, which is why this asks
	 *   the declared default rather than banning nesting outright.
	 *
	 * @since  0.0.44
	 * @param  string                    $id    Field id, for the message.
	 * @param  mixed                     $value Caller's value.
	 * @param  array<string, mixed>      $spec  The field's specification entry.
	 * @return true|WP_Error
	 */
	private static function assert_shape( string $id, $value, array $spec ) {
		$type     = (string) $spec['type'];
		$is_array = is_array( $value );

		if ( 'array' === $type && ! $is_array ) {
			return new WP_Error(
				'invalid_field_value',
				sprintf(
					/* translators: %s: field id */
					__( 'The field "%s" takes a list, and a single value was given. Pass a list even when it has one entry.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		if ( 'array' !== $type && $is_array ) {
			return new WP_Error(
				'invalid_field_value',
				sprintf(
					/* translators: 1: field id, 2: declared type */
					__( 'The field "%1$s" takes a single %2$s value, and a list was given.', 'acrossai-abilities-manager' ),
					$id,
					$type
				)
			);
		}

		if ( 'array' === $type && empty( $spec['nested'] ) ) {
			foreach ( $value as $member ) {
				if ( is_array( $member ) || is_object( $member ) ) {
					return new WP_Error(
						'invalid_field_value',
						sprintf(
							/* translators: %s: field id */
							__( 'The field "%s" takes a flat list of values, and one of the entries is itself a list. AIOSEO would store the nesting as given and then fail to read it back.', 'acrossai-abilities-manager' ),
							$id
						)
					);
				}
			}
		}

		if ( 'number' === $type && null === $value ) {
			return new WP_Error(
				'invalid_field_value',
				sprintf(
					/* translators: %s: field id */
					__( 'The field "%s" takes a number, and null was given. AIOSEO would store it as 0, which is a value rather than a cleared field — pass the number you want.', 'acrossai-abilities-manager' ),
					$id
				)
			);
		}

		if ( 'number' === $type && ! is_numeric( $value ) ) {
			return new WP_Error(
				'invalid_field_value',
				sprintf(
					/* translators: 1: field id, 2: the value given */
					__( 'The field "%1$s" takes a number, and "%2$s" is not one. AIOSEO would store it as 0.', 'acrossai-abilities-manager' ),
					$id,
					is_scalar( $value ) ? (string) $value : gettype( $value )
				)
			);
		}

		return true;
	}

	/**
	 * Whether a declared default is a list containing further lists.
	 *
	 * @since  0.0.44
	 * @param  mixed $default Declared default.
	 * @return bool
	 */
	private static function default_is_nested( $default ): bool {
		if ( ! is_array( $default ) ) {
			return false;
		}

		foreach ( $default as $member ) {
			if ( is_array( $member ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Expand a dotted field map into the nested shape AIOSEO's save path expects.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $fields Field id => value, ids possibly dotted.
	 * @return array<string, mixed>
	 */
	public static function expand( array $fields ): array {
		$nested = array();

		foreach ( $fields as $id => $value ) {
			$segments = explode( '.', (string) $id );
			$cursor   = &$nested;

			foreach ( $segments as $depth => $segment ) {
				if ( count( $segments ) - 1 === $depth ) {
					$cursor[ $segment ] = $value;
					break;
				}

				if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
					$cursor[ $segment ] = array();
				}

				$cursor = &$cursor[ $segment ];
			}

			unset( $cursor );
		}

		return $nested;
	}

	/**
	 * The current stored values for one panel, flattened to the same dotted ids as the spec.
	 *
	 * @since  0.0.44
	 * @param  string $panel Panel slug.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function values( string $panel ) {
		$spec = self::fields( $panel );
		if ( is_wp_error( $spec ) ) {
			return $spec;
		}

		$values = array();
		foreach ( $spec as $id => $field ) {
			$values[ $id ] = $field['current'];
		}

		return $values;
	}

	/**
	 * AIOSEO's whole typed defaults tree.
	 *
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	public static function all_defaults(): array {
		if ( ! Aioseo_Guard::is_available() || ! isset( aioseo()->options ) ) {
			return array();
		}

		$defaults = aioseo()->options->getDefaults();

		return is_array( $defaults ) ? $defaults : array();
	}

	/**
	 * The current site-wide settings, as a plain array.
	 *
	 * @since  0.0.44
	 * @return array<string, mixed>
	 */
	public static function all_values(): array {
		if ( ! Aioseo_Guard::is_available() || ! isset( aioseo()->options ) ) {
			return array();
		}

		$all = aioseo()->options->all();

		return is_array( $all ) ? $all : array();
	}

	/**
	 * Top-level group names that are panels rather than AIOSEO bookkeeping.
	 *
	 * @since  0.0.44
	 * @return string[]
	 */
	public static function excluded_groups(): array {
		return self::EXCLUDED_GROUPS;
	}

	/**
	 * Walk a path into a tree.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $tree Tree.
	 * @param  string[]             $path Path segments.
	 * @return mixed Null when the path does not resolve.
	 */
	private static function node( array $tree, array $path ) {
		$cursor = $tree;

		foreach ( $path as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return null;
			}

			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * Current values for one panel's path.
	 *
	 * @since  0.0.44
	 * @param  string[] $path Path segments.
	 * @return mixed
	 */
	private static function current_group( array $path ) {
		return self::node( self::all_values(), $path );
	}

	/**
	 * Flatten a defaults subtree into dotted field specs, pairing each with its stored value.
	 *
	 * A node is a LEAF when it carries a STRING `type` key. The string test is what makes this safe:
	 * a group could legitimately hold a child field named `type`, and that child is an array, so it
	 * reads as a group member rather than as its parent's type marker. Verified against AIOSEO 5.0.3,
	 * where 302 leaves and 79 groups resolve with no group carrying a `type` child.
	 *
	 * @since  0.0.44
	 * @param  array<string, mixed> $defaults Defaults subtree.
	 * @param  array<string, mixed> $values   Stored values subtree.
	 * @param  string               $prefix   Dotted prefix accumulated so far.
	 * @return array<string, array<string, mixed>>
	 */
	private static function flatten( array $defaults, array $values, string $prefix = '' ): array {
		$fields = array();

		foreach ( $defaults as $key => $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$id = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( isset( $node['type'] ) && is_string( $node['type'] ) ) {
				$fields[ $id ] = array(
					'type'          => $node['type'],
					'default'       => $node['default'] ?? null,
					'current'       => array_key_exists( $key, $values ) ? $values[ $key ] : ( $node['default'] ?? null ),
					'localized'     => ! empty( $node['localized'] ),
					'preserve_html' => ! empty( $node['preserveHtml'] ),
					'nested'        => self::default_is_nested( $node['default'] ?? null ),
				);

				continue;
			}

			$child_values = ( isset( $values[ $key ] ) && is_array( $values[ $key ] ) ) ? $values[ $key ] : array();

			$fields = array_merge( $fields, self::flatten( $node, $child_values, $id ) );
		}

		return $fields;
	}
}
