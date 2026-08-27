<?php
/**
 * MCP Server list table (WP_List_Table).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Admin\Partials
 */

namespace AcrossAI_MCP_Manager\Admin\Partials;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the server list page (`?page=acrossai_mcp_manager`) per FR-004 / FR-006.
 *
 * NOTE: List-table subclasses are excepted from the singleton-only rule
 * because (a) they extend \WP_List_Table which requires its own public
 * constructor + parent::__construct() call, (b) they are instantiated
 * per-render inside Settings, never wired into hooks via the Loader
 * (so the B5 double-hook risk does not apply).
 */
class MCPServerListTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'mcp_server',
				'plural'   => 'mcp_servers',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns: Name, Status, Registered From, Route, Actions.
	 * Plus a `cb` checkbox column to enable bulk actions.
	 *
	 * Slug column removed — the URL/route columns already carry the same
	 * identity signal and the slug is exposed inline on the Name column edit
	 * link. `route_namespace` + `route` merged into a single `Route` column
	 * displayed as `<namespace>/<route>`. `version` column removed —
	 * uncommonly referenced from the list view; still surfaced on the edit
	 * page. Actions column bundles Edit + Enable/Disable toggle + quick-
	 * access links into the per-server-edit tabs (Connectors, Access Control,
	 * Abilities, MCP Clients).
	 */
	public function get_columns(): array {
		return array(
			'cb'      => '<input type="checkbox" />',
			'name'    => esc_html__( 'Name', 'acrossai-mcp-manager' ),
			'status'  => esc_html__( 'Status', 'acrossai-mcp-manager' ),
			'source'  => esc_html__( 'Registered From', 'acrossai-mcp-manager' ),
			'route'   => esc_html__( 'Route', 'acrossai-mcp-manager' ),
			'actions' => esc_html__( 'Actions', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Bulk actions per FR-006. Each handler in Settings::handle_actions
	 * verifies the WP-Lists nonce action `bulk-mcp_servers`.
	 */
	public function get_bulk_actions(): array {
		return array(
			'enable'  => esc_html__( 'Enable', 'acrossai-mcp-manager' ),
			'disable' => esc_html__( 'Disable', 'acrossai-mcp-manager' ),
			'delete'  => esc_html__( 'Delete', 'acrossai-mcp-manager' ),
		);
	}

	/**
	 * Pull rows via the BerlinDB-style Query class. FR-005 + FR-022.
	 */
	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$rows = Query::instance()->query(
			array(
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);

		$this->items = array_map(
			static function ( $row ) {
				return array(
					'id'                     => $row->id,
					'name'                   => $row->server_name,
					'slug'                   => $row->server_slug,
					'description'            => $row->description,
					'enabled'                => ! empty( $row->is_enabled ),
					'registered_from'        => $row->registered_from,
					'server_route_namespace' => $row->server_route_namespace,
					'server_route'           => $row->server_route,
					'server_version'         => $row->server_version,
				);
			},
			$rows
		);
	}

	/**
	 * Row checkbox for bulk actions.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="server_ids[]" value="%d" />',
			(int) $item['id']
		);
	}

	/**
	 * Fallback column renderer for the merged `route` column
	 * (`<namespace>/<route>`, with duplicate slashes at the join collapsed).
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'route':
				$namespace = trim( (string) $item['server_route_namespace'], '/' );
				$route     = ltrim( (string) $item['server_route'], '/' );
				$combined  = '' === $namespace ? $route : $namespace . '/' . $route;
				return esc_html( $combined );
			default:
				return '';
		}
	}

	/**
	 * Name column with row actions (Edit + conditional Delete).
	 * Source-repo behavior preserved: Delete row action only appears for
	 * 'database'-source rows (the seeded default-plugin row is not deletable
	 * from the UI).
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_name( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'edit',
				'server' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$row_actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'acrossai-mcp-manager' )
			),
		);

		if ( 'database' === $item['registered_from'] ) {
			$delete_url            = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => AdminPageSlugs::PARENT,
						'action' => 'delete',
						'server' => (int) $item['id'],
					),
					admin_url( 'admin.php' )
				),
				'acrossai_mcp_delete_' . (int) $item['id']
			);
			$row_actions['delete'] = sprintf(
				'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\')">%s</a>',
				esc_url( $delete_url ),
				esc_js( __( 'Are you sure you want to delete this server? This cannot be undone.', 'acrossai-mcp-manager' ) ),
				esc_html__( 'Delete', 'acrossai-mcp-manager' )
			);
		}

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s',
			esc_url( $edit_url ),
			esc_html( $item['name'] ),
			$this->row_actions( $row_actions )
		);
	}

	/**
	 * Source badge: plugin / database / theme / core.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_source( $item ): string {
		$source = $item['registered_from'];

		$labels = array(
			'plugin'   => __( 'Plugin', 'acrossai-mcp-manager' ),
			'database' => __( 'Database', 'acrossai-mcp-manager' ),
			'theme'    => __( 'Theme', 'acrossai-mcp-manager' ),
			'core'     => __( 'Core', 'acrossai-mcp-manager' ),
		);

		$label = $labels[ $source ] ?? $source;
		$class = 'acrossai-source-badge acrossai-source-' . sanitize_html_class( $source );

		return sprintf( '<span class="%s">%s</span>', esc_attr( $class ), esc_html( $label ) );
	}

	/**
	 * Status badge (Active / Inactive).
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_status( $item ): string {
		if ( $item['enabled'] ) {
			return '<span class="acrossai-status-badge acrossai-status-active">'
				. esc_html__( 'Active', 'acrossai-mcp-manager' )
				. '</span>';
		}
		return '<span class="acrossai-status-badge acrossai-status-inactive">'
			. esc_html__( 'Inactive', 'acrossai-mcp-manager' )
			. '</span>';
	}

	/**
	 * Actions column: Edit + Enable/Disable toggle + quick-access links that
	 * jump directly to the corresponding tab on the server-edit page. The
	 * toggle carries a per-row nonce; Edit and quick-links are plain nav.
	 *
	 * @param array<string, mixed> $item Row data.
	 */
	public function column_actions( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'   => AdminPageSlugs::PARENT,
				'action' => 'edit',
				'server' => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$edit_html = sprintf(
			'<a href="%s" class="button button-small button-primary acrossai-btn-edit">%s</a>',
			esc_url( $edit_url ),
			esc_html__( 'Edit', 'acrossai-mcp-manager' )
		);

		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'toggle_status',
					'server' => (int) $item['id'],
				),
				admin_url( 'admin.php' )
			),
			'acrossai_mcp_toggle_' . (int) $item['id']
		);

		if ( $item['enabled'] ) {
			$toggle_html = sprintf(
				'<a href="%s" class="button button-small acrossai-btn-disable">%s</a>',
				esc_url( $toggle_url ),
				esc_html__( 'Disable', 'acrossai-mcp-manager' )
			);
		} else {
			$toggle_html = sprintf(
				'<a href="%s" class="button button-small acrossai-btn-enable">%s</a>',
				esc_url( $toggle_url ),
				esc_html__( 'Enable', 'acrossai-mcp-manager' )
			);
		}

		$quick_links = array(
			'ai-connectors'  => array(
				'label' => __( 'Connectors', 'acrossai-mcp-manager' ),
				'icon'  => 'admin-plugins',
			),
			'access-control' => array(
				'label' => __( 'Access Control', 'acrossai-mcp-manager' ),
				'icon'  => 'shield',
			),
			'abilities'      => array(
				'label' => __( 'Abilities', 'acrossai-mcp-manager' ),
				'icon'  => 'superhero-alt',
			),
			'clients'        => array(
				'label' => __( 'MCP Clients', 'acrossai-mcp-manager' ),
				'icon'  => 'admin-users',
			),
		);

		$links_html = '';
		foreach ( $quick_links as $tab_slug => $meta ) {
			$tab_url     = add_query_arg(
				array(
					'page'   => AdminPageSlugs::PARENT,
					'action' => 'edit',
					'server' => (int) $item['id'],
					'tab'    => $tab_slug,
				),
				admin_url( 'admin.php' )
			);
			$links_html .= sprintf(
				'<a href="%s" class="acrossai-quicklink"><span class="dashicons dashicons-%s" aria-hidden="true"></span><span class="acrossai-quicklink-label">%s</span></a>',
				esc_url( $tab_url ),
				esc_attr( $meta['icon'] ),
				esc_html( $meta['label'] )
			);
		}

		// F072 FR-005 — per-row Quick Connect via AcrossAI pill, emitted outside the loop
		// because Quick Connect via AcrossAI is not a tab (no ?tab= param). Server id is
		// deep-linked so Step 1 opens with this row preselected.
		$quick_connect_url = add_query_arg(
			array(
				'page'        => AdminPageSlugs::PARENT,
				'quick-connect' => '1',
				'step'        => '1',
				'server'      => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);
		$links_html     .= sprintf(
			'<a href="%s" class="acrossai-quicklink"><span class="dashicons dashicons-admin-tools" aria-hidden="true"></span><span class="acrossai-quicklink-label">%s</span></a>',
			esc_url( $quick_connect_url ),
			esc_html__( 'Quick Connect via AcrossAI', 'acrossai-mcp-manager' )
		);

		return sprintf(
			'<div class="acrossai-actions-cell"><div class="acrossai-actions-primary">%s%s</div><div class="acrossai-actions-quicklinks">%s</div></div>',
			$edit_html,
			$toggle_html,
			$links_html
		);
	}
}
