=== OD MCP Bridge ===
Contributors: olein
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose selected WordPress abilities to authenticated MCP clients.

== Description ==

OD MCP Bridge connects the WordPress Abilities API to MCP clients through the official WordPress MCP Adapter.

Public-content abilities expose site information, published posts and pages, categories, and tags. Optional maintenance abilities expose capability-filtered update, plugin, theme, Site Health, content activity, stale content, and WP-Cron summaries. A maintenance snapshot composes enabled sections while preserving permission and failure boundaries.

Every ability requires an authenticated WordPress user. Idempotent post-draft, page-draft, and block-template-part creation abilities are available as opt-in write operations and are disabled by default. Draft abilities fix the post type, status, and author on the server. Template parts are limited to the active block theme, never overwrite existing parts, and require a dedicated capability. Maintenance abilities are also disabled by default and require their corresponding administrative capabilities. Page-draft and template-part creation use Application Password authentication only.

Authentication supports WordPress Application Passwords and an optional OAuth resource-server mode. OAuth mode validates RS256 JWT access tokens against a configured issuer, JWKS URI, audience, subject mapping, per-ability scope, and the existing WordPress capability checks. OD MCP Bridge does not issue or store access or refresh tokens.

== Changelog ==

= 0.5.0 =
* Add an opt-in, idempotent fixed-page draft creation ability.
* Add Application Password-only block template part creation with a dedicated capability and overwrite protection.
* Expand permissions, diagnostics, integration coverage, documentation, and Japanese translations for the new write abilities.

= 0.4.1 =
* Confirm compatibility with WordPress 7.1.

= 0.4.0 =
* Add complete bundled Japanese translations and translation catalog tooling.
* Document use with Codex, Claude, Visual Studio Code, Cursor, and Gemini CLI.
* Expand the usage guide for draft creation and multilingual environments.

= 0.3.0 =
* Add an opt-in, idempotent post draft creation ability.
* Add a dedicated OAuth content write scope and least-privilege diagnostics.
* Document safe draft creation from Codex and MCP Inspector.

= 0.2.0 =
* Add public page, category, and tag abilities.
* Add optional update, plugin, theme, Site Health, content, Cron, and maintenance snapshot abilities.
* Expand integration coverage and usage documentation.

= 0.1.0 =
* Add the first read-only MCP abilities and settings screen.
