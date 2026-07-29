<?php
/**
 * AI API abstraction layer.
 *
 * Supports Anthropic (Claude) and OpenAI (GPT) providers.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_API
 */
class WebscribeAI_API {

	/**
	 * API provider: 'anthropic' or 'openai'.
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * AI model identifier.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Comma-separated primary keywords from settings.
	 *
	 * @var string
	 */
	private string $keywords_primary;

	/**
	 * Comma-separated secondary keywords from settings.
	 *
	 * @var string
	 */
	private string $keywords_secondary;

	/**
	 * Anthropic API endpoint.
	 */
	const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * OpenAI API endpoint.
	 */
	const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Default models per provider.
	 */
	const DEFAULT_MODELS = [
		'anthropic' => 'claude-opus-4-8',
		'openai'    => 'gpt-5.4-mini',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings        = (array) get_option( 'webscribeai_settings', [] );
		$this->provider  = $settings['api_provider'] ?? 'anthropic';
		$this->api_key   = $settings['api_key'] ?? '';
		$this->model     = ! empty( $settings['ai_model'] )
			? $settings['ai_model']
			: ( self::DEFAULT_MODELS[ $this->provider ] ?? '' );
		$this->keywords_primary   = $settings['keywords_primary'] ?? '';
		$this->keywords_secondary = $settings['keywords_secondary'] ?? '';
	}

	/**
	 * Generate SEO meta data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{title: string, description: string}|WP_Error
	 */
	public function generate_seo_meta( int $post_id ): array|WP_Error {
		if ( empty( $this->api_key ) ) {
			return new WP_Error(
				'webscribeai_no_api_key',
				__( 'No API key configured. Please add your API key in the WebscribeAI settings.', 'webscribeai' )
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'webscribeai_invalid_post',
				__( 'Invalid post ID.', 'webscribeai' )
			);
		}

		$content = $this->prepare_post_content( $post );

		if ( empty( trim( $content ) ) ) {
			return new WP_Error(
				'webscribeai_no_content',
				__( 'This post has no content or excerpt to generate SEO meta data from.', 'webscribeai' )
			);
		}

		$prompt = $this->build_prompt( $post->post_title, $content, $this->build_title_format_instructions(), $this->keywords_primary, $this->keywords_secondary );

		return 'anthropic' === $this->provider
			? $this->call_anthropic( $prompt )
			: $this->call_openai( $prompt );
	}

	/**
	 * Prepare post content for the AI prompt.
	 *
	 * Combines standard post_content with any text found in ACF fields
	 * (WYSIWYG, text, textarea, flexible content, repeater).
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	private function prepare_post_content( WP_Post $post ): string {
		$parts = [];

		// Standard post content.
		$post_content = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( do_shortcode( $post->post_content ) ) ) );
		if ( '' !== $post_content ) {
			$parts[] = $post_content;
		}

		// ACF field content (requires ACF plugin to be active).
		if ( function_exists( 'get_field_objects' ) ) {
			$acf_text = $this->extract_acf_content( $post->ID );
			if ( '' !== $acf_text ) {
				$parts[] = $acf_text;
			}
		}

		// Post excerpt — used as a fallback when body content is sparse or absent.
		if ( '' !== trim( $post->post_excerpt ) ) {
			$excerpt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $post->post_excerpt ) ) );
			if ( '' !== $excerpt && ! in_array( $excerpt, $parts, true ) ) {
				$parts[] = $excerpt;
			}
		}

		$content = implode( ' ', $parts );

		// Limit to ~3000 words to avoid excessive token usage.
		$words = explode( ' ', $content );
		if ( count( $words ) > 3000 ) {
			$words   = array_slice( $words, 0, 3000 );
			$content = implode( ' ', $words );
		}

		return trim( $content );
	}

	/**
	 * Extract text content from all ACF fields attached to a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function extract_acf_content( int $post_id ): string {
		$fields = get_field_objects( $post_id );
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return '';
		}

		$parts = [];
		foreach ( $fields as $field ) {
			$text = $this->extract_acf_field_text( $field );
			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Recursively extract plain text from a single ACF field object.
	 *
	 * Handles: text, textarea, wysiwyg, flexible_content, repeater.
	 * Other field types (image, file, relationship, etc.) are skipped.
	 *
	 * @param array $field ACF field object (must contain 'type' and 'value' keys).
	 * @return string
	 */
	private function extract_acf_field_text( array $field ): string {
		$type  = $field['type'] ?? '';
		$value = $field['value'] ?? null;

		if ( null === $value || '' === $value || false === $value ) {
			return '';
		}

		switch ( $type ) {
			case 'text':
			case 'textarea':
				return trim( wp_strip_all_tags( (string) $value ) );

			case 'wysiwyg':
				return trim( wp_strip_all_tags( (string) $value ) );

			case 'flexible_content':
				if ( ! is_array( $value ) ) {
					return '';
				}
				// Build a map of layout name → sub-field definitions.
				$layout_map = [];
				foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) {
					$layout_map[ $layout['name'] ] = $layout['sub_fields'] ?? [];
				}

				$parts = [];
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$layout_name = $row['acf_fc_layout'] ?? '';
					$sub_fields  = $layout_map[ $layout_name ] ?? [];

					foreach ( $sub_fields as $sub_field ) {
						$sub_field['value'] = $row[ $sub_field['name'] ] ?? null;
						$text               = $this->extract_acf_field_text( $sub_field );
						if ( '' !== $text ) {
							$parts[] = $text;
						}
					}
				}
				return implode( ' ', $parts );

