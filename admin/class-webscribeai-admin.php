<?php
/**
 * Admin settings page.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_Admin
 */
class WebscribeAI_Admin {

	/**
	 * Option name for plugin settings.
	 */
	const OPTION_NAME = 'webscribeai_settings';

	/**
	 * SEO title/description length guidelines used by the bulk populate validity bar.
	 */
	const SEO_TITLE_LIMIT      = 60;
	const SEO_TITLE_WARN_LIMIT = 70;
	const SEO_DESC_LIMIT       = 160;
	const SEO_DESC_WARN_LIMIT  = 180;

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . WEBSCRIBEAI_BASENAME, [ $this, 'add_settings_link' ] );
	}

	/**
	 * Register admin menu page.
	 */
	public function register_menu(): void {
		add_management_page(
			__( 'WebscribeAI Settings', 'webscribeai' ),
			__( 'WebscribeAI', 'webscribeai' ),
			'manage_options',
			'webscribeai',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register plugin settings with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			'webscribeai_settings_group',
			self::OPTION_NAME,
			[
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			]
		);

		// --- API Configuration Section ---
		add_settings_section(
			'webscribeai_api_section',
			__( 'API Configuration', 'webscribeai' ),
			[ $this, 'render_api_section_description' ],
			'webscribeai'
		);

		add_settings_field(
			'api_provider',
			__( 'AI Provider', 'webscribeai' ),
			[ $this, 'render_field_provider' ],
			'webscribeai',
			'webscribeai_api_section'
		);

		add_settings_field(
			'api_key',
			__( 'API Key', 'webscribeai' ),
			[ $this, 'render_field_api_key' ],
			'webscribeai',
			'webscribeai_api_section'
		);

		add_settings_field(
			'ai_model',
			__( 'AI Model', 'webscribeai' ),
			[ $this, 'render_field_model' ],
			'webscribeai',
			'webscribeai_api_section'
		);

		// --- Behaviour Section ---
		add_settings_section(
			'webscribeai_behaviour_section',
			__( 'Behaviour', 'webscribeai' ),
			'__return_null',
			'webscribeai'
		);

		add_settings_field(
			'override_existing',
			__( 'Override Existing Meta', 'webscribeai' ),
			[ $this, 'render_field_override' ],
			'webscribeai',
			'webscribeai_behaviour_section'
		);

		add_settings_field(
			'include_site_title',
			__( 'Include Site Title', 'webscribeai' ),
			[ $this, 'render_field_include_site_title' ],
			'webscribeai',
			'webscribeai_behaviour_section'
		);

		add_settings_field(
			'keywords_primary',
			__( 'Primary Keywords', 'webscribeai' ),
			[ $this, 'render_field_keywords_primary' ],
			'webscribeai',
			'webscribeai_behaviour_section'
		);

		add_settings_field(
			'keywords_secondary',
			__( 'Secondary Keywords', 'webscribeai' ),
			[ $this, 'render_field_keywords_secondary' ],
			'webscribeai',
			'webscribeai_behaviour_section'
		);
	}

	/**
	 * Sanitize settings before saving.
	 *
	 * @param array $input Raw input array.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$allowed_providers = [ 'anthropic', 'openai' ];
		$provider          = in_array( $input['api_provider'] ?? '', $allowed_providers, true )
			? $input['api_provider']
			: 'anthropic';

		$allowed_models = array_keys( WebscribeAI_API::get_available_models()[ $provider ] ?? [] );
		$model          = in_array( $input['ai_model'] ?? '', $allowed_models, true )
			? $input['ai_model']
			: '';

		$raw_key  = sanitize_text_field( $input['api_key'] ?? '' );
		$sentinel = sanitize_text_field( $input['api_key_sentinel'] ?? '' );
		if ( '' !== $sentinel && $raw_key === $sentinel ) {
			// Submitted unchanged (the masked stand-in came back as-is) — keep the saved key.
			$existing = (array) get_option( self::OPTION_NAME, [] );
			$api_key  = $existing['api_key'] ?? '';
		} else {
			// The user selected and deleted the masked value (now blank — remove the key),
			// or replaced it with a new one (encrypt and store it).
			$api_key = '' !== $raw_key ? WebscribeAI_Encryption::encrypt( $raw_key ) : '';
		}

		return [
			'api_provider'       => $provider,
			'api_key'            => $api_key,
			'ai_model'           => $model,
			'override_existing'  => ! empty( $input['override_existing'] ) ? 1 : 0,
			'include_site_title' => ! empty( $input['include_site_title'] ) ? 1 : 0,
			'keywords_primary'   => sanitize_textarea_field( $input['keywords_primary'] ?? '' ),
			'keywords_secondary' => sanitize_textarea_field( $input['keywords_secondary'] ?? '' ),
		];
	}

	/**
	 * Render the full settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap webscribeai-wrap">
			<h1>
				<?php esc_html_e( 'WebscribeAI', 'webscribeai' ); ?>
			</h1>

			<nav class="nav-tab-wrapper">
				<a
					href="<?php echo esc_url( admin_url( 'tools.php?page=webscribeai&tab=settings' ) ); ?>"
					class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'Settings', 'webscribeai' ); ?>
				</a>
				<a
					href="<?php echo esc_url( admin_url( 'tools.php?page=webscribeai&tab=bulk' ) ); ?>"
					class="nav-tab <?php echo 'bulk' === $active_tab ? 'nav-tab-active' : ''; ?>"
				>
					<?php esc_html_e( 'Bulk Populate', 'webscribeai' ); ?>
				</a>
			</nav>

			<div class="webscribeai-tab-content">
				<?php $this->render_usage_summary(); ?>
				<?php if ( 'settings' === $active_tab ) : ?>
					<?php $this->render_settings_tab(); ?>
				<?php else : ?>
					<?php $this->render_bulk_tab(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the current month's usage against the monthly generation cap.
	 */
	private function render_usage_summary(): void {
		$count = WebscribeAI_Usage::get_count();
		$limit = WebscribeAI_Usage::get_limit();
		$class = $count >= $limit ? 'notice-warning' : 'notice-info';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> inline webscribeai-usage-summary">
			<p>
				<?php
				printf(
					/* translators: 1: generations used this month, 2: monthly limit */
					esc_html__( 'AI generations used this month: %1$d / %2$d. Resets on the 1st of next month.', 'webscribeai' ),
					(int) $count,
					(int) $limit
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Settings tab.
	 */
	private function render_settings_tab(): void {
		?>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'webscribeai_settings_group' );
			do_settings_sections( 'webscribeai' );
			submit_button( __( 'Save Settings', 'webscribeai' ) );
			?>
		</form>
		<?php
	}

	/**
	 * Render the Bulk Populate tab.
	 */
	private function render_bulk_tab(): void {
		$settings      = (array) get_option( self::OPTION_NAME, [] );
		$has_api_key   = ! empty( $settings['api_key'] );
		$limit_reached = ! WebscribeAI_Usage::has_remaining();
		$can_generate  = $has_api_key && ! $limit_reached;
		$post_types    = $this->get_bulk_post_types();
		$all_posts     = $this->get_all_posts_grouped( $post_types );
		?>
		<div class="webscribeai-bulk-wrap">

			<?php if ( ! $has_api_key ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: settings tab link */
							esc_html__( 'Please configure your API key in the %s tab before running a bulk populate.', 'webscribeai' ),
							'<a href="' . esc_url( admin_url( 'tools.php?page=webscribeai&tab=settings' ) ) . '">'
							. esc_html__( 'Settings', 'webscribeai' ) . '</a>'
						);
						?>
					</p>
				</div>
			<?php elseif ( $limit_reached ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %d: monthly generation limit */
							esc_html__( 'Monthly AI generation limit reached (%d). Bulk generation is unavailable until the limit resets on the 1st of next month.', 'webscribeai' ),
							(int) WebscribeAI_Usage::get_limit()
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="webscribeai-bulk-options">
				<label class="webscribeai-override-label">
					<input
						type="checkbox"
						id="webscribeai-bulk-override"
						<?php checked( ! empty( $settings['override_existing'] ) ); ?>
					/>
					<?php esc_html_e( 'Override existing meta data', 'webscribeai' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'When unchecked, posts that already have SEO meta data will be skipped.', 'webscribeai' ); ?>
				</p>
			</div>

			<div class="webscribeai-bulk-select-bar">
				<button type="button" class="button" id="webscribeai-select-all">
					<?php esc_html_e( 'Select All', 'webscribeai' ); ?>
				</button>
				<button type="button" class="button" id="webscribeai-deselect-all">
					<?php esc_html_e( 'Deselect All', 'webscribeai' ); ?>
				</button>
				<div class="webscribeai-bulk-actions-group">
					<label class="screen-reader-text" for="webscribeai-bulk-action-select">
						<?php esc_html_e( 'Select bulk action', 'webscribeai' ); ?>
					</label>
					<select id="webscribeai-bulk-action-select">
						<option value=""><?php esc_html_e( 'Bulk actions', 'webscribeai' ); ?></option>
						<option value="generate_meta" <?php selected( $can_generate ); ?> <?php disabled( ! $can_generate ); ?>>
							<?php esc_html_e( 'Generate SEO Meta (Title + Description)', 'webscribeai' ); ?>
						</option>
						<option value="generate_title" <?php disabled( ! $can_generate ); ?>>
							<?php esc_html_e( 'Generate SEO Title', 'webscribeai' ); ?>
						</option>
						<option value="generate_description" <?php disabled( ! $can_generate ); ?>>
							<?php esc_html_e( 'Generate SEO Description', 'webscribeai' ); ?>
						</option>
						<option value="remove_title">
							<?php esc_html_e( 'Remove SEO Title', 'webscribeai' ); ?>
						</option>
						<option value="remove_description">
							<?php esc_html_e( 'Remove SEO Description', 'webscribeai' ); ?>
						</option>
						<option value="remove_all">
							<?php esc_html_e( 'Remove All SEO Meta', 'webscribeai' ); ?>
						</option>
					</select>
					<button type="button" class="button" id="webscribeai-bulk-apply" <?php disabled( ! $has_api_key ); ?>>
						<?php esc_html_e( 'Apply', 'webscribeai' ); ?>
					</button>
				</div>

				<button
					type="button"
					class="button"
					id="webscribeai-bulk-run-all"
					<?php disabled( ! $can_generate ); ?>
				>
					<?php esc_html_e( 'Generate All', 'webscribeai' ); ?>
				</button>
			</div>

			<div id="webscribeai-bulk-progress" style="display:none;">
				<div class="webscribeai-progress-bar-wrap">
					<div class="webscribeai-progress-bar" id="webscribeai-progress-fill"></div>
				</div>
				<p id="webscribeai-progress-label"></p>
				<button type="button" class="button" id="webscribeai-stop-btn">
					<?php esc_html_e( 'Stop', 'webscribeai' ); ?>
				</button>
			</div>

			<div id="webscribeai-bulk-results" style="display:none;"></div>

			<div id="webscribeai-bulk-modal-overlay" class="webscribeai-modal-overlay" style="display:none;">
				<div class="webscribeai-modal" role="dialog" aria-modal="true" aria-labelledby="webscribeai-modal-title">
					<div class="webscribeai-modal-icon" id="webscribeai-modal-icon" aria-hidden="true"></div>
					<h2 class="webscribeai-modal-title" id="webscribeai-modal-title"></h2>
					<div class="webscribeai-modal-stats" id="webscribeai-modal-stats"></div>
					<p class="webscribeai-modal-note" id="webscribeai-modal-note"></p>
					<button type="button" class="button button-primary button-hero" id="webscribeai-modal-ok-btn"></button>
				</div>
			</div>

			<?php if ( empty( $all_posts ) ) : ?>
				<p><?php esc_html_e( 'No posts or pages found.', 'webscribeai' ); ?></p>
			<?php else : ?>
				<div class="webscribeai-post-list">
					<?php foreach ( $all_posts as $post_type_label => $posts ) : ?>
						<div class="webscribeai-post-group">
							<h3 class="webscribeai-post-group-title">
									<?php echo esc_html( $post_type_label ); ?>
									<span class="webscribeai-count">(<?php echo count( $posts ); ?>)</span>
								</h3>
								<table class="widefat striped webscribeai-post-table">
									<colgroup>
										<col class="col-check" />
										<col class="col-title" />
										<col class="col-status" />
										<col class="col-seo-title" />
										<col class="col-seo-desc" />
										<col class="col-result" />
										<col class="col-actions" />
									</colgroup>
									<thead>
										<tr>
											<th class="check-column">
												<input
													type="checkbox"
													class="webscribeai-group-toggle"
													data-group="<?php echo esc_attr( sanitize_title( $post_type_label ) ); ?>"
													title="<?php esc_attr_e( 'Select all in this group', 'webscribeai' ); ?>"
												/>
											</th>
											<th><?php esc_html_e( 'Title', 'webscribeai' ); ?></th>
											<th><?php esc_html_e( 'Status', 'webscribeai' ); ?></th>
											<th><?php esc_html_e( 'SEO Title', 'webscribeai' ); ?></th>
											<th><?php esc_html_e( 'SEO Description', 'webscribeai' ); ?></th>
											<th><?php esc_html_e( 'Result', 'webscribeai' ); ?></th>
											<th></th>
										</tr>
								</thead>
								<tbody>
									<?php foreach ( $posts as $post ) : ?>
										<?php $existing = WebscribeAI_SEO::get_meta( $post->ID ); ?>
										<tr
											class="webscribeai-post-row"
											id="webscribeai-row-<?php echo esc_attr( $post->ID ); ?>"
											data-group="<?php echo esc_attr( sanitize_title( $post_type_label ) ); ?>"
										>
											<td class="check-column">
												<input
													type="checkbox"
													class="webscribeai-post-checkbox"
													value="<?php echo esc_attr( $post->ID ); ?>"
													data-has-title="<?php echo WebscribeAI_SEO::has_existing_meta_field( $post->ID, 'title' ) ? '1' : '0'; ?>"
													data-has-description="<?php echo WebscribeAI_SEO::has_existing_meta_field( $post->ID, 'description' ) ? '1' : '0'; ?>"
												/>
											</td>
											<td>
												<a
													href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"
													target="_blank"
												>
													<?php echo esc_html( $post->post_title ?: __( '(no title)', 'webscribeai' ) ); ?>
												</a>
											</td>
											<td><?php echo esc_html( get_post_status_object( $post->post_status )->label ?? $post->post_status ); ?></td>
											<td class="webscribeai-cell-title">
												<span class="webscribeai-meta-text"><?php echo esc_html( $existing['title'] ); ?></span>
												<?php $this->render_validity_bar( $existing['title'], self::SEO_TITLE_LIMIT, self::SEO_TITLE_WARN_LIMIT ); ?>
											</td>
											<td class="webscribeai-cell-desc">
												<span class="webscribeai-meta-text"><?php echo esc_html( $existing['description'] ); ?></span>
												<?php $this->render_validity_bar( $existing['description'], self::SEO_DESC_LIMIT, self::SEO_DESC_WARN_LIMIT ); ?>
											</td>
											<td class="webscribeai-cell-result" id="webscribeai-result-<?php echo esc_attr( $post->ID ); ?>"></td>
											<td class="webscribeai-cell-actions">
												<button
													type="button"
													class="button-link webscribeai-edit-btn"
													data-post-id="<?php echo esc_attr( $post->ID ); ?>"
													title="<?php esc_attr_e( 'Edit meta', 'webscribeai' ); ?>"
												><span class="dashicons dashicons-edit"></span></button>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Output a small colour-coded bar showing how a title/description length
	 * compares to the recommended SEO character limit.
	 */
	private function render_validity_bar( string $text, int $limit, int $warn_limit ): void {
		$length = mb_strlen( $text );

		if ( 0 === $length ) {
			echo '<div class="webscribeai-vbar is-empty"><div class="webscribeai-vbar-fill"></div></div>';
			return;
		}

		if ( $length <= $limit ) {
			$state = 'is-good';
		} elseif ( $length <= $warn_limit ) {
			$state = 'is-warn';
		} else {
			$state = 'is-bad';
		}

		printf(
			'<div class="webscribeai-vbar %1$s" title="%2$s"><div class="webscribeai-vbar-fill" style="width:%3$s%%"></div></div>',
			esc_attr( $state ),
			esc_attr(
				sprintf(
					/* translators: 1: current character count, 2: recommended character limit */
					__( '%1$d / %2$d characters', 'webscribeai' ),
					$length,
					$limit
				)
			),
			esc_attr( (string) min( 100, round( $length / $limit * 100, 2 ) ) )
		);
	}

	// -------------------------------------------------------------------------
	// Settings field renderers
	// -------------------------------------------------------------------------

	/**
	 * Render API section description.
	 */
	public function render_api_section_description(): void {
		echo '<p>' . esc_html__( 'Connect WebscribeAI to an AI provider to generate SEO meta data.', 'webscribeai' ) . '</p>';
	}

	/**
	 * Render AI provider field.
	 */
	public function render_field_provider(): void {
		$settings = (array) get_option( self::OPTION_NAME, [] );
		$current  = $settings['api_provider'] ?? 'anthropic';
		?>
		<fieldset>
			<label>
				<input
					type="radio"
					name="webscribeai_settings[api_provider]"
					value="anthropic"
					<?php checked( $current, 'anthropic' ); ?>
				/>
				<?php esc_html_e( 'Anthropic (Claude) — Recommended', 'webscribeai' ); ?>
			</label>
			<br />
			<label>
				<input
					type="radio"
					name="webscribeai_settings[api_provider]"
					value="openai"
					<?php checked( $current, 'openai' ); ?>
				/>
				<?php esc_html_e( 'OpenAI (GPT)', 'webscribeai' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php esc_html_e( 'Anthropic\'s Claude models excel at precise, structured content generation — ideal for SEO copy.', 'webscribeai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render API key field.
	 */
	public function render_field_api_key(): void {
		$settings  = (array) get_option( self::OPTION_NAME, [] );
		$stored    = $settings['api_key'] ?? '';
		$has_key   = ! empty( $stored );
		$decrypted = $has_key ? WebscribeAI_Encryption::decrypt( $stored ) : '';
		// A masked stand-in for the real key: real enough to select and delete like normal
		// text, but never the actual secret. Submitting it back unchanged means "keep the
		// saved key"; clearing it means "remove it"; typing something else means "replace it".
		$sentinel = $has_key ? str_repeat( '•', 20 ) . substr( $decrypted, -4 ) : '';
		?>
		<input
			type="password"
			id="webscribeai_api_key"
			name="webscribeai_settings[api_key]"
			value="<?php echo esc_attr( $sentinel ); ?>"
			class="regular-text"
			autocomplete="off"
		/>
		<?php if ( $has_key ) : ?>
			<input type="hidden" name="webscribeai_settings[api_key_sentinel]" value="<?php echo esc_attr( $sentinel ); ?>" />
		<?php endif; ?>
		<p class="description">
			<?php
			printf(
				/* translators: %1$s: Anthropic console URL, %2$s: OpenAI platform URL */
				esc_html__( 'Get your key from the %1$s (Anthropic) or %2$s (OpenAI).', 'webscribeai' ),
				'<a href="https://console.anthropic.com/account/keys" target="_blank" rel="noopener noreferrer">Anthropic Console</a>',
				'<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">OpenAI Platform</a>'
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render AI model select field.
	 */
	public function render_field_model(): void {
		$settings = (array) get_option( self::OPTION_NAME, [] );
		$provider = $settings['api_provider'] ?? 'anthropic';
		$current  = $settings['ai_model'] ?? '';
		$models   = WebscribeAI_API::get_available_models();
		?>
		<select id="webscribeai_ai_model" name="webscribeai_settings[ai_model]">
			<?php foreach ( $models as $provider_key => $provider_models ) : ?>
				<optgroup
					label="<?php echo esc_attr( ucfirst( $provider_key ) ); ?>"
					data-provider="<?php echo esc_attr( $provider_key ); ?>"
				>
					<?php foreach ( $provider_models as $model_key => $model_label ) : ?>
						<option
							value="<?php echo esc_attr( $model_key ); ?>"
							<?php selected( $current, $model_key ); ?>
							data-provider="<?php echo esc_attr( $provider_key ); ?>"
						>
							<?php echo esc_html( $model_label ); ?>
						</option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Leave on default to use the recommended model for your chosen provider.', 'webscribeai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render override existing meta field.
	 */
	public function render_field_override(): void {
		$settings = (array) get_option( self::OPTION_NAME, [] );
		$checked  = ! empty( $settings['override_existing'] );
		?>
		<label>
			<input
				type="checkbox"
				name="webscribeai_settings[override_existing]"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<?php esc_html_e( 'Allow AI-generated meta data to replace existing SEO meta data', 'webscribeai' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When unchecked, posts/pages that already have a SEO title or description will not be modified.', 'webscribeai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render include site title field.
	 */
	public function render_field_include_site_title(): void {
		$settings   = (array) get_option( self::OPTION_NAME, [] );
		$checked    = ! empty( $settings['include_site_title'] );
		$site_title = get_bloginfo( 'name' );
		?>
		<label>
			<input
				type="checkbox"
				name="webscribeai_settings[include_site_title]"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<?php esc_html_e( 'Append the site name to generated meta titles', 'webscribeai' ); ?>
		</label>
		<p class="description">
			<?php
			printf(
				/* translators: %s: site title */
				esc_html__( 'When enabled, AI-generated titles will always end with " | %s". Overrides any conflicting SEO plugin setting.', 'webscribeai' ),
				esc_html( $site_title )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render primary keywords field.
	 */
	public function render_field_keywords_primary(): void {
		$settings = (array) get_option( self::OPTION_NAME, [] );
		$keywords = $settings['keywords_primary'] ?? '';
		?>
		<textarea
			id="webscribeai_keywords_primary"
			name="webscribeai_settings[keywords_primary]"
			rows="2"
			class="large-text"
		><?php echo esc_textarea( $keywords ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Comma-separated. The AI will prioritise at least one of these (or a synonym/variation) in both the title and description.', 'webscribeai' ); ?>
		</p>
		<?php
	}

	/**
	 * Render secondary keywords field.
	 */
	public function render_field_keywords_secondary(): void {
		$settings = (array) get_option( self::OPTION_NAME, [] );
		$keywords = $settings['keywords_secondary'] ?? '';
		?>
		<textarea
			id="webscribeai_keywords_secondary"
			name="webscribeai_settings[keywords_secondary]"
			rows="2"
			class="large-text"
		><?php echo esc_textarea( $keywords ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Comma-separated. These will be woven in naturally where they fit, after primary keywords are addressed.', 'webscribeai' ); ?>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Get all public post types for bulk operations.
	 *
	 * @return WP_Post_Type[]
	 */
	private function get_bulk_post_types(): array {
		$post_types = get_post_types(
			[
				'public'  => true,
				'show_ui' => true,
			],
			'objects'
		);
		unset( $post_types['attachment'] );
		return $post_types;
	}

	/**
	 * Get all posts grouped by post type label.
	 *
	 * @param WP_Post_Type[] $post_types Post type objects.
	 * @return array<string, WP_Post[]>
	 */
	private function get_all_posts_grouped( array $post_types ): array {
		$grouped = [];

		foreach ( $post_types as $post_type ) {
			$posts = get_posts( [
				'post_type'      => $post_type->name,
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			] );

			if ( ! empty( $posts ) ) {
				$grouped[ $post_type->labels->name ] = $posts;
			}
		}

		return $grouped;
	}

	/**
	 * Add a Settings link to the plugins list table.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_settings_link( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=webscribeai' ) ),
			esc_html__( 'Settings', 'webscribeai' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Enqueue admin styles and scripts on the plugin's settings page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'tools_page_webscribeai' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'webscribeai-admin',
			WEBSCRIBEAI_URL . 'assets/css/admin.css',
			[],
			WEBSCRIBEAI_VERSION
		);

		wp_enqueue_script(
			'webscribeai-admin',
			WEBSCRIBEAI_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			WEBSCRIBEAI_VERSION,
			true
		);

		$settings    = (array) get_option( self::OPTION_NAME, [] );
		$has_api_key = ! empty( $settings['api_key'] );

		$saved_queue     = get_option( 'webscribeai_bulk_queue', null );
		$saved_queue_cfg = null;
		if ( is_array( $saved_queue ) && ! empty( $saved_queue['pending'] ) ) {
			$saved_queue_cfg = [
				'pending'  => array_map( 'intval', (array) $saved_queue['pending'] ),
				'override' => (bool) $saved_queue['override'],
				'field'    => (string) ( $saved_queue['field'] ?? 'both' ),
				'savedAt'  => (int) $saved_queue['saved_at'],
			];
		}

		// Flash-read the errors from the last bulk run: shown once on this
		// load, then discarded, so a later unrelated page load doesn't keep
		// resurfacing a stale failure list.
		$saved_errors     = get_option( 'webscribeai_bulk_errors', null );
		$last_errors_cfg  = null;
		if ( is_array( $saved_errors ) && ! empty( $saved_errors['errors'] ) ) {
			$last_errors_cfg = $saved_errors['errors']; // post_id => error message.
			delete_option( 'webscribeai_bulk_errors' );
		}

		wp_localize_script(
			'webscribeai-admin',
			'webscribeaiAdmin',
			[
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'webscribeai_ajax' ),
				'models'     => WebscribeAI_API::get_available_models(),
				'savedQueue' => $saved_queue_cfg,
				'lastErrors' => $last_errors_cfg,
				'hasApiKey'  => $has_api_key,
				'seoLimits'  => [
					'title'           => self::SEO_TITLE_LIMIT,
					'titleWarn'       => self::SEO_TITLE_WARN_LIMIT,
					'description'     => self::SEO_DESC_LIMIT,
					'descriptionWarn' => self::SEO_DESC_WARN_LIMIT,
				],
				'i18n'       => [
					'processing'    => __( 'Processing…', 'webscribeai' ),
					'done'          => __( 'Done', 'webscribeai' ),
					'success'       => __( 'Generated', 'webscribeai' ),
					'skipped'       => __( 'Skipped (existing)', 'webscribeai' ),
					'error'         => __( 'Error', 'webscribeai' ),
					'bulkComplete'  => __( 'Bulk generation complete!', 'webscribeai' ),
					'noSelection'   => __( 'Please select at least one post.', 'webscribeai' ),
					'confirmBulk'   => __( 'Generate SEO meta data for the selected posts? This may take a while depending on the number of posts.', 'webscribeai' ),
					'confirmAll'    => __( 'Generate SEO meta data for ALL posts and pages? This will process every post in the list and may take a while.', 'webscribeai' ),
					'confirmGenerateTitle'       => __( 'Generate SEO titles for the selected posts? This may take a while depending on the number of posts.', 'webscribeai' ),
					'confirmGenerateDescription' => __( 'Generate SEO descriptions for the selected posts? This may take a while depending on the number of posts.', 'webscribeai' ),
					'confirmRemoveTitle'       => __( 'Remove the SEO title for the selected posts? This cannot be undone.', 'webscribeai' ),
					'confirmRemoveDescription' => __( 'Remove the SEO description for the selected posts? This cannot be undone.', 'webscribeai' ),
					'confirmRemoveAll'         => __( 'Remove ALL SEO meta data (title and description) for the selected posts? This cannot be undone.', 'webscribeai' ),
					'chooseAction'  => __( 'Please choose a bulk action.', 'webscribeai' ),
					'removed'       => __( 'Removed', 'webscribeai' ),
					'bulkRemoveComplete' => __( 'Bulk removal complete!', 'webscribeai' ),
					'bulkRemoveStopped'  => __( 'Bulk removal stopped.', 'webscribeai' ),
					'edit'          => __( 'Edit meta', 'webscribeai' ),
					'save'          => __( 'Save', 'webscribeai' ),
					'cancel'        => __( 'Cancel', 'webscribeai' ),
					'saving'        => __( 'Saving…', 'webscribeai' ),
					'saved'         => __( 'Saved', 'webscribeai' ),
					'editError'     => __( 'Save failed', 'webscribeai' ),
					'resumeNotice'  => __( 'You have an unfinished bulk job.', 'webscribeai' ),
					'resumePosts'   => __( 'posts remaining from your last session.', 'webscribeai' ),
					'resumeBtn'     => __( 'Resume', 'webscribeai' ),
					'dismissBtn'    => __( 'Dismiss', 'webscribeai' ),
					'bulkStopped'   => __( 'Bulk generation stopped.', 'webscribeai' ),
					'pagePrev'      => __( 'Prev', 'webscribeai' ),
					'pageNext'      => __( 'Next', 'webscribeai' ),
					'characters'    => __( 'characters', 'webscribeai' ),
					'modalGenerated'    => __( 'Generated', 'webscribeai' ),
					'modalSkipped'      => __( 'Skipped', 'webscribeai' ),
					'modalErrors'       => __( 'Errors', 'webscribeai' ),
					'modalOk'           => __( 'OK', 'webscribeai' ),
					'modalRefreshNote'  => __( 'The page will refresh to update this month\'s usage count.', 'webscribeai' ),
					'modalErrorsNote'   => __( 'Rows that failed will stay highlighted below so you can find and retry them.', 'webscribeai' ),
					'lastErrorsCount'   => __( 'posts failed to generate on your last run. The affected rows are highlighted below.', 'webscribeai' ),
				],
				'perPage'    => 20,
			]
		);
	}
}
