=== OD MCP Bridge ===
Contributors: olein
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose selected read-only WordPress abilities to authenticated MCP clients.

== Description ==

OD MCP Bridge connects the WordPress Abilities API to MCP clients through the official WordPress MCP Adapter.

Public-content abilities expose site information, published posts and pages, categories, and tags. Optional maintenance abilities expose capability-filtered update, plugin, theme, Site Health, content activity, stale content, and WP-Cron summaries. A maintenance snapshot composes enabled sections while preserving permission and failure boundaries.

Every ability is read-only and requires an authenticated WordPress user. Maintenance abilities are disabled by default and require their corresponding administrative capabilities.

== Changelog ==

= 0.2.0 =
* Add public page, category, and tag abilities.
* Add optional update, plugin, theme, Site Health, content, Cron, and maintenance snapshot abilities.
* Expand integration coverage and usage documentation.

= 0.1.0 =
* Add the first read-only MCP abilities and settings screen.
