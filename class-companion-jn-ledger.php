<?php
/**
 * The Jurassic Ninja ledger, as seen from the site.
 *
 * The ledger is WordPress.com's record of what a Jurassic Ninja site is for: a label,
 * notes, the pull requests and issues it is testing, and which Jetpack Beta builds it
 * runs. This class shows that record in the welcome notice, lets admins edit the parts a
 * site is allowed to edit, and reports the Jetpack Beta builds that are running.
 *
 * Every call goes to the `jurassic-ninja/ledger` endpoints and authenticates the same way
 * the `extend` call in companion_wp_login() does: the site's domain plus its JN_API_TOKEN,
 * both in the POST body. Nothing else from the site's persistent data is ever sent.
 *
 * WordPress.com may not have those endpoints yet. A 404, a 403 or any other failure means
 * "no ledger", and then nothing in this class changes what the site shows or does.
 */
class Companion_JN_Ledger {

	/**
	 * Base URL of the ledger endpoints. `/update` and `/report` are appended to it.
	 */
	const API_URL = 'https://public-api.wordpress.com/wpcom/v2/jurassic-ninja/ledger';

	/**
	 * Timeout, in seconds, for reading and updating the ledger.
	 */
	const TIMEOUT = 5;

	/**
	 * Timeout, in seconds, for running-state reports. These go out at shutdown, so they can
	 * hold up the end of a request or a WP-CLI command and must stay short.
	 */
	const REPORT_TIMEOUT = 3;

	/**
	 * Site transient caching the ledger record (or its absence) for the welcome notice.
	 */
	const RECORD_CACHE_KEY = 'companion_jn_ledger';

	/**
	 * Site transient holding a hash of the last report sent, so an unchanged report is not
	 * sent again within REPORT_DEDUPE_TTL.
	 */
	const REPORT_HASH_KEY = 'companion_jn_ledger_report';

	/**
	 * WP-Cron hook for the hourly report.
	 */
	const REPORT_CRON_HOOK = 'companion_jn_ledger_report';

	/**
	 * Tools page slug for the "What's this site for?" form.
	 */
	const PAGE_SLUG = 'companion-jn-ledger';

	/**
	 * admin-post.php action, and nonce action, for the form.
	 */
	const UPDATE_ACTION = 'companion_jn_ledger_update';

	/**
	 * Whether a report has already been queued for the end of this request.
	 *
	 * @var bool
	 */
	private static $report_queued = false;

	/**
	 * Hooks everything up on Jurassic Ninja sites on WP.cloud. Does nothing anywhere else.
	 */
	public static function init() {
		if ( ! self::is_available() ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::UPDATE_ACTION, array( __CLASS__, 'handle_update' ) );

		/*
		 * Priority 0 on wp_login: companion_wp_login() runs at 1 and may exit to redirect,
		 * which would skip anything hooked later. The report itself goes out at shutdown.
		 */
		add_action( 'wp_login', array( __CLASS__, 'queue_report' ), 0 );

		/*
		 * Jetpack Beta switches between stable and dev builds by rewriting the active plugins
		 * option directly, which fires neither activated_plugin nor deactivated_plugin, so the
		 * option updates are watched as well.
		 */
		foreach ( array(
			'activated_plugin',
			'deactivated_plugin',
			'upgrader_process_complete',
			'update_option_active_plugins',
			'update_site_option_active_sitewide_plugins',
			self::REPORT_CRON_HOOK,
		) as $hook ) {
			add_action( $hook, array( __CLASS__, 'queue_report' ) );
		}

		add_action( 'init', array( __CLASS__, 'schedule_report' ) );
	}

