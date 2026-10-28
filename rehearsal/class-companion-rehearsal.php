<?php
/**
 * Rehearsal: repeatable, shareable step-by-step checks of a Jurassic Ninja site, run in the browser
 * from Tools → Rehearsal. See rehearsal/README.md.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'schema', 'store', 'runs', 'requirements', 'roles', 'share', 'playwright', 'rest', 'admin', 'examples' ) as $companion_rehearsal_part ) {
	require_once __DIR__ . "/includes/class-companion-rehearsal-$companion_rehearsal_part.php";
}
unset( $companion_rehearsal_part );

/**
 * Names shared by every part of Rehearsal, and the hooks that connect them.
 */
final class Companion_Rehearsal {
	const VERSION = '0.20.0';
	const RECIPE  = 'rehearsal';
	const RUN     = 'rehearsal_run';
	const SLUG    = 'rehearsal';
	// The newest rehearsal format this code can run. Raise it when rehearsals gain a step, matcher or
	// setting that older versions would not understand, so those versions can warn.
	const FORMAT = 2;

	/**
	 * A file in the rehearsal folder, wherever the class asking lives.
	 *
	 * @param string $relative Path inside rehearsal/.
	 * @return string
	 */
	public static function path( $relative ) {
		return __DIR__ . '/' . ltrim( $relative, '/' );
	}

	/**
	 * The URL of a file in the rehearsal folder.
	 *
	 * @param string $relative Path inside rehearsal/.
	 * @return string
	 */
	public static function url( $relative ) {
		return plugin_dir_url( __FILE__ ) . ltrim( $relative, '/' );
	}

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( Companion_Rehearsal_Store::class, 'register_types' ) );
		add_filter( 'map_meta_cap', array( Companion_Rehearsal_Runs::class, 'lock_runs' ), 10, 4 );
		add_action( 'admin_menu', array( Companion_Rehearsal_Admin::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( Companion_Rehearsal_Admin::class, 'enqueue' ) );
		// Priority 0 prints before the head scripts (admin 20, front end 9), so their errors are recorded too.
		add_action( 'admin_print_scripts', array( Companion_Rehearsal_Admin::class, 'print_recorder' ), 0 );
		add_action( 'wp_head', array( Companion_Rehearsal_Admin::class, 'print_recorder' ), 0 );
		add_action( 'rest_api_init', array( Companion_Rehearsal_Rest::class, 'routes' ) );
		add_action( 'template_redirect', array( Companion_Rehearsal_Share::class, 'serve_share' ), 0 );
		add_action( 'admin_post_rehearsal_seed', array( Companion_Rehearsal_Examples::class, 'seed' ) );
		add_filter( 'wp_client_side_media_processing_enabled', array( Companion_Rehearsal_Admin::class, 'no_isolation_in_frame' ), 100 );
		add_filter( 'determine_current_user', array( Companion_Rehearsal_Roles::class, 'act_as_role' ), 100 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/includes/class-companion-rehearsal-command.php';
			WP_CLI::add_command( 'rehearsal', Companion_Rehearsal_Command::class );
		}
	}
}
