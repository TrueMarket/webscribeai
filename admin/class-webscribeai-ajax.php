<?php
/**
 * AJAX request handlers.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_Ajax
 */
class WebscribeAI_Ajax {

	/**
	 * Constructor — register AJAX hooks.
	 */
	public function __construct() {
		// Per-post generation (meta box).
		add_action( 'wp_ajax_webscribeai_generate', [ $this, 'handle_generate' ] );

		// Apply generated meta data to a post.
		add_action( 'wp_ajax_webscribeai_apply', [ $this, 'handle_apply' ] );

		// Bulk generation (settings page).
		add_action( 'wp_ajax_webscribeai_bulk_generate', [ $this, 'handle_bulk_generate' ] );

		// Bulk removal of a single SEO meta field (settings page).
		add_action( 'wp_ajax_webscribeai_bulk_remove', [ $this, 'handle_bulk_remove' ] );

		// Bulk Populate table: one page of rows, and every ID matching a filter.
		add_action( 'wp_ajax_webscribeai_bulk_list', [ $this, 'handle_bulk_list' ] );
		add_action( 'wp_ajax_webscribeai_bulk_ids',  [ $this, 'handle_bulk_ids' ] );

		// Persist / clear the resumable bulk queue.
		add_action( 'wp_ajax_webscribeai_save_queue',  [ $this, 'handle_save_queue' ] );
		add_action( 'wp_ajax_webscribeai_clear_queue', [ $this, 'handle_clear_queue' ] );

		// Persist the errors from the last bulk generation run, so they can
		// be displayed once more after the completion modal reloads the page.
		add_action( 'wp_ajax_webscribeai_save_errors', [ $this, 'handle_save_errors' ] );
	}

	// -------------------------------------------------------------------------
	// Per-post handlers
	// -------------------------------------------------------------------------

