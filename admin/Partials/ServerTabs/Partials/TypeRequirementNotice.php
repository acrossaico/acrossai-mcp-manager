<?php
/**
 * The "enabled, but not ready" notice, shared by the tabs that need it.
 *
 * Extracted at the second use (Constitution §VI). The Overview tab had the
 * full version — headline, explanation, and the two things an operator can
 * actually do about it — while the Tools tab rendered a bare sentence of its
 * own from JavaScript. Same condition, same server, two different answers, and
 * the weaker one appeared on the tab where the operator is already looking at
 * the tools that are not being served.
 *
 * Only shown once the server is ENABLED. A disabled server serves nobody, so an
 * unmet requirement is not a problem yet — and saying so beside a banner that
 * already reads "Server is disabled" is two warnings about one non-event.
 *
 * Stateless renderer, singleton to match its neighbour
 * {@see AbilitiesManagerPromoCard}.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Admin\Partials\ServerTabs\Partials
 * @since      0.3.6
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Admin\Partials\ServerTabs\Partials;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\ServerTypes;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Renders the unmet-type-requirement notice for one server.
 *
 * @since 0.3.6
 */
final class TypeRequirementNotice {

	/**
	 * Main-menu Add-ons page slug — matches `\AcrossAI_Main_Menu\SettingsPage::ADDONS_SLUG`.
	 */
	private const ADDONS_PAGE_SLUG = 'acrossai-addons';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Singleton accessor.
	 *
	 * @since  0.3.6
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Render the notice, or nothing when there is nothing to say.
	 *
	 * @since  0.3.6
	 * @param  array<string, mixed> $server      Server row data.
	 * @param  string               $current_tab Tab doing the rendering, so the
	 *                                           notice does not offer a link to
	 *                                           the page it is already on.
	 * @return void
	 */
	public function render( array $server, string $current_tab = '' ): void {
		$server_type = (string) ( $server['server_type'] ?? '' );

		if ( '' === $server_type || empty( $server['is_enabled'] ) ) {
			return;
		}

		// The SOFT notice, not the hard refusal. Since 0.3.6 a server of this
		// type CAN be enabled without its plugin — it simply is not Ready.
		// `ServerTypes::enablement_error()` now covers only an unrecognised
		// slug, which is a different message on a different path.
		$error = ServerTypes::requirement_notice( $server_type );

		if ( null === $error ) {
			return;
		}

		$actions = sprintf(
			'<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( add_query_arg( array( 'page' => self::ADDONS_PAGE_SLUG ), admin_url( 'admin.php' ) ) ),
			esc_html__( 'Install the add-on →', 'acrossai-mcp-manager' )
		);

		// The second remedy is a link to the Tools tab, where the Server type
		// control lives. Omitted when that IS the current tab: a button that
		// reloads the page the operator is on, to reach a dropdown already on
		// screen, reads as broken rather than helpful.
		if ( 'tools' !== $current_tab ) {
			$actions .= sprintf(
				' <a class="button" href="%1$s">%2$s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'   => 'acrossai_mcp_manager',
							'action' => 'edit',
							'server' => (int) ( $server['id'] ?? 0 ),
							'tab'    => 'tools',
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Change the server type', 'acrossai-mcp-manager' )
			);
		}

		printf(
			'<div class="notice notice-warning inline"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			// Was "This server cannot be enabled yet.", which contradicted the
			// sentence after it — the server IS enabled, and since 0.3.6 that
			// is allowed. It is not READY, which is a different thing and the
			// one worth naming.
			esc_html__( 'Not ready yet.', 'acrossai-mcp-manager' ),
			esc_html( $error->get_error_message() ),
			// Built from escaped parts immediately above.
			$actions // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}
