<?php
/**
 * Rehearsal: Rehearsal posts: registering, saving, and shaping them for the runner.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rehearsal posts: registering, saving, and shaping them for the runner.
 */
final class Companion_Rehearsal_Store {
	/**
	 * Both post types are admin-only: a recipe acts as whoever runs it.
	 */
	public static function register_types() {
		$admin_caps = array_fill_keys(
			array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'create_posts' ),
			'manage_options'
		);

		// Recipes are edited in the Rehearsal dialog, so the post type has no screens of its own.
		register_post_type(
			Companion_Rehearsal::RECIPE,
			array(
				'label'        => 'Rehearsals',
				'public'       => false,
				'show_ui'      => false,
				'map_meta_cap' => true,
				'capabilities' => $admin_caps,
			)
		);

		register_post_type(
			Companion_Rehearsal::RUN,
			array(
				'label'        => 'Rehearsal runs',
				'public'       => false,
				'show_ui'      => false,
				'map_meta_cap' => true,
				'capabilities' => array_merge( $admin_caps, array( 'create_posts' => 'do_not_allow' ) ),
			)
		);
	}

	/**
	 * Recipes plus their latest run, for the runner.
	 *
	 * @return array
	 */
	public static function recipes_for_js() {
		$posts = get_posts(
			array(
				'post_type'      => Companion_Rehearsal::RECIPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'recipe_for_js' ), $posts ) ) );
	}

	/**
	 * One recipe as the runner sees it, or null when its stored steps are unusable.
	 *
	 * @param WP_Post $post Recipe post.
	 * @return array|null
	 */
	public static function recipe_for_js( $post ) {
		$recipe = json_decode( $post->post_content, true );
		if ( Companion_Rehearsal_Schema::validate( $recipe ) ) {
			return null;
		}
		$last = get_posts(
			array(
				'post_type'      => Companion_Rehearsal::RUN,
				'post_status'    => 'publish',
				'post_parent'    => $post->ID,
				'posts_per_page' => 1,
			)
		);
		return array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'hash'     => Companion_Rehearsal_Schema::hash( $post->post_content ),
			'recipe'   => $recipe,
			'shareUrl' => Companion_Rehearsal_Share::share_url( $post->ID ),
			'source'   => (string) get_post_meta( $post->ID, '_rehearsal_source', true ),
			'missing'  => Companion_Rehearsal_Requirements::missing_requirements( $recipe ),
			'lastRun'  => $last ? Companion_Rehearsal_Runs::run_for_js( $last[0] ) : null,
		);
	}

	/**
	 * Create or update a recipe. Content is written after insert so no content filter (kses) can alter it.
	 *
	 * @param int    $id     Recipe ID, or 0 to create.
	 * @param string $title  Title.
	 * @param array  $recipe Validated recipe.
	 * @param string $source Share URL it was installed from, if any.
	 * @return int|WP_Error Recipe ID.
	 */
	public static function save_recipe( $id, $title, array $recipe, $source = '' ) {
		global $wpdb;
		$post = array(
			'post_type'   => Companion_Rehearsal::RECIPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		);
		$id   = $id ? wp_update_post( $post + array( 'ID' => $id ), true ) : wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$wpdb->update( $wpdb->posts, array( 'post_content' => Companion_Rehearsal_Schema::canonical( $recipe ) ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
		if ( $source ) {
			update_post_meta( $id, '_rehearsal_source', $source );
		}
		return $id;
	}
}
