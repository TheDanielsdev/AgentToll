<?php
/**
 * Request interceptor: detects AI crawlers and answers HTTP 402 unless paid.
 *
 * Hot path ordering (cheapest checks first, humans exit early):
 *   enabled? -> UA present? -> UA matches whitelist/blocklist? -> post lookup -> token check.
 *
 * Covers:
 *   - Front-end singular posts via `template_redirect`
 *   - REST: GET /wp-json/wp/v2/posts and /wp-json/wp/v2/posts/{id} via `rest_pre_dispatch`
 *   - A virtual /llms.txt describing the policy (served only when no real file/page exists)
 *
 * @package AgentToll_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AgentToll_Interceptor {

	private const PROTOCOL = 'x402-mock';

	private AgentToll_Payment_Verifier $verifier;
	private AgentToll_Logger $logger;

	public function __construct( AgentToll_Payment_Verifier $verifier, AgentToll_Logger $logger ) {
		$this->verifier = $verifier;
		$this->logger   = $logger;
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle_frontend' ), 1 );
		add_filter( 'rest_pre_dispatch', array( $this, 'handle_rest' ), 1, 3 );
	}

	/* ------------------------------------------------------------------ *
	 * Front-end
	 * ------------------------------------------------------------------ */

	public function handle_frontend(): void {
		$t0       = microtime( true );
		$settings = AgentToll_Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( $this->is_llms_txt_request() ) {
			$this->serve_llms_txt( $settings );
		}

		$ua = $this->user_agent();
		if ( '' === $ua || ! $this->is_ai_crawler( $ua, $settings ) ) {
			return; // Humans and traditional search indexers: untouched.
		}

		if ( ! is_singular( AgentToll_Settings::protected_post_types() ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id < 1 || AgentToll_Settings::is_exempt( $post_id ) ) {
			return;
		}

		$resource = 'post:' . $post_id;
		$price    = AgentToll_Settings::price_for( $post_id );
		$check    = $this->authorize( $resource, $price );

		if ( $check['ok'] ) {
			$this->mark_paid_response( $t0 );
			$this->defer_log( $ua, $post_id, AgentToll_Logger::STATUS_PAID );
			return; // WordPress continues and serves the normal post.
		}

		$this->defer_log( $ua, $post_id, AgentToll_Logger::STATUS_BLOCKED );
		$this->send_402_and_exit( $resource, $price, $check['reason'], $t0 );
	}

	/* ------------------------------------------------------------------ *
	 * REST API
	 * ------------------------------------------------------------------ */

	/**
	 * @param mixed           $result  Short-circuit value (null to continue).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function handle_rest( $result, $server, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		$t0       = microtime( true );
		$settings = AgentToll_Settings::get();
		if ( empty( $settings['enabled'] ) ) {
			return $result;
		}

		$ua = $this->user_agent();
		if ( '' === $ua || ! $this->is_ai_crawler( $ua, $settings ) ) {
			return $result;
		}

		if ( ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $result;
		}
		if ( ! preg_match( '#^/wp/v2/posts(?:/(\d+))?/?$#', $request->get_route(), $m ) ) {
			return $result;
		}

		$post_id = isset( $m[1] ) ? (int) $m[1] : 0;
		if ( $post_id > 0 && AgentToll_Settings::is_exempt( $post_id ) ) {
			return $result;
		}

		$resource = $post_id > 0 ? 'post:' . $post_id : AgentToll_Payment_Verifier::COLLECTION_RESOURCE;
		$price    = AgentToll_Settings::price_for( $post_id );
		$check    = $this->authorize( $resource, $price );

		if ( $check['ok'] ) {
			$this->mark_paid_response( $t0 );
			$this->defer_log( $ua, $post_id, AgentToll_Logger::STATUS_PAID );
			return $result;
		}

		$this->defer_log( $ua, $post_id, AgentToll_Logger::STATUS_BLOCKED );

		$response = new WP_REST_Response( $this->challenge_body( $resource, $price, $check['reason'] ), 402 );
		foreach ( $this->challenge_headers( $resource, $price, $t0 ) as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/* ------------------------------------------------------------------ *
	 * Detection + authorization
	 * ------------------------------------------------------------------ */

	/**
	 * Whitelist wins over blocklist. Case-insensitive substring match.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	private function is_ai_crawler( string $ua, array $settings ): bool {
		foreach ( (array) $settings['whitelist_uas'] as $token ) {
			if ( '' !== $token && false !== stripos( $ua, (string) $token ) ) {
				return false;
			}
		}
		foreach ( (array) $settings['blocked_uas'] as $token ) {
			if ( '' !== $token && false !== stripos( $ua, (string) $token ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return array{ok:bool,reason:string} */
	private function authorize( string $resource, string $price ): array {
		$token = $this->bearer_token();
		if ( '' === $token ) {
			return array(
				'ok'     => false,
				'reason' => 'missing_token',
			);
		}
		return $this->verifier->verify_token( $token, $resource, AgentToll_Settings::price_micro( $price ) );
	}

	private function bearer_token(): string {
		$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

		if ( '' === $header && function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( (string) $name, 'Authorization' ) ) {
					$header = (string) $value;
					break;
				}
			}
		}

		if ( is_string( $header ) && preg_match( '/^Bearer\s+([A-Za-z0-9_\-\.]+)$/i', trim( $header ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	private function user_agent(): string {
		$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
		return is_string( $ua ) ? substr( sanitize_text_field( wp_unslash( $ua ) ), 0, 255 ) : '';
	}

	/* ------------------------------------------------------------------ *
	 * Response building
	 * ------------------------------------------------------------------ */

	/**
	 * Machine-readable payment challenge headers.
	 *
	 * @return array<string,string>
	 */
	private function challenge_headers( string $resource, string $price, float $t0 ): array {
		return array(
			'X-Payment-Required' => 'true',
			'X-Price-Amount'     => $price,
			'X-Price-Currency'   => 'USD',
			'X-Payment-Protocol' => self::PROTOCOL,
			'X-Payment-Endpoint' => $this->relative_endpoint(),
			'X-Payment-Resource' => $resource, // Extra: tells the agent what to ask the endpoint to unlock.
			'Cache-Control'      => 'no-store, max-age=0',
			'Vary'               => 'User-Agent, Authorization',
			'Server-Timing'      => $this->server_timing( $t0 ),
		);
	}

	/** @return array<string,mixed> */
	private function challenge_body( string $resource, string $price, string $reason ): array {
		return array(
			'error'    => 'payment_required',
			'message'  => 'This content is licensed to AI agents on a pay-per-request basis. Settle the micropayment, then retry with "Authorization: Bearer <token>".',
			'reason'   => $reason,
			'resource' => $resource,
			'license'  => array(
				'publisher' => get_bloginfo( 'name' ),
				'terms'     => 'Automated access by AI crawlers requires a per-request micropayment. Human readers and traditional search indexers are unaffected.',
				'llms_txt'  => home_url( '/llms.txt' ),
			),
			'price'    => array(
				'amount'   => $price,
				'currency' => 'USD',
			),
			'payment'  => array(
				'protocol'          => self::PROTOCOL,
				'endpoint'          => rest_url( AgentToll_Payment_Verifier::REST_NAMESPACE . AgentToll_Payment_Verifier::REST_ROUTE ),
				'method'            => 'POST',
				'body'              => array(
					'resource'      => $resource,
					'payment_proof' => '<settlement proof; ignored by the v0.1 mock>',
				),
				'token_ttl_seconds' => (int) AgentToll_Settings::get()['token_ttl'],
				'then'              => 'Retry the original request with header "Authorization: Bearer <token>".',
			),
		);
	}

	/**
	 * Path(+query) form of the verify endpoint, e.g. "/wp-json/agenttoll/v1/verify".
	 * Plain-permalink sites get "/?rest_route=/agenttoll/v1/verify".
	 */
	private function relative_endpoint(): string {
		$url   = rest_url( AgentToll_Payment_Verifier::REST_NAMESPACE . AgentToll_Payment_Verifier::REST_ROUTE );
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		return '' !== $query ? $path . '?' . $query : $path;
	}

	private function send_402_and_exit( string $resource, string $price, string $reason, float $t0 ): void {
		status_header( 402 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		foreach ( $this->challenge_headers( $resource, $price, $t0 ) as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo wp_json_encode( $this->challenge_body( $resource, $price, $reason ), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		// Flush to the client first; the deferred DB log then runs off the critical path.
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		exit;
	}

	private function mark_paid_response( float $t0 ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store' );
			header( 'Vary: User-Agent, Authorization' );
			header( 'X-Payment-Status: paid' );
			header( 'Server-Timing: ' . $this->server_timing( $t0 ) );
		}
	}

	private function server_timing( float $t0 ): string {
		return sprintf( 'agenttoll;dur=%.2f', ( microtime( true ) - $t0 ) * 1000 );
	}

	private function defer_log( string $ua, int $post_id, string $status ): void {
		add_action(
			'shutdown',
			function () use ( $ua, $post_id, $status ) {
				$this->logger->log( $ua, $post_id, $status );
			}
		);
	}

	/* ------------------------------------------------------------------ *
	 * /llms.txt (virtual)
	 * ------------------------------------------------------------------ */

	private function is_llms_txt_request(): bool {
		$uri = $_SERVER['REQUEST_URI'] ?? '';
		if ( ! is_string( $uri ) || false === stripos( $uri, 'llms.txt' ) || ! is_404() ) {
			return false;
		}
		$req  = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		$mine = untrailingslashit( (string) wp_parse_url( home_url( '/llms.txt' ), PHP_URL_PATH ) );
		return $req === $mine;
	}

	/** @param array<string,mixed> $settings Plugin settings. */
	private function serve_llms_txt( array $settings ): void {
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		$lines   = array();
		$lines[] = '# ' . get_bloginfo( 'name' );
		$lines[] = '';
		$lines[] = '> ' . get_bloginfo( 'description' );
		$lines[] = '';
		$lines[] = '## AI access policy';
		$lines[] = '';
		$lines[] = '- Human readers and traditional search indexers (Googlebot, Bingbot): free.';
		$lines[] = '- AI crawlers (e.g. ' . implode( ', ', array_slice( (array) $settings['blocked_uas'], 0, 5 ) ) . '): paid per request via HTTP 402.';
		$lines[] = '- Default price: ' . AgentToll_Settings::format_price( $settings['price'] ) . ' USD per post.';
		$lines[] = '- Protocol: ' . self::PROTOCOL . ' (pluggable; L402 / x402 / Interledger planned).';
		$lines[] = '- Payment endpoint: ' . rest_url( AgentToll_Payment_Verifier::REST_NAMESPACE . AgentToll_Payment_Verifier::REST_ROUTE );
		$lines[] = '- Flow: request a post -> receive 402 + X-Payment-* headers -> settle -> retry with "Authorization: Bearer <token>".';
		$lines[] = '';

		echo implode( "\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain.
		exit;
	}
}
