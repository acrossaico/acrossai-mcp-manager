<?php
/**
 * Issue #202 — records ability names that more than one registrant tried to claim.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities;

defined( 'ABSPATH' ) || exit;

/**
 * Makes a silent name clash visible.
 *
 * `WP_Abilities_Registry::register()` refuses a duplicate name: the first registration wins, the
 * second gets `_doing_it_wrong()` and null (class-wp-abilities-registry.php:83-91). Nothing else
 * reports it. On a production site `_doing_it_wrong` goes nowhere, so a plugin can lose an ability
 * to another plugin and the only symptom is that the ability behaves like someone else's — or, where
 * the loser was the real implementation and the winner a placeholder, that it stops working at all.
 *
 * Which side wins is decided purely by hook order, so the same two plugins can resolve differently
 * on two sites. That is what makes it worth surfacing rather than reasoning about.
 *
 * Detection takes two listeners, because one cannot see the case this class exists for (issue #260).
 *
 * 1. `wp_register_ability_args`, at P1. Cheap — a name and a label per attempt, no backtrace. This
 *    runs for every ability on every request, so anything heavier would be a real cost for a
 *    diagnostic nobody reads most days. It sees the winner, and any registrant that failed *after*
 *    the filter (an unregistered category, a constructor throw).
 *
 *    It does NOT see a duplicate. `register()` bails at line 97 and only fires the filter at line
 *    139, so the registration a plugin actually loses never reaches it. This class previously
 *    claimed the opposite, which is why Elementor losing `elementor/create-page` to us went
 *    unreported while three unrelated names were reported.
 *
 * 2. `doing_it_wrong_run`, at P1, filtered to `WP_Abilities_Registry::register`. That action fires
 *    BEFORE the `WP_DEBUG` gate in `_doing_it_wrong()`, so it works on a production site where the
 *    notice itself goes nowhere.
 *
 *    The ability name and the losing registrant's label come out of the backtrace frame's arguments
 *    rather than out of the message, so nothing here depends on the message's wording or locale.
 *    `register()` emits `_doing_it_wrong` on five paths; the one we want is told apart by whether
 *    the name is registered at the moment of the notice. Only the duplicate path can be, because it
 *    is the registered name that caused the refusal — every other path fails before the ability
 *    exists. Verified against a deliberate duplicate and a deliberately-bad category.
 *
 *    The backtrace is bounded to 12 frames and only taken on the rejection path, which is rare.
 *
 * After registration settles, the winner is whichever label the registry actually holds.
 *
 * @since 0.0.34
 */
final class Ability_Collision_Recorder {

	/**
	 * Attempted registrations: name => list of labels, in attempt order.
	 *
	 * @since 0.0.34
	 * @var   array<string, array<int, string>>
	 */
	private static $attempts = array();

	/**
	 * Registrations WordPress refused as duplicates: name => list of the losing labels.
	 *
	 * Separate from `$attempts` because the two are different evidence. A name in `$attempts` twice
	 * means two registrants reached the args filter and one later failed for some other reason. A
	 * name here means WordPress turned a registrant away at the door — the plugin lost the ability,
	 * and we know which label lost rather than having to infer it.
	 *
	 * @since 0.0.42
	 * @var   array<string, array<int, string>>
	 */
	private static $rejected = array();

	/**
	 * Plugin directories whose registration was refused: name => set of plugin folders.
	 *
	 * @since 0.0.42
	 * @var   array<string, array<string, bool>>
	 */
	private static $rejected_plugins = array();

	/**
	 * Whether the filter has been attached.
	 *
	 * @since 0.0.34
	 * @var   bool
	 */
	private static $listening = false;

	/**
	 * Private constructor — static utility (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Start recording.
	 *
	 * Priority 1 so the record is taken before any other filter can rewrite the args — including our
	 * own override processor at P100000, which may legitimately change the label.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function listen(): void {
		if ( self::$listening ) {
			return;
		}

		self::$listening = true;

		add_filter( 'wp_register_ability_args', array( __CLASS__, 'record' ), 1, 2 );

		// The only place WordPress reports a refused duplicate. P1 for the same reason as above, and
		// three args so the callback can filter on the function name rather than inspecting every
		// `_doing_it_wrong` the request produces.
		add_action( 'doing_it_wrong_run', array( __CLASS__, 'record_rejection' ), 1, 3 );
	}

	/**
	 * Filter callback. Records and returns the args untouched.
	 *
	 * @since  0.0.34
	 * @param  array<string, mixed> $args Ability args.
	 * @param  string               $name Ability name.
	 * @return array<string, mixed>
	 */
	public static function record( $args, $name = '' ) {
		$name = (string) $name;

		if ( '' !== $name ) {
			$label = is_array( $args ) && isset( $args['label'] ) ? (string) $args['label'] : '';

			if ( ! isset( self::$attempts[ $name ] ) ) {
				self::$attempts[ $name ] = array();
			}

			self::$attempts[ $name ][] = $label;
		}

		return $args;
	}

