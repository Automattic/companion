<?php
/**
 * Rehearsal: Saved runs: cleaning what the runner sends, storing it, and finding runs again.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saved runs: cleaning what the runner sends, storing it, and finding runs again.
 */
final class Companion_Rehearsal_Runs {
	// What a run's ref may be: a short label, so it is safe in a URL and a meta query.
	const REF_PATTERN = '^[A-Za-z0-9._-]{0,64}$';

	/**
	 * The step log as the runner sent it, limited to known fields and sizes.
	 *
	 * @param array $log Log entries: { ok, msg, ms, pages? }.
	 * @return array[]
	 */
	public static function clean_log( array $log ) {
		$clean = array();
		foreach ( array_slice( $log, 0, 200 ) as $entry ) {
			$entry = (array) $entry;
			$item  = array(
				'ok'  => ! empty( $entry['ok'] ),
				// Plain text, only ever shown escaped: kept verbatim so selectors and quoted markup survive.
				'msg' => substr( (string) ( $entry['msg'] ?? '' ), 0, 1000 ),
				'ms'  => absint( $entry['ms'] ?? 0 ),
			);
			foreach ( array_slice( (array) ( $entry['pages'] ?? array() ), 0, 10 ) as $page ) {
				$item['pages'][] = self::clean_page_metrics( (array) $page );
			}
			$clean[] = $item;
		}
		return $clean;
	}

	/**
	 * Console errors as the runner grouped them. Stored as plain text and only ever shown escaped, so
	 * they stay verbatim for bug reports.
	 *
	 * @param array $errors Grouped errors: { msg, page, source, count }.
	 * @return array[]
	 */
	public static function clean_errors( array $errors ) {
		$clean = array();
		foreach ( array_slice( $errors, 0, 50 ) as $err ) {
			$err     = (array) $err;
			$clean[] = array(
				'msg'    => substr( (string) ( $err['msg'] ?? '' ), 0, 4000 ),
				'page'   => substr( (string) ( $err['page'] ?? '' ), 0, 300 ),
				'source' => substr( (string) ( $err['source'] ?? '' ), 0, 1000 ),
				'count'  => max( 1, absint( $err['count'] ?? 1 ) ),
			);
		}
		return $clean;
	}

	/**
	 * Keep only known page-metric fields, as numbers.
	 *
	 * @param array $page Metrics from the runner.
	 * @return array
	 */
	public static function clean_page_metrics( array $page ) {
		$clean = array( 'url' => substr( wp_strip_all_tags( (string) ( $page['url'] ?? '' ) ), 0, 300 ) );
		foreach ( array_merge( Companion_Rehearsal_Schema::BUDGET_KEYS, array( 'downloaded', 'unmeasured', 'load' ) ) as $k ) {
			$clean[ $k ] = isset( $page[ $k ] ) && is_numeric( $page[ $k ] ) ? 0 + $page[ $k ] : null;
		}
		$clean['warn']   = array_values( array_intersect( (array) ( $page['warn'] ?? array() ), Companion_Rehearsal_Schema::BUDGET_KEYS ) );
		$clean['limits'] = array_intersect_key( array_map( 'floatval', (array) ( $page['limits'] ?? array() ) ), array_flip( Companion_Rehearsal_Schema::BUDGET_KEYS ) );
		return $clean;
	}

