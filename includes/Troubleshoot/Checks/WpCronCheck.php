<?php
/**
 * Will the daily OAuth cleanup actually run? (Feature 101, issue #56)
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Troubleshoot\Checks
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Troubleshoot\Checks;

use AcrossAI_MCP_Manager\Includes\Database\OAuthAuthCodes\Query as AuthCodesQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthClients\Query as ClientsQuery;
use AcrossAI_MCP_Manager\Includes\Database\OAuthTokens\Query as TokensQuery;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Check;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Context;
use AcrossAI_MCP_Manager\Includes\Troubleshoot\Result;

defined( 'ABSPATH' ) || exit;

/**
 * Expired OAuth tokens and authorization codes are removed by a daily
 * `acrossai_mcp_manager_oauth_cleanup` event. Two things stop it: the
 * constant that switches WP-Cron off, and the event simply not being
 * scheduled — which happens on a site that was installed before the schedule
 * existed, or where another plugin cleared it.
 *
 * Severity is decided by whether the site has any OAuth data at all. On a
 * local or staging install with zero registered clients the cleanup is a
 * no-op, and warning an operator that a no-op will not run is how a
 * diagnostic screen teaches people to ignore it (issue #56). With real OAuth
 * state the same condition means rows accumulate indefinitely, and that is
 * worth saying plainly.
 */
final class WpCronCheck implements Check {

	/** The event the cleanup runs on. */
	private const EVENT = 'acrossai_mcp_manager_oauth_cleanup';

	/**
	 * Stable identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wp_cron';
	}

	/**
	 * Human-readable check name.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Scheduled OAuth cleanup', 'acrossai-mcp-manager' );
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
		$disabled  = ! self::cron_is_available();
		$scheduled = (bool) wp_next_scheduled( self::EVENT );
		$rows      = self::oauth_row_count();

		if ( ! $disabled && $scheduled ) {
			return Result::ok(
				$this->id(),
				$this->label(),
				__( 'WP-Cron is active and the daily OAuth cleanup is scheduled, so expired tokens and authorization codes are removed on their own.', 'acrossai-mcp-manager' )
			);
		}

		$cause = $disabled
			? __( 'WP-Cron is switched off on this site (DISABLE_WP_CRON is true)', 'acrossai-mcp-manager' )
			: __( 'the daily cleanup event is not scheduled', 'acrossai-mcp-manager' );

		// Nothing to clean yet. Say so, in the neutral tone it deserves: this
		// is the default state of every fresh install and of every local and
		// CI environment, where DISABLE_WP_CRON is set out of the box.
		if ( 0 === $rows ) {
			return Result::info(
				$this->id(),
				$this->label(),
				sprintf(
					/* translators: %s: the reason the cleanup will not run */
					__( 'The daily OAuth cleanup will not run automatically, because %s. Nothing is accumulating: this site has no OAuth clients, tokens or authorization codes, so the cleanup currently has no work to do. Worth revisiting once an AI host has actually connected.', 'acrossai-mcp-manager' ),
					$cause
				)
			);
		}

		return Result::warning(
			$this->id(),
			$this->label(),
			sprintf(
				/* translators: 1: the reason the cleanup will not run, 2: number of OAuth rows */
				_n(
					'%1$s, so the daily OAuth cleanup will not run — and this site has %2$d OAuth record that will never be removed once it expires.',
					'%1$s, so the daily OAuth cleanup will not run — and this site has %2$d OAuth records that will never be removed once they expire.',
					$rows,
					'acrossai-mcp-manager'
				),
				ucfirst( $cause ),
				$rows
			),
			$disabled
				? __( 'Either remove the DISABLE_WP_CRON constant from wp-config.php, or add a real cron entry running "wp cron event run acrossai_mcp_manager_oauth_cleanup" once a day. Expired rows are harmless individually; the cost is an unbounded table.', 'acrossai-mcp-manager' )
				: __( 'Deactivate and reactivate this plugin to reschedule the event, or schedule it yourself with "wp cron event schedule acrossai_mcp_manager_oauth_cleanup now daily".', 'acrossai-mcp-manager' )
		);
	}

	/**
	 * Can scheduled events fire on this site?
	 *
	 * `DISABLE_WP_CRON` is the usual answer, but it is not the whole one: a
	 * well-run site commonly sets it precisely BECAUSE it drives WP-Cron from
	 * a real system cron entry, which is the configuration we recommend. Such
	 * a site is healthy, and telling it otherwise forever is the complaint in
	 * issue #56. The filter lets it say so once.
	 *
	 * @return bool
	 */
	private static function cron_is_available(): bool {
		$available = ! ( defined( 'DISABLE_WP_CRON' ) && true === \DISABLE_WP_CRON );

		/**
		 * Filters whether scheduled events can fire on this site.
		 *
		 * Return true on a site that sets DISABLE_WP_CRON because it runs
		 * wp-cron.php from a system cron entry — the cleanup does run there,
		 * and the constant alone cannot tell us that.
		 *
		 * @since 0.4.6
		 *
		 * @param bool $available Derived from DISABLE_WP_CRON.
		 */
		return (bool) apply_filters( 'acrossai_mcp_cron_is_available', $available );
	}

	/**
	 * How many OAuth rows exist across the three tables.
	 *
	 * Counts rather than existence, because the number is what makes the
	 * warning concrete — "48 records" is actionable where "some data" is not.
	 * Each table is guarded separately: a site upgrading into this release
	 * may not have all three yet, and a troubleshooter that fatals on a
	 * missing table is worse than no troubleshooter.
	 *
	 * @return int
	 */
	private static function oauth_row_count(): int {
		$total = 0;

		foreach ( array( ClientsQuery::class, TokensQuery::class, AuthCodesQuery::class ) as $query_class ) {
			if ( ! class_exists( $query_class ) ) {
				continue;
			}

			$count = $query_class::instance()->query(
				array(
					'count'  => true,
					'number' => 0,
				)
			);

			$total += is_numeric( $count ) ? (int) $count : 0;
		}

		return $total;
	}
}