			case 'repeater':
				if ( ! is_array( $value ) ) {
					return '';
				}
				$sub_fields = $field['sub_fields'] ?? [];
				$parts      = [];
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( $sub_fields as $sub_field ) {
						$sub_field['value'] = $row[ $sub_field['name'] ] ?? null;
						$text               = $this->extract_acf_field_text( $sub_field );
						if ( '' !== $text ) {
							$parts[] = $text;
						}
					}
				}
				return implode( ' ', $parts );

			default:
				return '';
		}
	}

	/**
	 * Description opening styles used to ensure variety across generations.
	 *
	 * One is chosen at random per prompt so batch-generated descriptions
	 * don't all begin with the same phrase (e.g. "Discover how…").
	 */
	const DESCRIPTION_OPENING_STYLES = [
		'Lead with the key benefit or outcome the reader will get.',
		'Open with a strong action verb that puts the reader in motion (e.g. Master, Build, Boost, Get, Create, Transform).',
		'Open with the main topic directly, then explain why it matters.',
		'Frame the description around a specific detail, feature, or aspect of the content.',
		'Address the reader\'s goal or pain point, then state how this page helps.',
	];

	/**
	 * Build the AI prompt.
	 *
	 * @param string $title                     Post title.
	 * @param string $content                   Post content.
	 * @param string $title_format_instructions Additional title format instructions.
	 * @param string $keywords_primary          Comma-separated primary keywords (optional).
	 * @param string $keywords_secondary        Comma-separated secondary keywords (optional).
	 * @return string
	 */
	private function build_prompt( string $title, string $content, string $title_format_instructions = '', string $keywords_primary = '', string $keywords_secondary = '' ): string {
		$keywords_rule = '';
		if ( '' !== trim( $keywords_primary ) ) {
			$keywords_rule .= sprintf(
				"\n- Primary keywords: Prioritise at least one of these (exact form or a natural synonym/variation) in both the title and description: %s.",
				trim( $keywords_primary )
			);
		}
		if ( '' !== trim( $keywords_secondary ) ) {
			$keywords_rule .= sprintf(
				"\n- Secondary keywords: Weave these in naturally where they fit, after addressing the primary keywords (exact form or synonyms/variations are fine): %s.",
				trim( $keywords_secondary )
			);
		}

		$styles       = self::DESCRIPTION_OPENING_STYLES;
		$opening_style = $styles[ array_rand( $styles ) ];

		return sprintf(
			'You are an SEO expert. Given the following page title and content, generate an SEO-optimised meta title and meta description.

Rules:
- Meta title: 50–60 characters, engaging, includes the main keyword.
- Meta description: 120–160 characters, compelling summary that encourages clicks, includes the main keyword naturally.
- Title format preferences:%s%s
- Description opening style: %s
- Do NOT open the description with generic phrases like "Discover", "Learn how", "Find out", "Are you", "In this", "This page", or "Welcome".
- Do NOT use quotation marks inside the values.
- Respond ONLY with valid JSON in this exact format, no other text:
{"title": "Your meta title here", "description": "Your meta description here"}

Page Title: %s

Page Content:

%s',
			$title_format_instructions,
			$keywords_rule,
			$opening_style,
			$title,
			$content
		);
	}

	/**
	 * Build optional title formatting instructions from plugin and TSF settings.
	 *
	 * @return string
	 */
	private function build_title_format_instructions(): string {
		$prefs = WebscribeAI_SEO::get_tsf_title_preferences();

		$separator  = trim( (string) ( $prefs['separator'] ?? '|' ) );
		$site_title = trim( (string) ( $prefs['site_title'] ?? get_bloginfo( 'name' ) ) );
		$include    = $prefs['include_site_title'] ?? null;

		$separator_instruction = sprintf( ' Use "%s" as the title separator.', $separator ?: '|' );

		if ( true === $include && '' !== $site_title ) {
			return $separator_instruction . sprintf( ' Always end the meta title with " %s %s" (the site name).', $separator ?: '|', $site_title );
		}

		if ( false === $include ) {
			return $separator_instruction . ' Do not include the site title in the meta title.';
		}

		return $separator_instruction . ' Keep the title focused on the page topic.';
	}

	/**
	 * Call the Anthropic API.
	 *
	 * @param string $prompt Prompt text.
	 * @return array{title: string, description: string}|WP_Error
	 */
	private function call_anthropic( string $prompt ): array|WP_Error {
		$response = wp_remote_post(
			self::ANTHROPIC_ENDPOINT,
			[
				'timeout' => 60,
				'headers' => [
					'x-api-key'         => $this->api_key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				],
				// 2048 (rather than a tight ~256) leaves headroom for models that
				// always emit extended thinking (e.g. Claude Fable 5) — thinking
				// tokens are drawn from the same max_tokens budget as the reply.
				'body'    => wp_json_encode( [
					'model'      => $this->model,
					'max_tokens' => 2048,
					'messages'   => [
						[
							'role'    => 'user',
							'content' => $prompt,
						],
					],
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$message = $body['error']['message'] ?? __( 'Unknown Anthropic API error.', 'webscribeai' );
			return new WP_Error( 'webscribeai_api_error', $message );
		}

		$stop_reason = $body['stop_reason'] ?? '';

		if ( 'refusal' === $stop_reason ) {
			return new WP_Error(
				'webscribeai_refusal',
				__( 'Claude declined to generate SEO meta data for this content (safety filter). Try a different post, or switch models in WebscribeAI settings.', 'webscribeai' )
			);
		}

		// Response content is a list of typed blocks (thinking, text, ...);
		// find the text block rather than assuming it's first — models with
		// extended thinking on by default (e.g. Claude Fable 5) put one or
		// more thinking blocks ahead of it.
		$text = '';
		foreach ( (array) ( $body['content'] ?? [] ) as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text = $block['text'] ?? '';
				break;
			}
		}

		if ( '' === trim( $text ) && 'max_tokens' === $stop_reason ) {
			return new WP_Error(
				'webscribeai_truncated',
				__( 'The AI response was cut off before any text was produced, likely because the model spent its full token budget on internal reasoning. Try again, or switch to a different model in WebscribeAI settings.', 'webscribeai' )
			);
		}

		return $this->parse_json_response( $text );
	}

	/**
	 * Call the OpenAI API.
	 *
	 * @param string $prompt Prompt text.
	 * @return array{title: string, description: string}|WP_Error
	 */
	private function call_openai( string $prompt ): array|WP_Error {
		$response = wp_remote_post(
			self::OPENAI_ENDPOINT,
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				],
				// GPT-5 series are reasoning models: they take
				// max_completion_tokens (not max_tokens), don't support
				// temperature, and draw reasoning tokens from the same
				// budget as the reply, so 2048 (rather than a tight ~256)
				// leaves headroom for the model to still produce output.
				'body'    => wp_json_encode( [
					'model'                 => $this->model,
					'max_completion_tokens' => 2048,
					'reasoning_effort'      => 'low',
					'messages'              => [
						[
							'role'    => 'user',
							'content' => $prompt,
						],
					],
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code ) {
			$message = $body['error']['message'] ?? __( 'Unknown OpenAI API error.', 'webscribeai' );
			return new WP_Error( 'webscribeai_api_error', $message );
		}

		$text          = $body['choices'][0]['message']['content'] ?? '';
		$finish_reason = $body['choices'][0]['finish_reason'] ?? '';

		if ( '' === trim( $text ) && 'length' === $finish_reason ) {
			return new WP_Error(
				'webscribeai_truncated',
				__( 'The AI response was cut off before any text was produced, likely because the model spent its full token budget on internal reasoning. Try again, or switch to a different model in WebscribeAI settings.', 'webscribeai' )
			);
		}

		return $this->parse_json_response( $text );
	}

	/**
	 * Parse the JSON response from the AI.
	 *
	 * @param string $text Raw AI response text.
	 * @return array{title: string, description: string}|WP_Error
	 */
	private function parse_json_response( string $text ): array|WP_Error {
		// Strip markdown code fences if present.
		$text = preg_replace( '/^```(?:json)?\s*/i', '', trim( $text ) );
		$text = preg_replace( '/\s*```$/', '', $text );

		$data = json_decode( trim( $text ), true );

		if ( ! is_array( $data ) || empty( $data['title'] ) || empty( $data['description'] ) ) {
			return new WP_Error(
				'webscribeai_parse_error',
				__( 'The AI returned an unexpected response format. Please try again.', 'webscribeai' )
			);
		}

		return [
			'title'       => sanitize_text_field( $data['title'] ),
			'description' => sanitize_textarea_field( $data['description'] ),
		];
	}

	/**
	 * Return available models for each provider.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_available_models(): array {
		return [
			'anthropic' => [
				'claude-opus-4-8'          => 'Claude Opus 4.8 (Recommended)',
				'claude-sonnet-4-6'        => 'Claude Sonnet 4.6 (Balanced)',
				'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (Fastest)',
				'claude-fable-5'           => 'Claude Fable 5 (Most Capable)',
			],
			'openai' => [
				'gpt-5.4-mini' => 'GPT-5.4 Mini (Recommended)',
				'gpt-5.4'      => 'GPT-5.4 (Balanced)',
				'gpt-5.4-nano' => 'GPT-5.4 Nano (Fastest)',
				'gpt-5.5'      => 'GPT-5.5 (Most Capable)',
			],
		];
	}
}
