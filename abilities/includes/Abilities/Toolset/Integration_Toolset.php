<?php
/**
 * A Toolset dispatcher built from an integration's declaration.
 *
 * The thirteen sibling classes each hardcode four answers, which is right for the curated first-party
 * groups: they are few and they rarely change. Integrations are neither. Every one of them would carry
 * the same four declarations it has already made through
 * {@see \AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integration}, so
 * requiring a file here as well would mean two places to edit and one to forget — and forgetting this
 * one is silent: the group still gets a tab, a count and a REST filter, and is simply unreachable over
 * MCP.
 *
 * So this subclass takes the declaration and answers from it. Adding an integration adds no file here.
 *
 * The base constructor only registers hooks — it calls none of the four abstract methods — so setting
 * the descriptor before `parent::__construct()` is safe.
 *
 * @package    AcrossAI_Abilities_Manager
 * @subpackage AcrossAI_Abilities_Manager/includes/Abilities/Toolset
 * @since      0.0.34
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Toolset;

use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integration;
use AcrossAI_Abilities_Manager\Includes\Abilities\Integrations\AcrossAI_Toolset_Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Dispatcher for a group declared by an integration.
 */
final class Integration_Toolset extends Base_Toolset_Ability {

	/**
	 * The declaration this dispatcher serves.
	 *
	 * @since 0.0.34
	 * @var   AcrossAI_Toolset_Integration
	 */
	private $integration;

	/**
	 * @since 0.0.34
	 * @param AcrossAI_Toolset_Integration $integration Declaration to serve.
	 */
	public function __construct( AcrossAI_Toolset_Integration $integration ) {
		$this->integration = $integration;

		parent::__construct();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function group(): string {
		return $this->integration->group();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'toolset/' . $this->integration->group();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function toolset_label(): string {
		return $this->integration->toolset_label();
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function toolset_description(): string {
		return $this->integration->toolset_description();
	}

	/**
	 * Integrations are not part of the server type's default set.
	 *
	 * Every dispatcher here exists because a PLUGIN is installed, so including
	 * them would make the default set differ per site and change whenever a
	 * plugin is activated or this add-on ships another integration. A connected
	 * MCP client caches `tools/list` and cannot be told it changed, so a moving
	 * default set is one that connected clients are quietly wrong about.
	 *
	 * They remain fully registered tools — still in the picker, still addable by
	 * hand, still callable. `toolset/integrations` also lists and runs every one
	 * of them, so a client whose cached list predates the plugin can still reach
	 * it.
	 *
	 * **The catch-all is the exception.** `other` is an integration in the
	 * mechanical sense — it is declared through the same registry
	 * ({@see AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP}) — but it is present
	 * on every site and holds abilities belonging to no group. It is stable, so
	 * it stays a default. A blanket `false` here would drop it silently.
	 *
	 * @since  0.0.34
	 * @return bool
	 */
	protected function is_server_type_default(): bool {
		if ( AcrossAI_Toolset_Integrations::CATCH_ALL_GROUP === $this->group() ) {
			return parent::is_server_type_default();
		}

		return false;
	}

	/**
	 * Every dispatcher built from a declaration follows a plugin.
	 *
	 * True for the catch-all too, and most of all: what falls through to `other`
	 * is decided by which prefixes the installed plugins claim, so it can gain
	 * and lose abilities without anything being installed that is obviously
	 * "its" plugin.
	 *
	 * @since  0.0.38
	 * @return bool
	 */
	protected function is_volatile(): bool {
		return true;
	}
}
