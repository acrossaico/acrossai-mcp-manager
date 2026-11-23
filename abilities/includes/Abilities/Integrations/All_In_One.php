<?php
/**
 * All-in-One WP Migration's toolset declaration.
 *
 * Like {@see UpdraftPlus}, this is a forward-looking claim: All-in-One WP Migration ships no
 * abilities of its own today, so every `all-in-one/*` ability on a site is ours and already
 * declares its group inline.
 *
 * It exists so the day the host plugin adds Abilities API support, its abilities are grouped on
 * arrival rather than falling through to `other` — the way issue #184 (Rank Math) and issue #255
 * (Elementor) both happened.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\AllInOne\All_In_One_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Groups All-in-One WP Migration's abilities alongside this plugin's.
 */
final class All_In_One implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Matches what this plugin's All-in-One abilities declare inline and
	 * `Toolset\All_In_One::group()`. All three must agree or the halves split apart.
	 *
	 * Note it is not the same string as the ability namespace: the group is
	 * `all-in-one-wp-migration` (the plugin slug) while our abilities are named `all-in-one/*`.
	 * Both are claimed below.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const TAB_GROUP = 'all-in-one-wp-migration';

	/**
	 * @since  0.0.42
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Display name.
	 *
	 * Currently unused: `Toolset\All_In_One` is a hand-written dispatcher and supplies the live
	 * label and description — `all-in-one-wp-migration` is listed in the bootstrap's `$claimed`
	 * array. Declared anyway so that retiring that class is a deletion rather than a rewrite.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'All-in-One WP Migration', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Whether this site can be recovered through All-in-One WP Migration: what archives exist and how recent they are, whether they are reachable over HTTP, and exporting, labelling or removing one. Restoring is part of their paid Unlimited Extension, and the ability says so rather than failing. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Ability names in the All-in-One namespaces.
	 *
	 * Two claims, because the group key and the ability namespace differ here. `all-in-one` is the
	 * namespace our own abilities use and is distinctive enough to claim safely; the plugin slug is
	 * claimed for whatever the host plugin itself would register under.
	 *
	 * @since  0.0.42
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'all-in-one', 'all-in-one-wp-migration' );
	}

	/**
	 * Whether All-in-One WP Migration is present.
	 *
	 * Delegates to the guard every All-in-One read in this plugin already routes through, so the
	 * toolset and the abilities can never disagree about whether the host plugin is there.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	public function is_active(): bool {
		return All_In_One_Guard::is_available();
	}
}
