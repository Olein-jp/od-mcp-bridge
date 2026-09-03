<?php
/**
 * Safe, idempotent page draft creation.
 *
 * @package OdMcpBridge
 */

namespace Olein\MCPBridge;

use WP_Error;

/** Creates page drafts without exposing broader page mutation controls. */
final class Draft_Page_Creator {

	/** Marks pages created through the MCP ability. */
	const META_CREATED = '_od_mcp_bridge_created_via_mcp';

	/** Stores the caller-provided idempotency key. */
	const META_REQUEST_ID = '_od_mcp_bridge_request_id';

	/** Stores the normalized request hash. */
	const META_PAYLOAD_HASH = '_od_mcp_bridge_payload_hash';

	/** Maximum time before an abandoned creation lock may be reclaimed. */
	const LOCK_TTL = 600;

	/** Checks whether the current user may create page drafts. */
	public function check_permissions() {
		return current_user_can( 'edit_pages' );
	}

	/**
	 * Creates a page draft or returns the draft previously created for the request ID.
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
		$existing     = $this->find_existing_page( $user_id, $request_id );
		if ( $existing ) {
			return $this->format_existing_result( $existing, $payload_hash );
		}

		$lock_key = $this->get_lock_key( $user_id, $request_id );
		if ( ! $this->acquire_lock( $lock_key ) ) {
			$existing = $this->find_existing_page( $user_id, $request_id );

			return $existing
				? $this->format_existing_result( $existing, $payload_hash )
				: new WP_Error(
					'od_mcp_bridge_page_draft_request_in_progress',
					__( 'A page draft creation request with this request ID is already in progress.', 'od-mcp-bridge' ),
					array( 'status' => 409 )
				);
		}

		try {
			$existing = $this->find_existing_page( $user_id, $request_id );
			if ( $existing ) {
				return $this->format_existing_result( $existing, $payload_hash );
			}

			$page_id = wp_insert_post(
				wp_slash(
					array(
						'post_author'  => $user_id,
						'post_content' => $normalized['content'],
						'post_excerpt' => $normalized['excerpt'],
						'post_parent'  => $normalized['parent_id'],
						'post_status'  => 'draft',
						'post_title'   => $normalized['title'],
						'post_type'    => 'page',
						'menu_order'   => $normalized['menu_order'],
						'meta_input'   => array(
							self::META_CREATED      => '1',
							self::META_REQUEST_ID   => $request_id,
							self::META_PAYLOAD_HASH => $payload_hash,
						),
					)
				),
				true
			);

			if ( is_wp_error( $page_id ) ) {
				return $page_id;
			}

			return $this->format_result( $page_id, true );
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
		$allowed = array( 'request_id', 'title', 'content', 'excerpt', 'parent_id', 'menu_order' );
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), $allowed ) || ! isset( $input['request_id'], $input['title'], $input['content'] ) ) {
			return $this->invalid_input_error();
		}
		if ( ! is_string( $input['request_id'] ) || ! is_string( $input['title'] ) || ! is_string( $input['content'] ) || ( isset( $input['excerpt'] ) && ! is_string( $input['excerpt'] ) ) ) {
			return $this->invalid_input_error();
		}

		$request_id = strtolower( trim( (string) $input['request_id'] ) );
		if ( ! function_exists( 'wp_is_uuid' ) || ! wp_is_uuid( $request_id ) ) {
			return $this->invalid_input_error( __( 'request_id must be a valid UUID.', 'od-mcp-bridge' ) );
		}

		$title      = sanitize_text_field( $input['title'] );
		$content    = wp_kses_post( $input['content'] );
		$excerpt    = isset( $input['excerpt'] ) ? wp_kses_post( $input['excerpt'] ) : '';
		$parent_id  = isset( $input['parent_id'] ) ? absint( $input['parent_id'] ) : 0;
		$menu_order = isset( $input['menu_order'] ) ? absint( $input['menu_order'] ) : 0;

		if ( '' === trim( wp_strip_all_tags( $title ) ) || $this->string_length( $title ) > 200 || $this->string_length( $content ) > 200000 || $this->string_length( $excerpt ) > 5000 ) {
			return $this->invalid_input_error();
		}

		if ( isset( $input['parent_id'] ) && ( ! is_int( $input['parent_id'] ) || $input['parent_id'] < 0 ) ) {
			return $this->invalid_input_error( __( 'parent_id must be a non-negative integer.', 'od-mcp-bridge' ) );
		}

		if ( $parent_id ) {
			$parent = get_post( $parent_id );
			if ( ! $parent || 'page' !== $parent->post_type || 'trash' === $parent->post_status || ! current_user_can( 'edit_post', $parent_id ) ) {
				return $this->invalid_input_error( __( 'parent_id must reference an editable existing page that is not in the trash.', 'od-mcp-bridge' ) );
			}
		}

		if ( isset( $input['menu_order'] ) && ( ! is_int( $input['menu_order'] ) || $input['menu_order'] < 0 || $input['menu_order'] > 100000 ) ) {
			return $this->invalid_input_error( __( 'menu_order must be an integer from 0 to 100000.', 'od-mcp-bridge' ) );
		}

		return array(
			'request_id' => $request_id,
			'title'      => $title,
			'content'    => $content,
			'excerpt'    => $excerpt,
			'parent_id'  => $parent_id,
			'menu_order' => $menu_order,
		);
	}

	/**
	 * Returns a previously created page ID for this user and request.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $request_id Request UUID.
	 */
	private function find_existing_page( $user_id, $request_id ) {
		// The bounded protected-meta lookup is required for durable idempotency.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$page_ids = get_posts(
			array(
				'author'           => $user_id,
				'fields'           => 'ids',
				'meta_key'         => self::META_REQUEST_ID,
				'meta_value'       => $request_id,
				'no_found_rows'    => true,
				'order'            => 'ASC',
				'orderby'          => 'ID',
				'post_status'      => array_keys( get_post_stati() ),
				'post_type'        => 'page',
				'posts_per_page'   => 1,
				'suppress_filters' => true,
			)
		);
		// phpcs:enable

