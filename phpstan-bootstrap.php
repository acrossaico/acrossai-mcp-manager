<?php
/**
 * PHPStan bootstrap.
 *
 * NOT the plugin bootstrap. `acrossai-mcp-manager.php` opens with
 * `if ( ! defined( 'WPINC' ) ) { die; }`, so listing it under `bootstrapFiles`
 * exits the process before analysis begins — PHPStan then reports success
 * having examined zero files, which is what it did for this project's entire
 * history until 2026-10-07 (F095 T085). Two dangling class references and a
 * fatal on `/authorize` shipped with the gate green.
 *
 * This file defines only what the analysed code reads at parse time. It must
 * never require the plugin.
 *
 * @package AcrossAI_MCP_Manager
 */

declare( strict_types = 1 );

// Guarded: the WordPress stubs extension defines some of these itself, and a
// redeclaration warning is fatal to PHPStan's child processes.
defined( 'WPINC' ) || define( 'WPINC', 'wp-includes' );
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

defined( 'ACROSSAI_MCP_MANAGER_VERSION' ) || define( 'ACROSSAI_MCP_MANAGER_VERSION', '0.4.0' );
defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_FILE' ) || define( 'ACROSSAI_MCP_MANAGER_PLUGIN_FILE', __DIR__ . '/acrossai-mcp-manager.php' );
defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_PATH' ) || define( 'ACROSSAI_MCP_MANAGER_PLUGIN_PATH', __DIR__ . '/' );
defined( 'ACROSSAI_MCP_MANAGER_PLUGIN_URL' ) || define( 'ACROSSAI_MCP_MANAGER_PLUGIN_URL', 'https://example.test/wp-content/plugins/acrossai-mcp-manager/' );
