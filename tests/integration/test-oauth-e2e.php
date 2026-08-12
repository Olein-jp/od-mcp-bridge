<?php
/**
 * OAuth MCP REST end-to-end integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Admin\Settings_Page;
use Olein\MCPBridge\OAuth\Resource_Server;
use Olein\MCPBridge\OAuth\Scope_Policy;
use Olein\MCPBridge\OAuth\User_Mapper;
use WP\MCP\Core\McpAdapter;

/** Exercises signed Bearer tokens through the real WordPress REST route. */
class Test_OD_MCP_Bridge_OAuth_E2E extends WP_UnitTestCase {

	/**
	 * Test RSA private key.
	 *
	 * @var resource|OpenSSLAsymmetricKey
	 */
	private $private_key;

	/**
	 * Mock JWKS HTTP callback.
	 *
	 * @var callable|null
	 */
	private $http_filter;

	/** Creates one configured resource server identity and signing key. */
	public function set_up() {
		parent::set_up();
		$this->private_key = openssl_pkey_new(
			array(
				'digest_alg'       => 'sha256',
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		$details           = openssl_pkey_get_details( $this->private_key );
		$jwks              = array(
			'keys' => array(
				array(
					'kty' => 'RSA',
					'use' => 'sig',
					'alg' => 'RS256',
					'kid' => 'e2e-key',
					'n'   => $this->base64url_encode( $details['rsa']['n'] ),
					'e'   => $this->base64url_encode( $details['rsa']['e'] ),
				),
			),
		);

		$this->http_filter = static function ( $preempt, $args, $url ) use ( $jwks ) {
			if ( 'https://auth.example.com/jwks' !== $url ) {
				return $preempt;
			}

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $jwks ),
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );

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

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_user_meta( $user_id, User_Mapper::META_SUBJECT, 'e2e-subject' );
		update_user_meta( $user_id, User_Mapper::META_IDENTITY, User_Mapper::get_identity_key( 'https://auth.example.com', 'e2e-subject' ) );

		$this->register_adapter_abilities();
	}

	/** Restores OAuth configuration, caches, and authentication state. */
	public function tear_down() {
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		delete_transient( 'od_mcp_jwks_' . md5( 'https://auth.example.com/jwks' ) );
		delete_option( Settings_Page::OPTION_NAME );
		wp_set_current_user( 0 );
		foreach ( array( 'mcp-adapter/discover-abilities', 'mcp-adapter/get-ability-info', 'mcp-adapter/execute-ability' ) as $ability_name ) {
			if ( wp_get_ability( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}
		parent::tear_down();
	}

	/** Confirms discovery, signature validation, mapping, scope, and execution work together. */
	public function test_signed_token_executes_ability_through_rest_route() {
		$metadata = rest_get_server()->dispatch( new WP_REST_Request( 'GET', Resource_Server::METADATA_ROUTE ) );
		$this->assertSame( 200, $metadata->get_status() );
		$this->assertSame( 'https://auth.example.com', $metadata->get_data()['authorization_servers'][0] );

		$unauthenticated = rest_get_server()->dispatch( $this->create_execute_request() );
		$this->assertSame( 401, $unauthenticated->get_status() );
		$this->assertSame( 'oauth_token_required', $unauthenticated->get_data()['code'] );

		$insufficient = rest_get_server()->dispatch( $this->create_execute_request( $this->sign_token( array( Scope_Policy::DISCOVER ) ) ) );
		$this->assertSame( 403, $insufficient->get_status() );
		$this->assertStringContainsString( Scope_Policy::CONTENT_READ, $insufficient->get_headers()['WWW-Authenticate'] );

		$token              = $this->sign_token( array( Scope_Policy::DISCOVER, Scope_Policy::CONTENT_READ ) );
		$initialize_request = $this->create_initialize_request( $token );
		$initialize         = rest_get_server()->dispatch( $initialize_request );
		$initialize         = apply_filters( 'rest_post_dispatch', $initialize, rest_get_server(), $initialize_request );
		$headers            = $initialize->get_headers();
		$this->assertSame( 200, $initialize->get_status(), wp_json_encode( $initialize->get_data() ) );
		$session_id = isset( $headers['Mcp-Session-Id'] ) ? $headers['Mcp-Session-Id'] : ( $headers['mcp-session-id'] ?? '' );
		$this->assertNotEmpty( $session_id, wp_json_encode( $headers ) );

		$authorized = rest_get_server()->dispatch(
			$this->create_execute_request(
				$token,
				$session_id
			)
		);
		$this->assertSame( 200, $authorized->get_status(), wp_json_encode( $authorized->get_data() ) );
		$this->assertSame( '2.0', $authorized->get_data()['jsonrpc'] );
		$this->assertArrayHasKey( 'result', $authorized->get_data() );
		$this->assertStringContainsString( get_bloginfo( 'name' ), wp_json_encode( $authorized->get_data()['result'] ) );
	}

	/**
	 * Creates an MCP execute request, optionally with a Bearer token.
	 *
	 * @param string $token      Access token.
	 * @param string $session_id MCP session ID.
	 * @return WP_REST_Request
	 */
	private function create_execute_request( $token = '', $session_id = '' ) {
		$request = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		if ( '' !== $token ) {
			$request->set_header( 'authorization', 'Bearer ' . $token );
		}
		if ( '' !== $session_id ) {
			$request->set_header( 'mcp-session-id', $session_id );
		}
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'mcp-adapter-execute-ability',
						'arguments' => array(
							'ability_name' => 'od-mcp-bridge/get-site-info',
							'parameters'   => array(),
						),
					),
				)
			)
		);

