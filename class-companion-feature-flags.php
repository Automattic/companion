<?php
/**
 * Per-site overrides for Jetpack feature flags.
 *
 * Jetpack's automattic/jetpack-feature-flags package deliberately stores no state:
 * packages register a flag along with a default, and every read resolves through the
 * `jetpack_feature_flag_enabled` filter. This class is the storage layer that package
 * leaves out — it keeps overrides in an option, applies them through that filter, and
 * renders a settings section to edit them.
 *
 * Only flags that have been explicitly overridden are stored. A flag missing from the
 * option is not pinned, so it keeps following whatever default the code ships with.
 */

/**
 * Stores and edits Jetpack feature flag overrides.
 *
 * Everything the class touches — the option, the settings group, the page, the section —
 * is constructor configurable, so it can be dropped into another plugin that has its own
 * settings page without editing the class:
 *
 *     Companion_Feature_Flags::init(
 *         array(
 *             'option'         => 'my_plugin_feature_flags',
 *             'settings_group' => 'my_plugin',
 *             'settings_page'  => 'my_plugin_settings',
 *         )
 *     );
 */
class Companion_Feature_Flags {

	/**
	 * Fully-qualified name of the Jetpack package class this wraps.
	 */
	const PACKAGE_CLASS = 'Automattic\Jetpack\Feature_Flags\Feature_Flags';

	/**
	 * Flag names are documented as /^[a-z0-9][a-z0-9_-]*$/. The package enforces this at
	 * lint time rather than runtime, so we check it ourselves before storing anything.
	 */
	const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

	/**
	 * Option holding the sparse override map.
	 *
	 * @var string
	 */
	private $option;

	/**
	 * Settings group the option is registered into. Registering into the group an existing
	 * page already uses means that page's own Save button persists these overrides too.
	 *
	 * @var string
	 */
	private $settings_group;

	/**
	 * Slug of the settings page the section is added to.
	 *
	 * @var string
	 */
	private $settings_page;

	/**
	 * Settings section id.
	 *
	 * @var string
	 */
	private $section_id;

	/**
	 * Settings section heading. Empty falls back to a translated default.
	 *
	 * @var string
	 */
	private $section_title;

	/**
	 * Most recently booted instance, so callers without a reference (WP-CLI) can find one.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @param array $args {
	 *     Optional. Configuration overrides.
	 *
	 *     @type string $option         Option name. Default 'jetpack_feature_flags'.
	 *     @type string $settings_group Settings group. Default 'companion'.
	 *     @type string $settings_page  Settings page slug. Default 'companion_settings'.
	 *     @type string $section_id     Settings section id. Default 'companion_feature_flags'.
	 *     @type string $section_title  Section heading. Default 'Jetpack Feature Flags'.
	 * }
	 */
	public function __construct( array $args = array() ) {
		$args = array_merge(
			array(
				'option'         => 'jetpack_feature_flags',
				'settings_group' => 'companion',
				'settings_page'  => 'companion_settings',
				'section_id'     => 'companion_feature_flags',
				'section_title'  => '',
			),
			$args
		);

		$this->option         = $args['option'];
		$this->settings_group = $args['settings_group'];
		$this->settings_page  = $args['settings_page'];
		$this->section_id     = $args['section_id'];
		$this->section_title  = $args['section_title'];
	}

	/**
	 * Builds an instance, hooks it up, and remembers it for self::instance().
	 *
	 * @param array $args Configuration overrides. See the constructor.
	 *
	 * @return self
	 */
	public static function init( array $args = array() ) {
		$instance = new self( $args );
		$instance->register_hooks();

		self::$instance = $instance;

		return $instance;
	}

	/**
	 * Returns the booted instance, constructing a default one if nothing booted yet.
	 *
	 * For callers that cannot be handed a reference — chiefly the WP-CLI command, which
	 * WP-CLI instantiates itself.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers the filter and the settings section.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'jetpack_feature_flag_enabled', array( $this, 'apply_override' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_section' ), 20 );
	}

	/**
	 * Name of the option overrides are stored in.
	 *
	 * @return string
	 */
	public function get_option_name() {
		return $this->option;
	}

	/* ==========================================================================
	   Flag state
	   ========================================================================== */

