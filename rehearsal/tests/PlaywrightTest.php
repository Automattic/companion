<?php
/**
 * Tests for Companion_Rehearsal_Playwright.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

/**
 * A rehearsal must mean the same thing in Playwright as in the browser runner.
 */
class PlaywrightTest extends TestCase {

	/**
	 * Generate a test file for a list of steps plus extra settings.
	 *
	 * @param array $steps    Steps.
	 * @param array $settings Other rehearsal keys.
	 * @return string
	 */
	private function generate( array $steps, array $settings = array() ) {
		return Companion_Rehearsal_Playwright::to_playwright(
			array(
				'title'      => 'A rehearsal',
				'id'         => str_repeat( 'a', 64 ),
				'site'       => 'https://example.test/',
				'definition' => array( 'steps' => $steps ) + $settings,
			)
		);
	}

	/**
	 * @dataProvider translations
	 *
	 * @param array  $step     One step.
	 * @param string $expected A line the generated test must contain.
	 */
	public function test_translates_steps( $step, $expected ) {
		$this->assertStringContainsString( $expected, $this->generate( array( $step ) ) );
	}

	public function translations() {
		return array(
			// The browser runner's text= also matches aria-label; icon-only buttons depend on it.
			'text= also matches aria-label'   => array( array( 'click' => 'text=Add a contact form' ), 'page.locator( "text=Add a contact form" ).or( page.locator( "[aria-label*=\"Add a contact form\"]" ) )' ),
			'iframe part becomes frameLocator' => array( array( 'click' => 'iframe[name="editor-canvas"] >> .x' ), 'page.frameLocator( "iframe[name=\"editor-canvas\"]" ).locator( ".x" )' ),
			'{{run}} becomes the run constant' => array( array( 'fill' => '#name', 'value' => 'Ada {{run}}' ), '.fill( "Ada " + run );' ),
			'acts on the first visible match'  => array( array( 'click' => '#submit' ), 'page.locator( "#submit" ).filter( { visible: true } ).first()' ),
			'not toBeVisible means none shown' => array( array( 'expect' => '.notice', 'not' => true, 'toBeVisible' => true ), 'page.locator( ".notice" ).filter( { visible: true } ) ).toHaveCount( 0 );' ),
			'toHaveCount counts hidden ones'   => array( array( 'expect' => 'tr', 'toHaveCount' => 3 ), 'expect( page.locator( "tr" ) ).toHaveCount( 3 );' ),
			'step timeout is kept'             => array( array( 'waitFor' => '#x', 'timeout' => 4000 ), '.toBeVisible( { timeout: 4000 } );' ),
			'press without a target'           => array( array( 'press' => 'Escape' ), 'await page.keyboard.press( "Escape" );' ),
			'ignored console errors'           => array( array( 'expectNoConsoleErrors' => array( 'ignore' => array( 'already registered' ) ) ), 'new RegExp( "already registered" )' ),
			'user text keeps its spacing'      => array( array( 'fill' => '#q', 'value' => 'call ( ) now' ), '.fill( "call ( ) now" );' ),
			'no timeout, no options'           => array( array( 'hover' => '#menu' ), '.hover();' ),
			// Playwright's role= engine throws on pseudo-classes; the browser runner allows :has-text().
			'role with :has-text is a filter'  => array( array( 'click' => 'role=tab:has-text("Settings")' ), 'page.locator( "role=tab" ).filter( { hasText: "Settings" } )' ),
			'dialog accept with prompt text'   => array( array( 'dialog' => 'accept', 'value' => 'Ada {{run}}' ), "page.once( 'dialog', ( d ) => d.accept( \"Ada \" + run ) );" ),
			'dialog dismiss'                   => array( array( 'dialog' => 'dismiss' ), "page.once( 'dialog', ( d ) => d.dismiss() );" ),
			'each step is a logged test.step'  => array( array( 'expect' => 'h1', 'not' => true, 'toHaveText' => 'Oops' ), 'await rh.step( 1, "expect h1 not toHaveText \"Oops\"", async () => {' ),
		);
	}

