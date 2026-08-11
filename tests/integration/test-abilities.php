<?php
/**
 * Ability integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Abilities;
use Olein\MCPBridge\Admin\Settings_Page;

/**
 * Tests the plugin's public Ability contracts in a real WordPress environment.
 */
class Test_OD_MCP_Bridge_Abilities extends WP_UnitTestCase {

	/**
	 * Plugin ability names.
	 *
	 * @var array<int, string>
	 */
	private $ability_names = array(
		'od-mcp-bridge/get-site-info',
		'od-mcp-bridge/get-posts',
		'od-mcp-bridge/get-post',
	);

	/**
	 * Restores plugin state changed by individual tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		delete_option( Settings_Page::OPTION_NAME );
		$this->restore_default_abilities();

		parent::tear_down();
	}

	/**
	 * Confirms the real WordPress runtime meets the supported minimum.
	 *
	 * @return void
	 */
	public function test_runs_on_wordpress_6_9_or_later() {
		$this->assertTrue( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) );
	}

	/**
	 * Confirms all abilities expose read-only metadata and their expected schemas.
	 *
	 * @return void
	 */
	public function test_registers_abilities_with_readonly_metadata_and_schemas() {
		foreach ( $this->ability_names as $ability_name ) {
			$ability = wp_get_ability( $ability_name );

			$this->assertInstanceOf( WP_Ability::class, $ability );
			$this->assertSame( 'od-mcp-bridge', $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );

			$annotations = $ability->get_meta_item( 'annotations' );
			$this->assertTrue( $annotations['readonly'] );
			$this->assertFalse( $annotations['destructive'] );
			$this->assertTrue( $annotations['idempotent'] );
			$this->assertSame( 'object', $ability->get_output_schema()['type'] );
		}

		$site_info_schema = wp_get_ability( 'od-mcp-bridge/get-site-info' )->get_output_schema();
		$this->assertSame(
			array( 'name', 'description', 'url', 'language', 'timezone', 'wordpress_version' ),
			$site_info_schema['required']
		);

		$posts_ability = wp_get_ability( 'od-mcp-bridge/get-posts' );
		$this->assertSame( array( 'items', 'pagination' ), $posts_ability->get_output_schema()['required'] );
		$this->assertArrayHasKey( 'search', $posts_ability->get_input_schema()['properties'] );

		$post_ability = wp_get_ability( 'od-mcp-bridge/get-post' );
		$this->assertSame( array( 'post_id' ), $post_ability->get_input_schema()['required'] );
		$this->assertArrayHasKey( 'content', $post_ability->get_output_schema()['properties'] );
	}

	/**
	 * Confirms anonymous execution is rejected and a subscriber can execute.
	 *
	 * @return void
	 */
	public function test_requires_read_capability() {
		$ability = wp_get_ability( 'od-mcp-bridge/get-site-info' );

		wp_set_current_user( 0 );
		$this->assertFalse( $ability->check_permissions() );

		$anonymous_result = $ability->execute();
		$this->assertWPError( $anonymous_result );
		$this->assertSame( 'ability_invalid_permissions', $anonymous_result->get_error_code() );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$this->assertTrue( $ability->check_permissions() );
		$this->assertIsArray( $ability->execute() );
	}

	/**
	 * Confirms get-posts returns only published posts and honors query parameters.
	 *
	 * @return void
	 */
	public function test_get_posts_filters_searches_and_paginates_deterministically() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$alpha_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'OD MCP Needle Alpha',
			)
		);
		$beta_id  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'OD MCP Needle Beta',
			)
		);
		$gamma_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'OD MCP Needle Gamma',
			)
		);

		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'OD MCP Needle Draft',
			)
		);
		$page_id  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'OD MCP Needle Page',
				'post_type'   => 'page',
			)
		);

		$ability = wp_get_ability( 'od-mcp-bridge/get-posts' );
		$result  = $ability->execute(
			array(
				'order'    => 'asc',
				'orderby'  => 'title',
				'page'     => 2,
				'per_page' => 1,
				'search'   => 'OD MCP Needle',
			)
		);

		$this->assertSame( array( $beta_id ), wp_list_pluck( $result['items'], 'id' ) );
		$this->assertSame(
			array(
				'page'        => 2,
				'per_page'    => 1,
				'total'       => 3,
				'total_pages' => 3,
			),
			$result['pagination']
		);

		$all_results  = $ability->execute(
			array(
				'order'    => 'asc',
				'orderby'  => 'title',
				'per_page' => 100,
				'search'   => 'OD MCP Needle',
			)
		);
		$returned_ids = wp_list_pluck( $all_results['items'], 'id' );

		$this->assertSame( array( $alpha_id, $beta_id, $gamma_id ), $returned_ids );
		$this->assertNotContains( $draft_id, $returned_ids );
		$this->assertNotContains( $page_id, $returned_ids );
	}

	/**
	 * Confirms get-post accepts only published posts.
	 *
	 * @return void
	 */
	public function test_get_post_rejects_non_public_content() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$published_id = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Published content.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'OD MCP Published Post',
			)
		);
		$draft_id     = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$page_id      = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);

		$ability = wp_get_ability( 'od-mcp-bridge/get-post' );
		$result  = $ability->execute( array( 'post_id' => $published_id ) );

		$this->assertSame( $published_id, $result['id'] );
		$this->assertSame( 'OD MCP Published Post', $result['title'] );
		$this->assertStringContainsString( 'Published content.', $result['content'] );

		foreach ( array( $draft_id, $page_id, 9999999 ) as $invalid_id ) {
			$invalid_result = $ability->execute( array( 'post_id' => $invalid_id ) );
			$this->assertWPError( $invalid_result );
			$this->assertSame( 'od_mcp_bridge_post_not_found', $invalid_result->get_error_code() );
		}
	}

	/**
	 * Confirms disabled abilities are omitted during registration.
	 *
	 * @return void
	 */
	public function test_disabled_ability_is_not_registered() {
		foreach ( $this->ability_names as $ability_name ) {
			wp_unregister_ability( $ability_name );
		}

		update_option(
			Settings_Page::OPTION_NAME,
			array(
				'abilities' => array(
					'get-site-info' => true,
					'get-posts'     => false,
					'get-post'      => true,
				),
			)
		);

		$this->register_abilities_during_init();

		$registry = WP_Abilities_Registry::get_instance();
		$this->assertTrue( $registry->is_registered( 'od-mcp-bridge/get-site-info' ) );
		$this->assertFalse( $registry->is_registered( 'od-mcp-bridge/get-posts' ) );
		$this->assertTrue( $registry->is_registered( 'od-mcp-bridge/get-post' ) );
	}

	/**
	 * Re-registers all default abilities after a test changes the registry.
	 *
	 * @return void
	 */
	private function restore_default_abilities() {
		$registry = WP_Abilities_Registry::get_instance();

		foreach ( $this->ability_names as $ability_name ) {
			if ( $registry->is_registered( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}

		$this->register_abilities_during_init();
	}

	/**
	 * Runs the production registration callback on its required WordPress hook.
	 *
	 * @return void
	 */
	private function register_abilities_during_init() {
		global $wp_filter;

		$hook_name      = 'wp_abilities_api_init';
		$original_hooks = isset( $wp_filter[ $hook_name ] ) ? $wp_filter[ $hook_name ] : null;
		$settings       = new Settings_Page();
		$abilities      = new Abilities( $settings );

		remove_all_actions( $hook_name );
		add_action( $hook_name, array( $abilities, 'register_abilities' ) );

		try {
			do_action( $hook_name );
		} finally {
			if ( null === $original_hooks ) {
				unset( $wp_filter[ $hook_name ] );
			} else {
				$wp_filter[ $hook_name ] = $original_hooks;
			}
		}
	}
}
