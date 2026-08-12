<?php
/**
 * Plugin translation integration tests.
 *
 * @package OdMcpBridge
 */

/** Tests the bundled translation catalog. */
class Test_OD_MCP_Bridge_Translations extends WP_UnitTestCase {

	/** Confirms the Japanese catalog can be loaded by WordPress. */
	public function test_bundled_japanese_translation_loads() {
		unload_textdomain( 'od-mcp-bridge' );

		$loaded = load_textdomain(
			'od-mcp-bridge',
			dirname( __DIR__, 2 ) . '/languages/od-mcp-bridge-ja.mo'
		);

		$this->assertTrue( $loaded );
		$this->assertSame( '認証', __( 'Authentication', 'od-mcp-bridge' ) );
		$this->assertSame( '接続診断', __( 'Connection diagnostics', 'od-mcp-bridge' ) );

		unload_textdomain( 'od-mcp-bridge' );
	}
}