		return $request;
	}

	/**
	 * Creates an authenticated MCP initialize request.
	 *
	 * @param string $token Access token.
	 * @return WP_REST_Request
	 */
	private function create_initialize_request( $token ) {
		$request = new WP_REST_Request( 'POST', Resource_Server::MCP_ROUTE );
		$request->set_header( 'authorization', 'Bearer ' . $token );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'protocolVersion' => '2025-06-18',
						'capabilities'    => array(),
						'clientInfo'      => array(
							'name'    => 'od-mcp-bridge-test',
							'version' => '1.0.0',
						),
					),
				)
			)
		);

		return $request;
	}

	/**
	 * Signs an RS256 access token containing the requested scopes.
	 *
	 * @param array<int,string> $scopes OAuth scopes.
	 * @return string
	 */
	private function sign_token( $scopes ) {
		$header  = $this->base64url_encode(
			wp_json_encode(
				array(
					'alg' => 'RS256',
					'kid' => 'e2e-key',
					'typ' => 'JWT',
				)
			)
		);
		$payload = $this->base64url_encode(
			wp_json_encode(
				array(
					'iss'   => 'https://auth.example.com',
					'sub'   => 'e2e-subject',
					'aud'   => 'https://site.example.com/mcp',
					'iat'   => time(),
					'exp'   => time() + 300,
					'scope' => implode( ' ', $scopes ),
				)
			)
		);
		openssl_sign( $header . '.' . $payload, $signature, $this->private_key, OPENSSL_ALGO_SHA256 );

		return $header . '.' . $payload . '.' . $this->base64url_encode( $signature );
	}

	/** Registers Adapter abilities on the lifecycle hooks required by WordPress. */
	private function register_adapter_abilities() {
		global $wp_filter;

		$adapter = McpAdapter::instance();
		$hooks   = array(
			'wp_abilities_api_categories_init' => array( $adapter, 'register_default_category' ),
			'wp_abilities_api_init'            => array( $adapter, 'register_default_abilities' ),
		);
		foreach ( $hooks as $hook_name => $callback ) {
			$original_hooks = isset( $wp_filter[ $hook_name ] ) ? $wp_filter[ $hook_name ] : null;
			remove_all_actions( $hook_name );
			add_action( $hook_name, $callback );
			try {
				do_action( $hook_name );
			} finally {
				if ( null === $original_hooks ) {
					unset( $wp_filter[ $hook_name ] );
				} else {
					$wp_filter[ $hook_name ] = $original_hooks;
				}
			}
		}
	}

	/**
	 * Encodes a JWT component with base64url.
	 *
	 * @param string $value Raw component.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT test encoding.
	}
}
