<?php
/**
 * Lightweight access log (custom table, capped row count).
 *
 * @package AgentToll_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AgentToll_Logger {

	public const STATUS_BLOCKED = 'blocked';
	public const STATUS_PAID    = 'paid';
	public const MAX_ROWS       = 500;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'agenttoll_logs';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				user_agent VARCHAR(255) NOT NULL DEFAULT '',
				post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				status VARCHAR(16) NOT NULL DEFAULT '',
				PRIMARY KEY  (id)
			) {$charset};"
		);
	}

	public function log( string $user_agent, int $post_id, string $status ): void {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'user_agent' => substr( $user_agent, 0, 255 ),
				'post_id'    => max( 0, $post_id ),
				'status'     => $status,
			),
			array( '%s', '%s', '%d', '%s' )
		);

		// Cheap probabilistic pruning keeps the table bounded without cron.
		if ( 1 === mt_rand( 1, 50 ) ) {
			$this->prune();
		}
	}

	/**
	 * @return object[]
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array{blocked:int,paid:int} */
	public function counts(): array {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" );
		$counts = array(
			'blocked' => 0,
			'paid'    => 0,
		);
		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->n;
			}
		}
		return $counts;
	}

	public function clear(): void {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	private function prune(): void {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id <= ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d ) AS t )",
				self::MAX_ROWS
			)
		);
	}
}
