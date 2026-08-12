<?php
/**
 * OAuth 2.1 resource server integration for the MCP endpoint.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge\OAuth;

use Olein\MCPBridge\Admin\Settings_Page;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Authenticates Bearer tokens and enforces OAuth scopes before MCP dispatch. */
final class Resource_Server {

	/** MCP REST route protected by this resource server. */
	const MCP_ROUTE = '/mcp/mcp-adapter-default-server';

	/** Protected Resource Metadata REST route. */
	const METADATA_ROUTE = '/od-mcp-bridge/v1/oauth-protected-resource';

	/**
	 * Plugin settings.
	 *
	 * @var Settings_Page
	 */
	private $settings;

	/**
	 * Access token validator.
	 *
	 * @var Jwt_Validator
	 */
	private $validator;

	/**
	 * OAuth subject mapper.
	 *
	 * @var User_Mapper
	 */
	private $user_mapper;

	/**
	 * Ability scope policy.
	 *
	 * @var Scope_Policy
	 */
	private $scope_policy;

	/**
	 * Scopes in the active OAuth request.
	 *
	 * @var array<int,string>
	 */
	private $request_scopes = array();

	/**
	 * Whether the active MCP request used a valid OAuth Bearer token.
	 *
	 * @var bool
	 */
	private $oauth_authenticated = false;

	/**
	 * Constructor.
	 *
	 * @param Settings_Page $settings Plugin settings.
	 * @param Jwt_Validator $validator Token validator.
	 * @param User_Mapper   $user_mapper Subject mapper.
	 * @param Scope_Policy  $scope_policy Scope policy.
	 */
	public function __construct( Settings_Page $settings, Jwt_Validator $validator, User_Mapper $user_mapper, Scope_Policy $scope_policy ) {
		$this->settings     = $settings;
		$this->validator    = $validator;
		$this->user_mapper  = $user_mapper;
		$this->scope_policy = $scope_policy;
	}

