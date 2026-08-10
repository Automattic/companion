<?php
/**
 * Per-site overrides for Jetpack feature flags.
 *
 * Jetpack's automattic/jetpack-feature-flags package deliberately stores no state:
 * packages register a flag along with a default, and every read resolves through the
 * `jetpack_feature_flag_enabled` filter. This file is the storage layer that package
 * leaves out — it keeps overrides in the `jetpack_feature_flags` option, applies them
 * through that filter, and renders a section on the companion settings page to edit them.
 *
 * Only flags that have been explicitly overridden are stored. A flag missing from the
 * option is not pinned, so it keeps following whatever default the code ships with.
 */

const COMPANION_FEATURE_FLAGS_OPTION = 'jetpack_feature_flags';

const COMPANION_FEATURE_FLAGS_CLASS = 'Automattic\Jetpack\Feature_Flags\Feature_Flags';

/**
 * Flag names are documented as /^[a-z0-9][a-z0-9_-]*$/. The package enforces this at
 * lint time rather than runtime, so we check it ourselves before storing anything.
 */
const COMPANION_FEATURE_FLAG_NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

add_filter( 'jetpack_feature_flag_enabled', 'companion_feature_flag_override', 10, 2 );
add_action( 'admin_init', 'companion_register_feature_flags_section', 20 );

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
function companion_feature_flag_override( $enabled, $name ) {
	$overrides = companion_get_feature_flag_overrides();

	return array_key_exists( $name, $overrides ) ? $overrides[ $name ] : $enabled;
}

/**
 * Returns the stored overrides, keyed by flag name.
 *
 * @return array<string, bool> Only the flags that have been explicitly overridden.
 */
function companion_get_feature_flag_overrides() {
	$overrides = get_option( COMPANION_FEATURE_FLAGS_OPTION, array() );

	if ( ! is_array( $overrides ) ) {
		return array();
	}

	$clean = array();
	foreach ( $overrides as $name => $enabled ) {
		if ( companion_is_valid_feature_flag_name( $name ) ) {
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
function companion_update_feature_flag_overrides( array $overrides ) {
	update_option( COMPANION_FEATURE_FLAGS_OPTION, $overrides );
}

/**
 * Returns every flag the Feature_Flags package knows about on this request.
 *
 * Flags are registered at plugin/package bootstrap, so this is the full set as long as
 * the registering code has loaded. Returns nothing when the package isn't present.
 *
 * @return array<string, array> Flag definitions keyed by name.
 */
function companion_get_registered_feature_flags() {
	if ( ! companion_has_feature_flags_package() ) {
		return array();
	}

	return call_user_func( array( COMPANION_FEATURE_FLAGS_CLASS, 'all' ) );
}

/**
 * Whether the Jetpack feature flags package is available on this site.
 *
 * @return bool
 */
function companion_has_feature_flags_package() {
	return class_exists( COMPANION_FEATURE_FLAGS_CLASS );
}

/**
 * Whether a string is a usable feature flag name.
 *
 * @param string $name Candidate flag name.
 *
 * @return bool
 */
function companion_is_valid_feature_flag_name( $name ) {
	return is_string( $name ) && 1 === preg_match( COMPANION_FEATURE_FLAG_NAME_PATTERN, $name );
}

/**
 * Resolves a flag through the package, so the reported state includes every filter and
 * not just our own override.
 *
 * @param string $name Flag name.
 *
 * @return bool
 */
function companion_is_feature_flag_enabled( $name ) {
	if ( ! companion_has_feature_flags_package() ) {
		return false;
	}

	return (bool) call_user_func( array( COMPANION_FEATURE_FLAGS_CLASS, 'is_enabled' ), $name );
}

/**
 * Action: admin_init
 *
 * Adds the feature flags section to the existing companion settings page. Runs after
 * RationalOptionPages has registered its own sections so this one renders last, and
 * registers the option into the same `companion` settings group so the page's single
 * Save button persists it.
 *
 * @return void
 */
function companion_register_feature_flags_section() {
	if ( ! companion_has_feature_flags_package() ) {
		return;
	}

	register_setting(
		'companion',
		COMPANION_FEATURE_FLAGS_OPTION,
		array(
			'sanitize_callback' => 'companion_sanitize_feature_flag_overrides',
		)
	);

	add_settings_section(
		'companion_feature_flags',
		__( 'Jetpack Feature Flags', 'companion' ),
		'companion_render_feature_flags_section',
		'companion_settings'
	);
}

/**
 * Sanitize callback for the `jetpack_feature_flags` option.
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
function companion_sanitize_feature_flag_overrides( $input ) {
	if ( ! is_array( $input ) ) {
		return companion_get_feature_flag_overrides();
	}

	$overrides = array();
	foreach ( $input as $name => $state ) {
		if ( ! companion_is_valid_feature_flag_name( $name ) ) {
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
 * Renders the feature flags section on the companion settings page.
 *
 * @return void
 */
function companion_render_feature_flags_section() {
	$flags     = companion_get_registered_feature_flags();
	$overrides = companion_get_feature_flag_overrides();
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
				companion_render_feature_flag_row( $name, $definition, $overrides );
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
					companion_render_feature_flag_row( $name, null, $overrides );
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
function companion_render_feature_flag_row( $name, $definition, array $overrides ) {
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
			echo companion_is_feature_flag_enabled( $name )
				? esc_html__( 'On', 'companion' )
				: esc_html__( 'Off', 'companion' );
			?>
		</td>
		<td class="companion-ff-state">
			<?php foreach ( $choices as $value => $label ) : ?>
				<label>
					<input
						type="radio"
						name="<?php echo esc_attr( COMPANION_FEATURE_FLAGS_OPTION ); ?>[<?php echo esc_attr( $name ); ?>]"
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
