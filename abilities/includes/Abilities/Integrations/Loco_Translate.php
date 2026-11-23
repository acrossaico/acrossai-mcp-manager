<?php
/**
 * Loco Translate's toolset declaration.
 *
 * The "we supply everything" shape. Loco registers no abilities of its own — verified across the
 * whole plugin — so every ability in this group is ours and `ability_prefixes()` is empty. Claiming
 * `translations` would capture any future ability in that namespace and file it here without anyone
 * deciding to.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups this plugin's Loco Translate abilities.
 */
final class Loco_Translate implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Loco_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'loco-translate';

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
		return __( 'Loco Translate', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Translations via Loco Translate: discover which plugins, themes and core text domains can be translated, read their strings and see what is untranslated, write translations, create and delete translation files, rebuild templates from source, and install official language packs from WordPress.org. One thing to know before writing. A PO file is not what WordPress reads: one save produces up to four files — the PO, the MO, the .l10n.php cache that WordPress 6.5 and later reads in preference to the MO, and a JSON fragment per JavaScript file. Editing a PO through the Files tools leaves the rest stale, so the site keeps rendering the old strings with no error anywhere; every write here recompiles all of them and reports the bytes written to each, read back from disk. Run translations/get-translation-status first when a translation appears not to take effect. Only present when Loco Translate is active. Requires administrator rights. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
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
		return class_exists( 'Loco_package_Bundle' ) && class_exists( 'Loco_gettext_Compiler' );
	}
}
