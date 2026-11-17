<?php
/**
 * Stateless HMAC-SHA256 token issuer/verifier + mock settlement endpoint.
 *
 * Token format:  base64url(JSON claims) . "." . base64url(HMAC-SHA256(payload_b64, secret))
 *
 * Claims: v (int), iss, iat, exp, res (resource id), amt (micro-USD paid), cur, jti.
 *
 * Verification needs only the site secret: no DB or network round-trips.
 *
 * To plug in a real rail (L402 / x402 / Interledger), hook the
 * `agenttoll_verify_settlement` filter and return true only after the payment
 * proof has been validated. Everything else (tokens, headers, logging) stays.
 *
 * @package AgentToll_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AgentToll_Payment_Verifier {

	public const REST_NAMESPACE      = 'agenttoll/v1';
	public const REST_ROUTE          = '/verify';
	public const COLLECTION_RESOURCE = 'rest:wp/v2/posts';
	private const MAX_TOKEN_LENGTH   = 1024;

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_verify' ),
				// Public by design: agents have no WP account. See README security notes.
				'permission_callback' => '__return_true',
				'args'                => array(
					'resource'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'payment_proof' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * POST /wp-json/agenttoll/v1/verify  (v0.1: mock settlement)
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_verify( WP_REST_Request $request ) {
		$settings = AgentToll_Settings::get();

		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'agenttoll_disabled', 'AgentToll is disabled.', array( 'status' => 503 ) );
		}
		if ( '' === $this->key() ) {
			return new WP_Error( 'agenttoll_no_key', 'Signing key is not configured.', array( 'status' => 500 ) );
		}

		$resource = (string) $request->get_param( 'resource' );
		$post_id  = $this->resolve_resource( $resource );
		if ( null === $post_id ) {
			return new WP_Error( 'agenttoll_bad_resource', 'Unknown or unprotected resource.', array( 'status' => 400 ) );
		}

		$price = AgentToll_Settings::price_for( $post_id );

		/**
		 * Settlement hook. v0.1 mock: always succeeds (payment_proof is ignored).
		 * Real implementations must validate the proof and return true/false.
		 *
		 * @param bool            $settled  Whether payment settled.
		 * @param WP_REST_Request $request  The verify request.
		 * @param string          $resource Resource id, e.g. "post:123".
		 * @param string          $price    Price in USD, decimal string.
		 */
		$settled = apply_filters( 'agenttoll_verify_settlement', true, $request, $resource, $price );
		if ( true !== $settled ) {
			return new WP_Error( 'agenttoll_settlement_failed', 'Payment could not be settled.', array( 'status' => 402 ) );
		}

		$issued   = $this->issue_token( $resource, AgentToll_Settings::price_micro( $price ) );
		$response = new WP_REST_Response(
			array(
				'token'      => $issued['token'],
				'token_type' => 'Bearer',
				'expires_in' => (int) $issued['claims']['exp'] - (int) $issued['claims']['iat'],
				'resource'   => $resource,
				'amount'     => $price,
				'currency'   => 'USD',
				'protocol'   => 'x402-mock',
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * @return array{token:string,claims:array<string,mixed>}
	 */
	public function issue_token( string $resource, int $amount_micro, ?int $ttl = null ): array {
		$now    = time();
		$ttl    = $ttl ?? (int) AgentToll_Settings::get()['token_ttl'];
		$claims = array(
			'v'   => 1,
			'iss' => home_url(),
			'iat' => $now,
			'exp' => $now + max( 30, $ttl ),
			'res' => $resource,
			'amt' => $amount_micro,
			'cur' => 'USD',
			'jti' => bin2hex( random_bytes( 8 ) ),
		);

		$payload = self::b64url_encode( (string) wp_json_encode( $claims ) );
		$sig     = self::b64url_encode( hash_hmac( 'sha256', $payload, $this->key(), true ) );

		return array(
			'token'  => $payload . '.' . $sig,
			'claims' => $claims,
		);
	}

	/**
	 * Stateless verification. Constant-time signature compare; pure CPU (~microseconds).
	 *
	 * @param string $token         Raw bearer token.
	 * @param string $resource      Resource the request is for, e.g. "post:123".
	 * @param int    $min_amount_micro Price the token must cover.
	 * @return array{ok:bool,reason:string}
	 */
	public function verify_token( string $token, string $resource, int $min_amount_micro ): array {
		$key = $this->key();
		if ( '' === $key ) {
			return $this->fail( 'no_signing_key' );
		}
		if ( '' === $token || strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
			return $this->fail( 'malformed_token' );
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return $this->fail( 'malformed_token' );
		}
		list( $payload, $sig ) = $parts;

		$expected = self::b64url_encode( hash_hmac( 'sha256', $payload, $key, true ) );
		if ( ! hash_equals( $expected, $sig ) ) {
			return $this->fail( 'bad_signature' );
		}

		$json   = self::b64url_decode( $payload );
		$claims = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $claims ) || 1 !== ( $claims['v'] ?? null ) ) {
			return $this->fail( 'bad_claims' );
		}

		$now = time();
		if ( ! isset( $claims['exp'], $claims['iat'] ) || (int) $claims['exp'] <= $now ) {
			return $this->fail( 'token_expired' );
		}
		if ( (int) $claims['iat'] > $now + 60 ) {
			return $this->fail( 'token_from_future' );
		}
		if ( ! isset( $claims['res'] ) || ! hash_equals( (string) $claims['res'], $resource ) ) {
			return $this->fail( 'resource_mismatch' );
		}
		if ( (int) ( $claims['amt'] ?? 0 ) < $min_amount_micro ) {
			return $this->fail( 'insufficient_amount' );
		}

		return array(
			'ok'     => true,
			'reason' => 'ok',
		);
	}

	/**
	 * Map a resource id to a protected, published post (0 = collection). Null if invalid.
	 */
	private function resolve_resource( string $resource ): ?int {
		if ( self::COLLECTION_RESOURCE === $resource ) {
			return 0;
		}
		if ( ! preg_match( '/^post:(\d{1,18})$/', $resource, $m ) ) {
			return null;
		}

		$post = get_post( (int) $m[1] );
		if (
			! $post
			|| 'publish' !== $post->post_status
			|| ! in_array( $post->post_type, AgentToll_Settings::protected_post_types(), true )
			|| AgentToll_Settings::is_exempt( $post->ID )
		) {
			return null;
		}
		return (int) $post->ID;
	}

	private function key(): string {
		$key = AgentToll_Settings::get()['secret_key'] ?? '';
		return ( is_string( $key ) && strlen( $key ) >= 32 ) ? $key : '';
	}

	/** @return array{ok:bool,reason:string} */
	private function fail( string $reason ): array {
		return array(
			'ok'     => false,
			'reason' => $reason,
		);
	}

	public static function b64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function b64url_decode( string $data ): string|false {
		return base64_decode( strtr( $data, '-_', '+/' ), true );
	}
}