		return empty( $page_ids ) ? 0 : (int) $page_ids[0];
	}

	/**
	 * Returns a replayed request or rejects request ID reuse.
	 *
	 * @param int    $page_id      Existing page ID.
	 * @param string $payload_hash Normalized request hash.
	 */
	private function format_existing_result( $page_id, $payload_hash ) {
		$stored_hash = (string) get_post_meta( $page_id, self::META_PAYLOAD_HASH, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $payload_hash ) ) {
			return new WP_Error(
				'od_mcp_bridge_page_draft_request_conflict',
				__( 'This request ID has already been used with different page draft content.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		if ( 'draft' !== get_post_status( $page_id ) ) {
			return new WP_Error(
				'od_mcp_bridge_page_draft_request_already_used',
				__( 'The page created for this request ID is no longer a draft.', 'od-mcp-bridge' ),
				array( 'status' => 409 )
			);
		}

		return $this->format_result( $page_id, false );
	}

	/**
	 * Formats a successful ability result.
	 *
	 * @param int  $page_id Page ID.
	 * @param bool $created Whether this execution created the page.
	 */
	private function format_result( $page_id, $created ) {
		return array(
			'id'       => (int) $page_id,
			'status'   => 'draft',
			'edit_url' => (string) get_edit_post_link( $page_id, 'raw' ),
			'created'  => (bool) $created,
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
	 * Returns the per-site and per-user request lock key.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $request_id Request UUID.
	 */
	private function get_lock_key( $user_id, $request_id ) {
		return 'od_mcp_bridge_page_draft_lock_' . md5( get_current_blog_id() . '|' . $user_id . '|' . $request_id );
	}

	/**
	 * Creates a consistent invalid-input error.
	 *
	 * @param string $message Optional error message.
	 */
	private function invalid_input_error( $message = '' ) {
		return new WP_Error(
			'od_mcp_bridge_invalid_page_draft_input',
			$message ? $message : __( 'The page draft input is invalid.', 'od-mcp-bridge' ),
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
