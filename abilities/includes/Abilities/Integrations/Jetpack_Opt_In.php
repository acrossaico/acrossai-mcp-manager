<?php
/**
 * Jetpack's own abilities — the filter that makes them exist.
 *
 * Jetpack ships abilities from a shared package, `jetpack_vendor/automattic/jetpack-wp-abilities`,
 * which four of its feature packages call into: jetpack-stats, jetpack-forms, jetpack-backup and
 * jetpack-connection. Each calls `…_Abilities::init()` unconditionally during its own load, and the
 * gate sits inside the shared registrar (`src/class-registrar.php:91`):
 *
 *     if ( ! apply_filters( 'jetpack_wp_abilities_enabled', false ) ) {
 *         return;
 *     }
 *
 * Automattic's own comment explains the default:
 *
 * > Consumers opt in explicitly — per site, per user, or via feature flag — so abilities roll out
 * > gradually rather than flipping on for every site the moment the package loads.
 *
 * **Timing is the risk here.** Those packages read the filter while they load, not on a later hook,
 * so the filter has to be attached before Jetpack's own setup runs. `plugins_loaded` P20 — the base
 * class default, which suits ACF because ACF reads its setting at `acf/init` — is too late to be
 * safe, so {@see self::enable_hook()} moves it earlier. A switch that appears to work while doing
 * nothing is the failure mode this avoids.
 *
 * Four namespaces, not one: `jetpack-stats/*`, `jetpack-forms/*`, `jetpack-backup/*` and
 * `jetpack/get-connection-status`. There is no Jetpack toolset descriptor yet, so until one claims
 * those prefixes these land in **Other** — the intended failure per
 * DEC-TOOLSET-PREFIX-CLAIM-ONLY-WHAT-EXISTS, visible in the catch-all rather than mislabelled as
 * ours. Which also means this row does not yet gain a count from the toolset side; it appears
 * through its own Library definition below.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage includes/Abilities/Integrations
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Integrations\AcrossAI_Integration_Ability_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes Jetpack's abilities flag as one switch.
 */
final class Jetpack_Opt_In extends AcrossAI_Integration_Ability_Base {

	/**
	 * Group and Library category this integration owns.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const TAB_GROUP = 'jetpack';

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function slug(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	protected function label(): string {
		return __( 'Jetpack (its own abilities)', 'acrossai-abilities-manager' );
	}

	/**
	 * Attach the filter before Jetpack's packages read it.
	 *
	 * `plugins_loaded` P1: early enough that Jetpack's feature packages have not initialised, and
	 * still on `plugins_loaded` so `is_plugin_active()` can see whether the package is there at all.
	 *
	 * @since  0.0.42
	 * @return array{0: string, 1: int}
	 */
	protected function enable_hook(): array {
		return array( 'plugins_loaded', 1 );
	}

	/**
	 * Seeded on, for the same reason Elementor's is.
	 *
	 * Changed together with {@see Elementor_Opt_In::seeds_enabled()} and for the same argument: a
	 * row that reports an integration as enabled must not be withholding part of it. Leaving this
	 * one off while Elementor's went on would have made the two inconsistent on nothing more than
	 * which was discussed first.
	 *
	 * Reversible in one click, and non-destructive: switching the row off leaves Jetpack's own
	 * setting as its owner left it.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function seeds_enabled(): bool {
		return true;
	}

	/**
	 * Whether the shared abilities package is present.
	 *
	 * Keyed on the package class rather than on any one plugin: the abilities arrive with whichever
	 * of jetpack, jetpack-backup, jetpack-boost or jetpack-search happens to be active, and the
	 * package is the thing they all share.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	protected function is_plugin_active(): bool {
		return class_exists( '\Automattic\Jetpack\WP_Abilities\Registrar' );
	}

	/**
	 * @since  0.0.42
	 * @return void
	 */
	protected function enable_filter(): void {
		add_filter( 'jetpack_wp_abilities_enabled', '__return_true' );
	}

	/**
	 * What switching this on unlocks, by package.
	 *
	 * Named per package rather than per ability: which of them are present depends on which Jetpack
	 * plugins are active, so a flat slug list would describe abilities a given site cannot have.
	 *
	 * @since  0.0.42
	 * @return array<int, array{slug: string, label: string, description?: string}>
	 */
	protected function abilities(): array {
		return array(
			array(
				'slug'        => 'jetpack-stats/get-site-overview',
				'label'       => __( 'Jetpack Stats', 'acrossai-abilities-manager' ),
				'description' => __( 'Site overview, visits, post views, top content, followers and the Stats settings.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'        => 'jetpack-forms/get-responses',
				'label'       => __( 'Jetpack Forms', 'acrossai-abilities-manager' ),
				'description' => __( 'Read, create and delete forms, and read and bulk-update their responses.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'        => 'jetpack-backup/list-backups',
				'label'       => __( 'Jetpack Backup', 'acrossai-abilities-manager' ),
				'description' => __( 'Backup overview, the list of backups and restores, and requesting a backup.', 'acrossai-abilities-manager' ),
			),
			array(
				'slug'        => 'jetpack/get-connection-status',
				'label'       => __( 'Jetpack connection', 'acrossai-abilities-manager' ),
				'description' => __( 'Whether this site is connected to WordPress.com, which the rest depend on.', 'acrossai-abilities-manager' ),
			),
		);
	}
}
