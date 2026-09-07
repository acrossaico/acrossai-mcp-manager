<?php
/**
 * Settings renderer — small HTML helper shared by Settings tab/page methods.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers for the four edit-page tabs.
 *
 * Constitution: singleton + private __construct + zero add_action/add_filter.
 */
class SettingsRenderer {

	/** @var SettingsRenderer|null */
	protected static $_instance = null;

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * Render the standard tab navigation row at the top of the edit page.
	 *
	 * @param array<string, string> $tabs    [slug => label].
	 * @param string                $current Currently active tab slug.
	 * @param int                   $server_id Row ID; appended to the tab href.
	 */
	public function render_tab_nav( array $tabs, string $current, int $server_id ): void {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url    = esc_url(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'edit',
						'server' => $server_id,
						'tab'    => $slug,
					),
					admin_url( 'admin.php' )
				)
			);
			$active = ( $slug === $current ) ? ' nav-tab-active' : '';
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( $url ), // SEC-S2: defense in depth — esc_url is idempotent.
				esc_attr( $active ),
				esc_html( $label )
			);
		}
		echo '</h2>';
	}
}
