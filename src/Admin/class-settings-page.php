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
		'get-update-status',
		'get-plugins',
		'get-themes',
		'get-site-health',
		'get-content-summary',
		'get-stale-content',
		'get-cron-status',
		'get-maintenance-snapshot',
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
	 * @return array<string, array<string, bool>>
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array( 'abilities' => array() );
		$submitted = is_array( $input ) && isset( $input['abilities'] ) && is_array( $input['abilities'] )
			? $input['abilities']
			: array();

		foreach ( $this->abilities as $key ) {
			$sanitized['abilities'][ $key ] = isset( $submitted[ $key ] ) && '1' === (string) $submitted[ $key ];
		}

		return $sanitized;
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

			<p class="description">
				<?php esc_html_e( 'Use a dedicated Subscriber user and an Application Password. Store credentials only in the MCP client environment; this plugin never stores them.', 'od-mcp-bridge' ); ?>
			</p>
			<ol>
				<li>
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to the Add New User screen. */
							__( 'Create a dedicated user with the Subscriber role on the <a href="%s">Add New User</a> screen.', 'od-mcp-bridge' ),
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

	/**
	 * Renders explanatory text for the abilities section.
	 *
	 * @return void
	 */
	public function render_abilities_description() {
		printf(
			'<p>%s</p>',
			esc_html__( 'Only enabled abilities are registered and exposed. Public content abilities are enabled by default; maintenance abilities are disabled by default and require elevated WordPress capabilities.', 'od-mcp-bridge' )
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
	 * @return array<string, array<string, bool>>
	 */
	private function get_settings() {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );

		if ( ! is_array( $settings ) || ! isset( $settings['abilities'] ) || ! is_array( $settings['abilities'] ) ) {
			return $this->get_defaults();
		}

		return array(
			'abilities' => array_merge( $this->get_defaults()['abilities'], $settings['abilities'] ),
		);
	}

	/**
	 * Returns default settings.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function get_defaults() {
		$defaults = array_fill_keys( $this->abilities, false );

		foreach ( $this->default_enabled_abilities as $key ) {
			$defaults[ $key ] = true;
		}

		return array(
			'abilities' => $defaults,
		);
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
			'get-update-status'        => __( 'Update status (maintenance)', 'od-mcp-bridge' ),
			'get-plugins'              => __( 'Plugin inventory (maintenance)', 'od-mcp-bridge' ),
			'get-themes'               => __( 'Theme inventory (maintenance)', 'od-mcp-bridge' ),
			'get-site-health'          => __( 'Site Health summary (maintenance)', 'od-mcp-bridge' ),
			'get-content-summary'      => __( 'Content summary (maintenance)', 'od-mcp-bridge' ),
			'get-stale-content'        => __( 'Stale content (maintenance)', 'od-mcp-bridge' ),
			'get-cron-status'          => __( 'WP-Cron status (maintenance)', 'od-mcp-bridge' ),
			'get-maintenance-snapshot' => __( 'Maintenance snapshot (maintenance)', 'od-mcp-bridge' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}
}
