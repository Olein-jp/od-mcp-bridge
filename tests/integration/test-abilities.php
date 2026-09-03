<?php
/**
 * Ability integration tests.
 *
 * @package OdMcpBridge
 */

use Olein\MCPBridge\Abilities;
use Olein\MCPBridge\Admin\Settings_Page;
use Olein\MCPBridge\Draft_Page_Creator;
use Olein\MCPBridge\Draft_Post_Creator;
use Olein\MCPBridge\Role_Manager;
use Olein\MCPBridge\Template_Part_Creator;

/**
 * Tests public Ability contracts in a real WordPress environment.
 */
class Test_OD_MCP_Bridge_Abilities extends WP_UnitTestCase {

	/**
	 * All plugin ability names.
	 *
	 * @var array<int, string>
	 */
	private $ability_names = array(
		'od-mcp-bridge/get-site-info',
		'od-mcp-bridge/get-posts',
		'od-mcp-bridge/get-post',
		'od-mcp-bridge/get-pages',
		'od-mcp-bridge/get-page',
		'od-mcp-bridge/get-terms',
		'od-mcp-bridge/create-post-draft',
		'od-mcp-bridge/create-page-draft',
		'od-mcp-bridge/create-template-part',
		'od-mcp-bridge/get-update-status',
		'od-mcp-bridge/get-plugins',
		'od-mcp-bridge/get-themes',
		'od-mcp-bridge/get-site-health',
		'od-mcp-bridge/get-content-summary',
		'od-mcp-bridge/get-stale-content',
		'od-mcp-bridge/get-cron-status',
		'od-mcp-bridge/get-maintenance-snapshot',
		'od-mcp-bridge/get-security-posture',
	);

	/**
	 * Default public-content abilities.
	 *
	 * @var array<int, string>
	 */
	private $default_ability_names = array(
		'od-mcp-bridge/get-site-info',
		'od-mcp-bridge/get-posts',
		'od-mcp-bridge/get-post',
		'od-mcp-bridge/get-pages',
		'od-mcp-bridge/get-page',
		'od-mcp-bridge/get-terms',
	);

	/** Restores state changed by individual tests. */
	public function tear_down() {
		wp_set_current_user( 0 );
		delete_option( Settings_Page::OPTION_NAME );
		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		wp_clear_scheduled_hook( 'od_mcp_bridge_test_event' );
		$this->unregister_all_abilities();
		$this->register_abilities_during_init();

		parent::tear_down();
	}

	/** Confirms the supported WordPress minimum. */
	public function test_runs_on_wordpress_6_9_or_later() {
		$this->assertTrue( version_compare( get_bloginfo( 'version' ), '6.9', '>=' ) );
	}

	/** Confirms default abilities and their read-only metadata. */
	public function test_registers_default_abilities_with_schemas() {
		foreach ( $this->default_ability_names as $ability_name ) {
			$ability = wp_get_ability( $ability_name );

			$this->assertInstanceOf( WP_Ability::class, $ability );
			$this->assertSame( 'od-mcp-bridge', $ability->get_category() );
			$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
			$this->assertSame(
				array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
				$ability->get_meta_item( 'annotations' )
			);
			$this->assertSame( 'object', $ability->get_output_schema()['type'] );
		}

		$this->assertSame(
			array( 'items', 'pagination' ),
			wp_get_ability( 'od-mcp-bridge/get-pages' )->get_output_schema()['required']
		);
		$this->assertSame(
			array( 'page_id' ),
			wp_get_ability( 'od-mcp-bridge/get-page' )->get_input_schema()['required']
		);
		$this->assertSame(
			array( 'category', 'post_tag' ),
			wp_get_ability( 'od-mcp-bridge/get-terms' )->get_input_schema()['properties']['taxonomy']['enum']
		);
	}

