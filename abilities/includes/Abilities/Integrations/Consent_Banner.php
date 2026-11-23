<?php
/**
 * The cookie consent toolset declaration.
 *
 * The "we supply everything" shape. The consent plugin registers no abilities of its own — verified
 * across the whole plugin — so every ability in this group is ours and `ability_prefixes()` is empty.
 * Claiming `consent` would capture any future ability in that namespace and file it here without
 * anyone deciding to.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's cookie consent abilities.
 */
final class Consent_Banner implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Consent_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'cookieyes';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'CookieYes', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Cookie consent banner: the cookies the site declares, the consent categories a visitor chooses between, the banner itself, its languages, and Google Consent Mode. One thing to know before writing. The banner a visitor receives is cached HTML built from the cookies, the categories and the settings, and it is refreshed only when the consent plugin own save hooks fire. Writing the consent tables through the Database tools leaves that cache holding the previous version, so the row reads back correctly and every visitor still sees the old banner with no error anywhere; every write here goes through the plugin own writers and then confirms the banner actually moved. Run consent/get-banner-status when a change appears not to take effect, and consent/rebuild-banner to repair it. Reporting - consent statistics, pageview figures and cookie scan status - is produced by the consent service and needs the site linked to a consent account; those abilities say so by name and point at the screen to do it on. Nothing here links the account, and nothing here makes a site compliant or decides which privacy law applies. Only present when the consent plugin is active. Requires administrator rights. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * Claims no prefixes — every ability in this group is declared by us.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Consent\Consent_Guard::is_available();
	}
}
