<?php
/**
 * Feature 069 — regenerate the IndexNow API key.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\RankMath
 * @since      0.0.28
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\RankMath;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\RankMath\Instant_Indexing_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Ability #10 — rank-math/reset-indexing-key.
 *
 * Destructive despite writing rather than deleting: IndexNow verifies ownership by
 * fetching a key file at a URL derived from the key. Rotating it invalidates the
 * old location, and search engines reject submissions with 403 until the new file
 * is reachable. The previous key is not recoverable.
 */
class Reset_Indexing_Key extends Base_Rank_Math_Ability {

	protected function slug(): string {
		return 'reset-indexing-key';
	}

	protected function ability_label(): string {
		return __( 'Reset IndexNow API Key', 'acrossai-abilities-manager' );
	}

	protected function ability_description(): string {
		return __( 'Generate a new IndexNow API key. The key file URL changes with it, so search engines will reject submissions with HTTP 403 until they can fetch the new file. The previous key cannot be restored. Only needed if the current key has leaked or is being rejected.', 'acrossai-abilities-manager' );
	}

	protected function sub_group(): string {
		return 'rank-math-instant-indexing';
	}

	protected function rank_math_cap(): string {
		return 'general';
	}

	protected function required_module(): string {
		return Instant_Indexing_Repository::MODULE;
	}

	protected function requires_confirmation(): bool {
		return true;
	}

	protected function input_properties(): array {
		return array();
	}

	protected function output_properties(): array {
		return array(
			'previous_key' => array( 'type' => 'string' ),
			'key'          => array( 'type' => 'string' ),
			'key_location' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Deliberately empty. 'confirm' must not be schema-required — see
	 * Base_Rank_Math_Ability::ability(). A required confirm would make an
	 * unconfirmed call fail core schema validation before execute() runs, so the
	 * caller would get a generic ability_invalid_input instead of
	 * confirmation_required and the message naming the flag.
	 */
	protected function required_input(): array {
		return array();
	}

	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => true, 'idempotent' => true );
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$result = Instant_Indexing_Repository::reset_key();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['message'] = sprintf(
			/* translators: %s: new key file URL */
			__( 'Generated a new IndexNow key. Search engines must be able to fetch %s before submissions will be accepted.', 'acrossai-abilities-manager' ),
			$result['key_location']
		);

		return $result;
	}
}
