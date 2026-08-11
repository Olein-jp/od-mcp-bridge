<?php
/**
 * Main plugin bootstrap.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use Olein\MCPBridge\Admin\Settings_Page;
use WP\MCP\Core\McpAdapter;

/**
 * Registers the plugin services.
 */
final class Plugin {

	/**
	 * Boots the plugin once WordPress plugins are loaded.
	 *
	 * @return void
	 */
	public static function boot() {
		$settings  = new Settings_Page();
		$abilities = new Abilities( $settings );

		$settings->register_hooks();
		$abilities->register_hooks();

		if ( class_exists( McpAdapter::class ) ) {
			McpAdapter::instance();
		} else {
			add_action( 'admin_notices', array( self::class, 'render_adapter_notice' ) );
		}
	}

	/**
	 * Displays an error when the bundled MCP Adapter cannot be loaded.
	 *
	 * @return void
	 */
	public static function render_adapter_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'OD MCP Bridge could not load the required WordPress MCP Adapter.', 'od-mcp-bridge' ); ?>
			</p>
		</div>
		<?php
	}
}
