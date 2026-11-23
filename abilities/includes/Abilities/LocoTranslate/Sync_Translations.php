<?php
/**
 * Feature 116 - reconciles a translation against its template or the source.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Translation_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * reconciles a translation against its template or the source.
 *
 * @since 0.0.34
 */
final class Sync_Translations extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/sync-translations';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Sync Translations', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Reconcile a translation against its POT template, or against the plugin source when there is no template: strings that no longer exist are dropped, new ones are added empty, and existing translations are carried across. Only exact source matches are carried over - Loco\'s fuzzy matching is deliberately off here, because a fuzzy match attaches a guessed translation to a changed string and there is no human in this loop to catch it.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'maintenance';
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
				'description' => __( 'Locale to sync.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array( 'bundle', 'locale' );
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
				'description'          => __( 'Bytes written per artefact, read back from disk.', 'acrossai-abilities-manager' ),
			),
			'file'          => array( 'type' => 'string' ),
			'before_count'  => array( 'type' => 'integer' ),
			'after_count'   => array( 'type' => 'integer' ),
			'source'        => array(
				'type'        => 'string',
				'description' => __( 'Whether the template or the source code was used as the reference.', 'acrossai-abilities-manager' ),
			),
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
		$file = $this->resolve_file( $input );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$bundle = Bundle_Repository::find( (string) ( $input['bundle'] ?? '' ) );

		if ( is_wp_error( $bundle ) ) {
			return $bundle;
		}

		$project = $this->resolve_project( $input );

		if ( is_wp_error( $project ) ) {
			return $project;
		}

		$target = Translation_Repository::load( $file );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		$before = count( Translation_Repository::messages( $target, '', PHP_INT_MAX ) );
		$pot    = $project->getPot();

		// Prefer the template; fall back to scanning the source, which is what Loco's own sync does
		// when a bundle ships no POT.
		if ( $pot && $pot->exists() ) {
			$source = Translation_Repository::load( (string) $pot->getPath() );
			$origin = 'template';

			if ( is_wp_error( $source ) ) {
				return $source;
			}
		} else {
			$extracted = Translation_Repository::extract( $bundle, $project );

			if ( is_wp_error( $extracted ) ) {
				return $extracted;
			}

			$source = $extracted['po'];
			$origin = 'source';
		}

		$synced = Translation_Repository::sync( $target, $source, $project );

		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		$written = Translation_Repository::write( $file, $synced['po'], $project );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		return array(
			'artefacts'    => $written,
			'file'         => $file,
			'before_count' => $before,
			'after_count'  => count( Translation_Repository::messages( $synced['po'], '', PHP_INT_MAX ) ),
			'source'       => $origin,
		);
	}
}
