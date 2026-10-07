<?php
/**
 * Shared OAuth message/error page.
 *
 * Rendered by MessagePage::render(). Self-contained HTML — NO admin
 * frame, NO theme header, NO admin bar. Analogous to wp-login.php and a
 * visual sibling of consent.php (same card, tokens, and typography).
 *
 * Available variables (set by MessagePage::render):
 *   - $message_heading    Pre-translated heading.
 *   - $message_paragraphs Pre-translated body paragraphs.
 *   - $message_icon_url   Icon URL ('' hides the icon).
 *   - $message_home_link  Whether to render the "Return to {site}" link.
 *   - $message_home_url   home_url( '/' ).
 *   - $message_site_name  get_bloginfo( 'name' ).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage templates/oauth
 */

defined( 'ABSPATH' ) || exit;

/** @var string $message_heading */
/** @var array<int, string> $message_paragraphs */
/** @var string $message_icon_url */
/** @var bool $message_home_link */
/** @var string $message_home_url */
/** @var string $message_site_name */

nocache_headers();

?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $message_heading ); ?></title>
	<style>
		body {
			font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: #f0f0f1;
			margin: 0;
			padding: 40px 20px;
			color: #1d2327;
		}
		.acrossai-mcp-message {
			max-width: 480px;
			margin: 40px auto;
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			padding: 32px;
			box-shadow: 0 1px 3px rgba( 0, 0, 0, .04 );
			text-align: center;
		}
		.acrossai-mcp-message__icon {
			display: block;
			margin: 0 auto 16px;
			width: 64px;
			height: 64px;
		}
		.acrossai-mcp-message__heading {
			font-size: 20px;
			margin: 0 0 8px;
			line-height: 1.3;
		}
		.acrossai-mcp-message__text {
			color: #50575e;
			margin: 0 0 16px;
		}
		.acrossai-mcp-message__home {
			margin: 24px 0 0;
			font-size: 13px;
		}
		.acrossai-mcp-message__home a {
			color: #2271b1;
			text-decoration: none;
		}
		.acrossai-mcp-message__home a:hover {
			text-decoration: underline;
		}
	</style>
</head>
<body>
	<div class="acrossai-mcp-message">
		<?php if ( '' !== $message_icon_url ) : ?>
			<img class="acrossai-mcp-message__icon" src="<?php echo esc_url( $message_icon_url ); ?>" alt="">
		<?php endif; ?>

		<h1 class="acrossai-mcp-message__heading"><?php echo esc_html( $message_heading ); ?></h1>

		<?php foreach ( $message_paragraphs as $message_paragraph ) : ?>
			<p class="acrossai-mcp-message__text"><?php echo esc_html( $message_paragraph ); ?></p>
		<?php endforeach; ?>

		<?php if ( $message_home_link ) : ?>
			<p class="acrossai-mcp-message__home">
				<a href="<?php echo esc_url( $message_home_url ); ?>">
					<?php
					printf(
						/* translators: %s: site name */
						esc_html__( 'Return to %s', 'acrossai-mcp-manager' ),
						esc_html( $message_site_name )
					);
					?>
				</a>
			</p>
		<?php endif; ?>
	</div>
</body>
</html>
