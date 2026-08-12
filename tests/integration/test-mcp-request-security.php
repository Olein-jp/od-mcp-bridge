<?php
/**
 * MCP HTTP request security integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Mcp_Request_Security;
use Olein\MCPBridge\OAuth\Resource_Server;

/** Tests MCP Origin validation. */
class Test_OD_MCP_Bridge_MCP_Request_Security extends WP_UnitTestCase {

	/**
	 * Custom allowed-origins filter used by a test.
	 *
	 * @var callable|null
	 */
	private $origins_filter;

	/** Restores custom Origin filters. */
	public function tear_down() {
		if ( $this->origins_filter ) {
			remove_filter( 'od_mcp_bridge_allowed_origins', $this->origins_filter );
		}
		$this->origins_filter = null;
		parent::tear_down();
	}

	/** Confirms non-browser clients without Origin remain supported. */
	public function test_missing_origin_is_allowed() {
		$request = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );

		$this->assertNull( ( new Mcp_Request_Security() )->validate_origin( null, null, $request ) );
	}

	/** Confirms the WordPress site Origin is trusted by default. */
	public function test_same_site_origin_is_allowed() {
		$request = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );
		$request->set_header( 'origin', home_url( '/' ) );

		$this->assertNull( ( new Mcp_Request_Security() )->validate_origin( null, null, $request ) );
	}

	/** Confirms an untrusted browser Origin receives HTTP 403. */
	public function test_untrusted_origin_is_rejected() {
		$request = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );
		$request->set_header( 'origin', 'https://attacker.example' );

		$response = ( new Mcp_Request_Security() )->validate_origin( null, null, $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mcp_invalid_origin', $response->get_data()['code'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	/** Confirms integrations can add an exact trusted Origin without wildcards. */
	public function test_filtered_exact_origin_is_allowed() {
		$this->origins_filter = static function ( $origins ) {
			$origins[] = 'https://client.example:443';
			return $origins;
		};
		add_filter( 'od_mcp_bridge_allowed_origins', $this->origins_filter );

		$request = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );
		$request->set_header( 'origin', 'https://client.example' );

		$this->assertNull( ( new Mcp_Request_Security() )->validate_origin( null, null, $request ) );
	}

	/** Confirms Origin validation does not affect unrelated REST routes. */
	public function test_unrelated_route_is_ignored() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts' );
		$request->set_header( 'origin', 'https://attacker.example' );

		$this->assertNull( ( new Mcp_Request_Security() )->validate_origin( null, null, $request ) );
	}

	/** Confirms normalization rejects credentials and non-HTTP schemes. */
	public function test_origin_normalization_is_strict() {
		$security = new Mcp_Request_Security();

		$this->assertSame( '', $security->normalize_origin( 'javascript:alert(1)' ) );
		$this->assertSame( '', $security->normalize_origin( 'https://user@example.com' ) );
		$this->assertSame( 'https://example.com', $security->normalize_origin( 'HTTPS://EXAMPLE.COM:443/' ) );
		$this->assertSame( '', $security->normalize_origin( 'https://example.com/path' ) );
	}
}
