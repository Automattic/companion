<?php
/**
 * Rehearsal: repeatable, shareable step-by-step checks of a Jurassic Ninja site, run in the browser
 * from Tools → Rehearsal. See rehearsal/README.md.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recipe and run storage, the admin screen, the in-frame error recorder and the runs endpoint.
 */
final class Companion_Rehearsal {
	const VERSION = '0.16.0';
	const RECIPE  = 'rehearsal';
	const RUN     = 'rehearsal_run';
	const SLUG    = 'rehearsal';
	const STEPS   = array( 'goto', 'waitFor', 'expect', 'expectText', 'expectCount', 'fill', 'click', 'check', 'uncheck', 'selectOption', 'hover', 'press', 'expectNoConsoleErrors', 'snapshot', 'screenshot' );
	// Named after Playwright's expect(locator) matchers, so recipes carry over to a Playwright runner.
	const MATCHERS = array( 'toBeVisible', 'toHaveText', 'toContainText', 'toHaveCount', 'toBeEnabled', 'toBeDisabled', 'toBeChecked', 'toHaveValue' );
	// What a rehearsal's "budgets" may limit: times in ms, CLS unitless, weight and JS in compressed bytes.
	// Page loads are only measured for rehearsals that set a budget.
	const BUDGET_KEYS = array( 'ttfb', 'fcp', 'lcp', 'cls', 'requests', 'weight', 'js' );