	/**
	 * Filter: jetpack_feature_flag_enabled
	 *
	 * Applies the stored override, if there is one, on top of the registered default.
	 *
	 * This hooks the generic filter at the default priority, which runs before the per-flag
	 * `jetpack_feature_flag_enabled_{$name}` filter. A deliberate code-level pin therefore
	 * still wins — we override the default, not someone else's explicit decision.
	 *
	 * @param bool   $enabled Whether the flag is enabled.
	 * @param string $name    Feature flag name.
	 *
	 * @return bool
	 */
	public function apply_override( $enabled, $name ) {
		$overrides = $this->get_overrides();

		return array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $enabled;
	}

	/**
	 * Returns the stored overrides, keyed by flag name.
	 *
	 * @return array<string, bool> Only the flags that have been explicitly overridden.
	 */
	public function get_overrides() {
		$overrides = get_option( $this->option, array() );

		if ( ! is_array( $overrides ) ) {
			return array();
		}

		$clean = array();
		foreach ( $overrides as $name => $enabled ) {
			if ( self::is_valid_name( $name ) ) {
				$clean[ $name ] = (bool) $enabled;
			}
		}

		return $clean;
	}

	/**
	 * Stores the overrides.
	 *
	 * @param array<string, bool> $overrides Overrides keyed by flag name.
	 *
	 * @return void
	 */
	public function update_overrides( array $overrides ) {
		update_option( $this->option, $overrides );
	}

	/**
	 * Returns every flag the Feature_Flags package knows about on this request.
	 *
	 * Flags are registered at plugin/package bootstrap, so this is the full set as long as
	 * the registering code has loaded. Returns nothing when the package isn't present.
	 *
	 * @return array<string, array> Flag definitions keyed by name.
	 */
	public static function get_registered_flags() {
		if ( ! self::has_package() ) {
			return array();
		}

		return call_user_func( array( self::PACKAGE_CLASS, 'all' ) );
	}

	/**
	 * Whether the Jetpack feature flags package is available on this site.
	 *
	 * @return bool
	 */
	public static function has_package() {
		return class_exists( self::PACKAGE_CLASS );
	}

	/**
	 * Whether a string is a usable feature flag name.
	 *
	 * @param string $name Candidate flag name.
	 *
	 * @return bool
	 */
	public static function is_valid_name( $name ) {
		return is_string( $name ) && 1 === preg_match( self::NAME_PATTERN, $name );
	}

	/**
	 * Resolves a flag through the package, so the reported state includes every filter and
	 * not just our own override.
	 *
	 * @param string $name Flag name.
	 *
	 * @return bool
	 */
	public static function is_enabled( $name ) {
		if ( ! self::has_package() ) {
			return false;
		}

		return (bool) call_user_func( array( self::PACKAGE_CLASS, 'is_enabled' ), $name );
	}

	/* ==========================================================================
	   Settings section
	   ========================================================================== */

