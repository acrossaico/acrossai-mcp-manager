<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Comments
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Comments;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helper for Approve / Unapprove / Mark-as-Spam abilities — translates
 * the REST-style status vocabulary (`approved`, `hold`, `spam`, `trash`) into
 * the matching core call and returns a formatted comment.
 */
final class Moderation {

	/**
	 * Set status.
	 *
	 * @param int $id
	 * @param string $status
	 * @return array
	 */
	public static function set_status( int $id, string $status ): array {
		if ( $id <= 0 ) {
			return array(
				'success' => false,
				'message' => __( 'A valid id is required.', 'acrossai-abilities-manager' ),
			);
		}

		$comment = get_comment( $id );
		if ( null === $comment ) {
			return array(
				'success' => false,
				'message' => __( 'Comment not found.', 'acrossai-abilities-manager' ),
			);
		}

		switch ( $status ) {
			case 'approved':
			case 'approve':
			case '1':
				$result = wp_set_comment_status( $id, 'approve', true );
				break;
			case 'hold':
			case 'unapproved':
			case '0':
				$result = wp_set_comment_status( $id, 'hold', true );
				break;
			case 'spam':
				$result = wp_spam_comment( $id );
				break;
			case 'trash':
				$result = wp_trash_comment( $id );
				break;
			default:
				return array(
					'success' => false,
					/* translators: %s: status value */
					'message' => sprintf( __( 'Unsupported status "%s".', 'acrossai-abilities-manager' ), $status ),
				);
		}

		if ( true !== $result ) {
			return Comment_Formatter::error_from(
				$result,
				/* translators: 1: comment ID, 2: status */
				sprintf( __( 'Could not set comment #%1$d to "%2$s".', 'acrossai-abilities-manager' ), $id, $status )
			);
		}

		$updated = get_comment( $id );
		$payload = null !== $updated ? Comment_Formatter::to_array( $updated ) : array();

		return array(
			'success' => true,
			'comment' => $payload,
			/* translators: 1: comment ID, 2: status */
			'message' => sprintf( __( 'Comment #%1$d set to "%2$s".', 'acrossai-abilities-manager' ), $id, $status ),
		);
	}
}
