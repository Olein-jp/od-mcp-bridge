<?php
/**
 * Safe, idempotent block template part creation.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use WP_Error;

/** Creates new database-backed template parts for the active block theme. */
final class Template_Part_Creator {

	/** Marks template parts created through the MCP ability. */
	const META_CREATED = '_od_mcp_bridge_created_via_mcp';

	/** Stores the caller-provided idempotency key. */
	const META_REQUEST_ID = '_od_mcp_bridge_request_id';

	/** Stores the normalized request hash. */
	const META_PAYLOAD_HASH = '_od_mcp_bridge_payload_hash';

	/** Maximum time before an abandoned creation lock may be reclaimed. */
	const LOCK_TTL = 600;

	/** Checks whether the current user may create template parts. */
	public function check_permissions() {
		return current_user_can( Role_Manager::CREATE_TEMPLATE_PARTS );
	}

	/**
	 * Creates a template part for the active block theme.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( $input = array() ) {
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return new WP_Error(
				'od_mcp_bridge_block_theme_required',
				__( 'Template parts can only be created while a block theme is active.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$user_id       = get_current_user_id();
		$theme         = get_stylesheet();
		$request_id    = $normalized['request_id'];
		$payload_hash  = hash( 'sha256', wp_json_encode( array_merge( $normalized, array( 'theme' => $theme ) ) ) );
		$existing      = $this->find_existing_part( $user_id, $request_id );
		$template_id   = $theme . '//' . $normalized['slug'];
		$request_lock  = $this->get_lock_key( 'request', $user_id . '|' . $request_id );
		$template_lock = $this->get_lock_key( 'template', $normalized['slug'] );

		if ( $existing ) {
			return $this->format_existing_result( $existing, $payload_hash );
		}

		if ( ! $this->acquire_lock( $request_lock ) ) {
			return new WP_Error(
				'od_mcp_bridge_template_part_request_in_progress',
				__( 'A template part creation request with this request ID is already in progress.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		try {
			$existing = $this->find_existing_part( $user_id, $request_id );
			if ( $existing ) {
				return $this->format_existing_result( $existing, $payload_hash );
			}

			if ( ! $this->acquire_lock( $template_lock ) ) {
				return new WP_Error(
					'od_mcp_bridge_template_part_slug_in_progress',
					__( 'A template part creation request for this slug is already in progress.', 'od-mcp-bridge' ),
					array( 'status' => 409 )
				);
			}

			try {
				if ( get_block_template( $template_id, 'wp_template_part' ) || get_page_by_path( $normalized['slug'], OBJECT, 'wp_template_part' ) ) {
					return new WP_Error(
						'od_mcp_bridge_template_part_exists',
						__( 'A template part with this slug already exists or the slug is reserved by another theme.', 'od-mcp-bridge' ),
						array( 'status' => 409 )
					);
				}

				$post_id = wp_insert_post(
					wp_slash(
						array(
							'post_author'  => $user_id,
							'post_content' => $normalized['content'],
							'post_name'    => $normalized['slug'],
							'post_status'  => 'publish',
							'post_title'   => $normalized['title'],
							'post_type'    => 'wp_template_part',
							'meta_input'   => array(
								self::META_CREATED      => '1',
								self::META_REQUEST_ID   => $request_id,
								self::META_PAYLOAD_HASH => $payload_hash,
							),
						)
					),
					true
				);

				if ( is_wp_error( $post_id ) ) {
					return $post_id;
				}
				if ( get_post_field( 'post_name', $post_id ) !== $normalized['slug'] ) {
					wp_delete_post( $post_id, true );

					return new WP_Error(
						'od_mcp_bridge_template_part_slug_conflict',
						__( 'The requested template part slug could not be reserved.', 'od-mcp-bridge' ),
						array( 'status' => 409 )
					);
				}

				$theme_terms = wp_set_post_terms( $post_id, array( $theme ), 'wp_theme' );
				$area_terms  = wp_set_post_terms( $post_id, array( $normalized['area'] ), 'wp_template_part_area' );
				if ( is_wp_error( $theme_terms ) || is_wp_error( $area_terms ) ) {
					wp_delete_post( $post_id, true );

					return new WP_Error(
						'od_mcp_bridge_template_part_terms_failed',
						__( 'The template part could not be assigned to the active theme and area.', 'od-mcp-bridge' ),
						array( 'status' => 500 )
					);
				}

				return $this->format_result( $post_id, true );
			} finally {
				delete_option( $template_lock );
			}
		} finally {
			delete_option( $request_lock );
		}
	}

	/**
	 * Normalizes and defensively validates ability input.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_input( $input ) {
		$allowed = array( 'request_id', 'title', 'slug', 'content', 'area' );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), $allowed ) || ! isset( $input['request_id'], $input['title'], $input['slug'], $input['content'], $input['area'] ) ) {
			return $this->invalid_input_error();
		}
		foreach ( $allowed as $key ) {
			if ( ! is_string( $input[ $key ] ) ) {
				return $this->invalid_input_error();
			}
		}

		$request_id = strtolower( trim( (string) $input['request_id'] ) );
		$title      = sanitize_text_field( $input['title'] );
		$slug       = sanitize_title( $input['slug'] );
		$content    = wp_kses_post( $input['content'] );
		$area       = sanitize_key( $input['area'] );

		if ( ! function_exists( 'wp_is_uuid' ) || ! wp_is_uuid( $request_id ) ) {
			return $this->invalid_input_error( __( 'request_id must be a valid UUID.', 'od-mcp-bridge' ) );
		}

		if ( '' === trim( wp_strip_all_tags( $title ) ) || $this->string_length( $title ) > 200 || '' === $slug || $slug !== $input['slug'] || $this->string_length( $slug ) > 200 || $this->string_length( $content ) > 200000 ) {
			return $this->invalid_input_error();
		}

		$areas = wp_list_pluck( get_allowed_block_template_part_areas(), 'area' );
		if ( '' === $area || $area !== $input['area'] || ! in_array( $area, $areas, true ) ) {
			return $this->invalid_input_error( __( 'area must be one of the template part areas allowed by WordPress.', 'od-mcp-bridge' ) );
		}

		return array(
			'request_id' => $request_id,
			'title'      => $title,
			'slug'       => $slug,
			'content'    => $content,
			'area'       => $area,
		);
	}

	/**
	 * Returns a previously created template part ID for this user and request.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $request_id Request UUID.
	 */
	private function find_existing_part( $user_id, $request_id ) {
		// The bounded protected-meta lookup is required for durable idempotency.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$post_ids = get_posts(
			array(
				'author'           => $user_id,
				'fields'           => 'ids',
				'meta_key'         => self::META_REQUEST_ID,
				'meta_value'       => $request_id,
				'no_found_rows'    => true,
				'order'            => 'ASC',
				'orderby'          => 'ID',
				'post_status'      => array_keys( get_post_stati() ),
				'post_type'        => 'wp_template_part',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		// phpcs:enable

		return empty( $post_ids ) ? 0 : (int) $post_ids[0];
	}

	/**
	 * Returns a replayed request or rejects request ID reuse.
	 *
	 * @param int    $post_id      Existing template part post ID.
	 * @param string $payload_hash Normalized request hash.
	 */
	private function format_existing_result( $post_id, $payload_hash ) {
		$stored_hash = (string) get_post_meta( $post_id, self::META_PAYLOAD_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $payload_hash ) ) {
			return new WP_Error(
				'od_mcp_bridge_template_part_request_conflict',
				__( 'This request ID has already been used with different template part content.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		if ( 'publish' !== get_post_status( $post_id ) ) {
			return new WP_Error(
				'od_mcp_bridge_template_part_request_already_used',
				__( 'The template part created for this request ID is no longer available.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		return $this->format_result( $post_id, false );
	}

	/**
	 * Formats a successful ability result.
	 *
	 * @param int  $post_id Template part post ID.
	 * @param bool $created Whether this execution created the template part.
	 */
	private function format_result( $post_id, $created ) {
		$theme = wp_get_post_terms( $post_id, 'wp_theme', array( 'fields' => 'slugs' ) );
		$area  = wp_get_post_terms( $post_id, 'wp_template_part_area', array( 'fields' => 'slugs' ) );

		return array(
			'id'          => (int) $post_id,
			'template_id' => ( is_wp_error( $theme ) || empty( $theme ) ? get_stylesheet() : $theme[0] ) . '//' . get_post_field( 'post_name', $post_id ),
			'status'      => (string) get_post_status( $post_id ),
			'slug'        => (string) get_post_field( 'post_name', $post_id ),
			'theme'       => is_wp_error( $theme ) || empty( $theme ) ? get_stylesheet() : (string) $theme[0],
			'area'        => is_wp_error( $area ) || empty( $area ) ? '' : (string) $area[0],
			'created'     => (bool) $created,
		);
	}

	/**
	 * Acquires a short-lived atomic lock, reclaiming only abandoned locks.
	 *
	 * @param string $lock_key Option key used for the lock.
	 */
	private function acquire_lock( $lock_key ) {
		if ( add_option( $lock_key, time(), '', false ) ) {
			return true;
		}

		$created_at = (int) get_option( $lock_key, 0 );
		if ( $created_at > 0 && $created_at < time() - self::LOCK_TTL ) {
			delete_option( $lock_key );

			return add_option( $lock_key, time(), '', false );
		}

		return false;
	}

	/**
	 * Returns a namespaced lock key.
	 *
	 * @param string $type  Lock type.
	 * @param string $value Value to hash into the key.
	 */
	private function get_lock_key( $type, $value ) {
		return 'od_mcp_bridge_template_part_' . $type . '_lock_' . md5( get_current_blog_id() . '|' . $value );
	}

	/**
	 * Creates a consistent invalid-input error.
	 *
	 * @param string $message Optional error message.
	 */
	private function invalid_input_error( $message = '' ) {
		return new WP_Error(
			'od_mcp_bridge_invalid_template_part_input',
			$message ? $message : __( 'The template part input is invalid.', 'od-mcp-bridge' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Returns a multibyte-safe string length where available.
	 *
	 * @param string $value Value to measure.
	 */
	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
