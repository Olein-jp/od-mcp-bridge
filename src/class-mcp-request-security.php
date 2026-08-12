<?php
/**
 * MCP HTTP request security checks.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use Olein\MCPBridge\OAuth\Resource_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Validates browser Origin headers before an MCP request is dispatched. */
final class Mcp_Request_Security {

	/** Registers MCP request security hooks. */
	public function register_hooks() {
		add_filter( 'rest_pre_dispatch', array( $this, 'validate_origin' ), 1, 3 );
	}

	/**
	 * Rejects browser requests from origins that are not explicitly trusted.
	 *
	 * Non-browser MCP clients commonly omit Origin and remain supported. The
	 * current WordPress home and site origins are trusted by default.
	 *
	 * @param mixed           $result  Previous pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server.
	 * @param WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public function validate_origin( $result, $server, $request ) {
		if ( null !== $result || Resource_Server::MCP_ROUTE !== $request->get_route() ) {
			return $result;
		}

		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' === $origin ) {
			return $result;
		}

		$origin  = $this->normalize_origin( $origin );
		$allowed = array_filter(
			array(
				$this->normalize_origin( home_url( '/' ) ),
				$this->normalize_origin( site_url( '/' ) ),
			)
		);

		/**
		 * Filters exact browser origins allowed to call the MCP endpoint.
		 *
		 * Wildcards are intentionally unsupported. Each value must contain only
		 * a scheme, host, and optional port.
		 *
		 * @param array<int,string> $allowed Allowed origins.
		 * @param WP_REST_Request   $request REST request.
		 */
		$allowed = apply_filters( 'od_mcp_bridge_allowed_origins', array_values( array_unique( $allowed ) ), $request );
		$allowed = is_array( $allowed ) ? array_filter( array_map( array( $this, 'normalize_origin' ), $allowed ) ) : array();

		if ( '' !== $origin && in_array( $origin, $allowed, true ) ) {
			return $result;
		}

		$response = new WP_REST_Response(
			array(
				'code'    => 'mcp_invalid_origin',
				'message' => __( 'The request Origin is not allowed for this MCP endpoint.', 'od-mcp-bridge' ),
				'data'    => array( 'status' => 403 ),
			),
			403
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Normalizes one exact HTTP origin.
	 *
	 * @param mixed $value Origin value.
	 * @return string
	 */
	public function normalize_origin( $value ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		$parts = wp_parse_url( trim( $value ) );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}
		if (
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			isset( $parts['query'] ) ||
			isset( $parts['fragment'] ) ||
			( isset( $parts['path'] ) && ! in_array( $parts['path'], array( '', '/' ), true ) )
		) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}

		return $scheme . '://' . $host . ( $port ? ':' . $port : '' );
	}
}
