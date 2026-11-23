<?php
/**
 * Fired during plugin activation.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes
 * @since      0.0.1
 */

namespace AcrossAI_Abilities_Manager\Includes;

use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\Database\AcrossAI_Abilities_Table;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Abilities_Access_Control;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Secret_Redactor;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Hardening_Settings;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Category_Slug_Migration;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Default_Opt_Ins;
use AcrossAI_Abilities_Manager\Includes\Modules\Abilities\AcrossAI_Library_Gate_Migration;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Global_Styles\Global_Styles_Flag_Migration;
use WPBoilerplate\AccessControl\Database\Rule\RuleTable;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      0.0.1
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes
 * @author     AcrossWP <deepak@acrosswp.com>
 */
class AcrossAI_Activator {

	/**
	 * Run activation tasks.
	 *
	 * Creates or upgrades the {prefix}acrossai_abilities
	 * and {prefix}abilities_access_control tables.
	 *
	 * Feature 046: also runs the one-time absorbed-code option-key migration.
	 *
	 * @since  0.0.1
	 * @return void
	 */
	public static function activate(): void {
		( new AcrossAI_Abilities_Table() )->maybe_upgrade();
		( new RuleTable( AcrossAI_Abilities_Access_Control::TABLE_SLUG ) )->maybe_upgrade();
		self::migrate_absorbed_options();
		AcrossAI_Category_Slug_Migration::maybe_migrate();
		// Feature 102 — translate the retired library gate for the activating site. Other sites on a
		// network pick it up on their own first request (plugins_loaded P1); the flag makes both
		// paths idempotent.
		AcrossAI_Library_Gate_Migration::instance()->maybe_migrate();
		// Global Styles records written before 0.0.41 lack the keys WordPress needs to apply them.
		// Idempotent and guarded by its own claim option, so this and the init-hook path cannot both
		// run the repair.
		Global_Styles_Flag_Migration::instance()->maybe_migrate();
		// Feature 117 — integrations whose default is ON. Idempotent and guarded by its own option,
		// so the plugins_loaded path and this one cannot seed twice.
		AcrossAI_Integration_Default_Opt_Ins::maybe_seed();
		self::seed_file_manager_settings();
		self::flag_quick_connect_redirect();
	}

	/**
	 * Ask the next admin page view to open the Quick Connect wizard.
	 *
	 * Feature 099. Deliberately a 30-second transient rather than an option:
	 * activation can happen with no admin page view following it (WP-CLI, a
	 * deployment script), and a persistent flag would ambush the operator on
	 * some unrelated screen hours later. Letting it expire is the correct
	 * outcome in that case (spec FR-004).
	 *
	 * The decision to actually redirect is not made here — ActivationRedirect
	 * applies the guards, including the one that leaves sites alone when the
	 * recommended transport is already present (FR-002).
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private static function flag_quick_connect_redirect(): void {
		if ( ! function_exists( 'set_transient' ) ) {
			return;
		}

		set_transient( 'acrossai_abilities_quick_connect_do_redirect', '1', 30 );
	}

	/**
	 * Feature 092: seed the three file-manager settings options with sensible
	 * defaults on activation. Uses add_option() so an existing value on
	 * re-activation or upgrade is preserved.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function seed_file_manager_settings(): void {
		add_option( Path_Allowlist_Guard::OPTION_WRITE, Path_Allowlist_Guard::DEFAULT_WRITE_ALLOWLIST );
		add_option( Path_Allowlist_Guard::OPTION_READ, Path_Allowlist_Guard::DEFAULT_READ_ALLOWLIST );
		add_option( Secret_Redactor::OPTION, Secret_Redactor::default_config() );

		// Feature 093 / 094 SCAFFOLD: seed the twelve hardening + audit option
		// keys the new File Manager tab panels bind to. Enforcement lands in
		// the follow-up features that read these keys — the UI ships first so
		// admins can see the shape and set values pre-emptively.
		add_option( Hardening_Settings::OPTION_DANGEROUS_EXTENSIONS, Hardening_Settings::DEFAULT_DANGEROUS_EXTENSIONS );
		add_option( Hardening_Settings::OPTION_BLOCK_DOUBLE_EXTENSIONS, Hardening_Settings::DEFAULT_BLOCK_DOUBLE_EXTENSIONS );
		add_option( Hardening_Settings::OPTION_HTACCESS_DIRECTIVE_SCAN, Hardening_Settings::DEFAULT_HTACCESS_DIRECTIVE_SCAN );
		add_option( Hardening_Settings::OPTION_SANITIZE_FILENAME_CHECK, Hardening_Settings::DEFAULT_SANITIZE_FILENAME_CHECK );
		add_option( Hardening_Settings::OPTION_WRITE_MAX_BYTES, Hardening_Settings::DEFAULT_WRITE_MAX_BYTES );
		add_option( Hardening_Settings::OPTION_SENSITIVE_READ_DENYLIST, Hardening_Settings::DEFAULT_SENSITIVE_READ_DENYLIST );
		add_option( Hardening_Settings::OPTION_STRICT_FILENAME_FILTER, Hardening_Settings::DEFAULT_STRICT_FILENAME_FILTER );
		add_option( Hardening_Settings::OPTION_MIME_TYPE_CHECK, Hardening_Settings::DEFAULT_MIME_TYPE_CHECK );
		add_option( Hardening_Settings::OPTION_AUDIT_LOG_ENABLED, Hardening_Settings::DEFAULT_AUDIT_LOG_ENABLED );
		add_option( Hardening_Settings::OPTION_AUDIT_LOG_RETENTION_DAYS, Hardening_Settings::DEFAULT_AUDIT_LOG_RETENTION_DAYS );
		add_option( Hardening_Settings::OPTION_BACKUP_ENABLED, Hardening_Settings::DEFAULT_BACKUP_ENABLED );
		add_option( Hardening_Settings::OPTION_BACKUP_RETENTION_DAYS, Hardening_Settings::DEFAULT_BACKUP_RETENTION_DAYS );
	}

	/**
	 * One-time absorbed-code option-key migration (Feature 046).
	 *
	 * Idempotent: repeated activation is a no-op once the legacy keys are gone.
	 * OR-monotonic for the uninstall opt-in: only ever transitions the
	 * manager's existing key false → true, never demotes a manager true.
	 *
	 * @since 0.1.0
	 * @return void
	 */
	private static function migrate_absorbed_options(): void {
		// (a) Copy the extra-MIME-types option under its new manager-branded key.
		// Preserve any manual edits: only copy when the target key is unset.
		$legacy_mimes = get_option( 'acrossai_core_abilities_extra_mimes', null );
		if ( null !== $legacy_mimes ) {
			$existing_mimes = get_option( 'acrossai_abilities_manager_extra_mimes', null );
			if ( null === $existing_mimes ) {
				update_option( 'acrossai_abilities_manager_extra_mimes', $legacy_mimes );
			}
			delete_option( 'acrossai_core_abilities_extra_mimes' );
		}

		// (b) Fold the legacy uninstall opt-in into the manager's existing one.
		// Monotonic OR: only ever flip false → true.
		$legacy_uninstall = get_option( 'acrossai_core_abilities_uninstall_delete_data', null );
		if ( null !== $legacy_uninstall ) {
			if ( ! empty( $legacy_uninstall ) && empty( get_option( 'acrossai_abilities_uninstall_delete_data', 0 ) ) ) {
				update_option( 'acrossai_abilities_uninstall_delete_data', 1 );
			}
			delete_option( 'acrossai_core_abilities_uninstall_delete_data' );
		}
	}
}
