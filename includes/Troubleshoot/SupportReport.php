<?php
/**
 * Plain-text diagnostic report for a support ticket (Feature 101).
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot;

defined( 'ABSPATH' ) || exit;

/**
 * Everything on the page as one paste-able block.
 *
 * Carries no secrets: site URL, versions, and each check's status and
 * message. The remedies are omitted — they are instructions to the operator,
 * not information about the site, and they would triple the length of
 * something meant to be pasted into a ticket box.
 */
final class SupportReport {

	/**
	 * Build the report.
	 *
	 * @param array<int, array<string, string>> $results Flat results from CheckRunner.
	 * @return string
	 */
	public static function build( array $results ): string {
		$lines = array(
			'AcrossAI MCP Manager — connection troubleshooter',
			'',
			'Site:            ' . untrailingslashit( home_url() ),
			'Plugin version:  ' . ( defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) ? (string) \ACROSSAI_MCP_MANAGER_VERSION : 'unknown' ),
			'WordPress:       ' . get_bloginfo( 'version' ),
			'PHP:             ' . PHP_VERSION,
			'Permalinks:      ' . ( '' !== (string) get_option( 'permalink_structure', '' ) ? (string) get_option( 'permalink_structure', '' ) : 'plain' ),
			'',
			str_repeat( '-', 60 ),
			'',
		);

		foreach ( $results as $result ) {
			$lines[] = sprintf(
				'[%s] %s',
				strtoupper( (string) ( $result['status'] ?? '' ) ),
				(string) ( $result['label'] ?? '' )
			);
			$lines[] = '    ' . (string) ( $result['message'] ?? '' );
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