	/**
	 * Keep only known page-metric fields, as numbers.
	 *
	 * @param array $page Metrics from the runner.
	 * @return array
	 */
	private static function clean_page_metrics( array $page ) {
		$clean = array( 'url' => substr( wp_strip_all_tags( (string) ( $page['url'] ?? '' ) ), 0, 300 ) );
		foreach ( array_merge( self::BUDGET_KEYS, array( 'downloaded', 'unmeasured', 'load' ) ) as $k ) {
			$clean[ $k ] = isset( $page[ $k ] ) && is_numeric( $page[ $k ] ) ? 0 + $page[ $k ] : null;
		}
		$clean['warn']   = array_values( array_intersect( (array) ( $page['warn'] ?? array() ), self::BUDGET_KEYS ) );
		$clean['limits'] = array_intersect_key( array_map( 'floatval', (array) ( $page['limits'] ?? array() ) ), array_flip( self::BUDGET_KEYS ) );
		return $clean;
	}

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_types' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'lock_runs' ), 10, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		// Priority 0 prints before the head scripts (admin 20, front end 9), so their errors are recorded too.
		add_action( 'admin_print_scripts', array( __CLASS__, 'print_recorder' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'print_recorder' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_share' ), 0 );
		add_action( 'admin_post_rehearsal_seed', array( __CLASS__, 'seed' ) );
		add_filter( 'wp_client_side_media_processing_enabled', array( __CLASS__, 'no_isolation_in_frame' ), 100 );
		add_filter( 'determine_current_user', array( __CLASS__, 'act_as_role' ), 100 );
	}

	/**
	 * The role the current request is acting as, when act_as_role() switched it.
	 *
	 * @var string
	 */
	private static $acting_as = '';

	/**
	 * While a rehearsal with a "role" runs, requests from the runner's frame act as that role's test user.
	 * Only for a real admin, and never for top-level pages or the Rehearsal page's own requests.
	 *
	 * @param int|false $user_id User from the auth cookies.
	 * @return int|false
	 */
	public static function act_as_role( $user_id ) {
		if ( ! $user_id || empty( $_COOKIE['rehearsal_role'] ) ) {
			return $user_id;
		}
		$dest    = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? sanitize_key( wp_unslash( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		// Frame page loads always switch; other requests switch unless the Rehearsal page itself made them.
		$from_frame = 'iframe' === $dest || ( 'document' !== $dest && false === strpos( $referer, 'page=' . self::SLUG ) );
		if ( ! $from_frame || ! user_can( $user_id, 'manage_options' ) ) {
			return $user_id;
		}
		$role = sanitize_key( wp_unslash( $_COOKIE['rehearsal_role'] ) );
		$test = get_user_by( 'login', 'rehearsal-' . $role );
		if ( ! $test || ! in_array( $role, (array) $test->roles, true ) ) {
			return $user_id;
		}
		self::$acting_as = $role;
		return $test->ID;
	}

	/**
	 * POST /rehearsal/v1/role-user: make sure the test user for a role exists, before a rehearsal runs as it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_role_user( $request ) {
		$role = sanitize_key( $request['role'] );
		if ( ! get_role( $role ) ) {
			return new WP_Error( 'rehearsal_no_role', "There is no \"$role\" role on this site.", array( 'status' => 400 ) );
		}
		$login = 'rehearsal-' . $role;
		$user  = get_user_by( 'login', $login );
		if ( ! $user ) {
			$id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 32, true, true ),
					'user_email'   => $login . '@example.invalid',
					'display_name' => "Rehearsal ($role)",
					'role'         => $role,
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$user = get_user_by( 'id', $id );
		} elseif ( ! in_array( $role, (array) $user->roles, true ) ) {
			$user->set_role( $role );
		}
		return rest_ensure_response(
			array(
				'id'    => $user->ID,
				'login' => $login,
				'role'  => $role,
			)
		);
	}

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
	 * Both post types are admin-only: a recipe acts as whoever runs it.
	 */
	public static function register_types() {
		$admin_caps = array_fill_keys(
			array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'create_posts' ),
			'manage_options'
		);

		// Recipes are edited in the Rehearsal dialog, so the post type has no screens of its own.
		register_post_type(
			self::RECIPE,
			array(
				'label'        => 'Rehearsals',
				'public'       => false,
				'show_ui'      => false,
				'map_meta_cap' => true,
				'capabilities' => $admin_caps,
			)
		);

		register_post_type(
			self::RUN,
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
	 * Runs are evidence: once the runner writes one, nobody edits it.
	 *
	 * @param string[] $caps    Primitive caps.
	 * @param string   $cap     Meta cap being checked.
	 * @param int      $user_id User ID.
	 * @param array    $args    Extra args; [0] is the post ID.
	 * @return string[]
	 */
	public static function lock_runs( $caps, $cap, $user_id, $args ) {
		if ( 'edit_post' === $cap && ! empty( $args[0] ) && self::RUN === get_post_type( $args[0] ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/**
	 * Tools → Rehearsal.
	 */
	public static function menu() {
		add_management_page( 'Rehearsal', 'Rehearsal', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Load the runner on our screen only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'tools_page_' . self::SLUG !== $hook ) {
			return;
		}
		$url = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_style( 'rehearsal', $url . 'runner.css', array(), self::VERSION );
		wp_enqueue_script( 'rehearsal', $url . 'runner.js', array( 'wp-api-fetch' ), self::VERSION, true );
		wp_add_inline_script(
			'rehearsal',
			'window.rehearsalData = ' . wp_json_encode(
				array(
					'adminUrl' => admin_url( '/' ),
					'homeUrl'  => home_url( '/' ),
					'build'    => self::build(),
					'recipes'  => self::recipes_for_js(),
					'runs'     => self::find_runs( array() ),
					'runUsers' => self::run_users(),
					'steps'    => self::STEPS,
					'matchers' => self::MATCHERS,
					'budgetKeys' => self::BUDGET_KEYS,
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
		if ( ! self::$acting_as && ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$js = file_get_contents( __DIR__ . '/assets/recorder.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
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
				'post_type'      => self::RECIPE,
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
	 * Check a decoded recipe against the step vocabulary.
	 *
	 * @param mixed $recipe Decoded JSON.
	 * @return string Error message, or '' when valid.
	 */
	public static function validate( $recipe ) {
		if ( ! is_array( $recipe ) || ! isset( $recipe['steps'] ) || ! is_array( $recipe['steps'] ) || ! $recipe['steps'] ) {
			return 'The JSON must be an object with a non-empty "steps" array.';
		}
		// Free-form labels a CI job matches against what changed: project names, paths or tags.
		if ( isset( $recipe['covers'] ) ) {
			foreach ( is_array( $recipe['covers'] ) ? $recipe['covers'] : array( null ) as $label ) {
				if ( ! is_string( $label ) || ! preg_match( '#^[A-Za-z0-9._/*@-]{1,100}$#', $label ) ) {
					return '"covers" must be a list of short labels without spaces, such as ["packages/forms"] or ["src/checkout/*"].';
				}
			}
		}
		if ( isset( $recipe['requires'] ) ) {
			foreach ( is_array( $recipe['requires'] ) ? $recipe['requires'] : array( null ) as $slug ) {
				if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9._-]{1,100}$/', $slug ) ) {
					return '"requires" must be a list of plugin slugs (their folder names), such as ["jetpack"].';
				}
			}
		}
		if ( isset( $recipe['role'] ) && ( ! is_string( $recipe['role'] ) || ! preg_match( '/^[a-z0-9_-]{1,60}$/', $recipe['role'] ) ) ) {
			return '"role" must be a role slug, such as "editor".';
		}
		if ( isset( $recipe['theme'] ) ) {
			$themes = is_array( $recipe['theme'] ) ? $recipe['theme'] : array( $recipe['theme'] );
			foreach ( $themes ?: array( null ) as $slug ) {
				if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9._-]{1,100}$/', $slug ) ) {
					return '"theme" must be a theme slug (its folder name), such as "twentytwentyfour", or a list of them.';
				}
			}
		}
		if ( isset( $recipe['budgets'] ) ) {
			foreach ( is_array( $recipe['budgets'] ) ? $recipe['budgets'] : array( 'x' => null ) as $key => $limit ) {
				if ( ! in_array( $key, self::BUDGET_KEYS, true ) || ! is_numeric( $limit ) || $limit <= 0 ) {
					return sprintf( '"budgets" takes positive numbers for: %s.', implode( ', ', self::BUDGET_KEYS ) );
				}
			}
		}
		if ( isset( $recipe['needs'] ) && 'playwright' !== $recipe['needs'] ) {
			return '"needs" can only be "playwright".';
		}
		foreach ( array_values( $recipe['steps'] ) as $i => $step ) {
			$actions = is_array( $step ) ? array_intersect( array_keys( $step ), self::STEPS ) : array();
			if ( 1 !== count( $actions ) ) {
				return sprintf( 'Step %d must have exactly one of: %s.', $i + 1, implode( ', ', self::STEPS ) );
			}
			if ( isset( $step['goto'] ) && ( ! is_string( $step['goto'] ) || '/' !== substr( $step['goto'], 0, 1 ) || '//' === substr( $step['goto'], 0, 2 ) ) ) {
				return sprintf( 'Step %d: "goto" must be a path on this site, starting with a single "/".', $i + 1 );
			}
			if ( isset( $step['expect'] ) && 1 !== count( array_intersect( array_keys( $step ), self::MATCHERS ) ) ) {
				return sprintf( 'Step %d: "expect" needs exactly one of: %s.', $i + 1, implode( ', ', self::MATCHERS ) );
			}
			if ( isset( $step['selectOption'] ) && ! isset( $step['value'] ) ) {
				return sprintf( 'Step %d: "selectOption" needs a "value" (the option\'s value or its label).', $i + 1 );
			}
			if ( isset( $step['press'] ) && ( ! is_string( $step['press'] ) || '' === $step['press'] ) ) {
				return sprintf( 'Step %d: "press" needs a key, such as "Enter" or "Control+A".', $i + 1 );
			}
		}
		return '';
	}

	/**
	 * Canonical JSON for a recipe.
	 *
	 * @param array $recipe Decoded recipe.
	 * @return string
	 */
	public static function canonical( array $recipe ) {
		return wp_json_encode( $recipe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Recipe ID.
	 *
	 * @param string $content Canonical recipe JSON.
	 * @return string
	 */
	public static function hash( $content ) {
		return hash( 'sha256', $content );
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

	/**
	 * Recipes plus their latest run, for the runner.
	 *
	 * @return array
	 */
	private static function recipes_for_js() {
		$posts = get_posts(
			array(
				'post_type'      => self::RECIPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return array_values( array_filter( array_map( array( __CLASS__, 'recipe_for_js' ), $posts ) ) );
	}

	/**
	 * Plugins on this site keyed by slug (folder, or file name for single-file plugins) and by text domain,
	 * so "jetpack" also matches a beta copy installed as jetpack-dev.
	 *
	 * @return array[] slug => { name, active }
	 */
	private static function plugin_index() {
		static $index = null;
		if ( null !== $index ) {
			return $index;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$index = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug   = '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file );
			$active = is_plugin_active( $file );
			foreach ( array_unique( array_filter( array( $slug, $data['TextDomain'] ) ) ) as $key ) {
				if ( empty( $index[ $key ]['active'] ) ) {
					$index[ $key ] = array(
						'name'   => $data['Name'],
						'active' => $active,
					);
				}
			}
		}
		return $index;
	}

	/**
	 * What the rehearsal needs that isn't active here: its required plugins, and its theme.
	 *
	 * @param array $recipe Decoded rehearsal.
	 * @return array[] { kind: plugin|theme, slug, name, state: inactive|missing }
	 */
	public static function missing_requirements( array $recipe ) {
		$index   = self::plugin_index();
		$missing = array();
		foreach ( (array) ( $recipe['requires'] ?? array() ) as $slug ) {
			$plugin = $index[ $slug ] ?? null;
			if ( ! $plugin || ! $plugin['active'] ) {
				$missing[] = array(
					'kind'  => 'plugin',
					'slug'  => $slug,
					'name'  => $plugin ? $plugin['name'] : $slug,
					'state' => $plugin ? 'inactive' : 'missing',
				);
			}
		}
		if ( ! empty( $recipe['role'] ) && ! get_role( $recipe['role'] ) ) {
			$missing[] = array(
				'kind'  => 'role',
				'slug'  => $recipe['role'],
				'name'  => $recipe['role'],
				'state' => 'missing',
			);
		}
		$themes = (array) ( $recipe['theme'] ?? array() );
		// A child theme of a required theme counts: the parent is still what's running.
		if ( $themes && ! array_intersect( $themes, array( get_stylesheet(), get_template() ) ) ) {
			$installed = array_values( array_filter( $themes, static fn( $slug ) => wp_get_theme( $slug )->exists() ) );
			$missing[] = array(
				'kind'  => 'theme',
				'slug'  => implode( ' or ', $themes ),
				'name'  => implode( ' or ', array_map( static fn( $slug ) => wp_get_theme( $slug )->exists() ? wp_get_theme( $slug )->get( 'Name' ) : $slug, $themes ) ),
				'state' => $installed ? 'inactive' : 'missing',
			);
		}
		return $missing;
	}

	/**
	 * One recipe as the runner sees it, or null when its stored steps are unusable.
	 *
	 * @param WP_Post $post Recipe post.
	 * @return array|null
	 */
	public static function recipe_for_js( $post ) {
		$recipe = json_decode( $post->post_content, true );
		if ( self::validate( $recipe ) ) {
			return null;
		}
		$last = get_posts(
			array(
				'post_type'      => self::RUN,
				'post_status'    => 'publish',
				'post_parent'    => $post->ID,
				'posts_per_page' => 1,
			)
		);
		return array(
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'hash'     => self::hash( $post->post_content ),
			'recipe'   => $recipe,
			'shareUrl' => self::share_url( $post->ID ),
			'source'   => (string) get_post_meta( $post->ID, '_rehearsal_source', true ),
			'missing'  => self::missing_requirements( $recipe ),
			'lastRun'  => $last ? self::run_for_js( $last[0] ) : null,
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
			'post_type'   => self::RECIPE,
			'post_status' => 'publish',
			'post_title'  => $title,
		);
		$id   = $id ? wp_update_post( $post + array( 'ID' => $id ), true ) : wp_insert_post( $post, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$wpdb->update( $wpdb->posts, array( 'post_content' => self::canonical( $recipe ) ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $id );
		if ( $source ) {
			update_post_meta( $id, '_rehearsal_source', $source );
		}
		return $id;
	}

	/**
	 * Unguessable per-recipe key, so a share link reveals that one recipe and no other.
	 *
	 * @param int $id Recipe ID.
	 * @return string
	 */
	private static function share_key( $id ) {
		return substr( hash_hmac( 'sha256', 'rehearsal-share|' . $id, wp_salt( 'auth' ) ), 0, 20 );
	}

	/**
	 * The link to give a colleague. It always serves the recipe's current version.
	 *
	 * @param int $id Recipe ID.
	 * @return string
	 */
	public static function share_url( $id ) {
		return add_query_arg( 'rehearsal', $id . '.' . self::share_key( $id ), home_url( '/' ) );
	}

	/**
	 * The portable recipe document: what CI reads and what another site installs.
	 *
	 * @param WP_Post $post Recipe post.
	 * @return array
	 */
	private static function share_payload( $post ) {
		return array(
			'format' => 'rehearsal',
			'v'      => 1,
			'title'  => get_the_title( $post ),
			'id'     => self::hash( $post->post_content ),
			'definition' => json_decode( $post->post_content, true ),
			'source' => self::share_url( $post->ID ),
			'site'   => home_url( '/' ),
			'build'  => self::build(),
		);
	}

	/**
	 * Serve ?rehearsal=<id>.<key>: a page for people, or JSON with &format=json for CI and other sites.
	 */
	public static function serve_share() {
		if ( empty( $_GET['rehearsal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		list( $id, $key ) = array_pad( explode( '.', sanitize_text_field( wp_unslash( $_GET['rehearsal'] ) ), 2 ), 2, '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post             = get_post( (int) $id );
		if ( ! $post || self::RECIPE !== $post->post_type || 'publish' !== $post->post_status || ! hash_equals( self::share_key( (int) $id ), $key ) ) {
			status_header( 404 );
			nocache_headers();
			wp_die( 'This rehearsal link is not valid. It may have been deleted, or copied incompletely.', 'Rehearsal not found', array( 'response' => 404 ) );
		}
		$payload = self::share_payload( $post );
		nocache_headers();
		$format = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'json' === $format ) {
			header( 'Access-Control-Allow-Origin: *' );
			wp_send_json( $payload );
		}
		if ( 'playwright' === $format ) {
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="' . sanitize_title( $payload['title'] ) . '.spec.ts"' );
			echo self::to_playwright( $payload ); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- Plain-text source code.
			exit;
		}
		self::render_share_page( $payload );
		exit;
	}

	/**
	 * A JS/TS string expression; {{run}} becomes the test's `run` constant.
	 *
	 * @param string $text Text from a step.
	 * @return string
	 */
	private static function pw_str( $text ) {
		$parts  = explode( '{{run}}', (string) $text );
		$pieces = array();
		foreach ( $parts as $i => $part ) {
			if ( '' !== $part ) {
				$pieces[] = wp_json_encode( $part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			}
			if ( $i < count( $parts ) - 1 ) {
				$pieces[] = 'run';
			}
		}
		return $pieces ? implode( ' + ', $pieces ) : '""';
	}

	/**
	 * A Playwright locator for a rehearsal selector. `a >> b` chains, and an iframe part becomes frameLocator().
	 *
	 * @param string $selector  Rehearsal selector.
	 * @param bool   $first_visible Narrow to the first visible match, which is what the browser runner acts on.
	 * @return string
	 */
	private static function pw_locator( $selector, $first_visible = true ) {
		$parts = preg_split( '/\s+>>\s+/', (string) $selector );
		$expr  = 'page';
		foreach ( $parts as $i => $part ) {
			if ( 0 === strpos( $part, 'text=' ) ) {
				// The browser runner's text= also matches aria-label (icon-only buttons); Playwright's doesn't.
				$aria = '[aria-label*="' . addcslashes( substr( $part, 5 ), '"\\' ) . '"]';
				$expr = "$expr.locator( " . self::pw_str( $part ) . " ).or( $expr.locator( " . self::pw_str( $aria ) . ' ) )';
				continue;
			}
			$method = $i < count( $parts ) - 1 && preg_match( '/^iframe\b/', $part ) ? 'frameLocator' : 'locator';
			$expr  .= ".$method( " . self::pw_str( $part ) . ' )';
		}
		return $first_visible ? $expr . '.filter( { visible: true } ).first()' : $expr;
	}

	/**
	 * Translate a rehearsal into a Playwright test file.
	 *
	 * @param array $payload Share payload.
	 * @return string TypeScript source.
	 */
	public static function to_playwright( array $payload ) {
		$def      = $payload['definition'];
		$timeout  = (int) ( $def['timeout'] ?? 10000 );
		$json     = static function ( $value ) {
			return wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		};
		$source   = (string) wp_json_encode( $def );
		$uses_run = false !== strpos( $source, '{{run}}' );
		$uses_err = false !== strpos( $source, '"expectNoConsoleErrors"' );
		$body     = array();
		foreach ( array_values( $def['steps'] ) as $step ) {
			$t    = empty( $step['timeout'] ) ? '' : '{ timeout: ' . (int) $step['timeout'] . ' }';
			$t2   = $t ? ", $t" : '';
			$code = '';
			if ( isset( $step['goto'] ) ) {
				$code = 'await page.goto( ' . self::pw_str( $step['goto'] ) . ' );';
			} elseif ( isset( $step['waitFor'] ) ) {
				$code = 'await expect( ' . self::pw_locator( $step['waitFor'] ) . " ).toBeVisible( $t );";
			} elseif ( isset( $step['expectText'] ) ) {
				$scope = isset( $step['in'] ) ? self::pw_locator( $step['in'] ) : "page.locator( 'body' )";
				$code  = "await expect( $scope ).toContainText( " . self::pw_str( $step['expectText'] ) . "$t2 );";
			} elseif ( isset( $step['expectCount'] ) ) {
				$code = 'await expect.poll( () => ' . self::pw_locator( $step['expectCount'], false ) . ".count()$t2 ).toBeGreaterThanOrEqual( " . (int) ( $step['min'] ?? 1 ) . ' );';
			} elseif ( isset( $step['expect'] ) ) {
				$matcher = current( array_intersect( self::MATCHERS, array_keys( $step ) ) );
				$not     = empty( $step['not'] ) ? '' : '.not';
				$arg     = is_bool( $step[ $matcher ] ) ? '' : ( is_int( $step[ $matcher ] ) ? (string) $step[ $matcher ] : self::pw_str( $step[ $matcher ] ) );
				if ( 'toBeVisible' === $matcher && $not ) {
					$code = 'await expect( ' . self::pw_locator( $step['expect'], false ) . ".filter( { visible: true } ) ).toHaveCount( 0$t2 );";
				} elseif ( 'toHaveCount' === $matcher ) {
					$code = 'await expect( ' . self::pw_locator( $step['expect'], false ) . " )$not.toHaveCount( $arg$t2 );";
				} else {
					$code = 'await expect( ' . self::pw_locator( $step['expect'] ) . " )$not.$matcher( " . ( '' === $arg ? $t : $arg . $t2 ) . ' );';
				}
			} elseif ( isset( $step['fill'] ) ) {
				$code = 'await ' . self::pw_locator( $step['fill'] ) . '.fill( ' . self::pw_str( $step['value'] ?? '' ) . "$t2 );";
			} elseif ( isset( $step['click'] ) ) {
				$code = 'await click( ' . self::pw_locator( $step['click'] ) . ( $t ? ", {$step['timeout']}" : '' ) . ' );';
			} elseif ( isset( $step['check'] ) || isset( $step['uncheck'] ) ) {
				$action = isset( $step['check'] ) ? 'check' : 'uncheck';
				$code   = 'await ' . self::pw_locator( $step[ $action ] ) . ".$action( $t );";
			} elseif ( isset( $step['selectOption'] ) ) {
				$code = 'await ' . self::pw_locator( $step['selectOption'] ) . '.selectOption( ' . self::pw_str( $step['value'] ) . "$t2 );";
			} elseif ( isset( $step['hover'] ) ) {
				$code = 'await ' . self::pw_locator( $step['hover'] ) . ".hover( $t );";
			} elseif ( isset( $step['press'] ) ) {
				$code = isset( $step['on'] )
					? 'await ' . self::pw_locator( $step['on'] ) . '.press( ' . self::pw_str( $step['press'] ) . "$t2 );"
					: 'await page.keyboard.press( ' . self::pw_str( $step['press'] ) . ' );';
			} elseif ( isset( $step['expectNoConsoleErrors'] ) ) {
				$ignore = (array) ( $step['expectNoConsoleErrors']['ignore'] ?? array() );
				$code   = $ignore
					? 'expect( consoleErrors.filter( ( e ) => ! [ ' . implode( ', ', array_map( static fn( $p ) => 'new RegExp( ' . $json( $p ) . ' )', $ignore ) ) . ' ].some( ( re ) => re.test( e ) ) ) ).toEqual( [] );'
					: 'expect( consoleErrors ).toEqual( [] );';
			} elseif ( isset( $step['snapshot'] ) ) {
				$code = 'await test.info().attach( ' . $json( 'snapshot: ' . $step['snapshot'] ) . ', { body: await ' . self::pw_locator( $step['snapshot'] ) . ".evaluate( ( el ) => el.outerHTML ), contentType: 'text/html' } );";
			} elseif ( isset( $step['screenshot'] ) ) {
				$code = 'await page.screenshot( { path: test.info().outputPath( ' . self::pw_str( $step['screenshot'] . '.png' ) . ' ), fullPage: true } );';
			}
			$code   = preg_replace( '/\(\s+\)/', '()', $code );
			$body[] = empty( $step['optional'] ) ? "\t$code" : "\tawait optional( async () => {\n\t\t$code\n\t} );";
			if ( ! empty( $def['budgets'] ) && ( isset( $step['goto'] ) || isset( $step['click'] ) || isset( $step['press'] ) ) ) {
				$body[] = "\tawait measure();";
			}
		}
		if ( ! empty( $def['budgets'] ) ) {
			$body[] = "\tawait measure();";
		}

		$head = array(
			'/**',
			' * Rehearsal: ' . str_replace( '*/', '* /', $payload['title'] ),
			' * Rehearsal ' . substr( $payload['id'], 0, 7 ) . ' from ' . $payload['site'] . ', converted to Playwright.',
			' *',
			' * Requires plugins: ' . ( empty( $def['requires'] ) ? 'none' : implode( ', ', $def['requires'] ) ) . '.',
			' * Requires theme: ' . ( empty( $def['theme'] ) ? 'any' : implode( ' or ', (array) $def['theme'] ) ) . '.',
			' * Runs as: ' . ( empty( $def['role'] ) ? 'the logged-in user' : $def['role'] . ' (uses STORAGE_STATE_' . strtoupper( str_replace( '-', '_', $def['role'] ) ) . ' when set)' ) . '.',
			' * Needs a logged-in session: set STORAGE_STATE (or the role\'s variable above) to a Playwright storage state file,',
			' * and BASE_URL to the site to test (defaults to the one it was shared from).',
			' */',
			'import { test, expect as baseExpect, type Locator' . ( empty( $def['budgets'] ) ? '' : ', type Page' ) . " } from '@playwright/test';",
			'',
			"const expect = baseExpect.configure( { timeout: $timeout } );",
			'test.use( {',
			"\tbaseURL: process.env.BASE_URL || " . $json( $payload['site'] ) . ',',
			"\tstorageState: " . ( empty( $def['role'] ) ? '' : 'process.env.STORAGE_STATE_' . strtoupper( str_replace( '-', '_', $def['role'] ) ) . ' || ' ) . 'process.env.STORAGE_STATE,',
			"\tactionTimeout: $timeout,",
			'} );',
			'',
			'// Links that open a new tab load in the same tab instead, as in the Rehearsal runner.',
			'async function click( locator: Locator, timeout?: number ) {',
			"\tconst href = await locator.evaluate( ( el ) => ( el.closest( 'a[target=\"_blank\"][href]' ) as HTMLAnchorElement | null )?.href ?? null, undefined, { timeout } );",
			"\tawait ( href ? locator.page().goto( href ) : locator.click( { timeout } ) );",
			'}',
			'',
			'async function optional( step: () => Promise< unknown > ) {',
			"\ttry {",
			"\t\tawait step();",
			"\t} catch {",
			"\t\t// Optional step: skipped when it doesn't apply.",
			"\t}",
			'}',
			'',
		);
		// Only rehearsals with their own budgets measure page loads at all.
		$tracker = array(
			'// This rehearsal\'s page-load budgets: times in ms, sizes in compressed bytes. Over budget is a notice, never a failure.',
			'const BUDGETS: Record< string, number > = ' . $json( (object) ( $def['budgets'] ?? array() ) ) . ';',
			'',
			'// Measures each page load: timings from the page; sizes from Playwright, so other sites\' files count too.',
			'async function trackLoads( page: Page ) {',
			"\t// Every page load is a first visit, so its size is the full page weight (Chromium only).",
			"\ttry {",
			"\t\tconst cdp = await page.context().newCDPSession( page );",
			"\t\tawait cdp.send( 'Network.enable' );",
			"\t\tawait cdp.send( 'Network.setCacheDisabled', { cacheDisabled: true } );",
			"\t} catch {}",
			"\tawait page.addInitScript( () => {",
			"\t\tconst v = ( ( window as any ).__rhVitals = { lcp: null as number | null, cls: 0 } );",
			"\t\ttry {",
			"\t\t\tnew PerformanceObserver( ( l ) => { const e = l.getEntries(); v.lcp = e[ e.length - 1 ].startTime; } ).observe( { type: 'largest-contentful-paint', buffered: true } );",
			"\t\t\tnew PerformanceObserver( ( l ) => l.getEntries().forEach( ( e: any ) => { if ( ! e.hadRecentInput ) v.cls += e.value; } ) ).observe( { type: 'layout-shift', buffered: true } );",
			"\t\t} catch {}",
			"\t} );",
			"\tconst loads: { url: string; requests: number; weight: number; js: number; reported: boolean }[] = [];",
			"\tpage.on( 'request', ( r ) => {",
			"\t\tif ( r.isNavigationRequest() && r.frame() === page.mainFrame() ) {",
			"\t\t\tloads.push( { url: r.url(), requests: 0, weight: 0, js: 0, reported: false } );",
			"\t\t}",
			"\t} );",
			"\tpage.on( 'requestfinished', async ( r ) => {",
			"\t\tconst load = loads[ loads.length - 1 ];",
			"\t\tconst sizes = load ? await r.sizes().catch( () => null ) : null;",
			"\t\tif ( ! load || ! sizes ) return;",
			"\t\tload.requests++;",
			"\t\tload.weight += sizes.responseBodySize;",
			"\t\tif ( r.resourceType() === 'script' ) load.js += sizes.responseBodySize;",
			"\t} );",
			"\treturn async function measure() {",
			"\t\tconst load = loads[ loads.length - 1 ];",
			"\t\tif ( ! load || load.reported ) return;",
			"\t\tload.reported = true;",
			"\t\tawait page.waitForLoadState( 'load' ).catch( () => {} );",
			"\t\tawait page.waitForTimeout( 1500 ); // Let LCP and layout shifts settle.",
			"\t\tconst t = await page.evaluate( () => {",
			"\t\t\tconst nav = performance.getEntriesByType( 'navigation' )[ 0 ] as PerformanceNavigationTiming | undefined;",
			"\t\t\tconst fcp = performance.getEntriesByName( 'first-contentful-paint' )[ 0 ];",
			"\t\t\tconst v = ( window as any ).__rhVitals || {};",
			"\t\t\treturn { ttfb: nav ? Math.round( nav.responseStart ) : null, fcp: fcp ? Math.round( fcp.startTime ) : null, lcp: v.lcp == null ? null : Math.round( v.lcp ), cls: Math.round( ( v.cls || 0 ) * 1000 ) / 1000 };",
			"\t\t} ).catch( () => null );",
			"\t\tif ( ! t ) return;",
			"\t\tconst m: Record< string, number | null > = { ...t, requests: load.requests, weight: load.weight, js: load.js };",
			"\t\tconst over = Object.keys( BUDGETS ).filter( ( k ) => m[ k ] !== null && m[ k ] !== undefined && ( m[ k ] as number ) > BUDGETS[ k ] );",
			"\t\tconst size = ( b: number ) => ( b < 1048576 ? `\${ Math.round( b / 1024 ) } KB` : `\${ ( b / 1048576 ).toFixed( 1 ) } MB` );",
			"\t\tconst line = `\${ new URL( load.url ).pathname } · TTFB \${ m.ttfb } ms · FCP \${ m.fcp } ms · LCP \${ m.lcp } ms · CLS \${ m.cls } · \${ load.requests } requests · \${ size( load.weight ) } (JS \${ size( load.js ) })`;",
			"\t\tconst note = over.length ? `\${ line } · over budget: \${ over.join( ', ' ) }` : line;",
			"\t\ttest.info().annotations.push( { type: over.length ? 'over budget' : 'page load', description: note } );",
			"\t\tconsole.log( `\${ over.length ? '⚠' : '·' } \${ note }` );",
			"\t};",
			'}',
			'',
		);
		$head = array_merge(
			$head,
			empty( $def['budgets'] ) ? array() : $tracker,
			array(
				'test( ' . $json( $payload['title'] ) . ', async ( { page } ) => {',
				"\ttest.setTimeout( " . max( 60000, count( $def['steps'] ) * $timeout + 30000 ) . ' );',
			)
		);
		if ( ! empty( $def['budgets'] ) ) {
			$head[] = "\tconst measure = await trackLoads( page );";
		}
		if ( $uses_run ) {
			$head[] = "\tconst run = Date.now().toString( 36 );";
		}
		if ( $uses_err ) {
			$head[] = "\tconst consoleErrors: string[] = [];";
			$head[] = "\t// Script errors only, like the Rehearsal runner. Failed requests are the network's, so they go in the report.";
			$head[] = "\tpage.on( 'console', ( m ) => m.type() === 'error' && ! m.text().startsWith( 'Failed to load resource' ) && consoleErrors.push( m.text() ) );";
			$head[] = "\tpage.on( 'pageerror', ( e ) => consoleErrors.push( e.message ) );";
			$head[] = "\tpage.on( 'requestfailed', ( r ) => test.info().annotations.push( { type: 'request failed', description: `\${ r.failure()?.errorText } \${ r.url() }` } ) );";
		}
		$head[] = '';
		return implode( "\n", array_merge( $head, $body, array( '} );', '' ) ) );
	}

	/**
	 * Standalone page for a share link: what the recipe does, and a box to install it on your own site.
	 *
	 * @param array $payload Share payload.
	 */
	private static function render_share_page( array $payload ) {
		$json_url = add_query_arg( 'format', 'json', $payload['source'] );
		$covers   = empty( $payload['definition']['covers'] ) ? 'Nothing: run it by hand only.' : implode( ', ', $payload['definition']['covers'] );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $payload['title'] ); ?> · Rehearsal</title>
<style>
body { margin: 0; background: #f0f0f1; color: #1d2327; font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
main { max-inline-size: 760px; margin: 40px auto; padding-inline: 16px; }
.card { background: #fff; border: 1px solid #c3c4c7; padding: 20px 24px; margin-block-end: 16px; }
h1 { font-size: 22px; margin: 0 0 4px; } .muted { color: #646970; font-size: 13px; }
dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; margin: 12px 0 0; font-size: 14px; } dt { color: #646970; } dd { margin: 0; overflow-wrap: anywhere; }
code, pre { font: 12.5px/1.5 Menlo, Consolas, monospace; } pre { background: #f6f7f7; border: 1px solid #dcdcde; padding: 12px; overflow-x: auto; max-block-size: 420px; }
form { display: flex; flex-wrap: wrap; gap: 8px; margin-block-start: 8px; } input { flex: 1 1 260px; padding: 8px 10px; font: inherit; border: 1px solid #8c8f94; border-radius: 4px; }
button { padding: 8px 16px; font: inherit; font-weight: 600; color: #fff; background: #2271b1; border: 0; border-radius: 4px; cursor: pointer; }
</style></head>
<body><main>
	<div class="card">
		<div class="muted">Rehearsal</div>
		<h1><?php echo esc_html( $payload['title'] ); ?></h1>
		<dl>
			<dt>Rehearsal ID</dt><dd><code><?php echo esc_html( substr( $payload['id'], 0, 7 ) ); ?></code></dd>
			<dt>Steps</dt><dd><?php echo (int) count( $payload['definition']['steps'] ); ?></dd>
			<dt>Runs in CI when</dt><dd><?php echo esc_html( $covers ); ?></dd>
			<dt>Requires plugins</dt><dd><?php echo esc_html( empty( $payload['definition']['requires'] ) ? 'None' : implode( ', ', $payload['definition']['requires'] ) ); ?></dd>
			<dt>Requires theme</dt><dd><?php echo esc_html( empty( $payload['definition']['theme'] ) ? 'Any' : implode( ' or ', (array) $payload['definition']['theme'] ) ); ?></dd>
			<dt>Runs as</dt><dd><?php echo esc_html( empty( $payload['definition']['role'] ) ? 'The logged-in admin' : $payload['definition']['role'] ); ?></dd>
			<dt>Shared from</dt><dd><?php echo esc_html( $payload['site'] ); ?> (<?php echo esc_html( $payload['build'] ); ?>)</dd>
			<dt>For CI</dt><dd><a href="<?php echo esc_url( $json_url ); ?>">JSON</a> · <a href="<?php echo esc_url( add_query_arg( 'format', 'playwright', $payload['source'] ) ); ?>">Playwright test</a></dd>
		</dl>
	</div>
	<div class="card">
		<h2 style="margin:0;font-size:17px">Install on your site</h2>
		<p class="muted" style="margin:4px 0 0">Your site needs Rehearsal, which comes with the Jurassic Ninja Companion plugin. You'll see the rehearsal again before anything is saved.</p>
		<form id="install">
			<input id="site" name="site" aria-label="Your site" placeholder="your-site.jurassic.ninja" required autocomplete="url">
			<button type="submit">Install</button>
		</form>
	</div>
	<div class="card"><pre><?php echo esc_html( wp_json_encode( $payload['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></div>
</main>
<script>
document.getElementById( 'install' ).addEventListener( 'submit', function ( e ) {
	e.preventDefault();
	var site = document.getElementById( 'site' ).value.trim().replace( /^https?:\/\//, '' ).replace( /\/.*$/, '' );
	window.location.href = 'https://' + site + '/wp-admin/tools.php?page=rehearsal&install=' + encodeURIComponent( <?php echo wp_json_encode( $payload['source'] ); ?> );
} );
</script>
</body></html>
		<?php
	}

	/**
	 * One page of runs, newest first, narrowed by recipe, result, who ran it, and a search.
	 *
	 * @param array $filters recipe (ID), status (pass|fail|queued), user (ID), search (title text or run number), page.
	 * @return array { runs, total, pages }
	 */
	public static function find_runs( array $filters ) {
		$args   = array(
			'post_type'      => self::RUN,
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
		if ( ! empty( $filters['status'] ) ) {
			$args['meta_key']   = '_rehearsal_status'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = $filters['status']; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
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
	private static function run_users() {
		$users = get_users(
			array(
				'has_published_posts' => array( self::RUN ),
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
	 * A run as the runner and the history table see it.
	 *
	 * @param WP_Post $run Run post.
	 * @return array
	 */
	public static function run_for_js( $run ) {
		$user = get_userdata( (int) $run->post_author );
		return array(
			'id'        => $run->ID,
			'recipeId'  => (int) $run->post_parent,
			// The run's own title ("<recipe>: <status>") outlives a permanently deleted recipe.
			'title'     => get_post( $run->post_parent ) ? get_the_title( $run->post_parent ) : preg_replace( '/: (pass|fail|queued)$/', '', $run->post_title ) . ' (rehearsal deleted)',
			'status'    => get_post_meta( $run->ID, '_rehearsal_status', true ),
			'hash'      => get_post_meta( $run->ID, '_rehearsal_recipe_hash', true ),
			'build'     => get_post_meta( $run->ID, '_rehearsal_build', true ),
			'log'       => json_decode( (string) get_post_meta( $run->ID, '_rehearsal_log', true ), true ),
			'snapshots' => count( (array) json_decode( (string) get_post_meta( $run->ID, '_rehearsal_snapshots', true ), true ) ),
			'errors'    => (array) json_decode( (string) get_post_meta( $run->ID, '_rehearsal_errors', true ), true ),
			'date'      => get_post_time( 'c', true, $run ),
			'by'        => $user ? $user->user_login : '',
			'byId'      => (int) $run->post_author,
			'role'      => (string) get_post_meta( $run->ID, '_rehearsal_role', true ),
		);
	}

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
			'/role-user',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_role_user' ),
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
				),
			)
		);
		register_rest_route(
			'rehearsal/v1',
			'/runs/search',
			array(
				'methods'             => 'GET',
				'callback'            => static function ( $request ) {
					return rest_ensure_response( self::find_runs( $request->get_params() ) );
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
				'callback'            => array( __CLASS__, 'create_run' ),
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
		if ( $id && self::RECIPE !== get_post_type( $id ) ) {
			return new WP_Error( 'rehearsal_no_recipe', 'That rehearsal does not exist.', array( 'status' => 404 ) );
		}
		$recipe = $request['recipe'];
		$error  = self::validate( $recipe );
		$title  = trim( sanitize_text_field( $request['title'] ) );
		if ( $error || '' === $title ) {
			return new WP_Error( 'rehearsal_invalid', $error ? $error : 'Give the rehearsal a title.', array( 'status' => 400 ) );
		}
		$id = self::save_recipe( $id, $title, $recipe );
		return is_wp_error( $id ) ? $id : rest_ensure_response( self::recipe_for_js( get_post( $id ) ) );
	}

	/**
	 * Move a recipe to the trash. Its runs stay, as evidence.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_delete_recipe( $request ) {
		$id = (int) $request['id'];
		if ( self::RECIPE !== get_post_type( $id ) ) {
			return new WP_Error( 'rehearsal_no_recipe', 'That rehearsal does not exist.', array( 'status' => 404 ) );
		}
		wp_trash_post( $id );
		return rest_ensure_response( array( 'deleted' => $id ) );
	}

	/**
	 * Fetch a share link's JSON, check it, and preview it (dry_run) or install it.
	 * Installing from the same link again updates that copy rather than adding a second one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_install( $request ) {
		$source = remove_query_arg( 'format', esc_url_raw( $request['url'] ) );
		if ( ! wp_http_validate_url( $source ) || 'https' !== wp_parse_url( $source, PHP_URL_SCHEME ) || false === strpos( $source, 'rehearsal=' ) ) {
			return new WP_Error( 'rehearsal_bad_link', 'That is not a rehearsal link. It should look like https://some-site.jurassic.ninja/?rehearsal=…', array( 'status' => 400 ) );
		}
		$response = wp_safe_remote_get( add_query_arg( 'format', 'json', $source ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'rehearsal_fetch_failed', 'Could not reach the site that shared this rehearsal: ' . $response->get_error_message(), array( 'status' => 502 ) );
		}
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! is_array( $payload ) || 'rehearsal' !== ( $payload['format'] ?? '' ) ) {
			return new WP_Error( 'rehearsal_not_recipe', 'The link did not return a rehearsal. It may have been deleted, or the site may have expired.', array( 'status' => 502 ) );
		}
		$recipe = $payload['definition'] ?? null;
		$error  = self::validate( $recipe );
		if ( $error ) {
			return new WP_Error( 'rehearsal_invalid', 'The shared rehearsal is not valid here: ' . $error, array( 'status' => 400 ) );
		}
		$title    = trim( sanitize_text_field( $payload['title'] ?? '' ) );
		$title    = '' === $title ? 'Shared rehearsal' : $title;
		$existing = get_posts(
			array(
				'post_type'      => self::RECIPE,
				'post_status'    => 'publish',
				'meta_key'       => '_rehearsal_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $source, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		$preview  = array(
			'title'   => $title,
			'recipe'  => $recipe,
			'id'      => self::hash( self::canonical( $recipe ) ),
			'site'    => esc_url_raw( $payload['site'] ?? '' ),
			'build'   => sanitize_text_field( $payload['build'] ?? '' ),
			'updates' => $existing ? (int) $existing[0] : 0,
			'missing' => self::missing_requirements( $recipe ),
		);
		if ( $request['dry_run'] ) {
			return rest_ensure_response( $preview );
		}
		$id = self::save_recipe( $existing ? (int) $existing[0] : 0, $title, $recipe, $source );
		return is_wp_error( $id ) ? $id : rest_ensure_response( self::recipe_for_js( get_post( $id ) ) + array( 'updated' => (bool) $existing ) );
	}

	/**
	 * Save a run, but only for the exact recipe the server holds now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_run( $request ) {
		$recipe = get_post( $request['recipe_id'] );
		if ( ! $recipe || self::RECIPE !== $recipe->post_type ) {
			return new WP_Error( 'rehearsal_no_recipe', 'That rehearsal does not exist.', array( 'status' => 404 ) );
		}
		if ( ! hash_equals( self::hash( $recipe->post_content ), (string) $request['recipe_hash'] ) ) {
			return new WP_Error( 'rehearsal_recipe_changed', 'The rehearsal changed while it was running. Reload and run it again.', array( 'status' => 409 ) );
		}
		$missing = self::missing_requirements( (array) json_decode( $recipe->post_content, true ) );
		if ( $missing ) {
			return new WP_Error( 'rehearsal_needs_plugins', 'This rehearsal needs what is not active here: ' . implode( ', ', wp_list_pluck( $missing, 'name' ) ) . '.', array( 'status' => 409 ) );
		}

		$log = array();
		foreach ( array_slice( (array) $request['log'], 0, 200 ) as $entry ) {
			$item = array(
				'ok'  => ! empty( $entry['ok'] ),
				// Not sanitize_text_field(): it strips %XX sequences, mangling the URLs these messages quote.
				'msg' => substr( wp_strip_all_tags( (string) ( $entry['msg'] ?? '' ) ), 0, 1000 ),
				'ms'  => absint( $entry['ms'] ?? 0 ),
			);
			foreach ( array_slice( (array) ( $entry['pages'] ?? array() ), 0, 10 ) as $page ) {
				$item['pages'][] = self::clean_page_metrics( (array) $page );
			}
			$log[] = $item;
		}
		// Stored as plain text and only ever shown escaped; keep it verbatim so it can be copied into a bug report.
		$errors = array();
		foreach ( array_slice( (array) $request['errors'], 0, 50 ) as $err ) {
			$errors[] = array(
				'msg'    => substr( (string) ( $err['msg'] ?? '' ), 0, 4000 ),
				'page'   => substr( (string) ( $err['page'] ?? '' ), 0, 300 ),
				'source' => substr( (string) ( $err['source'] ?? '' ), 0, 1000 ),
				'count'  => max( 1, absint( $err['count'] ?? 1 ) ),
			);
		}
		$snapshots = array();
		foreach ( array_slice( (array) $request['snapshots'], 0, 10 ) as $snap ) {
			$snapshots[] = array(
				'selector' => substr( sanitize_text_field( $snap['selector'] ?? '' ), 0, 200 ),
				'html'     => substr( (string) ( $snap['html'] ?? '' ), 0, 100000 ), // Stored as data, only ever shown escaped.
			);
		}

		$run_id = wp_insert_post(
			array(
				'post_type'   => self::RUN,
				'post_status' => 'publish',
				'post_parent' => $recipe->ID,
				'post_author' => get_current_user_id(),
				'post_title'  => sprintf( '%s: %s', $recipe->post_title, $request['status'] ),
				'meta_input'  => array(
					'_rehearsal_status'      => $request['status'],
					'_rehearsal_recipe_hash' => self::hash( $recipe->post_content ),
					'_rehearsal_recipe'      => wp_slash( $recipe->post_content ),
					'_rehearsal_build'       => self::build(),
					'_rehearsal_log'         => wp_slash( wp_json_encode( $log ) ),
					'_rehearsal_snapshots'   => wp_slash( wp_json_encode( $snapshots ) ),
					'_rehearsal_errors'      => wp_slash( wp_json_encode( $errors ) ),
					'_rehearsal_role'        => sanitize_key( (string) ( json_decode( $recipe->post_content, true )['role'] ?? '' ) ),
				),
			),
			true
		);
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}
		return rest_ensure_response( self::run_for_js( get_post( $run_id ) ) );
	}

	/**
	 * Rehearsal checking its own screen: the dialogs reject bad input, and the run filters respond.
	 * It saves nothing, so it can run any number of times.
	 *
	 * @return array Recipe.
	 */
	public static function self_test() {
		$close = array( 'click' => '#rh-modal button[data-close]' );
		$save  = array( 'click' => '#rh-modal [data-save]' );
		return array(
			'steps' => array(
				array( 'goto' => '/wp-admin/tools.php?page=' . self::SLUG ),
				array(
					'expect'      => '#rh-run',
					'toBeVisible' => true,
				),
				array(
					'expect'        => '.rh-build',
					'toContainText' => 'WordPress',
				),
				array( 'click' => '#rh-add' ),
				array(
					'expect'     => '#rh-modal-title',
					'toHaveText' => 'New rehearsal',
				),
				array(
					'fill'  => '#rh-m-title',
					'value' => 'Self-test {{run}}',
				),
				array(
					'fill'  => '#rh-m-json',
					'value' => '{ not json',
				),
				$save,
				array(
					'expect'        => '#rh-modal-error',
					'toContainText' => 'not valid JSON',
				),
				array(
					'fill'  => '#rh-m-json',
					'value' => '{"steps":[{"hover":"#a","click":"#b"}]}',
				),
				$save,
				array(
					'expect'        => '#rh-modal-error',
					'toContainText' => 'exactly one of',
				),
				$close,
				array(
					'expect'      => '#rh-modal',
					'not'         => true,
					'toBeVisible' => true,
				),
				array( 'click' => '#rh-install' ),
				array(
					'fill'  => '#rh-m-url',
					'value' => 'https://example.com/',
				),
				array( 'click' => '#rh-modal [data-preview]' ),
				array(
					'expect'        => '#rh-modal-error',
					'toContainText' => 'not a rehearsal link',
				),
				$close,
				array(
					'selectOption' => '#rh-f-status',
					'value'        => 'fail',
				),
				array(
					'expect'      => '#rh-f-clear',
					'toBeVisible' => true,
				),
				array( 'expectNoConsoleErrors' => true ),
			),
		);
	}

	/**
	 * The whole Forms journey a site owner takes, from an empty dashboard to reading a response.
	 *
	 * @return array Recipe.
	 */
	public static function forms_journey() {
		$canvas = 'iframe[name="editor-canvas"]';
		$guide  = array(
			'click'    => '[role=dialog] button[aria-label="Close"]',
			'optional' => true,
			'timeout'  => 4000,
		);
		$row    = 'tr:has-text("Ada Verify {{run}}")';
		return array(
			'requires' => array( 'jetpack' ),
			'covers'   => array( 'packages/forms' ),
			'timeout' => 20000,
			'steps'   => array(
				array( 'goto' => '/wp-admin/admin.php?page=jetpack-forms-responses-wp-admin&p=%2Fforms' ),
				array( 'click' => 'text=Create a form' ),
				array(
					'fill'  => '[role=dialog] input[placeholder="Enter form title"]',
					'value' => 'Rehearsal form {{run}}',
				),
				array( 'click' => '[role=dialog] button:has-text("Create")' ),
				array(
					'expect'      => "$canvas >> [data-type=\"jetpack/contact-form\"]",
					'toBeVisible' => true,
					'timeout'     => 40000,
				),
				$guide,
				array( 'click' => "$canvas >> text=Add a contact form to your page." ),
				array(
					'expect'      => "$canvas >> [data-type=\"jetpack/field-email\"]",
					'toBeVisible' => true,
				),
				array( 'click' => '.editor-header button:has-text("Publish")' ),
				array( 'click' => '.editor-post-publish-panel__header-publish-button button' ),
				array( 'click' => 'text=Add to a new page' ),
				array(
					'fill'  => '[role=dialog] input[placeholder="Untitled Form"]',
					'value' => 'Rehearsal page {{run}}',
				),
				array( 'click' => '[role=dialog] button:has-text("Create page")' ),
				array(
					'expect'        => '.editor-header',
					'toContainText' => 'Rehearsal page {{run}}',
					'timeout'       => 40000,
				),
				$guide,
				array(
					'expect'      => "$canvas >> [data-type=\"jetpack/field-email\"]",
					'toBeVisible' => true,
				),
				array( 'click' => '.editor-header button:has-text("Publish")' ),
				array( 'click' => '.editor-post-publish-panel__header-publish-button button' ),
				array( 'click' => '.post-publish-panel__postpublish-buttons a:has-text("View Page")' ),
				array(
					'fill'  => 'form.contact-form input[type=text]',
					'value' => 'Ada Verify {{run}}',
				),
				array(
					'fill'  => 'form.contact-form input[type=email]',
					'value' => 'ada+{{run}}@example.com',
				),
				array(
					'fill'  => 'form.contact-form textarea',
					'value' => 'Hello from Rehearsal {{run}}',
				),
				array( 'click' => 'form.contact-form button[type=submit]' ),
				array(
					'expect'        => '.contact-form-submission',
					'toContainText' => 'Thank you for your response',
				),
				array(
					'expect'        => '.contact-form-submission',
					'toContainText' => 'Ada Verify {{run}}',
				),
				// Akismet may flag test submissions; a site owner would rescue it from Spam.
				array( 'goto' => '/wp-admin/admin.php?page=jetpack-forms-responses-wp-admin&p=%2Fresponses%2Fspam' ),
				array(
					'click'    => "$row >> text=Not spam",
					'optional' => true,
					'timeout'  => 15000,
				),
				// The row disappears before the save finishes, and leaving the page would cancel it.
				array(
					'expect'      => 'text=Response marked as not spam.',
					'toBeVisible' => true,
					'optional'    => true,
					'timeout'     => 10000,
				),
				array(
					'expect'      => $row,
					'not'         => true,
					'toBeVisible' => true,
				),
				array( 'goto' => '/wp-admin/admin.php?page=jetpack-forms-responses-wp-admin&p=%2Fresponses%2Finbox' ),
				array(
					'expect'      => $row,
					'toBeVisible' => true,
					'timeout'     => 30000,
				),
				array( 'click' => 'text=Ada Verify {{run}}' ),
				array(
					'expect'      => 'text=Hello from Rehearsal {{run}}',
					'toBeVisible' => true,
				),
				array( 'expectNoConsoleErrors' => true ),
			),
		);
	}

	/**
	 * Add starter recipes that work on any site, plus Jetpack ones when Jetpack is active.
	 */
	public static function seed() {
		check_admin_referer( 'rehearsal_seed' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You are not allowed to add rehearsals.' );
		}
		$recipes = array(
			'Dashboard loads without console errors' => array(
				'steps' => array(
					array( 'goto' => '/wp-admin/index.php' ),
					array( 'waitFor' => '#dashboard-widgets' ),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'General settings: tagline saves (changes the tagline)' => array(
				'steps' => array(
					array( 'goto' => '/wp-admin/options-general.php' ),
					array(
						'fill'  => '#blogdescription',
						'value' => 'Checked by Rehearsal',
					),
					array( 'click' => '#submit' ),
					array( 'expectText' => 'Settings saved.' ),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'Discussion settings: checkbox, select and Enter (changes settings)' => array(
				'steps' => array(
					array( 'goto' => '/wp-admin/options-discussion.php' ),
					array(
						'expect'     => 'h1',
						'toHaveText' => 'Discussion Settings',
					),
					array(
						'expect'      => '#submit',
						'toBeEnabled' => true,
					),
					array( 'check' => '#thread_comments' ),
					array(
						'expect'      => '#thread_comments',
						'toBeChecked' => true,
					),
					array(
						'selectOption' => '#thread_comments_depth',
						'value'        => '3',
					),
					array(
						'press' => 'Enter',
						'on'    => '#comments_per_page',
					),
					array(
						'expect'        => '#setting-error-settings_updated',
						'toContainText' => 'Settings saved',
					),
					array(
						'expect'      => '#thread_comments_depth',
						'toHaveValue' => '3',
					),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'Front page renders'                     => array(
				'steps' => array(
					array( 'goto' => '/' ),
					array( 'waitFor' => 'body' ),
					array( 'snapshot' => 'body' ),
					array( 'expectNoConsoleErrors' => true ),
				),
			),
			'Front page screenshot'                  => array(
				'needs' => 'playwright',
				'steps' => array(
					array( 'goto' => '/' ),
					array( 'screenshot' => 'front-page' ),
				),
			),
		);
		$recipes['Rehearsal: its own screen works'] = self::self_test();
		if ( defined( 'JETPACK__VERSION' ) ) {
			$recipes['My Jetpack renders'] = array(
				'requires' => array( 'jetpack' ),
				'covers'   => array( 'packages/my-jetpack' ),
				'steps'  => array(
					array( 'goto' => '/wp-admin/admin.php?page=my-jetpack' ),
					array(
						'expect'      => '#wpbody-content [role=tab]:has-text("Overview")',
						'toBeVisible' => true,
					),
					array( 'expectNoConsoleErrors' => true ),
				),
			);
		}
		if ( post_type_exists( 'jetpack_form' ) ) {
			$recipes['Forms: create a form, add it to a page, submit it, read the response (creates content)'] = self::forms_journey();
		}
		foreach ( $recipes as $title => $recipe ) {
			self::save_recipe( 0, $title, $recipe );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::SLUG ) );
		exit;
	}
}

