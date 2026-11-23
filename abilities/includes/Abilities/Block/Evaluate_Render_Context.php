<?php
/**
 * Feature 074 — inspect the rendered wrapper around a post's block content.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities\Block
 * @since      0.0.31
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities\Block;

use AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition;

defined( 'ABSPATH' ) || exit;

/**
 * Render a post's content in a mock loop, then extract the wrapper class list
 * and inline style hints around the `.entry-content` / `.page-content` container.
 */
class Evaluate_Render_Context extends Ability_Definition {

	/**
	 * Full ability spec for wp_register_ability().
	 *
	 * @return array<string,mixed>
	 */
	protected function ability(): array {
		return array(
			'name' => 'blocks/evaluate-render-context',
			'args' => array(
				'label'               => __( 'Evaluate Render Context', 'acrossai-abilities-manager' ),
				'description'         => __( 'Render a post\'s content in a mock loop, then inspect the wrapper element that constrains width (entry-content / page-content or theme equivalent). Complements validate-content (which only sees the block markup).', 'acrossai-abilities-manager' ),
				'category'            => 'acrossai-block',
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'success'         => array( 'type' => 'boolean' ),
						'post_id'         => array( 'type' => 'integer' ),
						'wrapper_classes' => array( 'type' => 'array' ),
						'inline_style'    => array( 'type' => 'string' ),
						'inner_html_size' => array( 'type' => 'integer' ),
						'message'         => array( 'type' => 'string' ),
					),
					'required'             => array( 'success' ),
					'additionalProperties' => false,
				),
				'meta'                => array(
					'acrossai'     => array(
						'tab_group'       => 'blocks',
						'sub_group'       => 'analysis',
						'sub_group_label' => __( 'Analysis', 'acrossai-abilities-manager' ),
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
	 * @param array<string,mixed> $input Ability input payload.
	 * @return array<string,mixed>
	 */
	public function execute( array $input = array() ): array {
		$post_id     = absint( $input['post_id'] ?? 0 );
		$target_post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $target_post instanceof \WP_Post ) {
			return $this->failure( $post_id, __( 'Post not found.', 'acrossai-abilities-manager' ) );
		}

		global $post;
		$backup = $post;
		$post   = $target_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- setup_postdata contract.
		setup_postdata( $post );

		$content = apply_filters( 'the_content', (string) $target_post->post_content );

		wp_reset_postdata();
		$post = $backup; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring prior state.

		$wrapper_classes = array();
		$inline_style    = '';

		if ( class_exists( '\DOMDocument' ) && '' !== trim( $content ) ) {
			$dom = new \DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?><body>' . $content . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
			$xpath = new \DOMXPath( $dom );
			$nodes = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ') or contains(concat(' ', normalize-space(@class), ' '), ' page-content ') or contains(concat(' ', normalize-space(@class), ' '), ' wp-block-post-content ')]" );
			if ( $nodes instanceof \DOMNodeList && $nodes->length > 0 ) {
				$node          = $nodes->item( 0 );
				$class_attr    = $node instanceof \DOMElement ? (string) $node->getAttribute( 'class' ) : '';
				$style_attr    = $node instanceof \DOMElement ? (string) $node->getAttribute( 'style' ) : '';
				if ( '' === trim( $class_attr ) ) {
					$wrapper_classes = array();
				} else {
					$parts           = preg_split( '/\s+/', trim( $class_attr ) );
					$wrapper_classes = is_array( $parts ) ? $parts : array();
				}
				$inline_style    = trim( $style_attr );
			}
		}

		return array(
			'success'         => true,
			'post_id'         => $post_id,
			'wrapper_classes' => array_values( $wrapper_classes ),
			'inline_style'    => $inline_style,
			'inner_html_size' => strlen( $content ),
			'message'         => __( 'Render context inspected.', 'acrossai-abilities-manager' ),
		);
	}

	/**
	 * Failure envelope.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $message Failure message.
	 * @return array<string,mixed>
	 */
	private function failure( int $post_id, string $message ): array {
		return array(
			'success'         => false,
			'post_id'         => $post_id,
			'wrapper_classes' => array(),
			'inline_style'    => '',
			'inner_html_size' => 0,
			'message'         => $message,
		);
	}
}
