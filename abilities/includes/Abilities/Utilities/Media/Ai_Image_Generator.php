<?php
/**
 * Feature 131 — the sole bridge to WordPress core's PHP AI client for image
 * generation.
 *
 * Every `wp_ai_client_prompt()` / `WordPress\AiClient\*` reference in the suite
 * lives here. The ability class never names one, so the whole SDK surface is in
 * one file that degrades to a WP_Error instead of fatally dereferencing a client
 * that is absent (WordPress < 7.0) or a connector that is not configured.
 *
 * Mirrors wp-content/plugins/ai's Generate_Image::get_prompt_builder(), but
 * against core's client only — this plugin does not depend on the AI plugin.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Utilities\Media
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Media;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Static-only wrapper over core's PHP AI image client.
 */
final class Ai_Image_Generator {

	/**
	 * Filter: whether image generation is supported on this site.
	 */
	public const SUPPORT_FILTER = 'acrossai_abilities_manager_image_generation_support';

	/**
	 * Filter: the ordered [provider, model] preference list.
	 */
	public const MODELS_FILTER = 'acrossai_abilities_manager_preferred_image_models';

	/**
	 * The core Capability enum value for image generation.
	 */
	private const IMAGE_CAPABILITY = 'image_generation';

	/**
	 * Private constructor — this class is static-only.
	 */
	private function __construct() {}

