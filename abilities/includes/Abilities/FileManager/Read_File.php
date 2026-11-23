<?php
/**
 * Absorbed ability class scaffolded from acrossai-core-abilities (Feature 046).
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\FileManager
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\FileManager;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Hardening_Enforcer;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Fs_Roots;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Path_Allowlist_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Secret_Redactor;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Wp_Filesystem_Init;

defined( 'ABSPATH' ) || exit;

/**
 * Read_File ability class (absorbed).
 *
 * Feature 092: the previous outright refusal of wp-config.php / .htaccess
 * (blocked_reason:'protected_read') was removed. Those files are now
 * readable; sensitive content is scrubbed by Secret_Redactor before the
 * response leaves the site. Reads are also gated by the admin-configurable
 * read allowlist (Path_Allowlist_Guard).
 */
class Read_File extends Ability_Definition {

	/**
	 * Maximum file size (bytes) that read-file will return as text. Files
	 * larger than this are refused so the ability cannot exhaust PHP's
	 * memory limit on an arbitrary path.
	 *
	 * @var int
	 */
	private const MAX_READ_BYTES = 5242880; // 5 * 1024 * 1024.

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'file-manager/read-file',
			'args' => array(
				'label'               => __( 'Read File', 'acrossai-abilities-manager' ),
				'description'         => __( 'Reads the contents of a file within the WordPress installation. Path is relative to the WordPress directories -- a leading wp-content/ resolves against WP_CONTENT_DIR, so it reaches the live content directory on hosts that place it outside ABSPATH. The response reports which root answered in root. Refuses files larger than 5 MB and reports binary content without returning raw bytes. Text content is scrubbed through the configurable secret redactor before return; response includes redacted:bool and redaction_count:int. When a read allowlist is configured, reads outside it return blocked_reason:"path_not_allowed_for_read".', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-file-manager',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'path' => array(
							'type'        => 'string',
							'description' => __( 'File path relative to a WordPress directory (e.g. wp-content/uploads/test.txt).', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'path' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'content'         => array( 'type' => 'string' ),
						'path'            => array( 'type' => 'string' ),
						'root'            => array( 'type' => 'string' ),
						'size'            => array( 'type' => 'integer' ),
						'binary'          => array( 'type' => 'boolean' ),
						'redacted'        => array( 'type' => 'boolean' ),
						'redaction_count' => array( 'type' => 'integer' ),
						'max_bytes'       => array( 'type' => 'integer' ),
						'blocked_reason'  => array( 'type' => 'string' ),
						'allowed_roots'   => array( 'type' => 'array' ),
						// Feature 093 sensitive-read denylist context fields.
						'basename'        => array( 'type' => 'string' ),
						'matched_pattern' => array( 'type' => 'string' ),
						'message'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'files',
						'sub_group'       => 'files',
						'sub_group_label' => __( 'Files', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => false,
						'type'   => 'tool',
					),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Ability input payload.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$blocked = Wp_Filesystem_Init::blocked_response();
		if ( null !== $blocked ) {
			return $blocked;
		}
		$fs = Wp_Filesystem_Init::get();

		$rel_path = sanitize_text_field( $input['path'] ?? '' );

		// Feature 135: resolved against every root WordPress uses, not just ABSPATH. A path that
		// lands in an ABSPATH/wp-content which is not this site's content directory is refused
		// rather than served -- see Fs_Roots for the measurement that prompted this.
		$resolved = Fs_Roots::resolve( $rel_path );

		if ( is_wp_error( $resolved ) ) {
			return Fs_Roots::blocked_response( $resolved );
		}

		$abs_path = $resolved['path'];

		// Feature 092: admin-controlled read allowlist gate. Resolves against
		// the (possibly not-yet-existent) target — pass the composed absolute
		// path so the guard's realpath resolution of allowed roots works even
		// on paths that don't exist yet.
		$abs_for_check = realpath( $abs_path ) ?: $abs_path;
		$blocked       = Path_Allowlist_Guard::blocked_read_response( $abs_for_check );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// Feature 093 (FR-011): sensitive-read denylist runs AFTER the allowlist
		// gate so allowlist refusals (path_not_allowed_for_read) take precedence.
		$blocked = Hardening_Enforcer::check_read( $abs_for_check );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( ! $fs->is_file( $abs_path ) ) {
			return array(
				'success' => false,
				'message' => __( 'File does not exist.', 'acrossai-abilities-manager' ),
			);
		}

		// Size cap: refuse before loading the file into memory.
		$size = (int) $fs->size( $abs_path );
		if ( $size > self::MAX_READ_BYTES ) {
			return array(
				'success'        => false,
				'blocked_reason' => 'file_too_large',
				'size'           => $size,
				'max_bytes'      => self::MAX_READ_BYTES,
				/* translators: 1: observed size, 2: max size */
				'message'        => sprintf( __( 'File size (%1$d bytes) exceeds the maximum readable size (%2$d bytes).', 'acrossai-abilities-manager' ), $size, self::MAX_READ_BYTES ),
			);
		}

		$content = $fs->get_contents( $abs_path );

		if ( false === $content ) {
			return array(
				'success' => false,
				'message' => __( 'Could not read file.', 'acrossai-abilities-manager' ),
			);
		}

		$real_path = realpath( $abs_path ) ?: $abs_path;

		// Binary detection: return a distinct shape without the raw bytes.
		// Binary content is never routed through the redactor.
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return array(
				'success' => true,
				'binary'  => true,
				'path'    => $real_path,
				'root'    => $resolved['root'],
				'size'    => $size,
				'message' => __( 'Binary file; contents not returned as text.', 'acrossai-abilities-manager' ),
			);
		}

		// Feature 092: scrub secrets from the returned text content.
		$scrubbed = Secret_Redactor::scrub( $content );

		return array(
			'success'         => true,
			'content'         => $scrubbed['text'],
			'path'            => $real_path,
			'root'            => $resolved['root'],
			'size'            => $size,
			'binary'          => false,
			'redacted'        => $scrubbed['redacted'],
			'redaction_count' => $scrubbed['redaction_count'],
		);
	}
}
