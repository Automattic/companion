<?php
/**
 * Rehearsal: Validates rehearsals and gives them a stable ID.
 *
 * @package companion
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validates rehearsals and gives them a stable ID.
 */
final class Companion_Rehearsal_Schema {
	const STEPS   = array( 'goto', 'waitFor', 'expect', 'expectText', 'expectCount', 'fill', 'click', 'check', 'uncheck', 'selectOption', 'hover', 'press', 'expectNoConsoleErrors', 'snapshot', 'screenshot', 'dialog' );
	// Named after Playwright's expect(locator) matchers, so recipes carry over to a Playwright runner.
	const MATCHERS = array( 'toBeVisible', 'toHaveText', 'toContainText', 'toHaveCount', 'toBeEnabled', 'toBeDisabled', 'toBeChecked', 'toHaveValue' );
	// How the next alert, confirm or prompt is answered. Without a "dialog" step it is dismissed, as Playwright does.
	const DIALOG_ACTIONS = array( 'accept', 'dismiss' );
	// Screen sizes a rehearsal can be pinned to, when its steps only work at one of them.
	const VIEWPORTS = array( 'desktop', 'mobile' );
	// One plugin slug, or several joined with " or " when any one of them will do.
	const REQUIRES_PATTERN = '/^[a-z0-9._-]{1,100}( or [a-z0-9._-]{1,100})*$/';
	// What a rehearsal's "budgets" may limit: times in ms, CLS unitless, weight and JS in compressed bytes.
	// Page loads are only measured for rehearsals that set a budget.
	const BUDGET_KEYS = array( 'ttfb', 'fcp', 'lcp', 'cls', 'requests', 'weight', 'js' );

	// Step keys that take a selector.
	const SELECTOR_KEYS = array( 'waitFor', 'expect', 'expectCount', 'fill', 'click', 'check', 'uncheck', 'selectOption', 'hover', 'snapshot', 'in', 'on' );

	/**
	 * Check a decoded recipe against the step vocabulary.
	 *
	 * @param mixed $recipe Decoded JSON.
	 * @return string Error message, or '' when valid.
	 */
	public static function validate( $recipe ) {
		if ( ! is_array( $recipe ) || ! isset( $recipe['steps'] ) || ! self::is_list( $recipe['steps'] ) || ! $recipe['steps'] ) {
			return 'The JSON must be an object with a non-empty "steps" array.';
		}
		// Free-form labels a CI job matches against what changed: project names, paths or tags.
		if ( array_key_exists( 'covers', $recipe ) && ! self::is_list_of( $recipe['covers'], '#^[A-Za-z0-9._/*@-]{1,100}$#' ) ) {
			return '"covers" must be a list of short labels without spaces, such as ["packages/forms"] or ["src/checkout/*"].';
		}
		if ( array_key_exists( 'requires', $recipe ) && ! self::is_list_of( $recipe['requires'], self::REQUIRES_PATTERN ) ) {
			return '"requires" must be a list of plugin slugs (their folder names), such as ["jetpack"], or ["jetpack or jetpack-premium-analytics"] when any one will do.';
		}
		if ( array_key_exists( 'role', $recipe ) && ( ! is_string( $recipe['role'] ) || ! preg_match( '/^[a-z0-9_-]{1,60}$/', $recipe['role'] ) ) ) {
			return '"role" must be a role slug, such as "editor".';
		}
		if ( array_key_exists( 'theme', $recipe ) && ! self::is_list_of( is_string( $recipe['theme'] ) ? array( $recipe['theme'] ) : $recipe['theme'], '/^[a-z0-9._-]{1,100}$/' ) ) {
			return '"theme" must be a theme slug (its folder name), such as "twentytwentyfour", or a list of them.';
		}
		if ( array_key_exists( 'budgets', $recipe ) ) {
			foreach ( is_array( $recipe['budgets'] ) && $recipe['budgets'] ? $recipe['budgets'] : array( 'x' => null ) as $key => $limit ) {
				if ( ! in_array( $key, self::BUDGET_KEYS, true ) || ! is_numeric( $limit ) || $limit <= 0 ) {
					return sprintf( '"budgets" takes positive numbers for: %s.', implode( ', ', self::BUDGET_KEYS ) );
				}
			}
		}
		if ( array_key_exists( 'format', $recipe ) && ( ! is_int( $recipe['format'] ) || $recipe['format'] < 1 ) ) {
			return '"format" must be a whole number from 1 up.';
		}
		if ( array_key_exists( 'viewport', $recipe ) && ! in_array( $recipe['viewport'], self::VIEWPORTS, true ) ) {
			return '"viewport" must be "desktop" or "mobile".';
		}
		if ( array_key_exists( 'needs', $recipe ) && 'playwright' !== $recipe['needs'] ) {
			return '"needs" can only be "playwright".';
		}
		if ( array_key_exists( 'timeout', $recipe ) && ! self::is_timeout( $recipe['timeout'] ) ) {
			return '"timeout" must be a whole number of milliseconds, such as 10000.';
		}
		foreach ( $recipe['steps'] as $i => $step ) {
			$error = self::step_error( $step );
			if ( $error ) {
				return sprintf( 'Step %d%s', $i + 1, $error );
			}
		}
		return '';
	}

