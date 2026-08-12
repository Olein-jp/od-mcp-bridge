<?php
/**
 * Safe, idempotent post draft creation.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use WP_Error;

/** Creates post drafts without exposing broader post mutation controls. */
final class Draft_Post_Creator {

	/** Marks posts created through the MCP ability. */
	const META_CREATED = '_od_mcp_bridge_created_via_mcp';

	/** Stores the caller-provided idempotency key. */
	const META_REQUEST_ID = '_od_mcp_bridge_request_id';

	/** Stores the normalized request hash. */
	const META_PAYLOAD_HASH = '_od_mcp_bridge_payload_hash';

	/** Maximum time before an abandoned creation lock may be reclaimed. */
	const LOCK_TTL = 600;

	/**
	 * Checks whether the current user may create the requested draft.
	 *
	 * @param mixed $input Validated ability input.
	 * @return bool
	 */
	public function check_permissions( $input = array() ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$categories = is_array( $input ) && isset( $input['categories'] ) && is_array( $input['categories'] ) ? $input['categories'] : array();
		if ( empty( $categories ) ) {
			return true;
		}

		$taxonomy = get_taxonomy( 'category' );

		return $taxonomy && current_user_can( $taxonomy->cap->assign_terms );
	}

	/**
	 * Creates a draft or returns the draft previously created for the request ID.
	 *
	 * @param mixed $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( $input = array() ) {
		$normalized = $this->normalize_input( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$user_id      = get_current_user_id();
		$request_id   = $normalized['request_id'];
		$payload_hash = hash( 'sha256', wp_json_encode( $normalized ) );
		$existing     = $this->find_existing_post( $user_id, $request_id );
		if ( $existing ) {
			return $this->format_existing_result( $existing, $payload_hash );
		}

		$lock_key = $this->get_lock_key( $user_id, $request_id );
		if ( ! $this->acquire_lock( $lock_key ) ) {
			$existing = $this->find_existing_post( $user_id, $request_id );

			return $existing
				? $this->format_existing_result( $existing, $payload_hash )
				: new WP_Error(
					'od_mcp_bridge_draft_request_in_progress',
					__( 'A draft creation request with this request ID is already in progress.', 'od-mcp-bridge' ),
					array( 'status' => 409 )
				);
		}

		try {
			$existing = $this->find_existing_post( $user_id, $request_id );
			if ( $existing ) {
				return $this->format_existing_result( $existing, $payload_hash );
			}

			$post_id = wp_insert_post(
				array(
					'post_author'   => $user_id,
					'post_content'  => $normalized['content'],
					'post_excerpt'  => $normalized['excerpt'],
					'post_status'   => 'draft',
					'post_title'    => $normalized['title'],
					'post_type'     => 'post',
					'post_category' => $normalized['categories'],
					'meta_input'    => array(
						self::META_CREATED      => '1',
						self::META_REQUEST_ID   => $request_id,
						self::META_PAYLOAD_HASH => $payload_hash,
					),
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			return $this->format_result( $post_id, true );
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Normalizes and defensively validates ability input.
	 *
	 * @param mixed $input Ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	private function normalize_input( $input ) {
		$allowed = array( 'request_id', 'title', 'content', 'excerpt', 'categories' );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), $allowed ) || ! isset( $input['request_id'], $input['title'], $input['content'] ) ) {
			return $this->invalid_input_error();
		}

		$request_id = strtolower( trim( (string) $input['request_id'] ) );
		if ( ! function_exists( 'wp_is_uuid' ) || ! wp_is_uuid( $request_id ) ) {
			return $this->invalid_input_error( __( 'request_id must be a valid UUID.', 'od-mcp-bridge' ) );
		}

		$title      = sanitize_text_field( $input['title'] );
		$content    = wp_kses_post( $input['content'] );
		$excerpt    = isset( $input['excerpt'] ) ? wp_kses_post( $input['excerpt'] ) : '';
		$categories = isset( $input['categories'] ) ? $input['categories'] : array();

		if ( '' === trim( wp_strip_all_tags( $title ) ) || $this->string_length( $title ) > 200 || $this->string_length( $content ) > 200000 || $this->string_length( $excerpt ) > 5000 ) {
			return $this->invalid_input_error();
		}

		if ( ! is_array( $categories ) || count( $categories ) > 20 ) {
			return $this->invalid_input_error( __( 'categories must contain at most 20 category IDs.', 'od-mcp-bridge' ) );
		}

		$normalized_categories = array_map( 'absint', $categories );
		if ( in_array( 0, $normalized_categories, true ) || count( $normalized_categories ) !== count( array_unique( $normalized_categories ) ) ) {
			return $this->invalid_input_error( __( 'categories must contain unique positive category IDs.', 'od-mcp-bridge' ) );
		}

		sort( $normalized_categories, SORT_NUMERIC );
		foreach ( $normalized_categories as $category_id ) {
			if ( ! term_exists( $category_id, 'category' ) ) {
				return $this->invalid_input_error( __( 'One or more categories do not exist.', 'od-mcp-bridge' ) );
			}
		}

		return array(
			'request_id' => $request_id,
			'title'      => $title,
			'content'    => $content,
			'excerpt'    => $excerpt,
			'categories' => $normalized_categories,
		);
	}

	/**
	 * Returns the post created for a request ID and user, if any.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $request_id Idempotency UUID.
	 * @return int
	 */
	private function find_existing_post( $user_id, $request_id ) {
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
				'post_type'        => 'post',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		// phpcs:enable

		return empty( $post_ids ) ? 0 : (int) $post_ids[0];
	}

