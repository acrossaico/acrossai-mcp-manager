<?php
/**
 * UpdraftPlus's toolset declaration.
 *
 * UpdraftPlus ships no abilities of its own today, so unlike {@see Rank_Math} and {@see Elementor}
 * this file fixes nothing visible right now — every `updraftplus/*` ability on a site is ours and
 * already declares its group inline.
 *
 * It exists so that the day UpdraftPlus adds Abilities API support, its abilities are grouped on
 * arrival instead of falling through to `other`. That is exactly how issue #184 (Rank Math) and
 * issue #255 (Elementor) happened: the integration pre-dated the host plugin's abilities, and
 * nobody revisited the claim when they shipped. Claiming the prefix ahead of time is two lines and
 * removes the need to notice.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.42
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\UpdraftPlus\UpdraftPlus_Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Groups UpdraftPlus's abilities alongside this plugin's.
 */
final class UpdraftPlus implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Matches what this plugin's UpdraftPlus abilities declare inline and
	 * `Toolset\UpdraftPlus::group()`. All three must agree or the halves split apart.
	 *
	 * @since 0.0.42
	 * @var   string
	 */
	public const TAB_GROUP = 'updraftplus';

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
	 * Currently unused: `Toolset\UpdraftPlus` is a hand-written dispatcher and supplies the live
	 * label and description — `updraftplus` is listed in the bootstrap's `$claimed` array. Declared
	 * anyway so that retiring that class is a deletion rather than a rewrite.
	 *
	 * @since  0.0.42
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'UpdraftPlus', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.42
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Whether this site can be recovered through UpdraftPlus: when a backup last ran and whether it worked, what sets exist and what each contains, whether the archives are reachable over HTTP, and taking, deleting or restoring one. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Ability names in the UpdraftPlus namespace.
	 *
	 * `updraftplus` is both the plugin's slug and the namespace our own abilities use, so one claim
	 * covers both halves. No generic namespace is claimed — see the note in
	 * `AcrossAI_Toolset_Integration::ability_prefixes()`.
	 *
	 * @since  0.0.42
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'updraftplus' );
	}

	/**
	 * Whether UpdraftPlus is present.
	 *
	 * Delegates to the guard every UpdraftPlus read in this plugin already routes through, so the
	 * toolset and the abilities can never disagree about whether the host plugin is there.
	 *
	 * @since  0.0.42
	 * @return bool
	 */
	public function is_active(): bool {
		return UpdraftPlus_Guard::is_available();
	}
}
