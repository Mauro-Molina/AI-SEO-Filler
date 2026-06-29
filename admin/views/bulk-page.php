<?php
/**
 * Bulk processing page view.
 *
 * @package AiSeoFiller
 */

defined( 'ABSPATH' ) || exit;

use AiSeoFiller\Bulk;
use AiSeoFiller\Core;
use AiSeoFiller\Settings;

$allowed_types = Bulk::get_allowed_post_types();
$type_labels   = array(
	'post'    => __( 'Posts', 'ai-seo-filler' ),
	'page'    => __( 'Pages', 'ai-seo-filler' ),
	'product' => __( 'Products', 'ai-seo-filler' ),
);

$status_labels = array(
	'publish' => __( 'Published', 'ai-seo-filler' ),
	'draft'   => __( 'Draft', 'ai-seo-filler' ),
	'pending' => __( 'Pending', 'ai-seo-filler' ),
	'private' => __( 'Private', 'ai-seo-filler' ),
	'future'  => __( 'Scheduled', 'ai-seo-filler' ),
);

$has_api      = Settings::has_active_provider_configured();
$seo_plugin   = Core::detect_seo_plugin();
$can_process  = $has_api && 'none' !== $seo_plugin;
$queue_status = ( new Bulk( Core::instance() ) )->get_queue_status();
?>
<div class="wrap ai-seo-filler-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( ! $has_api ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'Configure your AI provider API key before running bulk processing.', 'ai-seo-filler' ); ?></p>
		</div>
	<?php elseif ( 'none' === $seo_plugin ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'No compatible SEO plugin detected. Install Rank Math or Yoast SEO first.', 'ai-seo-filler' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="ai-seo-filler-card">
		<h2><?php esc_html_e( 'Start Bulk Processing', 'ai-seo-filler' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %d: batch size */
				esc_html__( 'Posts are processed in batches of %d via WP-Cron (approximately one batch per minute).', 'ai-seo-filler' ),
				(int) AI_SEO_FILLER_BULK_BATCH_SIZE
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="ai_seo_filler_bulk_post_type"><?php esc_html_e( 'Content Type', 'ai-seo-filler' ); ?></label>
				</th>
				<td>
					<select id="ai_seo_filler_bulk_post_type" <?php disabled( ! $can_process ); ?>>
						<?php foreach ( $allowed_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>">
								<?php echo esc_html( isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : $type ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ai_seo_filler_bulk_post_status"><?php esc_html_e( 'Post Status', 'ai-seo-filler' ); ?></label>
				</th>
				<td>
					<select id="ai_seo_filler_bulk_post_status" <?php disabled( ! $can_process ); ?>>
						<?php foreach ( $status_labels as $status => $label ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<p class="ai-seo-filler-actions">
			<button
				type="button"
				class="button button-primary ai-seo-filler-start-bulk"
				<?php disabled( ! $can_process ); ?>
			>
				<?php esc_html_e( 'Start Bulk Generation', 'ai-seo-filler' ); ?>
			</button>
			<button
				type="button"
				class="button ai-seo-filler-cancel-bulk"
				<?php disabled( 'idle' === $queue_status['status'] || 'completed' === $queue_status['status'] ); ?>
			>
				<?php esc_html_e( 'Cancel Queue', 'ai-seo-filler' ); ?>
			</button>
		</p>
	</div>

	<div class="ai-seo-filler-card ai-seo-filler-progress-card" id="ai-seo-filler-bulk-progress">
		<h2><?php esc_html_e( 'Progress', 'ai-seo-filler' ); ?></h2>

		<div class="ai-seo-filler-progress-bar-wrap">
			<div
				class="ai-seo-filler-progress-bar"
				id="ai-seo-filler-progress-bar"
				style="width: <?php echo $queue_status['total'] > 0 ? esc_attr( round( ( $queue_status['processed'] / $queue_status['total'] ) * 100 ) ) : 0; ?>%;"
			></div>
		</div>

		<ul class="ai-seo-filler-progress-stats" id="ai-seo-filler-progress-stats">
			<li>
				<strong><?php esc_html_e( 'Status:', 'ai-seo-filler' ); ?></strong>
				<span id="ai-seo-filler-bulk-status-label"><?php echo esc_html( ucfirst( $queue_status['status'] ) ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Total:', 'ai-seo-filler' ); ?></strong>
				<span id="ai-seo-filler-bulk-total"><?php echo (int) $queue_status['total']; ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Processed:', 'ai-seo-filler' ); ?></strong>
				<span id="ai-seo-filler-bulk-processed"><?php echo (int) $queue_status['processed']; ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Pending:', 'ai-seo-filler' ); ?></strong>
				<span id="ai-seo-filler-bulk-pending"><?php echo (int) $queue_status['pending']; ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Errors:', 'ai-seo-filler' ); ?></strong>
				<span id="ai-seo-filler-bulk-errors"><?php echo (int) $queue_status['errors']; ?></span>
			</li>
		</ul>

		<div id="ai-seo-filler-bulk-message" class="ai-seo-filler-status" aria-live="polite"></div>

		<?php if ( ! empty( $queue_status['error_log'] ) ) : ?>
			<div class="ai-seo-filler-error-log" id="ai-seo-filler-error-log">
				<h3><?php esc_html_e( 'Recent Errors', 'ai-seo-filler' ); ?></h3>
				<ul>
					<?php foreach ( $queue_status['error_log'] as $error ) : ?>
						<li>
							<?php
							printf(
								/* translators: 1: post ID, 2: error message */
								esc_html__( 'Post #%1$d: %2$s', 'ai-seo-filler' ),
								(int) $error['post_id'],
								esc_html( $error['message'] )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
</div>

<script>
	// Pass initial queue state to admin.js for polling on page load.
	window.aiSeoFillerBulk = <?php echo wp_json_encode( $queue_status ); ?>;
</script>
