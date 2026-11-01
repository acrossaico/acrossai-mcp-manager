<?php
/**
 * Do the two discovery documents answer, and are they ours? (Feature 101)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\OAuth\DiscoveryController;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Remedies;

defined( 'ABSPATH' ) || exit;

/**
 * Fetch both `.well-known` documents over HTTP and judge the response.
 *
 * "Answers 200" is not enough. A document can be 200 and still be wrong in
 * three ways this separates:
 *
 *   - not JSON          — an HTML challenge page, a WAF interstitial, or the
 *                         homepage after an edge rule redirected us
 *   - JSON, not ours    — another plugin has taken over the path (the
 *                         DiscoveryConflictGuard case)
 *   - a redirect        — hosting platforms ship rules that 301 these paths;
 *                         the redirect names the owner, so it is not followed
 */
final class DiscoveryCheck implements Check {

	public const URLS = array(
		'oauth-authorization-server' => 'issuer',
		'oauth-protected-resource'   => 'resource',
	);

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'discovery';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'OAuth discovery documents', 'acrossai-mcp-manager' );
	}

	/**
	 * @return array<int, string>
	 */
	public function requires(): array {
		return array( 'rewrite_rules' );
	}

	/**
	 * Absolute URL of one discovery document.
	 *
	 * Built from the site's origin, not `home_url( $path )`: the well-known
	 * segment goes at the host root, before any subdirectory, so joining it
	 * to a subdirectory home URL produces a path that does not exist.
	 *
	 * @param string $document Well-known document name.
	 * @return string
	 */
	public static function url( string $document ): string {
		$origin = wp_parse_url( home_url( '/' ) );
		$scheme = isset( $origin['scheme'] ) ? (string) $origin['scheme'] : 'https';
		$host   = isset( $origin['host'] ) ? (string) $origin['host'] : '';
		$port   = isset( $origin['port'] ) ? ':' . (int) $origin['port'] : '';

		return $scheme . '://' . $host . $port . '/.well-known/' . $document;
	}

	/**
	 * Run the check.
	 *
	 * @param Context $context Shared probe cache and prior results.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		foreach ( self::URLS as $document => $identifying_field ) {
			$url   = self::url( $document );
			$probe = $context->fetch( $url );

			if ( '' !== $probe['error'] ) {
				return Result::fail(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: URL, 2: HTTP error message */
						__( 'This site could not fetch its own %1$s: %2$s', 'acrossai-mcp-manager' ),
						$url,
						$probe['error']
					),
					__( 'The server cannot reach its own public URL. Ask the host whether loopback HTTP requests are blocked — AI clients will reach it from outside, so this may affect only the checks on this page, but it also means nothing here can be trusted.', 'acrossai-mcp-manager' )
				);
			}

			if ( 301 === $probe['code'] || 302 === $probe['code'] || 307 === $probe['code'] || 308 === $probe['code'] ) {
				return Result::fail(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: URL, 2: HTTP status, 3: Location header */
						__( '%1$s answered HTTP %2$d and redirected to %3$s instead of returning the document. Something in front of WordPress owns this path.', 'acrossai-mcp-manager' ),
						$url,
						$probe['code'],
						$probe['headers']['location'] ?? __( '(no Location header)', 'acrossai-mcp-manager' )
					),
					__( 'Hosting platforms sometimes ship edge rules that redirect /.well-known/ paths. Ask the host to exclude the two OAuth documents below from any redirect or rewrite at the edge.', 'acrossai-mcp-manager' ),
					Remedies::paths_for_support()
				);
			}

			if ( 200 !== $probe['code'] ) {
				return Result::fail(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: URL, 2: HTTP status code */
						__( '%1$s answered HTTP %2$d instead of 200.', 'acrossai-mcp-manager' ),
						$url,
						$probe['code']
					),
					403 === $probe['code'] || 503 === $probe['code']
						? __( 'A firewall, bot filter or security plugin is rejecting the request before WordPress runs. The paths below need allowlisting.', 'acrossai-mcp-manager' )
						: __( 'If the rewrite check above passed, this is not a routing problem — look at what is in front of WordPress.', 'acrossai-mcp-manager' ),
					Remedies::paths_for_support()
				);
			}

			$decoded = json_decode( $probe['body'], true );

			if ( ! is_array( $decoded ) ) {
				return Result::fail(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: URL, 2: observed content type */
						__( '%1$s answered 200 but the body is not JSON (content type: %2$s). That is usually a challenge page, an interstitial, or the homepage.', 'acrossai-mcp-manager' ),
						$url,
						$probe['headers']['content-type'] ?? __( 'not stated', 'acrossai-mcp-manager' )
					),
					__( 'Something is answering on this path instead of WordPress. Check for a bot-protection challenge, a maintenance page, or a caching layer holding non-JSON content.', 'acrossai-mcp-manager' ),
					Remedies::paths_for_support()
				);
			}

			if ( ! isset( $decoded[ $identifying_field ] ) ) {
				return Result::fail(
					$this->id(),
					$this->label(),
					sprintf(
						/* translators: 1: URL, 2: the JSON field that should be present */
						__( '%1$s returned JSON with no "%2$s" field, so it is not an OAuth metadata document at all. Another plugin is probably serving this path.', 'acrossai-mcp-manager' ),
						$url,
						$identifying_field
					),
					__( 'Deactivate other plugins that implement OAuth discovery, or check the admin notices — this plugin reports a discovery conflict by name when it can detect one.', 'acrossai-mcp-manager' )
				);
			}
		}

		return Result::ok(
			$this->id(),
			$this->label(),
			__( 'Both documents answer 200 with JSON that identifies this site. Note this probe runs from the server to itself, so a firewall that blocks only external or datacenter addresses would still let it pass.', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * The same documents, produced in-process. Used by the cache check as the
	 * ground truth to compare an HTTP fetch against.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function documents_in_process(): array {
		return array(
			'oauth-authorization-server' => DiscoveryController::authorization_server_document(),
			'oauth-protected-resource'   => DiscoveryController::protected_resource_document(),
		);
	}
}
