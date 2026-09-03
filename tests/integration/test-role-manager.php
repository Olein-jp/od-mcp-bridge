<?php
/**
 * MCP role manager integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Role_Manager;

/** Tests lifecycle and least-privilege role behavior. */
class Test_OD_MCP_Bridge_Role_Manager extends WP_UnitTestCase {

	/** Restores the plugin-owned role after each test. */
	public function tear_down() {
		Role_Manager::install();
		parent::tear_down();
	}

	/** Confirms the dedicated role has read-only plugin capabilities only. */
	public function test_install_creates_least_privilege_role() {
		Role_Manager::install();
		$role = get_role( Role_Manager::ROLE );

		$this->assertInstanceOf( WP_Role::class, $role );
		$this->assertTrue( $role->has_cap( 'read' ) );
		foreach ( Role_Manager::get_capabilities() as $capability ) {
			$this->assertTrue( $role->has_cap( $capability ) );
			$this->assertTrue( get_role( 'administrator' )->has_cap( $capability ) );
		}
		$this->assertFalse( $role->has_cap( Role_Manager::CREATE_TEMPLATE_PARTS ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Role_Manager::CREATE_TEMPLATE_PARTS ) );

		foreach ( array( 'edit_posts', 'activate_plugins', 'switch_themes', 'manage_options', 'update_core', 'update_plugins', 'update_themes' ) as $capability ) {
			$this->assertFalse( $role->has_cap( $capability ) );
		}
	}

	/** Confirms uninstall removes only plugin-owned role state and capabilities. */
	public function test_uninstall_removes_role_and_custom_capabilities() {
		Role_Manager::install();
		Role_Manager::uninstall();

		$this->assertNull( get_role( Role_Manager::ROLE ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Role_Manager::VIEW_MAINTENANCE ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Role_Manager::CREATE_TEMPLATE_PARTS ) );
		$this->assertFalse( get_option( Role_Manager::OPTION_NAME ) );
	}
}
