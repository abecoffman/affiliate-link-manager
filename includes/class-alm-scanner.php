<?php
/**
 * Scans posts for links, classifies them via the provider registry, and
 * upserts results into the alm_links table. Read-only with respect to
 * post content -- this initial build never rewrites anything, only
 * records what it finds.
 *
 * Runs as a resumable batch (mirrors the same cursor/AJAX pattern the
 * sibling webp-generator plugin uses for its own bulk operation) so a
 * site with thousands of posts never risks hitting PHP's
 * max_execution_time in a single request.
 *
 * @package ALM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ALM_Scanner {

	/**
	 * @var ALM_Adapter_Registry
	 */
	private $adapters;

	/**
	 * @var ALM_Provider_Registry
	 */
	private $providers;

	public function __construct( ALM_Adapter_Registry $adapters, ALM_Provider_Registry $providers ) {
		$this->adapters  = $adapters;
		$this->providers = $providers;
	}

	/**
	 * Post types this plugin scans. Filterable so a site can add pages,
	 * a custom post type, etc.
	 *
	 * @return string[]
	 */
	public function get_scannable_post_types() {
		/**
		 * Filters which post types ALM_Scanner scans for links.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $post_types
		 */
		return apply_filters( 'alm_scannable_post_types', array( 'post' ) );
	}

	/**
	 * Total number of posts a full scan would cover -- used by the admin
	 * UI to size its progress display before starting.
	 *
	 * @return int
	 */
	public function count_scannable_posts() {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_scannable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * Scan one batch of posts starting at $offset, upsert findings into
	 * the alm_links table, and return progress info for the client to
	 * resume from.
	 *
	 * @param int $offset
	 * @param int $batch_size
	 * @return array {
	 *     @type bool $done        True once every post has been scanned.
	 *     @type int  $next_offset Offset to resume from on the next call.
	 *     @type int  $links_found Links found in this batch.
	 * }
	 */
	public function scan_batch( $offset, $batch_size ) {
		$query = new WP_Query(
			array(
				'post_type'      => $this->get_scannable_post_types(),
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => $batch_size,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$post_ids    = $query->posts;
		$links_found = 0;

		foreach ( $post_ids as $post_id ) {
			$links_found += $this->scan_post( (int) $post_id );
		}

		return array(
			'done'        => count( $post_ids ) < $batch_size,
			'next_offset' => $offset + count( $post_ids ),
			'links_found' => $links_found,
		);
	}

	/**
	 * Scan a single post and upsert its links.
	 *
	 * @param int $post_id
	 * @return int Number of links found (not necessarily new -- includes
	 *             ones already known from a previous scan).
	 */
	private function scan_post( $post_id ) {
		$adapter = $this->adapters->get_adapter_for_post( $post_id );
		$links   = $adapter->get_links( $post_id );

		foreach ( $links as $link ) {
			$provider = $this->providers->match_url( $link['url'] );
			$this->upsert_link( $post_id, $adapter->get_id(), $provider->get_id(), $link );
		}

		return count( $links );
	}

	/**
	 * @param int    $post_id
	 * @param string $adapter_id
	 * @param string $provider_id
	 * @param array  $link {location, url, anchor_text}
	 * @return void
	 */
	private function upsert_link( $post_id, $adapter_id, $provider_id, array $link ) {
		global $wpdb;

		$table = ALM_Install::table_name();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table} is a table name (not user input, can't be a placeholder); the real user-supplied values are all passed through prepare() below.
		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d AND adapter = %s AND location = %s", $post_id, $adapter_id, $link['location'] ) );

		$data = array(
			'post_id'     => $post_id,
			'provider'    => $provider_id,
			'adapter'     => $adapter_id,
			'location'    => $link['location'],
			'url'         => $link['url'],
			'anchor_text' => $link['anchor_text'],
			'status'      => 'unclassified' === $provider_id ? 'unclassified' : 'active',
			'last_seen'   => $now,
		);

		if ( $existing_id ) {
			$wpdb->update( $table, $data, array( 'id' => $existing_id ) );
		} else {
			$data['first_seen'] = $now;
			$wpdb->insert( $table, $data );
		}
	}
}
