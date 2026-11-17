<?php
/**
 * Plugin Name:       AgentToll WP
 * Plugin URI:        https://github.com/your-username/agenttoll-wp
 * Description:       HTTP 402 micropayment middleware for WordPress. Detects AI crawlers, blocks free scraping, and requires a payment token before serving post content.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            AgentToll contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agenttoll-wp
 *
 * @package AgentToll_WP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AGENTTOLL_VERSION', '0.1.0' );
define( 'AGENTTOLL_FILE', __FILE__ );
define( 'AGENTTOLL_DIR', plugin_dir_path( __FILE__ ) );

if ( PHP_VERSION_ID < 80100 ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'AgentToll WP requires PHP 8.1 or newer.', 'agenttoll-wp' )
				. '</p></div>';
		}
	);
	return;
}

require_once AGENTTOLL_DIR . 'includes/class-settings.php';
require_once AGENTTOLL_DIR . 'includes/class-logger.php';
require_once AGENTTOLL_DIR . 'includes/class-payment-verifier.php';
require_once AGENTTOLL_DIR . 'includes/class-interceptor.php';

if ( is_admin() ) {
	require_once AGENTTOLL_DIR . 'includes/class-admin.php';
}

/**
 * Plugin orchestrator.
 */
final class AgentToll_Plugin {

	public static function boot(): void {
		$logger   = new AgentToll_Logger();
		$verifier = new AgentToll_Payment_Verifier();

		$verifier->register();
		( new AgentToll_Interceptor( $verifier, $logger ) )->register();

		add_action( 'update_option_' . AgentToll_Settings::OPTION, array( AgentToll_Settings::class, 'flush_cache' ) );

		if ( is_admin() ) {
			( new AgentToll_Admin( $logger ) )->register();
		}
	}

	public static function activate(): void {
		AgentToll_Settings::install_defaults();
		AgentToll_Logger::install();
	}
}

add_action( 'plugins_loaded', array( 'AgentToll_Plugin', 'boot' ) );
register_activation_hook( __FILE__, array( 'AgentToll_Plugin', 'activate' ) );