	/** Registers REST hooks. */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_dispatch', array( $this, 'protect_mcp_request' ), 5, 3 );
	}

	/** Registers public Protected Resource Metadata. */
	public function register_routes() {
		register_rest_route(
			'od-mcp-bridge/v1',
			'/oauth-protected-resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Returns RFC 9728 Protected Resource Metadata.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_resource_metadata() {
		if ( ! $this->settings->is_oauth_configured() ) {
			return new WP_Error( 'oauth_not_configured', __( 'OAuth resource server settings are incomplete.', 'od-mcp-bridge' ), array( 'status' => 503 ) );
		}

		$response = new WP_REST_Response(
			array(
				'resource'                              => $this->settings->get_oauth_resource(),
				'authorization_servers'                 => array( $this->settings->get_oauth_issuer() ),
				'scopes_supported'                      => $this->scope_policy->get_supported_scopes(),
				'bearer_methods_supported'              => array( 'header' ),
				'resource_name'                         => get_bloginfo( 'name' ) . ' MCP',
				'resource_documentation'                => rest_url( 'od-mcp-bridge/v1/oauth-protected-resource' ),
				'resource_signing_alg_values_supported' => array( 'RS256' ),
			)
		);
		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * Authenticates and authorizes one MCP REST request before Adapter dispatch.
	 *
	 * @param mixed           $result  Previous pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public function protect_mcp_request( $result, $server, $request ) {
		if ( null !== $result || self::MCP_ROUTE !== $request->get_route() ) {
			return $result;
		}

		$this->request_scopes      = array();
		$this->oauth_authenticated = false;

		$mode = $this->settings->get_auth_mode();
		if ( Settings_Page::AUTH_APPLICATION_PASSWORD === $mode ) {
			return $result;
		}

		if ( ! $this->settings->is_oauth_configured() ) {
			return $this->error_response( 'oauth_not_configured', __( 'OAuth resource server settings are incomplete.', 'od-mcp-bridge' ), 503 );
		}

		$authorization = trim( (string) $request->get_header( 'authorization' ) );
		if ( ! preg_match( '/^Bearer\s+([^\s]+)$/i', $authorization, $matches ) ) {
			if ( Settings_Page::AUTH_BOTH === $mode && get_current_user_id() > 0 ) {
				return $result;
			}

			$error = '' === $authorization ? '' : 'invalid_request';

			return $this->oauth_error_response( $error, __( 'A Bearer access token is required.', 'od-mcp-bridge' ), 401, array(), 'oauth_token_required' );
		}

		$claims = $this->validator->validate( $matches[1] );
		if ( is_wp_error( $claims ) ) {
			return $this->oauth_error_response( 'invalid_token', __( 'The Bearer access token is invalid or expired.', 'od-mcp-bridge' ), 401 );
		}

		$user_id = $this->user_mapper->map( (string) $claims['iss'], (string) $claims['sub'], $claims );
		if ( is_wp_error( $user_id ) ) {
			return $this->oauth_error_response( 'invalid_token', __( 'The Bearer identity is not available on this site.', 'od-mcp-bridge' ), 401 );
		}

		wp_set_current_user( $user_id );
		$this->request_scopes      = isset( $claims['od_mcp_scopes'] ) && is_array( $claims['od_mcp_scopes'] ) ? array_map( 'strval', $claims['od_mcp_scopes'] ) : array();
		$this->oauth_authenticated = true;

		$required      = array( Scope_Policy::DISCOVER );
		$ability_names = $this->get_requested_ability_names( $request );
		foreach ( $ability_names as $ability_name ) {
			$scope = $this->scope_policy->get_ability_scope( $ability_name );
			if ( null === $scope ) {
				return $this->error_response( 'oauth_unmapped_ability', __( 'This ability is not enabled for OAuth access.', 'od-mcp-bridge' ), 403 );
			}
			$required[] = $scope;
		}

		$missing = array_values( array_diff( array_unique( $required ), $this->request_scopes ) );
		if ( $missing ) {
			return $this->oauth_error_response( 'insufficient_scope', __( 'The Bearer access token does not include the required scope.', 'od-mcp-bridge' ), 403, $missing );
		}

		return $result;
	}

	/** Returns whether a Bearer token authenticated the active MCP request. */
	public function is_oauth_authenticated() {
		return $this->oauth_authenticated;
	}

	/**
	 * Returns the normalized scopes for the active MCP request.
	 *
	 * @return array<int,string>
	 */
	public function get_request_scopes() {
		return $this->request_scopes;
	}

	/**
	 * Extracts target ability names from JSON-RPC tool calls.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<int,string>
	 */
	private function get_requested_ability_names( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			return array();
		}

		$messages  = isset( $payload['method'] ) ? array( $payload ) : $payload;
		$abilities = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || 'tools/call' !== ( $message['method'] ?? '' ) ) {
				continue;
			}
			$params    = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
			$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
			if (
				in_array( ( $params['name'] ?? '' ), array( 'mcp-adapter-execute-ability', 'mcp-adapter-get-ability-info' ), true ) &&
				isset( $arguments['ability_name'] ) &&
				is_string( $arguments['ability_name'] )
			) {
				$abilities[] = $arguments['ability_name'];
			}
		}

		return array_values( array_unique( $abilities ) );
	}

	/**
	 * Creates an OAuth error response with a Bearer challenge.
	 *
	 * @param string            $error       OAuth error code.
	 * @param string            $description Public error description.
	 * @param int               $status      HTTP status.
	 * @param array<int,string> $scopes      Required scopes.
	 * @param string            $response_code Optional REST response code.
	 * @return WP_REST_Response
	 */
	private function oauth_error_response( $error, $description, $status, $scopes = array(), $response_code = '' ) {
		$response = $this->error_response( '' !== $response_code ? $response_code : $error, $description, $status );
		$parts    = array( 'Bearer resource_metadata="' . $this->escape_challenge_value( rest_url( 'od-mcp-bridge/v1/oauth-protected-resource' ) ) . '"' );
		if ( '' !== $error ) {
			$parts[] = 'error="' . $this->escape_challenge_value( $error ) . '"';
		}
		if ( $scopes ) {
			$parts[] = 'scope="' . $this->escape_challenge_value( implode( ' ', $scopes ) ) . '"';
		}
		$response->header( 'WWW-Authenticate', implode( ', ', $parts ) );

		return $response;
	}

	/**
	 * Creates a non-cacheable REST error response.
	 *
	 * @param string $error       Error code.
	 * @param string $description Public error description.
	 * @param int    $status      HTTP status.
	 * @return WP_REST_Response
	 */
	private function error_response( $error, $description, $status ) {
		$response = new WP_REST_Response(
			array(
				'code'    => $error,
				'message' => $description,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Escapes an OAuth challenge quoted-string value.
	 *
	 * @param string $value Unescaped value.
	 * @return string
	 */
	private function escape_challenge_value( $value ) {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value );
	}
}
