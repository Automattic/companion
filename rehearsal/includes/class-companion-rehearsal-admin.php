<?php
/**
 * Rehearsal: The Tools → Rehearsal screen, its assets, and what every frame page needs.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Tools → Rehearsal screen, its assets, and what every frame page needs.
 */
final class Companion_Rehearsal_Admin {
	/**
	 * Editor pages send Document-Isolation-Policy, which stops the runner reading its frame; turn it off for that frame only.
	 *
	 * @param bool $enabled Whether client-side media processing (and with it the isolation header) is on.
	 * @return bool
	 */
	public static function no_isolation_in_frame( $enabled ) {
		$dest = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
		if ( 'iframe' === $dest && ! empty( $_COOKIE['rehearsal_frame'] ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Tools → Rehearsal.
	 */
	public static function menu() {
		add_management_page( 'Rehearsal', 'Rehearsal', 'manage_options', Companion_Rehearsal::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Load the runner on our screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'tools_page_' . Companion_Rehearsal::SLUG !== $hook ) {
			return;
		}
		$url = Companion_Rehearsal::url( 'assets/' );
		wp_enqueue_style( 'rehearsal', $url . 'runner.css', array(), Companion_Rehearsal::VERSION );
		wp_enqueue_script( 'rehearsal', $url . 'runner.js', array( 'wp-api-fetch' ), Companion_Rehearsal::VERSION, true );
		wp_add_inline_script(
			'rehearsal',
			'window.rehearsalData = ' . wp_json_encode(
				array(
					'adminUrl' => admin_url( '/' ),
					'homeUrl'  => home_url( '/' ),
					'build'    => self::build(),
					'recipes'  => Companion_Rehearsal_Store::recipes_for_js(),
					'runs'     => Companion_Rehearsal_Runs::find_runs( array() ),
					'runUsers' => Companion_Rehearsal_Runs::run_users(),
					'steps'    => Companion_Rehearsal_Schema::STEPS,
					'matchers' => Companion_Rehearsal_Schema::MATCHERS,
					'budgetKeys' => Companion_Rehearsal_Schema::BUDGET_KEYS,
					// A colleague's install link lands here as ?install=<share URL>; the modal confirms before anything is saved.
					'install'  => isset( $_GET['install'] ) ? esc_url_raw( wp_unslash( $_GET['install'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Inline, so it exists before any other script on the page runs. Inert outside the runner's frame.
	 */
	public static function print_recorder() {
		if ( ! Companion_Rehearsal_Roles::is_acting() && ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$js = file_get_contents( Companion_Rehearsal::path( 'assets/recorder.js' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( $js ) {
			wp_print_inline_script_tag( $js );
		}
	}

	/**
	 * The admin screen. The runner renders into #rh-app.
	 */
	public static function render() {
		$has_recipes = (bool) get_posts(
			array(
				'post_type'      => Companion_Rehearsal::RECIPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		?>
		<div class="wrap rh">
			<h1 class="wp-heading-inline">Rehearsal</h1>
			<hr class="wp-header-end">
			<p class="rh-build">Build under test: <code><?php echo esc_html( self::build() ); ?></code></p>
			<?php if ( ! $has_recipes ) : ?>
				<div class="rh-empty">
					<p>No rehearsals yet. A rehearsal is a list of steps (go to a page, wait for something, check it) that this page runs in your browser. Add the examples, install one from a colleague's link, or write your own.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rehearsal_seed">
						<?php wp_nonce_field( 'rehearsal_seed' ); ?>
						<?php submit_button( 'Add example rehearsals', 'secondary', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>
			<div id="rh-app"><p>Loading the runner…</p></div>
		</div>
		<?php
	}

	/**
	 * What the runs are verifying: WordPress, the active theme (and its parent), and each active plugin's
	 * version with its commit when a beta installer left one (a `.jpbeta.json` next to the plugin).
	 * Filter `rehearsal_build` to change it.
	 *
	 * @return string
	 */
	public static function build() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$theme   = wp_get_theme();
		$parent  = $theme->parent();
		$parts   = array(
			'WordPress ' . get_bloginfo( 'version' ),
			'Theme: ' . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $parent ? ' (child of ' . $parent->get( 'Name' ) . ' ' . $parent->get( 'Version' ) . ')' : '' ),
		);
		$plugins = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			if ( empty( $plugins[ $file ] ) ) {
				continue;
			}
			$part = $plugins[ $file ]['Name'] . ' ' . $plugins[ $file ]['Version'];
			$beta = WP_PLUGIN_DIR . '/' . dirname( $file ) . '/.jpbeta.json';
			if ( '.' !== dirname( $file ) && is_readable( $beta ) ) {
				$info = json_decode( (string) file_get_contents( $beta ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$part .= isset( $info->commit ) ? ' @ ' . substr( $info->commit, 0, 10 ) : '';
			}
			$parts[] = $part;
		}
		return (string) apply_filters( 'rehearsal_build', implode( ' · ', $parts ) );
	}
}
