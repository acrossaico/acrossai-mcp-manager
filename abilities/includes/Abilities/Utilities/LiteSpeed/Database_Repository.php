<?php
/**
 * Feature 104 — read-only reporting over LiteSpeed's database optimiser.
 *
 * **Read-only by necessity, not by preference.** LiteSpeed exposes no entry point for running a
 * cleanup that is usable from an ability: `DB_Optm::handler()` takes its type from `$_GET` via
 * `Router::verify_type()` and ends in `Admin::redirect()`, which exits; `handler_clean_db_cli()`
 * returns false unless `WP_CLI` is defined; `_db_clean()` is private, and the class fires no action
 * or filter anywhere. Defining `WP_CLI`, reflecting into a private method, or reimplementing the SQL
 * were all rejected — the first changes behaviour for every plugin in the request, the second breaks
 * on any LiteSpeed release, and the third abandons the rule that the host's database belongs to the
 * host.
 *
 * The counting API is public, so "show me what a cleanup would remove" works, and that is the half
 * that informs a decision. Actually running one stays in wp-admin.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\LiteSpeed
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LiteSpeed;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only repository over LiteSpeed's database optimiser.
 */
final class Database_Repository {

	/**
	 * Private constructor — static-only (DEC-UTILITY-STATIC-ONLY).
	 */
	private function __construct() {}

	/**
	 * The cleanup types LiteSpeed counts, as type => what it covers.
	 *
	 * Mirrors `DB_Optm::$types`, which is private.
	 *
	 * @since  0.0.34
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			'revision'           => __( 'Stored post revisions.', 'acrossai-abilities-manager' ),
			'orphaned_post_meta' => __( 'Post meta rows whose post no longer exists.', 'acrossai-abilities-manager' ),
			'auto_draft'         => __( 'Auto-drafts WordPress created and never used.', 'acrossai-abilities-manager' ),
			'trash_post'         => __( 'Posts in the trash.', 'acrossai-abilities-manager' ),
			'spam_comment'       => __( 'Comments marked as spam.', 'acrossai-abilities-manager' ),
			'trash_comment'      => __( 'Comments in the trash.', 'acrossai-abilities-manager' ),
			'trackback-pingback' => __( 'Trackbacks and pingbacks.', 'acrossai-abilities-manager' ),
			'expired_transient'  => __( 'Transients past their expiry.', 'acrossai-abilities-manager' ),
			'all_transients'     => __( 'Every transient, expired or not.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Which ability can actually perform each cleanup, since LiteSpeed's own cannot be called.
	 *
	 * Every entry names an ability this plugin already ships. `safe` distinguishes the two shapes:
	 *
	 * - **true** — a purpose-built ability that goes through WordPress's own APIs, so deleting a
	 *   comment also removes its meta, and deleting a transient also removes its timeout row.
	 * - **false** — only `database/delete-db-rows` fits, and that is a raw `$wpdb->delete()`.
	 *   Deleting rows from `posts` directly leaves their `postmeta` behind, which is precisely why
	 *   LiteSpeed itself carries a separate `orphaned_post_meta` type: its cleanup is a two-pass
	 *   operation. A caller taking this route must run the orphan pass afterwards or the database
	 *   ends up larger than before, not smaller.
	 *
	 * Keys must stay in step with types(); Test_LiteSpeed_Suite_Contract asserts they do, so a future
	 * LiteSpeed release adding a cleanup type cannot leave a silent hole here.
	 *
	 * @since  0.0.34
	 * @return array<string, array<string, mixed>>
	 */
	public static function recommendations(): array {
		$raw_delete = __( 'No purpose-built ability covers this. database/delete-db-rows can do it, but it is a raw row delete: removing rows from the posts table leaves their postmeta behind, so run a second database/delete-db-rows pass against the postmeta table for orphans afterwards.', 'acrossai-abilities-manager' );

		return array(
			'expired_transient'  => array(
				'ability' => 'cache/delete-expired-transients',
				'safe'    => true,
				'how'     => __( 'Purpose-built and safe: it removes each transient with its timeout row.', 'acrossai-abilities-manager' ),
			),
			'all_transients'     => array(
				'ability' => 'cache/flush-transients',
				'safe'    => true,
				'how'     => __( 'Removes every transient, expired or not. Anything cached is rebuilt on demand.', 'acrossai-abilities-manager' ),
			),
			'spam_comment'       => array(
				'ability' => 'comments/delete-comment',
				'safe'    => true,
				'how'     => __( 'List them with comments/list-comments (status: spam), then delete each. Goes through WordPress, so comment meta goes too.', 'acrossai-abilities-manager' ),
			),
			'trash_comment'      => array(
				'ability' => 'comments/delete-comment',
				'safe'    => true,
				'how'     => __( 'List them with comments/list-comments (status: trash), then delete each.', 'acrossai-abilities-manager' ),
			),
			'trackback-pingback' => array(
				'ability' => 'comments/delete-comment',
				'safe'    => true,
				'how'     => __( 'List them with comments/list-comments (type: trackback or pingback), then delete each.', 'acrossai-abilities-manager' ),
			),
			'revision'           => array(
				'ability' => 'database/delete-db-rows',
				'safe'    => false,
				'how'     => $raw_delete,
			),
			'trash_post'         => array(
				'ability' => 'database/delete-db-rows',
				'safe'    => false,
				'how'     => $raw_delete,
			),
			'auto_draft'         => array(
				'ability' => 'database/delete-db-rows',
				'safe'    => false,
				'how'     => $raw_delete,
			),
			'orphaned_post_meta' => array(
				'ability' => 'database/delete-db-rows',
				'safe'    => false,
				'how'     => __( 'A raw row delete against the postmeta table. This IS the orphan pass the other raw deletes require afterwards.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Row count per cleanup type, as ROWS.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function counts(): array {
		$db   = \LiteSpeed\Core::cls( 'DB_Optm' );
		$rows = array();

		foreach ( self::types() as $type => $describes ) {
			$rows[] = array(
				'type'      => $type,
				'count'     => (int) $db->db_count( $type ),
				'describes' => $describes,
			);
		}

		return $rows;
	}

	/**
	 * The largest autoloaded options — the usual cause of a slow site.
	 *
	 * @since  0.0.34
	 * @return array<int, array<string, mixed>>
	 */
	public static function autoload_summary(): array {
		$summary = \LiteSpeed\Core::cls( 'DB_Optm' )->autoload_summary();
		$rows    = array();

		foreach ( (array) ( is_object( $summary ) ? (array) $summary : $summary ) as $key => $value ) {
			$rows[] = array(
				'key'   => (string) $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}

		return $rows;
	}

	/**
	 * Tables still using the MyISAM engine.
	 *
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	public static function myisam_tables(): array {
		$tables = \LiteSpeed\Core::cls( 'DB_Optm' )->list_myisam();
		$names  = array();

		foreach ( (array) $tables as $table ) {
			if ( is_object( $table ) ) {
				$names[] = (string) ( $table->TABLE_NAME ?? '' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}

			$names[] = is_array( $table ) ? (string) reset( $table ) : (string) $table;
		}

		return array_values( array_filter( $names, static fn( string $v ): bool => '' !== $v ) );
	}
}