	/**
	 * Action callback. Records a registration WordPress refused as a duplicate.
	 *
	 * Ignores every `_doing_it_wrong` that is not `WP_Abilities_Registry::register`, including
	 * `wp_register_ability`'s own "must be registered on the wp_abilities_api_init action" — that is
	 * a timing mistake by the caller, not a contested name, and it never reaches the registry.
	 *
	 * @since  0.0.42
	 * @param  string $function_name Function reported.
	 * @param  string $message       Message. Unused: the wording is translated, so the name and label
	 *                               are read from the call's arguments instead.
	 * @param  string $version       Version the notice was added in. Unused.
	 * @return void
	 */
	public static function record_rejection( $function_name = '', $message = '', $version = '' ): void {
		unset( $message, $version );

		if ( 'WP_Abilities_Registry::register' !== (string) $function_name ) {
			return;
		}

		$frame = self::register_frame();

		if ( null === $frame ) {
			return;
		}

		$name = isset( $frame['args'][0] ) ? (string) $frame['args'][0] : '';

		if ( '' === $name ) {
			return;
		}

		/*
		 * Tells the duplicate refusal apart from the other four `_doing_it_wrong` paths in
		 * `register()` without reading the message. Only a duplicate can be refused while the name is
		 * already held; an invalid name, an unregistered category, a bad `ability_class` and a
		 * constructor throw all happen while nothing holds it.
		 */
		if ( ! self::name_is_held( $name ) ) {
			return;
		}

		$args  = isset( $frame['args'][1] ) && is_array( $frame['args'][1] ) ? $frame['args'][1] : array();
		$label = isset( $args['label'] ) ? (string) $args['label'] : '';

		// The label is often useless on its own: two plugins implementing the same ability tend to
		// label it the same way, which is precisely why inferring the loser from labels failed. The
		// plugin that made the call is the answer an operator actually needs.
		self::record_refusal( $name, $label, self::calling_plugin() );
	}

	/**
	 * Record one refused registration.
	 *
	 * The seam between reading a refusal out of the backtrace and storing it. Separate because a
	 * backtrace cannot be synthesised in a test, so the storage and reporting behaviour is exercised
	 * through here while {@see self::record_rejection()} keeps the part that only a live refusal can
	 * produce. The same split the pass-through tests already use for {@see self::record()}.
	 *
	 * @since  0.0.42
	 * @param  string $name   Ability name that was refused.
	 * @param  string $label  Label the refused registrant declared, if any.
	 * @param  string $plugin Plugin directory the refused call came from, or '' when unknown.
	 * @return void
	 */
	public static function record_refusal( string $name, string $label = '', string $plugin = '' ): void {
		if ( '' === $name ) {
			return;
		}

		if ( ! isset( self::$rejected[ $name ] ) ) {
			self::$rejected[ $name ] = array();
		}

		self::$rejected[ $name ][] = $label;

		if ( '' !== $plugin ) {
			self::$rejected_plugins[ $name ][ $plugin ] = true;
		}
	}

	/**
	 * The plugin directory whose code called `wp_register_ability()`.
	 *
	 * Read from the `wp_register_ability` frame rather than the `register` one: in a backtrace a
	 * frame's `file` is where that function was called FROM, so `register`'s frame points at
	 * `abilities-api.php` and the frame above it points at the plugin.
	 *
	 * @since  0.0.42
	 * @return string Plugin directory name, or '' when the call did not come from a plugin.
	 */
	private static function calling_plugin(): string {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( 'wp_register_ability' !== ( $frame['function'] ?? '' ) || isset( $frame['class'] ) ) {
				continue;
			}

			$file = (string) ( $frame['file'] ?? '' );
			$base = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
			$file = wp_normalize_path( $file );

			if ( '' === $file || 0 !== strpos( $file, $base ) ) {
				return '';
			}

			$relative = substr( $file, strlen( $base ) );
			$slash    = strpos( $relative, '/' );

			return false !== $slash ? substr( $relative, 0, $slash ) : $relative;
		}

