<?php
/**
 * Site maintenance mode — write the .maintenance marker used by WP core.
 *
 * Writes ABSPATH/.maintenance so wp_maintenance() (wp-includes/load.php)
 * short-circuits every request with a 503. Because core treats the marker
 * as stale after 10 minutes, a wp-cron event refreshes the timestamp
 * every 5 minutes until the requested duration elapses.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\SiteHealth
 * @since      0.0.25
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\SiteHealth;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Set_Site_Maintenance_Mode — activate the WP-core .maintenance marker
 * for a bounded duration, keeping the timestamp fresh via wp-cron.
 */
class Set_Site_Maintenance_Mode extends Ability_Definition {

	public const CRON_HOOK        = 'acrossai_refresh_maintenance_marker';
	public const EXPIRY_OPTION    = 'acrossai_maintenance_expires_at';
	public const REFRESH_INTERVAL = 300; // 5 minutes.
	public const DEFAULT_MINUTES  = 60;
	public const MAX_MINUTES      = 1440;

	/**
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'site-health/set-site-maintenance-mode',
			'args' => array(
				'label'               => __( 'Set Site Maintenance Mode', 'acrossai-abilities-manager' ),
				'description'         => __( 'Activate WordPress core maintenance mode by writing the ABSPATH/.maintenance marker. A wp-cron event refreshes the marker every 5 minutes so the site stays down for the requested duration. WARNING: this blocks wp-admin as well as the frontend.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-site-health',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'duration_minutes' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => self::MAX_MINUTES,
							'default' => self::DEFAULT_MINUTES,
						),
						'confirm'          => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'confirm' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'          => array( 'type' => 'boolean' ),
						'active'           => array( 'type' => 'boolean' ),
						'expires_at'       => array( 'type' => 'integer' ),
						'duration_minutes' => array( 'type' => 'integer' ),
						'refresh_scheduled'=> array( 'type' => 'boolean' ),
						'message'          => array( 'type' => 'string' ),
						'error_code'       => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'diagnostics',
						'sub_group'       => 'maintenance',
						'sub_group_label' => __( 'Maintenance', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
				),
			),
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		if ( empty( $input['confirm'] ) ) {
			return array(
				'success'    => false,
				'message'    => __( 'Refused: set confirm=true to acknowledge that maintenance mode blocks wp-admin as well as the frontend.', 'acrossai-abilities-manager' ),
				'error_code' => 'confirmation_required',
			);
		}

		$minutes = isset( $input['duration_minutes'] ) ? (int) $input['duration_minutes'] : self::DEFAULT_MINUTES;
		if ( $minutes < 1 ) {
			$minutes = self::DEFAULT_MINUTES;
		}
		if ( $minutes > self::MAX_MINUTES ) {
			$minutes = self::MAX_MINUTES;
		}

		$now        = time();
		$expires_at = $now + ( $minutes * MINUTE_IN_SECONDS );
		$marker     = ABSPATH . '.maintenance';

		$written = self::write_marker( $marker, $now );
		if ( ! $written ) {
			return array(
				'success'    => false,
				'message'    => __( 'Could not write the .maintenance marker file at ABSPATH. Check filesystem permissions.', 'acrossai-abilities-manager' ),
				'error_code' => 'marker_write_failed',
			);
		}

		update_option( self::EXPIRY_OPTION, $expires_at, false );

		$scheduled = true;
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$result = wp_schedule_event( $now + self::REFRESH_INTERVAL, 'acrossai_five_minutes', self::CRON_HOOK );
		if ( false === $result || is_wp_error( $result ) ) {
			$scheduled = false;
		}

		return array(
			'success'          => true,
			'active'           => true,
			'expires_at'       => $expires_at,
			'duration_minutes' => $minutes,
			'refresh_scheduled'=> $scheduled,
			/* translators: 1: duration in minutes, 2: expiry unix timestamp */
			'message'          => sprintf( __( 'Maintenance mode activated for %1$d minutes (expires at %2$d).', 'acrossai-abilities-manager' ), $minutes, $expires_at ),
		);
	}

	/**
	 * Write the .maintenance marker with the given $upgrading timestamp.
	 *
	 * @param string $marker Absolute path.
	 * @param int    $timestamp Unix time to assign to $upgrading.
	 * @return bool True on success.
	 */
	public static function write_marker( string $marker, int $timestamp ): bool {
		$contents = '<?php $upgrading = ' . $timestamp . '; ?>';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the exact file WP core writes; WP_Filesystem is unavailable in ability execution context.
		$bytes = @file_put_contents( $marker, $contents, LOCK_EX );
		return false !== $bytes;
	}

	/**
	 * WP-cron callback — refresh the marker until the recorded expiry.
	 * Deletes the marker and clears itself once expiry passes.
	 */
	public static function refresh_marker(): void {
		$expires_at = (int) get_option( self::EXPIRY_OPTION, 0 );
		$marker     = ABSPATH . '.maintenance';
		$now        = time();

		if ( $expires_at <= 0 || $now >= $expires_at ) {
			if ( file_exists( $marker ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- symmetric with write_marker() which uses file_put_contents.
				@unlink( $marker );
			}
			delete_option( self::EXPIRY_OPTION );
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		self::write_marker( $marker, $now );
	}

	/**
	 * Register the 5-minute cron schedule used by refresh_marker().
	 *
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public static function register_cron_schedule( array $schedules ): array {
		if ( ! isset( $schedules['acrossai_five_minutes'] ) ) {
			$schedules['acrossai_five_minutes'] = array(
				'interval' => self::REFRESH_INTERVAL,
				'display'  => __( 'Every 5 minutes (AcrossAI maintenance-mode refresh)', 'acrossai-abilities-manager' ),
			);
		}
		return $schedules;
	}
}
