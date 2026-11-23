<?php
/**
 * One-time repair of wp_global_styles records written without the flags WordPress requires.
 *
 * ## Why a migration is needed at all
 *
 * Before 0.0.41 every DB write through `blocks/create-global-style` and `blocks/update-global-style`
 * stored `settings` and `styles` with no `isGlobalStylesUserThemeJSON` key.
 * `WP_Theme_JSON_Resolver::get_user_data()` rejects such a record outright, so the styles were
 * stored, reported back on read, and never applied. The write path is fixed, but a fix to the write
 * path only helps sites that write again — a site that set its palette once and moved on would keep
 * serving theme defaults indefinitely, with a record in the database that looks correct.
 *
 * ## What it touches, and what it refuses to touch
 *
 * Only rows whose content **decodes** and whose post_name carries core's `wp-global-styles-` prefix:
 *
 *  - Content that does not decode is left alone. There is nothing safe to infer from bytes that are
 *    not JSON, and rewriting them would destroy whatever a hand-repair could still recover.
 *  - Rows without the prefix are block style variations sharing the post type. They are read by a
 *    different core path and must not carry the flag.
 *  - Nothing else in the record is altered: keys, order and values are preserved; `version` is
 *    stamped to the current schema and the flag is added.
 *
 * ## Trigger
 *
 * Plugin activation, plus `init` on every request path, both guarded by a per-site claim option.
 * Not admin-only: the abilities that read and write these records are reachable over REST and MCP,
 * neither of which loads wp-admin, so an admin-only trigger would leave an unattended site reporting
 * `origin: "db"` for a record WordPress is ignoring.
 *
 * Per-site rather than network-wide because wp_global_styles posts are per-site.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Global_Styles
 * @since      0.0.41
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles;

defined( 'ABSPATH' ) || exit;

/**
 * Adds version + isGlobalStylesUserThemeJSON to records written by earlier versions.
 */
class Global_Styles_Flag_Migration {

	/**
	 * Per-site flag recording that the repair has been claimed.
	 *
	 * @var string
	 */
	public const DONE_OPTION = 'acrossai_global_styles_flag_migration_done';

	/**
	 * Singleton instance.
	 *
	 * @var Global_Styles_Flag_Migration|null
	 */
	protected static $_instance = null; // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore -- plugin-wide singleton convention.

	/**
	 * Retrieve the singleton.
	 *
	 * @since  0.0.41
	 * @return Global_Styles_Flag_Migration
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 0.0.41
	 */
	private function __construct() {}

	/**
	 * Claim the repair for this site and run it exactly once.
	 *
	 * `add_option()` is a single INSERT that returns false when the row already exists, so of N
	 * concurrent requests exactly one wins the claim.
	 *
	 * @since  0.0.41
	 * @return void
	 */
	public function maybe_migrate(): void {
		if ( false === add_option( self::DONE_OPTION, '1', '', false ) ) {
			return;
		}

		$this->repair_all();
	}

	/**
	 * Repair every eligible record on this site.
	 *
	 * @since  0.0.41
	 * @return array{examined: int, repaired: int, skipped: int} Counts, for tests and WP-CLI callers.
	 */
	public function repair_all(): array {
		$examined = 0;
		$repaired = 0;
		$skipped  = 0;

		foreach ( Global_Styles_Db::list_all( 500 ) as $post ) {
			if ( ! Global_Styles_Db::is_main_record( $post ) ) {
				continue;
			}

			++$examined;

			if ( ! self::needs_repair( (string) $post->post_content ) ) {
				continue;
			}

			$result = Global_Styles_Db::repair( $post );
			if ( is_wp_error( $result ) || empty( $result['changed'] ) ) {
				++$skipped;
				continue;
			}

			++$repaired;
		}

		return array(
			'examined' => $examined,
			'repaired' => $repaired,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Whether stored content is a record this migration should rewrite.
	 *
	 * Split out and public so the selection rule — the only thing standing between this and an
	 * unattended rewrite of content it should not touch — is testable without a database.
	 *
	 * @since  0.0.41
	 * @param  string $content Raw post_content.
	 * @return bool
	 */
	public static function needs_repair( string $content ): bool {
		$decoded = Global_Styles_Writer::decode( $content );

		// Not JSON: nothing safe to infer, and rewriting would destroy a recoverable record.
		if ( is_wp_error( $decoded ) ) {
			return false;
		}

		// Empty: core creates this record lazily, and seeding an empty one here would make the
		// detector report a DB copy that overrides a theme.json the site is happily serving.
		if ( array() === $decoded ) {
			return false;
		}

		if ( ! Global_Styles_Writer::has_user_flags( $decoded ) ) {
			return true;
		}

		return ! isset( $decoded['version'] ) || Global_Styles_Writer::latest_schema() !== (int) $decoded['version'];
	}
}
