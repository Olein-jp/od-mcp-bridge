<?php
/**
 * Scope-aware MCP Ability discovery.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge\OAuth;

/** Filters OAuth discovery results to abilities usable by the active token. */
final class Scope_Aware_Discovery {

	/**
	 * OAuth request context.
	 *
	 * @var Resource_Server
	 */
	private $resource_server;

	/**
	 * Ability scope policy.
	 *
	 * @var Scope_Policy
	 */
	private $scope_policy;

	/**
	 * Constructor.
	 *
	 * @param Resource_Server $resource_server OAuth request context.
	 * @param Scope_Policy    $scope_policy    Ability scope policy.
	 */
	public function __construct( Resource_Server $resource_server, Scope_Policy $scope_policy ) {
		$this->resource_server = $resource_server;
		$this->scope_policy    = $scope_policy;
	}

	/** Registers the Adapter result filter. */
	public function register_hooks() {
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'filter_discovery_result' ), 10, 3 );
	}

	/**
	 * Removes abilities that the active OAuth token cannot execute.
	 *
	 * Application Password requests retain the Adapter's original result.
	 *
	 * @param mixed  $result    Raw Adapter tool result.
	 * @param array  $arguments Tool arguments.
	 * @param string $tool_name MCP tool name.
	 * @return mixed
	 */
	public function filter_discovery_result( $result, $arguments, $tool_name ) {
		unset( $arguments );
		if (
			'mcp-adapter-discover-abilities' !== $tool_name ||
			! $this->resource_server->is_oauth_authenticated() ||
			! is_array( $result ) ||
			! isset( $result['abilities'] ) ||
			! is_array( $result['abilities'] )
		) {
			return $result;
		}

		$scopes              = $this->resource_server->get_request_scopes();
		$result['abilities'] = array_values(
			array_filter(
				$result['abilities'],
				function ( $ability ) use ( $scopes ) {
					if ( ! is_array( $ability ) || ! isset( $ability['name'] ) || ! is_string( $ability['name'] ) ) {
						return false;
					}
					$scope = $this->scope_policy->get_ability_scope( $ability['name'] );

					return null !== $scope && in_array( $scope, $scopes, true );
				}
			)
		);

		return $result;
	}
}
