<?php
/**
 * Feature 116 - installs official language packs from WordPress.org.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\LocoTranslate
 * @since      0.0.34
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\LocoTranslate;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Bundle_Repository;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\LocoTranslate\Loco_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * installs official language packs from WordPress.org.
 *
 * @since 0.0.34
 */
final class Fetch_Translations extends Base_Loco_Ability {

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function slug(): string {
		return 'translations/fetch-translations';
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Fetch Translations', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Download and install the official WordPress.org language packs that this site is due, through WordPress\'s own language-pack installer rather than by fetching files directly - so they land where WordPress expects and are recorded as installed. Call list-available-languages first: most plugins have no official packs, and this reports honestly when there is nothing to fetch rather than appearing to succeed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.34
	 * @return string
	 */
	protected function sub_group(): string {
		return 'wordpress';
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function input_properties(): array {
		return array(
			'locale' => array(
				'type'        => 'string',
				'description' => __( 'Restrict to one locale, such as de_DE. Omit to install everything due.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * @since  0.0.34
	 * @return array<int, string>
	 */
	protected function required_input(): array {
		return array();
	}

	/**
	 * @since  0.0.34
	 * @return array<string, mixed>
	 */
	protected function output_properties(): array {
		return array(
			'installed'       => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'installed_count' => array( 'type' => 'integer' ),
			'available_count' => array(
				'type'        => 'integer',
				'description' => __( 'How many updates WordPress had pending before the install.', 'acrossai-abilities-manager' ),
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
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = (array) wp_get_translation_updates();
		$wanted  = trim( (string) ( $input['locale'] ?? '' ) );

		if ( '' !== $wanted ) {
			$locale = Loco_Guard::parse_locale( $wanted );

			if ( is_wp_error( $locale ) ) {
				return $locale;
			}

			$updates = array_values(
				array_filter(
					$updates,
					static function ( $update ) use ( $wanted ): bool {
						return isset( $update->language ) && (string) $update->language === $wanted;
					}
				)
			);
		}

		if ( empty( $updates ) ) {
			return array(
				'installed'       => array(),
				'installed_count' => 0,
				'available_count' => 0,
				'message'         => '' !== $wanted
					? sprintf(
						/* translators: %s: locale. */
						__( 'WordPress has no %s language packs due. Either everything is current, or nothing official exists — translations/list-available-languages tells you which.', 'acrossai-abilities-manager' ),
						$wanted
					)
					: __( 'WordPress has no language packs due. Either everything is current, or no bundle on this site has official translations.', 'acrossai-abilities-manager' ),
			);
		}

		foreach ( array( 'file.php', 'misc.php', 'class-wp-upgrader.php' ) as $include ) {
			require_once ABSPATH . 'wp-admin/includes/' . $include;
		}

		if ( ! class_exists( 'Language_Pack_Upgrader' ) ) {
			return new WP_Error(
				'upgrader_unavailable',
				__( 'WordPress\'s language-pack installer is not available on this request.', 'acrossai-abilities-manager' )
			);
		}

		// Automatic_Upgrader_Skin, not the default: the default one prints progress to output, which
		// in an ability response is noise at best and broken JSON at worst.
		$upgrader = new \Language_Pack_Upgrader( new \Automatic_Upgrader_Skin() );
		$results  = $upgrader->bulk_upgrade( $updates, array( 'clear_update_cache' => true ) );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		$installed = array();

		foreach ( (array) $results as $index => $result ) {
			// bulk_upgrade() returns true, a WP_Error or false per item. Only true is an install.
			if ( true !== $result ) {
				continue;
			}

			$update = $updates[ $index ] ?? null;

			$installed[] = array(
				'locale' => $update && isset( $update->language ) ? (string) $update->language : '',
				'slug'   => $update && isset( $update->slug ) ? (string) $update->slug : '',
				'type'   => $update && isset( $update->type ) ? (string) $update->type : '',
			);
		}

		return array(
			'installed'       => $installed,
			'installed_count' => count( $installed ),
			'available_count' => count( $updates ),
			'message'         => sprintf(
				/* translators: 1: installed count, 2: available count. */
				__( 'Installed %1$d of %2$d available language packs.', 'acrossai-abilities-manager' ),
				count( $installed ),
				count( $updates )
			),
		);
	}
}
