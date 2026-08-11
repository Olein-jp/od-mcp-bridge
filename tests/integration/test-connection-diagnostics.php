<?php
/**
 * Connection diagnostics integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Admin\Connection_Diagnostics;
use Olein\MCPBridge\Admin\Settings_Page;
use Olein\MCPBridge\Role_Manager;

/** Tests local configuration and role diagnostics. */
class Test_OD_MCP_Bridge_Connection_Diagnostics extends WP_UnitTestCase {

	/** Restores plugin settings and role state. */
	public function tear_down() {
		delete_option( Settings_Page::OPTION_NAME );
		Role_Manager::install();
		parent::tear_down();
	}

	/** Confirms checks are structured and contain no credential values. */
	public function test_get_checks_reports_local_readiness_only() {
		$diagnostics = new Connection_Diagnostics( new Settings_Page() );
		$checks      = $diagnostics->get_checks();

		$this->assertSame( 'good', $checks['mcp_adapter']['status'] );
		$this->assertSame( 'good', $checks['wordpress_version']['status'] );
		$this->assertSame( 'good', $checks['php_version']['status'] );
		$this->assertSame( 'good', $checks['maintenance_role']['status'] );
		$this->assertStringContainsString( '6 of 14', $checks['enabled_abilities']['message'] );
		$this->assertStringNotContainsString( 'password=', strtolower( wp_json_encode( $checks ) ) );
	}

	/** Confirms a damaged dedicated role is reported as an error. */
	public function test_get_checks_detects_unsafe_role_capabilities() {
		$role = get_role( Role_Manager::ROLE );
		$role->add_cap( 'manage_options' );

		$checks = ( new Connection_Diagnostics( new Settings_Page() ) )->get_checks();
		$this->assertSame( 'error', $checks['maintenance_role']['status'] );

		$role->remove_cap( 'manage_options' );
	}

	/** Confirms every ability exposes its setting and capability policy. */
	public function test_get_ability_access_covers_every_ability() {
		$rows = ( new Connection_Diagnostics( new Settings_Page() ) )->get_ability_access();

		$this->assertCount( 14, $rows );
		$this->assertCount( 6, wp_list_filter( $rows, array( 'enabled' => true ) ) );
		$this->assertCount( 14, wp_list_filter( $rows, array( 'role_access' => true ) ) );
	}
}
