<?php
/**
 * Feature 116 - writes translations and recompiles everything WordPress loads.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Loco_Guard;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Translation_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * writes translations and recompiles everything WordPress loads.
 *
 * @since 0.0.34
 */
final class Update_Strings extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/update-strings';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Update Translation Strings', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Write one or more translations into a locale\'s PO file and recompile every artefact WordPress actually loads. Translations are stored exactly as sent, including backslashes. Strings are matched on their exact source text plus context; anything that does not match is reported back rather than silently ignored. This is the only correct way to change a translation: editing the PO through the file abilities leaves the MO and the .l10n.php cache stale, so the site keeps serving the old strings with no error anywhere. The response carries the bytes written per artefact, read back from disk.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'strings';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'bundle' => array(
				'type'        => 'string',
				'description' => __( 'Bundle id, handle or slug.', 'acrossai-abilities-manager' ),
			),
			'domain' => array(
				'type'        => 'string',
				'description' => __( 'Text domain. Omit for the bundle default.', 'acrossai-abilities-manager' ),
			),
			'locale' => array(
				'type'        => 'string',
				'description' => __( 'Locale such as de_DE.', 'acrossai-abilities-manager' ),
			),
			'strings'        => array(
				'type'        => 'array',
				'items'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'description' => __( 'Rows of source, target and optional context. Source must match the file byte for byte.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'bundle', 'locale', 'strings' );
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'artefacts' => array(
				'type'                 => 'object',
				'additionalProperties' => true,
				'description'          => __( 'Bytes written per artefact: po_bytes, mo_bytes, php_bytes, json_files, json_bytes. Read back from disk, not reported by Loco - a zero mo_bytes means the site is still serving the old strings.', 'acrossai-abilities-manager' ),
			),
			'applied_count'   => array( 'type' => 'integer' ),
			'unmatched'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'unmatched_count' => array( 'type' => 'integer' ),
			'file'            => array( 'type' => 'string' ),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<string, bool>
	 */
	protected function annotations(): array {
		return array( 'readonly' => false, 'destructive' => false, 'idempotent' => false );
	}

	/**
	 * @since  0.0.34
	 * @param  array<string, mixed> $input Input.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input ) {
		$rows = isset( $input['strings'] ) && is_array( $input['strings'] ) ? $input['strings'] : array();

		if ( empty( $rows ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Supply at least one string to write.', 'acrossai-abilities-manager' )
			);
		}

		$file = $this->resolve_file( $input );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$project = $this->resolve_project( $input );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$po = Translation_Repository::load( $file );

		if ( is_wp_error( $po ) ) {
			return $po;
		}

		/*
		 * Targets are written through UNCHANGED. No wp_slash here, which is the opposite of what the
		 * post-writing abilities do and is worth stating because the instinct is wrong.
		 *
		 * The rule is about who unslashes, not about the payload. wp_insert_post() and
		 * update_post_meta() unslash internally, so their input must arrive slashed. Loco writes
		 * gettext files through its own writer, which ends in a raw putContents() with no unslashing
		 * anywhere in its gettext or fs layers - so slashing here ADDS a level nothing removes.
		 *
		 * Measured: sending a target containing a single backslash through a slashed write and
		 * reading it back returned two. A translation legitimately carries backslashes - an escaped
		 * quote, a \n in a format string - so that corruption would be silent and permanent.
		 */
		$changes = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['source'] ) ) {
				continue;
			}

			$changes[] = array(
				'source'  => (string) $row['source'],
				'context' => (string) ( $row['context'] ?? '' ),
				'target'  => (string) ( $row['target'] ?? '' ),
			);
		}

		$applied = Translation_Repository::apply( $po, $changes );
		$written = Translation_Repository::write( $file, $applied['po'], $project );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'artefacts'       => $written,
			'applied_count'   => $applied['applied_count'],
			'unmatched'       => $applied['unmatched'],
			'unmatched_count' => $applied['unmatched_count'],
			'file'            => $file,
			'message'         => 0 === $applied['applied_count']
				? __( 'Nothing matched. The file was still recompiled, but no translation changed - check the source text matches byte for byte.', 'acrossai-abilities-manager' )
				: sprintf(
					/* translators: %d: number of strings written. */
					_n( '%d translation written and recompiled.', '%d translations written and recompiled.', $applied['applied_count'], 'acrossai-abilities-manager' ),
					$applied['applied_count']
				),
		);
	}
}
