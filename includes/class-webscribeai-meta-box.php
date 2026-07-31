<?php
/**
 * Meta box for per-post SEO generation.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_Meta_Box
 */
class WebscribeAI_Meta_Box {

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'post_submitbox_misc_actions', [ $this, 'render_sidebar_action' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register the meta box on all public post types.
	 */
	public function register_meta_boxes(): void {
		$post_types = $this->get_supported_post_types();
		$has_tsf    = WebscribeAI_SEO::has_tsf();

		foreach ( $post_types as $post_type ) {
			if ( $has_tsf ) {
				continue;
			}

			add_meta_box(
				'webscribeai_seo_meta',
				__( 'WebscribeAI — SEO Meta', 'webscribeai' ),
				[ $this, 'render_meta_box' ],
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render a single-button sidebar action when TSF is active.
	 */
	public function render_sidebar_action(): void {
		if ( ! WebscribeAI_SEO::has_tsf() ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || empty( $screen->post_type ) ) {
			return;
		}

		$supported_post_types = $this->get_supported_post_types();
		if ( ! in_array( $screen->post_type, $supported_post_types, true ) ) {
			return;
		}

		$post_id = $this->get_current_post_id();
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$settings      = (array) get_option( 'webscribeai_settings', [] );
		$override      = ! empty( $settings['override_existing'] );
		$has_api_key   = ! empty( $settings['api_key'] );
		$limit_reached = ! WebscribeAI_Usage::has_remaining();
		?>
		<div class="misc-pub-section webscribeai-sidebar-action" id="webscribeai-sidebar-<?php echo esc_attr( $post_id ); ?>">
			<strong><?php esc_html_e( 'WebscribeAI', 'webscribeai' ); ?></strong>

			<?php if ( ! $has_api_key ) : ?>
				<p class="webscribeai-notice webscribeai-notice--warning">
					<?php
					printf(
						/* translators: %s: Settings page URL */
						esc_html__( 'No API key configured. Please add your key in %s.', 'webscribeai' ),
						sprintf(
							'<a href="%s">%s</a>',
							esc_url( admin_url( 'options-general.php?page=webscribeai' ) ),
							esc_html__( 'WebscribeAI Settings', 'webscribeai' )
						)
					);
					?>
				</p>
			<?php elseif ( $limit_reached ) : ?>
				<p class="webscribeai-notice webscribeai-notice--warning">
					<?php
					printf(
						/* translators: %d: monthly generation limit */
						esc_html__( 'Monthly AI generation limit reached (%d). Resets on the 1st of next month.', 'webscribeai' ),
						(int) WebscribeAI_Usage::get_limit()
					);
					?>
				</p>
			<?php endif; ?>

			<button
				type="button"
				class="button button-secondary webscribeai-sidebar-generate-btn"
				data-post-id="<?php echo esc_attr( $post_id ); ?>"
				<?php disabled( ! $has_api_key || $limit_reached ); ?>
			>
				<?php esc_html_e( 'Generate SEO Meta', 'webscribeai' ); ?>
			</button>

			<div class="webscribeai-sidebar-message" id="webscribeai-sidebar-message-<?php echo esc_attr( $post_id ); ?>" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Get all public post types that support the editor.
	 *
	 * @return string[]
	 */
	private function get_supported_post_types(): array {
		$post_types = get_post_types(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'names'
		);

		// Exclude attachments — they don't have editable content.
		unset( $post_types['attachment'] );

		/**
		 * Filter the post types that get the WebscribeAI meta box.
		 *
		 * @param string[] $post_types Array of post type slugs.
		 */
		return (array) apply_filters( 'webscribeai_supported_post_types', array_values( $post_types ) );
	}

	/**
	 * Get the current post ID on post edit screens.
	 *
	 * @return int
	 */
	private function get_current_post_id(): int {
		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		if ( isset( $_POST['post_ID'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return absint( $_POST['post_ID'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		$post = get_post();
		if ( $post instanceof WP_Post ) {
			return (int) $post->ID;
		}

		return 0;
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_meta_box( WP_Post $post ): void {
		$existing      = WebscribeAI_SEO::get_meta( $post->ID );
		$settings      = (array) get_option( 'webscribeai_settings', [] );
		$override      = ! empty( $settings['override_existing'] );
		$has_api_key   = ! empty( $settings['api_key'] );
		$limit_reached = ! WebscribeAI_Usage::has_remaining();

		wp_nonce_field( 'webscribeai_meta_box', 'webscribeai_nonce' );
		?>
		<div class="webscribeai-metabox" id="webscribeai-metabox-<?php echo esc_attr( $post->ID ); ?>">

			<?php if ( ! $has_api_key ) : ?>
				<p class="webscribeai-notice webscribeai-notice--warning">
					<?php
					printf(
						/* translators: %s: Settings page URL */
						esc_html__( 'No API key configured. Please add your key in %s.', 'webscribeai' ),
						sprintf(
							'<a href="%s">%s</a>',
							esc_url( admin_url( 'options-general.php?page=webscribeai' ) ),
							esc_html__( 'WebscribeAI Settings', 'webscribeai' )
						)
					);
					?>
				</p>
			<?php elseif ( $limit_reached ) : ?>
				<p class="webscribeai-notice webscribeai-notice--warning">
					<?php
					printf(
						/* translators: %d: monthly generation limit */
						esc_html__( 'Monthly AI generation limit reached (%d). Resets on the 1st of next month.', 'webscribeai' ),
						(int) WebscribeAI_Usage::get_limit()
					);
					?>
				</p>
			<?php endif; ?>

			<div class="webscribeai-current-meta">
				<p>
					<strong><?php esc_html_e( 'Current SEO Title:', 'webscribeai' ); ?></strong>
					<span class="webscribeai-current-title">
						<?php echo esc_html( $existing['title'] ?: __( '(not set)', 'webscribeai' ) ); ?>
					</span>
				</p>
				<p>
					<strong><?php esc_html_e( 'Current SEO Description:', 'webscribeai' ); ?></strong>
					<span class="webscribeai-current-desc">
						<?php echo esc_html( $existing['description'] ?: __( '(not set)', 'webscribeai' ) ); ?>
					</span>
				</p>
			</div>

			<div class="webscribeai-actions">
				<label class="webscribeai-override-label">
					<input
						type="checkbox"
						id="webscribeai-override-<?php echo esc_attr( $post->ID ); ?>"
						class="webscribeai-override-checkbox"
						<?php checked( $override ); ?>
					/>
					<?php esc_html_e( 'Override existing meta data', 'webscribeai' ); ?>
				</label>

				<button
					type="button"
					class="button button-primary webscribeai-generate-btn"
					data-post-id="<?php echo esc_attr( $post->ID ); ?>"
					<?php disabled( ! $has_api_key || $limit_reached ); ?>
				>
					<?php esc_html_e( 'Generate SEO Meta', 'webscribeai' ); ?>
				</button>
			</div>

			<div class="webscribeai-result" id="webscribeai-result-<?php echo esc_attr( $post->ID ); ?>" style="display:none;">
				<div class="webscribeai-result-inner">
					<p>
						<label>
							<strong><?php esc_html_e( 'Generated Title:', 'webscribeai' ); ?></strong>
							<input
								type="text"
								class="webscribeai-gen-title widefat"
								readonly
							/>
						</label>
					</p>
					<p>
						<label>
							<strong><?php esc_html_e( 'Generated Description:', 'webscribeai' ); ?></strong>
							<textarea
								class="webscribeai-gen-desc widefat"
								rows="3"
								readonly
							></textarea>
						</label>
					</p>
					<div class="webscribeai-result-notice">
						<button
							type="button"
							class="button button-secondary webscribeai-apply-btn"
							data-post-id="<?php echo esc_attr( $post->ID ); ?>"
						>
							<?php esc_html_e( 'Apply Meta Data', 'webscribeai' ); ?>
						</button>
						<button
							type="button"
							class="button webscribeai-discard-btn"
						>
							<?php esc_html_e( 'Discard', 'webscribeai' ); ?>
						</button>
					</div>
				</div>
			</div>

			<div class="webscribeai-spinner" id="webscribeai-spinner-<?php echo esc_attr( $post->ID ); ?>" style="display:none;">
				<span class="spinner is-active"></span>
				<?php esc_html_e( 'Generating SEO meta data…', 'webscribeai' ); ?>
			</div>

			<div class="webscribeai-message" id="webscribeai-message-<?php echo esc_attr( $post->ID ); ?>" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue meta box scripts and styles on post edit screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$settings = (array) get_option( 'webscribeai_settings', [] );

		$css_file = WEBSCRIBEAI_DIR . 'assets/css/metabox.css';
		$js_file  = WEBSCRIBEAI_DIR . 'assets/js/metabox.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : WEBSCRIBEAI_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : WEBSCRIBEAI_VERSION;

		wp_enqueue_style(
			'webscribeai-metabox',
			WEBSCRIBEAI_URL . 'assets/css/metabox.css',
			[],
			$css_ver
		);

		wp_enqueue_script(
			'webscribeai-metabox',
			WEBSCRIBEAI_URL . 'assets/js/metabox.js',
			[ 'jquery' ],
			$js_ver,
			true
		);

		wp_localize_script(
			'webscribeai-metabox',
			'webscribeaiMetaBox',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'webscribeai_ajax' ),
				'hasTsf'  => WebscribeAI_SEO::has_tsf(),
				'override' => ! empty( $settings['override_existing'] ),
				'currentPostId' => $this->get_current_post_id(),
				'hasApiKey' => ! empty( $settings['api_key'] ),
				'provider' => $settings['api_provider'] ?? 'anthropic',
				'model' => $settings['ai_model'] ?? '',
				'i18n'    => [
					'generating'     => __( 'Generating…', 'webscribeai' ),
					'applying'       => __( 'Applying…', 'webscribeai' ),
					'applied'        => __( 'Meta data applied successfully.', 'webscribeai' ),
					'appliedSidebar' => __( 'SEO meta generated and written to The SEO Framework fields.', 'webscribeai' ),
					'skipped'        => __( 'Skipped — existing meta data preserved. Enable "Override existing" to replace it.', 'webscribeai' ),
					'invalidPost'    => __( 'Unable to determine the current post. Please save/reload and try again.', 'webscribeai' ),
					'error'          => __( 'An error occurred. Please try again.', 'webscribeai' ),
					'generate'       => __( 'Generate SEO Meta', 'webscribeai' ),
					'confirmApply'   => __( 'This will overwrite the existing SEO meta data. Continue?', 'webscribeai' ),
				],
			]
		);
	}
}
