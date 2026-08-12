<?php
/**
 * OAuth JWT validator integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Admin\Settings_Page;
use Olein\MCPBridge\OAuth\Jwt_Validator;

/** Tests built-in RS256 access token validation. */
class Test_OD_MCP_Bridge_OAuth_JWT_Validator extends WP_UnitTestCase {

	/**
	 * Test RSA private key.
	 *
	 * @var resource|OpenSSLAsymmetricKey
	 */
	private $private_key;

	/**
	 * Mock HTTP filter callback.
	 *
	 * @var callable|null
	 */
	private $http_filter;

	/** Creates an isolated RSA key pair and mocked JWKS response. */
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
					'kid' => 'test-key',
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
	}

	/** Restores HTTP filters and cached key data. */
	public function tear_down() {
		remove_filter( 'pre_http_request', $this->http_filter, 10 );
		delete_transient( 'od_mcp_jwks_' . md5( 'https://auth.example.com/jwks' ) );
		delete_option( Settings_Page::OPTION_NAME );
		parent::tear_down();
	}

	/** Confirms valid signatures and required claims produce normalized scopes. */
	public function test_valid_rs256_token_is_accepted() {
		$claims = $this->valid_claims();
		$result = ( new Jwt_Validator( new Settings_Page() ) )->validate( $this->sign_token( $claims ) );

		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'subject-123', $result['sub'] );
		$this->assertSame( array( 'od-mcp:discover', 'od-mcp:content:read' ), $result['od_mcp_scopes'] );
	}

	/** Confirms tokens issued for another MCP resource are rejected. */
	public function test_wrong_audience_is_rejected() {
		$claims        = $this->valid_claims();
		$claims['aud'] = 'https://other.example.com/mcp';
		$result        = ( new Jwt_Validator( new Settings_Page() ) )->validate( $this->sign_token( $claims ) );

		$this->assertWPError( $result );
		$this->assertSame( 'oauth_invalid_token', $result->get_error_code() );
	}

	/** Confirms expired tokens are rejected. */
	public function test_expired_token_is_rejected() {
		$claims        = $this->valid_claims();
		$claims['exp'] = time() - 120;
		$result        = ( new Jwt_Validator( new Settings_Page() ) )->validate( $this->sign_token( $claims ) );

		$this->assertWPError( $result );
	}

	/**
	 * Returns a valid access token claim set.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_claims() {
		return array(
			'iss'   => 'https://auth.example.com',
			'sub'   => 'subject-123',
			'aud'   => 'https://site.example.com/mcp',
			'iat'   => time(),
			'exp'   => time() + 300,
			'scope' => 'od-mcp:discover od-mcp:content:read',
		);
	}

	/**
	 * Signs claims as a compact RS256 JWT.
	 *
	 * @param array<string,mixed> $claims Token claims.
	 * @return string
	 */
	private function sign_token( $claims ) {
		$header  = $this->base64url_encode(
			wp_json_encode(
				array(
					'alg' => 'RS256',
					'kid' => 'test-key',
					'typ' => 'JWT',
				)
			)
		);
		$payload = $this->base64url_encode( wp_json_encode( $claims ) );
		openssl_sign( $header . '.' . $payload, $signature, $this->private_key, OPENSSL_ALGO_SHA256 );

		return $header . '.' . $payload . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * Encodes test token data using base64url.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT test fixture encoding.
	}
}
