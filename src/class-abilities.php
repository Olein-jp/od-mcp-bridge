<?php
/**
 * WordPress abilities exposed through MCP.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use Olein\MCPBridge\Admin\Settings_Page;
use Olein\MCPBridge\OAuth\Scope_Policy;
use Throwable;
use WP_Error;
use WP_Query;

/**
 * Registers and executes public content and maintenance abilities.
 */
final class Abilities {

	/** Ability category ID. */
	const CATEGORY = 'od-mcp-bridge';

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

	/** Registers Abilities API hooks. */
	public function register_hooks() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/** Registers the plugin ability category. */
	public function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'OD MCP Bridge', 'od-mcp-bridge' ),
				'description' => __( 'Public content, maintenance information, and opt-in safe content operations exposed by OD MCP Bridge.', 'od-mcp-bridge' ),
			)
		);
	}

	/** Registers enabled read-only abilities. */
	public function register_abilities() {
		$registrations = array(
			'get-site-info'            => 'register_site_info',
			'get-posts'                => 'register_posts',
			'get-post'                 => 'register_post',
			'get-pages'                => 'register_pages',
			'get-page'                 => 'register_page',
			'get-terms'                => 'register_terms',
			'create-post-draft'        => 'register_post_draft',
			'get-update-status'        => 'register_update_status',
			'get-plugins'              => 'register_plugins',
			'get-themes'               => 'register_themes',
			'get-site-health'          => 'register_site_health',
			'get-content-summary'      => 'register_content_summary',
			'get-stale-content'        => 'register_stale_content',
			'get-cron-status'          => 'register_cron_status',
			'get-maintenance-snapshot' => 'register_maintenance_snapshot',
			'get-security-posture'     => 'register_security_posture',
		);

		foreach ( $registrations as $key => $method ) {
			if ( $this->settings->is_ability_enabled( $key ) ) {
				$this->{$method}();
			}
		}
	}

	/** Returns basic site information. */
	public function execute_site_info() {
		return array(
			'name'              => get_bloginfo( 'name' ),
			'description'       => get_bloginfo( 'description' ),
			'url'               => home_url( '/' ),
			'language'          => get_bloginfo( 'language' ),
			'timezone'          => wp_timezone_string(),
			'wordpress_version' => get_bloginfo( 'version' ),
		);
	}

	/**
	 * Returns published posts.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute_posts( $input = array() ) {
		return $this->execute_content_collection( 'post', $input );
	}

	/**
	 * Returns one published post.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_post( $input = array() ) {
		$post_id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

		return $this->execute_single_content( 'post', $post_id );
	}

	/**
	 * Returns published pages.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute_pages( $input = array() ) {
		return $this->execute_content_collection( 'page', $input );
	}

	/**
	 * Returns one published page.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_page( $input = array() ) {
		$page_id = is_array( $input ) && isset( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;

		return $this->execute_single_content( 'page', $page_id );
	}

	/**
	 * Returns categories or tags.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_terms( $input = array() ) {
		$input      = is_array( $input ) ? $input : array();
		$taxonomy   = isset( $input['taxonomy'] ) && in_array( $input['taxonomy'], array( 'category', 'post_tag' ), true ) ? $input['taxonomy'] : 'category';
		$per_page   = isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 10;
		$page       = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$orderby    = isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'name', 'slug', 'count' ), true ) ? $input['orderby'] : 'name';
		$order      = isset( $input['order'] ) && 'desc' === strtolower( $input['order'] ) ? 'DESC' : 'ASC';
		$query_args = array(
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'order'      => $order,
			'orderby'    => $orderby,
			'search'     => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
			'taxonomy'   => $taxonomy,
		);
		$terms      = get_terms( $query_args );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$count_args = $query_args;
		unset( $count_args['number'], $count_args['offset'], $count_args['order'], $count_args['orderby'] );
		$total = wp_count_terms( $count_args );

		if ( is_wp_error( $total ) ) {
			return $total;
		}

		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'id'          => (int) $term->term_id,
				'name'        => (string) $term->name,
				'slug'        => (string) $term->slug,
				'description' => wp_strip_all_tags( $term->description ),
				'count'       => (int) $term->count,
				'taxonomy'    => $taxonomy,
			);
		}

		return array(
			'items'      => $items,
			'pagination' => $this->format_pagination( $page, $per_page, (int) $total ),
		);
	}

	/** Returns cached update information without refreshing it. */
	public function execute_update_status() {
		$result = array( 'generated_at' => gmdate( DATE_W3C ) );

		if ( current_user_can( Role_Manager::VIEW_CORE_UPDATES ) ) {
			$result['core'] = $this->get_core_update_status();
		}
		if ( current_user_can( Role_Manager::VIEW_PLUGIN_UPDATES ) ) {
			$result['plugins'] = $this->get_plugin_update_status();
		}
		if ( current_user_can( Role_Manager::VIEW_THEME_UPDATES ) ) {
			$result['themes'] = $this->get_theme_update_status();
		}
		if ( $this->can_view_updates() ) {
			$result['translations'] = $this->get_translation_update_status();
		}

		return $result;
	}

	/** Returns installed plugin inventory. */
	public function execute_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugins      = get_plugins();
		$auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
		$updates      = get_site_transient( 'update_plugins' );
		$update_items = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$items        = array();

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$directory = dirname( $plugin_file );
			$slug      = '.' === $directory ? basename( $plugin_file, '.php' ) : $directory;
			$items[]   = array(
				'slug'             => sanitize_key( $slug ),
				'name'             => (string) $plugin_data['Name'],
				'version'          => (string) $plugin_data['Version'],
				'status'           => is_plugin_active( $plugin_file ) ? 'active' : 'inactive',
				'network_active'   => is_multisite() && is_plugin_active_for_network( $plugin_file ),
				'auto_update'      => in_array( $plugin_file, $auto_updates, true ),
				'update_available' => isset( $update_items[ $plugin_file ] ),
			);
		}

		usort( $items, array( $this, 'sort_items_by_slug' ) );

		return array( 'items' => $items );
	}

	/** Returns installed theme inventory. */
	public function execute_themes() {
		$themes       = wp_get_themes();
		$current      = get_stylesheet();
		$auto_updates = (array) get_site_option( 'auto_update_themes', array() );
		$updates      = get_site_transient( 'update_themes' );
		$update_items = is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
		$items        = array();

		foreach ( $themes as $stylesheet => $theme ) {
			$parent  = $theme->parent();
			$items[] = array(
				'stylesheet'       => (string) $stylesheet,
				'name'             => (string) $theme->get( 'Name' ),
				'version'          => (string) $theme->get( 'Version' ),
				'active'           => $current === $stylesheet,
				'parent'           => $parent ? (string) $parent->get_stylesheet() : '',
				'auto_update'      => in_array( $stylesheet, $auto_updates, true ),
				'update_available' => isset( $update_items[ $stylesheet ] ),
			);
		}

		usort( $items, array( $this, 'sort_items_by_stylesheet' ) );

		return array( 'items' => $items );
	}

	/** Runs a bounded set of synchronous Site Health tests. */
	public function execute_site_health() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';

		$health = \WP_Site_Health::get_instance();
		$tests  = $health->get_tests();
		$direct = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();
		$map    = array(
			'php_version'              => 'get_test_php_version',
			'php_extensions'           => 'get_test_php_extensions',
			'php_default_timezone'     => 'get_test_php_default_timezone',
			'php_sessions'             => 'get_test_php_sessions',
			'sql_server'               => 'get_test_sql_server',
			'scheduled_events'         => 'get_test_scheduled_events',
			'debug_enabled'            => 'get_test_is_in_debug_mode',
			'file_uploads'             => 'get_test_file_uploads',
			'autoloaded_options'       => 'get_test_autoloaded_options',
			'insecure_registration'    => 'get_test_insecure_registration',
			'search_engine_visibility' => 'get_test_search_engine_visibility',
			'opcode_cache'             => 'get_test_opcode_cache',
		);
		$items  = array();
		$counts = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);

		foreach ( $map as $test_name => $method ) {
			if ( ! isset( $direct[ $test_name ] ) || ! is_callable( array( $health, $method ) ) ) {
				continue;
			}

			try {
				$test_result = call_user_func( array( $health, $method ) );
			} catch ( Throwable $exception ) {
				continue;
			}

			if ( ! is_array( $test_result ) ) {
				continue;
			}

			$status = isset( $test_result['status'] ) && isset( $counts[ $test_result['status'] ] ) ? $test_result['status'] : 'recommended';
			++$counts[ $status ];
			$items[] = array(
				'test'        => $test_name,
				'label'       => isset( $test_result['label'] ) ? wp_strip_all_tags( $test_result['label'] ) : $test_name,
				'status'      => $status,
				'description' => isset( $test_result['description'] ) ? trim( wp_strip_all_tags( $test_result['description'] ) ) : '',
			);
		}

		$status = $counts['critical'] > 0 ? 'critical' : ( $counts['recommended'] > 0 ? 'recommended' : 'good' );

		return array(
			'generated_at' => gmdate( DATE_W3C ),
			'status'       => $status,
			'counts'       => $counts,
			'tests'        => $items,
		);
	}

	/** Returns content activity counts for posts and pages. */
	public function execute_content_summary() {
		$now   = time();
		$after = gmdate( 'Y-m-d H:i:s', $now - ( 30 * DAY_IN_SECONDS ) );
		$types = array();

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$counts              = wp_count_posts( $post_type );
			$types[ $post_type ] = array(
				'published'              => isset( $counts->publish ) ? (int) $counts->publish : 0,
				'drafts'                 => isset( $counts->draft ) ? (int) $counts->draft : 0,
				'published_last_30_days' => $this->count_recent_content( $post_type, 'post_date_gmt', $after ),
				'updated_last_30_days'   => $this->count_recent_content( $post_type, 'post_modified_gmt', $after ),
			);
		}

		return array(
			'generated_at' => gmdate( DATE_W3C, $now ),
			'period_days'  => 30,
			'timezone'     => 'UTC',
			'types'        => $types,
		);
	}

	/**
	 * Returns published content not updated within a given period.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute_stale_content( $input = array() ) {
		$input      = is_array( $input ) ? $input : array();
		$days       = isset( $input['older_than_days'] ) ? min( 3650, max( 1, absint( $input['older_than_days'] ) ) ) : 730;
		$post_type  = isset( $input['post_type'] ) && in_array( $input['post_type'], array( 'post', 'page' ), true ) ? $input['post_type'] : 'post';
		$per_page   = isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 20;
		$cutoff     = time() - ( $days * DAY_IN_SECONDS );
		$cutoff_sql = gmdate( 'Y-m-d H:i:s', $cutoff );
		$query      = new WP_Query(
			array(
				'date_query'          => array(
					array(
						'before'    => $cutoff_sql,
						'column'    => 'post_modified_gmt',
						'inclusive' => true,
					),
				),
				'ignore_sticky_posts' => true,
				'order'               => 'ASC',
				'orderby'             => 'modified',
				'post_status'         => 'publish',
				'post_type'           => $post_type,
				'posts_per_page'      => $per_page,
			)
		);
		$items      = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'       => (int) $post->ID,
				'type'     => $post_type,
				'title'    => get_the_title( $post ),
				'modified' => (string) get_post_modified_time( DATE_W3C, true, $post ),
				'link'     => (string) get_permalink( $post ),
			);
		}

		return array(
			'cutoff'          => gmdate( DATE_W3C, $cutoff ),
			'older_than_days' => $days,
			'post_type'       => $post_type,
			'timezone'        => 'UTC',
			'items'           => $items,
		);
	}

	/**
	 * Returns a sanitized WP-Cron status summary.
	 *
	 * @param mixed $input Validated input.
	 * @return array<string, mixed>
	 */
	public function execute_cron_status( $input = array() ) {
		$input      = is_array( $input ) ? $input : array();
		$per_page   = isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 20;
		$now        = time();
		$cron       = _get_cron_array();
		$events     = array();
		$signatures = array();
		$total      = 0;
		$overdue    = 0;
		$duplicates = 0;

		if ( is_array( $cron ) ) {
			foreach ( $cron as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $instances ) {
					foreach ( $instances as $instance ) {
						++$total;
						$is_overdue = (int) $timestamp < $now;
						$overdue   += $is_overdue ? 1 : 0;
						$schedule   = ! empty( $instance['schedule'] ) ? sanitize_key( $instance['schedule'] ) : 'single';
						$signature  = (int) $timestamp . '|' . $hook . '|' . $schedule;

						if ( isset( $signatures[ $signature ] ) ) {
							++$duplicates;
						} else {
							$signatures[ $signature ] = true;
						}

						$events[] = array(
							'hook'     => sanitize_key( $hook ),
							'next_run' => gmdate( DATE_W3C, (int) $timestamp ),
							'schedule' => $schedule,
							'interval' => isset( $instance['interval'] ) ? (int) $instance['interval'] : 0,
							'overdue'  => $is_overdue,
						);
					}
				}
			}
		}

		return array(
			'generated_at'         => gmdate( DATE_W3C, $now ),
			'total_events'         => $total,
			'overdue_events'       => $overdue,
			'duplicate_candidates' => $duplicates,
			'duplicate_rule'       => 'Same hook and schedule at the same timestamp; arguments are intentionally ignored.',
			'events'               => array_slice( $events, 0, $per_page ),
		);
	}

	/** Returns a maintenance snapshot composed from enabled abilities. */
	public function execute_maintenance_snapshot() {
		$sections = array(
			'site'        => 'get-site-info',
			'updates'     => 'get-update-status',
			'site_health' => 'get-site-health',
			'plugins'     => 'get-plugins',
			'themes'      => 'get-themes',
			'content'     => 'get-content-summary',
			'cron'        => 'get-cron-status',
		);
		$result   = array( 'generated_at' => gmdate( DATE_W3C ) );

		foreach ( $sections as $section => $ability_key ) {
			$result[ $section ] = $this->execute_snapshot_section( $ability_key );
		}

		return $result;
	}

	/** Returns a bounded local security configuration summary. */
	public function execute_security_posture() {
		return ( new Security_Posture() )->get_report();
	}

	/** Checks the current user's read capability. */
	public function can_read() {
		return current_user_can( 'read' );
	}

	/** Checks the current user's content summary capability. */
	public function can_view_content_summary() {
		return current_user_can( Role_Manager::VIEW_CONTENT );
	}

	/** Checks the current user's Cron capability. */
	public function can_view_cron() {
		return current_user_can( Role_Manager::VIEW_CRON );
	}

	/** Checks the current user's maintenance snapshot capability. */
	public function can_view_maintenance() {
		return current_user_can( Role_Manager::VIEW_MAINTENANCE );
	}

	/** Checks the current user's security posture capability. */
	public function can_view_security() {
		return current_user_can( Role_Manager::VIEW_SECURITY );
	}

	/** Checks the current user's Site Health capability. */
	public function can_view_site_health() {
		return current_user_can( Role_Manager::VIEW_SITE_HEALTH );
	}

	/** Checks whether at least one update section is permitted. */
	public function can_view_updates() {
		return current_user_can( Role_Manager::VIEW_CORE_UPDATES ) || current_user_can( Role_Manager::VIEW_PLUGIN_UPDATES ) || current_user_can( Role_Manager::VIEW_THEME_UPDATES );
	}

	/** Checks the current user's plugin capability. */
	public function can_view_plugins() {
		return current_user_can( Role_Manager::VIEW_PLUGINS );
	}

	/** Checks the current user's theme capability. */
	public function can_view_themes() {
		return current_user_can( Role_Manager::VIEW_THEMES );
	}

	/** Registers site information. */
	private function register_site_info() {
		$this->register_readonly_ability(
			'get-site-info',
			__( 'Get site information', 'od-mcp-bridge' ),
			__( 'Returns the site name, description, URL, language, timezone, and WordPress version.', 'od-mcp-bridge' ),
			array( $this, 'execute_site_info' ),
			array( $this, 'can_read' ),
			null,
			$this->get_site_info_schema()
		);
	}

	/** Registers published posts. */
	private function register_posts() {
		$this->register_readonly_ability(
			'get-posts',
			__( 'Get published posts', 'od-mcp-bridge' ),
			__( 'Returns a searchable, paginated list of published posts.', 'od-mcp-bridge' ),
			array( $this, 'execute_posts' ),
			array( $this, 'can_read' ),
			$this->get_collection_input_schema(),
			$this->get_collection_output_schema()
		);
	}

	/** Registers one published post. */
	private function register_post() {
		$this->register_single_content_ability( 'post' );
	}

	/** Registers published pages. */
	private function register_pages() {
		$this->register_readonly_ability(
			'get-pages',
			__( 'Get published pages', 'od-mcp-bridge' ),
			__( 'Returns a searchable, paginated list of published pages.', 'od-mcp-bridge' ),
			array( $this, 'execute_pages' ),
			array( $this, 'can_read' ),
			$this->get_collection_input_schema(),
			$this->get_collection_output_schema()
		);
	}

	/** Registers one published page. */
	private function register_page() {
		$this->register_single_content_ability( 'page' );
	}

	/** Registers categories and tags. */
	private function register_terms() {
		$this->register_readonly_ability(
			'get-terms',
			__( 'Get categories or tags', 'od-mcp-bridge' ),
			__( 'Returns a paginated list from the category or post_tag taxonomy.', 'od-mcp-bridge' ),
			array( $this, 'execute_terms' ),
			array( $this, 'can_read' ),
			$this->get_terms_input_schema(),
			$this->get_terms_output_schema()
		);
	}

	/** Registers safe, idempotent post draft creation. */
	private function register_post_draft() {
		$creator = new Draft_Post_Creator();
		$key     = 'create-post-draft';

		wp_register_ability(
			'od-mcp-bridge/' . $key,
			array(
				'label'               => __( 'Create a post draft', 'od-mcp-bridge' ),
				'description'         => __( 'Creates only a post draft for the current user, with request ID based idempotency.', 'od-mcp-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_post_draft_input_schema(),
				'output_schema'       => $this->get_post_draft_output_schema(),
				'execute_callback'    => array( $creator, 'execute' ),
				'permission_callback' => array( $creator, 'check_permissions' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'oauth'        => array( 'required_scope' => ( new Scope_Policy() )->get_ability_scope( 'od-mcp-bridge/' . $key ) ),
				),
			)
		);
	}

	/** Registers cached update status. */
	private function register_update_status() {
		$this->register_readonly_ability(
			'get-update-status',
			__( 'Get update status', 'od-mcp-bridge' ),
			__( 'Returns cached update information allowed for the current user without refreshing it.', 'od-mcp-bridge' ),
			array( $this, 'execute_update_status' ),
			array( $this, 'can_view_updates' ),
			null,
			$this->get_update_status_schema()
		);
	}

	/** Registers plugin inventory. */
	private function register_plugins() {
		$this->register_readonly_ability(
			'get-plugins',
			__( 'Get plugin inventory', 'od-mcp-bridge' ),
			__( 'Returns sanitized plugin versions and activation, auto-update, and update states.', 'od-mcp-bridge' ),
			array( $this, 'execute_plugins' ),
			array( $this, 'can_view_plugins' ),
			null,
			$this->get_plugins_schema()
		);
	}

	/** Registers theme inventory. */
	private function register_themes() {
		$this->register_readonly_ability(
			'get-themes',
			__( 'Get theme inventory', 'od-mcp-bridge' ),
			__( 'Returns sanitized theme versions and activation, parent, auto-update, and update states.', 'od-mcp-bridge' ),
			array( $this, 'execute_themes' ),
			array( $this, 'can_view_themes' ),
			null,
			$this->get_themes_schema()
		);
	}

	/** Registers a bounded Site Health summary. */
	private function register_site_health() {
		$this->register_readonly_ability(
			'get-site-health',
			__( 'Get Site Health summary', 'od-mcp-bridge' ),
			__( 'Runs bounded synchronous Site Health tests and omits actions and internal paths.', 'od-mcp-bridge' ),
			array( $this, 'execute_site_health' ),
			array( $this, 'can_view_site_health' ),
			null,
			$this->get_site_health_schema()
		);
	}

	/** Registers content activity summary. */
	private function register_content_summary() {
		$this->register_readonly_ability(
			'get-content-summary',
			__( 'Get content summary', 'od-mcp-bridge' ),
			__( 'Returns post and page counts plus 30-day publishing and update activity in UTC.', 'od-mcp-bridge' ),
			array( $this, 'execute_content_summary' ),
			array( $this, 'can_view_content_summary' ),
			null,
			$this->get_content_activity_schema()
		);
	}

	/** Registers stale content lookup. */
	private function register_stale_content() {
		$this->register_readonly_ability(
			'get-stale-content',
			__( 'Get stale content', 'od-mcp-bridge' ),
			__( 'Returns published posts or pages not modified before a UTC cutoff.', 'od-mcp-bridge' ),
			array( $this, 'execute_stale_content' ),
			array( $this, 'can_read' ),
			$this->get_stale_content_input_schema(),
			$this->get_stale_content_schema()
		);
	}

	/** Registers WP-Cron status. */
	private function register_cron_status() {
		$this->register_readonly_ability(
			'get-cron-status',
			__( 'Get WP-Cron status', 'od-mcp-bridge' ),
			__( 'Returns sanitized scheduled event timing without event arguments.', 'od-mcp-bridge' ),
			array( $this, 'execute_cron_status' ),
			array( $this, 'can_view_cron' ),
			$this->get_limit_input_schema(),
			$this->get_cron_status_schema()
		);
	}

	/** Registers the composed maintenance snapshot. */
	private function register_maintenance_snapshot() {
		$this->register_readonly_ability(
			'get-maintenance-snapshot',
			__( 'Get maintenance snapshot', 'od-mcp-bridge' ),
			__( 'Returns enabled maintenance sections while preserving permission and failure boundaries.', 'od-mcp-bridge' ),
			array( $this, 'execute_maintenance_snapshot' ),
			array( $this, 'can_view_maintenance' ),
			null,
			$this->get_maintenance_snapshot_schema()
		);
	}

	/** Registers the local security posture summary. */
	private function register_security_posture() {
		$this->register_readonly_ability(
			'get-security-posture',
			__( 'Get security posture', 'od-mcp-bridge' ),
			__( 'Returns a bounded local configuration summary without credentials, internal paths, or external requests.', 'od-mcp-bridge' ),
			array( $this, 'execute_security_posture' ),
			array( $this, 'can_view_security' ),
			null,
			$this->get_security_posture_schema()
		);
	}

	/**
	 * Registers one read-only ability.
	 *
	 * @param string     $key                 Ability key.
	 * @param string     $label               Label.
	 * @param string     $description         Description.
	 * @param callable   $execute_callback    Execute callback.
	 * @param callable   $permission_callback Permission callback.
	 * @param array|null $input_schema        Input schema.
	 * @param array      $output_schema       Output schema.
	 */
	private function register_readonly_ability( $key, $label, $description, $execute_callback, $permission_callback, $input_schema, $output_schema ) {
		$args = array(
			'label'               => $label,
			'description'         => $description,
			'category'            => self::CATEGORY,
			'output_schema'       => $output_schema,
			'execute_callback'    => $execute_callback,
			'permission_callback' => $permission_callback,
			'meta'                => $this->get_readonly_meta( $key ),
		);

		if ( is_array( $input_schema ) ) {
			$args['input_schema'] = $input_schema;
		}

		wp_register_ability( 'od-mcp-bridge/' . $key, $args );
	}

	/**
	 * Registers one published post or page ability.
	 *
	 * @param string $post_type Post type.
	 */
	private function register_single_content_ability( $post_type ) {
		$is_page  = 'page' === $post_type;
		$key      = $is_page ? 'get-page' : 'get-post';
		$id_key   = $is_page ? 'page_id' : 'post_id';
		$label    = $is_page ? __( 'Get a published page', 'od-mcp-bridge' ) : __( 'Get a published post', 'od-mcp-bridge' );
		$callback = $is_page ? array( $this, 'execute_page' ) : array( $this, 'execute_post' );

		$this->register_readonly_ability(
			$key,
			$label,
			$is_page ? __( 'Returns raw block content and public metadata for one published page.', 'od-mcp-bridge' ) : __( 'Returns raw block content and public metadata for one published post.', 'od-mcp-bridge' ),
			$callback,
			array( $this, 'can_read' ),
			$this->object_schema(
				array(
					$id_key => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				array( $id_key )
			),
			$is_page ? $this->get_page_schema() : $this->get_post_schema()
		);
	}

	/**
	 * Executes a public post or page collection.
	 *
	 * @param string $post_type Post type.
	 * @param mixed  $input     Validated input.
	 */
	private function execute_content_collection( $post_type, $input ) {
		$input    = is_array( $input ) ? $input : array();
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$orderby  = isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'modified', 'title' ), true ) ? $input['orderby'] : 'date';
		$order    = isset( $input['order'] ) && 'asc' === strtolower( $input['order'] ) ? 'ASC' : 'DESC';
		$query    = new WP_Query(
			array(
				'ignore_sticky_posts' => true,
				'order'               => $order,
				'orderby'             => $orderby,
				'paged'               => $page,
				'post_status'         => 'publish',
				'post_type'           => $post_type,
				'posts_per_page'      => $per_page,
				's'                   => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
			)
		);

		return array(
			'items'      => array_map( array( $this, 'format_public_content' ), $query->posts ),
			'pagination' => $this->format_pagination( $page, $per_page, (int) $query->found_posts ),
		);
	}

	/**
	 * Executes one public post or page.
	 *
	 * @param string $post_type Post type.
	 * @param int    $post_id   Content ID.
	 */
	private function execute_single_content( $post_type, $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || $post_type !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'od_mcp_bridge_' . $post_type . '_not_found',
				__( 'The requested published content was not found.', 'od-mcp-bridge' ),
				array( 'status' => 404 )
			);
		}

		$result            = $this->format_public_content( $post );
		$result['content'] = $post->post_content;

		if ( 'page' === $post_type ) {
			$result['parent_id']  = (int) $post->post_parent;
			$result['menu_order'] = (int) $post->menu_order;
		}

		return $result;
	}

	/**
	 * Formats public content.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function format_public_content( $post ) {
		return array(
			'id'       => (int) $post->ID,
			'title'    => get_the_title( $post ),
			'excerpt'  => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'date'     => (string) get_post_time( DATE_W3C, true, $post ),
			'modified' => (string) get_post_modified_time( DATE_W3C, true, $post ),
			'link'     => (string) get_permalink( $post ),
		);
	}

	/**
	 * Formats pagination metadata.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Page size.
	 * @param int $total    Total items.
	 */
	private function format_pagination( $page, $per_page, $total ) {
		return array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
		);
	}

	/** Returns core update status from the existing transient. */
	private function get_core_update_status() {
		$transient = get_site_transient( 'update_core' );
		$versions  = array();

		if ( is_object( $transient ) && isset( $transient->updates ) && is_array( $transient->updates ) ) {
			foreach ( $transient->updates as $update ) {
				if ( is_object( $update ) && isset( $update->response, $update->current ) && 'upgrade' === $update->response ) {
					$versions[] = (string) $update->current;
				}
			}
		}

		return array(
			'current'            => get_bloginfo( 'version' ),
			'initialized'        => is_object( $transient ),
			'last_checked'       => $this->format_checked_time( $transient ),
			'update_count'       => count( $versions ),
			'available_versions' => array_values( array_unique( $versions ) ),
		);
	}

	/** Returns plugin update status from the existing transient. */
	private function get_plugin_update_status() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$transient = get_site_transient( 'update_plugins' );
		$updates   = is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$plugins   = get_plugins();
		$items     = array();

		foreach ( $updates as $plugin_file => $update ) {
			$directory = dirname( $plugin_file );
			$slug      = '.' === $directory ? basename( $plugin_file, '.php' ) : $directory;
			$items[]   = array(
				'slug'              => sanitize_key( $slug ),
				'name'              => isset( $plugins[ $plugin_file ]['Name'] ) ? (string) $plugins[ $plugin_file ]['Name'] : sanitize_key( $slug ),
				'current_version'   => isset( $plugins[ $plugin_file ]['Version'] ) ? (string) $plugins[ $plugin_file ]['Version'] : '',
				'available_version' => is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : '',
			);
		}

		usort( $items, array( $this, 'sort_items_by_slug' ) );

		return array(
			'initialized'  => is_object( $transient ),
			'last_checked' => $this->format_checked_time( $transient ),
			'update_count' => count( $items ),
			'items'        => $items,
		);
	}

	/** Returns theme update status from the existing transient. */
	private function get_theme_update_status() {
		$transient = get_site_transient( 'update_themes' );
		$updates   = is_object( $transient ) && isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$themes    = wp_get_themes();
		$items     = array();

		foreach ( $updates as $stylesheet => $update ) {
			$items[] = array(
				'stylesheet'        => (string) $stylesheet,
				'name'              => isset( $themes[ $stylesheet ] ) ? (string) $themes[ $stylesheet ]->get( 'Name' ) : (string) $stylesheet,
				'current_version'   => isset( $themes[ $stylesheet ] ) ? (string) $themes[ $stylesheet ]->get( 'Version' ) : '',
				'available_version' => is_array( $update ) && isset( $update['new_version'] ) ? (string) $update['new_version'] : '',
			);
		}

		usort( $items, array( $this, 'sort_items_by_stylesheet' ) );

		return array(
			'initialized'  => is_object( $transient ),
			'last_checked' => $this->format_checked_time( $transient ),
			'update_count' => count( $items ),
			'items'        => $items,
		);
	}

	/** Returns cached translation updates. */
	private function get_translation_update_status() {
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$items        = array();
		$capabilities = array(
			'core'   => Role_Manager::VIEW_CORE_UPDATES,
			'plugin' => Role_Manager::VIEW_PLUGIN_UPDATES,
			'theme'  => Role_Manager::VIEW_THEME_UPDATES,
		);
		foreach ( wp_get_translation_updates() as $update ) {
			$type = isset( $update->type ) ? sanitize_key( $update->type ) : '';

			if ( ! isset( $capabilities[ $type ] ) || ! current_user_can( $capabilities[ $type ] ) ) {
				continue;
			}

			$items[] = array(
				'type'     => $type,
				'slug'     => isset( $update->slug ) ? sanitize_key( $update->slug ) : '',
				'language' => isset( $update->language ) ? sanitize_text_field( $update->language ) : '',
				'version'  => isset( $update->version ) ? sanitize_text_field( $update->version ) : '',
			);
		}

		return array(
			'update_count' => count( $items ),
			'items'        => $items,
		);
	}

	/**
	 * Formats a transient last_checked timestamp.
	 *
	 * @param mixed $transient Update transient.
	 */
	private function format_checked_time( $transient ) {
		return is_object( $transient ) && isset( $transient->last_checked ) && $transient->last_checked > 0 ? gmdate( DATE_W3C, (int) $transient->last_checked ) : '';
	}

	/**
	 * Counts recent published content.
	 *
	 * @param string $post_type Post type.
	 * @param string $column    Date column.
	 * @param string $after     UTC SQL date.
	 */
	private function count_recent_content( $post_type, $column, $after ) {
		$query = new WP_Query(
			array(
				'date_query'          => array(
					array(
						'after'     => $after,
						'column'    => $column,
						'inclusive' => true,
					),
				),
				'fields'              => 'ids',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
				'post_status'         => 'publish',
				'post_type'           => $post_type,
				'posts_per_page'      => 1,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Executes one snapshot section through its registered ability.
	 *
	 * @param string $ability_key Ability key.
	 */
	private function execute_snapshot_section( $ability_key ) {
		if ( ! $this->settings->is_ability_enabled( $ability_key ) ) {
			return $this->format_unavailable_section( 'disabled' );
		}

		if ( ! \WP_Abilities_Registry::get_instance()->is_registered( 'od-mcp-bridge/' . $ability_key ) ) {
			return $this->format_unavailable_section( 'not_registered' );
		}

		$ability = wp_get_ability( 'od-mcp-bridge/' . $ability_key );

		try {
			$permission = $ability->check_permissions();
			if ( is_wp_error( $permission ) || true !== $permission ) {
				return $this->format_unavailable_section( 'forbidden' );
			}

			$data = $ability->execute();
			if ( is_wp_error( $data ) ) {
				return $this->format_unavailable_section( sanitize_key( $data->get_error_code() ) );
			}
		} catch ( Throwable $exception ) {
			return $this->format_unavailable_section( 'execution_failed' );
		}

		return array(
			'status' => 'available',
			'reason' => '',
			'data'   => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Formats an unavailable snapshot section.
	 *
	 * @param string $reason Reason code.
	 */
	private function format_unavailable_section( $reason ) {
		return array(
			'status' => 'unavailable',
			'reason' => $reason,
			'data'   => array(),
		);
	}

	/**
	 * Sorts records by slug.
	 *
	 * @param array $left  Left record.
	 * @param array $right Right record.
	 */
	private function sort_items_by_slug( $left, $right ) {
		return strcmp( $left['slug'], $right['slug'] );
	}

	/**
	 * Sorts records by stylesheet.
	 *
	 * @param array $left  Left record.
	 * @param array $right Right record.
	 */
	private function sort_items_by_stylesheet( $left, $right ) {
		return strcmp( $left['stylesheet'], $right['stylesheet'] );
	}

	/**
	 * Returns metadata shared by all read-only abilities.
	 *
	 * @param string $key Ability key.
	 */
	private function get_readonly_meta( $key ) {
		$scope = ( new Scope_Policy() )->get_ability_scope( 'od-mcp-bridge/' . $key );

		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array( 'public' => true ),
			'oauth'        => array( 'required_scope' => $scope ),
		);
	}

	/**
	 * Returns a strict object schema.
	 *
	 * @param array $properties Schema properties.
	 * @param array $required   Required keys.
	 */
	private function object_schema( $properties, $required ) {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/** Returns site information schema. */
	private function get_site_info_schema() {
		$keys = array( 'name', 'description', 'url', 'language', 'timezone', 'wordpress_version' );

		return $this->object_schema( array_fill_keys( $keys, array( 'type' => 'string' ) ), $keys );
	}

	/** Returns common collection input schema. */
	private function get_collection_input_schema() {
		return $this->object_schema(
			array(
				'per_page' => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 100,
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'search'   => array( 'type' => 'string' ),
				'orderby'  => array(
					'type'    => 'string',
					'enum'    => array( 'date', 'modified', 'title' ),
					'default' => 'date',
				),
				'order'    => array(
					'type'    => 'string',
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'desc',
				),
			),
			array()
		);
	}

	/** Returns the strict post draft input schema. */
	private function get_post_draft_input_schema() {
		return $this->object_schema(
			array(
				'request_id' => array(
					'type'      => 'string',
					'minLength' => 36,
					'maxLength' => 36,
					'pattern'   => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
				),
				'title'      => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'content'    => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
				'excerpt'    => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'categories' => array(
					'type'        => 'array',
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'maxItems'    => 20,
					'uniqueItems' => true,
				),
			),
			array( 'request_id', 'title', 'content' )
		);
	}

	/** Returns the post draft result schema. */
	private function get_post_draft_output_schema() {
		return $this->object_schema(
			array(
				'id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'status'   => array(
					'type' => 'string',
					'enum' => array( 'draft' ),
				),
				'edit_url' => array( 'type' => 'string' ),
				'created'  => array( 'type' => 'boolean' ),
			),
			array( 'id', 'status', 'edit_url', 'created' )
		);
	}

	/** Returns common collection output schema. */
	private function get_collection_output_schema() {
		return $this->object_schema(
			array(
				'items'      => array(
					'type'  => 'array',
					'items' => $this->get_public_content_schema(),
				),
				'pagination' => $this->get_pagination_schema(),
			),
			array( 'items', 'pagination' )
		);
	}

	/** Returns one post output schema. */
	private function get_post_schema() {
		$schema                          = $this->get_public_content_schema();
		$schema['properties']['content'] = array( 'type' => 'string' );
		$schema['required'][]            = 'content';

		return $schema;
	}

	/** Returns one page output schema. */
	private function get_page_schema() {
		$schema                             = $this->get_post_schema();
		$schema['properties']['parent_id']  = array( 'type' => 'integer' );
		$schema['properties']['menu_order'] = array( 'type' => 'integer' );
		$schema['required'][]               = 'parent_id';
		$schema['required'][]               = 'menu_order';

		return $schema;
	}

	/** Returns public content item schema. */
	private function get_public_content_schema() {
		$keys = array( 'id', 'title', 'excerpt', 'date', 'modified', 'link' );

		return $this->object_schema(
			array(
				'id'       => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'excerpt'  => array( 'type' => 'string' ),
				'date'     => array( 'type' => 'string' ),
				'modified' => array( 'type' => 'string' ),
				'link'     => array( 'type' => 'string' ),
			),
			$keys
		);
	}

	/** Returns pagination schema. */
	private function get_pagination_schema() {
		$keys = array( 'page', 'per_page', 'total', 'total_pages' );

		return $this->object_schema( array_fill_keys( $keys, array( 'type' => 'integer' ) ), $keys );
	}

	/** Returns terms input schema. */
	private function get_terms_input_schema() {
		return $this->object_schema(
			array(
				'taxonomy' => array(
					'type'    => 'string',
					'enum'    => array( 'category', 'post_tag' ),
					'default' => 'category',
				),
				'per_page' => array(
					'type'    => 'integer',
					'default' => 10,
					'minimum' => 1,
					'maximum' => 100,
				),
				'page'     => array(
					'type'    => 'integer',
					'default' => 1,
					'minimum' => 1,
				),
				'search'   => array( 'type' => 'string' ),
				'orderby'  => array(
					'type'    => 'string',
					'enum'    => array( 'name', 'slug', 'count' ),
					'default' => 'name',
				),
				'order'    => array(
					'type'    => 'string',
					'enum'    => array( 'asc', 'desc' ),
					'default' => 'asc',
				),
			),
			array()
		);
	}

	/** Returns terms output schema. */
	private function get_terms_output_schema() {
		$item = $this->object_schema(
			array(
				'id'          => array( 'type' => 'integer' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'count'       => array( 'type' => 'integer' ),
				'taxonomy'    => array(
					'type' => 'string',
					'enum' => array( 'category', 'post_tag' ),
				),
			),
			array( 'id', 'name', 'slug', 'description', 'count', 'taxonomy' )
		);

		return $this->object_schema(
			array(
				'items'      => array(
					'type'  => 'array',
					'items' => $item,
				),
				'pagination' => $this->get_pagination_schema(),
			),
			array( 'items', 'pagination' )
		);
	}

	/** Returns update status schema. */
	private function get_update_status_schema() {
		$core                                   = $this->object_schema(
			array(
				'current'            => array( 'type' => 'string' ),
				'initialized'        => array( 'type' => 'boolean' ),
				'last_checked'       => array( 'type' => 'string' ),
				'update_count'       => array( 'type' => 'integer' ),
				'available_versions' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			array( 'current', 'initialized', 'last_checked', 'update_count', 'available_versions' )
		);
		$plugin_item                            = $this->object_schema(
			array(
				'slug'              => array( 'type' => 'string' ),
				'name'              => array( 'type' => 'string' ),
				'current_version'   => array( 'type' => 'string' ),
				'available_version' => array( 'type' => 'string' ),
			),
			array( 'slug', 'name', 'current_version', 'available_version' )
		);
		$theme_item                             = $plugin_item;
		$theme_item['properties']['stylesheet'] = array( 'type' => 'string' );
		unset( $theme_item['properties']['slug'] );
		$theme_item['required'][0] = 'stylesheet';
		$translation_item          = $this->object_schema(
			array_fill_keys( array( 'type', 'slug', 'language', 'version' ), array( 'type' => 'string' ) ),
			array( 'type', 'slug', 'language', 'version' )
		);

		return $this->object_schema(
			array(
				'generated_at' => array( 'type' => 'string' ),
				'core'         => $core,
				'plugins'      => $this->get_update_items_section_schema( $plugin_item ),
				'themes'       => $this->get_update_items_section_schema( $theme_item ),
				'translations' => $this->object_schema(
					array(
						'update_count' => array( 'type' => 'integer' ),
						'items'        => array(
							'type'  => 'array',
							'items' => $translation_item,
						),
					),
					array( 'update_count', 'items' )
				),
			),
			array( 'generated_at' )
		);
	}

	/**
	 * Returns one update collection section schema.
	 *
	 * @param array $item_schema Item schema.
	 */
	private function get_update_items_section_schema( $item_schema ) {
		return $this->object_schema(
			array(
				'initialized'  => array( 'type' => 'boolean' ),
				'last_checked' => array( 'type' => 'string' ),
				'update_count' => array( 'type' => 'integer' ),
				'items'        => array(
					'type'  => 'array',
					'items' => $item_schema,
				),
			),
			array( 'initialized', 'last_checked', 'update_count', 'items' )
		);
	}

	/** Returns plugin inventory schema. */
	private function get_plugins_schema() {
		$item = $this->object_schema(
			array(
				'slug'             => array( 'type' => 'string' ),
				'name'             => array( 'type' => 'string' ),
				'version'          => array( 'type' => 'string' ),
				'status'           => array(
					'type' => 'string',
					'enum' => array( 'active', 'inactive' ),
				),
				'network_active'   => array( 'type' => 'boolean' ),
				'auto_update'      => array( 'type' => 'boolean' ),
				'update_available' => array( 'type' => 'boolean' ),
			),
			array( 'slug', 'name', 'version', 'status', 'network_active', 'auto_update', 'update_available' )
		);

		return $this->object_schema(
			array(
				'items' => array(
					'type'  => 'array',
					'items' => $item,
				),
			),
			array( 'items' )
		);
	}

	/** Returns theme inventory schema. */
	private function get_themes_schema() {
		$item = $this->object_schema(
			array(
				'stylesheet'       => array( 'type' => 'string' ),
				'name'             => array( 'type' => 'string' ),
				'version'          => array( 'type' => 'string' ),
				'active'           => array( 'type' => 'boolean' ),
				'parent'           => array( 'type' => 'string' ),
				'auto_update'      => array( 'type' => 'boolean' ),
				'update_available' => array( 'type' => 'boolean' ),
			),
			array( 'stylesheet', 'name', 'version', 'active', 'parent', 'auto_update', 'update_available' )
		);

		return $this->object_schema(
			array(
				'items' => array(
					'type'  => 'array',
					'items' => $item,
				),
			),
			array( 'items' )
		);
	}

	/** Returns Site Health schema. */
	private function get_site_health_schema() {
		$statuses = array( 'good', 'recommended', 'critical' );
		$item     = $this->object_schema(
			array(
				'test'        => array( 'type' => 'string' ),
				'label'       => array( 'type' => 'string' ),
				'status'      => array(
					'type' => 'string',
					'enum' => $statuses,
				),
				'description' => array( 'type' => 'string' ),
			),
			array( 'test', 'label', 'status', 'description' )
		);

		return $this->object_schema(
			array(
				'generated_at' => array( 'type' => 'string' ),
				'status'       => array(
					'type' => 'string',
					'enum' => $statuses,
				),
				'counts'       => $this->object_schema( array_fill_keys( $statuses, array( 'type' => 'integer' ) ), $statuses ),
				'tests'        => array(
					'type'  => 'array',
					'items' => $item,
				),
			),
			array( 'generated_at', 'status', 'counts', 'tests' )
		);
	}

	/** Returns the security posture output schema. */
	private function get_security_posture_schema() {
		$status = array(
			'type' => 'string',
			'enum' => array( 'good', 'recommended', 'attention', 'unknown' ),
		);
		$base   = array(
			'status'  => $status,
			'summary' => array( 'type' => 'string' ),
		);

		return $this->object_schema(
			array(
				'generated_at'          => array( 'type' => 'string' ),
				'status'                => $status,
				'environment'           => array(
					'type' => 'string',
					'enum' => array( 'local', 'development', 'staging', 'production' ),
				),
				'https'                 => $this->object_schema(
					array_merge(
						$base,
						array(
							'using_https'    => array( 'type' => 'boolean' ),
							'support_status' => array(
								'type' => 'string',
								'enum' => array( 'confirmed_by_configuration', 'not_checked' ),
							),
						)
					),
					array( 'status', 'summary', 'using_https', 'support_status' )
				),
				'core_updates'          => $this->object_schema(
					array_merge(
						$base,
						array(
							'state' => array(
								'type' => 'string',
								'enum' => array( 'up_to_date', 'update_available', 'unknown' ),
							),
						)
					),
					array( 'status', 'summary', 'state' )
				),
				'debug'                 => $this->object_schema(
					array_merge(
						$base,
						array_fill_keys( array( 'enabled', 'display_enabled', 'log_enabled' ), array( 'type' => 'boolean' ) )
					),
					array( 'status', 'summary', 'enabled', 'display_enabled', 'log_enabled' )
				),
				'file_editing'          => $this->object_schema(
					array_merge(
						$base,
						array_fill_keys( array( 'editor_allowed', 'modifications_allowed' ), array( 'type' => 'boolean' ) )
					),
					array( 'status', 'summary', 'editor_allowed', 'modifications_allowed' )
				),
				'automatic_updates'     => $this->object_schema(
					array_merge(
						$base,
						array(
							'updater_enabled'            => array( 'type' => 'boolean' ),
							'minor_core_updates_allowed' => array( 'type' => 'boolean' ),
							'plugin_theme_configuration' => array(
								'type' => 'string',
								'enum' => array( 'good', 'issues_detected', 'unknown' ),
							),
							'previous_failure'           => array( 'type' => 'boolean' ),
						)
					),
					array( 'status', 'summary', 'updater_enabled', 'minor_core_updates_allowed', 'plugin_theme_configuration', 'previous_failure' )
				),
				'administrators'        => $this->object_schema(
					array_merge(
						$base,
						array(
							'count' => array( 'type' => 'integer' ),
							'scope' => array(
								'type' => 'string',
								'enum' => array( 'current_site' ),
							),
						)
					),
					array( 'status', 'summary', 'count', 'scope' )
				),
				'application_passwords' => $this->object_schema(
					array_merge(
						$base,
						array_fill_keys( array( 'globally_available', 'available_for_current_user' ), array( 'type' => 'boolean' ) )
					),
					array( 'status', 'summary', 'globally_available', 'available_for_current_user' )
				),
			),
			array( 'generated_at', 'status', 'environment', 'https', 'core_updates', 'debug', 'file_editing', 'automatic_updates', 'administrators', 'application_passwords' )
		);
	}

	/** Returns content activity schema. */
	private function get_content_activity_schema() {
		$count_keys = array( 'published', 'drafts', 'published_last_30_days', 'updated_last_30_days' );
		$type       = $this->object_schema( array_fill_keys( $count_keys, array( 'type' => 'integer' ) ), $count_keys );
		$types      = $this->object_schema(
			array(
				'post' => $type,
				'page' => $type,
			),
			array( 'post', 'page' )
		);

		return $this->object_schema(
			array(
				'generated_at' => array( 'type' => 'string' ),
				'period_days'  => array( 'type' => 'integer' ),
				'timezone'     => array( 'type' => 'string' ),
				'types'        => $types,
			),
			array( 'generated_at', 'period_days', 'timezone', 'types' )
		);
	}

	/** Returns stale content input schema. */
	private function get_stale_content_input_schema() {
		return $this->object_schema(
			array(
				'older_than_days' => array(
					'type'    => 'integer',
					'default' => 730,
					'minimum' => 1,
					'maximum' => 3650,
				),
				'post_type'       => array(
					'type'    => 'string',
					'enum'    => array( 'post', 'page' ),
					'default' => 'post',
				),
				'per_page'        => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			array()
		);
	}

	/** Returns stale content output schema. */
	private function get_stale_content_schema() {
		$item = $this->object_schema(
			array(
				'id'       => array( 'type' => 'integer' ),
				'type'     => array(
					'type' => 'string',
					'enum' => array( 'post', 'page' ),
				),
				'title'    => array( 'type' => 'string' ),
				'modified' => array( 'type' => 'string' ),
				'link'     => array( 'type' => 'string' ),
			),
			array( 'id', 'type', 'title', 'modified', 'link' )
		);

		return $this->object_schema(
			array(
				'cutoff'          => array( 'type' => 'string' ),
				'older_than_days' => array( 'type' => 'integer' ),
				'post_type'       => array(
					'type' => 'string',
					'enum' => array( 'post', 'page' ),
				),
				'timezone'        => array( 'type' => 'string' ),
				'items'           => array(
					'type'  => 'array',
					'items' => $item,
				),
			),
			array( 'cutoff', 'older_than_days', 'post_type', 'timezone', 'items' )
		);
	}

	/** Returns a bounded list input schema. */
	private function get_limit_input_schema() {
		return $this->object_schema(
			array(
				'per_page' => array(
					'type'    => 'integer',
					'default' => 20,
					'minimum' => 1,
					'maximum' => 100,
				),
			),
			array()
		);
	}

	/** Returns WP-Cron status schema. */
	private function get_cron_status_schema() {
		$item = $this->object_schema(
			array(
				'hook'     => array( 'type' => 'string' ),
				'next_run' => array( 'type' => 'string' ),
				'schedule' => array( 'type' => 'string' ),
				'interval' => array( 'type' => 'integer' ),
				'overdue'  => array( 'type' => 'boolean' ),
			),
			array( 'hook', 'next_run', 'schedule', 'interval', 'overdue' )
		);

		return $this->object_schema(
			array(
				'generated_at'         => array( 'type' => 'string' ),
				'total_events'         => array( 'type' => 'integer' ),
				'overdue_events'       => array( 'type' => 'integer' ),
				'duplicate_candidates' => array( 'type' => 'integer' ),
				'duplicate_rule'       => array( 'type' => 'string' ),
				'events'               => array(
					'type'  => 'array',
					'items' => $item,
				),
			),
			array( 'generated_at', 'total_events', 'overdue_events', 'duplicate_candidates', 'duplicate_rule', 'events' )
		);
	}

	/** Returns maintenance snapshot schema. */
	private function get_maintenance_snapshot_schema() {
		$section                    = $this->object_schema(
			array(
				'status' => array(
					'type' => 'string',
					'enum' => array( 'available', 'unavailable' ),
				),
				'reason' => array( 'type' => 'string' ),
				'data'   => array( 'type' => 'object' ),
			),
			array( 'status', 'reason', 'data' )
		);
		$keys                       = array( 'site', 'updates', 'site_health', 'plugins', 'themes', 'content', 'cron' );
		$properties                 = array_fill_keys( $keys, $section );
		$properties['generated_at'] = array( 'type' => 'string' );

		return $this->object_schema( $properties, array_merge( array( 'generated_at' ), $keys ) );
	}
}
