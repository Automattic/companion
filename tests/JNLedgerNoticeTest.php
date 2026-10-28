<?php
/**
 * The ledger part of the welcome notice, rendered from a cached record.
 *
 * @package companion
 */

use PHPUnit\Framework\TestCase;

class JNLedgerNoticeTest extends TestCase {

	const HAND_SET = array(
		'owner'  => 'Automattic',
		'repo'   => 'wp-calypso',
		'number' => 7,
		'url'    => 'https://github.com/Automattic/wp-calypso/pull/7',
	);

	const DETECTED_URL = 'https://github.com/Automattic/jetpack/pull/123';

	protected function setUp(): void {
		$GLOBALS['jn_site_transients'] = array();
		$GLOBALS['jn_can_manage']      = false;
	}

	private function render( array $record ) {
		$GLOBALS['jn_site_transients'][ Companion_JN_Ledger::RECORD_CACHE_KEY ] = array(
			'record'      => $record,
			'fetched_at'  => time(),
			'fresh_until' => time() + 300,
		);
		ob_start();
		Companion_JN_Ledger::render_notice_section();
		return ob_get_clean();
	}

	private function detected_pr( $number = 123 ) {
		return array(
			'owner'    => 'Automattic',
			'repo'     => 'jetpack',
			'number'   => $number,
			'url'      => 'https://github.com/Automattic/jetpack/pull/' . $number,
			'detected' => true,
		);
	}

	private function build( $branch = 'add/thing', $pr_url = self::DETECTED_URL, $plugin = 'jetpack' ) {
		return array(
			'plugin' => $plugin,
			'branch' => $branch,
			'pr_url' => $pr_url,
		);
	}

	public function test_old_response_without_prs_shows_no_jetpack_beta_line() {
		$html = $this->render( array( 'label' => 'Testing' ) );

		$this->assertStringContainsString( 'Testing', $html );
		$this->assertStringNotContainsString( 'Jetpack Beta', $html );
		$this->assertStringNotContainsString( '(detected)', $html );
		$this->assertStringNotContainsString( 'jn_ledger_refs', $html );
	}

	public function test_old_response_lists_prs_without_detected_label() {
		$html = $this->render( array( 'prs' => array( self::HAND_SET ) ) );

		$this->assertStringContainsString( '<strong>PRs:</strong>', $html );
		$this->assertStringContainsString( '<a href="https://github.com/Automattic/wp-calypso/pull/7" target="_blank" rel="noopener noreferrer">Automattic/wp-calypso#7</a>', $html );
		$this->assertStringNotContainsString( '(detected)', $html );
	}

	public function test_detected_pr_is_shown_on_the_branch_line_only() {
		$html = $this->render(
			array(
				'prs'          => array( $this->detected_pr() ),
				'jetpack_beta' => array( $this->build() ),
			)
		);

		$this->assertStringContainsString( '<strong>Jetpack Beta:</strong>', $html );
		$this->assertStringContainsString( '<code>add/thing</code> &rarr; <a href="' . self::DETECTED_URL . '"', $html );
		$this->assertStringContainsString( '>Automattic/jetpack#123</a>', $html );
		$this->assertSame( 1, substr_count( $html, '(detected)' ) );
		$this->assertStringNotContainsString( '<strong>PRs:</strong>', $html );
	}

	public function test_branch_without_a_pr_is_shown_without_a_link() {
		$html = $this->render( array( 'jetpack_beta' => array( $this->build( 'add/thing', '' ) ) ) );

		$this->assertStringContainsString( '<code>add/thing</code>', $html );
		$this->assertStringNotContainsString( '&rarr;', $html );
		$this->assertStringContainsString( '(detected)', $html );
	}

