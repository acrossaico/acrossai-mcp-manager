<?php
/**
 * Contact Form 7's toolset declaration.
 *
 * The "we supply everything" shape: Contact Form 7 registers no abilities of its own — verified
 * across all 111 PHP files of v6.1.7 — so every ability in this group is one of ours, declared under
 * `includes/Abilities/ContactForm7/` and already carrying `meta.acrossai.tab_group`. Nothing needs
 * tagging, which is why `ability_prefixes()` is empty: claiming the `contact-form-7` prefix would
 * capture any ability CF7 might add later and file it under our label without anyone deciding to.
 *
 * This declaration still owns the group key, the display name and the MCP description, and it is what
 * generates the dispatcher — so there is no `includes/Abilities/Toolset/` file for this suite.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Contact Form 7 abilities.
 */
final class Contact_Form_7 implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key.
	 *
	 * Must equal `Base_Contact_Form_7_Ability::TAB_GROUP`, which is what all 25 abilities declare. If
	 * the two drift, the abilities land in one group and the dispatcher serves another.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'contact-form-7';

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function group(): string {
		return self::TAB_GROUP;
	}

	/**
	 * Display name.
	 *
	 * Declared rather than derived: the label rule would turn `contact-form-7` into
	 * "Contact Form 7" correctly by luck here, but the trailing digit is exactly the kind of thing
	 * `ucwords()` gets wrong in other locales, and the name is a product name either way.
	 *
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_label(): string {
		return __( 'Contact Form 7', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Contact Form 7: list and read forms, create and duplicate them, edit the fields in a form template, edit both mail templates and check their tags resolve, change validation messages and per-form behaviour settings, and run Contact Form 7\'s own configuration validator. Forms are only reachable by visitors once their shortcode is placed on a page. Every ability requires administrator rights, and deleting a form is irreversible — Contact Form 7 bypasses the trash. Narrow action=discover with sub_group: cf7-forms (the form lifecycle), cf7-fields (the template and its fields), cf7-mail (notifications and mail tags), cf7-messages (validation text) and cf7-settings (behaviour switches and validation). action=execute runs one ability.',
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
	 * Whether Contact Form 7 is present.
	 *
	 * `WPCF7_ContactForm` rather than the `WPCF7_VERSION` constant: it is the class every ability
	 * reaches through, so its presence also proves CF7's autoloading is live.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}
}
