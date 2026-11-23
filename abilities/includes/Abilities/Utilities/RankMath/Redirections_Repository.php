<?php
/**
 * Feature 069 — Rank Math redirection access and server-config serialization.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only accessor for Rank Math's redirection manager.
 *
 * The Apache and Nginx serializers below are a PORT, not a delegation. Rank Math's
 * Redirections\Export::export() reads Param::get('export') from $_GET, calls
 * check_admin_referer(), emits five header() calls, echoes and exit()s — unusable
 * from an ability — and every formatter it uses is private. Reflection was rejected
 * because it would break silently on any Rank Math refactor; a port with @see
 * citations at least fails visibly and can be re-diffed.
 *
 * Behaviour preserved exactly, including the asymmetry for invalid regex: Apache
 * emits the rule commented out with '# ', Nginx omits it entirely.
 *
 * PARITY QUIRK, verified live against Rank Math 1.0.276 and deliberately NOT fixed:
 * is_valid_regex() wraps the pattern in '/.../' to test it, so any pattern that
 * itself contains an unescaped '/' — 'legacy/(.*)' for instance — closes the
 * delimiter early and is reported invalid even though it is a perfectly good regex.
 * encode_regex() then mangles it further while "stripping delimiters". Both are Rank
 * Math's own behaviour; reproducing them is the point, since the output must match
 * what its exporter would have produced. That is what format_parity: 'ported'
 * signals, and why warnings[] surfaces the affected sources rather than silently
 * emitting a rule the site owner did not expect.
 *
 * @see seo-by-rank-math/includes/modules/redirections/class-export.php
 */
final class Redirections_Repository {

	/**
	 * Module slug.
	 */
	public const MODULE = 'redirections';

	/**
	 * Statuses DB::get_redirections() accepts.
	 */
	public const STATUSES = array( 'all', 'active', 'inactive', 'trashed' );

	/**
	 * Reversible bulk transitions, mapped to the status they set.
	 *
	 * Hard delete is deliberately absent: it is a separate, confirm-gated ability
	 * so that these four can honestly declare destructive:false.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-admin.php:423-451
	 */
	public const STATUS_ACTIONS = array(
		'activate'   => 'active',
		'deactivate' => 'inactive',
		'trash'      => 'trashed',
		'restore'    => 'active',
	);