	/**
	 * Action: admin_init
	 *
	 * Adds the feature flags section to the configured settings page. Hooked late so it
	 * registers after any sections the page builds itself and therefore renders last, and
	 * registers the option into the page's own settings group so its Save button persists it.
	 *
	 * @return void
	 */
	public function register_section() {
		if ( ! self::has_package() ) {
			return;
		}

		register_setting(
			$this->settings_group,
			$this->option,
			array(
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section(
			$this->section_id,
			'' !== $this->section_title ? $this->section_title : __( 'Jetpack Feature Flags', 'companion' ),
			array( $this, 'render_section' ),
			$this->settings_page
		);
	}

	/**
	 * Sanitize callback for the option.
	 *
	 * Turns the posted radio values into a sparse override map. `default` drops the flag
	 * from the map entirely so it goes back to following its registered default.
	 *
	 * A non-array means nothing was submitted for this option, which is what options.php
	 * hands us when a registered option is missing from the request. Keeping the stored
	 * value in that case stops an unrelated save from wiping every override.
	 *
	 * @param mixed $input Raw posted value.
	 *
	 * @return array<string, bool>
	 */
	public function sanitize( $input ) {
		if ( ! is_array( $input ) ) {
			return $this->get_overrides();
		}

		$overrides = array();
		foreach ( $input as $name => $state ) {
			if ( ! self::is_valid_name( $name ) ) {
				continue;
			}

			if ( 'on' === $state ) {
				$overrides[ $name ] = true;
			} elseif ( 'off' === $state ) {
				$overrides[ $name ] = false;
			}
		}

		return $overrides;
	}

	/**
	 * Renders the feature flags section.
	 *
	 * @return void
	 */
	public function render_section() {
		$flags     = self::get_registered_flags();
		$overrides = $this->get_overrides();
		$orphaned  = array_diff_key( $overrides, $flags );

		echo '<p>' . esc_html__( 'Override the default state of each Jetpack feature flag on this site. Leaving a flag on "Default" keeps it following whatever the code ships with.', 'companion' ) . '</p>';

		if ( empty( $flags ) && empty( $orphaned ) ) {
			echo '<p>' . esc_html__( 'No feature flags are currently registered.', 'companion' ) . '</p>';
			return;
		}

		?>
		<style>
			.companion-feature-flags { width: 100%; }
			/* Keep the three radios on one line and let Description absorb the slack. */
			.companion-feature-flags .companion-ff-state { white-space: nowrap; width: 1%; }
			.companion-feature-flags .companion-ff-state label { margin-right: 1em; }
			/* 782px is WordPress's own admin breakpoint for narrow screens. */
			@media screen and ( max-width: 782px ) {
				.companion-feature-flags .companion-ff-description { display: none; }
			}
		</style>
		<table class="widefat striped companion-feature-flags">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Flag', 'companion' ); ?></th>
					<th scope="col" class="companion-ff-description"><?php esc_html_e( 'Description', 'companion' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Currently', 'companion' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'companion' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $flags as $name => $definition ) {
					$this->render_row( $name, $definition, $overrides );
				}

				if ( ! empty( $orphaned ) ) {
					?>
					<tr>
						<th colspan="4" scope="colgroup">
							<?php esc_html_e( 'Not registered', 'companion' ); ?>
							<span style="font-weight: normal;">
								&mdash; <?php esc_html_e( 'left over from a flag that no longer exists, or set before its code landed.', 'companion' ); ?>
							</span>
						</th>
					</tr>
					<?php
					foreach ( array_keys( $orphaned ) as $name ) {
						$this->render_row( $name, null, $overrides );
					}
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders one flag row: name, description, resolved state, and the override radios.
	 *
	 * @param string     $name       Flag name.
	 * @param array|null $definition Registered definition, or null when the flag is not registered.
	 * @param array      $overrides  Stored overrides keyed by flag name.
	 *
	 * @return void
	 */
	private function render_row( $name, $definition, array $overrides ) {
		$is_registered = is_array( $definition );

		if ( array_key_exists( $name, $overrides ) ) {
			$state = $overrides[ $name ] ? 'on' : 'off';
		} else {
			$state = 'default';
		}

		// An unregistered flag has no default to fall back to, so "default" simply clears it.
		if ( $is_registered ) {
			$default_label = $definition['default']
				? __( 'Default (on)', 'companion' )
				: __( 'Default (off)', 'companion' );
		} else {
			$default_label = __( 'Clear', 'companion' );
		}

		$choices = array(
			'default' => $default_label,
			'on'      => __( 'On', 'companion' ),
			'off'     => __( 'Off', 'companion' ),
		);

		?>
		<tr>
			<td><code><?php echo esc_html( $name ); ?></code></td>
			<td class="companion-ff-description">
				<?php
				if ( $is_registered && '' !== $definition['description'] ) {
					echo esc_html( $definition['description'] );
				}
				if ( $is_registered && '' !== $definition['owner'] ) {
					echo ' <em>' . esc_html( sprintf( __( 'Owner: %s', 'companion' ), $definition['owner'] ) ) . '</em>';
				}
				?>
			</td>
			<td>
				<?php
				// Resolved through the package, so this reflects any other filters too and
				// will disagree with the chosen state if something pins the flag in code.
				echo self::is_enabled( $name )
					? esc_html__( 'On', 'companion' )
					: esc_html__( 'Off', 'companion' );
				?>
			</td>
			<td class="companion-ff-state">
				<?php foreach ( $choices as $value => $label ) : ?>
					<label>
						<input
							type="radio"
							name="<?php echo esc_attr( $this->option ); ?>[<?php echo esc_attr( $name ); ?>]"
							value="<?php echo esc_attr( $value ); ?>"
							<?php checked( $state, $value ); ?>
						/>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</td>
		</tr>
		<?php
	}
}
