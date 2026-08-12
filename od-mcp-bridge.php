<?php
/**
 * Plugin Name:       OD MCP Bridge
 * Description:       Safely exposes selected WordPress abilities to MCP clients.
 * Version:           0.3.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Koji Kuno
 * Author URI:        https://olein-design.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       od-mcp-bridge
 * Update URI:        https://github.com/Olein-jp/od-mcp-bridge
 *
 * @package OdMcpBridge
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/vendor/autoload_packages.php';

define( 'OD_MCP_BRIDGE_VERSION', '0.3.0' );

register_activation_hook( __FILE__, array( Olein\MCPBridge\Role_Manager::class, 'install' ) );
register_uninstall_hook( __FILE__, array( Olein\MCPBridge\Role_Manager::class, 'uninstall' ) );

/**
 * Initializes the plugin.
 *
 * @return void
 */
function od_mcp_bridge_initialize_plugin() {
	Olein\MCPBridge\Plugin::boot();
}
add_action( 'plugins_loaded', 'od_mcp_bridge_initialize_plugin' );

/**
 * Initializes the GitHub-based plugin updater.
 *
 * @return void
 */
function od_mcp_bridge_initialize_updater() {
	new Inc2734\WP_GitHub_Plugin_Updater\Bootstrap(
		plugin_basename( __FILE__ ),
		'Olein-jp',
		'od-mcp-bridge',
		array(
			'tested'       => '7.0',
			'requires'     => '6.9',
			'requires_php' => '7.4',
		)
	);
}
add_action( 'plugins_loaded', 'od_mcp_bridge_initialize_updater' );
