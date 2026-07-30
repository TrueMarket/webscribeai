<?php
/**
 * SEO integration layer.
 *
 * Handles writing meta data to The SEO Framework or standalone
 * WordPress meta fields, and reading existing values.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_SEO
 */
class WebscribeAI_SEO {

	/**
	 * Common TSF separator slugs mapped to visible characters.
	 *
	 * @var array<string, string>
	 */
	const TSF_SEPARATOR_MAP = [
		'bar'           => '|',
		'pipe'          => '|',
		'broken'        => '|',
		'dash'          => '-',
		'hyphen'        => '-',
		'ndash'         => '-',
		'mdash'         => '-',
		'middot'        => '|',
		'bull'          => '|',
		'bullet'        => '|',
		'lsaquo'        => '<',
		'rsaquo'        => '>',
		'laquo'         => '<',
		'raquo'         => '>',
		'fullwidth_bar' => '|',
		'small_pipe'    => '|',
		'small_broken'  => '|',
	];

	/**
	 * Standalone meta key for SEO title.
	 */
	const META_TITLE = '_webscribeai_seo_title';

	/**
	 * Standalone meta key for SEO description.
	 */
	const META_DESCRIPTION = '_webscribeai_seo_description';

	/**
	 * Check whether The SEO Framework plugin is active.
	 *
	 * @return bool
	 */
	public static function has_tsf(): bool {
		return function_exists( 'the_seo_framework' );
	}

	/**
	 * Write AI-generated SEO meta data for a post.
	 *
	 * Respects the "override existing" setting.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $title       Meta title.
	 * @param string $description Meta description.
	 * @param bool   $override    Whether to override existing values.
	 * @return bool True if data was written, false if skipped.
	 */
	public static function save_meta( int $post_id, string $title, string $description, bool $override = false ): bool {
		// Gate title and description independently so a post missing only one
		// of the two still gets that field generated, even without override.
		$title_saved       = self::save_meta_field( $post_id, 'title', $title, $override );
		$description_saved = self::save_meta_field( $post_id, 'description', $description, $override );

		return $title_saved || $description_saved;
	}

	/**
	 * Check whether a post already has SEO meta data.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function has_existing_meta( int $post_id ): bool {
		if ( self::has_tsf() ) {
			return self::has_existing_tsf_meta( $post_id );
		}

		$standalone_title = get_post_meta( $post_id, self::META_TITLE, true );
		$standalone_desc  = get_post_meta( $post_id, self::META_DESCRIPTION, true );

		return ! empty( $standalone_title ) || ! empty( $standalone_desc );
	}

	/**
	 * Check whether a post already has a value for a single SEO meta field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   'title' or 'description'.
	 * @return bool
	 */
	public static function has_existing_meta_field( int $post_id, string $field ): bool {
		if ( 'title' === $field ) {
			return self::has_tsf()
				? '' !== (string) get_post_meta( $post_id, '_genesis_title', true )
				: '' !== (string) get_post_meta( $post_id, self::META_TITLE, true );
		}

		if ( 'description' === $field ) {
			return self::has_tsf()
				? '' !== (string) get_post_meta( $post_id, '_genesis_description', true )
				: '' !== (string) get_post_meta( $post_id, self::META_DESCRIPTION, true );
		}

		return false;
	}

	/**
	 * Write a single SEO meta field (title or description) for a post.
	 *
	 * Unlike save_meta(), this leaves the other field untouched — used when
	 * only the title or only the description is being (re)generated.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $field    'title' or 'description'.
	 * @param string $value    New field value.
	 * @param bool   $override Whether to override an existing value.
	 * @return bool True if written, false if skipped.
	 */
	public static function save_meta_field( int $post_id, string $field, string $value, bool $override = false ): bool {
		if ( ! in_array( $field, [ 'title', 'description' ], true ) ) {
			return false;
		}

		if ( ! $override && self::has_existing_meta_field( $post_id, $field ) ) {
			return false;
		}

		if ( 'title' === $field ) {
			$value = self::apply_tsf_title_preferences( $value );
			if ( self::has_tsf() ) {
				update_post_meta( $post_id, '_genesis_title', $value );
			}
			update_post_meta( $post_id, self::META_TITLE, $value );
		} else {
			if ( self::has_tsf() ) {
				update_post_meta( $post_id, '_genesis_description', $value );
			}
			update_post_meta( $post_id, self::META_DESCRIPTION, $value );
		}

		return true;
	}

