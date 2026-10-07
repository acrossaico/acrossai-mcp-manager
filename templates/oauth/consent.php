<?php
/**
 * OAuth 2.1 consent screen (Feature 021).
 *
 * Rendered by AuthorizationController::handle_get. Self-contained HTML —
 * NO admin frame, NO theme header, NO admin bar. Analogous to wp-login.php.
 *
 * Available variables (set by the controller):
 *   - $params            Sanitized authorize params.
 *   - $client_name       Human-readable client name.
 *   - $branding          Result of $profile->get_consent_branding() OR default.
 *   - $current_user      Logged-in WP_User.
 *   - $post_url          URL to POST approve/deny to.
 *   - $connector_icon    Icon URL (may be empty for non-connector-profile clients).
 *
 * @package AcrossAI_MCP_Manager
 * @subpackage templates/oauth
 */

defined( 'ABSPATH' ) || exit;

/** @var array<string, string> $params */
/** @var string $client_name */
/** @var array{heading: string, subtitle: string, permissions_bullets: array<int, string>} $branding */
/** @var \WP_User $current_user */
/** @var string $post_url */
/** @var string $connector_icon */

nocache_headers();

?><!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $branding['heading'] ); ?></title>
	<style>
		body {
			font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			background: #f0f0f1;
			margin: 0;
			padding: 40px 20px;
			color: #1d2327;
		}
		.acrossai-mcp-consent {
			max-width: 480px;
			margin: 40px auto;
			background: #fff;
			border: 1px solid #dcdcde;
			border-radius: 4px;
			padding: 32px;
			box-shadow: 0 1px 3px rgba( 0, 0, 0, .04 );
		}
		.acrossai-mcp-consent__icon {
			display: block;
			margin: 0 auto 16px;
			width: 64px;
			height: 64px;
		}
		.acrossai-mcp-consent__heading {
			font-size: 20px;
			text-align: center;
			margin: 0 0 8px;
			line-height: 1.3;
		}
		.acrossai-mcp-consent__subtitle {
			text-align: center;
			color: #50575e;
			margin: 0 0 24px;
		}
		.acrossai-mcp-consent__site {
			background: #f6f7f7;
			border-radius: 4px;
			padding: 12px;
			text-align: center;
			margin: 0 0 24px;
			font-size: 13px;
			color: #50575e;
		}
		.acrossai-mcp-consent__site strong {
			display: block;
			color: #1d2327;
			font-size: 15px;
			margin-bottom: 4px;
		}
		.acrossai-mcp-consent__bullets {
			margin: 0 0 24px;
			padding: 0 0 0 20px;
		}
		.acrossai-mcp-consent__bullets li {
			margin-bottom: 6px;
		}
		.acrossai-mcp-consent__buttons {
			display: flex;
			gap: 8px;
			margin-top: 24px;
		}
		.acrossai-mcp-consent__button {
			flex: 1;
			padding: 10px;
			font-size: 14px;
			border: 1px solid #2271b1;
			background: #2271b1;
			color: #fff;
			border-radius: 3px;
			cursor: pointer;
		}
		.acrossai-mcp-consent__button--deny {
			background: #fff;
			color: #2271b1;
		}
	</style>
</head>
<body>
	<div class="acrossai-mcp-consent">
		<?php if ( '' !== $connector_icon ) : ?>
			<img class="acrossai-mcp-consent__icon" src="<?php echo esc_url( $connector_icon ); ?>" alt="">
		<?php endif; ?>

		<h1 class="acrossai-mcp-consent__heading"><?php echo esc_html( $branding['heading'] ); ?></h1>
		<p class="acrossai-mcp-consent__subtitle"><?php echo esc_html( $branding['subtitle'] ); ?></p>

		<div class="acrossai-mcp-consent__site">
			<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
			<?php
			printf(
				/* translators: %s: user display name */
				esc_html__( 'Signed in as %s', 'acrossai-mcp-manager' ),
				esc_html( $current_user->display_name )
			);
			?>
		</div>

		<?php if ( ! empty( $branding['permissions_bullets'] ) ) : ?>
			<p><strong><?php esc_html_e( 'This will allow:', 'acrossai-mcp-manager' ); ?></strong></p>
			<ul class="acrossai-mcp-consent__bullets">
				<?php foreach ( $branding['permissions_bullets'] as $bullet ) : ?>
					<li><?php echo esc_html( $bullet ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( 'acrossai_mcp_manager_oauth_authorize' ); ?>
			<?php foreach ( $params as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>

			<div class="acrossai-mcp-consent__buttons">
				<button type="submit" name="authorize_action" value="deny" class="acrossai-mcp-consent__button acrossai-mcp-consent__button--deny">
					<?php esc_html_e( 'Deny', 'acrossai-mcp-manager' ); ?>
				</button>
				<button type="submit" name="authorize_action" value="approve" class="acrossai-mcp-consent__button">
					<?php esc_html_e( 'Approve', 'acrossai-mcp-manager' ); ?>
				</button>
			</div>
		</form>
	</div>
</body>
</html>
