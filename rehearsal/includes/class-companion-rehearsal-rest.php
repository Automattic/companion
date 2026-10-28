<?php
/**
 * Rehearsal: REST routes for saving, deleting and installing rehearsals.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for saving, deleting and installing rehearsals.
 */
final class Companion_Rehearsal_Rest {
	/**
	 * Runs, recipe saving from the modal, and installing from a share link.
	 */
	public static function routes() {
		$admin  = static function () {
			return current_user_can( 'manage_options' );
		};
		$recipe = array(
			'title'  => array(
				'type'     => 'string',
				'required' => true,
			),
			'recipe' => array(
				'type'     => 'object',
				'required' => true,
			),
		);
		register_rest_route(
			'rehearsal/v1',
			'/rehearsals',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_save_recipe' ),
				'permission_callback' => $admin,
				'args'                => $recipe,
			)
		);
		register_rest_route(
			'rehearsal/v1',
			'/rehearsals/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_save_recipe' ),
					'permission_callback' => $admin,
					'args'                => $recipe,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'rest_delete_recipe' ),
					'permission_callback' => $admin,
				),
			)
		);
		register_rest_route(
			'rehearsal/v1',
			'/bundle',
			array(
				'methods'             => 'POST',
				'callback'            => static function ( $request ) {
					return rest_ensure_response( array( 'url' => Companion_Rehearsal_Share::bundle_url( $request['ids'] ) ) );
				},
				'permission_callback' => $admin,
				'args'                => array(
					'ids' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'minItems' => 1,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'rehearsal/v1',
			'/role-user',
			array(
				'methods'             => 'POST',
				'callback'            => array( Companion_Rehearsal_Roles::class, 'rest_role_user' ),
				'permission_callback' => $admin,
				'args'                => array(
					'role' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'rehearsal/v1',
			'/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_install' ),
				'permission_callback' => $admin,
				'args'                => array(
					'url'     => array(
						'type'     => 'string',
						'format'   => 'uri',
						'required' => true,
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					// The rehearsal ID the preview showed; installing anything else is refused.
					'expect'  => array(
						'type'    => 'string',
						'pattern' => '^[0-9a-f]{64}$',
					),
				),
			)
		);
		register_rest_route(
			'rehearsal/v1',
			'/runs/search',
			array(
				'methods'             => 'GET',
				'callback'            => static function ( $request ) {
					return rest_ensure_response( Companion_Rehearsal_Runs::find_runs( $request->get_params() ) );
				},
				'permission_callback' => $admin,
				'args'                => array(
					'recipe' => array( 'type' => 'integer' ),
					'user'   => array( 'type' => 'integer' ),
					'status' => array(
						'type' => 'string',
						'enum' => array( '', 'pass', 'fail', 'queued' ),
					),
					'search' => array( 'type' => 'string' ),
					'ref'    => array( 'type' => 'string' ),
					'page'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			)
		);
		register_rest_route(
			'rehearsal/v1',
			'/runs',
			array(
				'methods'             => 'POST',
				'callback'            => array( Companion_Rehearsal_Runs::class, 'create_run' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'recipe_id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'recipe_hash' => array(
						'type'     => 'string',
						'required' => true,
					),
					'status'      => array(
						'type'     => 'string',
						'enum'     => array( 'pass', 'fail', 'queued' ),
						'required' => true,
					),
					'log'         => array(
						'type'    => 'array',
						'default' => array(),
					),
					'snapshots'   => array(
						'type'    => 'array',
						'default' => array(),
					),
					'errors'      => array(
						'type'    => 'array',
						'default' => array(),
					),
					// Which runner ran it: this screen, or a Playwright test reporting back.
					'runner'      => array(
						'type'    => 'string',
						'enum'    => array( 'browser', 'playwright' ),
						'default' => 'browser',
					),
					// The screen size the runner used: desktop, or a phone's for pages' mobile layouts.
					'viewport'    => array(
						'type'    => 'string',
						'enum'    => array( 'desktop', 'mobile' ),
						'default' => 'desktop',
					),
					// A label the caller picks, such as an agent's task ID, to find this run again.
					'ref'         => array(
						'type'    => 'string',
						'pattern' => Companion_Rehearsal_Runs::REF_PATTERN,
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Create (no id) or update a recipe from the modal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save_recipe( $request ) {
		$id = (int) $request['id'];
		if ( $id && Companion_Rehearsal::RECIPE !== get_post_type( $id ) ) {
			return new WP_Error( 'rehearsal_no_recipe', 'That rehearsal does not exist.', array( 'status' => 404 ) );
		}
		$recipe = $request['recipe'];
		$error  = Companion_Rehearsal_Schema::validate( $recipe );
		$title  = trim( sanitize_text_field( $request['title'] ) );
		if ( $error || '' === $title ) {
			return new WP_Error( 'rehearsal_invalid', $error ? $error : 'Give the rehearsal a title.', array( 'status' => 400 ) );
		}
		// Posting the same rehearsal again, as a retrying agent does, returns the one already saved.
		$same = $id ? 0 : Companion_Rehearsal_Store::find_same( $title, $recipe );
		if ( $same ) {
			return rest_ensure_response( Companion_Rehearsal_Store::recipe_for_js( get_post( $same ) ) + array( 'existing' => true ) );
		}
		$id = Companion_Rehearsal_Store::save_recipe( $id, $title, $recipe );
		return is_wp_error( $id ) ? $id : rest_ensure_response( Companion_Rehearsal_Store::recipe_for_js( get_post( $id ) ) );
	}

	/**
	 * Move a recipe to the trash. Its runs stay, as evidence.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete_recipe( $request ) {
		$id = (int) $request['id'];
		if ( Companion_Rehearsal::RECIPE !== get_post_type( $id ) ) {
			return new WP_Error( 'rehearsal_no_recipe', 'That rehearsal does not exist.', array( 'status' => 404 ) );
		}
		wp_trash_post( $id );
		return rest_ensure_response( array( 'deleted' => $id ) );
	}

	/**
	 * Preview (dry_run) or install a rehearsal from a share link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_install( $request ) {
		$result = self::install( $request['url'], (bool) $request['dry_run'], (string) $request['expect'] );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Fetch a share link's JSON, check it, and preview it (dry_run) or install it. Installing from the
	 * same link again updates that copy rather than adding a second one. `wp rehearsal install` uses it too.
	 *
	 * @param string $url     Share link.
	 * @param bool   $dry_run Only preview.
	 * @param string $expect  The rehearsal ID the preview showed; installing anything else is refused.
	 * @return array|WP_Error The preview, or the installed rehearsal as the runner sees it.
	 */
	public static function install( $url, $dry_run, $expect ) {
		$source = remove_query_arg( 'format', esc_url_raw( $url ) );
		if ( ! wp_http_validate_url( $source ) || 'https' !== wp_parse_url( $source, PHP_URL_SCHEME ) || false === strpos( $source, 'rehearsal=' ) ) {
			return new WP_Error( 'rehearsal_bad_link', 'That is not a rehearsal link. It should look like https://some-site.jurassic.ninja/?rehearsal=…', array( 'status' => 400 ) );
		}
		$response = wp_safe_remote_get(
			add_query_arg( 'format', 'json', $source ),
			array(
				'timeout'             => 15,
				'limit_response_size' => MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rehearsal_fetch_failed', 'Could not reach the site that shared this rehearsal: ' . $response->get_error_message(), array( 'status' => 502 ) );
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $payload ) || 'rehearsal' !== ( $payload['format'] ?? '' ) ) {
			return new WP_Error( 'rehearsal_not_recipe', 'The link did not return a rehearsal. It may have been deleted, or the site may have expired.', array( 'status' => 502 ) );
		}
		$recipe = $payload['definition'] ?? null;
		$error  = Companion_Rehearsal_Schema::validate( $recipe );
		if ( $error ) {
			return new WP_Error( 'rehearsal_invalid', Companion_Rehearsal_Schema::install_error( is_array( $recipe ) ? $recipe : array(), $error ), array( 'status' => 400 ) );
		}
		$text     = static fn( $key ) => isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) ? $payload[ $key ] : '';
		$title    = trim( sanitize_text_field( $text( 'title' ) ) );
		$title    = '' === $title ? 'Shared rehearsal' : $title;
		$existing = get_posts(
			array(
				'post_type'      => Companion_Rehearsal::RECIPE,
				'post_status'    => 'publish',
				'meta_key'       => '_rehearsal_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$preview  = array(
			'title'     => $title,
			'recipe'    => $recipe,
			'id'        => Companion_Rehearsal_Schema::hash( Companion_Rehearsal_Schema::canonical( $recipe ) ),
			'site'      => esc_url_raw( $text( 'site' ) ),
			'build'     => sanitize_text_field( $text( 'build' ) ),
			'updates'   => $existing ? (int) $existing[0] : 0,
			'format'    => Companion_Rehearsal_Schema::format_of( $recipe ),
			'newer'     => Companion_Rehearsal_Schema::is_newer( $recipe ),
			'shared_by' => sanitize_text_field( $text( 'shared_by' ) ),
			'missing'   => Companion_Rehearsal_Requirements::missing_requirements( $recipe ),
		);
		if ( $dry_run ) {
			return $preview;
		}
		if ( ! $expect || ! hash_equals( $preview['id'], $expect ) ) {
			return new WP_Error( 'rehearsal_changed', 'The shared rehearsal changed since you previewed it. Preview it again before installing.', array( 'status' => 409 ) );
		}
		$id = Companion_Rehearsal_Store::save_recipe( $existing ? (int) $existing[0] : 0, $title, $recipe, $source );
		return is_wp_error( $id ) ? $id : Companion_Rehearsal_Store::recipe_for_js( get_post( $id ) ) + array( 'updated' => (bool) $existing );
	}
}
