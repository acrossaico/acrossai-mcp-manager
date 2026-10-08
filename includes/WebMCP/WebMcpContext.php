<?php
/**
 * F093 — run a non-transport request as if it were an MCP request.
 *
 * WHY THIS EXISTS
 * ---------------
 * Four independent gates protect a tool call, and every one of them resolves
 * the current server from something only the vendor MCP transport provides:
 *
 *   | Gate                                        | Resolves the server from |
 *   | ------------------------------------------- | ------------------------ |
 *   | `AbilityHelpers::apply_exposure_filter()`   | `CurrentServerHolder`    |
 *   | `…\AcrossAI_MCP_Access_Control::gate_mcp_tools_list()` | the `$server` argument |
 *   | `…\AcrossAI_MCP_Access_Control::gate_mcp_tool_call()`  | the `$server` argument |
 *   | `…\MCP\ToolExposureGate::gate_tool_call_by_curation()` | the `$server` argument |
 *
 * A WebMCP request arrives over plain REST carrying a cookie. None of that
 * context exists, so all four quietly do nothing — and the first of them is
 * documented to fail *open*, falling back to `meta.mcp.public`. A caller that
 * forgets to establish context therefore does not merely lose the per-server
 * rules; it silently widens to every ability flagged public.
 *
 * The alternative to this class is re-deriving four gates at four priorities
 * and keeping them in step with the remote path forever. The first time they
 * drift the drift is a security bug, so they are not re-derived. Instead this
 * class reproduces the only two things the transport actually does:
 *
 *   1. It sets `CurrentServerHolder` to a real `McpServer`.
 *   2. It applies the vendor filters, passing that same object.
 *
 * Both are reproducible outside a transport request, so the already-wired
 * gates fire themselves, unchanged, in their registered priority order. The
 * browser path and the remote path become the same path, entered differently.
 *
 * FAIL CLOSED
 * -----------
 * `with()` refuses rather than proceeding whenever the context cannot be
 * fully established — unknown slug, no matching registered server, or a
 * holder that still reports no server id afterwards. That last check is not
 * redundant: it is the specific state in which every gate downstream would
 * fail open.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\WebMCP
 * @since      0.4.2
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\WebMCP;

use AcrossAI_MCP_Manager\Includes\Abilities\CurrentServerHolder;
use Throwable;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Core\McpServer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * All static. No hooks, no state of its own — the only state it touches is
 * `CurrentServerHolder`, and it always puts it back.
 *
 * @since 0.4.2
 */
final class WebMcpContext {

	/**
	 * Vendor filter fired when a server lists its tools.
	 *
	 * Pinned here rather than inlined so `WebMcpContextTest` can assert the
	 * name and argument count against the live wiring in `Includes\Main`.
	 * An adapter upgrade that renames this or changes its shape must fail a
	 * test, not silently un-gate the browser.
	 */
	public const FILTER_TOOLS_LIST = 'mcp_adapter_tools_list';

	/**
	 * Vendor filter fired before a tool call is dispatched.
	 *
	 * Carries three independent enforcement layers at three priorities:
	 * access control (10), ability exposure (20), tool curation (30).
	 */
	public const FILTER_PRE_TOOL_CALL = 'mcp_adapter_pre_tool_call';

	/**
	 * Argument counts the replays must supply, keyed by filter name.
	 *
	 * Passing too few arguments silently starves a gate of the `$server` it
	 * needs, which is indistinguishable from the gate being absent — the
	 * exact failure this class exists to prevent.
	 *
	 * @var array<string, int>
	 */
	public const FILTER_ARITY = array(
		self::FILTER_TOOLS_LIST    => 3,
		self::FILTER_PRE_TOOL_CALL => 4,
	);

