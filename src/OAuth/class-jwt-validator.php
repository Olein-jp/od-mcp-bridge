<?php
/**
 * Minimal RS256 JWT validation for OAuth resource access.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge\OAuth;

use Olein\MCPBridge\Admin\Settings_Page;
use WP_Error;

/** Validates signed access tokens without storing token material. */
final class Jwt_Validator {

	/** Allowed clock difference in seconds. */
	const CLOCK_SKEW = 60;

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
	 * Validates a Bearer access token.
	 *
	 * @param string $token Compact JWT.
	 * @return array<string,mixed>|WP_Error
	 */
	public function validate( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 16384 ) {
			return $this->invalid_token();
		}

		/**
		 * Filters token validation for providers using opaque or non-RS256 tokens.
		 *
		 * Return validated claims, WP_Error, or null to use built-in RS256 validation.
		 * Raw tokens must never be logged or persisted by callbacks.
		 *
		 * @param array<string,mixed>|WP_Error|null $result Validation result.
		 * @param string                            $token  Raw Bearer token.
		 * @param Settings_Page                     $settings Plugin settings.
		 */
		$filtered = apply_filters( 'od_mcp_bridge_oauth_validate_token', null, $token, $this->settings );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_array( $filtered ) ) {
			$valid_result = isset( $filtered['iss'], $filtered['sub'], $filtered['od_mcp_scopes'] )
				&& is_array( $filtered['od_mcp_scopes'] )
				&& hash_equals( $this->settings->get_oauth_issuer(), (string) $filtered['iss'] )
				&& '' !== (string) $filtered['sub'];

			return $valid_result ? $filtered : $this->invalid_token();
		}

		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return $this->invalid_token();
		}

		$header    = $this->decode_json_segment( $parts[0] );
		$claims    = $this->decode_json_segment( $parts[1] );
		$signature = $this->base64url_decode( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $claims ) || false === $signature || 'RS256' !== ( $header['alg'] ?? '' ) ) {
			return $this->invalid_token();
		}

		$key = $this->get_signing_key( isset( $header['kid'] ) ? (string) $header['kid'] : '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$verified = openssl_verify( $parts[0] . '.' . $parts[1], $signature, $key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $verified ) {
			return $this->invalid_token();
		}

		$claim_check = $this->validate_claims( $claims );
		if ( is_wp_error( $claim_check ) ) {
			return $claim_check;
		}

		$claims['od_mcp_scopes'] = $this->normalize_scopes( isset( $claims['scope'] ) ? $claims['scope'] : '' );

		return $claims;
	}

	/**
	 * Validates required access token claims.
	 *
	 * @param array<string,mixed> $claims Decoded token claims.
	 * @return true|WP_Error
	 */
	private function validate_claims( $claims ) {
		$now      = time();
		$issuer   = $this->settings->get_oauth_issuer();
		$resource = $this->settings->get_oauth_resource();

		if ( '' === $issuer || ! isset( $claims['iss'] ) || ! hash_equals( $issuer, (string) $claims['iss'] ) ) {
			return $this->invalid_token();
		}
		if ( ! isset( $claims['sub'] ) || '' === (string) $claims['sub'] ) {
			return $this->invalid_token();
		}
		if ( ! isset( $claims['exp'] ) || ! is_numeric( $claims['exp'] ) || $now - self::CLOCK_SKEW >= (int) $claims['exp'] ) {
			return $this->invalid_token();
		}
		if ( isset( $claims['nbf'] ) && ( ! is_numeric( $claims['nbf'] ) || $now + self::CLOCK_SKEW < (int) $claims['nbf'] ) ) {
			return $this->invalid_token();
		}
		if ( isset( $claims['iat'] ) && ( ! is_numeric( $claims['iat'] ) || $now + self::CLOCK_SKEW < (int) $claims['iat'] ) ) {
			return $this->invalid_token();
		}

		$audience = isset( $claims['aud'] ) && is_array( $claims['aud'] ) ? $claims['aud'] : array( isset( $claims['aud'] ) ? $claims['aud'] : '' );
		$audience = array_map( 'strval', $audience );
		if ( '' === $resource || ! in_array( $resource, $audience, true ) ) {
			return $this->invalid_token();
		}

		return true;
	}

	/**
	 * Selects one signing key from the configured JWKS.
	 *
	 * @param string $kid   Token key identifier.
	 * @param bool   $retry Whether to retry after clearing cached keys.
	 * @return string|WP_Error
	 */
	private function get_signing_key( $kid, $retry = true ) {
		$jwks = $this->get_jwks();
		if ( is_wp_error( $jwks ) ) {
			return $jwks;
		}

		$candidates = array();
		foreach ( $jwks['keys'] as $key ) {
			if ( ! is_array( $key ) || 'RSA' !== ( $key['kty'] ?? '' ) || ( isset( $key['use'] ) && 'sig' !== $key['use'] ) || ( isset( $key['alg'] ) && 'RS256' !== $key['alg'] ) ) {
				continue;
			}
			if ( '' !== $kid && (string) ( $key['kid'] ?? '' ) !== $kid ) {
				continue;
			}
			$candidates[] = $key;
		}

		if ( 1 !== count( $candidates ) ) {
			if ( $retry ) {
				delete_transient( 'od_mcp_jwks_' . md5( $this->settings->get_oauth_jwks_uri() ) );

				return $this->get_signing_key( $kid, false );
			}

			return new WP_Error( 'oauth_signing_key_unavailable', __( 'A unique OAuth signing key could not be selected.', 'od-mcp-bridge' ) );
		}

		return $this->jwk_to_pem( $candidates[0] );
	}

	/**
	 * Retrieves and caches the configured JSON Web Key Set.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_jwks() {
		$url       = $this->settings->get_oauth_jwks_uri();
		$cache_key = 'od_mcp_jwks_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['keys'] ) && is_array( $cached['keys'] ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'headers'     => array( 'Accept' => 'application/json' ),
				'redirection' => 2,
				'timeout'     => 5,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'oauth_jwks_unavailable', __( 'The OAuth signing keys are unavailable.', 'od-mcp-bridge' ) );
		}

		$jwks = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $jwks ) || ! isset( $jwks['keys'] ) || ! is_array( $jwks['keys'] ) ) {
			return new WP_Error( 'oauth_jwks_invalid', __( 'The OAuth signing key response is invalid.', 'od-mcp-bridge' ) );
		}

		/** Filters the JWKS cache lifetime in seconds. */
		$ttl = (int) apply_filters( 'od_mcp_bridge_oauth_jwks_cache_ttl', 300 );
		set_transient( $cache_key, $jwks, max( 60, $ttl ) );

		return $jwks;
	}

	/**
	 * Converts an RSA JSON Web Key to PEM.
	 *
	 * @param array<string,mixed> $jwk JSON Web Key.
	 * @return string|WP_Error
	 */
	private function jwk_to_pem( $jwk ) {
		if ( isset( $jwk['x5c'][0] ) && is_string( $jwk['x5c'][0] ) ) {
			return "-----BEGIN CERTIFICATE-----\n" . chunk_split( $jwk['x5c'][0], 64, "\n" ) . "-----END CERTIFICATE-----\n";
		}
		if ( ! isset( $jwk['n'], $jwk['e'] ) || ! is_string( $jwk['n'] ) || ! is_string( $jwk['e'] ) ) {
			return new WP_Error( 'oauth_jwk_invalid', __( 'The OAuth signing key is invalid.', 'od-mcp-bridge' ) );
		}

		$modulus  = $this->base64url_decode( $jwk['n'] );
		$exponent = $this->base64url_decode( $jwk['e'] );
		if ( false === $modulus || false === $exponent ) {
			return new WP_Error( 'oauth_jwk_invalid', __( 'The OAuth signing key is invalid.', 'od-mcp-bridge' ) );
		}

		$rsa_public_key = $this->der_sequence( $this->der_integer( $modulus ) . $this->der_integer( $exponent ) );
		$algorithm      = hex2bin( '300d06092a864886f70d0101010500' );
		$public_key     = $this->der_sequence( $algorithm . "\x03" . $this->der_length( strlen( $rsa_public_key ) + 1 ) . "\x00" . $rsa_public_key );

		// Base64 is required by the PEM text encoding and is not used to hide executable code.
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $public_key ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decodes one base64url-encoded JSON segment.
	 *
	 * @param string $segment Encoded JWT segment.
	 * @return array<string,mixed>|null
	 */
	private function decode_json_segment( $segment ) {
		$decoded = $this->base64url_decode( $segment );
		if ( false === $decoded ) {
			return null;
		}
		$value = json_decode( $decoded, true );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Decodes a base64url value.
	 *
	 * @param string $value Encoded value.
	 * @return string|false
	 */
	private function base64url_decode( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]*$/', $value ) ) {
			return false;
		}
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- JWT wire-format decoding.
	}

	/**
	 * Encodes an ASN.1 DER integer.
	 *
	 * @param string $value Binary integer.
	 * @return string
	 */
	private function der_integer( $value ) {
		$value = ltrim( $value, "\x00" );
		if ( '' === $value ) {
			$value = "\x00";
		}
		if ( ord( $value[0] ) > 0x7f ) {
			$value = "\x00" . $value;
		}
		return "\x02" . $this->der_length( strlen( $value ) ) . $value;
	}

	/**
	 * Encodes an ASN.1 DER sequence.
	 *
	 * @param string $value Encoded sequence contents.
	 * @return string
	 */
	private function der_sequence( $value ) {
		return "\x30" . $this->der_length( strlen( $value ) ) . $value;
	}

	/**
	 * Encodes an ASN.1 DER length.
	 *
	 * @param int $length Byte length.
	 * @return string
	 */
	private function der_length( $length ) {
		if ( $length < 128 ) {
			return chr( $length );
		}
		$encoded = '';
		while ( $length > 0 ) {
			$encoded  = chr( $length & 0xff ) . $encoded;
			$length >>= 8;
		}
		return chr( 0x80 | strlen( $encoded ) ) . $encoded;
	}

	/**
	 * Normalizes the standard space-delimited scope claim.
	 *
	 * @param mixed $scope Scope claim.
	 * @return array<int,string>
	 */
	private function normalize_scopes( $scope ) {
		if ( ! is_string( $scope ) ) {
			return array();
		}
		$scopes = preg_split( '/\s+/', trim( $scope ) );

		return array_values( array_unique( array_filter( false === $scopes ? array() : $scopes ) ) );
	}

	/**
	 * Creates a non-sensitive invalid token error.
	 *
	 * @return WP_Error
	 */
	private function invalid_token() {
		return new WP_Error( 'oauth_invalid_token', __( 'The OAuth access token is invalid.', 'od-mcp-bridge' ) );
	}
}
