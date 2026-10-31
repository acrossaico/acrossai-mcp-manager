<?php
/**
 * BerlinDB Table subclass for the MCPServerMeta module.
 *
 * Standard WP-style meta table (mirrors wp_postmeta / wp_usermeta) keyed
 * by `server_id`. Generic per-server key-value storage owned by this plugin.
 *
 * acrossai-pro's embed feature is its largest tenant — it writes the
 * `_embeds_enabled` and `_embeds_clients` keys through here — but it is a
 * tenant, not the reason the table exists. Those keys are referenced below
 * only to explain an upgrade; nothing in this plugin reads them any more.
 *
 * F011 phantom-version guard preserved.
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage Includes\Database\MCPServerMeta
 */

declare( strict_types = 1 );

namespace AcrossAI_MCP_Manager\Includes\Database\MCPServerMeta;

defined( 'ABSPATH' ) || exit;

class Table extends \BerlinDB\Database\Kern\Table {

	/** @var string */
	protected $name = 'acrossai_mcp_servers_meta';

	/** @var string */
	protected $version = '1.0.1';

	/**
	 * @var array<string, string>
	 *
	 * 1.0.1 — retire the per-DTO row storage (`_embed_dto:*` meta keys)
	 * introduced during the F037 iteration cycle; the current storage is
	 * a single JSON blob at `_embeds_clients`. Stale rows would remain
	 * orphaned but harmless (no reader references them) — this upgrade
	 * cleans them up in one DELETE.
	 */
	protected $upgrades = array(
		'1.0.1' => 'upgrade_to_1_0_1',
	);

	/** @var string */
	protected $db_version_key = 'acrossai_mcp_servers_meta_db_version';

	/** @var string */
	protected $schema = Schema::class;

	/** @var bool */
	protected $global = false;

	/** @var Table|null */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
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
	 * Phantom-version guard (F011 SEC-011-002 preservation).
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->exists() ) {
			delete_option( $this->db_version_key );
		}
		parent::maybe_upgrade();
	}

	/**
	 * 1.0.1 — Delete legacy `_embed_dto:*` meta rows retired in favour of
	 * the single `_embeds_clients` JSON blob.
	 *
	 * @return bool
	 */
	protected function upgrade_to_1_0_1(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE meta_key LIKE %s',
				$wpdb->prefix . 'acrossai_mcp_servers_meta',
				$wpdb->esc_like( '_embed_dto:' ) . '%'
			)
		);
		return true;
	}
}
