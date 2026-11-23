<?php
/**
 * Overrides_Store — JSON overrides file I/O + mu-plugin status.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Debugging
 * @since      0.0.21
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Debugging;

defined( 'ABSPATH' ) || exit;

/**
 * Overrides_Store — persists per-plugin override entries to a JSON document at
 * a fixed system-owned path and reports the mu-plugin mechanism status.
 *
 * Singleton per Constitution §I module contract. Every write is atomic
 * (temp file + rename). Every read auto-prunes orphaned entries (per FR-021)
 * and rewrites the file when the pruned map shrunk (or deletes the file
 * entirely if the pruned map is empty, per FR-012).
 */
final class Overrides_Store {

	/**
	 * On-disk path of the JSON overrides document.
	 *
	 * Fixed at WP_CONTENT_DIR . '/conflict-test-overrides.json' — no
	 * caller-supplied path is ever accepted (per FR-014).
	 */
	private const OVERRIDES_FILENAME = 'conflict-test-overrides.json';

	/**
	 * Deployed mu-plugin path relative to WPMU_PLUGIN_DIR.
	 *
	 * Fixed — no caller-supplied path is ever accepted (per FR-014).
	 */
	private const MU_PLUGIN_FILENAME = 'wp-conflict-tester.php';

	/**
	 * Relative asset path of the bundled mu-plugin source (from the plugin root).
	 */
	private const BUNDLED_MU_ASSET_REL = 'includes/Abilities/Debugging/assets/wp-conflict-tester.php';

	/**
	 * Cached SHA-256 of the bundled mu-plugin source, computed on first access.
	 *
	 * @var string|null
	 */
	private ?string $bundled_hash_cache = null;

	/** @var self|null */
	protected static $instance = null;

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Absolute path of the on-disk overrides document.
	 *
	 * @return string
	 */
	public function overrides_path(): string {
		return trailingslashit( WP_CONTENT_DIR ) . self::OVERRIDES_FILENAME;
	}

	/**
	 * Absolute path of the deployed mu-plugin file.
	 *
	 * @return string
	 */
	public function mu_plugin_path(): string {
		return trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_PLUGIN_FILENAME;
	}

	/**
	 * Absolute path of the bundled mu-plugin source asset shipped with this plugin.
	 *
	 * @return string
	 */
	public function bundled_mu_source_path(): string {
		return trailingslashit( ACROSSAI_ABILITIES_MANAGER_PLUGIN_PATH ) . self::BUNDLED_MU_ASSET_REL;
	}

