<?php
/**
 * OAuth subject to WordPress user mapping.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge\OAuth;

use Olein\MCPBridge\Admin\Settings_Page;
use WP_Error;

/** Stores and resolves immutable OAuth subject mappings. */
final class User_Mapper {

	/** User meta key containing the OAuth subject. */
	const META_SUBJECT = '_od_mcp_bridge_oauth_subject';

	/** User meta key containing a non-reversible issuer and subject identifier. */
	const META_IDENTITY = '_od_mcp_bridge_oauth_identity';

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

	/** Registers user profile hooks. */
	public function register_hooks() {
		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
	}

	/**
	 * Resolves a validated issuer and subject to one active site user.
	 *
	 * @param string              $issuer Validated token issuer.
	 * @param string              $subject Validated token subject.
	 * @param array<string,mixed> $claims Validated token claims.
	 * @return int|WP_Error
	 */
	public function map( $issuer, $subject, $claims ) {
		/**
		 * Filters OAuth subject mapping before the stored mapping is queried.
		 *
		 * Return a positive user ID to provide a mapping, WP_Error to deny, or
		 * null to use the stored profile mapping.
		 *
		 * @param int|WP_Error|null   $user_id Mapped user ID, error, or null.
		 * @param string              $issuer  Validated issuer.
		 * @param string              $subject Validated subject.
		 * @param array<string,mixed> $claims  Validated claims.
		 */
		$filtered = apply_filters( 'od_mcp_bridge_oauth_map_subject', null, $issuer, $subject, $claims );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
			return $this->validate_user( (int) $filtered );
		}

		$identity = self::get_identity_key( $issuer, $subject );
		$users    = get_users(
			array(
				'blog_id'     => 0,
				'count_total' => false,
				'fields'      => 'ID',
				'meta_key'    => self::META_IDENTITY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded identity lookup required for authentication.
				'meta_value'  => $identity, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded identity lookup required for authentication.
				'number'      => 2,
			)
		);

		if ( 1 !== count( $users ) ) {
			return new WP_Error( 'oauth_subject_not_mapped', __( 'The OAuth subject is not mapped to exactly one WordPress user.', 'od-mcp-bridge' ) );
		}

		return $this->validate_user( (int) $users[0] );
	}

	/**
	 * Renders the OAuth subject field for administrators.
	 *
	 * @param \WP_User $user Profile user.
	 */
	public function render_profile_field( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'OD MCP Bridge OAuth', 'od-mcp-bridge' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="od-mcp-bridge-oauth-subject"><?php esc_html_e( 'OAuth subject', 'od-mcp-bridge' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="od-mcp-bridge-oauth-subject" name="od_mcp_bridge_oauth_subject" value="<?php echo esc_attr( get_user_meta( $user->ID, self::META_SUBJECT, true ) ); ?>" autocomplete="off" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: configured authorization server issuer. */
							esc_html__( 'Enter the exact sub claim issued for this user by %s. Leave blank to disable OAuth mapping.', 'od-mcp-bridge' ),
							esc_html( $this->settings->get_oauth_issuer() ? $this->settings->get_oauth_issuer() : __( 'the configured issuer', 'od-mcp-bridge' ) )
						);
						?>
					</p>
					<?php wp_nonce_field( 'od_mcp_bridge_oauth_subject_' . $user->ID, 'od_mcp_bridge_oauth_subject_nonce' ); ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves an administrator-managed OAuth subject mapping.
	 *
	 * @param int $user_id Profile user ID.
	 */
	public function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['od_mcp_bridge_oauth_subject_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['od_mcp_bridge_oauth_subject_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'od_mcp_bridge_oauth_subject_' . $user_id ) ) {
			return;
		}

		$subject = isset( $_POST['od_mcp_bridge_oauth_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['od_mcp_bridge_oauth_subject'] ) ) : '';
		if ( '' === $subject ) {
			delete_user_meta( $user_id, self::META_SUBJECT );
			delete_user_meta( $user_id, self::META_IDENTITY );
			return;
		}

		update_user_meta( $user_id, self::META_SUBJECT, $subject );
		$issuer = $this->settings->get_oauth_issuer();
		if ( '' !== $issuer ) {
			update_user_meta( $user_id, self::META_IDENTITY, self::get_identity_key( $issuer, $subject ) );
		} else {
			delete_user_meta( $user_id, self::META_IDENTITY );
		}
	}

	/**
	 * Builds a non-reversible lookup key for an issuer and subject pair.
	 *
	 * @param string $issuer OAuth issuer.
	 * @param string $subject OAuth subject.
	 * @return string
	 */
	public static function get_identity_key( $issuer, $subject ) {
		return hash( 'sha256', $issuer . "\0" . $subject );
	}

	/**
	 * Validates that a mapped user exists and belongs to the current site.
	 *
	 * @param int $user_id User ID.
	 * @return int|WP_Error
	 */
	private function validate_user( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'oauth_user_unavailable', __( 'The mapped WordPress user is unavailable on this site.', 'od-mcp-bridge' ) );
		}

		return $user_id;
	}
}
