<?php
/**
 * Records and reports schema upgrades that could not be applied (FR-013b).
 *
 * Every `Table` subclass in this plugin fires
 * `acrossai_mcp_schema_upgrade_failed` when a migration statement fails, and
 * until now nothing listened. A signal with no listener is the same as no
 * signal: the version stays unstamped so the migration will retry, but nobody
 * is told that the table is currently in a shape the code does not expect.
 *
 * That matters more the more installs there are. A failed migration leaves a
 * table whose columns or keys disagree with the schema, which is the B34
 * failure mode — writes that silently lose data, or queries that quietly use
 * the wrong index. Across a fleet, silence is indistinguishable from success.
 *
 * Two parts, deliberately in one class because neither is useful alone:
 * the listener persists what happened, the Site Health test reports it.
 *
 * @package    AcrossAI_MCP_Manager
 * @subpackage Includes\Database\Support
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Listener + Site Health reporter for failed schema upgrades.
 *
 * Hook wiring lives in Main.php per A1.
 */
final class SchemaFailureHealthCheck {

	/** Site Health test key. */
	public const TEST_KEY = 'acrossai_mcp_schema_upgrades';

	/** Where the failures are kept. Autoload off — only read in wp-admin. */
	public const OPTION_FAILURES = 'acrossai_mcp_schema_upgrade_failures';

	/**
	 * How many distinct failures to keep.
	 *
	 * Keyed by table + version, so a migration retrying on every admin page
	 * load overwrites its own entry rather than growing the option. The cap
	 * only binds if many DIFFERENT migrations fail, which is already a
	 * catastrophic state; it exists so a failing upgrade path cannot inflate an
	 * option without bound.
	 */
	private const MAX_FAILURES = 20;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/** Singleton accessor. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Private — wiring lives in Main.php per A1. */
	private function __construct() {}

	/**
	 * Listener for `acrossai_mcp_schema_upgrade_failed`.
	 *
	 * Never throws. This runs inside a migration that has ALREADY failed; a
	 * second failure here must not turn a recoverable schema problem into a
	 * fatal on an admin page load.
	 *
	 * @param mixed $table   Table name, without prefix.
	 * @param mixed $version Target schema version.
	 * @param mixed $error   Database error text.
	 */
	public function record_failure( $table = '', $version = '', $error = '' ): void {
		try {
			$table   = substr( (string) $table, 0, 64 );
			$version = substr( (string) $version, 0, 32 );

			if ( '' === $table ) {
				return;
			}

			$failures = $this->failures();

			// Keyed, so a migration retrying every admin load updates its entry
			// instead of appending a new one each time.
			$failures[ $table . '@' . $version ] = array(
				'table'   => $table,
				'version' => $version,
				'error'   => substr( (string) $error, 0, 500 ),
				'at'      => gmdate( 'Y-m-d H:i:s' ),
			);

			if ( count( $failures ) > self::MAX_FAILURES ) {
				$failures = array_slice( $failures, -self::MAX_FAILURES, null, true );
			}

			update_option( self::OPTION_FAILURES, $failures, false );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Forget every recorded failure.
	 *
	 * Called once reconciliation finds that NO table still needs an upgrade,
	 * which is the normal outcome: an unstamped version means the migration
	 * retries on the next admin load, so most failures here are transient and
	 * must stop being reported when they resolve.
	 *
	 * All-or-nothing rather than per table because BerlinDB's `Table` exposes
	 * no public accessor for its own name, so a stored failure cannot be
	 * matched back to a specific Table object from outside. "Nothing needs
	 * upgrading" is the stronger statement anyway, and it is the one worth
	 * acting on.
	 */
	public function clear_all(): void {
		try {
			if ( array() !== $this->failures() ) {
				update_option( self::OPTION_FAILURES, array(), false );
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Register the Site Health test.
	 *
	 * @param array<string, mixed>|mixed $tests Site Health tests.
	 * @return array<string, mixed>|mixed
	 */
	public function add_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct'][ self::TEST_KEY ] = array(
			'label' => __( 'AcrossAI MCP database upgrades', 'acrossai-mcp-manager' ),
			'test'  => array( $this, 'run_self_check' ),
		);

		return $tests;
	}

	/**
	 * Report any migration that has not been able to apply.
	 *
	 * @return array<string, mixed>
	 */
	public function run_self_check(): array {
		$failures = $this->failures();

		if ( empty( $failures ) ) {
			return $this->build_result(
				'good',
				__( 'AcrossAI MCP database tables are up to date', 'acrossai-mcp-manager' ),
				'<p>' . esc_html__( 'Every database change this plugin needed has been applied successfully.', 'acrossai-mcp-manager' ) . '</p>'
			);
		}

		$items = '';

		foreach ( $failures as $failure ) {
			$items .= '<li>' . sprintf(
				/* translators: 1: database table name, 2: schema version, 3: UTC timestamp. */
				esc_html__( '%1$s could not be upgraded to version %2$s (last attempt %3$s UTC)', 'acrossai-mcp-manager' ),
				'<code>' . esc_html( (string) ( $failure['table'] ?? '' ) ) . '</code>',
				'<code>' . esc_html( (string) ( $failure['version'] ?? '' ) ) . '</code>',
				esc_html( (string) ( $failure['at'] ?? '' ) )
			);

			if ( ! empty( $failure['error'] ) ) {
				$items .= '<br /><em>' . esc_html( (string) $failure['error'] ) . '</em>';
			}

			$items .= '</li>';
		}

		return $this->build_result(
			'critical',
			__( 'An AcrossAI MCP database upgrade could not be applied', 'acrossai-mcp-manager' ),
			'<p>' . esc_html__( 'One or more database changes this plugin needed did not apply. The affected table is in a shape the plugin does not expect, which can mean saved data is silently incomplete.', 'acrossai-mcp-manager' ) . '</p>'
			. '<ul>' . $items . '</ul>'
			. '<p>' . esc_html__( 'The upgrade is retried on each admin page load, so this often clears on its own. If it persists, the database user most likely lacks ALTER permission on these tables.', 'acrossai-mcp-manager' ) . '</p>'
		);
	}

	/**
	 * Stored failures, normalised to an array.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function failures(): array {
		$stored = get_option( self::OPTION_FAILURES, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Shape a Site Health result.
	 *
	 * @param string $status      One of `good`, `recommended` or `critical`.
	 * @param string $label       Short headline.
	 * @param string $description Already-escaped HTML.
	 * @return array<string, mixed>
	 */
	private function build_result( string $status, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Database', 'acrossai-mcp-manager' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '',
			'test'        => self::TEST_KEY,
		);
	}
}