	/** Confirms public abilities require authentication with read. */
	public function test_public_abilities_require_read_capability() {
		$ability = wp_get_ability( 'od-mcp-bridge/get-site-info' );

		wp_set_current_user( 0 );
		$this->assertFalse( $ability->check_permissions() );
		$this->assertWPError( $ability->execute() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( $ability->check_permissions() );
		$this->assertIsArray( $ability->execute() );
	}

	/** Confirms published post filtering, search, and pagination. */
	public function test_get_posts_filters_searches_and_paginates() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$alpha_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'OD Needle Alpha',
			)
		);
		$beta_id  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'OD Needle Beta',
			)
		);
		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'OD Needle Draft',
			)
		);
		$page_id  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'OD Needle Page',
				'post_type'   => 'page',
			)
		);
		$ability  = wp_get_ability( 'od-mcp-bridge/get-posts' );
		$result   = $ability->execute(
			array(
				'order'    => 'asc',
				'orderby'  => 'title',
				'page'     => 2,
				'per_page' => 1,
				'search'   => 'OD Needle',
			)
		);

		$this->assertSame( array( $beta_id ), wp_list_pluck( $result['items'], 'id' ) );
		$all = $ability->execute(
			array(
				'per_page' => 100,
				'search'   => 'OD Needle',
			)
		);
		$ids = wp_list_pluck( $all['items'], 'id' );
		$this->assertContains( $alpha_id, $ids );
		$this->assertNotContains( $draft_id, $ids );
		$this->assertNotContains( $page_id, $ids );
	}

	/** Confirms published pages and allowed terms are exposed safely. */
	public function test_get_pages_page_and_terms_exclude_non_public_content() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$page_id  = self::factory()->post->create(
			array(
				'menu_order'   => 4,
				'post_content' => '<!-- wp:paragraph --><p>Page content.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
				'post_title'   => 'OD Public Page',
				'post_type'    => 'page',
			)
		);
		$draft_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => 'page',
			)
		);
		$term_id  = self::factory()->term->create(
			array(
				'name'     => 'OD Category',
				'taxonomy' => 'category',
			)
		);

		$pages = wp_get_ability( 'od-mcp-bridge/get-pages' )->execute( array( 'search' => 'OD Public Page' ) );
		$this->assertSame( array( $page_id ), wp_list_pluck( $pages['items'], 'id' ) );

		$page = wp_get_ability( 'od-mcp-bridge/get-page' )->execute( array( 'page_id' => $page_id ) );
		$this->assertSame( 4, $page['menu_order'] );
		$this->assertStringContainsString( 'Page content.', $page['content'] );
		$this->assertWPError( wp_get_ability( 'od-mcp-bridge/get-page' )->execute( array( 'page_id' => $draft_id ) ) );

		$terms = wp_get_ability( 'od-mcp-bridge/get-terms' )->execute(
			array(
				'taxonomy' => 'category',
				'per_page' => 100,
			)
		);
		$this->assertContains( $term_id, wp_list_pluck( $terms['items'], 'id' ) );
		$this->assertWPError( wp_get_ability( 'od-mcp-bridge/get-terms' )->execute( array( 'taxonomy' => 'invalid' ) ) );
	}

	/** Confirms maintenance abilities are disabled by default. */
	public function test_maintenance_abilities_are_disabled_by_default() {
		$registry = WP_Abilities_Registry::get_instance();
		foreach ( array_diff( $this->ability_names, $this->default_ability_names ) as $ability_name ) {
			$this->assertFalse( $registry->is_registered( $ability_name ) );
		}
	}

	/** Confirms the opt-in draft ability has a strict write contract. */
	public function test_post_draft_ability_registers_safe_write_metadata_and_schema() {
		$this->enable_abilities( array( 'create-post-draft' ) );
		$ability = wp_get_ability( 'od-mcp-bridge/create-post-draft' );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			),
			$ability->get_meta_item( 'annotations' )
		);
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
		$this->assertSame( array( 'request_id', 'title', 'content' ), $ability->get_input_schema()['required'] );
		$this->assertSame( 20, $ability->get_input_schema()['properties']['categories']['maxItems'] );
		$this->assertSame( array( 'id', 'status', 'edit_url', 'created' ), $ability->get_output_schema()['required'] );
	}

	/** Confirms draft creation requires edit_posts and excludes the maintenance reader. */
	public function test_post_draft_ability_enforces_write_permissions() {
		$this->enable_abilities( array( 'create-post-draft' ) );
		$ability = wp_get_ability( 'od-mcp-bridge/create-post-draft' );
		$input   = $this->get_draft_input();

		wp_set_current_user( self::factory()->user->create( array( 'role' => Role_Manager::ROLE ) ) );
		$this->assertFalse( $ability->check_permissions( $input ) );
		$this->assertWPError( $ability->execute( $input ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertTrue( $ability->check_permissions( $input ) );
	}

	/** Confirms draft fields, category assignment, and protected audit metadata. */
	public function test_post_draft_ability_creates_only_a_sanitized_draft() {
		$this->enable_abilities( array( 'create-post-draft' ) );
		$user_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$category_id = self::factory()->term->create(
			array(
				'name'     => 'Draft category',
				'taxonomy' => 'category',
			)
		);
		wp_set_current_user( $user_id );

		$input  = $this->get_draft_input(
			array(
				'title'      => '<b>Safe draft</b>',
				'content'    => '<!-- wp:paragraph --><p>Allowed</p><!-- /wp:paragraph --><script>alert(1)</script>',
				'excerpt'    => '<strong>Excerpt</strong>',
				'categories' => array( $category_id ),
			)
		);
		$result = wp_get_ability( 'od-mcp-bridge/create-post-draft' )->execute( $input );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'Safe draft', get_post_field( 'post_title', $result['id'] ) );
		$this->assertSame( 'draft', get_post_status( $result['id'] ) );
		$this->assertSame( 'post', get_post_type( $result['id'] ) );
		$this->assertSame( $user_id, (int) get_post_field( 'post_author', $result['id'] ) );
		$this->assertStringNotContainsString( '<script', get_post_field( 'post_content', $result['id'] ) );
		$this->assertContains( $category_id, wp_get_post_categories( $result['id'] ) );
		$this->assertSame( '1', get_post_meta( $result['id'], Draft_Post_Creator::META_CREATED, true ) );
		$this->assertSame( $input['request_id'], get_post_meta( $result['id'], Draft_Post_Creator::META_REQUEST_ID, true ) );
		$this->assertNotSame( '', get_post_meta( $result['id'], Draft_Post_Creator::META_PAYLOAD_HASH, true ) );
	}

	/** Confirms request IDs prevent duplicate drafts and reject payload changes. */
	public function test_post_draft_ability_is_idempotent_per_user_and_payload() {
		$this->enable_abilities( array( 'create-post-draft' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$ability = wp_get_ability( 'od-mcp-bridge/create-post-draft' );
		$input   = $this->get_draft_input();

		$first  = $ability->execute( $input );
		$replay = $ability->execute( $input );

		$this->assertTrue( $first['created'] );
		$this->assertFalse( $replay['created'] );
		$this->assertSame( $first['id'], $replay['id'] );

		$input['title'] = 'Changed payload';
		$conflict       = $ability->execute( $input );
		$this->assertWPError( $conflict );
		$this->assertSame( 'od_mcp_bridge_draft_request_conflict', $conflict->get_error_code() );
	}

	/** Confirms an in-flight request is rejected without creating a duplicate. */
	public function test_post_draft_ability_rejects_concurrent_request() {
		$this->enable_abilities( array( 'create-post-draft' ) );
		$user_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$input   = $this->get_draft_input();
		wp_set_current_user( $user_id );
		$lock_key = 'od_mcp_bridge_draft_lock_' . md5( get_current_blog_id() . '|' . $user_id . '|' . $input['request_id'] );
		add_option( $lock_key, time(), '', false );

		$result = wp_get_ability( 'od-mcp-bridge/create-post-draft' )->execute( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'od_mcp_bridge_draft_request_in_progress', $result->get_error_code() );
		$this->assertSame(
			0,
			count(
				get_posts(
					array(
						'post_type'   => 'post',
						'post_status' => 'draft',
						'author'      => $user_id,
					)
				)
			)
		);
		delete_option( $lock_key );
	}

	/** Confirms strict schema and category validation reject unsafe inputs. */
	public function test_post_draft_ability_rejects_invalid_inputs() {
		$this->enable_abilities( array( 'create-post-draft' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$ability = wp_get_ability( 'od-mcp-bridge/create-post-draft' );

		$unknown           = $this->get_draft_input();
		$unknown['status'] = 'publish';
		$this->assertWPError( $ability->execute( $unknown ) );

		$missing_category               = $this->get_draft_input( array( 'request_id' => '22222222-2222-4222-8222-222222222222' ) );
		$missing_category['categories'] = array( 99999999 );
		$this->assertWPError( $ability->execute( $missing_category ) );

		$empty_title          = $this->get_draft_input( array( 'request_id' => '33333333-3333-4333-8333-333333333333' ) );
		$empty_title['title'] = '<b></b>';
		$this->assertWPError( $ability->execute( $empty_title ) );
	}

	/** Confirms page draft creation is opt-in, capability-gated, and idempotent. */
	public function test_page_draft_ability_creates_only_a_page_draft() {
		$this->enable_abilities( array( 'create-page-draft' ) );
		$ability = wp_get_ability( 'od-mcp-bridge/create-page-draft' );
		$input   = $this->get_page_draft_input(
			array(
				'menu_order' => 7,
				'title'      => '<b>Safe page</b>',
				'content'    => '<!-- wp:paragraph --><p>Allowed</p><!-- /wp:paragraph --><script>alert(1)</script>',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertFalse( $ability->check_permissions( $input ) );

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );
		$this->assertTrue( $ability->check_permissions( $input ) );
		$first  = $ability->execute( $input );
		$replay = $ability->execute( $input );

		$this->assertTrue( $first['created'] );
		$this->assertFalse( $replay['created'] );
		$this->assertSame( $first['id'], $replay['id'] );
		$this->assertSame( 'page', get_post_type( $first['id'] ) );
		$this->assertSame( 'draft', get_post_status( $first['id'] ) );
		$this->assertSame( 'Safe page', get_post_field( 'post_title', $first['id'] ) );
		$this->assertSame( 7, (int) get_post_field( 'menu_order', $first['id'] ) );
		$this->assertSame( $user_id, (int) get_post_field( 'post_author', $first['id'] ) );
		$this->assertStringNotContainsString( '<script', get_post_field( 'post_content', $first['id'] ) );
		$this->assertSame( '1', get_post_meta( $first['id'], Draft_Page_Creator::META_CREATED, true ) );
	}

	/** Confirms page draft parent validation rejects non-page content. */
	public function test_page_draft_ability_rejects_invalid_parent() {
		$this->enable_abilities( array( 'create-page-draft' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = self::factory()->post->create();
		$input   = $this->get_page_draft_input( array( 'parent_id' => $post_id ) );

		$result = wp_get_ability( 'od-mcp-bridge/create-page-draft' )->execute( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'od_mcp_bridge_invalid_page_draft_input', $result->get_error_code() );
	}

	/** Confirms template part creation uses a dedicated capability and never overwrites. */
	public function test_template_part_ability_creates_only_a_new_part_for_active_block_theme() {
		if ( ! wp_get_theme( 'twentytwentyfive' )->exists() ) {
			$this->markTestSkipped( 'The Twenty Twenty-Five block theme is unavailable.' );
		}

		$previous_theme = get_stylesheet();
		switch_theme( 'twentytwentyfive' );
		$this->enable_abilities( array( 'create-template-part' ) );
		$ability = wp_get_ability( 'od-mcp-bridge/create-template-part' );
		$input   = $this->get_template_part_input();
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$user = wp_get_current_user();

		try {
			$this->assertFalse( $ability->check_permissions( $input ) );
			$user->add_cap( Role_Manager::CREATE_TEMPLATE_PARTS );
			$this->assertTrue( $ability->check_permissions( $input ) );

			$first  = $ability->execute( $input );
			$replay = $ability->execute( $input );

			$this->assertTrue( $first['created'] );
			$this->assertFalse( $replay['created'] );
			$this->assertSame( $first['id'], $replay['id'] );
			$this->assertSame( 'wp_template_part', get_post_type( $first['id'] ) );
			$this->assertSame( 'publish', get_post_status( $first['id'] ) );
			$this->assertSame( get_stylesheet() . '//mcp-test-part', $first['template_id'] );
			$this->assertContains( get_stylesheet(), wp_get_post_terms( $first['id'], 'wp_theme', array( 'fields' => 'slugs' ) ) );
			$this->assertContains( 'header', wp_get_post_terms( $first['id'], 'wp_template_part_area', array( 'fields' => 'slugs' ) ) );
			$this->assertSame( '1', get_post_meta( $first['id'], Template_Part_Creator::META_CREATED, true ) );

			$duplicate               = $input;
			$duplicate['request_id'] = '44444444-4444-4444-8444-444444444444';
			$result                  = $ability->execute( $duplicate );
			$this->assertWPError( $result );
			$this->assertSame( 'od_mcp_bridge_template_part_exists', $result->get_error_code() );
		} finally {
			$user->remove_cap( Role_Manager::CREATE_TEMPLATE_PARTS );
			switch_theme( $previous_theme );
		}
	}

	/** Confirms maintenance abilities retain read-only metadata and elevated permissions. */
	public function test_maintenance_abilities_enforce_permissions() {
		$maintenance_keys = array(
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
		$this->enable_abilities( $maintenance_keys );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $maintenance_keys as $key ) {
			$ability = wp_get_ability( 'od-mcp-bridge/' . $key );
			$this->assertSame( 'get-stale-content' === $key, $ability->check_permissions() );
			$this->assertTrue( $ability->get_meta_item( 'annotations' )['readonly'] );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( $maintenance_keys as $key ) {
			$this->assertTrue( wp_get_ability( 'od-mcp-bridge/' . $key )->check_permissions() );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => Role_Manager::ROLE ) ) );
		foreach ( $maintenance_keys as $key ) {
			$this->assertTrue( wp_get_ability( 'od-mcp-bridge/' . $key )->check_permissions() );
		}
	}

	/** Confirms cached update status is capability-filtered. */
	public function test_get_update_status_uses_cached_data_and_filters_sections() {
		$this->enable_abilities( array( 'get-update-status' ) );
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'response'     => array(
					'od-mcp-bridge/od-mcp-bridge.php' => (object) array( 'new_version' => '9.9.9' ),
				),
				'translations' => array(
					(object) array(
						'type'     => 'plugin',
						'slug'     => 'od-mcp-bridge',
						'language' => 'ja',
						'version'  => '9.9.9',
					),
				),
			)
		);
		set_site_transient(
			'update_themes',
			(object) array(
				'translations' => array(
					(object) array(
						'type'     => 'theme',
						'slug'     => 'twentytwentyfive',
						'language' => 'ja',
						'version'  => '1.0',
					),
				),
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( Role_Manager::VIEW_PLUGIN_UPDATES );
		wp_set_current_user( $user_id );
		$result = wp_get_ability( 'od-mcp-bridge/get-update-status' )->execute();

		$this->assertArrayHasKey( 'plugins', $result );
		$this->assertArrayHasKey( 'translations', $result );
		$this->assertArrayNotHasKey( 'core', $result );
		$this->assertArrayNotHasKey( 'themes', $result );
		$this->assertSame( '9.9.9', $result['plugins']['items'][0]['available_version'] );
		$this->assertSame( array( 'plugin' ), wp_list_pluck( $result['translations']['items'], 'type' ) );

		delete_site_transient( 'update_plugins' );
		$result = wp_get_ability( 'od-mcp-bridge/get-update-status' )->execute();
		$this->assertFalse( $result['plugins']['initialized'] );
		$this->assertSame( 0, $result['plugins']['update_count'] );
	}

	/** Confirms inventory abilities require elevated capabilities and omit paths. */
	public function test_plugin_and_theme_inventory_are_sanitized() {
		$this->enable_abilities( array( 'get-plugins', 'get-themes' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$plugins = wp_get_ability( 'od-mcp-bridge/get-plugins' )->execute();
		$themes  = wp_get_ability( 'od-mcp-bridge/get-themes' )->execute();

		$this->assertNotEmpty( $plugins['items'] );
		$this->assertArrayNotHasKey( 'path', $plugins['items'][0] );
		$this->assertArrayNotHasKey( 'file', $plugins['items'][0] );
		$this->assertNotEmpty( wp_list_filter( $themes['items'], array( 'active' => true ) ) );
	}

	/** Confirms content summary and stale content enforce visibility boundaries. */
	public function test_content_summary_and_stale_content() {
		$this->enable_abilities( array( 'get-content-summary', 'get-stale-content' ) );
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 800 * DAY_IN_SECONDS ) );
		$old_id   = self::factory()->post->create(
			array(
				'post_date'     => $old_date,
				'post_date_gmt' => $old_date,
				'post_status'   => 'publish',
				'post_title'    => 'OD Old Published',
			)
		);
		$draft_id = self::factory()->post->create(
			array(
				'post_date'   => $old_date,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => Role_Manager::ROLE ) ) );
		$summary = wp_get_ability( 'od-mcp-bridge/get-content-summary' )->execute();
		$this->assertGreaterThanOrEqual( 1, $summary['types']['post']['published'] );
		$this->assertGreaterThanOrEqual( 1, $summary['types']['post']['drafts'] );

		$stale = wp_get_ability( 'od-mcp-bridge/get-stale-content' )->execute(
			array(
				'older_than_days' => 730,
				'per_page'        => 100,
			)
		);
		$ids   = wp_list_pluck( $stale['items'], 'id' );
		$this->assertContains( $old_id, $ids );
		$this->assertNotContains( $draft_id, $ids );
		$this->assertWPError( wp_get_ability( 'od-mcp-bridge/get-stale-content' )->execute( array( 'older_than_days' => 0 ) ) );
		$this->assertWPError( wp_get_ability( 'od-mcp-bridge/get-stale-content' )->execute( array( 'post_type' => 'attachment' ) ) );
		$this->assertWPError( wp_get_ability( 'od-mcp-bridge/get-stale-content' )->execute( array( 'per_page' => 101 ) ) );
	}

	/** Confirms Site Health and Cron outputs are sanitized. */
	public function test_site_health_and_cron_status_are_sanitized() {
		$this->enable_abilities( array( 'get-site-health', 'get-cron-status' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$timestamp = time() + HOUR_IN_SECONDS;
		wp_schedule_single_event( $timestamp, 'od_mcp_bridge_test_event', array( 'secret-a' ) );
		wp_schedule_single_event( $timestamp, 'od_mcp_bridge_test_event', array( 'secret-b' ) );
		wp_schedule_event( time() - HOUR_IN_SECONDS, 'hourly', 'od_mcp_bridge_test_event', array( 'secret-overdue' ) );

		$health = wp_get_ability( 'od-mcp-bridge/get-site-health' )->execute();
		$cron   = wp_get_ability( 'od-mcp-bridge/get-cron-status' )->execute( array( 'per_page' => 100 ) );

		$this->assertContains( $health['status'], array( 'good', 'recommended', 'critical' ), true );
		$this->assertArrayNotHasKey( 'actions', $health['tests'][0] );
		$this->assertGreaterThanOrEqual( 2, $cron['total_events'] );
		$this->assertGreaterThanOrEqual( 1, $cron['overdue_events'] );
		$this->assertGreaterThanOrEqual( 1, $cron['duplicate_candidates'] );
		$this->assertStringNotContainsString( 'secret', wp_json_encode( $cron ) );
	}

	/** Confirms security posture is local, schema-safe, and omits sensitive values. */
	public function test_security_posture_is_local_and_sanitized() {
		$this->enable_abilities( array( 'get-security-posture' ) );
		$user_id = self::factory()->user->create(
			array(
				'role'       => Role_Manager::ROLE,
				'user_email' => 'security-posture-secret@example.test',
				'user_login' => 'security-posture-secret-user',
			)
		);
		wp_set_current_user( $user_id );

		$password = WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => 'Security Posture Secret Client' )
		);
		$this->assertIsArray( $password );

		$http_requests = 0;
		$block_http    = static function () use ( &$http_requests ) {
			++$http_requests;

			return new WP_Error( 'unexpected_http_request', 'Security posture must not use HTTP.' );
		};
		add_filter( 'pre_http_request', $block_http, 10, 3 );

		try {
			$ability = wp_get_ability( 'od-mcp-bridge/get-security-posture' );
			$result  = $ability->execute();
		} finally {
			remove_filter( 'pre_http_request', $block_http, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertSame( 0, $http_requests );
		$this->assertContains( $result['status'], array( 'good', 'recommended', 'attention', 'unknown' ), true );
		$this->assertSame( 'current_site', $result['administrators']['scope'] );
		$this->assertArrayHasKey( 'available_for_current_user', $result['application_passwords'] );
		$this->assertArrayNotHasKey( 'count', $result['application_passwords'] );

		$encoded = wp_json_encode( $result );
		$this->assertStringNotContainsString( 'security-posture-secret', $encoded );
		$this->assertStringNotContainsString( 'Security Posture Secret Client', $encoded );
		$this->assertStringNotContainsString( ABSPATH, $encoded );
		$this->assertStringNotContainsString( $password[0], $encoded );
	}

	/** Confirms the snapshot respects disabled sections and reuses abilities. */
	public function test_maintenance_snapshot_handles_available_and_disabled_sections() {
		$this->enable_abilities(
			array(
				'get-update-status',
				'get-plugins',
				'get-themes',
				'get-site-health',
				'get-content-summary',
				'get-cron-status',
				'get-maintenance-snapshot',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$snapshot = wp_get_ability( 'od-mcp-bridge/get-maintenance-snapshot' )->execute();
		$this->assertSame( 'available', $snapshot['site']['status'] );
		$this->assertSame( 'available', $snapshot['site_health']['status'] );

		$settings                            = get_option( Settings_Page::OPTION_NAME );
		$settings['abilities']['get-themes'] = false;
		update_option( Settings_Page::OPTION_NAME, $settings );
		$snapshot = wp_get_ability( 'od-mcp-bridge/get-maintenance-snapshot' )->execute();
		$this->assertSame( 'unavailable', $snapshot['themes']['status'] );
		$this->assertSame( 'disabled', $snapshot['themes']['reason'] );
	}

	/** Confirms individual settings omit disabled abilities. */
	public function test_disabled_ability_is_not_registered() {
		$this->unregister_all_abilities();
		update_option(
			Settings_Page::OPTION_NAME,
			array(
				'abilities' => array(
					'get-site-info' => true,
					'get-posts'     => false,
					'get-post'      => true,
					'get-pages'     => true,
					'get-page'      => true,
					'get-terms'     => true,
				),
			)
		);
		$this->register_abilities_during_init();

		$this->assertFalse( WP_Abilities_Registry::get_instance()->is_registered( 'od-mcp-bridge/get-posts' ) );
		$this->assertInstanceOf( WP_Ability::class, wp_get_ability( 'od-mcp-bridge/get-pages' ) );
	}

	/**
	 * Enables selected maintenance abilities and re-registers the plugin.
	 *
	 * @param array<int, string> $ability_keys Ability setting keys to enable.
	 */
	private function enable_abilities( $ability_keys ) {
		$this->unregister_all_abilities();
		$settings = array( 'abilities' => array() );
		foreach ( $ability_keys as $key ) {
			$settings['abilities'][ $key ] = true;
		}
		update_option( Settings_Page::OPTION_NAME, $settings );
		$this->register_abilities_during_init();
	}

	/**
	 * Returns valid draft input with optional overrides.
	 *
	 * @param array<string, mixed> $overrides Input overrides.
	 * @return array<string, mixed>
	 */
	private function get_draft_input( $overrides = array() ) {
		return array_merge(
			array(
				'request_id' => '11111111-1111-4111-8111-111111111111',
				'title'      => 'MCP draft',
				'content'    => '<p>Draft content</p>',
			),
			$overrides
		);
	}

	/**
	 * Returns valid page draft input with optional overrides.
	 *
	 * @param array<string, mixed> $overrides Input overrides.
	 */
	private function get_page_draft_input( $overrides = array() ) {
		return array_merge(
			array(
				'request_id' => '22222222-2222-4222-8222-222222222222',
				'title'      => 'MCP page draft',
				'content'    => '<p>Page draft content</p>',
			),
			$overrides
		);
	}

	/** Returns valid template part input. */
	private function get_template_part_input() {
		return array(
			'request_id' => '33333333-3333-4333-8333-333333333333',
			'title'      => 'MCP test part',
			'slug'       => 'mcp-test-part',
			'content'    => '<!-- wp:paragraph --><p>Template part content</p><!-- /wp:paragraph -->',
			'area'       => 'header',
		);
	}

	/** Unregisters every plugin ability that is currently present. */
	private function unregister_all_abilities() {
		$registry = WP_Abilities_Registry::get_instance();
		foreach ( $this->ability_names as $ability_name ) {
			if ( $registry->is_registered( $ability_name ) ) {
				wp_unregister_ability( $ability_name );
			}
		}
	}

	/** Runs registration on the required WordPress hook. */
	private function register_abilities_during_init() {
		global $wp_filter;

		$hook_name      = 'wp_abilities_api_init';
		$original_hooks = isset( $wp_filter[ $hook_name ] ) ? $wp_filter[ $hook_name ] : null;
		$abilities      = new Abilities( new Settings_Page() );

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
