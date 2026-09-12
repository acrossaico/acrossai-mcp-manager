<?php
/**
 * Abstract base class for public MCP client-configuration renderers.
 *
 * Feature 013 introduces the public/Renderers/ layer so third-party plugins
 * (BuddyBoss, WooCommerce, other AcrossAI-family plugins) can embed the
 * client-configuration UIs (npm, MCP Clients) with zero code duplication.
 * Admin tab classes and third-party consumers all invoke
 * Block::render( $server_id, $context ).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 *
 * See DEC-CLIENT-RENDERER-PUBLIC-API. See docs/security-reviews/
 * 2026-07-03-013-per-server-tabs-refactor-plan.md SEC-013-001..008 for the
 * security invariants this base class + subclasses enforce.
 */

namespace AcrossAI_MCP_Manager\Public\Renderers;

use AcrossAI_MCP_Manager\Includes\Database\MCPServer\Query as MCPServerQuery;
use AcrossAI_MCP_Manager\Includes\Utilities\AdminPageSlugs;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Abstract base for the 3 client-configuration Block subclasses.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Public/Renderers
 * @since      0.0.6
 * @experimental May change without notice before 1.0.0.
 */
abstract class AbstractClientRenderer {

	/**
	 * Returns the block's slug (e.g. 'npm', 'clients').
	 *
	 * @since 0.0.6
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * Renders the block body. Subclasses implement.
	 *
	 * @since 0.0.6
	 * @param array $server  Server row data.
	 * @param array $context Resolved context array.
	 * @return void
	 */
	abstract protected function render_body( array $server, array $context ): void;

	/**
	 * Public entry point. Called by admin tab classes AND by external plugins
	 * (via shortcodes, do_action('acrossai_mcp_render_client_block',...), or
	 * direct static call).
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0. (SEC-013-004)
	 *
	 * @param int   $server_id MCP server ID.
	 * @param array $context   Optional context array. Keys:
	 *                         'context' (slug, default 'admin'),
	 *                         'cap' (default 'manage_options'),
	 *                         'submit_target_url', 'nonce_action',
	 *                         'user_id' (default get_current_user_id()),
	 *                         'copy_button' (default true),
	 *                         'sub_client' (MCPClients only).
	 * @return void
	 */
	final public function render( int $server_id, array $context = array() ): void {
		$context = $this->resolve_context( $server_id, $context );
		// SEC-013-005: cap check via context — never hardcoded manage_options.
		if ( ! current_user_can( $context['cap'] ) ) {
			return;
		}
		$rows = MCPServerQuery::instance()->query(
			array(
				'id'     => $server_id,
				'number' => 1,
			)
		);
		if ( empty( $rows ) ) {
			$this->render_missing_server_notice();
			return;
		}
		$this->render_body( $rows[0]->to_array(), $context );
	}

	/**
	 * Merges context defaults and applies the acrossai_mcp_client_block_context
	 * filter. Casts the filter return to (array) per SEC-013-003.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param int   $server_id MCP server ID.
	 * @param array $context   Caller-provided context.
	 * @return array
	 */
	protected function resolve_context( int $server_id, array $context ): array {
		$context_slug = isset( $context['context'] ) ? sanitize_key( (string) $context['context'] ) : 'admin';
		$defaults     = array(
			'context'           => $context_slug,
			'cap'               => 'manage_options',
			'submit_target_url' => admin_url( 'admin.php?page=' . AdminPageSlugs::PARENT ),
			'nonce_action'      => 'acrossai_mcp_render_' . $this->slug() . '_' . $server_id . '_' . $context_slug,
			'user_id'           => get_current_user_id(),
			'copy_button'       => true,
			'sub_client'        => '',
		);
		$context      = wp_parse_args( $context, $defaults );

		$filtered = apply_filters(
			'acrossai_mcp_client_block_context',
			$context,
			$this->slug(),
			$server_id
		);

		// SEC-013-003: a third-party callback returning a non-array MUST NOT
		// fatal, and the defaults MUST still apply. A bare `(array)` cast is
		// not enough for the second half — `(array) null` is `[]`, which drops
		// every default and leaves render() reading an undefined 'cap' key.
		// Re-merge so the contract in this method's docblock actually holds.
		return wp_parse_args( is_array( $filtered ) ? $filtered : array(), $defaults );
	}