	/**
	 * Runs are evidence: once the runner writes one, nobody edits it.
	 *
	 * @param string[] $caps    Primitive caps.
	 * @param string   $cap     Meta cap being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Extra args; [0] is the post ID.
	 * @return string[]
	 */
	public static function lock_runs( $caps, $cap, $user_id, $args ) {
		if ( 'edit_post' === $cap && ! empty( $args[0] ) && Companion_Rehearsal::RUN === get_post_type( $args[0] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * One page of runs, newest first, narrowed by recipe, result, who ran it, and a search.
	 *
	 * @param array $filters recipe (ID), status (pass|fail|queued), user (ID), ref (label the run was started
	 *                       with), search (title text or run number), page.
	 * @return array { runs, total, pages }
	 */
	public static function find_runs( array $filters ) {
		$args   = array(
			'post_type'      => Companion_Rehearsal::RUN,
			'post_status'    => 'publish',
			'posts_per_page' => 25,
			'paged'          => max( 1, (int) ( $filters['page'] ?? 1 ) ),
		);
		$search = trim( (string) ( $filters['search'] ?? '' ) );
		if ( ! empty( $filters['recipe'] ) ) {
			$args['post_parent'] = (int) $filters['recipe'];
		}
		if ( ! empty( $filters['user'] ) ) {
			$args['author'] = (int) $filters['user'];
		}
		foreach ( array( 'status', 'ref' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'key'   => "_rehearsal_$key",
					'value' => (string) $filters[ $key ],
				);
			}
		}
		if ( preg_match( '/^#?(\d+)$/', $search, $m ) ) {
			$args['p'] = (int) $m[1];
		} elseif ( '' !== $search ) {
			$args['s'] = $search;
		}
		$query = new WP_Query( $args );
		return array(
			'runs'  => array_map( array( __CLASS__, 'run_for_js' ), $query->posts ),
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Everyone who has run a recipe here, for the "Run by" filter.
	 *
	 * @return array[] { id, name }
	 */
	public static function run_users() {
		$users = get_users(
			array(
				'has_published_posts' => array( Companion_Rehearsal::RUN ),
				'fields'              => array( 'ID', 'user_login' ),
			)
		);
		return array_map(
			static function ( $u ) {
				return array(
					'id'   => (int) $u->ID,
					'name' => $u->user_login,
				);
			},
			$users
		);
	}

	/**
	 * A rehearsal's latest run.
	 *
	 * @param int $recipe_id Rehearsal ID.
	 * @return array|null As run_for_js() shapes it.
	 */
	public static function latest( $recipe_id ) {
		$last = get_posts(
			array(
				'post_type'      => Companion_Rehearsal::RUN,
				'post_status'    => 'publish',
				'post_parent'    => $recipe_id,
				'posts_per_page' => 1,
			)
		);
		return $last ? self::run_for_js( $last[0] ) : null;
	}

	/**
	 * A run as the runner and the history table see it.
	 *
	 * @param WP_Post $run Run post.
	 * @return array
	 */
	public static function run_for_js( $run ) {
		$user   = get_userdata( (int) $run->post_author );
		$recipe = (string) get_post_meta( $run->ID, '_rehearsal_recipe', true );
		return array(
			'id'        => $run->ID,
			'recipeId'  => (int) $run->post_parent,
			// The run's own title ("<recipe>: <status>") outlives a permanently deleted recipe.
			'title'     => get_post( $run->post_parent ) ? get_the_title( $run->post_parent ) : preg_replace( '/: (pass|fail|queued)$/', '', $run->post_title ) . ' (rehearsal deleted)',
			'status'    => get_post_meta( $run->ID, '_rehearsal_status', true ),
			// Recomputed from the steps the run used, so runs saved before IDs ignored key order still match.
			'hash'      => '' === $recipe ? get_post_meta( $run->ID, '_rehearsal_recipe_hash', true ) : Companion_Rehearsal_Schema::hash( $recipe ),
			'build'     => get_post_meta( $run->ID, '_rehearsal_build', true ),
			'log'       => json_decode( (string) get_post_meta( $run->ID, '_rehearsal_log', true ), true ),
			'snapshots' => count( (array) json_decode( (string) get_post_meta( $run->ID, '_rehearsal_snapshots', true ), true ) ),
			'errors'    => (array) json_decode( (string) get_post_meta( $run->ID, '_rehearsal_errors', true ), true ),
			'date'      => get_post_time( 'c', true, $run ),
			'by'        => $user ? $user->user_login : '',
			'byId'      => (int) $run->post_author,
			'role'      => (string) get_post_meta( $run->ID, '_rehearsal_role', true ),
			'runner'    => 'playwright' === get_post_meta( $run->ID, '_rehearsal_runner', true ) ? 'playwright' : 'browser',
			'ref'       => (string) get_post_meta( $run->ID, '_rehearsal_ref', true ),
		);
	}

	/**
	 * Save a run, but only for the exact recipe the server holds now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_run( $request ) {
		$recipe = get_post( $request['recipe_id'] );
		if ( ! $recipe || Companion_Rehearsal::RECIPE !== $recipe->post_type ) {
			return new WP_Error( 'rehearsal_no_recipe', 'That rehearsal does not exist.', array( 'status' => 404 ) );
		}
		if ( ! hash_equals( Companion_Rehearsal_Schema::hash( $recipe->post_content ), (string) $request['recipe_hash'] ) ) {
			return new WP_Error( 'rehearsal_recipe_changed', 'The rehearsal changed while it was running. Reload and run it again.', array( 'status' => 409 ) );
		}
		$missing = Companion_Rehearsal_Requirements::missing_requirements( (array) json_decode( $recipe->post_content, true ) );
		if ( $missing ) {
			return new WP_Error( 'rehearsal_needs_plugins', 'This rehearsal needs what is not active here: ' . implode( ', ', wp_list_pluck( $missing, 'name' ) ) . '.', array( 'status' => 409 ) );
		}

		$log       = self::clean_log( (array) $request['log'] );
		$errors    = self::clean_errors( (array) $request['errors'] );
		$snapshots = array();
		foreach ( array_slice( (array) $request['snapshots'], 0, 10 ) as $snap ) {
			$snapshots[] = array(
				'selector' => substr( sanitize_text_field( $snap['selector'] ?? '' ), 0, 200 ),
				'html'     => substr( (string) ( $snap['html'] ?? '' ), 0, 100000 ), // Stored as data, only ever shown escaped.
			);
		}

		$run_id = wp_insert_post(
			array(
				'post_type'   => Companion_Rehearsal::RUN,
				'post_status' => 'publish',
				'post_parent' => $recipe->ID,
				'post_author' => get_current_user_id(),
				'post_title'  => sprintf( '%s: %s', $recipe->post_title, $request['status'] ),
				'meta_input'  => array(
					'_rehearsal_status'      => $request['status'],
					'_rehearsal_recipe_hash' => Companion_Rehearsal_Schema::hash( $recipe->post_content ),
					'_rehearsal_recipe'      => wp_slash( $recipe->post_content ),
					'_rehearsal_build'       => Companion_Rehearsal_Admin::build(),
					'_rehearsal_log'         => wp_slash( wp_json_encode( $log ) ),
					'_rehearsal_snapshots'   => wp_slash( wp_json_encode( $snapshots ) ),
					'_rehearsal_errors'      => wp_slash( wp_json_encode( $errors ) ),
					'_rehearsal_role'        => sanitize_key( (string) ( json_decode( $recipe->post_content, true )['role'] ?? '' ) ),
					'_rehearsal_runner'      => $request['runner'],
					'_rehearsal_ref'         => (string) $request['ref'],
				),
			),
			true
		);
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}
		return rest_ensure_response( self::run_for_js( get_post( $run_id ) ) );
	}
}