	/**
	 * Whether this site can talk to the ledger at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return defined( 'IS_ATOMIC_JN' ) && IS_ATOMIC_JN && class_exists( 'Atomic_Persistent_Data' );
	}

	/**
	 * POSTs to a ledger endpoint, with the domain and API token in the body.
	 *
	 * @param string $path   '' for the record, '/update' or '/report'.
	 * @param array  $fields Body fields besides the domain and API token.
	 * @param array  $args   Extra wp_remote_post() arguments.
	 * @return array|WP_Error `code` (int) and `body` (decoded JSON, or null) on any HTTP response.
	 */
	public static function request( $path = '', array $fields = array(), array $args = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'companion_jn_ledger_unavailable' );
		}

		$persistent_data = new Atomic_Persistent_Data();
		$api_token       = $persistent_data->JN_API_TOKEN;
		if ( ! is_string( $api_token ) || '' === $api_token ) {
			return new WP_Error( 'companion_jn_ledger_unavailable' );
		}

		$urlparts = wp_parse_url( network_site_url() );
		$response = wp_remote_post(
			self::API_URL . $path,
			array_merge(
				array( 'timeout' => self::TIMEOUT ),
				$args,
				array(
					'body' => array_merge(
						$fields,
						array(
							'domain'    => $urlparts['host'],
							'api_token' => $api_token,
						)
					),
				)
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => json_decode( wp_remote_retrieve_body( $response ), true ),
		);
	}

	/**
	 * The ledger record, from a short cache unless $fresh.
	 *
	 * The absence of a record is cached too, so a site whose wpcom has no ledger endpoints
	 * makes one request every few minutes rather than one per admin page.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array|null The record, or null when there isn't one or it can't be read.
	 */
	public static function get_record( $fresh = false ) {
		if ( ! self::is_available() ) {
			return null;
		}

		if ( ! $fresh ) {
			$cached = get_site_transient( self::RECORD_CACHE_KEY );
			if ( is_array( $cached ) && array_key_exists( 'record', $cached ) ) {
				return $cached['record'];
			}
		}

		$record   = null;
		$response = self::request();
		if ( ! is_wp_error( $response ) && 200 === $response['code'] && is_array( $response['body'] ) ) {
			$record = $response['body'];
		}
		set_site_transient( self::RECORD_CACHE_KEY, array( 'record' => $record ), 5 * MINUTE_IN_SECONDS );

		return $record;
	}

	/**
	 * Prints the ledger part of the welcome notice. Prints nothing without a record.
	 */
	public static function render_notice_section() {
		$record = self::get_record();
		if ( null === $record ) {
			return;
		}

		$label           = self::string_field( $record, 'label' );
		$notes           = self::string_field( $record, 'notes' );
		$prs             = self::prs( $record );
		$linear_issues   = self::linear_issues( $record );
		$report_card_url = self::url_field( $record, 'report_card_url' );
		$edit_url        = current_user_can( 'manage_options' ) ? admin_url( 'tools.php?page=' . self::PAGE_SLUG ) : '';

		if ( '' !== $label || '' !== $notes ) {
			?>
			<p class="jn_ledger_about">
				<?php if ( '' !== $label ) : ?>
					<strong><?php echo esc_html( $label ); ?></strong>
				<?php endif; ?>
				<?php if ( '' !== $notes ) : ?>
					<span><?php echo esc_html( self::excerpt( $notes, 30 ) ); ?></span>
				<?php endif; ?>
			</p>
			<?php
		}

		if ( $prs || $linear_issues ) {
			?>
			<p class="jn_ledger_refs">
				<?php if ( $prs ) : ?>
					<strong><?php echo esc_html__( 'PRs:', 'companion' ); ?></strong>
					<?php
					$links = array();
					foreach ( $prs as $pr ) {
						$links[] = '' === $pr['url']
							? esc_html( $pr['text'] )
							: '<a href="' . esc_url( $pr['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $pr['text'] ) . '</a>';
					}
					echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
					?>
				<?php endif; ?>
				<?php if ( $linear_issues ) : ?>
					<strong><?php echo esc_html__( 'Issues:', 'companion' ); ?></strong>
					<?php echo esc_html( implode( ', ', $linear_issues ) ); ?>
				<?php endif; ?>
			</p>
			<?php
		}

		if ( '' !== $report_card_url || '' !== $edit_url ) {
			?>
			<p class="jn_ledger_links">
				<?php if ( '' !== $report_card_url ) : ?>
					<a href="<?php echo esc_url( $report_card_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View in Mission Control', 'companion' ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== $report_card_url && '' !== $edit_url ) : ?>
					|
				<?php endif; ?>
				<?php if ( '' !== $edit_url ) : ?>
					<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( "What's this site for?", 'companion' ); ?></a>
				<?php endif; ?>
			</p>
			<?php
		}
	}

	/**
	 * Adds the "What's this site for?" page under Tools, for admins, when there is a ledger.
	 */
	public static function add_page() {
		if ( ! current_user_can( 'manage_options' ) || null === self::get_record() ) {
			return;
		}

		add_management_page(
			__( "What's this site for?", 'companion' ),
			__( "What's this site for?", 'companion' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Renders the "What's this site for?" form.
	 */
	public static function render_page() {
		$notice = get_transient( self::notice_key() );
		if ( false !== $notice ) {
			delete_transient( self::notice_key() );
		}

		// Read past the cache, so the form starts from what is on wpcom now.
		$record = self::get_record( true );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( "What's this site for?", 'companion' ); ?></h1>
			<?php if ( is_array( $notice ) && ! empty( $notice['message'] ) ) : ?>
				<div class="notice notice-<?php echo 'success' === $notice['type'] ? 'success' : 'error'; ?>">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>
			<?php
			if ( null === $record ) {
				echo '<p>' . esc_html__( "This site's ledger can't be read right now. Try again in a few minutes.", 'companion' ) . '</p></div>';
				return;
			}

			// After a failed save, show what was submitted rather than what is stored.
			$values          = is_array( $notice ) && isset( $notice['values'] ) ? $notice['values'] : array();
			$label           = isset( $values['label'] ) ? $values['label'] : self::string_field( $record, 'label' );
			$notes           = isset( $values['notes'] ) ? $values['notes'] : self::string_field( $record, 'notes' );
			$pr_url          = isset( $values['pr_url'] ) ? $values['pr_url'] : '';
			$prs             = self::prs( $record );
			$report_card_url = self::url_field( $record, 'report_card_url' );
			?>
			<p><?php echo esc_html__( 'Tell your teammates what this test site is for. This is saved to the site\'s record on WordPress.com.', 'companion' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::UPDATE_ACTION ); ?>" />
				<?php wp_nonce_field( self::UPDATE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="companion_jn_ledger_label"><?php echo esc_html__( 'Label', 'companion' ); ?></label></th>
						<td><input type="text" id="companion_jn_ledger_label" name="label" class="regular-text" value="<?php echo esc_attr( $label ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="companion_jn_ledger_notes"><?php echo esc_html__( 'Notes', 'companion' ); ?></label></th>
						<td><textarea id="companion_jn_ledger_notes" name="notes" class="large-text" rows="5"><?php echo esc_textarea( $notes ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="companion_jn_ledger_pr_url"><?php echo esc_html__( 'Add a pull request', 'companion' ); ?></label></th>
						<td>
							<?php if ( $prs ) : ?>
								<ul>
									<?php foreach ( $prs as $pr ) : ?>
										<li>
											<?php if ( '' === $pr['url'] ) : ?>
												<?php echo esc_html( $pr['text'] ); ?>
											<?php else : ?>
												<a href="<?php echo esc_url( $pr['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $pr['text'] ); ?></a>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<input type="url" id="companion_jn_ledger_pr_url" name="pr_url" class="regular-text" value="<?php echo esc_attr( $pr_url ); ?>" placeholder="https://github.com/owner/repo/pull/123" aria-describedby="companion_jn_ledger_pr_url_description" />
							<p class="description" id="companion_jn_ledger_pr_url_description"><?php echo esc_html__( 'Leave empty to keep the pull requests as they are.', 'companion' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save', 'companion' ) ); ?>
			</form>
			<?php if ( '' !== $report_card_url ) : ?>
				<p><a href="<?php echo esc_url( $report_card_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'View in Mission Control', 'companion' ); ?></a></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Saves the form to wpcom and redirects back to it with the outcome.
	 */
	public static function handle_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'companion' ), 403 );
		}
		check_admin_referer( self::UPDATE_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$fields = array(
			'label' => isset( $_POST['label'] ) ? self::plain_text( wp_unslash( $_POST['label'] ), false ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by plain_text().
			'notes' => isset( $_POST['notes'] ) ? self::plain_text( wp_unslash( $_POST['notes'] ), true ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by plain_text().
		);
		$pr_url = isset( $_POST['pr_url'] ) ? sanitize_text_field( wp_unslash( $_POST['pr_url'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( '' !== $pr_url ) {
			$fields['pr_url'] = $pr_url;
		}

		$response = self::request( '/update', $fields );
		if ( ! is_wp_error( $response ) && 200 === $response['code'] ) {
			delete_site_transient( self::RECORD_CACHE_KEY );
			$notice = array(
				'type'    => 'success',
				'message' => __( 'Saved.', 'companion' ),
			);
		} else {
			$message = __( "Couldn't save right now. Try again in a few minutes.", 'companion' );
			if ( ! is_wp_error( $response ) && 400 === $response['code'] && is_array( $response['body'] ) ) {
				$wpcom_message = self::string_field( $response['body'], 'message' );
				if ( '' !== $wpcom_message ) {
					$message = $wpcom_message;
				}
			}
			$notice = array(
				'type'    => 'error',
				'message' => $message,
				'values'  => array(
					'label'  => $fields['label'],
					'notes'  => $fields['notes'],
					'pr_url' => $pr_url,
				),
			);
		}

		set_transient( self::notice_key(), $notice, MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Schedules the hourly report, the backstop for changes no hook sees.
	 */
	public static function schedule_report() {
		if ( ! wp_next_scheduled( self::REPORT_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::REPORT_CRON_HOOK );
		}
	}

	/**
	 * Removes the hourly report.
	 */
	public static function unschedule_report() {
		wp_clear_scheduled_hook( self::REPORT_CRON_HOOK );
	}

	/**
	 * Queues one report for the end of the request, however many hooks ask for it.
	 */
	public static function queue_report() {
		if ( self::$report_queued ) {
			return;
		}
		self::$report_queued = true;
		add_action( 'shutdown', array( __CLASS__, 'send_report' ) );
	}

	/**
	 * Reports the running Jetpack Beta builds, unless the same report went out in the last
	 * five minutes. Errors are ignored: the next trigger, or the hourly event, tries again.
	 */
	public static function send_report() {
		$installed = self::get_installed();
		if ( null === $installed ) {
			return;
		}

		$json = wp_json_encode( $installed );
		$hash = md5( $json );
		if ( get_site_transient( self::REPORT_HASH_KEY ) === $hash ) {
			return;
		}
		set_site_transient( self::REPORT_HASH_KEY, $hash, 5 * MINUTE_IN_SECONDS );

		self::request(
			'/report',
			array( 'installed' => $json ),
			array(
				'blocking' => false,
				'timeout'  => self::REPORT_TIMEOUT,
			)
		);
	}

	/**
	 * The running-state report: whether Jetpack Beta is active, and every plugin it manages
	 * that is running a dev build.
	 *
	 * @return array|null Null when Jetpack Beta is active but its plugin list can't be read.
	 */
	public static function get_installed() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if (
			! class_exists( 'Automattic\JetpackBeta\Plugin' )
			|| ! defined( 'JPBETA__PLUGIN_FOLDER' )
			|| ! is_plugin_active( JPBETA__PLUGIN_FOLDER . '/jetpack-beta.php' )
		) {
			return array(
				'jetpack_beta' => false,
				'plugins'      => array(),
			);
		}

		$plugins = array();
		try {
			foreach ( \Automattic\JetpackBeta\Plugin::get_all_plugins() as $slug => $plugin ) {
				if ( ! $plugin->is_active( 'dev' ) ) {
					continue;
				}
				$info = self::read_dev_info( dirname( $plugin->dev_plugin_path() ) . '/.jpbeta.json' );
				if ( null === $info ) {
					continue;
				}
				$plugins[] = array_merge( array( 'plugin' => (string) $slug ), $info );
			}
		} catch ( Exception $e ) {
			return null;
		}

		return array(
			'jetpack_beta' => true,
			'plugins'      => $plugins,
		);
	}

	/**
	 * Reads the source, branch and version of a dev build from the `.jpbeta.json` that Jetpack
	 * Beta writes next to it.
	 *
	 * This is the file Plugin::dev_info() reads, read directly: dev_info() goes through
	 * request_filesystem_credentials(), which can print a credentials form when the
	 * filesystem method isn't direct. That has no place in cron, WP-CLI or shutdown.
	 *
	 * @param string $file Path to `.jpbeta.json`.
	 * @return array|null `source`, `branch` and `version`, or null if the file is missing or unreadable.
	 */
	private static function read_dev_info( $file ) {
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$info = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( ! is_array( $info ) ) {
			return null;
		}

		$source = self::string_field( $info, 'source' );
		if ( '' === $source ) {
			return null;
		}

		// For PR builds `id` is the branch name with slashes replaced, so prefer `branch`.
		$branch = self::string_field( $info, 'branch' );
		if ( '' === $branch ) {
			$branch = self::string_field( $info, 'id' );
		}

		return array(
			'source'  => 'master' === $source ? 'trunk' : $source,
			'branch'  => $branch,
			'version' => self::string_field( $info, 'version' ),
		);
	}

	/**
	 * The record's pull requests as link text plus a URL ('' when the URL isn't http(s)).
	 *
	 * @param array $record Ledger record.
	 * @return array[]
	 */
	private static function prs( array $record ) {
		if ( empty( $record['prs'] ) || ! is_array( $record['prs'] ) ) {
			return array();
		}

		$prs = array();
		foreach ( $record['prs'] as $pr ) {
			if ( ! is_array( $pr ) ) {
				continue;
			}
			$url    = self::url_field( $pr, 'url' );
			$owner  = self::string_field( $pr, 'owner' );
			$repo   = self::string_field( $pr, 'repo' );
			$number = isset( $pr['number'] ) ? absint( $pr['number'] ) : 0;
			$text   = '' !== $owner && '' !== $repo && $number ? "$owner/$repo#$number" : $url;
			if ( '' === $text ) {
				continue;
			}
			$prs[] = array(
				'text' => $text,
				'url'  => $url,
			);
		}
		return $prs;
	}

	/**
	 * The record's Linear issue IDs.
	 *
	 * @param array $record Ledger record.
	 * @return string[]
	 */
	private static function linear_issues( array $record ) {
		if ( empty( $record['linear_issues'] ) || ! is_array( $record['linear_issues'] ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', array_filter( $record['linear_issues'], 'is_scalar' ) ), 'strlen' ) );
	}

	/**
	 * Cleans free text from the form the same way Mission Control does, without stripping
	 * anything that looks like markup.
	 *
	 * Labels and notes are plain text and are escaped wherever they're shown, so "fix the
	 * <Card> layout" or a URL with %2F in it must survive a save. sanitize_text_field() would
	 * strip both. Only invalid UTF-8 is dropped.
	 *
	 * @param string $value     Raw value, already unslashed.
	 * @param bool   $multiline Keep line breaks (notes) or fold them into spaces (label).
	 * @return string
	 */
	private static function plain_text( $value, $multiline ) {
		$value = str_replace( array( "\r\n", "\r" ), "\n", wp_check_invalid_utf8( (string) $value ) );
		if ( ! $multiline ) {
			$value = preg_replace( '/[\n\t]+/', ' ', $value );
		}
		return trim( $value );
	}

	/**
	 * The first $words words of plain text. Unlike wp_trim_words(), this keeps anything that
	 * looks like markup, since the text is escaped when it's shown.
	 *
	 * @param string $text  Plain text.
	 * @param int    $words Word limit.
	 * @return string
	 */
	private static function excerpt( $text, $words ) {
		$all = preg_split( '/\s+/', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $all ) <= $words ) {
			return implode( ' ', $all );
		}
		return implode( ' ', array_slice( $all, 0, $words ) ) . "\u{2026}";
	}

	/**
	 * A string field from decoded JSON, or '' when it's missing or not a scalar.
	 *
	 * @param array  $data Decoded JSON.
	 * @param string $key  Field.
	 * @return string
	 */
	private static function string_field( array $data, $key ) {
		return isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
	}

	/**
	 * An http(s) URL field from decoded JSON, or '' when it's missing or anything else.
	 *
	 * @param array  $data Decoded JSON.
	 * @param string $key  Field.
	 * @return string
	 */
	private static function url_field( array $data, $key ) {
		return esc_url_raw( self::string_field( $data, $key ), array( 'http', 'https' ) );
	}

	/**
	 * Transient carrying the form's outcome to the page it redirects to, per user.
	 *
	 * @return string
	 */
	private static function notice_key() {
		return 'companion_jn_ledger_notice_' . get_current_user_id();
	}
}
