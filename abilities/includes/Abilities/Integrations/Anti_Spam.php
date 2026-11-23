<?php
/**
 * The anti-spam toolset declaration.
 *
 * Adopt-only: every ability in this group is the anti-spam plugin's own, claimed by prefix and never
 * re-registered. Bare prefix, per #209.
 *
 * Adopt-only: every ability in this group belongs to the anti-spam plugin, claimed by prefix and
 * never re-registered. Registering a name it already owns would meet the Abilities API duplicate
 * refusal, and which side survives would depend only on load order. Bare prefix, per #209.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Integrations
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the Anti-Spam abilities.
 */
final class Anti_Spam implements AcrossAI_Toolset_Integration {

	/**
	 * Toolset key. Must equal Base_Email_Ability::TAB_GROUP.
	 *
	 * @since 0.0.34
	 * @var   string
	 */
	public const TAB_GROUP = 'akismet';

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
		return __( 'Akismet', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	public function toolset_description(): string {
		return __(
			'Spam protection for comments and form submissions. These abilities come from the anti-spam plugin itself, not from this plugin: read the spam figures it has recorded, and check a specific comment against its service. Nothing here manages the account or its key. Requires administrator rights. action=discover lists this group; action=info returns schemas; action=execute runs one ability.',
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
		return array( 'akismet' );
	}

	/**
	 * @since  0.0.34
	 * @return bool
	 */
	public function is_active(): bool {
		return class_exists( 'Akismet' ) && function_exists( 'akismet_init' );
	}
}