	public function test_a_click_timeout_cannot_add_code() {
		$code = $this->generate( array( array( 'click' => '#a', 'timeout' => '1 ); process.exit( 1 ); ( 2' ) ) );
		$this->assertStringContainsString( ".first(), 1 );\n", $code );
		$this->assertStringNotContainsString( 'process.exit', $code );
	}

	/**
	 * @dataProvider every_step
	 *
	 * @param array $step A step of one type.
	 */
	public function test_every_step_type_becomes_code( $step ) {
		$code = $this->generate( array( array( 'goto' => '/' ), $step ) );
		$this->assertMatchesRegularExpression( '/\n\t\tawait rh\.step\( 2, [^\n]+, async \(\) => \{\n\t\t\t(await |expect\(|page\.once\()[^\n]+;\n\t\t\} \);\n\t\} \);\n\} \);\n$/', $code );
	}

	public function test_every_step_type_is_in_the_list_above() {
		$types = array_map( static fn( $row ) => current( array_intersect( array_keys( $row[0] ), Companion_Rehearsal_Schema::STEPS ) ), $this->every_step() );
		$this->assertEqualsCanonicalizing( Companion_Rehearsal_Schema::STEPS, array_values( array_unique( $types ) ) );
	}

	public function every_step() {
		$steps = array(
			'goto'                  => array( 'goto' => '/wp-admin/' ),
			'waitFor'               => array( 'waitFor' => '#a' ),
			'expectText'            => array( 'expectText' => 'Hello', 'in' => 'main' ),
			'expectCount'           => array( 'expectCount' => 'li', 'min' => 0 ),
			'fill'                  => array( 'fill' => '#a', 'value' => 'x' ),
			'click'                 => array( 'click' => '#a' ),
			'check'                 => array( 'check' => '#a' ),
			'uncheck'               => array( 'uncheck' => '#a' ),
			'selectOption'          => array( 'selectOption' => '#a', 'value' => 'b' ),
			'hover'                 => array( 'hover' => '#a' ),
			'press'                 => array( 'press' => 'Enter', 'on' => '#a' ),
			'expectNoConsoleErrors' => array( 'expectNoConsoleErrors' => true ),
			'snapshot'              => array( 'snapshot' => 'main' ),
			'screenshot'            => array( 'screenshot' => 'home' ),
			'dialog'                => array( 'dialog' => 'accept' ),
		);
		$values = array( 'toHaveCount' => 2, 'toHaveText' => 'a', 'toContainText' => 'a', 'toHaveValue' => 'a' );
		foreach ( Companion_Rehearsal_Schema::MATCHERS as $matcher ) {
			$steps[ "expect $matcher" ] = array( 'expect' => '#a', $matcher => $values[ $matcher ] ?? true );
		}
		return array_map( static fn( $step ) => array( $step ), $steps );
	}


	public function test_optional_steps_cannot_fail_the_test() {
		$code = $this->generate( array( array( 'click' => '#close', 'optional' => true ) ) );
		$this->assertStringContainsString( "await rh.step( 1, \"click #close\", async () => {\n\t\t\tawait click(", $code );
		$this->assertStringContainsString( "\n\t\t}, true );", $code );
	}

	public function test_reports_the_result_to_the_rehearsal_it_came_from() {
		$code = Companion_Rehearsal_Playwright::to_playwright(
			array(
				'title'      => 'A rehearsal',
				'id'         => str_repeat( 'c', 64 ),
				'post_id'    => 42,
				'site'       => 'https://example.test/',
				'definition' => array( 'steps' => array( array( 'goto' => '/' ) ) ),
			)
		);
		$this->assertStringContainsString( 'const rh = rehearsal( 42, "' . str_repeat( 'c', 64 ) . '", "desktop" );', $code );
		$this->assertStringContainsString( 'test.afterEach( ( {}, info ) => rh.report( info ) );', $code );
		$this->assertStringContainsString( "'?rest_route=/rehearsal/v1/runs'", $code );
	}

