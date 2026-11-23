<?php
/**
 * The email delivery toolset declaration.
 *
 * The mail plugin registers its own abilities under `wp-mail-smtp`, so the prefix is CLAIMED and they
 * are adopted into this tab rather than re-registered. Bare, with no trailing slash: the tagger
 * matches the segment before the first slash (#209).
 *
 * The mixed shape: four abilities are ours, and the mail plugin's own are ADOPTED. It registers
 * under `wp-mail-smtp` (`src/Abilities/AbilityRegistrar.php:164`) — one in the free edition, more in
 * the paid one — so the prefix is claimed and they arrive filed here without another release. Bare,
 * with no trailing slash: the tagger matches the segment before the first slash (#209).
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the Email Delivery abilities.
 */
final class Email_Delivery implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Email_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'wp-mail-smtp';

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
		return __( 'WP Mail SMTP', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Email delivery: how this site sends mail, whether it actually can, and a real test send that proves it. Start with email/get-delivery-status when notifications, password resets or order emails are not arriving - it reports the mailer in use, whether its credentials are stored, whether another plugin is also taking over email, and the last recorded failure. Configuration alone never proves delivery, so follow it with email/send-test-email, which sends a real message and returns the mail plugin own error text when it fails. Passwords and API keys are never returned by any ability here, and none can be written; the mailer itself cannot be switched, because changing it without the matching credentials stops all email on the site. Requires administrator rights. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
			'acrossai-abilities-manager'
		);
	}

	/**
	 * The prefix this group adopts.
	 *
	 * @since  0.0.34
	 * @return string[]
	 */
	public function ability_prefixes(): array {
		return array( 'wp-mail-smtp' );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return \AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Email\Email_Guard::is_available();
	}
}
