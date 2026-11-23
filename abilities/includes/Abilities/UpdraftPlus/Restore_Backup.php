<?php
/**
 * Feature 127 - put the site back to an earlier state. There is no undo.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\UpdraftPlus
 * @since      0.0.35
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\UpdraftPlus;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\UpdraftPlus\Backup_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The most destructive thing in this plugin.
 *
 * A restore overwrites what is on the site now with what was in the backup. Everything written since
 * that backup was taken - orders placed, posts published, users registered, settings changed - is
 * gone, and no step of this can be reversed. The confirmation text says so in those words, because
 * an operator who has not understood that has not really confirmed anything.
 *
 * The current state is reported BEFORE the restore runs, so the answer to "what am I about to lose"
 * is in the response rather than left to be inferred.
 *
 * @since 0.0.35
 */
final class Restore_Backup extends Base_UpdraftPlus_Ability {

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function slug(): string {
		return 'updraftplus/restore-backup';
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Restore Backup', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Restore this site from a backup set. THIS IS IRREVERSIBLE. It overwrites the current database and files with the contents of the backup: everything created or changed since that backup was taken - orders, posts, users, settings, uploads - is permanently lost, and there is no undo. Requires explicit confirmation. Consider taking a fresh backup first with updraftplus/start-backup, so the current state remains recoverable.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function sub_group(): string {
		return 'recovery';
	}

	/**
	 * @since  0.0.35
	 * @return bool
	 */
	protected function requires_confirmation(): bool {
		return true;
	}

	/**
	 * @since  0.0.35
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'THIS IS IRREVERSIBLE. Restoring replaces the current site with the backup: every order, post, user, setting and upload created or changed since that backup was taken is permanently lost, and there is no undo. If the current state matters, take a backup of it first with updraftplus/start-backup. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'id'         => array(
				'type'        => 'string',
				'description' => __( 'Backup identifier from updraftplus/list-backups.', 'acrossai-abilities-manager' ),
			),
			'components' => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Restrict the restore to these components of the set. Omit to restore all of them.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array('id');
	}

	/**
	 * @since  0.0.35
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'backup_id'       => array( 'type' => 'string' ),
			'entities'        => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			'job_id'          => array( 'type' => array( 'string', 'null' ) ),
			'replaced_state'  => array(
				'type'        => 'object',
				'additionalProperties' => true,
				'description' => __( 'What the site looked like immediately before the restore, recorded so the loss is visible.', 'acrossai-abilities-manager' ),
			),
			'irreversible'    => array( 'type' => 'boolean' ),
			'note'            => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.35
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
	}

	/**
	 * @since  0.0.35
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$id     = (string) $input['id'];
		$backup = Backup_Repository::get_backup( $id );

		if ( is_wp_error( $backup ) ) {
			return $backup;
		}

		// Recorded BEFORE the restore, because afterwards there is nothing left to read it from.
		$before = self::current_state( isset( $backup['created'] ) ? (int) $backup['created'] : 0 );

		$result = Backup_Repository::restore_backup( $id, $input );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['replaced_state'] = $before;
		$result['irreversible']   = true;
		$result['note']           = __( 'The restore has run and cannot be undone. Anything created or changed since the backup was taken is gone. Expect to sign in again if the users table was part of the set.', 'acrossai-abilities-manager' );

		return $result;
	}

	/**
	 * What is about to be overwritten.
	 *
	 * Counts rather than contents: enough for an operator to recognise the scale of what a restore
	 * discards, without dumping the site into a response.
	 *
	 * @since  0.0.35
	 * @param  int $backup_time When the backup being restored was taken.
	 * @return array<string, mixed>
	 */
	private static function current_state( int $backup_time ): array {
		$state = array(
			'checked_at'  => time(),
			'backup_time' => $backup_time > 0 ? $backup_time : null,
			'users'       => (int) count_users()['total_users'],
		);

		$posts = wp_count_posts( 'post' );
		$pages = wp_count_posts( 'page' );

		$state['published_posts'] = isset( $posts->publish ) ? (int) $posts->publish : 0;
		$state['published_pages'] = isset( $pages->publish ) ? (int) $pages->publish : 0;

		if ( $backup_time > 0 ) {
			$since = new \WP_Query(
				array(
					'post_type'      => 'any',
					'post_status'    => 'any',
					'date_query'     => array( array( 'after' => gmdate( 'Y-m-d H:i:s', $backup_time ), 'inclusive' => false ) ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
				)
			);

			$state['content_written_since_backup'] = (int) $since->found_posts;
		}

		return $state;
	}
}
