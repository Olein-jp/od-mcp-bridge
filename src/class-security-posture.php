<?php
/**
 * Local WordPress security posture summary.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use Throwable;

/** Builds a bounded security configuration summary without external requests. */
final class Security_Posture {

	/** Returns the local security posture report. */
	public function get_report() {
		$checks = array(
			'https'                 => $this->get_https_check(),
			'core_updates'          => $this->get_core_updates_check(),
			'debug'                 => $this->get_debug_check(),
			'file_editing'          => $this->get_file_editing_check(),
			'automatic_updates'     => $this->get_automatic_updates_check(),
			'administrators'        => $this->get_administrators_check(),
			'application_passwords' => $this->get_application_passwords_check(),
		);

		return array_merge(
			array(
				'generated_at' => gmdate( DATE_W3C ),
				'status'       => $this->get_overall_status( $checks ),
				'environment'  => wp_get_environment_type(),
			),
			$checks
		);
	}

	/** Returns the HTTPS configuration check. */
	private function get_https_check() {
		$using_https = wp_is_using_https();

		if ( $using_https ) {
			$status         = 'good';
			$support_status = 'confirmed_by_configuration';
			$summary        = __( 'WordPress home and site URLs use HTTPS.', 'od-mcp-bridge' );
		} else {
			$status         = 'recommended';
			$support_status = 'not_checked';
			$summary        = __( 'WordPress is not configured to use HTTPS. Server support was not tested because this ability never makes external requests.', 'od-mcp-bridge' );
		}

		return array(
			'status'         => $status,
			'summary'        => $summary,
			'using_https'    => $using_https,
			'support_status' => $support_status,
		);
	}

	/** Returns cached WordPress core update state without refreshing it. */
	private function get_core_updates_check() {
		$transient = get_site_transient( 'update_core' );
		$state     = 'unknown';

		if ( is_object( $transient ) && isset( $transient->updates ) && is_array( $transient->updates ) ) {
			$state = 'up_to_date';
			foreach ( $transient->updates as $update ) {
				if ( is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response ) {
					$state = 'update_available';
					break;
				}
			}
		}

		$statuses  = array(
			'unknown'          => 'unknown',
			'up_to_date'       => 'good',
			'update_available' => 'attention',
		);
		$summaries = array(
			'unknown'          => __( 'The cached WordPress update state is unavailable.', 'od-mcp-bridge' ),
			'up_to_date'       => __( 'The cached update state reports no WordPress core update.', 'od-mcp-bridge' ),
			'update_available' => __( 'The cached update state reports that a WordPress core update is available.', 'od-mcp-bridge' ),
		);

		return array(
			'status'  => $statuses[ $state ],
			'summary' => $summaries[ $state ],
			'state'   => $state,
		);
	}

	/** Returns debug configuration without exposing log paths or contents. */
	private function get_debug_check() {
		$enabled         = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$display_enabled = defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY;
		$log_enabled     = defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG;
		$production      = 'production' === wp_get_environment_type();

		if ( $production && $display_enabled ) {
			$status  = 'attention';
			$summary = __( 'Debug output is enabled for site visitors in the production environment.', 'od-mcp-bridge' );
		} elseif ( $production && ( $enabled || $log_enabled ) ) {
			$status  = 'recommended';
			$summary = __( 'Debugging is enabled in the production environment. Confirm that this is intentional.', 'od-mcp-bridge' );
		} elseif ( $enabled || $display_enabled || $log_enabled ) {
			$status  = 'good';
			$summary = __( 'Debugging is enabled in a non-production environment.', 'od-mcp-bridge' );
		} else {
			$status  = 'good';
			$summary = __( 'Debug output and logging are disabled.', 'od-mcp-bridge' );
		}

		return array(
			'status'          => $status,
			'summary'         => $summary,
			'enabled'         => $enabled,
			'display_enabled' => $display_enabled,
			'log_enabled'     => $log_enabled,
		);
	}

	/** Returns file editor and modification policy as booleans only. */
	private function get_file_editing_check() {
		$modifications_allowed = wp_is_file_mod_allowed( 'capability_edit_themes' );
		$editor_allowed        = $modifications_allowed && ( ! defined( 'DISALLOW_FILE_EDIT' ) || ! DISALLOW_FILE_EDIT );

		return array(
			'status'                => $editor_allowed ? 'recommended' : 'good',
			'summary'               => $editor_allowed
				? __( 'The built-in plugin and theme file editor is available. Confirm that this matches the site policy.', 'od-mcp-bridge' )
				: __( 'The built-in plugin and theme file editor is disabled.', 'od-mcp-bridge' ),
			'editor_allowed'        => $editor_allowed,
			'modifications_allowed' => $modifications_allowed,
		);
	}

