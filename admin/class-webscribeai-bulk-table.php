<?php
/**
 * Bulk Populate table: filtered, paginated post queries and row rendering.
 *
 * Rows are loaded one page at a time over AJAX (see WebscribeAI_Ajax) so the
 * Bulk Populate tab stays fast on sites with thousands of posts.
 *
 * @package WebscribeAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WebscribeAI_Bulk_Table
 */
class WebscribeAI_Bulk_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Filter views shown above the table.
	 */
	const VIEWS = [ 'all', 'missing_title', 'missing_description', 'too_long', 'errors' ];

	/**
	 * Post statuses included in the table.
	 */
	const STATUSES = [ 'publish', 'draft', 'private' ];

	/**
	 * Get all public post types for bulk operations.
	 *
	 * @return WP_Post_Type[]
	 */
	public static function get_post_types(): array {
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
	 * Get the errors stored from the last bulk generation run.
	 *
	 * @return array<int, string> Post ID => error message.
	 */
	public static function get_last_errors(): array {
		$saved = get_option( 'webscribeai_bulk_errors', null );

		if ( ! is_array( $saved ) || empty( $saved['errors'] ) || ! is_array( $saved['errors'] ) ) {
			return [];
		}

		return $saved['errors'];
	}

	/**
	 * Normalise filter arguments from a (unslashed) request array.
	 *
	 * @param array $raw Raw request values.
	 * @return array{post_type: string, view: string, search: string, paged: int, include: int[]}
	 */
	public static function parse_args( array $raw ): array {
		$post_type = sanitize_key( (string) ( $raw['post_type'] ?? '' ) );
		$view      = sanitize_key( (string) ( $raw['view'] ?? 'all' ) );

		return [
			'post_type' => array_key_exists( $post_type, self::get_post_types() ) ? $post_type : '',
			'view'      => in_array( $view, self::VIEWS, true ) ? $view : 'all',
			'search'    => sanitize_text_field( (string) ( $raw['search'] ?? '' ) ),
			'paged'     => max( 1, absint( $raw['paged'] ?? 1 ) ),
			'include'   => array_values( array_filter( array_map( 'absint', (array) ( $raw['include'] ?? [] ) ) ) ),
		];
	}

	/**
	 * Get one page of rows matching the filters.
	 *
	 * When `include` is set, exactly those posts are returned in the given
	 * order (used to show the rows of a running bulk job).
	 *
	 * @param array $args Parsed args from parse_args().
	 * @return array{rows: object[], total: int}
	 */
	public static function query( array $args ): array {
		global $wpdb;

		$derived = self::derived_table( $args );
		$where   = self::view_condition( $args['view'] );

		if ( ! empty( $args['include'] ) ) {
			$order = 'FIELD( t.ID, ' . implode( ',', array_map( 'intval', $args['include'] ) ) . ' )';
		} else {
			$order = 't.post_title ASC, t.ID ASC';
		}

		$offset = ( $args['paged'] - 1 ) * self::PER_PAGE;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $derived is prepared; the rest is built from integers and constants.
		$rows  = $wpdb->get_results( "SELECT t.* FROM ( {$derived} ) t WHERE {$where} ORDER BY {$order} LIMIT {$offset}, " . self::PER_PAGE );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM ( {$derived} ) t WHERE {$where}" );
		// phpcs:enable

		return [
			'rows'  => (array) $rows,
			'total' => $total,
		];
	}

	/**
	 * Count the posts in each view for the current post type / search filters.
	 *
	 * @param array $args Parsed args from parse_args().
	 * @return array<string, int>
	 */
	public static function counts( array $args ): array {
		global $wpdb;

		$derived = self::derived_table( $args );
		$selects = [];

		foreach ( self::VIEWS as $view ) {
			$selects[] = 'SUM( ' . self::view_condition( $view ) . " ) AS `{$view}`";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $derived is prepared; the rest is built from constants.
		$row = (array) $wpdb->get_row( 'SELECT ' . implode( ', ', $selects ) . " FROM ( {$derived} ) t", ARRAY_A );

		$counts = [];
		foreach ( self::VIEWS as $view ) {
			$counts[ $view ] = (int) ( $row[ $view ] ?? 0 );
		}

		return $counts;
	}

	/**
	 * Get the ID and title of every post matching the filters (all pages).
	 *
	 * @param array $args       Parsed args from parse_args().
	 * @param bool  $needs_meta Only return posts missing a title or description.
	 * @return array<int, array{0: int, 1: string}>
	 */
	public static function get_ids( array $args, bool $needs_meta = false ): array {
		global $wpdb;

		$derived = self::derived_table( $args );
		$where   = self::view_condition( $args['view'] );

		if ( $needs_meta ) {
			$where .= ' AND ( ' . self::view_condition( 'missing_title' ) . ' OR ' . self::view_condition( 'missing_description' ) . ' )';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $derived is prepared; the rest is built from constants.
		$rows = $wpdb->get_results( "SELECT t.ID, t.post_title FROM ( {$derived} ) t WHERE {$where} ORDER BY t.post_title ASC, t.ID ASC" );

		return array_map(
			static fn( $row ) => [ (int) $row->ID, (string) $row->post_title ],
			(array) $rows
		);
	}

	/**
	 * Render table rows for the given query rows.
	 *
	 * @param object[] $rows Rows from query().
	 * @return string HTML.
	 */
	public static function render_rows( array $rows ): string {
		if ( empty( $rows ) ) {
			return '<tr class="webscribeai-empty-row"><td colspan="8">'
				. esc_html__( 'No posts match these filters.', 'webscribeai' )
				. '</td></tr>';
		}

		$errors = self::get_last_errors();

		ob_start();
		foreach ( $rows as $row ) {
			$post_id     = (int) $row->ID;
			$title       = (string) $row->post_title;
			$seo_title   = (string) $row->seo_title;
			$seo_desc    = (string) $row->seo_description;
			$type_object = get_post_type_object( $row->post_type );
			$status      = get_post_status_object( $row->post_status );
			$has_error   = array_key_exists( $post_id, $errors );
			?>
			<tr
				class="webscribeai-post-row<?php echo $has_error ? ' webscribeai-errored' : ''; ?>"
				id="webscribeai-row-<?php echo esc_attr( $post_id ); ?>"
				data-post-id="<?php echo esc_attr( $post_id ); ?>"
				data-title="<?php echo esc_attr( $title ); ?>"
			>
				<th scope="row" class="check-column">
					<input type="checkbox" class="webscribeai-post-checkbox" value="<?php echo esc_attr( $post_id ); ?>" />
				</th>
				<td class="webscribeai-cell-post">
					<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank">
						<?php echo esc_html( '' !== $title ? $title : __( '(no title)', 'webscribeai' ) ); ?>
					</a>
				</td>
				<td><?php echo esc_html( $type_object ? $type_object->labels->singular_name : $row->post_type ); ?></td>
				<td><?php echo esc_html( $status ? $status->label : $row->post_status ); ?></td>
				<td class="webscribeai-cell-title">
					<span class="webscribeai-meta-text"><?php echo esc_html( $seo_title ); ?></span>
					<?php self::render_validity_bar( $seo_title, WebscribeAI_Admin::SEO_TITLE_LIMIT, WebscribeAI_Admin::SEO_TITLE_WARN_LIMIT ); ?>
				</td>
				<td class="webscribeai-cell-desc">
					<span class="webscribeai-meta-text"><?php echo esc_html( $seo_desc ); ?></span>
					<?php self::render_validity_bar( $seo_desc, WebscribeAI_Admin::SEO_DESC_LIMIT, WebscribeAI_Admin::SEO_DESC_WARN_LIMIT ); ?>
				</td>
				<td class="webscribeai-cell-result<?php echo $has_error ? ' is-error' : ''; ?>" id="webscribeai-result-<?php echo esc_attr( $post_id ); ?>"><?php
					if ( $has_error ) {
						echo esc_html( __( 'Error', 'webscribeai' ) . ( '' !== $errors[ $post_id ] ? ': ' . $errors[ $post_id ] : '' ) );
					}
				?></td>
				<td class="webscribeai-cell-actions">
					<button
						type="button"
						class="button-link webscribeai-edit-btn"
						data-post-id="<?php echo esc_attr( $post_id ); ?>"
						title="<?php esc_attr_e( 'Edit meta', 'webscribeai' ); ?>"
					><span class="dashicons dashicons-edit"></span></button>
				</td>
			</tr>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Output a small colour-coded bar showing how a title/description length
	 * compares to the recommended SEO character limit.
	 *
	 * @param string $text       Meta text.
	 * @param int    $limit      Recommended limit.
	 * @param int    $warn_limit Limit past which the bar turns red.
	 */
	public static function render_validity_bar( string $text, int $limit, int $warn_limit ): void {
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
	// SQL helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the prepared inner query: one row per matching post with its
	 * current SEO title and description pulled from post meta.
	 *
	 * Ignores the `view` arg — views are applied on top by the caller.
	 *
	 * @param array $args Parsed args from parse_args().
	 * @return string Prepared SQL.
	 */
	private static function derived_table( array $args ): string {
		global $wpdb;

		$types = '' !== $args['post_type'] ? [ $args['post_type'] ] : array_keys( self::get_post_types() );
		$types = ! empty( $types ) ? $types : [ '' ];

		$values = [ self::meta_key( 'title' ), self::meta_key( 'description' ) ];
		$where  = [];

		$where[] = 'p.post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
		$values  = array_merge( $values, $types );

		$where[] = 'p.post_status IN (' . implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) ) . ')';
		$values  = array_merge( $values, self::STATUSES );

		if ( ! empty( $args['include'] ) ) {
			$where[] = 'p.ID IN (' . implode( ',', array_map( 'intval', $args['include'] ) ) . ')';
		}

		if ( '' !== $args['search'] ) {
			$where[]  = 'p.post_title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$meta_subquery = "( SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = %s ORDER BY pm.meta_id ASC LIMIT 1 )";

		$sql = "SELECT p.ID, p.post_title, p.post_type, p.post_status,
				{$meta_subquery} AS seo_title,
				{$meta_subquery} AS seo_description
			FROM {$wpdb->posts} p
			WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built above.
		return $wpdb->prepare( $sql, $values );
	}

	/**
	 * SQL condition (on the derived table `t`) for a view.
	 *
	 * @param string $view One of self::VIEWS.
	 * @return string
	 */
	private static function view_condition( string $view ): string {
		switch ( $view ) {
			case 'missing_title':
				return "( COALESCE( t.seo_title, '' ) = '' )";

			case 'missing_description':
				return "( COALESCE( t.seo_description, '' ) = '' )";

			case 'too_long':
				return sprintf(
					"( CHAR_LENGTH( COALESCE( t.seo_title, '' ) ) > %d OR CHAR_LENGTH( COALESCE( t.seo_description, '' ) ) > %d )",
					WebscribeAI_Admin::SEO_TITLE_LIMIT,
					WebscribeAI_Admin::SEO_DESC_LIMIT
				);

			case 'errors':
				$ids = array_map( 'intval', array_keys( self::get_last_errors() ) );
				return ! empty( $ids ) ? '( t.ID IN (' . implode( ',', $ids ) . ') )' : '( 1 = 0 )';

			default:
				return '( 1 = 1 )';
		}
	}

	/**
	 * Meta key the current SEO title/description is read from — mirrors
	 * WebscribeAI_SEO::get_meta().
	 *
	 * @param string $field 'title' or 'description'.
	 * @return string
	 */
	private static function meta_key( string $field ): string {
		if ( WebscribeAI_SEO::has_tsf() ) {
			return 'title' === $field ? '_genesis_title' : '_genesis_description';
		}

		return 'title' === $field ? WebscribeAI_SEO::META_TITLE : WebscribeAI_SEO::META_DESCRIPTION;
	}
}
