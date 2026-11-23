<?php
/**
 * Feature 106 — Update SEO Integration Settings.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Yoast
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Yoast;

defined( 'ABSPATH' ) || exit;

/**
 * seo/update-integration-settings — Update SEO Integration Settings.
 *
 * The stored OAuth tokens for these services are not in this area: Settings_Repository subtracts
 * Yoast's own DISALLOWED_SETTINGS, which names semrush_tokens and wincher_tokens. Turning an
 * integration on here does not connect it — the account is linked from the Yoast admin screens.
 */
final class Update_Integration_Settings extends Base_Settings_Write_Ability {

	protected function slug(): string {
		return 'seo/update-integration-settings';
	}

	protected function ability_label(): string {
		return __( 'Update SEO Integration Settings', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __(
			'Toggles for the third-party services Yoast can surface in the editor — Semrush keyphrase research and its country code, Wincher rank tracking and whether new keyphrases are tracked automatically, and Algolia search. These switch the integration on and off only; the OAuth connection itself is made from the Yoast admin screens and its tokens are never readable or writable through this suite.',
			'acrossai-abilities-manager'
		);
	}

	protected function area_written(): string {
		return 'integrations';
	}
}