	/**
	 * Build TSF title preferences for prompting and save-time formatting.
	 *
	 * @return array{separator: string, include_site_title: ?bool, site_title: string}
	 */
	public static function get_tsf_title_preferences(): array {
		$prefs = [
			'separator'          => '|',
			'include_site_title' => null,
			'site_title'         => (string) get_bloginfo( 'name' ),
		];

		$site_settings = get_option( 'autodescription-site-settings', [] );
		if ( ! is_array( $site_settings ) ) {
			$site_settings = [];
		}

		$separator_value = self::first_non_empty_option_value(
			[
				$site_settings['title_separator'] ?? null,
				$site_settings['separator'] ?? null,
				get_option( 'autodescription-title-separator', null ),
			]
		);

		if ( is_string( $separator_value ) ) {
			$prefs['separator'] = self::normalize_tsf_separator( $separator_value );
		}

		$include_site_title = self::first_boolean_option_value(
			[
				$site_settings['show_site_title'] ?? null,
				$site_settings['append_site_name'] ?? null,
				$site_settings['add_blogname'] ?? null,
				$site_settings['title_additions'] ?? null,
			]
		);

		if ( null !== $include_site_title ) {
			$prefs['include_site_title'] = $include_site_title;
		}

		// Plugin-level setting overrides TSF detection when explicitly enabled.
		$plugin_settings = (array) get_option( 'webscribeai_settings', [] );
		if ( ! empty( $plugin_settings['include_site_title'] ) ) {
			$prefs['include_site_title'] = true;
		}

		/**
		 * Filter detected TSF title preferences.
		 *
		 * @param array{separator: string, include_site_title: ?bool, site_title: string} $prefs
		 */
		return (array) apply_filters( 'webscribeai_tsf_title_preferences', $prefs );
	}

	/**
	 * Convert a TSF separator setting to a visible separator character.
	 *
	 * @param string $separator Raw separator setting.
	 * @return string
	 */
	private static function normalize_tsf_separator( string $separator ): string {
		$separator = trim( $separator );

		if ( '' === $separator ) {
			return '|';
		}

		$key = strtolower( $separator );
		if ( isset( self::TSF_SEPARATOR_MAP[ $key ] ) ) {
			return self::TSF_SEPARATOR_MAP[ $key ];
		}

		return $separator;
	}

	/**
	 * Apply TSF title formatting preferences to generated titles.
	 *
	 * @param string $title Generated title.
	 * @return string
	 */
	private static function apply_tsf_title_preferences( string $title ): string {
		$title = trim( $title );
		if ( '' === $title ) {
			return $title;
		}

		$prefs      = self::get_tsf_title_preferences();
		$site_title = trim( (string) ( $prefs['site_title'] ?? '' ) );
		$separator  = trim( (string) ( $prefs['separator'] ?? '|' ) );
		$include    = $prefs['include_site_title'] ?? null;

		if ( '' === $site_title || null === $include ) {
			return $title;
		}

		if ( true === $include ) {
			if ( false !== stripos( $title, $site_title ) ) {
				return $title;
			}

			return trim( $title . ' ' . $separator . ' ' . $site_title );
		}

		if ( false === $include ) {
			$patterns = [
				'/\s*' . preg_quote( $separator, '/' ) . '\s*' . preg_quote( $site_title, '/' ) . '\s*$/i',
				'/\s*' . preg_quote( $site_title, '/' ) . '\s*' . preg_quote( $separator, '/' ) . '\s*$/i',
				'/^\s*' . preg_quote( $site_title, '/' ) . '\s*' . preg_quote( $separator, '/' ) . '\s*/i',
				'/^\s*' . preg_quote( $separator, '/' ) . '\s*' . preg_quote( $site_title, '/' ) . '\s*/i',
			];

			foreach ( $patterns as $pattern ) {
				$title = preg_replace( $pattern, '', $title ) ?? $title;
			}

			$title = trim( preg_replace( '/\s{2,}/', ' ', $title ) ?? $title );
		}

		return $title;
	}

