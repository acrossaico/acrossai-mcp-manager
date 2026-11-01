<?php
/**
 * What is sitting in front of WordPress? (Feature 101)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Name the CDN, WAF, cache and security plugins present.
 *
 * Presence only — this check never accuses anything of anything. It exists
 * because the operator's next step after a red row is "who do I ask", and
 * answering that from response headers and the active-plugin list saves them
 * discovering halfway through a support ticket that their host also runs an
 * edge cache they did not know about.
 *
 * Two independent sources, because each misses things the other catches: a
 * CDN announces itself in headers but is not a plugin, and a security plugin
 * that blocks silently leaves no header at all.
 */
final class EdgeLayerCheck implements Check {

	/**
	 * Response headers that identify a layer, header => label.
	 *
	 * @var array<string, string>
	 */
	private const HEADER_SIGNATURES = array(
		'cf-ray'            => 'Cloudflare',
		'cf-cache-status'   => 'Cloudflare cache',
		'x-sucuri-id'       => 'Sucuri',
		'x-sucuri-cache'    => 'Sucuri cache',
		'x-wpe-backend'     => 'WP Engine',
		'x-kinsta-cache'    => 'Kinsta cache',
		'x-litespeed-cache' => 'LiteSpeed cache',
		'x-proxy-cache'     => 'proxy cache',
		'x-cache'           => 'an upstream cache',
		'x-ac'              => 'Pressable / Automattic edge',
	);

	/**
	 * Active-plugin basename prefixes, prefix => label.
	 *
	 * @var array<string, string>
	 */
	private const PLUGIN_SIGNATURES = array(
		'wordfence/'                           => 'Wordfence',
		'better-wp-security/'                  => 'Solid Security',
		'ithemes-security-pro/'                => 'Solid Security Pro',
		'sucuri-scanner/'                      => 'Sucuri Security',
		'all-in-one-wp-security-and-firewall/' => 'All In One WP Security',
		'wp-cerber/'                           => 'Cerber Security',
		'wp-simple-firewall/'                  => 'ShieldPRO',
		'malcare-security/'                    => 'MalCare',
		'advanced-ip-blocker/'                 => 'Advanced IP Blocker',
		'litespeed-cache/'                     => 'LiteSpeed Cache',
		'wp-rocket/'                           => 'WP Rocket',
		'w3-total-cache/'                      => 'W3 Total Cache',
		'wp-super-cache/'                      => 'WP Super Cache',
		'wp-fastest-cache/'                    => 'WP Fastest Cache',
		'cache-enabler/'                       => 'Cache Enabler',
		'nginx-helper/'                        => 'Nginx Helper',
		'cloudflare/'                          => 'Cloudflare (plugin)',
	);

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'edge';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Caching and security layers', 'acrossai-mcp-manager' );
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
		$found   = array();
		$headers = $context->all_observed_headers();

		foreach ( self::HEADER_SIGNATURES as $header => $label ) {
			if ( isset( $headers[ $header ] ) && '' !== $headers[ $header ] ) {
				$found[ $label ] = true;
			}
		}

		if ( isset( $headers['server'] ) && false !== stripos( $headers['server'], 'litespeed' ) ) {
			$found['LiteSpeed'] = true;
		}
		if ( isset( $headers['server'] ) && false !== stripos( $headers['server'], 'cloudflare' ) ) {
			$found['Cloudflare'] = true;
		}

		foreach ( self::PLUGIN_SIGNATURES as $prefix => $label ) {
			foreach ( (array) get_option( 'active_plugins', array() ) as $basename ) {
				if ( 0 === strpos( (string) $basename, $prefix ) ) {
					$found[ $label ] = true;
					break;
				}
			}
		}

		$cache_control = $headers['cache-control'] ?? '';
		$stack         = array_keys( $found );

		if ( array() === $stack ) {
			return Result::info(
				$this->id(),
				$this->label(),
				'' !== $cache_control
					? sprintf(
						/* translators: %s: observed Cache-Control header value */
						__( 'No CDN, caching plugin or security plugin was detected. Discovery responds with Cache-Control: %s', 'acrossai-mcp-manager' ),
						$cache_control
					)
					: __( 'No CDN, caching plugin or security plugin was detected.', 'acrossai-mcp-manager' )
			);
		}

		return Result::info(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: comma-separated layer names, 2: Cache-Control header or a placeholder */
				__( 'In front of or alongside WordPress: %1$s. Discovery responds with Cache-Control: %2$s. This is a list of who to ask, not an accusation — each of these can be configured to pass AI traffic.', 'acrossai-mcp-manager' ),
				implode( ', ', $stack ),
				'' !== $cache_control ? $cache_control : __( '(not stated)', 'acrossai-mcp-manager' )
			),
			__( 'If a connection fails while every check above passes, these are the layers that can reject a caller this probe cannot impersonate.', 'acrossai-mcp-manager' )
		);
	}
}