	/**
	 * Run `$callback` with the named server established as the current MCP
	 * server, then restore whatever was there before.
	 *
	 * The holder is restored rather than merely cleared because this may be
	 * called from inside a real MCP request in tests, and clobbering a live
	 * transport context would be worse than the bug being fixed.
	 *
	 * @since 0.4.2
	 *
	 * @param string   $server_slug The `server_slug` of the selected server.
	 * @param callable $callback    Receives the resolved McpServer. Its return
	 *                              value is returned unchanged.
	 * @return mixed|WP_Error The callback's return value, or WP_Error when the
	 *                        context could not be established.
	 */
	public static function with( string $server_slug, callable $callback ) {
		$server = self::resolve( $server_slug );
		if ( $server instanceof WP_Error ) {
			return $server;
		}

		$holder   = CurrentServerHolder::instance();
		$previous = $holder->get();

		$holder->set( $server );

		try {
			// Establishing the holder is not the same as the holder being
			// usable: get_server_id() maps slug -> DB primary key and returns
			// null when that lookup fails. Null is precisely the value every
			// downstream gate treats as "no MCP context", so proceeding past
			// it is what fails open. Checked inside the try so the finally
			// still restores.
			if ( null === $holder->get_server_id() ) {
				return new WP_Error(
					'acrossai_mcp_webmcp_no_server_context',
					__( 'The selected WebMCP server could not be resolved to a database row.', 'acrossai-mcp-manager' ),
					array( 'status' => 403 )
				);
			}

			return $callback( $server );
		} catch ( Throwable $e ) {
			// Never let an exception leave the holder set. A long-lived PHP
			// process (Roadrunner, FrankenPHP, wp-env keep-alive) would carry
			// the stale server into the next request, where it would be the
			// wrong server rather than no server.
			return new WP_Error(
				'acrossai_mcp_webmcp_context_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		} finally {
			$holder->set( $previous );
		}
	}

	/**
	 * Find the registered `McpServer` whose slug matches.
	 *
	 * `McpServer::get_server_id()` returns the SLUG, not an integer — the same
	 * vendor naming that `CurrentServerHolder::get_server_id()` has to work
	 * around. Matching here is therefore slug-to-slug.
	 *
	 * Only *registered* servers can match. A row that exists in the database
	 * but was never registered with the adapter — disabled, or suppressed by
	 * `mcp_adapter_create_default_server` — correctly yields no match, so a
	 * disabled server cannot be reached through the browser.
	 *
	 * @since 0.4.2
	 *
	 * @param string $server_slug Slug to resolve.
	 * @return McpServer|WP_Error
	 */
	public static function resolve( string $server_slug ) {
		$server_slug = trim( $server_slug );

		if ( '' === $server_slug ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_no_server_selected',
				__( 'No WebMCP server has been selected.', 'acrossai-mcp-manager' ),
				array( 'status' => 403 )
			);
		}

		if ( ! class_exists( McpAdapter::class ) ) {
			return new WP_Error(
				'acrossai_mcp_webmcp_adapter_missing',
				__( 'The MCP adapter is not available.', 'acrossai-mcp-manager' ),
				array( 'status' => 500 )
			);
		}

		// No is_object()/method_exists() guard: get_servers() is typed to
		// return McpServer[], and CurrentServerHolder::capture_from_request()
		// walks the same collection without guarding. Defending here would
		// only be defending against the vendor's own type being wrong, which
		// the contract test in tests/phpunit/MCP/WebMcpContextTest.php covers
		// more usefully than a silent `continue` would.
		foreach ( McpAdapter::instance()->get_servers() as $server ) {
			if ( $server_slug === (string) $server->get_server_id() ) {
				return $server;
			}
		}

		return new WP_Error(
			'acrossai_mcp_webmcp_server_not_registered',
			__( 'The selected WebMCP server is not registered or is disabled.', 'acrossai-mcp-manager' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Replay the vendor tools-list filter so the access-control list gate
	 * fires, hiding the list from a user the server's rule excludes.
	 *
	 * Hiding the list matters independently of blocking the call. The audit
	 * that produced `gate_mcp_tools_list()` found a user outside a server's
	 * rule being correctly refused execution while still being served every
	 * tool name and description. In a browser that list is handed straight to
	 * an AI, so the disclosure is worse here than it was there.
	 *
	 * @since 0.4.2
	 *
	 * @param array<int|string, mixed> $tools  Composed tool list.
	 * @param McpServer                $server The established server.
	 * @return array<int|string, mixed> Possibly emptied by the gate.
	 */
	public static function filter_tools_list( array $tools, McpServer $server ): array {
		/** This filter is documented in the vendor mcp-adapter package. */
		$filtered = apply_filters( self::FILTER_TOOLS_LIST, $tools, $server, null );

		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * Replay the vendor pre-tool-call filter, which carries all three
	 * call-time gates: access control (10), ability exposure (20), tool
	 * curation (30).
	 *
	 * Returns the (possibly rewritten) arguments on allow, or the gate's own
	 * WP_Error on deny. Callers MUST treat a WP_Error as a refusal and stop —
	 * the gates express denial only through the return value.
	 *
	 * @since 0.4.2
	 *
	 * @param array<string, mixed> $args      Tool call arguments.
	 * @param string               $tool_name The tool being called.
	 * @param mixed                $mcp_tool  The tool object, or null.
	 * @param McpServer            $server    The established server.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function filter_pre_tool_call( array $args, string $tool_name, $mcp_tool, McpServer $server ) {
		/** This filter is documented in the vendor mcp-adapter package. */
		return apply_filters( self::FILTER_PRE_TOOL_CALL, $args, $tool_name, $mcp_tool, $server );
	}
}
