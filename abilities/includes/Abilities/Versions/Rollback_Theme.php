<?php
/**
 * Feature 136 — move an installed theme onto a chosen version.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Versions
 * @since      0.0.45
 */

declare( strict_types = 1 );

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Versions;

defined( 'ABSPATH' ) || exit;

/**
 * Put a theme on a specific version, in either direction.
 *
 * The active theme is refused unless `force: true`. Not because the operation is more likely to
 * fail — it is the same file swap — but because the consequence is different in kind: a visitor
 * sees the result immediately, and a theme whose templates changed between versions can break a
 * site's appearance in a way no setting records. A caller rolling back a dormant theme is tidying
 * up; a caller rolling back the active one is changing what the site looks like right now, and
 * should have had to say so.
 *
 * @since 0.0.45
 */
class Rollback_Theme extends Base_Asset_Rollback {

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function slug(): string {
		return 'rollback-theme';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function asset_type(): string {
		return 'theme';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function identifier_key(): string {
		return 'stylesheet';
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_label(): string {
		return __( 'Roll Back Theme', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function ability_description(): string {
		return __( 'Move an installed theme onto a specific version — older or newer — by replacing its files with that version\'s package from WordPress.org, or from a local archive kept by WP Rollback for a privately-hosted theme. Customizer settings and content live outside the theme folder and are untouched, as are child themes. The active theme is refused unless force: true, because the change is visible to visitors immediately. The previous copy is backed up first and restored automatically if the package fails to apply. Requires confirm: true.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return string
	 */
	protected function confirmation_message(): string {
		return __( 'This replaces the theme\'s files with a different version. Customizer settings, content and child themes are kept, and the previous copy is restored automatically if the package fails to apply — but templates that changed between versions will change how the site renders. Pass confirm: true to proceed.', 'acrossai-abilities-manager' );
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function input_properties(): array {
		return array_merge(
			parent::input_properties(),
			array(
				'force' => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Required to change the version of the currently active theme, whose appearance changes for visitors as soon as the files are replaced.', 'acrossai-abilities-manager' ),
				),
			)
		);
	}

	/**
	 * @since  0.0.45
	 * @return array<string,mixed>
	 */
	protected function output_properties(): array {
		return array_merge(
			parent::output_properties(),
			array(
				'stylesheet'      => array( 'type' => 'string' ),
				'is_active_theme' => array( 'type' => 'boolean' ),
			)
		);
	}

	/**
	 * Refuse the active theme without `force`.
	 *
	 * A separate flag from `confirm` on purpose: `confirm` says "I accept a version change", and
	 * `force` says "I accept that this one is live". Folding them together would let a caller who
	 * meant to tidy a dormant theme change the site's appearance with the same single flag.
	 *
	 * @since  0.0.45
	 * @param  array<string,string> $asset Resolved asset.
	 * @param  array<string,mixed>  $input Caller input.
	 * @return \WP_Error|null
	 */
	protected function guard_asset( array $asset, array $input ) {
		$stylesheet = (string) $asset['slug'];

		if ( get_stylesheet() !== $stylesheet || ! empty( $input['force'] ) ) {
			return null;
		}

		return new \WP_Error(
			'active_theme_requires_force',
			sprintf(
				/* translators: %s: theme name */
				__( '"%s" is the active theme, so replacing its files changes what visitors see as soon as this returns. Pass force: true as well as confirm: true to proceed.', 'acrossai-abilities-manager' ),
				(string) $asset['name']
			)
		);
	}

	/**
	 * @since  0.0.45
	 * @param  array<string,string> $asset Resolved asset.
	 * @return array<string,mixed>
	 */
	protected function before_install( array $asset ): array {
		return array( 'is_active' => get_stylesheet() === (string) $asset['slug'] );
	}

	/**
	 * @since  0.0.45
	 * @param  array<string,string> $asset   Resolved asset.
	 * @param  array<string,mixed>  $state   State from before_install().
	 * @param  string               $version Version now installed.
	 * @return array<string,mixed>
	 */
	protected function after_install( array $asset, array $state, string $version ): array {
		$is_active = ! empty( $state['is_active'] );

		$message = sprintf(
			/* translators: 1: theme name, 2: version */
			__( '"%1$s" is now on version %2$s.', 'acrossai-abilities-manager' ),
			(string) $asset['name'],
			$version
		);

		if ( $is_active ) {
			$message .= ' ' . __( 'It is the active theme, so this is live for visitors now.', 'acrossai-abilities-manager' );
		}

		return array(
			'stylesheet'      => (string) $asset['slug'],
			'is_active_theme' => $is_active,
			'message'         => $message,
		);
	}
}
