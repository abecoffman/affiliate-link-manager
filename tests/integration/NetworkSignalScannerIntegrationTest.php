<?php
/**
 * Integration tests for ALM_Network_Signal_Scanner -- needs a real
 * $wpdb and real transients, neither of which the Brain Monkey unit
 * tier can exercise.
 *
 * @package ALM
 */

/**
 * @covers ALM_Network_Signal_Scanner
 */
class NetworkSignalScannerIntegrationTest extends WP_UnitTestCase {

	/**
	 * @var ALM_Network_Signal_Scanner
	 */
	private $scanner;

	public function set_up() {
		parent::set_up();

		global $wpdb;
		ALM_Install::activate();
		$wpdb->query( 'TRUNCATE TABLE ' . ALM_Install::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, test-only truncate between runs.
		delete_transient( ALM_Network_Signal_Scanner::TRANSIENT_KEY );

		$this->scanner = new ALM_Network_Signal_Scanner();
	}

	public function tear_down() {
		remove_all_filters( 'alm_known_unrecognized_network_domains' );
		parent::tear_down();
	}

	public function test_scan_finds_a_link_through_a_known_unrecognized_domain() {
		$this->insert_link_row(
			array(
				'url'      => 'https://avantlink.com/click.php?tt=cl&merchant_id=123&website_id=456',
				'provider' => 'unclassified',
			)
		);

		$results = $this->scanner->scan();

		$this->assertArrayHasKey( 'avantlink.com', $results );
		$this->assertSame( 'AvantLink', $results['avantlink.com']['label'] );
		$this->assertSame( 1, $results['avantlink.com']['count'] );
	}

	/**
	 * The real point of this whole class: once a real ALM_Provider gets
	 * built for a network, its links are no longer provider=unclassified
	 * at all -- they stop appearing here automatically, no separate
	 * bookkeeping needed to keep the two in sync.
	 */
	public function test_scan_ignores_a_link_already_claimed_by_a_real_provider() {
		$this->insert_link_row(
			array(
				'url'      => 'https://amzn.to/3XUY1At',
				'provider' => 'amazon',
			)
		);

		$results = $this->scanner->scan();

		$this->assertSame( array(), $results );
	}

	public function test_scan_ignores_an_unrelated_unclassified_domain() {
		$this->insert_link_row(
			array(
				'url'      => 'https://www.zara.com/us/en/product.html',
				'provider' => 'unclassified',
			)
		);

		$results = $this->scanner->scan();

		$this->assertSame( array(), $results );
	}

	public function test_scan_counts_multiple_links_through_the_same_domain() {
		$this->insert_link_row(
			array(
				'url'      => 'https://avantlink.com/?id=1',
				'provider' => 'unclassified',
			)
		);
		$this->insert_link_row(
			array(
				'url'      => 'https://avantlink.com/?id=2',
				'provider' => 'unclassified',
				'location' => '1',
			)
		);

		$results = $this->scanner->scan();

		$this->assertSame( 2, $results['avantlink.com']['count'] );
	}

	public function test_known_unrecognized_domains_is_filterable() {
		add_filter(
			'alm_known_unrecognized_network_domains',
			function ( $domains ) {
				$domains['a-brand-new-network.example'] = 'A Brand New Network';
				return $domains;
			}
		);

		$this->insert_link_row(
			array(
				'url'      => 'https://a-brand-new-network.example/click?id=1',
				'provider' => 'unclassified',
			)
		);

		$results = $this->scanner->scan();

		$this->assertArrayHasKey( 'a-brand-new-network.example', $results );
		$this->assertSame( 'A Brand New Network', $results['a-brand-new-network.example']['label'] );
	}

	/**
	 * Confirms the 1-hour cache actually caches -- a link added after
	 * the first scan() call shouldn't appear until the cache expires.
	 */
	public function test_scan_result_is_cached() {
		$results_before = $this->scanner->scan();
		$this->assertSame( array(), $results_before );

		$this->insert_link_row(
			array(
				'url'      => 'https://avantlink.com/?id=1',
				'provider' => 'unclassified',
			)
		);

		$results_after = $this->scanner->scan();
		$this->assertSame( array(), $results_after, 'The cached (empty) result should still be returned.' );

		delete_transient( ALM_Network_Signal_Scanner::TRANSIENT_KEY );
		$results_fresh = $this->scanner->scan();
		$this->assertArrayHasKey( 'avantlink.com', $results_fresh );
	}

	/**
	 * @param array $overrides
	 * @return void
	 */
	private function insert_link_row( array $overrides ) {
		global $wpdb;

		$defaults = array(
			'post_id'       => 1,
			'adapter'       => 'post_content',
			'location'      => '0',
			'anchor_text'   => 'link',
			'category'      => ALM_Install::CATEGORY_CANDIDATE,
			'classified_at' => current_time( 'mysql' ),
			'first_seen'    => current_time( 'mysql' ),
			'last_seen'     => current_time( 'mysql' ),
		);

		$wpdb->insert( ALM_Install::table_name(), array_merge( $defaults, $overrides ) );
	}
}
