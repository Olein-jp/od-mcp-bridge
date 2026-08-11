<?php
/**
 * Read-only MCP connection diagnostics.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge\Admin;

use Olein\MCPBridge\Role_Manager;
use WP\MCP\Core\McpAdapter;

/** Builds local environment and ability access diagnostics. */
final class Connection_Diagnostics {

	/**
	 * Plugin settings.
	 *
	 * @var Settings_Page
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings_Page $settings Plugin settings.
	 */
	public function __construct( Settings_Page $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns local connection readiness checks.
	 *
	 * @return array<string, array{label: string, status: string, message: string}>
	 */
	public function get_checks() {
		$role         = get_role( Role_Manager::ROLE );
		$role_is_safe = $role && $role->has_cap( 'read' );
		foreach ( Role_Manager::get_capabilities() as $capability ) {
			$role_is_safe = $role_is_safe && $role->has_cap( $capability );
		}
		foreach ( array( 'activate_plugins', 'switch_themes', 'manage_options', 'update_core', 'update_plugins', 'update_themes' ) as $capability ) {
			$role_is_safe = $role_is_safe && ! $role->has_cap( $capability );
		}

		$enabled_count = 0;
		foreach ( $this->settings->get_ability_keys() as $key ) {
			$enabled_count += $this->settings->is_ability_enabled( $key ) ? 1 : 0;
		}

		return array(
			'mcp_adapter'           => $this->format_check(
				__( 'WordPress MCP Adapter', 'od-mcp-bridge' ),
				class_exists( McpAdapter::class ) ? 'good' : 'error',
				class_exists( McpAdapter::class ) ? __( 'The bundled adapter is loaded.', 'od-mcp-bridge' ) : __( 'The bundled adapter could not be loaded.', 'od-mcp-bridge' )
			),
			'wordpress_version'     => $this->format_check(
				__( 'WordPress version', 'od-mcp-bridge' ),
				version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) ? 'good' : 'error',
				sprintf(
					/* translators: %s: detected WordPress version. */
					__( 'Detected WordPress %s; version 6.9 or later is required.', 'od-mcp-bridge' ),
					get_bloginfo( 'version' )
				)
			),
			'php_version'           => $this->format_check(
				__( 'PHP version', 'od-mcp-bridge' ),
				version_compare( PHP_VERSION, '7.4', '>=' ) ? 'good' : 'error',
				sprintf(
					/* translators: %s: detected PHP version. */
					__( 'Detected PHP %s; version 7.4 or later is required.', 'od-mcp-bridge' ),
					PHP_VERSION
				)
			),
			'https'                 => $this->format_check(
				__( 'HTTPS', 'od-mcp-bridge' ),
				wp_is_using_https() ? 'good' : 'warning',
				wp_is_using_https() ? __( 'WordPress home and site URLs use HTTPS.', 'od-mcp-bridge' ) : __( 'Use HTTPS before connecting to a production site.', 'od-mcp-bridge' )
			),
			'application_passwords' => $this->format_check(
				__( 'Application Passwords', 'od-mcp-bridge' ),
				wp_is_application_passwords_available() ? 'good' : 'error',
				wp_is_application_passwords_available() ? __( 'Application Password authentication is available.', 'od-mcp-bridge' ) : __( 'Application Password authentication is unavailable in this environment.', 'od-mcp-bridge' )
			),
			'permalinks'            => $this->format_check(
				__( 'Permalinks', 'od-mcp-bridge' ),
				get_option( 'permalink_structure' ) ? 'good' : 'warning',
				get_option( 'permalink_structure' ) ? __( 'Pretty permalinks are enabled.', 'od-mcp-bridge' ) : __( 'Plain permalinks may prevent the displayed MCP endpoint URL from working.', 'od-mcp-bridge' )
			),
			'maintenance_role'      => $this->format_check(
				__( 'MCP Maintenance Reader role', 'od-mcp-bridge' ),
				$role_is_safe ? 'good' : 'error',
				$role_is_safe ? __( 'The dedicated role has all read-only MCP capabilities and no WordPress management capabilities.', 'od-mcp-bridge' ) : __( 'Reactivating the plugin should repair the dedicated role and its capabilities.', 'od-mcp-bridge' )
			),
			'enabled_abilities'     => $this->format_check(
				__( 'Enabled abilities', 'od-mcp-bridge' ),
				$enabled_count > 0 ? 'good' : 'warning',
				sprintf(
					/* translators: 1: enabled ability count, 2: total ability count. */
					__( '%1$d of %2$d abilities are enabled.', 'od-mcp-bridge' ),
					$enabled_count,
					count( $this->settings->get_ability_keys() )
				)
			),
		);
	}

	/**
	 * Returns configured ability and dedicated-role access rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_ability_access() {
		$requirements = Role_Manager::get_ability_requirements();
		$role         = get_role( Role_Manager::ROLE );
		$rows         = array();

		foreach ( $this->settings->get_ability_keys() as $key ) {
			$requirement = $requirements[ $key ];
			$matches     = 0;
			foreach ( $requirement['capabilities'] as $capability ) {
				$matches += $role && $role->has_cap( $capability ) ? 1 : 0;
			}
			$allowed = 'any' === $requirement['match'] ? $matches > 0 : count( $requirement['capabilities'] ) === $matches;

			$rows[] = array(
				'key'          => $key,
				'enabled'      => $this->settings->is_ability_enabled( $key ),
				'capabilities' => $requirement['capabilities'],
				'match'        => $requirement['match'],
				'role_access'  => $allowed,
			);
		}

		return $rows;
	}

	/**
	 * Formats one diagnostic row.
	 *
	 * @param string $label   Check label.
	 * @param string $status  Check status.
	 * @param string $message Check details.
	 * @return array{label: string, status: string, message: string}
	 */
	private function format_check( $label, $status, $message ) {
		return array(
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
		);
	}
}
