<?php
/**
 * MCP maintenance reader role management.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

/**
 * Provisions least-privilege capabilities for read-only maintenance abilities.
 */
final class Role_Manager {

	/** Dedicated maintenance reader role slug. */
	const ROLE = 'od_mcp_bridge_maintenance_reader';

	/** Role schema option name. */
	const OPTION_NAME = 'od_mcp_bridge_role_schema_version';

	/** Current role schema version. */
	const SCHEMA_VERSION = '2';

	/** Custom maintenance capabilities. */
	const VIEW_CORE_UPDATES   = 'od_mcp_bridge_view_core_updates';
	const VIEW_PLUGIN_UPDATES = 'od_mcp_bridge_view_plugin_updates';
	const VIEW_THEME_UPDATES  = 'od_mcp_bridge_view_theme_updates';
	const VIEW_PLUGINS        = 'od_mcp_bridge_view_plugins';
	const VIEW_THEMES         = 'od_mcp_bridge_view_themes';
	const VIEW_SITE_HEALTH    = 'od_mcp_bridge_view_site_health';
	const VIEW_CONTENT        = 'od_mcp_bridge_view_content_summary';
	const VIEW_CRON           = 'od_mcp_bridge_view_cron';
	const VIEW_MAINTENANCE    = 'od_mcp_bridge_view_maintenance';
	const VIEW_SECURITY       = 'od_mcp_bridge_view_security';

	/** Installs or upgrades the role schema when needed. */
	public static function maybe_install() {
		if ( self::SCHEMA_VERSION !== get_option( self::OPTION_NAME ) ) {
			self::install();
		}
	}

	/** Creates the dedicated role and grants its capabilities to administrators. */
	public static function install() {
		$role_capabilities = array( 'read' => true );
		foreach ( self::get_capabilities() as $capability ) {
			$role_capabilities[ $capability ] = true;
		}

		add_role(
			self::ROLE,
			__( 'MCP Maintenance Reader', 'od-mcp-bridge' ),
			$role_capabilities
		);

		$reader = get_role( self::ROLE );
		$admin  = get_role( 'administrator' );
		if ( $reader ) {
			$reader->add_cap( 'read' );
		}
		foreach ( self::get_capabilities() as $capability ) {
			if ( $reader ) {
				$reader->add_cap( $capability );
			}
			if ( $admin ) {
				$admin->add_cap( $capability );
			}
		}

		update_option( self::OPTION_NAME, self::SCHEMA_VERSION, false );
	}

	/** Removes plugin-owned roles, capabilities, and schema state on uninstall. */
	public static function uninstall() {
		remove_role( self::ROLE );

		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::get_capabilities() as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		delete_option( self::OPTION_NAME );
	}

	/**
	 * Returns all custom read-only maintenance capabilities.
	 *
	 * @return array<int, string>
	 */
	public static function get_capabilities() {
		return array(
			self::VIEW_CORE_UPDATES,
			self::VIEW_PLUGIN_UPDATES,
			self::VIEW_THEME_UPDATES,
			self::VIEW_PLUGINS,
			self::VIEW_THEMES,
			self::VIEW_SITE_HEALTH,
			self::VIEW_CONTENT,
			self::VIEW_CRON,
			self::VIEW_MAINTENANCE,
			self::VIEW_SECURITY,
		);
	}

	/**
	 * Returns the capability policy for every plugin ability.
	 *
	 * @return array<string, array{capabilities: array<int, string>, match: string}>
	 */
	public static function get_ability_requirements() {
		$read = array(
			'capabilities' => array( 'read' ),
			'match'        => 'all',
		);

		return array(
			'get-site-info'            => $read,
			'get-posts'                => $read,
			'get-post'                 => $read,
			'get-pages'                => $read,
			'get-page'                 => $read,
			'get-terms'                => $read,
			'create-post-draft'        => array(
				'capabilities' => array( 'edit_posts' ),
				'match'        => 'all',
			),
			'get-update-status'        => array(
				'capabilities' => array( self::VIEW_CORE_UPDATES, self::VIEW_PLUGIN_UPDATES, self::VIEW_THEME_UPDATES ),
				'match'        => 'any',
			),
			'get-plugins'              => array(
				'capabilities' => array( self::VIEW_PLUGINS ),
				'match'        => 'all',
			),
			'get-themes'               => array(
				'capabilities' => array( self::VIEW_THEMES ),
				'match'        => 'all',
			),
			'get-site-health'          => array(
				'capabilities' => array( self::VIEW_SITE_HEALTH ),
				'match'        => 'all',
			),
			'get-content-summary'      => array(
				'capabilities' => array( self::VIEW_CONTENT ),
				'match'        => 'all',
			),
			'get-stale-content'        => $read,
			'get-cron-status'          => array(
				'capabilities' => array( self::VIEW_CRON ),
				'match'        => 'all',
			),
			'get-maintenance-snapshot' => array(
				'capabilities' => array( self::VIEW_MAINTENANCE ),
				'match'        => 'all',
			),
			'get-security-posture'     => array(
				'capabilities' => array( self::VIEW_SECURITY ),
				'match'        => 'all',
			),
		);
	}
}
