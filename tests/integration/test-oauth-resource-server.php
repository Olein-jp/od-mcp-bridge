<?php
/**
 * OAuth resource server integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Admin\Settings_Page;
use Olein\MCPBridge\OAuth\Jwt_Validator;
use Olein\MCPBridge\OAuth\Resource_Server;
use Olein\MCPBridge\OAuth\Scope_Aware_Discovery;
use Olein\MCPBridge\OAuth\Scope_Policy;
use Olein\MCPBridge\OAuth\User_Mapper;

/** Tests OAuth settings, token validation, mapping, and scope challenges. */
class Test_OD_MCP_Bridge_OAuth_Resource_Server extends WP_UnitTestCase {

	/**
	 * Token validation filter callback.
	 *
	 * @var callable|null
	 */
	private $token_filter;

	/** Restores authentication state and plugin options. */
	public function tear_down() {
		if ( $this->token_filter ) {
			remove_filter( 'od_mcp_bridge_oauth_validate_token', $this->token_filter );
		}
		$this->token_filter = null;
		delete_option( Settings_Page::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/** Confirms authentication settings use safe defaults and sanitize remote HTTP. */
	public function test_settings_default_to_application_password_and_require_https() {
		$settings  = new Settings_Page();
		$sanitized = $settings->sanitize_settings(
			array(
				'oauth' => array(
					'mode'     => Settings_Page::AUTH_OAUTH,
					'issuer'   => 'http://auth.example.com',
					'jwks_uri' => 'https://auth.example.com/jwks',
					'resource' => 'https://site.example.com/wp-json/mcp/mcp-adapter-default-server',
				),
			)
		);

		$this->assertSame( Settings_Page::AUTH_APPLICATION_PASSWORD, $settings->get_auth_mode() );
		$this->assertSame( '', $sanitized['oauth']['issuer'] );
		$this->assertSame( 'https://auth.example.com/jwks', $sanitized['oauth']['jwks_uri'] );
	}

	/** Confirms Protected Resource Metadata advertises the configured issuer and scopes. */
	public function test_resource_metadata_matches_oauth_configuration() {
		$server   = $this->create_resource_server();
		$response = $server->get_resource_metadata();
		$data     = $response->get_data();

		$this->assertSame( 'https://site.example.com/mcp', $data['resource'] );
		$this->assertSame( array( 'https://auth.example.com' ), $data['authorization_servers'] );
		$this->assertContains( Scope_Policy::DISCOVER, $data['scopes_supported'] );
		$this->assertContains( Scope_Policy::MAINTENANCE_READ, $data['scopes_supported'] );
	}

	/** Confirms an OAuth-only endpoint returns a discoverable Bearer challenge. */
	public function test_missing_bearer_token_returns_401_challenge() {
		$server   = $this->create_resource_server();
		$request  = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );
		$response = $server->protect_mcp_request( null, null, $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertStringContainsString( 'resource_metadata=', $response->get_headers()['WWW-Authenticate'] );
		$this->assertStringNotContainsString( 'error=', $response->get_headers()['WWW-Authenticate'] );
		$this->assertSame( 'oauth_token_required', $response->get_data()['code'] );
	}

	/** Confirms a valid identity still receives a 403 challenge when an ability scope is missing. */
	public function test_missing_ability_scope_returns_403_challenge() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->map_user( $user_id );
		$this->filter_validated_claims( array( Scope_Policy::DISCOVER ) );

		$response = $this->create_resource_server()->protect_mcp_request( null, null, $this->create_ability_request( 'od-mcp-bridge/get-posts' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertStringContainsString( 'error="insufficient_scope"', $response->get_headers()['WWW-Authenticate'] );
		$this->assertStringContainsString( Scope_Policy::CONTENT_READ, $response->get_headers()['WWW-Authenticate'] );
	}

	/** Confirms valid scopes map the subject and allow Adapter permission checks to continue. */
	public function test_valid_scopes_set_current_user_and_continue_dispatch() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->map_user( $user_id );
		$this->filter_validated_claims( array( Scope_Policy::DISCOVER, Scope_Policy::CONTENT_READ ) );

		$result = $this->create_resource_server()->protect_mcp_request( null, null, $this->create_ability_request( 'od-mcp-bridge/get-posts' ) );

		$this->assertNull( $result );
		$this->assertSame( $user_id, get_current_user_id() );
		$this->assertTrue( current_user_can( 'read' ) );
	}

	/** Confirms OAuth refuses public abilities without an explicit scope mapping. */
	public function test_unmapped_ability_is_denied() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->map_user( $user_id );
		$this->filter_validated_claims( array( Scope_Policy::DISCOVER ) );

		$response = $this->create_resource_server()->protect_mcp_request( null, null, $this->create_ability_request( 'other-plugin/dangerous-operation' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'oauth_unmapped_ability', $response->get_data()['code'] );
	}

	/** Confirms OAuth discovery returns only abilities covered by token scopes. */
	public function test_discovery_result_is_limited_to_token_scopes() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->map_user( $user_id );
		$this->filter_validated_claims( array( Scope_Policy::DISCOVER, Scope_Policy::CONTENT_READ ) );

		$server  = $this->create_resource_server();
		$request = $this->create_tool_request( 'mcp-adapter-discover-abilities' );
		$this->assertNull( $server->protect_mcp_request( null, null, $request ) );

		$discovery = new Scope_Aware_Discovery( $server, new Scope_Policy() );
		$result    = $discovery->filter_discovery_result(
			array(
				'abilities' => array(
					array( 'name' => 'od-mcp-bridge/get-posts' ),
					array( 'name' => 'od-mcp-bridge/get-update-status' ),
					array( 'name' => 'other-plugin/public-operation' ),
				),
			),
			array(),
			'mcp-adapter-discover-abilities'
		);

		$this->assertSame( array( array( 'name' => 'od-mcp-bridge/get-posts' ) ), $result['abilities'] );
	}

	/** Confirms get-ability-info cannot reveal an ability outside token scopes. */
	public function test_get_ability_info_requires_the_target_scope() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->map_user( $user_id );
		$this->filter_validated_claims( array( Scope_Policy::DISCOVER, Scope_Policy::CONTENT_READ ) );

		$response = $this->create_resource_server()->protect_mcp_request(
			null,
			null,
			$this->create_tool_request( 'mcp-adapter-get-ability-info', 'od-mcp-bridge/get-update-status' )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertStringContainsString( Scope_Policy::MAINTENANCE_READ, $response->get_headers()['WWW-Authenticate'] );
	}

	/**
	 * Creates a configured OAuth resource server.
	 *
	 * @return Resource_Server
	 */
	private function create_resource_server() {
		update_option(
			Settings_Page::OPTION_NAME,
			array(
				'oauth' => array(
					'mode'     => Settings_Page::AUTH_OAUTH,
					'issuer'   => 'https://auth.example.com',
					'jwks_uri' => 'https://auth.example.com/jwks',
					'resource' => 'https://site.example.com/mcp',
				),
			)
		);

		$settings = new Settings_Page();

		return new Resource_Server( $settings, new Jwt_Validator( $settings ), new User_Mapper( $settings ), new Scope_Policy() );
	}

	/**
	 * Maps a test user to the configured issuer and subject.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function map_user( $user_id ) {
		update_user_meta( $user_id, User_Mapper::META_SUBJECT, 'subject-123' );
		update_user_meta( $user_id, User_Mapper::META_IDENTITY, User_Mapper::get_identity_key( 'https://auth.example.com', 'subject-123' ) );
	}

	/**
	 * Creates an MCP execute-ability request.
	 *
	 * @param string $ability_name Ability name.
	 * @return WP_REST_Request
	 */
	private function create_ability_request( $ability_name ) {
		return $this->create_tool_request( 'mcp-adapter-execute-ability', $ability_name );
	}

	/**
	 * Creates an MCP wrapper-tool request.
	 *
	 * @param string $tool_name    MCP wrapper tool name.
	 * @param string $ability_name Target ability name.
	 * @return WP_REST_Request
	 */
	private function create_tool_request( $tool_name, $ability_name = '' ) {
		$request = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );
		$request->set_header( 'authorization', 'Bearer opaque-test-token' );
		$request->set_header( 'content-type', 'application/json' );
		$arguments = array();
		if ( '' !== $ability_name ) {
			$arguments['ability_name'] = $ability_name;
			if ( 'mcp-adapter-execute-ability' === $tool_name ) {
				$arguments['parameters'] = array();
			}
		}
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => $tool_name,
						'arguments' => $arguments,
					),
				)
			)
		);

		return $request;
	}

	/**
	 * Installs a deterministic validated-claims result for resource tests.
	 *
	 * @param array<int,string> $scopes Valid OAuth scopes.
	 */
	private function filter_validated_claims( $scopes ) {
		$this->token_filter = static function () use ( $scopes ) {
			return array(
				'iss'           => 'https://auth.example.com',
				'sub'           => 'subject-123',
				'aud'           => 'https://site.example.com/mcp',
				'exp'           => time() + 300,
				'od_mcp_scopes' => $scopes,
			);
		};
		add_filter( 'od_mcp_bridge_oauth_validate_token', $this->token_filter );
	}
}