	/**
	 * Read the overrides document from disk.
	 *
	 * Auto-prunes entries whose plugin no longer resolves against get_plugins()
	 * (FR-021). If pruning shrunk the map, the on-disk document is rewritten
	 * with the smaller version — or deleted entirely if the pruned map is
	 * empty (per FR-012). Callers observe only live entries.
	 *
	 * Malformed JSON is tolerated per FR-019 — the returned map is empty and
	 * parse_error is a short string describing the failure.
	 *
	 * @return array{overrides: array<string,bool>, parse_error: string|null}
	 */
	public function read(): array {
		$path = $this->overrides_path();

		if ( ! file_exists( $path ) ) {
			return array(
				'overrides'   => array(),
				'parse_error' => null,
			);
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixed system-owned path, WP_Filesystem is overkill for a per-site JSON blob
		if ( false === $raw ) {
			return array(
				'overrides'   => array(),
				'parse_error' => __( 'Overrides file exists but could not be read.', 'acrossai-abilities-manager' ),
			);
		}

		if ( '' === $raw ) {
			return array(
				'overrides'   => array(),
				'parse_error' => null,
			);
		}

		try {
			$decoded = json_decode( $raw, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return array(
				'overrides'   => array(),
				'parse_error' => sprintf(
					/* translators: %s: JSON parse error message */
					__( 'Overrides file is malformed JSON: %s', 'acrossai-abilities-manager' ),
					$e->getMessage()
				),
			);
		}

		$overrides = array();
		if ( is_array( $decoded ) && isset( $decoded['overrides'] ) && is_array( $decoded['overrides'] ) ) {
			foreach ( $decoded['overrides'] as $plugin_file => $active ) {
				if ( is_string( $plugin_file ) && '' !== $plugin_file ) {
					$overrides[ $plugin_file ] = (bool) $active;
				}
			}
		}

		if ( array() === $overrides ) {
			return array(
				'overrides'   => array(),
				'parse_error' => null,
			);
		}

		$pruned = $this->prune_orphans( $overrides );

		if ( $pruned !== $overrides ) {
			$this->persist( $pruned );
		}

		return array(
			'overrides'   => $pruned,
			'parse_error' => null,
		);
	}

	/**
	 * Write or drop a single override entry.
	 *
	 * Drops (does not record) an entry whose requested state already matches
	 * the plugin's DB-recorded active state (per FR-011).
	 *
	 * @param string $plugin_file Plugin identifier as returned by get_plugins().
	 * @param bool   $active      Effective active state.
	 * @return array{recorded: bool, reason: string}
	 */
	public function write_one( string $plugin_file, bool $active ): array {
		if ( $this->matches_db_state( $plugin_file, $active ) ) {
			// Idempotent: drop any existing entry for this plugin because it now
			// matches the DB — this is the correct FR-011 semantics.
			$current = $this->read()['overrides'];
			if ( isset( $current[ $plugin_file ] ) ) {
				unset( $current[ $plugin_file ] );
				$this->persist( $current );
			}
			return array(
				'recorded' => false,
				'reason'   => 'matches-db-state',
			);
		}

		$current = $this->read()['overrides'];
		$current[ $plugin_file ] = $active;
		$this->persist( $current );

		return array(
			'recorded' => true,
			'reason'   => 'override-applied',
		);
	}

	/**
	 * Batched write for the bulk path.
	 *
	 * Merges the given entries into the current map with the same
	 * matches-db-state cancellation rule as write_one(). Performs exactly
	 * one atomic write regardless of how many entries the caller passed.
	 *
	 * @param array<string,bool> $entries Map of plugin_file => desired active state.
	 * @return array{applied: list<array{plugin_file: string, active: bool}>, no_op: list<array{plugin_file: string, reason: string}>}
	 */
	public function write_many( array $entries ): array {
		$current = $this->read()['overrides'];
		$applied = array();
		$no_op   = array();

		foreach ( $entries as $plugin_file => $active ) {
			$active = (bool) $active;

			if ( $this->matches_db_state( $plugin_file, $active ) ) {
				if ( isset( $current[ $plugin_file ] ) ) {
					unset( $current[ $plugin_file ] );
				}
				$no_op[] = array(
					'plugin_file' => $plugin_file,
					'reason'      => 'matches-db-state',
				);
				continue;
			}

			$current[ $plugin_file ] = $active;
			$applied[] = array(
				'plugin_file' => $plugin_file,
				'active'      => $active,
			);
		}

		$this->persist( $current );

		return array(
			'applied' => $applied,
			'no_op'   => $no_op,
		);
	}

	/**
	 * Delete the overrides document from disk.
	 *
	 * Idempotent — an absent file is a success.
	 *
	 * @return array{cleared: true, file_existed_before: bool}
	 */
	public function clear(): array {
		$path            = $this->overrides_path();
		$existed_before  = file_exists( $path );

		if ( $existed_before && ! unlink( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fixed system-owned path
			// Retry once via WP_Filesystem in case of odd permissions; if still fails,
			// still report existed_before honestly. In practice unlink fails only when
			// the process lacks permission on the directory; those cases surface as
			// filesystem errors downstream.
			return array(
				'cleared'             => false,
				'file_existed_before' => true,
			);
		}

		return array(
			'cleared'             => true,
			'file_existed_before' => $existed_before,
		);
	}

	/**
	 * Report the deployed mu-plugin's state relative to the bundled reference.
	 *
	 * @return string One of 'deployed', 'missing', 'stale'.
	 */
	public function mu_plugin_status(): string {
		$deployed_path = $this->mu_plugin_path();

		if ( ! file_exists( $deployed_path ) ) {
			return 'missing';
		}

		$deployed_hash = @hash_file( 'sha256', $deployed_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable file should yield 'missing' rather than a fatal
		if ( false === $deployed_hash ) {
			return 'missing';
		}

		return $deployed_hash === $this->bundled_source_hash() ? 'deployed' : 'stale';
	}

	/**
	 * SHA-256 of the bundled mu-plugin source, cached per request.
	 *
	 * @return string
	 */
	public function bundled_source_hash(): string {
		if ( null !== $this->bundled_hash_cache ) {
			return $this->bundled_hash_cache;
		}

		$path = $this->bundled_mu_source_path();
		if ( ! file_exists( $path ) ) {
			$this->bundled_hash_cache = '';
			return '';
		}

		$hash                     = hash_file( 'sha256', $path );
		$this->bundled_hash_cache = false === $hash ? '' : $hash;
		return $this->bundled_hash_cache;
	}

	/**
	 * True if the requested effective state already equals the plugin's
	 * DB-recorded active state.
	 *
	 * @param string $plugin_file Plugin identifier.
	 * @param bool   $active      Requested effective state.
	 * @return bool
	 */
	public function matches_db_state( string $plugin_file, bool $active ): bool {
		$active_plugins = get_option( 'active_plugins', array() );
		if ( ! is_array( $active_plugins ) ) {
			$active_plugins = array();
		}
		$db_active = in_array( $plugin_file, $active_plugins, true );
		return $db_active === $active;
	}

	/**
	 * Filter out entries whose plugin no longer resolves against get_plugins().
	 *
	 * @param array<string,bool> $overrides Current map.
	 * @return array<string,bool> Pruned map — same reference-equal array if nothing was pruned.
	 */
	private function prune_orphans( array $overrides ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = get_plugins();

		$live = array();
		foreach ( $overrides as $plugin_file => $active ) {
			if ( isset( $installed[ $plugin_file ] ) ) {
				$live[ $plugin_file ] = $active;
			}
		}
		return $live;
	}

	/**
	 * Persist the given map atomically.
	 *
	 * If the map is empty, delete the on-disk document (FR-012). Otherwise
	 * write to a sibling temp file and rename into the target (FR-019 belt +
	 * suspenders, research R2).
	 *
	 * @param array<string,bool> $overrides Map to persist.
	 * @return void
	 */
	private function persist( array $overrides ): void {
		$path = $this->overrides_path();

		if ( array() === $overrides ) {
			if ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- fixed system-owned path
			}
			return;
		}

		$payload = wp_json_encode(
			array( 'overrides' => $overrides ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		if ( false === $payload ) {
			return;
		}

		$dir      = dirname( $path );
		$tmp_path = tempnam( $dir, '.wctester-' );
		if ( false === $tmp_path ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- fixed system-owned temp path; WP_Filesystem is overkill for a per-site JSON blob
		$bytes = file_put_contents( $tmp_path, $payload );
		if ( false === $bytes ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}

		if ( ! rename( $tmp_path, $path ) ) {
			@unlink( $tmp_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
