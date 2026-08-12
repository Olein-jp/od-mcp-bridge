<?php
/**
 * OAuth scope policy for MCP abilities.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge\OAuth;

/** Maps MCP operations and abilities to OAuth scopes. */
final class Scope_Policy {

	/** Baseline scope required for MCP protocol and discovery requests. */
	const DISCOVER = 'od-mcp:discover';

	/** Scope for public content abilities. */
	const CONTENT_READ = 'od-mcp:content:read';

	/** Scope for maintenance abilities. */
	const MAINTENANCE_READ = 'od-mcp:maintenance:read';

	/**
	 * Returns every scope currently supported by the resource server.
	 *
	 * @return array<int, string>
	 */
	public function get_supported_scopes() {
		return array(
			self::DISCOVER,
			self::CONTENT_READ,
			self::MAINTENANCE_READ,
		);
	}

	/**
	 * Returns the scope required to execute an ability.
	 *
	 * Unmapped third-party abilities are denied by default. Integrations may map
	 * them explicitly with the od_mcp_bridge_oauth_ability_scope filter.
	 *
	 * @param string $ability_name Fully qualified ability name.
	 * @return string|null
	 */
	public function get_ability_scope( $ability_name ) {
		$content = array(
			'od-mcp-bridge/get-site-info',
			'od-mcp-bridge/get-posts',
			'od-mcp-bridge/get-post',
			'od-mcp-bridge/get-pages',
			'od-mcp-bridge/get-page',
			'od-mcp-bridge/get-terms',
		);

		$maintenance = array(
			'od-mcp-bridge/get-update-status',
			'od-mcp-bridge/get-plugins',
			'od-mcp-bridge/get-themes',
			'od-mcp-bridge/get-site-health',
			'od-mcp-bridge/get-content-summary',
			'od-mcp-bridge/get-stale-content',
			'od-mcp-bridge/get-cron-status',
			'od-mcp-bridge/get-maintenance-snapshot',
			'od-mcp-bridge/get-security-posture',
		);

		$scope = null;
		if ( in_array( $ability_name, $content, true ) ) {
			$scope = self::CONTENT_READ;
		} elseif ( in_array( $ability_name, $maintenance, true ) ) {
			$scope = self::MAINTENANCE_READ;
		}

		/**
		 * Filters the OAuth scope required for an MCP ability.
		 *
		 * Returning null denies execution through OAuth.
		 *
		 * @param string|null $scope        Required scope or null to deny.
		 * @param string      $ability_name Fully qualified ability name.
		 */
		$scope = apply_filters( 'od_mcp_bridge_oauth_ability_scope', $scope, $ability_name );

		return is_string( $scope ) && '' !== $scope ? $scope : null;
	}
}
