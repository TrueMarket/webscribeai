<?php
/**
 * Plugin Name:       WebscribeAI
 * Plugin URI:        https://en-ca.wordpress.org/plugins/webscribeai/
 * Description:       Automatically populate SEO meta data using AI. Integrates with The SEO Framework and works out of the box.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            True Market
 * Author URI:        https://truemarket.ca
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       webscribeai
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'WEBSCRIBEAI_VERSION', '0.2.0' );
define( 'WEBSCRIBEAI_FILE', __FILE__ );
define( 'WEBSCRIBEAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WEBSCRIBEAI_URL', plugin_dir_url( __FILE__ ) );
define( 'WEBSCRIBEAI_BASENAME', plugin_basename( __FILE__ ) );

/**
 * GitHub repository the update checker reads releases from.
 */
define( 'WEBSCRIBEAI_GITHUB_REPO', 'https://github.com/truemarket/webscribeai/' );

/**
 * Main plugin class.
 */
final class WebscribeAI {

	/**
	 * Single instance of the plugin.
	 *
	 * @var WebscribeAI|null
	 */
	private static ?WebscribeAI $instance = null;

	/**
	 * Returns the single plugin instance.
	 *
	 * @return WebscribeAI
	 */
	public static function instance(): WebscribeAI {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — load all plugin components.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_updater();
		$this->init_hooks();
	}

	/**
	 * Load required files.
	 */
	private function load_dependencies(): void {
		require_once WEBSCRIBEAI_DIR . 'includes/class-webscribeai-api.php';
		require_once WEBSCRIBEAI_DIR . 'includes/class-webscribeai-seo.php';
		require_once WEBSCRIBEAI_DIR . 'includes/class-webscribeai-meta-box.php';
		require_once WEBSCRIBEAI_DIR . 'admin/class-webscribeai-admin.php';
		require_once WEBSCRIBEAI_DIR . 'admin/class-webscribeai-ajax.php';
	}

	/**
	 * Set up automatic updates from the GitHub repository.
	 *
	 * Uses the Plugin Update Checker library (Yahnis Elsts) to compare the
	 * installed version against the latest GitHub release/tag and surface
	 * updates on the WordPress Plugins screen.
	 */
	private function init_updater(): void {
		$library = WEBSCRIBEAI_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

		if ( ! file_exists( $library ) ) {
			return;
		}

		require_once $library;

		$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			WEBSCRIBEAI_GITHUB_REPO,
			WEBSCRIBEAI_FILE,
			'webscribeai'
		);

		// The branch that holds the stable, production-ready code. On the default
		// branch, PUC prefers GitHub releases/tags over raw branch commits.
		$update_checker->setBranch( 'main' );

		// If you attach a built .zip to each GitHub release (rather than letting
		// PUC use the auto-generated source archive), uncomment the next line so
		// updates download that asset instead:
		// $update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
	}

	/**
	 * Register plugin hooks.
	 */
	private function init_hooks(): void {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
		add_action( 'init', [ $this, 'init' ] );

		register_activation_hook( WEBSCRIBEAI_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( WEBSCRIBEAI_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Initialise plugin components.
	 */
	public function init(): void {
		new WebscribeAI_Meta_Box();

		if ( is_admin() ) {
			new WebscribeAI_Admin();
			new WebscribeAI_Ajax();
		}
	}

	/**
	 * Load plugin text domain.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'webscribeai',
			false,
			dirname( WEBSCRIBEAI_BASENAME ) . '/languages'
		);
	}

	/**
	 * Plugin activation.
	 */
	public function activate(): void {
		// Set default options if they don't exist.
		if ( ! get_option( 'webscribeai_settings' ) ) {
			update_option( 'webscribeai_settings', [
				'api_provider'    => 'anthropic',
				'api_key'         => '',
				'ai_model'        => '',
				'override_existing'  => 0,
				'keywords_primary'   => '',
				'keywords_secondary' => '',
			] );
		}
	}

	/**
	 * Plugin deactivation.
	 */
	public function deactivate(): void {
		// Nothing to do on deactivation.
	}
}

/**
 * Returns the main plugin instance.
 *
 * @return WebscribeAI
 */
function webscribeai(): WebscribeAI {
	return WebscribeAI::instance();
}

webscribeai();
