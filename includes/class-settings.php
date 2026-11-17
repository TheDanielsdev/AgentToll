<?php
/**
 * Settings access + pricing helpers (shared by interceptor, verifier, admin).
 *
 * @package AgentToll_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AgentToll_Settings {

	public const OPTION = 'agenttoll_settings';

	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'       => 1,
			'price'         => '0.005',
			'currency'      => 'USD',
			'blocked_uas'   => array(
				'GPTBot',
				'ChatGPT-User',
				'OAI-SearchBot',
				'ClaudeBot',
				'Claude-Web',
				'anthropic-ai',
				'CCBot',
				'PerplexityBot',
				'Google-Extended',
				'Bytespider',
				'Amazonbot',
				'Applebot-Extended',
				'cohere-ai',
				'Diffbot',
			),
			'whitelist_uas' => array(
				'archive.org_bot',
			),
			'secret_key'    => '',
			'token_ttl'     => 300,
		);
	}

	/**
	 * Cached for the request: one (autoloaded) option read, zero extra queries.
	 *
	 * @return array<string,mixed>
	 */
	public static function get(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	public static function generate_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	public static function install_defaults(): void {
		if ( false === get_option( self::OPTION, false ) ) {
			$defaults               = self::defaults();
			$defaults['secret_key'] = self::generate_secret();
			add_option( self::OPTION, $defaults, '', true );
		}
	}

	/** Normalise a price to a trimmed decimal string, e.g. "0.005". */
	public static function format_price( $price ): string {
		$str = number_format( max( 0.0, (float) $price ), 6, '.', '' );
		$str = rtrim( rtrim( $str, '0' ), '.' );
		return '' === $str ? '0' : $str;
	}

	/** Price in millionths of a currency unit (integer-safe comparisons). */
	public static function price_micro( $price ): int {
		return (int) round( (float) $price * 1000000 );
	}

	/**
	 * @return string[]
	 */
	public static function protected_post_types(): array {
		return (array) apply_filters( 'agenttoll_protected_post_types', array( 'post' ) );
	}

	/**
	 * Per-post price: `_agenttoll_price` meta overrides the global default.
	 * Pass 0 for collection endpoints (default price).
	 */
	public static function price_for( int $post_id ): string {
		$price = self::get()['price'];

		if ( $post_id > 0 ) {
			$override = get_post_meta( $post_id, '_agenttoll_price', true );
			if ( '' !== $override && is_numeric( $override ) && (float) $override >= 0 ) {
				$price = $override;
			}
		}

		return self::format_price( apply_filters( 'agenttoll_price', $price, $post_id ) );
	}

	/** Per-post opt-out: set `_agenttoll_exempt` meta to 1 (or use the filter). */
	public static function is_exempt( int $post_id ): bool {
		$exempt = $post_id > 0 && (bool) get_post_meta( $post_id, '_agenttoll_exempt', true );
		return (bool) apply_filters( 'agenttoll_is_exempt', $exempt, $post_id );
	}
}
