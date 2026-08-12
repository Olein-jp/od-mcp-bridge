#!/usr/bin/env bash

set -euo pipefail

: "${OD_MCP_URL:?Set OD_MCP_URL to the MCP endpoint URL.}"
: "${OD_MCP_METADATA_URL:?Set OD_MCP_METADATA_URL to the Protected Resource Metadata URL.}"
: "${OD_MCP_ACCESS_TOKEN:?Set OD_MCP_ACCESS_TOKEN without writing it to a repository or log.}"

smoke_dir="$(mktemp -d)"
trap 'rm -rf "${smoke_dir}"' EXIT

curl --silent --show-error --fail \
	--output "${smoke_dir}/metadata.json" \
	"${OD_MCP_METADATA_URL}"

php -r '
$data = json_decode(file_get_contents($argv[1]), true);
if (!is_array($data) || empty($data["resource"]) || empty($data["authorization_servers"])) {
	fwrite(STDERR, "Protected Resource Metadata is invalid.\n");
	exit(1);
}
' "${smoke_dir}/metadata.json"

unauthenticated_status="$(curl --silent --show-error \
	--output "${smoke_dir}/unauthenticated.json" \
	--dump-header "${smoke_dir}/unauthenticated.headers" \
	--write-out '%{http_code}' \
	--request POST \
	--header 'Content-Type: application/json' \
	--data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"od-mcp-smoke","version":"1.0.0"}}}' \
	"${OD_MCP_URL}")"

if [[ "${unauthenticated_status}" != "401" ]] || ! grep -qi '^WWW-Authenticate: Bearer ' "${smoke_dir}/unauthenticated.headers"; then
	echo "Expected an unauthenticated Bearer challenge, received HTTP ${unauthenticated_status}." >&2
	exit 1
fi

initialize_status="$(curl --silent --show-error \
	--output "${smoke_dir}/initialize.json" \
	--dump-header "${smoke_dir}/initialize.headers" \
	--write-out '%{http_code}' \
	--request POST \
	--header 'Content-Type: application/json' \
	--header "Authorization: Bearer ${OD_MCP_ACCESS_TOKEN}" \
	--data '{"jsonrpc":"2.0","id":2,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"od-mcp-smoke","version":"1.0.0"}}}' \
	"${OD_MCP_URL}")"

if [[ "${initialize_status}" != "200" ]]; then
	echo "OAuth MCP initialize failed with HTTP ${initialize_status}." >&2
	exit 1
fi

session_id="$(awk 'tolower($0) ~ /^mcp-session-id:/ { sub(/^[^:]+:[[:space:]]*/, ""); sub(/\r$/, ""); print; exit }' "${smoke_dir}/initialize.headers")"
if [[ -z "${session_id}" ]]; then
	echo 'MCP initialize did not return Mcp-Session-Id.' >&2
	exit 1
fi

execute_status="$(curl --silent --show-error \
	--output "${smoke_dir}/execute.json" \
	--write-out '%{http_code}' \
	--request POST \
	--header 'Content-Type: application/json' \
	--header "Authorization: Bearer ${OD_MCP_ACCESS_TOKEN}" \
	--header "Mcp-Session-Id: ${session_id}" \
	--data '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp-adapter-execute-ability","arguments":{"ability_name":"od-mcp-bridge/get-site-info","parameters":{}}}}' \
	"${OD_MCP_URL}")"

if [[ "${execute_status}" != "200" ]]; then
	echo "OAuth Ability execution failed with HTTP ${execute_status}." >&2
	exit 1
fi

php -r '
$data = json_decode(file_get_contents($argv[1]), true);
if (!is_array($data) || isset($data["error"]) || !isset($data["result"])) {
	fwrite(STDERR, "MCP Ability returned an invalid or error response.\n");
	exit(1);
}
' "${smoke_dir}/execute.json"

echo 'OAuth MCP smoke test passed.'