	/**
	 * Generate SEO meta data for a single post (AJAX).
	 */
	public function handle_generate(): void {
		$this->verify_nonce();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'webscribeai' ),
					'debug'   => $this->get_debug_context( $post_id ),
				]
			);
		}

		$api    = new WebscribeAI_API();
		$result = $api->generate_seo_meta( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				[
					'message' => $result->get_error_message(),
					'debug'   => $this->get_debug_context( $post_id ),
				]
			);
		}

		wp_send_json_success(
			array_merge(
				$result,
				[
					'debug' => $this->get_debug_context( $post_id ),
				]
			)
		);
	}

	/**
	 * Apply generated meta data to a post (AJAX).
	 *
	 * This is called after the user reviews and confirms the generated data.
	 */
	public function handle_apply(): void {
		$this->verify_nonce();

		$post_id     = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
		$raw_override = $_POST['override'] ?? false;
		$override     = $this->request_to_bool( $raw_override );

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Permission denied.', 'webscribeai' ),
					'debug'   => $this->get_debug_context( $post_id, $override, $raw_override ),
				]
			);
		}

		$saved = WebscribeAI_SEO::save_meta( $post_id, $title, $description, $override );

		if ( ! $saved ) {
			wp_send_json_success( [
				'skipped' => true,
				'message' => __( 'Skipped — existing meta data preserved. Enable "Override existing" to replace it.', 'webscribeai' ),
				'debug'   => $this->get_debug_context( $post_id, $override, $raw_override ),
				'snapshot' => $this->get_meta_snapshot( $post_id ),
			] );
		}

		wp_send_json_success( [
			'skipped' => false,
			'message' => __( 'SEO meta data saved successfully.', 'webscribeai' ),
			'debug'   => $this->get_debug_context( $post_id, $override, $raw_override ),
			'snapshot' => $this->get_meta_snapshot( $post_id ),
		] );
	}

	// -------------------------------------------------------------------------
	// Bulk handler
	// -------------------------------------------------------------------------

	/**
	 * Generate and immediately save SEO meta for a single post in bulk mode.
	 *
	 * Called once per post from the JS bulk loop.
	 */
	public function handle_bulk_generate(): void {
		$this->verify_nonce();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'webscribeai' ) ] );
		}

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$override = $this->request_to_bool( $_POST['override'] ?? false );
		$field    = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'both';

		if ( ! in_array( $field, [ 'both', 'title', 'description' ], true ) ) {
			$field = 'both';
		}

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid post ID.', 'webscribeai' ) ] );
		}

		// Determine which of the requested field(s) actually need (re)generating.
		// A field that already has a value is left untouched unless overriding —
		// a post missing only one of title/description still gets that field filled in.
		$needs_title       = ( 'description' !== $field )
			&& ( $override || ! WebscribeAI_SEO::has_existing_meta_field( $post_id, 'title' ) );
		$needs_description = ( 'title' !== $field )
			&& ( $override || ! WebscribeAI_SEO::has_existing_meta_field( $post_id, 'description' ) );

		if ( ! $needs_title && ! $needs_description ) {
			wp_send_json_success( [
				'status'     => 'skipped',
				'post_id'    => $post_id,
				'post_title' => get_the_title( $post_id ),
				'message'    => __( 'Skipped — meta data already exists.', 'webscribeai' ),
				'usage'      => $this->get_usage_payload(),
			] );
		}

		$api    = new WebscribeAI_API();
		$result = $api->generate_seo_meta( $post_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [
				'post_id'       => $post_id,
				'post_title'    => get_the_title( $post_id ),
				'message'       => $result->get_error_message(),
				'limit_reached' => 'webscribeai_limit_reached' === $result->get_error_code(),
				'usage'         => $this->get_usage_payload(),
			] );
		}

		// Save immediately in bulk mode — only the field(s) that needed it.
		$saved_title       = null;
		$saved_description = null;

		if ( $needs_title ) {
			WebscribeAI_SEO::save_meta_field( $post_id, 'title', $result['title'], true );
			$saved_title = $result['title'];
		}

		if ( $needs_description ) {
			WebscribeAI_SEO::save_meta_field( $post_id, 'description', $result['description'], true );
			$saved_description = $result['description'];
		}

		wp_send_json_success( [
			'status'      => 'generated',
			'post_id'     => $post_id,
			'post_title'  => get_the_title( $post_id ),
			'field'       => $field,
			'title'       => $saved_title,
			'description' => $saved_description,
			'message'     => __( 'Generated and saved.', 'webscribeai' ),
			'usage'       => $this->get_usage_payload(),
		] );
	}

	/**
	 * Return one page of Bulk Populate table rows (AJAX).
	 *
	 * With `include`, returns exactly those posts in that order (the rows of
	 * a running job); otherwise applies the post type / view / search filters
	 * and also returns the per-view counts.
	 */
	public function handle_bulk_list(): void {
		$this->verify_nonce();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'webscribeai' ) ] );
		}

		$args   = WebscribeAI_Bulk_Table::parse_args( wp_unslash( $_POST ) );
		$result = WebscribeAI_Bulk_Table::query( $args );

		wp_send_json_success( [
			'html'    => WebscribeAI_Bulk_Table::render_rows( $result['rows'] ),
			'total'   => $result['total'],
			'counts'  => empty( $args['include'] ) ? WebscribeAI_Bulk_Table::counts( $args ) : null,
			'perPage' => WebscribeAI_Bulk_Table::PER_PAGE,
		] );
	}

	/**
	 * Return the ID and title of every post matching the filters (AJAX).
	 *
	 * Used for "Select all matching" and "Generate all in this view". With
	 * `needs_meta`, only posts missing a title or description are returned.
	 */
	public function handle_bulk_ids(): void {
		$this->verify_nonce();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'webscribeai' ) ] );
		}

		$args       = WebscribeAI_Bulk_Table::parse_args( wp_unslash( $_POST ) );
		$needs_meta = $this->request_to_bool( $_POST['needs_meta'] ?? false );

		wp_send_json_success( [
			'items' => WebscribeAI_Bulk_Table::get_ids( $args, $needs_meta ),
		] );
	}

	/**
	 * Remove a single SEO meta field (title or description) for a post in bulk mode.
	 *
	 * Called once per post from the JS bulk loop.
	 */
	public function handle_bulk_remove(): void {
		$this->verify_nonce();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'webscribeai' ) ] );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$field   = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';

		if ( ! $post_id || ! in_array( $field, [ 'title', 'description', 'all' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'webscribeai' ) ] );
		}

		WebscribeAI_SEO::delete_meta_field( $post_id, $field );

		wp_send_json_success( [
			'post_id' => $post_id,
			'field'   => $field,
			'message' => __( 'Removed.', 'webscribeai' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Resumable queue handlers
	// -------------------------------------------------------------------------

	/**
	 * Save the pending bulk queue so the run can be resumed after navigation.
	 *
	 * Stores { pending, override, saved_at } in wp_options.
	 * Not autoloaded — only fetched on the bulk tab.
	 */
	public function handle_save_queue(): void {
		$this->verify_nonce();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'webscribeai' ) ] );
		}

		$pending  = isset( $_POST['pending'] )
			? array_values( array_filter( array_map( 'absint', (array) $_POST['pending'] ) ) )
			: [];
		$override = $this->request_to_bool( $_POST['override'] ?? false );
		$field    = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : 'both';

		if ( ! in_array( $field, [ 'both', 'title', 'description' ], true ) ) {
			$field = 'both';
		}

		update_option(
			'webscribeai_bulk_queue',
			[
				'pending'  => $pending,
				'override' => $override,
				'field'    => $field,
				'saved_at' => time(),
			],
			false // not autoloaded
		);

		wp_send_json_success();
	}

	/**
	 * Delete the saved bulk queue (called when a run completes or is dismissed).
	 */
	public function handle_clear_queue(): void {
		$this->verify_nonce();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'webscribeai' ) ] );
		}

		delete_option( 'webscribeai_bulk_queue' );

		wp_send_json_success();
	}

	/**
	 * Persist the failed rows from a just-finished bulk generation run.
	 *
	 * Backs the Bulk Populate "Errors" view and the failed-row highlighting
	 * (see WebscribeAI_Bulk_Table) until the next run replaces the list. An
	 * empty list clears any previously stored errors.
	 */
	public function handle_save_errors(): void {
		$this->verify_nonce();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'webscribeai' ) ] );
		}

		$raw_errors = isset( $_POST['errors'] ) ? (array) wp_unslash( $_POST['errors'] ) : [];
		$errors     = [];

		foreach ( $raw_errors as $entry ) {
			$post_id = isset( $entry['post_id'] ) ? absint( $entry['post_id'] ) : 0;
			$message = isset( $entry['message'] ) ? sanitize_text_field( $entry['message'] ) : '';

			if ( $post_id ) {
				$errors[ $post_id ] = $message;
			}
		}

		if ( empty( $errors ) ) {
			delete_option( 'webscribeai_bulk_errors' );
		} else {
			update_option(
				'webscribeai_bulk_errors',
				[
					'errors'   => $errors,
					'saved_at' => time(),
				],
				false // not autoloaded
			);
		}

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Verify the AJAX nonce and die on failure.
	 */
	private function verify_nonce(): void {
		if ( ! check_ajax_referer( 'webscribeai_ajax', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'webscribeai' ) ] );
		}
	}

	/**
	 * Current monthly usage, returned with each bulk generation so the page
	 * can update its usage count without a reload.
	 *
	 * @return array{count: int, limit: int}
	 */
	private function get_usage_payload(): array {
		return [
			'count' => WebscribeAI_Usage::get_count(),
			'limit' => WebscribeAI_Usage::get_limit(),
		];
	}

	/**
	 * Parse a request value into a boolean.
	 *
	 * Accepts common truthy values from form and AJAX payloads.
	 *
	 * @param mixed $value Request value.
	 * @return bool
	 */
	private function request_to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value === 1;
		}

		if ( is_string( $value ) ) {
			return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes', 'on' ], true );
		}

		return false;
	}

	/**
	 * Build safe debug context for AJAX troubleshooting.
	 *
	 * @param int        $post_id      Target post ID.
	 * @param bool|null  $override     Parsed override value.
	 * @param mixed|null $raw_override Raw request override value.
	 * @return array<string, mixed>
	 */
	private function get_debug_context( int $post_id, ?bool $override = null, $raw_override = null ): array {
		$settings = (array) get_option( 'webscribeai_settings', [] );

		return [
			'post_id'         => $post_id,
			'post_exists'     => (bool) get_post( $post_id ),
			'tsf_detected'    => WebscribeAI_SEO::has_tsf(),
			'api_provider'    => $settings['api_provider'] ?? 'anthropic',
			'ai_model'        => $settings['ai_model'] ?? '',
			'api_key_present' => ! empty( $settings['api_key'] ),
			'override'        => $override,
			'override_raw'    => $raw_override,
		];
	}

	/**
	 * Return a compact post-save snapshot of relevant SEO meta keys.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function get_meta_snapshot( int $post_id ): array {
		$all_meta = (array) get_post_meta( $post_id );
		$focus    = [];

		foreach ( $all_meta as $key => $values ) {
			if ( 0 === strpos( (string) $key, '_genesis_' ) || 0 === strpos( (string) $key, '_tsf_' ) ) {
				$focus[ $key ] = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
			}
		}

		return [
			'_genesis_title'             => (string) get_post_meta( $post_id, '_genesis_title', true ),
			'_genesis_description'       => (string) get_post_meta( $post_id, '_genesis_description', true ),
			'_webscribeai_seo_title'     => (string) get_post_meta( $post_id, '_webscribeai_seo_title', true ),
			'_webscribeai_seo_description' => (string) get_post_meta( $post_id, '_webscribeai_seo_description', true ),
			'focus_meta'                 => $focus,
		];
	}
}