	/**
	 * Whether image generation can run on this site right now.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' )
			&& class_exists( '\WordPress\AiClient\AiClient' )
			&& self::has_image_support();
	}

	/**
	 * The "Query Monitor is not active" style message shared by the ability.
	 *
	 * @return string
	 */
	public static function unavailable_message(): string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return __( 'Image generation needs WordPress 7.0 or newer, which bundles the AI client this ability uses. No extra plugin is required.', 'acrossai-abilities-manager' );
		}

		// Distinguish "no API key yet" from "a key is set but the provider has no
		// image model", so the message tells the owner exactly what to do.
		$has_connector = false;
		$has_key       = false;
		if ( function_exists( 'wp_get_connectors' ) ) {
			foreach ( (array) wp_get_connectors() as $data ) {
				if ( ! is_array( $data ) || 'ai_provider' !== ( $data['type'] ?? '' ) ) {
					continue;
				}
				$has_connector = true;
				if ( self::connector_authenticated( $data ) ) {
					$has_key = true;
					break;
				}
			}
		}

		if ( ! $has_key ) {
			$message = $has_connector
				? __( 'This ability needs an AI connector with an API key, and none is set yet. Open Settings → Connectors, pick a provider that generates images — Cloudflare Workers AI (free daily allowance), Google (Gemini/Imagen) or OpenAI — and add its API key. No extra plugin is required.', 'acrossai-abilities-manager' )
				: __( 'This ability needs an AI connector, and none is configured. Open Settings → Connectors and connect a provider that generates images, such as Cloudflare Workers AI (free daily allowance), Google or OpenAI, then add its API key. No extra plugin is required.', 'acrossai-abilities-manager' );
		} else {
			$message = __( 'An AI connector is configured, but it does not offer image generation. Connect a provider that does — Cloudflare Workers AI, Google (Imagen/Gemini) or OpenAI — under Settings → Connectors.', 'acrossai-abilities-manager' );
		}

		if ( function_exists( 'admin_url' ) ) {
			/* translators: %s: URL of the WordPress Connectors settings screen. */
			$message .= ' ' . sprintf( __( 'Set it up here: %s', 'acrossai-abilities-manager' ), admin_url( 'options-connectors.php' ) );
		}

		return $message;
	}

	/**
	 * Whether any configured connector advertises image-generation support.
	 *
	 * Scans the Connectors API for an authenticated `ai_provider` whose model
	 * metadata lists the image-generation capability — the same test WordPress's
	 * AI plugin uses, but against core's registry directly. Cached per request,
	 * and filterable so a site can declare support for a connector that does not
	 * authenticate by API key.
	 *
	 * @return bool
	 */
	public static function has_image_support(): bool {
		$has_support = array() !== self::discover_image_models();

		/**
		 * Filters whether image generation is supported on this site.
		 *
		 * Lets a site declare support for a connector that does not authenticate by
		 * API key (e.g. OAuth), without a live request.
		 *
		 * @param bool $has_support Result of the connector capability scan.
		 */
		return (bool) apply_filters( self::SUPPORT_FILTER, $has_support );
	}

	/**
	 * The configured, image-capable [provider, model] pairs on this site.
	 *
	 * One pair per authenticated `ai_provider` connector whose model metadata
	 * lists the image-generation capability. The try/catch is PER CONNECTOR, not
	 * around the whole loop: a connector that is authenticated but not registered
	 * in the SDK registry throws from getProviderClassName(), and wrapping the
	 * whole scan would let that one failure hide a perfectly good connector later
	 * in the list. Cached per request.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function discover_image_models(): array {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}

		$found = array();

		if ( function_exists( 'wp_get_connectors' ) && class_exists( '\WordPress\AiClient\AiClient' ) ) {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();

			foreach ( (array) wp_get_connectors() as $id => $data ) {
				if ( ! is_string( $id ) || ! is_array( $data ) || 'ai_provider' !== ( $data['type'] ?? '' ) ) {
					continue;
				}
				if ( ! self::connector_authenticated( $data ) ) {
					continue;
				}

				try {
					$provider_class = $registry->getProviderClassName( $id );

					foreach ( $provider_class::modelMetadataDirectory()->listModelMetadata() as $model ) {
						foreach ( $model->getSupportedCapabilities() as $capability ) {
							if ( self::IMAGE_CAPABILITY === $capability->value ) {
								$found[] = array( $id, (string) $model->getId() );
								continue 3;
							}
						}
					}
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		$memo = $found;

		return $memo;
	}

	/**
	 * Generate an image from a prompt.
	 *
	 * @param string              $prompt The text prompt.
	 * @param array<string,mixed> $opts   aspect_ratio, provider, model, timeout.
	 * @return array{base64:string,mime:string,provider:string,model:string}|WP_Error
	 */
	public static function generate( string $prompt, array $opts = array() ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'image_generation_unavailable', self::unavailable_message() );
		}

		try {
			$request_options = new \WordPress\AiClient\Providers\Http\DTO\RequestOptions();
			$request_options->setTimeout( isset( $opts['timeout'] ) ? max( 10, (int) $opts['timeout'] ) : 90 );

			$builder = wp_ai_client_prompt( $prompt )
				->using_request_options( $request_options )
				->as_output_file_type( \WordPress\AiClient\Files\Enums\FileTypeEnum::inline() );

			if ( ! empty( $opts['aspect_ratio'] ) ) {
				$builder = $builder->as_output_media_aspect_ratio( (string) $opts['aspect_ratio'] );
			}

			$provider = isset( $opts['provider'] ) ? (string) $opts['provider'] : '';
			$model    = isset( $opts['model'] ) ? (string) $opts['model'] : '';

			if ( '' !== $provider && '' !== $model ) {
				$builder = $builder->using_model(
					\WordPress\AiClient\AiClient::defaultRegistry()->getProviderModel( $provider, $model )
				);
			} else {
				if ( '' !== $provider ) {
					$builder = $builder->using_provider( $provider );
				}
				$builder = $builder->using_model_preference( ...self::preferred_models() );
			}

			if ( ! $builder->is_supported_for_image_generation() ) {
				return new WP_Error( 'unsupported_model', self::unavailable_message() );
			}

			$result = $builder->generate_image_result();
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$file   = $result->toImageFile();
			$base64 = trim( (string) ( $file->getBase64Data() ?? '' ) );
			if ( '' === $base64 ) {
				return new WP_Error( 'no_image_data', __( 'The AI model returned no image data.', 'acrossai-abilities-manager' ) );
			}

			$provider_meta = $result->getProviderMetadata()->toArray();
			$model_meta     = $result->getModelMetadata()->toArray();

			return array(
				'base64'   => $base64,
				'mime'     => $file->getMimeType() ?: 'image/png',
				'provider' => (string) ( $provider_meta['name'] ?? $provider_meta['id'] ?? '' ),
				'model'    => (string) ( $model_meta['name'] ?? $model_meta['id'] ?? '' ),
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'image_generation_failed', $e->getMessage() );
		}
	}

	/**
	 * The ordered [provider, model] preference list.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private static function preferred_models(): array {
		// Prefer what the site actually has configured, so generation targets the
		// connector that is authenticated (not a provider that only exists in the
		// hard-coded list). Fall back to the common hosted models when the scan
		// found nothing — the filter can still override either way.
		$models = self::discover_image_models();

		if ( array() === $models ) {
			$models = array(
				array( 'google', 'gemini-3.1-flash-image-preview' ),
				array( 'google', 'gemini-2.5-flash-image' ),
				array( 'google', 'imagen-4.0-generate-001' ),
				array( 'openai', 'gpt-image-2' ),
			);
		}

		/**
		 * Filters the ordered [provider, model] image preference list.
		 *
		 * @param array<int,array{0:string,1:string}> $models The preference list.
		 */
		return (array) apply_filters( self::MODELS_FILTER, $models );
	}

	/**
	 * Whether a connector row carries a usable API-key credential.
	 *
	 * Reimplements the AI plugin's has_connector_authentication() against core's
	 * connector shape so this class needs nothing from that plugin.
	 *
	 * @param array<string,mixed> $data Connector row from wp_get_connectors().
	 * @return bool
	 */
	private static function connector_authenticated( array $data ): bool {
		$auth = $data['authentication'] ?? null;
		if ( ! is_array( $auth ) || 'api_key' !== ( $auth['method'] ?? '' ) ) {
			return false;
		}

		$setting = (string) ( $auth['setting_name'] ?? '' );
		$env     = (string) ( $auth['env_var_name'] ?? '' );
		$const   = (string) ( $auth['constant_name'] ?? '' );

		// Prefer WordPress core's own resolver, which checks env var → constant →
		// stored option and returns the source (or 'none'). Reimplement only when
		// an older core lacks it.
		if ( function_exists( '_wp_connectors_get_api_key_source' ) ) {
			return 'none' !== _wp_connectors_get_api_key_source( $setting, $env, $const );
		}

		if ( '' !== $env && '' !== (string) getenv( $env ) ) {
			return true;
		}
		if ( '' !== $const && defined( $const ) && '' !== (string) constant( $const ) ) {
			return true;
		}
		return '' !== $setting && '' !== (string) get_option( $setting, '' );
	}
}