	/**
	 * What is wrong with one step.
	 *
	 * @param mixed $step Step.
	 * @return string Error, starting with ":" or " must", or '' when valid.
	 */
	private static function step_error( $step ) {
		$actions = is_array( $step ) && ! self::is_list( $step ) ? array_intersect( array_keys( $step ), self::STEPS ) : array();
		if ( 1 !== count( $actions ) ) {
			return ' must have exactly one of: ' . implode( ', ', self::STEPS ) . '.';
		}
		if ( array_key_exists( 'goto', $step ) ) {
			// No backslash or control character: browsers read "/\x" and "/<tab>/x" as "//x", another site.
			if ( ! is_string( $step['goto'] ) || ! preg_match( '#^/(?!/)[^\\\\\x00-\x1f]*$#', $step['goto'] ) ) {
				return ': "goto" must be a path on this site, starting with a single "/".';
			}
		}
		foreach ( self::SELECTOR_KEYS as $key ) {
			if ( array_key_exists( $key, $step ) && ( ! is_string( $step[ $key ] ) || '' === trim( $step[ $key ] ) ) ) {
				return sprintf( ': "%s" must be a selector, such as "#submit" or "text=Save".', $key );
			}
		}
		if ( array_key_exists( 'expectText', $step ) && ( ! is_string( $step['expectText'] ) || '' === $step['expectText'] ) ) {
			return ': "expectText" must be the text to find.';
		}
		if ( array_key_exists( 'screenshot', $step ) && ( ! is_string( $step['screenshot'] ) || ! preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $step['screenshot'] ) || '.' === $step['screenshot'][0] ) ) {
			return ': "screenshot" must be a file name without a folder, such as "checkout".';
		}
		if ( array_key_exists( 'dialog', $step ) && ! in_array( $step['dialog'], self::DIALOG_ACTIONS, true ) ) {
			return ': "dialog" must be "accept" or "dismiss".';
		}
		if ( array_key_exists( 'press', $step ) && ( ! is_string( $step['press'] ) || '' === $step['press'] ) ) {
			return ': "press" needs a key, such as "Enter" or "Control+A".';
		}
		if ( isset( $step['selectOption'] ) && ! isset( $step['value'] ) ) {
			return ': "selectOption" needs a "value" (the option\'s value or its label).';
		}
		if ( array_key_exists( 'value', $step ) && ! is_string( $step['value'] ) && ! is_int( $step['value'] ) && ! is_float( $step['value'] ) ) {
			return ': "value" must be text or a number.';
		}
		if ( array_key_exists( 'timeout', $step ) && ! self::is_timeout( $step['timeout'] ) ) {
			return ': "timeout" must be a whole number of milliseconds, such as 5000.';
		}
		if ( array_key_exists( 'min', $step ) && ( ! is_int( $step['min'] ) || $step['min'] < 0 ) ) {
			return ': "min" must be a whole number from 0 up.';
		}
		foreach ( array( 'optional', 'not' ) as $key ) {
			if ( array_key_exists( $key, $step ) && ! is_bool( $step[ $key ] ) ) {
				return sprintf( ': "%s" must be true or false.', $key );
			}
		}
		if ( array_key_exists( 'expectNoConsoleErrors', $step ) ) {
			$check = $step['expectNoConsoleErrors'];
			if ( true !== $check && ( ! is_array( $check ) || self::is_list( $check ) || ( array_key_exists( 'ignore', $check ) && ! self::is_list_of( $check['ignore'] ) ) ) ) {
				return ': "expectNoConsoleErrors" must be true, or { "ignore": [ patterns ] }.';
			}
		}
		if ( isset( $step['expect'] ) ) {
			$matchers = array_values( array_intersect( array_keys( $step ), self::MATCHERS ) );
			if ( 1 !== count( $matchers ) ) {
				return ': "expect" needs exactly one of: ' . implode( ', ', self::MATCHERS ) . '.';
			}
			$want = $step[ $matchers[0] ];
			switch ( $matchers[0] ) {
				case 'toHaveCount':
					$ok = is_int( $want ) && $want >= 0;
					break;
				case 'toHaveText':
				case 'toContainText':
					$ok = is_string( $want );
					break;
				case 'toHaveValue':
					$ok = is_string( $want ) || is_int( $want ) || is_float( $want );
					break;
				default:
					// false used to pass as true; "not" is the one way to invert a matcher.
					if ( false === $want ) {
						return sprintf( ': "%1$s" must be true. To check the opposite, write "%1$s": true, "not": true.', $matchers[0] );
					}
					$ok = true === $want;
			}
			if ( ! $ok ) {
				return sprintf( ': "%s" has the wrong kind of value.', $matchers[0] );
			}
		}
		return '';
	}

	/**
	 * Whether a value is a JSON array rather than an object.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_list( $value ) {
		return is_array( $value ) && array_values( $value ) === $value;
	}

	/**
	 * Whether a value is a non-empty JSON array of strings, each matching a pattern when one is given.
	 *
	 * @param mixed  $value   Value.
	 * @param string $pattern Regex.
	 * @return bool
	 */
	private static function is_list_of( $value, $pattern = '' ) {
		if ( ! self::is_list( $value ) || ! $value ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || ( $pattern && ! preg_match( $pattern, $item ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is a usable timeout: a positive whole number of milliseconds.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_timeout( $value ) {
		return is_int( $value ) && $value > 0 && $value <= 600000;
	}

	/**
	 * A rehearsal stamped with the oldest format that can run it, so older sites only warn about
	 * rehearsals that use something they lack. One that has a format keeps it.
	 *
	 * @param array $recipe Validated rehearsal.
	 * @return array
	 */
	public static function with_format( array $recipe ) {
		return isset( $recipe['format'] ) ? $recipe : array( 'format' => self::format_needed( $recipe ) ) + $recipe;
	}

	/**
	 * The oldest format that has everything a rehearsal uses. Format 2 added the "dialog" step,
	 * either-or "requires" and "viewport".
	 *
	 * @param array $recipe Validated rehearsal.
	 * @return int
	 */
	public static function format_needed( array $recipe ) {
		if ( isset( $recipe['viewport'] ) ) {
			return 2;
		}
		foreach ( (array) ( $recipe['requires'] ?? array() ) as $slug ) {
			if ( false !== strpos( (string) $slug, ' or ' ) ) {
				return 2;
			}
		}
		foreach ( (array) ( $recipe['steps'] ?? array() ) as $step ) {
			if ( is_array( $step ) && array_key_exists( 'dialog', $step ) ) {
				return 2;
			}
		}
		return 1;
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
	 * Recipe ID. It leaves "format" out, so stamping a format on an existing rehearsal keeps its results,
	 * and sorts object keys, so `{ "fill", "value" }` and `{ "value", "fill" }` are the same rehearsal.
	 *
	 * @param string $content Canonical recipe JSON.
	 * @return string
	 */
	public static function hash( $content ) {
		$recipe = json_decode( $content, true );
		if ( is_array( $recipe ) ) {
			unset( $recipe['format'] );
			$content = self::canonical( self::sort_keys( $recipe ) );
		}
		return hash( 'sha256', $content );
	}

	/**
	 * Objects with their keys sorted, at every depth. Lists keep their order: step order matters.
	 *
	 * @param mixed $value Decoded JSON.
	 * @return mixed
	 */
	private static function sort_keys( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( __CLASS__, 'sort_keys' ), $value );
		if ( ! self::is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	/**
	 * The format a rehearsal was written in; rehearsals from before formats existed are format 1.
	 *
	 * @param array $recipe Decoded rehearsal.
	 * @return int
	 */
	public static function format_of( array $recipe ) {
		return isset( $recipe['format'] ) && is_int( $recipe['format'] ) ? $recipe['format'] : 1;
	}

	/**
	 * Whether a rehearsal was written for a newer Rehearsal than this one, so some steps may not work.
	 *
	 * @param array $recipe Decoded rehearsal.
	 * @return bool
	 */
	public static function is_newer( array $recipe ) {
		return self::format_of( $recipe ) > Companion_Rehearsal::FORMAT;
	}

	/**
	 * Why a shared rehearsal can't be used here: a newer format explains unknown steps better than the raw error.
	 *
	 * @param array  $recipe Decoded rehearsal.
	 * @param string $error  What validate() said.
	 * @return string
	 */
	public static function install_error( array $recipe, $error ) {
		if ( self::is_newer( $recipe ) ) {
			return sprintf(
				'This rehearsal was made with a newer version of Rehearsal (format %d; this site understands format %d) and uses something this version does not know: %s Update the Companion plugin on this site to run it.',
				self::format_of( $recipe ),
				Companion_Rehearsal::FORMAT,
				$error
			);
		}
		return 'The shared rehearsal is not valid here: ' . $error;
	}
}
