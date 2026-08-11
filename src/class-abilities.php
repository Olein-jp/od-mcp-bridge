<?php
/**
 * Read-only WordPress abilities.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use Olein\MCPBridge\Admin\Settings_Page;
use WP_Error;
use WP_Query;

/**
 * Registers and executes the MVP abilities.
 */
final class Abilities {

	/**
	 * Ability category ID.
	 *
	 * @var string
	 */
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

	/**
	 * Registers Abilities API hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the plugin ability category.
	 *
	 * @return void
	 */
	public function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'OD MCP Bridge', 'od-mcp-bridge' ),
				'description' => __( 'Read-only site and content information exposed by OD MCP Bridge.', 'od-mcp-bridge' ),
			)
		);
	}

	/**
	 * Registers enabled read-only abilities.
	 *
	 * @return void
	 */
	public function register_abilities() {
		if ( $this->settings->is_ability_enabled( 'get-site-info' ) ) {
			$this->register_site_info();
		}

		if ( $this->settings->is_ability_enabled( 'get-posts' ) ) {
			$this->register_posts();
		}

		if ( $this->settings->is_ability_enabled( 'get-post' ) ) {
			$this->register_post();
		}
	}

	/**
	 * Returns basic site information.
	 *
	 * @return array<string, string>
	 */
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
	 * Returns a paginated list of published posts.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>
	 */
	public function execute_posts( $input = array() ) {
		$input    = is_array( $input ) ? $input : array();
		$per_page = isset( $input['per_page'] ) ? min( 100, max( 1, absint( $input['per_page'] ) ) ) : 10;
		$page     = isset( $input['page'] ) ? max( 1, absint( $input['page'] ) ) : 1;
		$orderby  = isset( $input['orderby'] ) && in_array( $input['orderby'], array( 'date', 'modified', 'title' ), true ) ? $input['orderby'] : 'date';
		$order    = isset( $input['order'] ) && 'asc' === strtolower( $input['order'] ) ? 'ASC' : 'DESC';

		$query = new WP_Query(
			array(
				'ignore_sticky_posts' => true,
				'order'               => $order,
				'orderby'             => $orderby,
				'paged'               => $page,
				'post_status'         => 'publish',
				'post_type'           => 'post',
				'posts_per_page'      => $per_page,
				's'                   => isset( $input['search'] ) ? sanitize_text_field( $input['search'] ) : '',
			)
		);

		$items = array_map( array( $this, 'format_post_summary' ), $query->posts );

		return array(
			'items'      => $items,
			'pagination' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
			),
		);
	}

	/**
	 * Returns one published post.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute_post( $input = array() ) {
		$post_id = is_array( $input ) && isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		$post    = get_post( $post_id );

		if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error(
				'od_mcp_bridge_post_not_found',
				__( 'The requested published post was not found.', 'od-mcp-bridge' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'id'       => (int) $post->ID,
			'title'    => get_the_title( $post ),
			'content'  => $post->post_content,
			'excerpt'  => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'date'     => (string) get_post_time( DATE_W3C, true, $post ),
			'modified' => (string) get_post_modified_time( DATE_W3C, true, $post ),
			'link'     => (string) get_permalink( $post ),
		);
	}

	/**
	 * Checks the current user's read capability.
	 *
	 * @return bool
	 */
	public function can_read() {
		return current_user_can( 'read' );
	}

	/**
	 * Registers the site information ability.
	 *
	 * @return void
	 */
	private function register_site_info() {
		wp_register_ability(
			'od-mcp-bridge/get-site-info',
			array(
				'label'               => __( 'Get site information', 'od-mcp-bridge' ),
				'description'         => __( 'Returns the site name, description, URL, language, timezone, and WordPress version.', 'od-mcp-bridge' ),
				'category'            => self::CATEGORY,
				'output_schema'       => $this->get_site_info_schema(),
				'execute_callback'    => array( $this, 'execute_site_info' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->get_readonly_meta(),
			)
		);
	}

	/**
	 * Registers the post list ability.
	 *
	 * @return void
	 */
	private function register_posts() {
		wp_register_ability(
			'od-mcp-bridge/get-posts',
			array(
				'label'               => __( 'Get published posts', 'od-mcp-bridge' ),
				'description'         => __( 'Returns a searchable, paginated list of published posts.', 'od-mcp-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => $this->get_posts_input_schema(),
				'output_schema'       => $this->get_posts_output_schema(),
				'execute_callback'    => array( $this, 'execute_posts' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->get_readonly_meta(),
			)
		);
	}

	/**
	 * Registers the single post ability.
	 *
	 * @return void
	 */
	private function register_post() {
		wp_register_ability(
			'od-mcp-bridge/get-post',
			array(
				'label'               => __( 'Get a published post', 'od-mcp-bridge' ),
				'description'         => __( 'Returns the title, raw block content, excerpt, dates, and URL for one published post.', 'od-mcp-bridge' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => __( 'The published post ID.', 'od-mcp-bridge' ),
							'minimum'     => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->get_post_schema(),
				'execute_callback'    => array( $this, 'execute_post' ),
				'permission_callback' => array( $this, 'can_read' ),
				'meta'                => $this->get_readonly_meta(),
			)
		);
	}

	/**
	 * Formats a post for collection output.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private function format_post_summary( $post ) {
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
	 * Returns metadata shared by all read-only abilities.
	 *
	 * @return array<string, mixed>
	 */
	private function get_readonly_meta() {
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
			),
		);
	}

	/**
	 * Returns the site information output schema.
	 *
	 * @return array<string, mixed>
	 */
	private function get_site_info_schema() {
		$properties = array_fill_keys(
			array( 'name', 'description', 'url', 'language', 'timezone', 'wordpress_version' ),
			array( 'type' => 'string' )
		);

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the post list input schema.
	 *
	 * @return array<string, mixed>
	 */
	private function get_posts_input_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of posts per page.', 'od-mcp-bridge' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number.', 'od-mcp-bridge' ),
					'default'     => 1,
					'minimum'     => 1,
				),
				'search'   => array(
					'type'        => 'string',
					'description' => __( 'Optional search term.', 'od-mcp-bridge' ),
				),
				'orderby'  => array(
					'type'        => 'string',
					'description' => __( 'Field used to order posts.', 'od-mcp-bridge' ),
					'enum'        => array( 'date', 'modified', 'title' ),
					'default'     => 'date',
				),
				'order'    => array(
					'type'        => 'string',
					'description' => __( 'Sort direction.', 'od-mcp-bridge' ),
					'enum'        => array( 'asc', 'desc' ),
					'default'     => 'desc',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the collection output schema.
	 *
	 * @return array<string, mixed>
	 */
	private function get_posts_output_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items'      => array(
					'type'  => 'array',
					'items' => $this->get_post_summary_schema(),
				),
				'pagination' => array(
					'type'                 => 'object',
					'properties'           => array(
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
						'total'       => array( 'type' => 'integer' ),
						'total_pages' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'page', 'per_page', 'total', 'total_pages' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'items', 'pagination' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the single post output schema.
	 *
	 * @return array<string, mixed>
	 */
	private function get_post_schema() {
		$schema               = $this->get_post_summary_schema();
		$schema['properties'] = array_merge(
			$schema['properties'],
			array( 'content' => array( 'type' => 'string' ) )
		);
		$schema['required'][] = 'content';

		return $schema;
	}

	/**
	 * Returns the post summary schema.
	 *
	 * @return array<string, mixed>
	 */
	private function get_post_summary_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'excerpt'  => array( 'type' => 'string' ),
				'date'     => array( 'type' => 'string' ),
				'modified' => array( 'type' => 'string' ),
				'link'     => array( 'type' => 'string' ),
			),
			'required'             => array( 'id', 'title', 'excerpt', 'date', 'modified', 'link' ),
			'additionalProperties' => false,
		);
	}
}