	/**
	 * Formats a replayed request or rejects request ID reuse.
	 *
	 * @param int    $post_id      Existing post ID.
	 * @param string $payload_hash Normalized request hash.
	 * @return array<string, mixed>|WP_Error
	 */
	private function format_existing_result( $post_id, $payload_hash ) {
		$stored_hash = (string) get_post_meta( $post_id, self::META_PAYLOAD_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $payload_hash ) ) {
			return new WP_Error(
				'od_mcp_bridge_draft_request_conflict',
				__( 'This request ID has already been used with different draft content.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		if ( 'draft' !== get_post_status( $post_id ) ) {
			return new WP_Error(
				'od_mcp_bridge_draft_request_already_used',
				__( 'The post created for this request ID is no longer a draft.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		return $this->format_result( $post_id, false );
	}

	/**
	 * Formats a successful ability result.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $created Whether this execution created the post.
	 * @return array<string, mixed>
	 */
	private function format_result( $post_id, $created ) {
		return array(
			'id'       => (int) $post_id,
			'status'   => 'draft',
			'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
			'created'  => (bool) $created,
		);
	}

	/**
	 * Acquires a short-lived atomic lock, reclaiming only abandoned locks.
	 *
	 * @param string $lock_key Lock option name.
	 * @return bool
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
	 * Builds a site- and user-specific option key without storing request data.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $request_id Idempotency UUID.
	 * @return string
	 */
	private function get_lock_key( $user_id, $request_id ) {
		return 'od_mcp_bridge_draft_lock_' . md5( get_current_blog_id() . '|' . $user_id . '|' . $request_id );
	}

	/**
	 * Returns a Unicode-aware string length when available.
	 *
	 * @param string $value Input string.
	 * @return int
	 */
	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Returns a consistent validation error.
	 *
	 * @param string $message Optional custom message.
	 * @return WP_Error
	 */
	private function invalid_input_error( $message = '' ) {
		return new WP_Error(
			'od_mcp_bridge_invalid_draft_input',
			$message ? $message : __( 'The draft input is invalid.', 'od-mcp-bridge' ),
			array( 'status' => 400 )
		);
	}
}