	/**
	 * Return the first non-empty string value from a list of options.
	 *
	 * @param array<int, mixed> $values Candidate values.
	 * @return string|null
	 */
	private static function first_non_empty_option_value( array $values ): ?string {
		foreach ( $values as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return null;
	}

	/**
	 * Return the first boolean-like value from a list of options.
	 *
	 * @param array<int, mixed> $values Candidate values.
	 * @return bool|null
	 */
	private static function first_boolean_option_value( array $values ): ?bool {
		foreach ( $values as $value ) {
			if ( is_bool( $value ) ) {
				return $value;
			}

			if ( is_numeric( $value ) ) {
				return (int) $value === 1;
			}

			if ( is_string( $value ) ) {
				$normalized = strtolower( trim( $value ) );
				if ( in_array( $normalized, [ '1', 'true', 'yes', 'on' ], true ) ) {
					return true;
				}

				if ( in_array( $normalized, [ '0', 'false', 'no', 'off' ], true ) ) {
					return false;
				}
			}
		}

		return null;
	}

	/**
	 * Check whether TSF fields already exist on this post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function has_existing_tsf_meta( int $post_id ): bool {
		$title = (string) get_post_meta( $post_id, '_genesis_title', true );
		$desc  = (string) get_post_meta( $post_id, '_genesis_description', true );

		return (bool) ( $title || $desc );
	}

	/**
	 * Delete SEO meta field(s) for a post.
	 *
	 * Clears both TSF and standalone storage so the removal is visible
	 * regardless of which SEO plugin is active.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   'title', 'description', or 'all'.
	 */
	public static function delete_meta_field( int $post_id, string $field ): void {
		if ( 'title' === $field || 'all' === $field ) {
			delete_post_meta( $post_id, '_genesis_title' );
			delete_post_meta( $post_id, self::META_TITLE );
		}

		if ( 'description' === $field || 'all' === $field ) {
			delete_post_meta( $post_id, '_genesis_description' );
			delete_post_meta( $post_id, self::META_DESCRIPTION );
		}
	}

	/**
	 * Get current SEO meta for a post.
	 *
	 * Prioritises TSF data when available.
	 *
	 * @param int $post_id Post ID.
	 * @return array{title: string, description: string}
	 */
	public static function get_meta( int $post_id ): array {
		if ( self::has_tsf() ) {
			return [
				'title'       => (string) get_post_meta( $post_id, '_genesis_title', true ),
				'description' => (string) get_post_meta( $post_id, '_genesis_description', true ),
			];
		}

		return [
			'title'       => (string) get_post_meta( $post_id, self::META_TITLE, true ),
			'description' => (string) get_post_meta( $post_id, self::META_DESCRIPTION, true ),
		];
	}

	/**
	 * Hook standalone meta into wp_head when no SEO plugin is active.
	 */
	public static function output_standalone_meta(): void {
		if ( self::has_tsf() ) {
			return; // TSF handles its own output.
		}

		if ( ! is_singular() ) {
			return;
		}

		$post_id = get_queried_object_id();
		$meta    = self::get_meta( $post_id );

		if ( ! empty( $meta['title'] ) ) {
			printf(
				'<meta name="title" content="%s" />' . "\n",
				esc_attr( $meta['title'] )
			);
		}

		if ( ! empty( $meta['description'] ) ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $meta['description'] )
			);
		}
	}
}

// Output standalone meta tags in <head> when no SEO plugin is present.
add_action( 'wp_head', [ 'WebscribeAI_SEO', 'output_standalone_meta' ], 1 );