	public function test_hand_set_pr_hides_the_branch_line_and_marks_the_detected_pr() {
		$html = $this->render(
			array(
				'prs'          => array( self::HAND_SET, $this->detected_pr() ),
				'jetpack_beta' => array( $this->build() ),
			)
		);

		$this->assertStringNotContainsString( 'Jetpack Beta', $html );
		$this->assertStringContainsString( '>Automattic/wp-calypso#7</a>, <a', $html );
		$this->assertStringContainsString( '>Automattic/jetpack#123</a> (detected)', $html );
		$this->assertSame( 1, substr_count( $html, '(detected)' ) );
	}

	public function test_hand_set_pr_equal_to_the_branch_pr_is_not_marked_detected() {
		$hand_set = array_merge( $this->detected_pr(), array( 'detected' => false ) );
		$html     = $this->render(
			array(
				'prs'          => array( $hand_set ),
				'jetpack_beta' => array( $this->build() ),
			)
		);

		$this->assertStringNotContainsString( 'Jetpack Beta', $html );
		$this->assertStringContainsString( '>Automattic/jetpack#123</a>', $html );
		$this->assertStringNotContainsString( '(detected)', $html );
	}

	public function test_detected_pr_for_another_branch_stays_in_the_prs_line() {
		$html = $this->render(
			array(
				'prs'          => array( $this->detected_pr( 99 ) ),
				'jetpack_beta' => array( $this->build() ),
			)
		);

		$this->assertStringContainsString( '<code>add/thing</code> &rarr;', $html );
		$this->assertStringContainsString( '>Automattic/jetpack#99</a> (detected)', $html );
	}

	public function test_several_builds_are_named_by_plugin() {
		$html = $this->render(
			array(
				'jetpack_beta' => array(
					$this->build(),
					$this->build( 'fix/other', '', 'jetpack-boost' ),
				),
			)
		);

		$this->assertStringContainsString( 'jetpack <code>add/thing</code>', $html );
		$this->assertStringContainsString( 'jetpack-boost <code>fix/other</code>', $html );
	}

	public function test_site_reported_strings_are_escaped() {
		$html = $this->render(
			array(
				'jetpack_beta' => array(
					$this->build( '<script>alert(1)</script>', 'javascript:alert(1)', '<b>x</b>' ),
					$this->build( 'b"><img src=x>', 'https://github.com/a"b/<r>/pull/1' ),
				),
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<r>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
	}

	public function test_pr_url_that_is_not_a_github_pull_is_shown_as_the_url() {
		$html = $this->render( array( 'jetpack_beta' => array( $this->build( 'add/thing', 'https://example.test/x' ) ) ) );

		$this->assertStringContainsString( '<code>add/thing</code> &rarr; <a href="https://example.test/x" target="_blank" rel="noopener noreferrer">https://example.test/x</a>', $html );
	}

	/**
	 * @dataProvider malformed_records
	 */
	public function test_malformed_fields_render_without_errors( array $record, array $expected ) {
		$html = $this->render( $record );

		$this->assertStringNotContainsString( 'Jetpack Beta', $html );
		foreach ( $expected as $text ) {
			$this->assertStringContainsString( $text, $html );
		}
	}

	public function malformed_records() {
		return array(
			'jetpack_beta not a list'      => array( array( 'jetpack_beta' => true ), array() ),
			'jetpack_beta a string'        => array( array( 'jetpack_beta' => 'add/thing' ), array() ),
			'jetpack_beta an object'       => array( array( 'jetpack_beta' => array( 'branch' => 'add/thing' ) ), array() ),
			'build without branch'         => array( array( 'jetpack_beta' => array( array( 'pr_url' => self::DETECTED_URL ), null, 3 ) ), array() ),
			'branch not a string'          => array( array( 'jetpack_beta' => array( array( 'branch' => array( 'x' ) ) ) ), array() ),
			'PR without owner/repo/number' => array( array( 'prs' => array( array( 'url' => self::DETECTED_URL ) ) ), array( '>' . self::DETECTED_URL . '</a>' ) ),
			'PR with no usable fields'     => array( array( 'prs' => array( array( 'number' => 'x' ), 'junk' ) ), array() ),
		);
	}
}