	/**
	 * Renders the "Generate New Application Password" button. Disabled when
	 * $context['user_id'] does not equal get_current_user_id() (defense
	 * against admin-impersonation when embedded in a third-party context).
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0. (SEC-013-002)
	 *
	 * @param array $server  Server row data.
	 * @param array $context Resolved context array.
	 * @return void
	 */
	protected function passwords_generate_button( array $server, array $context ): void {
		$user_id_match = get_current_user_id() === (int) $context['user_id'];
		$attrs         = $user_id_match ? '' : ' disabled aria-disabled="true"';
		$endpoint      = rest_url( 'acrossai-mcp-manager/v1/generate-app-password' );
		$nonce         = wp_create_nonce( 'wp_rest' );
		// Sub-clients (e.g. MCP Clients Block's per-client tabs) split on
		// $context['sub_client']; fall back to the block's slug otherwise.
		$client_slug = '' !== (string) ( $context['sub_client'] ?? '' )
			? sanitize_key( (string) $context['sub_client'] )
			: sanitize_key( $this->slug() );
		printf(
			'<button type="button" class="button button-primary generate-app-password" data-server-id="%1$s" data-client-slug="%2$s" data-context="%3$s" data-endpoint="%4$s" data-nonce="%5$s"%6$s>%7$s</button>'
			. '<span class="acrossai-generate-app-password-status" aria-live="polite"></span>',
			esc_attr( (string) (int) $server['id'] ),
			esc_attr( $client_slug ),
			esc_attr( sanitize_key( (string) $context['context'] ) ),
			esc_url( $endpoint ),
			esc_attr( $nonce ),
			esc_attr( $attrs ),
			esc_html__( 'Generate New Application Password', 'acrossai-mcp-manager' )
		);
		if ( ! $user_id_match ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Application Passwords can only be generated for the current user.', 'acrossai-mcp-manager' )
			);
		}
	}

	/**
	 * Emits a JSON configuration <pre> block for a specific MCP client.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param array  $server      Server row data.
	 * @param string $client_slug Client slug.
	 * @param array  $config      Config array to JSON-encode.
	 * @return void
	 */
	protected function config_json_pre_block( array $server, string $client_slug, array $config ): void {
		$json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		printf(
			'<pre id="acrossai-mcp-%1$s-config-%2$s"><code>%3$s</code></pre>',
			esc_attr( sanitize_key( $client_slug ) ),
			esc_attr( (string) (int) $server['id'] ),
			esc_html( (string) $json )
		);
	}

	/**
	 * Renders the "Copy Configuration" JS button for a config block.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param string $target_selector CSS selector of the <pre> to copy.
	 * @return void
	 */
	protected function copy_config_button( string $target_selector ): void {
		printf(
			'<button type="button" class="button" data-copy-target="%1$s">%2$s</button>',
			esc_attr( $target_selector ),
			esc_html__( 'Copy Configuration', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Renders a "server not found" notice when the server ID is invalid.
	 *
	 * @since 0.0.6
	 * @return void
	 */
	protected function render_missing_server_notice(): void {
		printf(
			'<div class="notice notice-warning inline"><p>%s</p></div>',
			esc_html__( 'MCP server not found.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Renders the "feature currently disabled" notice with a link back to
	 * the MCP settings tab. Used by NpmClientBlock when its F012 toggle is
	 * off. (SEC-013-005)
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param string $feature_label    Feature label (e.g. 'npm / npx CLI').
	 * @param string $enable_link_text Link text (e.g. 'enable CLI Connections').
	 * @param string $explanation      Explanatory paragraph.
	 * @return void
	 */
	protected function render_feature_disabled_notice( string $feature_label, string $enable_link_text, string $explanation ): void {
		$settings_url = admin_url( 'admin.php?page=acrossai-settings&tab=mcp' );
		printf(
			'<div class="acrossai-mcp-disabled-notice"><p><strong>%1$s</strong></p><p>%2$s <a href="%3$s">%4$s</a>.</p><p>%5$s</p></div>',
			esc_html__( 'This feature is currently disabled.', 'acrossai-mcp-manager' ),
			esc_html( sprintf( /* translators: %s: feature name */ __( 'To use the %s feature, please', 'acrossai-mcp-manager' ), $feature_label ) ),
			esc_url( $settings_url ),
			esc_html( $enable_link_text ),
			esc_html( $explanation )
		);
	}

	/**
	 * Renders a section heading inside a block body.
	 *
	 * @since 0.0.6
	 * @param string $heading Heading text (already i18n'd).
	 * @return void
	 */
	protected function render_section_heading( string $heading ): void {
		printf( '<h2>%s</h2>', esc_html( $heading ) );
	}

	/**
	 * Shortcode entry point. Subclasses may override; default just renders
	 * with the shortcode's server attribute.
	 *
	 * @since 0.0.6
	 * @experimental May change without notice before 1.0.0.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ): string {
		$atts      = shortcode_atts(
			array(
				'server' => '0',
			),
			is_array( $atts ) ? $atts : array(),
			'acrossai_mcp_block'
		);
		$server_id = absint( $atts['server'] );
		if ( 0 === $server_id ) {
			return '';
		}
		ob_start();
		static::instance()->render( $server_id, array( 'context' => 'shortcode' ) );
		return (string) ob_get_clean();
	}

	/**
	 * Singleton instance accessor. Subclasses each maintain their own $instance.
	 *
	 * @since 0.0.6
	 * @return static
	 */
	abstract public static function instance();
}
