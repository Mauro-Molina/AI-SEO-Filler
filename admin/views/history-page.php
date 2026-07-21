<?php
/**
 * Global history page view.
 *
 * @package AiSeoFiller
 */

defined( 'ABSPATH' ) || exit;

// Template variables are local to this included view.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

use AiSeoFiller\Bulk;
use AiSeoFiller\History;
use AiSeoFiller\Settings;

$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$action    = isset( $_GET['history_action'] ) ? sanitize_key( wp_unslash( $_GET['history_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$search    = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page      = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$allowed_types = Bulk::get_allowed_post_types();
if ( $post_type && ! in_array( $post_type, $allowed_types, true ) ) {
	$post_type = '';
}

$result = History::query_entries(
	array(
		'per_page'  => 20,
		'page'      => $page,
		'post_type' => $post_type,
		'action'    => $action,
		'search'    => $search,
	)
);

$items     = $result['items'];
$total     = (int) $result['total'];
$pages     = (int) $result['pages'];
$page      = (int) $result['page'];
$base_url  = admin_url( 'admin.php?page=ai-seo-filler-history' );
$type_labels = array();

foreach ( $allowed_types as $type ) {
	$type_labels[ $type ] = Settings::get_post_type_label( $type );
}

$field_labels = array(
	'meta_title'          => __( 'SEO title', 'ai-seo-filler' ),
	'meta_description'    => __( 'Meta description', 'ai-seo-filler' ),
	'focus_keyword'       => __( 'Focus keyword', 'ai-seo-filler' ),
	'keyword_alternatives'=> __( 'Keyword alternatives', 'ai-seo-filler' ),
	'slug'                => __( 'Slug', 'ai-seo-filler' ),
	'og_title'            => __( 'OG title', 'ai-seo-filler' ),
	'og_description'      => __( 'OG description', 'ai-seo-filler' ),
	'short_description'   => __( 'Short description', 'ai-seo-filler' ),
	'optimized_content'   => __( 'Content', 'ai-seo-filler' ),
	'image_alts'          => __( 'Image alts', 'ai-seo-filler' ),
);
?>
<div class="wrap ai-seo-filler-wrap ai-seo-filler-history-page">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Recent SEO and image applies across your content. You can undo the latest apply for each item when a snapshot is available.', 'ai-seo-filler' ); ?>
	</p>

	<div class="ai-seo-filler-card">
		<form method="get" class="ai-seo-filler-history-filters">
			<input type="hidden" name="page" value="ai-seo-filler-history" />

			<label class="screen-reader-text" for="ai-seo-filler-history-search"><?php esc_html_e( 'Search', 'ai-seo-filler' ); ?></label>
			<input
				type="search"
				id="ai-seo-filler-history-search"
				name="s"
				value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search by title…', 'ai-seo-filler' ); ?>"
			/>

			<label class="screen-reader-text" for="ai-seo-filler-history-type"><?php esc_html_e( 'Content type', 'ai-seo-filler' ); ?></label>
			<select id="ai-seo-filler-history-type" name="post_type">
				<option value=""><?php esc_html_e( 'All types', 'ai-seo-filler' ); ?></option>
				<?php foreach ( $allowed_types as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $post_type, $type ); ?>>
						<?php echo esc_html( $type_labels[ $type ] ?? $type ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="ai-seo-filler-history-action"><?php esc_html_e( 'Action', 'ai-seo-filler' ); ?></label>
			<select id="ai-seo-filler-history-action" name="history_action">
				<option value=""><?php esc_html_e( 'All actions', 'ai-seo-filler' ); ?></option>
				<option value="apply_seo" <?php selected( $action, 'apply_seo' ); ?>><?php esc_html_e( 'SEO', 'ai-seo-filler' ); ?></option>
				<option value="apply_images" <?php selected( $action, 'apply_images' ); ?>><?php esc_html_e( 'Images', 'ai-seo-filler' ); ?></option>
				<option value="undone" <?php selected( $action, 'undone' ); ?>><?php esc_html_e( 'Undone', 'ai-seo-filler' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'ai-seo-filler' ), 'secondary', '', false ); ?>

			<?php if ( $search || $post_type || $action ) : ?>
				<a class="button button-link" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Reset', 'ai-seo-filler' ); ?></a>
			<?php endif; ?>
		</form>

		<p class="ai-seo-filler-history-page__count">
			<?php
			printf(
				/* translators: %d: number of history entries */
				esc_html( _n( '%d entry', '%d entries', $total, 'ai-seo-filler' ) ),
				(int) $total
			);
			?>
		</p>

		<?php if ( empty( $items ) ) : ?>
			<p class="ai-seo-filler-history-page__empty">
				<?php esc_html_e( 'No history yet. Generate SEO or images on a post, page, or product to see entries here.', 'ai-seo-filler' ); ?>
			</p>
		<?php else : ?>
			<table class="widefat striped ai-seo-filler-history-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'ai-seo-filler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Content', 'ai-seo-filler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'ai-seo-filler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Provider', 'ai-seo-filler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'User', 'ai-seo-filler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'ai-seo-filler' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'ai-seo-filler' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<?php
						$user_name = '';
						if ( ! empty( $item['user_id'] ) ) {
							$user = get_user_by( 'id', $item['user_id'] );
							$user_name = $user ? $user->display_name : '';
						}

						$details = array();
						if ( 'apply_images' === $item['action'] ) {
							if ( ! empty( $item['featured'] ) ) {
								/* translators: %d: attachment ID */
								$details[] = sprintf( __( 'Featured #%d', 'ai-seo-filler' ), (int) $item['featured'] );
							}
							if ( ! empty( $item['gallery'] ) ) {
								/* translators: %d: gallery image count */
								$details[] = sprintf( __( '%d gallery', 'ai-seo-filler' ), count( $item['gallery'] ) );
							}
						} else {
							foreach ( $item['fields'] as $field ) {
								$details[] = $field_labels[ $field ] ?? $field;
							}
						}

						$row_class = ! empty( $item['undone'] ) ? 'is-undone' : '';
						?>
						<tr class="<?php echo esc_attr( $row_class ); ?>" data-post-id="<?php echo esc_attr( (string) $item['post_id'] ); ?>">
							<td>
								<?php
								echo esc_html(
									$item['timestamp']
										? wp_date( 'Y-m-d H:i', $item['timestamp'] )
										: '—'
								);
								?>
							</td>
							<td>
								<strong>
									<?php if ( ! empty( $item['edit_link'] ) ) : ?>
										<a href="<?php echo esc_url( $item['edit_link'] ); ?>">
											<?php echo esc_html( $item['post_title'] ?: __( '(no title)', 'ai-seo-filler' ) ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $item['post_title'] ?: __( '(no title)', 'ai-seo-filler' ) ); ?>
									<?php endif; ?>
								</strong>
								<div class="ai-seo-filler-history-table__meta">
									<?php echo esc_html( $type_labels[ $item['post_type'] ] ?? $item['post_type'] ); ?>
									· #<?php echo esc_html( (string) $item['post_id'] ); ?>
								</div>
							</td>
							<td>
								<span class="ai-seo-filler-badge <?php echo ! empty( $item['undone'] ) ? 'ai-seo-filler-badge--warn' : 'ai-seo-filler-badge--ok'; ?>">
									<?php echo esc_html( History::get_action_label( $item ) ); ?>
								</span>
							</td>
							<td>
								<?php echo esc_html( $item['provider'] ?: '—' ); ?>
								<?php if ( ! empty( $item['model'] ) ) : ?>
									<div class="ai-seo-filler-history-table__meta"><?php echo esc_html( $item['model'] ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $user_name ?: '—' ); ?></td>
							<td>
								<?php if ( empty( $details ) ) : ?>
									—
								<?php else : ?>
									<span class="ai-seo-filler-history-table__details" title="<?php echo esc_attr( implode( ', ', $details ) ); ?>">
										<?php echo esc_html( implode( ', ', array_slice( $details, 0, 4 ) ) ); ?>
										<?php if ( count( $details ) > 4 ) : ?>
											…
										<?php endif; ?>
									</span>
								<?php endif; ?>
							</td>
							<td class="ai-seo-filler-history-table__actions">
								<?php if ( ! empty( $item['edit_link'] ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $item['edit_link'] ); ?>"><?php esc_html_e( 'Edit', 'ai-seo-filler' ); ?></a>
								<?php endif; ?>
								<?php if ( ! empty( $item['can_undo'] ) ) : ?>
									<button
										type="button"
										class="button button-small ai-seo-filler-undo"
										data-post-id="<?php echo esc_attr( (string) $item['post_id'] ); ?>"
									>
										<?php esc_html_e( 'Undo', 'ai-seo-filler' ); ?>
									</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %d: number of history entries */
								esc_html( _n( '%d item', '%d items', $total, 'ai-seo-filler' ) ),
								(int) $total
							);
							?>
						</span>
						<span class="pagination-links">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg(
											array_filter(
												array(
													'page'           => 'ai-seo-filler-history',
													'post_type'      => $post_type,
													'history_action' => $action,
													's'              => $search,
													'paged'          => '%#%',
												)
											),
											admin_url( 'admin.php' )
										),
										'format'    => '',
										'current'   => $page,
										'total'     => $pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
									)
								)
							);
							?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
