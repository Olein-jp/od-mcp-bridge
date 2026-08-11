<?php
/**
 * Plugin Name:       OD MCP Bridge
 * Description:       Provides the OD MCP Bridge plugin foundation.
 * Version:           0.0.0
 * Requires at least: 5.9
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

require_once __DIR__ . '/vendor/autoload.php';

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
			'requires'     => '5.9',
			'requires_php' => '7.4',
		)
	);
}
add_action( 'plugins_loaded', 'od_mcp_bridge_initialize_updater' );
