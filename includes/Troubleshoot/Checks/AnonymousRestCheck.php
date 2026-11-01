<?php
/**
 * Does the REST API answer an unauthenticated request? (Feature 101)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\PluginNamer;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Remedies;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Several security plugins ship a "restrict REST API to logged-in users"
 * option, and some enable it by default. It is a reasonable setting for a site
 * with no API consumers — and it breaks the first step of every AI connection,
 * because dynamic client registration happens before any credential exists and
 * is therefore necessarily anonymous.
 *
 * The operator sees "couldn't register" in the client and a perfectly healthy
 * site in the admin.
 */
final class AnonymousRestCheck implements Check {

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'rest';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Anonymous REST API access', 'acrossai-mcp-manager' );
	}

	/**
	 * @return array<int, string>
	 */
	public function requires(): array {
		return array();
	}

	/**
	 * Run the check.
	 *
	 * @param Context $context Shared probe cache and prior results.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$url   = rest_url();
		$probe = $context->fetch( $url );

		if ( '' !== $probe['error'] ) {
			return Result::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: HTTP error message */
					__( 'This site could not fetch its own REST index: %s', 'acrossai-mcp-manager' ),
					$probe['error']
				),
				__( 'Ask the host whether the server can make HTTP requests to its own public URL. Until it can, none of the probes on this page mean anything.', 'acrossai-mcp-manager' )
			);
		}

		if ( 401 === $probe['code'] || 403 === $probe['code'] ) {
			$culprits = self::plugins_filtering_rest_access();
			$reason   = self::reason_from_body( $probe['body'] );

			return Result::fail(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: 1: HTTP status code, 2: what is doing it and why, as a sentence */
					__( 'The REST API answers anonymous requests with HTTP %1$d, which blocks the registration step every AI client performs before it has any credential. %2$s', 'acrossai-mcp-manager' ),
					$probe['code'],
					self::attribution_sentence( $culprits, $reason )
				),
				array() === $culprits
					? __( 'Find the setting that restricts the REST API to logged-in users and allow unauthenticated access — at minimum the acrossai-mcp-manager/v1 and mcp namespaces. It is usually in a security plugin, but a host-level rule or an mu-plugin can do it too. Then run these checks again.', 'acrossai-mcp-manager' )
					: sprintf(
						/* translators: %s: comma-separated plugin names */
						__( 'Open %s and allow unauthenticated access to the REST API — at minimum the acrossai-mcp-manager/v1 and mcp namespaces. Then run these checks again.', 'acrossai-mcp-manager' ),
						implode( __( ', ', 'acrossai-mcp-manager' ), $culprits )
					),
				Remedies::paths_for_support()
			);
		}

		if ( 200 !== $probe['code'] ) {
			return Result::warning(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The REST index answered HTTP %d instead of 200. That is not a flat refusal, but it is not healthy either.', 'acrossai-mcp-manager' ),
					$probe['code']
				),
				__( 'Open the REST index in a private browser window and read what it says.', 'acrossai-mcp-manager' )
			);
		}

		return Result::ok(
			$this->id(),
			$this->label(),
			__( 'The REST API answers anonymous requests, so client registration can reach it.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Plugins with a callback on the hooks that gate REST access.
	 *
	 * `rest_authentication_errors` is the documented way to refuse
	 * unauthenticated REST requests and is what the "disable REST API"
	 * plugins and most security suites use. `rest_pre_dispatch` and
	 * `rest_endpoints` can refuse too, so they are inspected as well —
	 * a plugin on any of them is a candidate worth naming.
	 *
	 * Naming is not blame: a plugin may be on these hooks for an unrelated
	 * reason, which is why the message says "look at" rather than "this is".
	 * It still turns a thirty-plugin bisect into two.
	 *
	 * @return array<int, string>
	 */
	private static function plugins_filtering_rest_access(): array {
		$names = array();

		foreach ( array( 'rest_authentication_errors', 'rest_pre_dispatch', 'rest_endpoints' ) as $hook ) {
			foreach ( PluginNamer::plugins_hooked_to( $hook ) as $name ) {
				$names[ $name ] = true;
			}
		}

		return array_keys( $names );
	}

	/**
	 * The REST error message the site itself returned, when there is one.
	 *
	 * WordPress refuses with a JSON body carrying `code` and `message`, and
	 * plugins put their own wording there — "Only authenticated users can
	 * access the REST API", and so on. Quoting it back is usually the
	 * fastest route to the setting, because it is the string the operator
	 * can search for in their plugin's options.
	 *
	 * @param string $body Response body.
	 * @return string Message, or ''.
	 */
	private static function reason_from_body( string $body ): string {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['message'] ) || ! is_string( $decoded['message'] ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( $decoded['message'] ) );
	}

	/**
	 * One sentence naming what is doing it, from whichever evidence exists.
	 *
	 * @param array<int, string> $culprits Plugin names found on the gate hooks.
	 * @param string             $reason   The site's own error message, or ''.
	 * @return string
	 */
	private static function attribution_sentence( array $culprits, string $reason ): string {
		$parts = array();

		if ( '' !== $reason ) {
			$parts[] = sprintf(
				/* translators: %s: the error message the REST API returned */
				__( 'The site answered: "%s".', 'acrossai-mcp-manager' ),
				$reason
			);
		}

		if ( array() !== $culprits ) {
			$parts[] = sprintf(
				/* translators: %s: comma-separated plugin names */
				_n(
					'%s filters REST access on this site and is the place to look.',
					'These plugins filter REST access on this site and are the place to look: %s.',
					count( $culprits ),
					'acrossai-mcp-manager'
				),
				implode( __( ', ', 'acrossai-mcp-manager' ), $culprits )
			);
		} else {
			$parts[] = __( 'No active plugin was found filtering REST access, so this is more likely a host-level rule, an mu-plugin or a server configuration than a plugin setting.', 'acrossai-mcp-manager' );
		}

		return implode( ' ', $parts );
	}
}
