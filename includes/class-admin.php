<?php
/**
 * Admin UI: Settings > AgentToll WP (settings form + recent AI crawler logs).
 *
 * @package AgentToll_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AgentToll_Admin {

	private const PAGE_SLUG = 'agenttoll-wp';
	private const GROUP     = 'agenttoll_group';

	private AgentToll_Logger $logger;

	public function __construct( AgentToll_Logger $logger ) {
		$this->logger = $logger;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_agenttoll_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AGENTTOLL_FILE ), array( $this, 'action_links' ) );
	}

	public function add_menu(): void {
		add_options_page(
			__( 'AgentToll WP', 'agenttoll-wp' ),
			__( 'AgentToll WP', 'agenttoll-wp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'agenttoll-wp' ) . '</a>' );
		return $links;
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			AgentToll_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => AgentToll_Settings::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = AgentToll_Settings::get();

		$price = isset( $input['price'] )
			? (float) str_replace( ',', '.', (string) $input['price'] )
			: (float) $existing['price'];
		$price = max( 0.0, min( 100.0, $price ) );

		$ttl = isset( $input['token_ttl'] ) ? (int) $input['token_ttl'] : (int) $existing['token_ttl'];
		$ttl = max( 30, min( 86400, $ttl ) );

		// Secret key: keep existing unless regeneration requested (or none/invalid).
		$secret = isset( $input['secret_key'] ) ? (string) $input['secret_key'] : (string) $existing['secret_key'];
		if ( ! empty( $input['regenerate_key'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $secret ) ) {
			$secret = AgentToll_Settings::generate_secret();
			add_settings_error(
				AgentToll_Settings::OPTION,
				'agenttoll_key',
				__( 'A new signing key was generated. All previously issued tokens are now invalid.', 'agenttoll-wp' ),
				'info'
			);
		}

		return array(
			'enabled'       => empty( $input['enabled'] ) ? 0 : 1,
			'price'         => AgentToll_Settings::format_price( $price ),
			'currency'      => 'USD',
			'blocked_uas'   => $this->sanitize_list( $input['blocked_uas'] ?? array() ),
			'whitelist_uas' => $this->sanitize_list( $input['whitelist_uas'] ?? array() ),
			'secret_key'    => $secret,
			'token_ttl'     => $ttl,
		);
	}

	/**
	 * @param mixed $value Textarea string or array.
	 * @return string[]
	 */
	private function sanitize_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value ) ?: array();
		}
		$out = array();
		foreach ( (array) $value as $item ) {
			$item = substr( trim( sanitize_text_field( (string) $item ) ), 0, 100 );
			if ( '' !== $item ) {
				$out[ strtolower( $item ) ] = $item;
			}
		}
		return array_slice( array_values( $out ), 0, 100 );
	}

	public function handle_clear_logs(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'agenttoll-wp' ), 403 );
		}
		check_admin_referer( 'agenttoll_clear_logs' );
		$this->logger->clear();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&cleared=1' ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s        = AgentToll_Settings::get();
		$opt      = AgentToll_Settings::OPTION;
		$counts   = $this->logger->counts();
		$logs     = $this->logger->recent( 50 );
		$endpoint = rest_url( AgentToll_Payment_Verifier::REST_NAMESPACE . AgentToll_Payment_Verifier::REST_ROUTE );
		$key      = (string) $s['secret_key'];
		$masked   = strlen( $key ) >= 12 ? substr( $key, 0, 6 ) . str_repeat( '•', 20 ) . substr( $key, -4 ) : __( '(not set)', 'agenttoll-wp' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AgentToll WP', 'agenttoll-wp' ); ?></h1>

			<div class="notice notice-warning inline">
				<p>
					<strong><?php esc_html_e( 'v0.1 uses a mock settlement endpoint.', 'agenttoll-wp' ); ?></strong>
					<?php esc_html_e( 'Anyone can obtain a token without paying. This validates the protocol flow only; do not rely on it for revenue yet.', 'agenttoll-wp' ); ?>
				</p>
			</div>

			<?php settings_errors( $opt ); ?>
			<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Logs cleared.', 'agenttoll-wp' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable AgentToll', 'agenttoll-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( 1, (int) $s['enabled'] ); ?> />
								<?php esc_html_e( 'Require payment from blocked AI user-agents', 'agenttoll-wp' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agenttoll_price"><?php esc_html_e( 'Default price per post (USD)', 'agenttoll-wp' ); ?></label></th>
						<td>
							<input id="agenttoll_price" type="number" step="0.0001" min="0" max="100" class="small-text"
								name="<?php echo esc_attr( $opt ); ?>[price]" value="<?php echo esc_attr( AgentToll_Settings::format_price( $s['price'] ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Per-post override: set the custom field _agenttoll_price. Exempt a post: set _agenttoll_exempt to 1.', 'agenttoll-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agenttoll_blocked"><?php esc_html_e( 'Blocked AI user-agents', 'agenttoll-wp' ); ?></label></th>
						<td>
							<textarea id="agenttoll_blocked" rows="8" cols="40" class="large-text code"
								name="<?php echo esc_attr( $opt ); ?>[blocked_uas]"><?php echo esc_textarea( implode( "\n", (array) $s['blocked_uas'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line. Case-insensitive substring match against the User-Agent header. Googlebot and Bingbot are never blocked unless you add them.', 'agenttoll-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agenttoll_whitelist"><?php esc_html_e( 'Whitelisted academic / non-profit user-agents', 'agenttoll-wp' ); ?></label></th>
						<td>
							<textarea id="agenttoll_whitelist" rows="4" cols="40" class="large-text code"
								name="<?php echo esc_attr( $opt ); ?>[whitelist_uas]"><?php echo esc_textarea( implode( "\n", (array) $s['whitelist_uas'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Always allowed free; checked before the blocklist.', 'agenttoll-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="agenttoll_ttl"><?php esc_html_e( 'Token lifetime (seconds)', 'agenttoll-wp' ); ?></label></th>
						<td><input id="agenttoll_ttl" type="number" min="30" max="86400" class="small-text"
							name="<?php echo esc_attr( $opt ); ?>[token_ttl]" value="<?php echo esc_attr( (string) (int) $s['token_ttl'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Secret signing key', 'agenttoll-wp' ); ?></th>
						<td>
							<code><?php echo esc_html( $masked ); ?></code>
							<p><label>
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[regenerate_key]" value="1" />
								<?php esc_html_e( 'Regenerate on save (invalidates all issued tokens)', 'agenttoll-wp' ); ?>
							</label></p>
							<p class="description"><?php esc_html_e( 'Auto-generated (256-bit). Used for HMAC-SHA256 token signing; never shown in full.', 'agenttoll-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Payment endpoint', 'agenttoll-wp' ); ?></th>
						<td><code><?php echo esc_html( $endpoint ); ?></code></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Recent AI Crawler Logs', 'agenttoll-wp' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: blocked count, 2: paid count */
					esc_html__( 'Totals (last %3$d retained): %1$d blocked (402), %2$d paid (200).', 'agenttoll-wp' ),
					(int) $counts['blocked'],
					(int) $counts['paid'],
					(int) AgentToll_Logger::MAX_ROWS
				);
				?>
			</p>

			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th style="width:170px"><?php esc_html_e( 'Timestamp', 'agenttoll-wp' ); ?></th>
						<th><?php esc_html_e( 'User-Agent', 'agenttoll-wp' ); ?></th>
						<th style="width:220px"><?php esc_html_e( 'Post', 'agenttoll-wp' ); ?></th>
						<th style="width:110px"><?php esc_html_e( 'Status', 'agenttoll-wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $logs ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No AI crawler requests logged yet.', 'agenttoll-wp' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $logs as $row ) : ?>
						<?php
						$paid    = AgentToll_Logger::STATUS_PAID === $row->status;
						$post_id = (int) $row->post_id;
						$when    = wp_date( 'Y-m-d H:i:s', (int) strtotime( $row->created_at . ' UTC' ) );
						?>
						<tr>
							<td><?php echo esc_html( $when ); ?></td>
							<td><code><?php echo esc_html( $row->user_agent ); ?></code></td>
							<td>
								<?php if ( $post_id > 0 && get_post( $post_id ) ) : ?>
									<a href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>">#<?php echo (int) $post_id; ?> <?php echo esc_html( wp_html_excerpt( get_the_title( $post_id ), 40, '…' ) ); ?></a>
								<?php elseif ( $post_id > 0 ) : ?>
									#<?php echo (int) $post_id; ?>
								<?php else : ?>
									<?php esc_html_e( 'REST collection', 'agenttoll-wp' ); ?>
								<?php endif; ?>
							</td>
							<td>
								<strong style="color:<?php echo $paid ? '#1a7f37' : '#b32d2e'; ?>">
									<?php echo $paid ? esc_html__( '200 Paid', 'agenttoll-wp' ) : esc_html__( '402 Blocked', 'agenttoll-wp' ); ?>
								</strong>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="agenttoll_clear_logs" />
				<?php wp_nonce_field( 'agenttoll_clear_logs' ); ?>
				<?php submit_button( __( 'Clear logs', 'agenttoll-wp' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