	/**
	 * Private constructor — this class is static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * Whether Rank Math's redirection classes are loadable.
	 *
	 * @return true|WP_Error
	 */
	private static function assert_available() {
		if ( ! class_exists( '\RankMath\Redirections\DB' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math redirections module is not available.', 'acrossai-abilities-manager' ) );
		}
		return true;
	}

	/**
	 * Paginated redirection list.
	 *
	 * status=trashed is what makes emptying the trash a discoverable operation, so
	 * it must stay exposed.
	 *
	 * @param string $status  One of self::STATUSES.
	 * @param int    $limit   Page size.
	 * @param int    $page    1-based page number.
	 * @param string $search  Optional search term.
	 * @param string $orderby Column to sort by.
	 * @param string $order   ASC or DESC.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function listing( string $status, int $limit, int $page, string $search, string $orderby, string $order ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$result = \RankMath\Redirections\DB::get_redirections(
			array(
				// Rank Math treats an unrecognised status as "everything except
				// trashed", so 'all' is passed through as its own sentinel rather
				// than mapped.
				'status'  => 'all' === $status ? 'any' : $status,
				'limit'   => $limit,
				'paged'   => max( 1, $page ),
				'search'  => $search,
				'orderby' => $orderby,
				'order'   => $order,
			)
		);

		$rows  = isset( $result['redirections'] ) && is_array( $result['redirections'] ) ? $result['redirections'] : array();
		$total = isset( $result['count'] ) ? (int) $result['count'] : count( $rows );

		return array(
			'redirections' => array_map( array( self::class, 'shape' ), $rows ),
			'count'        => count( $rows ),
			'total'        => $total,
			'status'       => $status,
			'page'         => max( 1, $page ),
		);
	}

	/**
	 * Find redirections whose source rules match a URL or path.
	 *
	 * @param string $url         URL or path to test.
	 * @param bool   $active_only Restrict to active rules.
	 * @param int    $limit       Maximum matches.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function find( string $url, bool $active_only, int $limit ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		// Rank Math matches against a path with no leading slash, not a full URL.
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '' === $path ) {
			$path = $url;
		}
		$path = ltrim( $path, '/' );

		// DB::match_redirections() is deliberately NOT used here. Despite its $all
		// parameter it returns the FIRST matching row or false — never a list — so
		// it cannot answer "which rules match this URL", and iterating its return
		// as a collection silently yields nothing.
		//
		// Instead, walk the candidate rows and apply Rank Math's own public
		// comparison, so exact / contains / start / end / regex semantics stay
		// identical to what the redirect handler does at request time.
		$rows = array();
		$page = 1;
		do {
			$batch = \RankMath\Redirections\DB::get_redirections(
				array(
					'status' => $active_only ? 'active' : 'any',
					'limit'  => 200,
					'paged'  => $page,
				)
			);
			$found = isset( $batch['redirections'] ) && is_array( $batch['redirections'] ) ? $batch['redirections'] : array();
			$rows  = array_merge( $rows, $found );
			++$page;
			// Cap the sweep so a site with tens of thousands of rules cannot stall
			// the request; 2000 candidates is far beyond any realistic set.
		} while ( array() !== $found && count( $rows ) < 2000 );

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$sources = maybe_unserialize( $row['sources'] ?? array() );
			if ( ! is_array( $sources ) || array() === $sources ) {
				continue;
			}
			if ( ! \RankMath\Redirections\DB::compare_sources( $sources, $path ) ) {
				continue;
			}
			$out[] = self::shape( $row );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return array(
			'url'       => $url,
			'path'      => $path,
			'matches'   => $out,
			'count'     => count( $out ),
			'scanned'   => count( $rows ),
		);
	}

	/**
	 * Counts by status plus hit statistics.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function stats() {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$counts = \RankMath\Redirections\DB::get_counts();
		$stats  = \RankMath\Redirections\DB::get_stats();

		return array(
			'counts' => is_array( $counts ) ? $counts : array(),
			'stats'  => is_object( $stats ) ? (array) $stats : ( is_array( $stats ) ? $stats : array() ),
		);
	}

	/**
	 * Create or update a redirection.
	 *
	 * Replicates Admin_Rest::update_settings()'s redirections branch: build through
	 * Redirection::from(), test is_infinite_loop() BEFORE save(), and surface Rank
	 * Math's two distinct loop outcomes rather than collapsing them.
	 *
	 * @see seo-by-rank-math/includes/rest/class-admin.php:250-285
	 *
	 * @param array<string,mixed> $data Redirection fields; include 'id' to update.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function save( array $data ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		if ( ! class_exists( '\RankMath\Redirections\Redirection' ) ) {
			return new WP_Error( 'rank_math_module_inactive', __( 'The Rank Math redirection model is not available.', 'acrossai-abilities-manager' ) );
		}

		$is_update = ! empty( $data['id'] );

		if ( $is_update ) {
			$existing = \RankMath\Redirections\DB::get_redirection_by_id( (int) $data['id'], 'all' );
			if ( empty( $existing ) || ! is_array( $existing ) ) {
				return new WP_Error(
					'not_found',
					sprintf(
						/* translators: %d: redirection id */
						__( 'Redirection %d does not exist.', 'acrossai-abilities-manager' ),
						(int) $data['id']
					)
				);
			}

			// Redirection::from() builds a fresh object from exactly what it is
			// given — it does not load the existing row — and save() bails at
			// has_sources() when none are present. So a partial update that omits
			// `sources` would be rejected with no_valid_source, and one that omits
			// url_to or header_code would blank them. Merge the stored values in so
			// "supply only the fields you want to change" actually holds.
			$stored = array(
				'id'          => (int) $data['id'],
				'sources'     => maybe_unserialize( $existing['sources'] ?? array() ),
				'url_to'      => (string) ( $existing['url_to'] ?? '' ),
				'header_code' => (string) ( $existing['header_code'] ?? '301' ),
				'status'      => (string) ( $existing['status'] ?? 'active' ),
			);
			$data   = array_merge( $stored, $data );

			if ( ! is_array( $data['sources'] ) || array() === $data['sources'] ) {
				return new WP_Error(
					'no_valid_source',
					sprintf(
						/* translators: %d: redirection id */
						__( 'Redirection %d has no stored source rules, so it cannot be updated without supplying sources.', 'acrossai-abilities-manager' ),
						(int) $data['id']
					)
				);
			}
		}

		$redirection = \RankMath\Redirections\Redirection::from( $data );
		if ( ! is_object( $redirection ) ) {
			return new WP_Error( 'invalid_input', __( 'Rank Math could not build a redirection from that input.', 'acrossai-abilities-manager' ) );
		}

		if ( method_exists( $redirection, 'is_infinite_loop' ) && $redirection->is_infinite_loop() ) {
			// Rank Math distinguishes these: a NEW looping redirection is saved but
			// forced inactive, while an UPDATE that would loop is refused outright.
			if ( $is_update ) {
				return new WP_Error(
					'infinite_loop_update',
					__( 'That change would make the redirection point at itself, so it was refused and nothing was saved.', 'acrossai-abilities-manager' )
				);
			}

			// The model exposes set_status(), not a generic set().
			$redirection->set_status( 'inactive' );
			$id = $redirection->save();

			return array(
				'id'                => (int) $id,
				'redirection'       => self::by_id( (int) $id ),
				'auto_deactivated'  => true,
				'infinite_loop'     => true,
			);
		}

		$id = $redirection->save();
		if ( empty( $id ) ) {
			return new WP_Error(
				'no_valid_source',
				__( 'Rank Math rejected the redirection: no usable source rule. Each source needs a non-empty pattern and a comparison of exact, contains, start, end or regex.', 'acrossai-abilities-manager' )
			);
		}

		return array(
			'id'               => (int) $id,
			'redirection'      => self::by_id( (int) $id ),
			'auto_deactivated' => false,
			'infinite_loop'    => false,
		);
	}

	/**
	 * Apply a reversible bulk status transition.
	 *
	 * @param int[]  $ids    Redirection ids.
	 * @param string $action One of self::STATUS_ACTIONS keys.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function change_status( array $ids, string $action ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		if ( ! isset( self::STATUS_ACTIONS[ $action ] ) ) {
			return new WP_Error(
				'invalid_input',
				sprintf(
					/* translators: %s: comma-separated list of valid actions */
					__( 'action must be one of: %s.', 'acrossai-abilities-manager' ),
					implode( ', ', array_keys( self::STATUS_ACTIONS ) )
				)
			);
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one redirection id.', 'acrossai-abilities-manager' ) );
		}

		$status  = self::STATUS_ACTIONS[ $action ];
		$changed = (int) \RankMath\Redirections\DB::change_status( $ids, $status );

		return array(
			'ids'     => $ids,
			'action'  => $action,
			'status'  => $status,
			'changed' => $changed,
		);
	}

	/**
	 * Hard-delete redirections.
	 *
	 * @param int[] $ids Redirection ids.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function delete( array $ids ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( array() === $ids ) {
			return new WP_Error( 'invalid_input', __( 'Supply at least one redirection id.', 'acrossai-abilities-manager' ) );
		}

		return array(
			'ids'     => $ids,
			'deleted' => (int) \RankMath\Redirections\DB::delete( $ids ),
		);
	}

	/**
	 * Permanently remove everything in the trash.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function clear_trashed() {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		return array( 'deleted' => (int) \RankMath\Redirections\DB::clear_trashed() );
	}

	/**
	 * One redirection by id, in our shape.
	 *
	 * @param int $id Redirection id.
	 * @return array<string,mixed>|null
	 */
	private static function by_id( int $id ): ?array {
		$row = \RankMath\Redirections\DB::get_redirection_by_id( $id, 'all' );
		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Normalise a DB row for output.
	 *
	 * `sources` is stored serialized, so it is unserialized here rather than leaking
	 * a serialized string to the caller.
	 *
	 * @param array<string,mixed> $row Raw DB row.
	 * @return array<string,mixed>
	 */
	private static function shape( array $row ): array {
		$sources = isset( $row['sources'] ) ? maybe_unserialize( $row['sources'] ) : array();

		return array(
			'id'           => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'sources'      => is_array( $sources ) ? array_values( $sources ) : array(),
			'url_to'       => isset( $row['url_to'] ) ? (string) $row['url_to'] : '',
			'header_code'  => isset( $row['header_code'] ) ? (string) $row['header_code'] : '',
			'status'       => isset( $row['status'] ) ? (string) $row['status'] : '',
			'hits'         => isset( $row['hits'] ) ? (int) $row['hits'] : 0,
			'created'      => isset( $row['created'] ) ? (string) $row['created'] : '',
			'updated'      => isset( $row['updated'] ) ? (string) $row['updated'] : '',
			'last_accessed' => isset( $row['last_accessed'] ) ? (string) $row['last_accessed'] : '',
		);
	}

	// =====================================================================
	// Server-config serializers — ported from Rank Math's private methods.
	// =====================================================================

	/**
	 * Serialize active redirections as Apache or Nginx configuration.
	 *
	 * @param string $format 'apache' or 'nginx'.
	 * @param int    $limit  Maximum redirections to include.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function export( string $format, int $limit ) {
		$available = self::assert_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}
		if ( ! in_array( $format, array( 'apache', 'nginx' ), true ) ) {
			return new WP_Error( 'invalid_input', __( 'format must be apache or nginx.', 'acrossai-abilities-manager' ) );
		}

		$result = \RankMath\Redirections\DB::get_redirections(
			array(
				'limit'  => $limit,
				'status' => 'active',
			)
		);
		$items  = isset( $result['redirections'] ) && is_array( $result['redirections'] ) ? $result['redirections'] : array();

		$warnings = array();
		$lines    = array(
			'# Created by Rank Math',
			'# Exported via rank-math/export-redirections',
			'',
		);

		$body  = 'apache' === $format
			? self::to_apache( $items, $warnings )
			: self::to_nginx( $items, $warnings );
		$lines = array_merge( $lines, $body, array( '', '# Rank Math Redirections END' ) );

		return array(
			'format'        => $format,
			'content'       => implode( PHP_EOL, $lines ) . PHP_EOL,
			'rule_count'    => count( $items ),
			'warnings'      => $warnings,
			// Flags that this output is produced by our port of Rank Math's private
			// formatters rather than by Rank Math itself.
			'format_parity' => 'ported',
		);
	}

	/**
	 * Apache rewrite block.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:90-121
	 *
	 * @param array<int,array<string,mixed>> $items    Redirection rows.
	 * @param array<int,string>              $warnings Collected warnings, by reference.
	 * @return string[]
	 */
	private static function to_apache( array $items, array &$warnings ): array {
		$output = array( '<IfModule mod_rewrite.c>' );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$header_code = (string) ( $item['header_code'] ?? '301' );
			$target      = '410' === $header_code
				? '- [G]'
				: sprintf( '%s [R=%d,L]', self::encode2nd( (string) ( $item['url_to'] ?? '' ) ), (int) $header_code );

			$sources = maybe_unserialize( $item['sources'] ?? array() );
			foreach ( (array) $sources as $from ) {
				if ( ! is_array( $from ) ) {
					continue;
				}
				$url        = (string) ( $from['pattern'] ?? '' );
				$comparison = (string) ( $from['comparison'] ?? 'exact' );

				// A query string cannot be matched by RewriteRule, so Rank Math
				// splits it into a preceding RewriteCond.
				if ( ( 'regex' !== $comparison && str_contains( $url, '?' ) ) || str_contains( $url, '&' ) ) {
					$parts    = wp_parse_url( $url );
					$url      = isset( $parts['path'] ) ? (string) $parts['path'] : $url;
					$output[] = sprintf( 'RewriteCond %%{QUERY_STRING} ^%s$', preg_quote( (string) ( $parts['query'] ?? '' ), '' ) );
				}

				$valid = self::is_valid_regex( $from );
				if ( ! $valid ) {
					$warnings[] = sprintf(
						/* translators: 1: source pattern, 2: redirection id */
						__( 'Source "%1$s" (redirection %2$d) is not a valid regular expression, so the Apache rule is commented out.', 'acrossai-abilities-manager' ),
						(string) ( $from['pattern'] ?? '' ),
						(int) ( $item['id'] ?? 0 )
					);
				}

				$output[] = sprintf(
					'%sRewriteRule %s %s',
					$valid ? '' : '# ',
					self::get_comparison( $url, $from ),
					$target
				);
			}
		}

		$output[] = '</IfModule>';
		return $output;
	}

	/**
	 * Nginx server block.
	 *
	 * Invalid-regex sources are SKIPPED entirely here, unlike Apache where they are
	 * commented out. Preserved deliberately.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:126-160
	 *
	 * @param array<int,array<string,mixed>> $items    Redirection rows.
	 * @param array<int,string>              $warnings Collected warnings, by reference.
	 * @return string[]
	 */
	private static function to_nginx( array $items, array &$warnings ): array {
		$output = array( 'server {' );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$raw_code    = (string) ( $item['header_code'] ?? '' );
			$target      = self::encode2nd( (string) ( $item['url_to'] ?? '' ) );
			$header_code = '301' === $raw_code ? 'permanent' : 'redirect';
			$sources     = maybe_unserialize( $item['sources'] ?? array() );

			// Nginx has no equivalent of Apache's [G] flag, and Rank Math's
			// nginx_item() does not special-case 410/451 — so a gone/unavailable
			// rule serializes with an EMPTY target, producing an invalid directive
			// that nginx will refuse to load. The output is left byte-identical to
			// Rank Math's for parity, but the caller is warned, since warnings[]
			// exists precisely so this is reviewed before the config is applied.
			if ( in_array( $raw_code, array( '410', '451' ), true ) ) {
				$warnings[] = sprintf(
					/* translators: 1: redirection id, 2: HTTP status code */
					__( 'Redirection %1$d uses status %2$s, which has no destination. Nginx has no equivalent of Apache\'s [G] flag, so the generated rewrite line has an empty target and nginx will reject it — replace that line with a "return %2$s;" directive in a matching location block.', 'acrossai-abilities-manager' ),
					(int) ( $item['id'] ?? 0 ),
					$raw_code
				);
			}

			foreach ( (array) $sources as $from ) {
				if ( ! is_array( $from ) ) {
					continue;
				}
				if ( ! self::is_valid_regex( $from ) ) {
					$warnings[] = sprintf(
						/* translators: 1: source pattern, 2: redirection id */
						__( 'Source "%1$s" (redirection %2$d) is not a valid regular expression and was omitted from the Nginx output.', 'acrossai-abilities-manager' ),
						(string) ( $from['pattern'] ?? '' ),
						(int) ( $item['id'] ?? 0 )
					);
					continue;
				}

				$output[] = self::normalize_nginx_redirect(
					self::get_comparison( (string) ( $from['pattern'] ?? '' ), $from ),
					$target,
					$header_code
				);
			}
		}

		$output[] = '}';
		return $output;
	}

	/**
	 * Whether a source's pattern is a usable regex.
	 *
	 * Guards against writing a broken rule into .htaccess and taking the site down.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:166-172
	 *
	 * @param array<string,mixed> $source Source row.
	 * @return bool
	 */
	private static function is_valid_regex( array $source ): bool {
		if ( 'regex' !== ( $source['comparison'] ?? '' ) ) {
			return true;
		}
		// Rank Math passes null as the subject; '' is the PHP 8.1-safe equivalent.
		return false !== @preg_match( '/' . ( $source['pattern'] ?? '' ) . '/', '' ); // phpcs:ignore
	}

	/**
	 * Build the match pattern for a source.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:196-215
	 *
	 * @param string              $url  Pattern, possibly query-stripped.
	 * @param array<string,mixed> $from Source row.
	 * @return string
	 */
	private static function get_comparison( string $url, array $from ): string {
		$comparison = (string) ( $from['comparison'] ?? 'exact' );
		if ( 'regex' === $comparison ) {
			return self::encode_regex( (string) ( $from['pattern'] ?? '' ) );
		}

		$hash = array(
			'exact'    => '^{url}/?$',
			'contains' => '^(.*){url}(.*)$',
			'start'    => '^{url}',
			'end'      => '{url}/?$',
		);

		$quoted = preg_quote( $url, '' );
		return isset( $hash[ $comparison ] ) ? str_replace( '{url}', $quoted, $hash[ $comparison ] ) : $quoted;
	}

	/**
	 * Strip control characters and newlines, then format an Nginx rewrite line.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:181-188
	 *
	 * @param string $source      Match pattern.
	 * @param string $target      Redirect target.
	 * @param string $header_code 'permanent' or 'redirect'.
	 * @return string
	 */
	private static function normalize_nginx_redirect( string $source, string $target, string $header_code ): string {
		$source = (string) preg_replace( "/[\r\n\t].*?$/s", '', $source );
		$source = (string) preg_replace( '/[^\PC\s]/u', '', $source );
		$target = (string) preg_replace( "/[\r\n\t].*?$/s", '', $target );
		$target = (string) preg_replace( '/[^\PC\s]/u', '', $target );

		return "    rewrite {$source} {$target} {$header_code};";
	}

	/**
	 * URL-encode a target while leaving path and query punctuation intact.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:231-242
	 *
	 * @param string $url Target URL.
	 * @return string
	 */
	private static function encode2nd( string $url ): string {
		$url = rawurlencode( $url );
		return str_replace(
			array( '%2F', '%3F', '%3A', '%3D', '%26', '%25', '+', '%24' ),
			array( '/', '?', ':', '=', '&', '%', '%20', '$' ),
			$url
		);
	}

	/**
	 * Normalise a user-supplied regex for use in a server config.
	 *
	 * @see seo-by-rank-math/includes/modules/redirections/class-export.php:250-260
	 *
	 * @param string $url Regex pattern.
	 * @return string
	 */
	private static function encode_regex( string $url ): string {
		// Strip delimiters and trailing modifiers.
		$url = (string) preg_replace( '/[^a-zA-Z0-9\s](.*)[^a-zA-Z0-9\s][imsxeADSUXJu]*/', '$1', $url );
		$url = (string) preg_replace( "/[\r\n\t].*?$/s", '', $url );
		$url = (string) preg_replace( '/[^\PC\s]/u', '', $url );
		$url = str_replace( array( ' ', '%24' ), array( '%20', '$' ), $url );
		$url = ltrim( $url, '/' );
		// A leading ^/ would never match once the slash is stripped.
		return (string) preg_replace( '@^\^/@', '^', $url );
	}
}
