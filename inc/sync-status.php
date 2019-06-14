<?php
/**
 * This file contains provides interfaces to surface Jetpack Sync's status.
 *
 * @package JPSEP
 */

namespace JPSEP\Status;

use Exception;
use function JPSEP\Dispatcher\sync_many_posts_synchronously;
use function JPSEP\ES\audit_post;
use function JPSEP\ES\get_post_counts_by_type_from_db;
use function JPSEP\ES\get_post_counts_by_type_and_status_from_es;

add_action( 'admin_menu', __NAMESPACE__ . '\admin_menu', 999 );
add_action( 'wp_ajax_jpsep-tools-audit', __NAMESPACE__ . '\ajax_audit_posts' );
add_action( 'wp_ajax_jpsep-tools-sync', __NAMESPACE__ . '\ajax_sync_posts' );

/**
 * Register the submenu page.
 */
function admin_menu() {
	add_submenu_page(
		'jetpack',
		__( 'Sync Status', 'jpsep' ),
		__( 'Sync Status', 'jpsep' ),
		'manage_options',
		'jpsep',
		__NAMESPACE__ . '\admin_page'
	);
}

/**
 * Render a list of counts by post status. This is a little helper to DRY up the
 * template.
 *
 * @param array $counts Array of counts, indexed by post status.
 */
function output_post_type_status_list( $counts ) {
	foreach ( $counts as $status => $count ) {
		echo '<li>' . esc_html( "{$status}: {$count}" ) . '</li>';
	}
}

/**
 * Render the admin page for the Sync Status.
 */
function admin_page() {
	wp_enqueue_script( 'jpsep-tools' );

	$post_types = get_post_types( [ 'public' => true ] );
	$db_counts  = get_post_counts_by_type_from_db();
	$es_counts  = get_post_counts_by_type_and_status_from_es();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Jetpack Sync Status', 'jpsep' ); ?></h1>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Post Type', 'jpsep' ); ?></th>
					<th><?php esc_html_e( 'Local Count (published only)', 'jpsep' ); ?></th>
					<th><?php esc_html_e( 'Elasticsearch Count', 'jpsep' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $post_types as $post_type ) : ?>
					<tr>
						<td><?php echo esc_html( $post_type ); ?></td>
						<td><ul><?php isset( $db_counts[ $post_type ] ) && output_post_type_status_list( $db_counts[ $post_type ] ); ?></ul></td>
						<td><ul><?php isset( $es_counts[ $post_type ] ) && output_post_type_status_list( $es_counts[ $post_type ] ); ?></ul></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<hr />

		<h3><?php esc_html_e( 'Sync Posts', 'jpsep' ); ?></h3>
		<form method="post" id="jpsep-tools" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<input type="hidden" name="action" id="jpsep-action" />
			<?php wp_nonce_field( 'jpsep-tools-nonce' ); ?>
			<p>
				<label for="jpsep-tools-post-ids">
					<?php esc_html_e( 'Post IDs (comma-separated):', 'jpsep' ); ?>
				</label>
				<input type="text" name="post_ids" id="jpsep-tools-post-ids" />
				<?php submit_button( 'Audit Posts', 'primary', 'audit', false, [ 'data-action' => 'jpsep-tools-audit' ] ); ?>
				<?php submit_button( 'Sync Posts', 'primary', 'sync', false, [ 'data-action' => 'jpsep-tools-sync' ] ); ?>
			</p>
		</form>
		<pre id="jpsep-tools-output"></pre>
	</div>
	<?php
}

/**
 * Validate an AJAX request and return the sanitized post ids from the request.
 *
 * @return array Post IDs.
 */
function get_post_ids_for_ajax_request(): array {
	// Validate the data and nonce.
	if ( empty( $_POST['post_ids'] ) || empty( $_POST['_wpnonce'] ) ) {
		wp_send_json_error( 'Invalid request', 400 );
	}
	wp_verify_nonce( sanitize_text_field( $_POST['_wpnonce'] ), 'jpsep-tools-nonce' );

	// Sanitize the post IDs.
	$post_ids = explode( ',', sanitize_text_field( wp_unslash( $_POST['post_ids'] ) ) );
	$post_ids = array_map( 'intval', $post_ids );
	if ( empty( $post_ids ) ) {
		wp_send_json_error( 'Invalid request', 400 );
	}

	return $post_ids;
}

/**
 * AJAX responder for audit request.
 */
function ajax_audit_posts() {
	$post_ids = get_post_ids_for_ajax_request();
	$errors   = [];

	foreach ( $post_ids as $post_id ) {
		try {
			audit_post( $post_id );
		} catch ( Exception $e ) {
			$errors[] = "{$post_id}: {$e->getMessage()}";
		}
	}

	if ( ! empty( $errors ) ) {
		wp_send_json_error( implode( "\n", $errors ) );
	}

	wp_send_json_success(
		sprintf(
			/* translators: 1: post count */
			_n( 'Successfully confirmed %1$d item', 'Successfully confirmed %1$d items', count( $post_ids ), 'jpsep' ),
			count( $post_ids )
		)
	);
}

/**
 * AJAX responder for sync request.
 */
function ajax_sync_posts() {
	$post_ids = get_post_ids_for_ajax_request();
	try {
		sync_many_posts_synchronously( $post_ids );
		wp_send_json_success(
			sprintf(
				/* translators: 1: post count */
				_n(
					'Successfully sent %1$d post to WordPress.com. It might take a minute or two until it is updated in Elasticsearch.',
					'Successfully sent %1$d posts to WordPress.com. It might take a minute or two until they are updated in Elasticsearch.',
					count( $post_ids ),
					'jpsep'
				),
				count( $post_ids )
			)
		);
	} catch ( Exception $e ) {
		wp_send_json_error( $e->getMessage() );
	}
}