	/** Returns local automatic update policy without checking remote services. */
	private function get_automatic_updates_check() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

		$updater                  = new \WP_Automatic_Updater();
		$updater_enabled          = ! $updater->is_disabled();
		$minor_core_updates       = ( ! defined( 'WP_AUTO_UPDATE_CORE' ) || false !== WP_AUTO_UPDATE_CORE ) && apply_filters( 'allow_minor_auto_core_updates', true );
		$previous_failure         = (bool) get_site_option( 'auto_core_update_failed' );
		$plugin_theme_config      = 'unknown';
		$plugin_theme_has_problem = false;

		try {
			$result = \WP_Site_Health::get_instance()->detect_plugin_theme_auto_update_issues();
			if ( is_object( $result ) && isset( $result->status ) ) {
				$plugin_theme_has_problem = 'good' !== $result->status;
				$plugin_theme_config      = $plugin_theme_has_problem ? 'issues_detected' : 'good';
			}
		} catch ( Throwable $exception ) {
			$plugin_theme_config = 'unknown';
		}

		if ( ! $updater_enabled || ! $minor_core_updates || $previous_failure ) {
			$status  = 'attention';
			$summary = __( 'Automatic core updates are disabled, blocked, or have a recorded failure. Review the update policy.', 'od-mcp-bridge' );
		} elseif ( $plugin_theme_has_problem ) {
			$status  = 'recommended';
			$summary = __( 'WordPress detected a potential plugin or theme auto-update configuration issue.', 'od-mcp-bridge' );
		} elseif ( 'unknown' === $plugin_theme_config ) {
			$status  = 'unknown';
			$summary = __( 'WordPress could not determine the plugin and theme auto-update configuration.', 'od-mcp-bridge' );
		} else {
			$status  = 'good';
			$summary = __( 'Automatic update policy checks did not identify a configuration issue.', 'od-mcp-bridge' );
		}

		return array(
			'status'                     => $status,
			'summary'                    => $summary,
			'updater_enabled'            => $updater_enabled,
			'minor_core_updates_allowed' => (bool) $minor_core_updates,
			'plugin_theme_configuration' => $plugin_theme_config,
			'previous_failure'           => $previous_failure,
		);
	}

	/** Returns only the administrator count for the current site. */
	private function get_administrators_check() {
		$counts = count_users( 'time', get_current_blog_id() );
		$count  = isset( $counts['avail_roles']['administrator'] ) ? (int) $counts['avail_roles']['administrator'] : 0;

		if ( 0 === $count ) {
			$status  = 'attention';
			$summary = __( 'No administrator role assignment was found for the current site.', 'od-mcp-bridge' );
		} elseif ( 1 === $count ) {
			$status  = 'good';
			$summary = __( 'One administrator role assignment exists on the current site.', 'od-mcp-bridge' );
		} else {
			$status  = 'recommended';
			$summary = __( 'Multiple administrator role assignments exist. Review them periodically.', 'od-mcp-bridge' );
		}

		return array(
			'status'  => $status,
			'summary' => $summary,
			'count'   => $count,
			'scope'   => 'current_site',
		);
	}

	/** Returns Application Password availability without reading password metadata. */
	private function get_application_passwords_check() {
		$globally_available         = wp_is_application_passwords_available();
		$available_for_current_user = wp_is_application_passwords_available_for_user( wp_get_current_user() );
		$available                  = $globally_available && $available_for_current_user;

		return array(
			'status'                     => $available ? 'good' : 'attention',
			'summary'                    => $available
				? __( 'Application Password authentication is available for the current user.', 'od-mcp-bridge' )
				: __( 'Application Password authentication is unavailable globally or for the current user.', 'od-mcp-bridge' ),
			'globally_available'         => $globally_available,
			'available_for_current_user' => $available_for_current_user,
		);
	}

	/**
	 * Returns the highest-priority check status without declaring a vulnerability.
	 *
	 * @param array<string, array<string, mixed>> $checks Security posture checks.
	 */
	private function get_overall_status( $checks ) {
		$priority = array(
			'good'        => 0,
			'unknown'     => 1,
			'recommended' => 2,
			'attention'   => 3,
		);
		$status   = 'good';

		foreach ( $checks as $check ) {
			if ( isset( $check['status'], $priority[ $check['status'] ] ) && $priority[ $check['status'] ] > $priority[ $status ] ) {
				$status = $check['status'];
			}
		}

		return $status;
	}
}
