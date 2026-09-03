<?php
/**
 * Plugin settings screen.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge\Admin;

use WP\MCP\Core\McpAdapter;

/**
 * Manages the plugin settings page and stored ability toggles.
 */
final class Settings_Page {

	/** Application Password authentication only. */
	const AUTH_APPLICATION_PASSWORD = 'application_password';

	/** OAuth Bearer authentication only. */
	const AUTH_OAUTH = 'oauth';

	/** Application Password and OAuth authentication. */
	const AUTH_BOTH = 'both';

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'od_mcp_bridge_settings';

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'od-mcp-bridge';

	/**
	 * Supported ability keys.
	 *
	 * @var array<int, string>
	 */
	private $abilities = array(
		'get-site-info',
		'get-posts',
		'get-post',
		'get-pages',
		'get-page',
		'get-terms',
		'create-post-draft',
		'create-page-draft',
		'create-template-part',
		'get-update-status',
		'get-plugins',
		'get-themes',
		'get-site-health',
		'get-content-summary',
		'get-stale-content',
		'get-cron-status',
		'get-maintenance-snapshot',
		'get-security-posture',
	);

	/**
	 * Abilities enabled by default.
	 *
	 * @var array<int, string>
	 */
	private $default_enabled_abilities = array(
		'get-site-info',
		'get-posts',
		'get-post',
		'get-pages',
		'get-page',
		'get-terms',
	);

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/**
	 * Registers the option, section, and ability fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_NAME,
			array(
				'type'              => 'object',
				'default'           => $this->get_defaults(),
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'od_mcp_bridge_authentication',
			esc_html__( 'Authentication', 'od-mcp-bridge' ),
			array( $this, 'render_authentication_description' ),
			self::PAGE_SLUG
		);

		$auth_fields = array(
			'mode'     => __( 'Authentication mode', 'od-mcp-bridge' ),
			'issuer'   => __( 'Authorization server issuer', 'od-mcp-bridge' ),
			'jwks_uri' => __( 'JWKS URI', 'od-mcp-bridge' ),
			'resource' => __( 'OAuth resource URI', 'od-mcp-bridge' ),
		);
		foreach ( $auth_fields as $key => $label ) {
			add_settings_field(
				'od_mcp_bridge_oauth_' . $key,
				esc_html( $label ),
				array( $this, 'render_authentication_field' ),
				self::PAGE_SLUG,
				'od_mcp_bridge_authentication',
				array( 'key' => $key )
			);
		}

		add_settings_section(
			'od_mcp_bridge_abilities',
			esc_html__( 'Available abilities', 'od-mcp-bridge' ),
			array( $this, 'render_abilities_description' ),
			self::PAGE_SLUG
		);

		foreach ( $this->abilities as $key ) {
			add_settings_field(
				'od_mcp_bridge_' . str_replace( '-', '_', $key ),
				esc_html( $this->get_ability_label( $key ) ),
				array( $this, 'render_ability_field' ),
				self::PAGE_SLUG,
				'od_mcp_bridge_abilities',
				array(
					'key' => $key,
				)
			);
		}
	}

	/**
	 * Registers the Settings page.
	 *
	 * @return void
	 */
	public function register_page() {
		add_options_page(
			esc_html__( 'OD MCP Bridge', 'od-mcp-bridge' ),
			esc_html__( 'OD MCP Bridge', 'od-mcp-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Sanitizes the settings payload using an allowlist.
	 *
	 * @param mixed $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array(
			'abilities' => array(),
			'oauth'     => $this->get_defaults()['oauth'],
		);
		$submitted = is_array( $input ) && isset( $input['abilities'] ) && is_array( $input['abilities'] )
			? $input['abilities']
			: array();
		$oauth     = is_array( $input ) && isset( $input['oauth'] ) && is_array( $input['oauth'] )
			? $input['oauth']
			: array();

		foreach ( $this->abilities as $key ) {
			$sanitized['abilities'][ $key ] = isset( $submitted[ $key ] ) && '1' === (string) $submitted[ $key ];
		}

		$modes                          = array( self::AUTH_APPLICATION_PASSWORD, self::AUTH_OAUTH, self::AUTH_BOTH );
		$mode                           = isset( $oauth['mode'] ) ? sanitize_key( $oauth['mode'] ) : self::AUTH_APPLICATION_PASSWORD;
		$sanitized['oauth']['mode']     = in_array( $mode, $modes, true ) ? $mode : self::AUTH_APPLICATION_PASSWORD;
		$sanitized['oauth']['issuer']   = $this->sanitize_oauth_url( isset( $oauth['issuer'] ) ? $oauth['issuer'] : '' );
		$sanitized['oauth']['jwks_uri'] = $this->sanitize_oauth_url( isset( $oauth['jwks_uri'] ) ? $oauth['jwks_uri'] : '' );
		$sanitized['oauth']['resource'] = $this->sanitize_oauth_url( isset( $oauth['resource'] ) ? $oauth['resource'] : '' );

		return $sanitized;
	}

	/** Returns the configured authentication mode. */
	public function get_auth_mode() {
		return $this->get_settings()['oauth']['mode'];
	}

	/** Returns the exact configured authorization server issuer. */
	public function get_oauth_issuer() {
		return $this->get_settings()['oauth']['issuer'];
	}

	/** Returns the configured JSON Web Key Set URI. */
	public function get_oauth_jwks_uri() {
		return $this->get_settings()['oauth']['jwks_uri'];
	}

	/** Returns the canonical OAuth resource URI. */
	public function get_oauth_resource() {
		$resource = $this->get_settings()['oauth']['resource'];

		return '' !== $resource ? $resource : rest_url( 'mcp/mcp-adapter-default-server' );
	}

	/** Checks whether all settings required for built-in OAuth validation exist. */
	public function is_oauth_configured() {
		return '' !== $this->get_oauth_issuer() && '' !== $this->get_oauth_jwks_uri() && '' !== $this->get_oauth_resource();
	}

	/**
	 * Checks whether an ability is enabled.
	 *
	 * @param string $key Ability key.
	 * @return bool
	 */
	public function is_ability_enabled( $key ) {
		$settings = $this->get_settings();

		return isset( $settings['abilities'][ $key ] ) && true === $settings['abilities'][ $key ];
	}

	/**
	 * Returns supported ability keys.
	 *
	 * @return array<int, string>
	 */
	public function get_ability_keys() {
		return $this->abilities;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'OD MCP Bridge', 'od-mcp-bridge' ); ?></h1>

			<h2><?php esc_html_e( 'MCP server', 'od-mcp-bridge' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'od-mcp-bridge' ); ?></th>
						<td>
							<?php echo class_exists( McpAdapter::class ) ? esc_html__( 'Enabled', 'od-mcp-bridge' ) : esc_html__( 'Unavailable', 'od-mcp-bridge' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Endpoint', 'od-mcp-bridge' ); ?></th>
						<td><code><?php echo esc_html( rest_url( 'mcp/mcp-adapter-default-server' ) ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<?php $this->render_connection_diagnostics(); ?>

			<p class="description">
				<?php esc_html_e( 'Use a dedicated Subscriber for public content, or the MCP Maintenance Reader role for maintenance abilities. Store credentials only in the MCP client environment; this plugin never stores access tokens.', 'od-mcp-bridge' ); ?>
			</p>
			<ol>
				<li>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to the Add New User screen. */
							__( 'Create a dedicated user with the Subscriber or MCP Maintenance Reader role on the <a href="%s">Add New User</a> screen.', 'od-mcp-bridge' ),
							array( 'a' => array( 'href' => array() ) )
						),
						esc_url( admin_url( 'user-new.php' ) )
					);
					?>
				</li>
				<li><?php esc_html_e( 'Sign in as that user and create an Application Password under Users → Profile.', 'od-mcp-bridge' ); ?></li>
				<li><?php esc_html_e( 'Revoke the Application Password from the same profile when the client is no longer used.', 'od-mcp-bridge' ); ?></li>
			</ol>
			<p class="description">
				<?php esc_html_e( 'Use HTTPS for this endpoint on production sites. Never paste a real Application Password into plugin settings, source files, logs, or issues.', 'od-mcp-bridge' ); ?>
			</p>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::PAGE_SLUG );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/** Renders authentication settings guidance. */
	public function render_authentication_description() {
		printf(
			'<p>%s</p>',
			esc_html__( 'OAuth mode validates RS256 JWT access tokens from one trusted issuer. Configure only public issuer, JWKS, and resource URLs here; no client secret or token is stored.', 'od-mcp-bridge' )
		);
	}

	/**
	 * Renders one authentication setting.
	 *
	 * @param array<string,string> $args Field arguments.
	 */
	public function render_authentication_field( $args ) {
		$key      = isset( $args['key'] ) ? $args['key'] : '';
		$settings = $this->get_settings()['oauth'];

		if ( 'mode' === $key ) {
			?>
			<select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[oauth][mode]">
				<option value="<?php echo esc_attr( self::AUTH_APPLICATION_PASSWORD ); ?>" <?php selected( $settings['mode'], self::AUTH_APPLICATION_PASSWORD ); ?>><?php esc_html_e( 'Application Password only', 'od-mcp-bridge' ); ?></option>
				<option value="<?php echo esc_attr( self::AUTH_OAUTH ); ?>" <?php selected( $settings['mode'], self::AUTH_OAUTH ); ?>><?php esc_html_e( 'OAuth only', 'od-mcp-bridge' ); ?></option>
				<option value="<?php echo esc_attr( self::AUTH_BOTH ); ?>" <?php selected( $settings['mode'], self::AUTH_BOTH ); ?>><?php esc_html_e( 'Application Password and OAuth', 'od-mcp-bridge' ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Use the combined mode only during migration because Basic authentication remains an alternate access path.', 'od-mcp-bridge' ); ?></p>
			<?php
			return;
		}

		$descriptions = array(
			'issuer'   => __( 'Exact iss claim and authorization server identifier, for example https://auth.example.com.', 'od-mcp-bridge' ),
			'jwks_uri' => __( 'HTTPS endpoint containing the issuer public signing keys.', 'od-mcp-bridge' ),
			'resource' => __( 'Exact aud claim for this MCP endpoint. Leave blank to use the displayed MCP endpoint.', 'od-mcp-bridge' ),
		);
		?>
		<input type="url" class="regular-text code" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[oauth][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $settings[ $key ] ); ?>" placeholder="https://" />
		<p class="description"><?php echo esc_html( $descriptions[ $key ] ); ?></p>
		<?php
	}

	/** Renders read-only connection and permission diagnostics. */
	private function render_connection_diagnostics() {
		$diagnostics = new Connection_Diagnostics( $this );
		?>
		<h2><?php esc_html_e( 'Connection diagnostics', 'od-mcp-bridge' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These checks inspect local WordPress configuration only. They do not send an external request or store credentials.', 'od-mcp-bridge' ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Check', 'od-mcp-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'od-mcp-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'od-mcp-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $diagnostics->get_checks() as $check ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $check['label'] ); ?></th>
						<td><strong><?php echo esc_html( $this->get_diagnostic_status_label( $check['status'] ) ); ?></strong></td>
						<td><?php echo esc_html( $check['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Ability access', 'od-mcp-bridge' ); ?></h3>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Ability', 'od-mcp-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Setting', 'od-mcp-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Required capability', 'od-mcp-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'OAuth scope', 'od-mcp-bridge' ); ?></th>
					<th scope="col"><?php esc_html_e( 'MCP Maintenance Reader', 'od-mcp-bridge' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $diagnostics->get_ability_access() as $ability ) : ?>
					<tr>
						<th scope="row"><code><?php echo esc_html( 'od-mcp-bridge/' . $ability['key'] ); ?></code></th>
						<td><?php echo $ability['enabled'] ? esc_html__( 'Enabled', 'od-mcp-bridge' ) : esc_html__( 'Disabled', 'od-mcp-bridge' ); ?></td>
						<td>
							<?php foreach ( $ability['capabilities'] as $index => $capability ) : ?>
								<?php if ( $index > 0 ) : ?>
									<span aria-hidden="true">, </span>
								<?php endif; ?>
								<code><?php echo esc_html( $capability ); ?></code>
							<?php endforeach; ?>
							<?php if ( 'any' === $ability['match'] ) : ?>
								<?php esc_html_e( ' (any)', 'od-mcp-bridge' ); ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $ability['oauth_scope'] ); ?></code></td>
						<td><?php echo $ability['role_access'] ? esc_html__( 'Allowed', 'od-mcp-bridge' ) : esc_html__( 'Not allowed', 'od-mcp-bridge' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Returns a translated diagnostic status label.
	 *
	 * @param string $status Diagnostic status.
	 * @return string
	 */
	private function get_diagnostic_status_label( $status ) {
		$labels = array(
			'good'    => __( 'Ready', 'od-mcp-bridge' ),
			'warning' => __( 'Check', 'od-mcp-bridge' ),
			'error'   => __( 'Action required', 'od-mcp-bridge' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Renders explanatory text for the abilities section.
	 *
	 * @return void
	 */
	public function render_abilities_description() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Only enabled abilities are registered and exposed. Public content abilities are enabled by default. Maintenance and write abilities are disabled by default; write capabilities are never granted to the MCP Maintenance Reader role.', 'od-mcp-bridge' )
		);
	}

	/**
	 * Renders one ability checkbox.
	 *
	 * @param array<string, string> $args Field arguments.
	 * @return void
	 */
	public function render_ability_field( $args ) {
		$key = isset( $args['key'] ) ? $args['key'] : '';
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[abilities][<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( $this->is_ability_enabled( $key ) ); ?>
			/>
			<code><?php echo esc_html( 'od-mcp-bridge/' . $key ); ?></code>
		</label>
		<?php
	}

	/**
	 * Returns stored settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings() {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );

		if ( ! is_array( $settings ) ) {
			return $this->get_defaults();
		}

		$defaults  = $this->get_defaults();
		$abilities = isset( $settings['abilities'] ) && is_array( $settings['abilities'] ) ? $settings['abilities'] : array();
		$oauth     = isset( $settings['oauth'] ) && is_array( $settings['oauth'] ) ? $settings['oauth'] : array();

		return array(
			'abilities' => array_merge( $defaults['abilities'], $abilities ),
			'oauth'     => array_merge( $defaults['oauth'], $oauth ),
		);
	}

	/**
	 * Returns default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_defaults() {
		$defaults = array_fill_keys( $this->abilities, false );

		foreach ( $this->default_enabled_abilities as $key ) {
			$defaults[ $key ] = true;
		}

		return array(
			'abilities' => $defaults,
			'oauth'     => array(
				'mode'     => self::AUTH_APPLICATION_PASSWORD,
				'issuer'   => '',
				'jwks_uri' => '',
				'resource' => '',
			),
		);
	}

	/**
	 * Sanitizes an OAuth endpoint URL and rejects insecure remote HTTP URLs.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	private function sanitize_oauth_url( $value ) {
		$url = esc_url_raw( is_string( $value ) ? trim( $value ) : '', array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$local  = in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
		if ( 'https' !== $scheme && ! $local ) {
			return '';
		}

		return $url;
	}

	/**
	 * Returns the translated label for an ability key.
	 *
	 * @param string $key Ability key.
	 * @return string
	 */
	private function get_ability_label( $key ) {
		$labels = array(
			'get-site-info'            => __( 'Site information', 'od-mcp-bridge' ),
			'get-posts'                => __( 'Published post list', 'od-mcp-bridge' ),
			'get-post'                 => __( 'Published post content', 'od-mcp-bridge' ),
			'get-pages'                => __( 'Published page list', 'od-mcp-bridge' ),
			'get-page'                 => __( 'Published page content', 'od-mcp-bridge' ),
			'get-terms'                => __( 'Categories and tags', 'od-mcp-bridge' ),
			'create-post-draft'        => __( 'Create post draft (write)', 'od-mcp-bridge' ),
			'create-page-draft'        => __( 'Create page draft (write)', 'od-mcp-bridge' ),
			'create-template-part'     => __( 'Create template part (write)', 'od-mcp-bridge' ),
			'get-update-status'        => __( 'Update status (maintenance)', 'od-mcp-bridge' ),
			'get-plugins'              => __( 'Plugin inventory (maintenance)', 'od-mcp-bridge' ),
			'get-themes'               => __( 'Theme inventory (maintenance)', 'od-mcp-bridge' ),
			'get-site-health'          => __( 'Site Health summary (maintenance)', 'od-mcp-bridge' ),
			'get-content-summary'      => __( 'Content summary (maintenance)', 'od-mcp-bridge' ),
			'get-stale-content'        => __( 'Stale content (maintenance)', 'od-mcp-bridge' ),
			'get-cron-status'          => __( 'WP-Cron status (maintenance)', 'od-mcp-bridge' ),
			'get-maintenance-snapshot' => __( 'Maintenance snapshot (maintenance)', 'od-mcp-bridge' ),
			'get-security-posture'     => __( 'Security posture summary (maintenance)', 'od-mcp-bridge' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}
}
