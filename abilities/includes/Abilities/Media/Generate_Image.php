<?php
/**
 * Generate_Image ability (Feature 131).
 *
 * Slug: media/generate-image
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Media
 * @since      0.0.43
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Media;

use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Media\Ai_Image_Generator;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Media\Media_Sideload;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Generate an image from a text prompt and add it to the Media Library in one call.
 *
 * The generic abilities can store an image you already have (media/upload-media),
 * but none can make one. This generates the image server-side through WordPress
 * 7.0's bundled AI client — using whichever AI connector the site has configured
 * for image generation — and sideloads the result into the library, optionally
 * attaching it to a post and setting it as the featured image.
 *
 * Each run makes a paid AI request, so it is gated on confirm:true.
 */
class Generate_Image extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array
	 */
	protected function ability(): array {
		return array(
			'name' => 'media/generate-image',
			'args' => array(
				'label'               => __( 'Generate Image', 'acrossai-abilities-manager' ),
				'description'         => __( 'Generate an image from a text prompt and add it to the Media Library in one call, using WordPress\'s built-in AI client and whichever connector the site has configured for image generation. Optionally attach it to a post and set it as the featured image. Each call makes a paid AI request, so confirm:true is required. Requires WordPress 7.0+ and an image-capable AI connector; without one it returns a clear message rather than an error.', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-media',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'prompt'          => array(
							'type'        => 'string',
							'description' => __( 'What to generate, described in plain language.', 'acrossai-abilities-manager' ),
						),
						'confirm'         => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Must be true. Generation makes a paid AI request.', 'acrossai-abilities-manager' ),
						),
						'aspect_ratio'    => array(
							'type'        => 'string',
							'enum'        => array( '1:1', '16:9', '4:3', '3:2', '9:16', '2:3' ),
							'description' => __( 'Optional output aspect ratio. Model support varies.', 'acrossai-abilities-manager' ),
						),
						'post_id'         => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'Attach the new image to this post.', 'acrossai-abilities-manager' ),
						),
						'set_as_featured' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'When post_id is given, also set the image as that post\'s featured image.', 'acrossai-abilities-manager' ),
						),
						'title'           => array(
							'type'        => 'string',
							'description' => __( 'Attachment title. Defaults to one derived from the prompt.', 'acrossai-abilities-manager' ),
						),
						'alt_text'        => array(
							'type'        => 'string',
							'description' => __( 'Alt text for the image.', 'acrossai-abilities-manager' ),
						),
						'caption'         => array(
							'type'        => 'string',
							'description' => __( 'Attachment caption.', 'acrossai-abilities-manager' ),
						),
						'description'     => array(
							'type'        => 'string',
							'description' => __( 'Attachment description.', 'acrossai-abilities-manager' ),
						),
						'filename'        => array(
							'type'        => 'string',
							'description' => __( 'Base filename without extension. Defaults to one derived from the prompt.', 'acrossai-abilities-manager' ),
						),
						'provider'        => array(
							'type'        => 'string',
							'description' => __( 'Advanced: force a specific AI provider id (e.g. google, openai).', 'acrossai-abilities-manager' ),
						),
						'model'           => array(
							'type'        => 'string',
							'description' => __( 'Advanced: force a specific model id. Requires provider.', 'acrossai-abilities-manager' ),
						),
					),
					'required'             => array( 'prompt' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'id'              => array( 'type' => 'integer' ),
						'url'             => array( 'type' => 'string' ),
						'media'           => array( 'type' => 'object', 'additionalProperties' => true ),
						'provider'        => array( 'type' => 'string' ),
						'model'           => array( 'type' => 'string' ),
						'set_as_featured' => array( 'type' => 'boolean' ),
						'message'         => array( 'type' => 'string' ),
						'error_code'      => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'content',
						'sub_group'       => 'manage',
						'sub_group_label' => __( 'Media', 'acrossai-abilities-manager' ),
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false, 'type' => 'tool' ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
				),
			),
		);
	}

	/**
	 * Point callers who have an image at the plain uploader.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function suggested_abilities(): array {
		return array(
			array(
				'slug'   => 'media/upload-media',
				'reason' => __( 'Already have the image bytes, a URL, or a local file? Skip generation and upload it directly.', 'acrossai-abilities-manager' ),
			),
		);
	}

	/**
	 * Execute — generate the image and add it to the library.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( array $input = array() ): array {
		$prompt = isset( $input['prompt'] ) ? trim( (string) $input['prompt'] ) : '';
		if ( '' === $prompt ) {
			return array(
				'success'    => false,
				'error_code' => 'invalid_input',
				'message'    => __( 'prompt is required.', 'acrossai-abilities-manager' ),
			);
		}

		if ( empty( $input['confirm'] ) || true !== (bool) $input['confirm'] ) {
			return array(
				'success'    => false,
				'error_code' => 'confirmation_required',
				'message'    => __( 'Generating an image makes a paid AI request. Pass confirm:true to proceed.', 'acrossai-abilities-manager' ),
			);
		}

		if ( ! Ai_Image_Generator::is_available() ) {
			return array(
				'success'    => false,
				'error_code' => 'image_generation_unavailable',
				'message'    => Ai_Image_Generator::unavailable_message(),
			);
		}

		$generated = Ai_Image_Generator::generate(
			$prompt,
			array(
				'aspect_ratio' => isset( $input['aspect_ratio'] ) ? (string) $input['aspect_ratio'] : '',
				'provider'     => isset( $input['provider'] ) ? (string) $input['provider'] : '',
				'model'        => isset( $input['model'] ) ? (string) $input['model'] : '',
			)
		);
		if ( is_wp_error( $generated ) ) {
			return array(
				'success'    => false,
				'error_code' => (string) $generated->get_error_code(),
				'message'    => $generated->get_error_message(),
			);
		}

		$filename = isset( $input['filename'] ) && '' !== trim( (string) $input['filename'] )
			? (string) $input['filename']
			: sanitize_title( $prompt );
		if ( '' === $filename ) {
			$filename = 'ai-image';
		}
		$filename = substr( $filename, 0, 80 );

		$meta_input = array( '_acrossai_generated_image' => 1 );
		if ( ! empty( $input['alt_text'] ) ) {
			$meta_input['_wp_attachment_image_alt'] = sanitize_text_field( (string) $input['alt_text'] );
		}

		$post_data = array( 'meta_input' => $meta_input );
		if ( ! empty( $input['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( (string) $input['title'] );
		}
		if ( isset( $input['description'] ) ) {
			$post_data['post_content'] = (string) $input['description'];
		}
		if ( isset( $input['caption'] ) ) {
			$post_data['post_excerpt'] = (string) $input['caption'];
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );

		$id = Media_Sideload::from_base64( $generated['base64'], $filename, $generated['mime'], $post_id, $post_data );
		if ( is_wp_error( $id ) ) {
			return array(
				'success'    => false,
				'error_code' => (string) $id->get_error_code(),
				'message'    => $id->get_error_message(),
			);
		}
		$id = (int) $id;

		$featured = false;
		if ( $post_id > 0 && ! empty( $input['set_as_featured'] ) ) {
			$featured = (bool) set_post_thumbnail( $post_id, $id );
		}

		return array(
			'success'         => true,
			'id'              => $id,
			'url'             => (string) wp_get_attachment_url( $id ),
			'media'           => (array) get_post( $id, ARRAY_A ),
			'provider'        => $generated['provider'],
			'model'           => $generated['model'],
			'set_as_featured' => $featured,
			/* translators: %d: attachment ID */
			'message'         => sprintf( __( 'Generated and uploaded attachment #%d.', 'acrossai-abilities-manager' ), $id ),
		);
	}
}