	public function test_a_pinned_viewport_uses_the_browser_runners_size() {
		$this->assertStringContainsString( 'test.use( { actionTimeout: 10000, viewport: { width: 390, height: 844 } } );', $this->generate( array( array( 'goto' => '/' ) ), array( 'viewport' => 'mobile' ) ) );
		$this->assertStringContainsString( 'viewport: { width: 1280, height: 800 }', $this->generate( array( array( 'goto' => '/' ) ), array( 'viewport' => 'desktop' ) ) );
		$this->assertStringNotContainsString( 'viewport: {', $this->generate( array( array( 'goto' => '/' ) ) ) );
	}

	public function test_no_page_measuring_without_budgets() {
		$code = $this->generate( array( array( 'goto' => '/' ) ) );
		$this->assertStringNotContainsString( 'measure', $code );
		$this->assertStringNotContainsString( 'trackLoads', $code );
	}

	public function test_measures_each_page_load_against_the_rehearsals_budgets() {
		$code = $this->generate( array( array( 'goto' => '/' ), array( 'click' => 'a' ) ), array( 'budgets' => array( 'lcp' => 2500 ) ) );
		$this->assertStringContainsString( 'const measure = await trackLoads( page, {"lcp":2500} );', $code );
		$this->assertSame( 3, substr_count( $code, 'await measure();' ) );
	}

	public function test_a_role_reads_its_own_storage_state_first() {
		$this->assertStringContainsString( 'test.use( { actionTimeout: 10000, storageState: process.env.STORAGE_STATE_EDITOR || process.env.STORAGE_STATE } );', $this->generate( array( array( 'goto' => '/' ) ), array( 'role' => 'editor' ) ) );
	}

	public function test_a_bundle_shares_one_setup_and_keeps_each_rehearsals_settings() {
		$payload = static fn( $title, $def ) => array(
			'title'      => $title,
			'id'         => str_repeat( 'b', 64 ),
			'site'       => 'https://example.test/',
			'definition' => $def,
		);
		$code    = Companion_Rehearsal_Playwright::to_playwright_bundle(
			array(
				$payload( 'Plain', array( 'steps' => array( array( 'goto' => '/' ) ) ) ),
				$payload( 'As editor', array( 'role' => 'editor', 'timeout' => 20000, 'steps' => array( array( 'goto' => '/wp-admin/' ) ) ) ),
				$payload( 'Budgeted', array( 'budgets' => array( 'weight' => 1000 ), 'steps' => array( array( 'goto' => '/' ) ) ) ),
			),
			'https://example.test/'
		);

		$this->assertSame( 1, substr_count( $code, "from '@playwright/test';" ) );
		$this->assertSame( 1, substr_count( $code, 'async function trackLoads(' ) );
		$this->assertSame( 3, substr_count( $code, 'test.describe( ' ) );
		$this->assertSame( 1, substr_count( $code, 'process.env.STORAGE_STATE_EDITOR' ) );
		$this->assertStringContainsString( "test.describe( \"As editor\", () => {\n\tconst expect = baseExpect.configure( { timeout: 20000 } );", $code );
		$this->assertStringContainsString( 'trackLoads( page, {"weight":1000} )', $code );
	}

	/**
	 * @dataProvider line_ends
	 *
	 * @param string $end A character JS reads as the end of a // comment.
	 */
	public function test_a_title_stays_on_its_comment_line( $end ) {
		$code = Companion_Rehearsal_Playwright::to_playwright(
			array(
				'title'      => "Two{$end}lines",
				'id'         => str_repeat( 'a', 64 ),
				'site'       => 'https://example.test/',
				'definition' => array( 'steps' => array( array( 'goto' => '/' ) ) ),
			)
		);
		$this->assertStringContainsString( '// Rehearsal: Two lines', $code );
	}

	public function line_ends() {
		return array(
			'newline'             => array( "\n" ),
			'carriage return'     => array( "\r" ),
			'line separator'      => array( "\u{2028}" ),
			'paragraph separator' => array( "\u{2029}" ),
		);
	}
}