		return '';
	}

	/**
	 * The `WP_Abilities_Registry::register()` frame, with its arguments.
	 *
	 * Bounded to 12 frames: `_doing_it_wrong` is called directly by `register()`, so the frame is
	 * two or three deep and an unbounded trace would copy every argument above it for nothing.
	 *
	 * @since  0.0.42
	 * @return array<string, mixed>|null
	 */
	private static function register_frame(): ?array {
		foreach ( debug_backtrace( 0, 12 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( 'register' === ( $frame['function'] ?? '' ) && 'WP_Abilities_Registry' === ( $frame['class'] ?? '' ) ) {
				return $frame;
			}
		}

		return null;
	}

	/**
	 * Whether the registry currently holds this name.
	 *
	 * @since  0.0.42
	 * @param  string $name Ability name.
	 * @return bool
	 */
	private static function name_is_held( string $name ): bool {
		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			return false;
		}

		$registry = \WP_Abilities_Registry::get_instance();

		return null !== $registry && $registry->is_registered( $name );
	}

	/**
	 * Names that were claimed more than once, with who won.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function collisions(): array {
		$rows = array();

		// Every name either listener saw. A refused duplicate appears only in `$rejected`, so keying
		// off `$attempts` alone is what made this report miss the real collisions.
		$names = array_unique( array_merge( array_keys( self::$attempts ), array_keys( self::$rejected ) ) );
		sort( $names );

		foreach ( $names as $name ) {
			$labels   = self::$attempts[ $name ] ?? array();
			$refused  = self::$rejected[ $name ] ?? array();
			$attempts = count( $labels ) + count( $refused );

			if ( $attempts < 2 ) {
				continue;
			}

			$registered = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
			$winner     = ( null !== $registered && is_object( $registered ) && method_exists( $registered, 'get_label' ) )
				? (string) $registered->get_label()
				: '';

			/*
			 * The losers are every attempted label that is not the one the registry kept. Compared by
			 * label rather than by order: our own override processor can rewrite a label between the
			 * attempt and registration, so "the first attempt won" is not safe to assume.
			 */
			$losers = array();

			foreach ( $labels as $label ) {
				if ( $label !== $winner ) {
					$losers[] = $label;
				}
			}

			// A refused label is a loser as a matter of record, not inference: WordPress turned it
			// away. It is included even when it equals the winner's label, because two plugins using
			// the same label is exactly the case the old inference-by-label could not see.
			$losers = array_values( array_unique( array_merge( $losers, $refused ) ) );

			$row = array(
				'ability'  => (string) $name,
				'attempts' => $attempts,
				'refused'  => count( $refused ),
				'winner'   => $winner,
				'losers'   => $losers,
			);

			if ( ! empty( self::$rejected_plugins[ $name ] ) ) {
				$row['lost_by'] = array_keys( self::$rejected_plugins[ $name ] );
			}

			/*
			 * Two cases where the loser list alone would mislead, both real:
			 *
			 * - No winner at all. The name is contested but nothing holds it now, because something
			 *   unregistered it afterwards - our own Force Block does exactly that at
			 *   wp_abilities_api_init P100001. Every label then looks like a loser, implying a fight
			 *   nobody won rather than an ability deliberately removed.
			 * - Identical labels. Comparing labels cannot separate the registrants, so the list comes
			 *   back empty and the row reads as "two attempts, nothing discarded" - the opposite of
			 *   the truth. Measured on acf/register-custom-post-type, where our placeholder label
			 *   matched ACF's exactly.
			 */
			$no_winner = __( 'This name is contested but nothing currently holds it, so no winner can be named. It was most likely unregistered after the fact - a per-ability Force Block does this.', 'acrossai-abilities-manager' );

			if ( ! empty( $refused ) ) {
				/*
				 * The refusal leads, because it is the only one of these that is a fact rather than an
				 * inference, and the only one that means a plugin lost an ability. When nothing holds
				 * the name either, both are true and both are said: something refused a registrant and
				 * then something else removed the winner.
				 */
				$row['note'] = __( 'WordPress refused a registration for this name outright, so a plugin lost this ability. Which plugin wins is decided by load order alone, so another site with the same plugins can resolve the other way.', 'acrossai-abilities-manager' );

				if ( '' === $winner ) {
					$row['note'] .= ' ' . $no_winner;
				}
			} elseif ( '' === $winner ) {
				$row['note'] = $no_winner;
			} elseif ( empty( $losers ) ) {
				$row['note'] = __( 'A registration was still discarded; the registrants used identical labels, so which one lost cannot be told apart from the label alone.', 'acrossai-abilities-manager' );
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Every name seen, for the "nothing collided" case to mean something.
	 *
	 * @since  0.0.34
	 * @return int
	 */
	public static function attempted_count(): int {
		return count( array_unique( array_merge( array_keys( self::$attempts ), array_keys( self::$rejected ) ) ) );
	}

	/**
	 * How many registrations WordPress refused as duplicates.
	 *
	 * Distinct from the collision count: one contested name can have refused more than one
	 * registration. A non-zero value here means a plugin is losing an ability right now.
	 *
	 * @since  0.0.42
	 * @return int
	 */
	public static function refused_count(): int {
		$total = 0;

		foreach ( self::$rejected as $labels ) {
			$total += count( $labels );
		}

		return $total;
	}

	/**
	 * Whether recording is active.
	 *
	 * A report of zero collisions is only meaningful if the recorder was listening before the
	 * registrations happened; otherwise it means "nothing was watched", which is a different claim.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public static function is_listening(): bool {
		return self::$listening;
	}

	/**
	 * Reset — tests only.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	public static function reset(): void {
		self::$attempts         = array();
		self::$rejected         = array();
		self::$rejected_plugins = array();
	}
}
