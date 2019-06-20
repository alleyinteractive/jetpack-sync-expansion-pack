<?php // phpcs:disable
/**
 * WP-CLI utilities
 *
 * @package JPSEP
 */

namespace JPSEP;

use Exception;
use function JPSEP\Dispatcher\sync_many_posts_synchronously;
use function JPSEP\ES\audit_posts;
use WP_CLI;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\make_progress_bar;

require_once __DIR__ . '/trait-alley-cli-bulk-task.php';

class CLI {
	use Alley_CLI_Bulk_Task;

	/**
	 * Prevent memory leaks from growing out of control
	 */
	protected function stop_the_insanity() {
		global $wpdb, $wp_object_cache;
		$wpdb->queries = [];
		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}
		$wp_object_cache->group_ops      = [];
		$wp_object_cache->stats          = [];
		$wp_object_cache->memcache_debug = [];
		$wp_object_cache->cache          = [];
		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset();
		}
	}

	/**
	 * Ask the user a yes/no question.
	 *
	 * @param string $question Question to ask.
	 * @return bool True if "y", false otherwise.
	 */
	protected function ask( $question ) {
		fwrite( STDOUT, $question . ' [y/n] ' );

		return 'y' === strtolower( trim( fgets( STDIN ) ) );
	}

	/**
	 * Run an iteration of the audit queue.
	 *
	 * @param array $posts WP_Post objects.
	 * @return array {
	 *     @type int   $confirmed Number of posts confirmed.
	 *     @type array $errors    Array of failed audits, reasons indexed by
	 *                            Post ID.
	 * }
	 */
	protected function run_audit_queue( array $posts ): array {
		$confirmed = 0;
		try {
			$errors = audit_posts( $posts );
			if ( true === $errors ) {
				$errors = [];
			}
			$confirmed = count( $posts ) - count( $errors );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		return compact( 'confirmed', 'errors' );
	}

	/**
	 * Audit the site against Jetpack ES.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : If present, script will attempt to fix any posts that fail the audit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp jetpack sync audit_posts
	 */
	public function audit_posts( $args, $assoc_args ) {
		$post_types = get_post_types( [ 'public' => true ] );
		$errors     = [];
		$fix        = ! empty( $assoc_args['fix'] );
		$confirmed  = 0;
		$queue      = [];

		WP_CLI::line( 'Auditing published posts...' );

		$this->bulk_task(
			[
				'post_type'   => $post_types,
				'post_status' => 'publish',
			],
			function( $post ) use ( &$errors, &$confirmed, &$queue ) {
				$queue[] = $post;

				if ( 100 === count( $queue ) ) {
					[
						'confirmed' => $batch_confirmed,
						'errors'    => $batch_errors,
					] = $this->run_audit_queue( $queue );

					// Reset the queue.
					$queue = [];

					$confirmed += $batch_confirmed;

					foreach ( $batch_errors as $post_id => $reason ) {
						$errors[] = compact( 'post_id', 'reason' );
					}
				}
			}
		);

		// Run the last batch if necessary.
		if ( ! empty( $queue ) ) {
			[
				'confirmed' => $batch_confirmed,
				'errors'    => $batch_errors,
			] = $this->run_audit_queue( $queue );

			// Reset the queue.
			$queue = [];

			$confirmed += $batch_confirmed;

			foreach ( $batch_errors as $post_id => $reason ) {
				$errors[] = compact( 'post_id', 'reason' );
			}
		}

		if ( ! empty( $errors ) ) {
			WP_CLI::warning( sprintf( 'Audit failed! %d items failed the audit.', count( $errors ) ) );
			if ( $this->ask( 'Would you like to see what failed and why?' ) ) {
				format_items(
					'table',
					$errors,
					[
						'post_id',
						'reason',
					]
				);
			}
			if ( $fix || $this->ask( 'Would you like to try to fix these items?' ) ) {
				$post_ids = array_column( $errors, 'post_id' );
				$chunks   = array_chunk( $post_ids, 100 );
				$progress = make_progress_bar(
					sprintf( 'Attempting to push %d items to WordPress.com', count( $post_ids ) ),
					count( $chunks )
				);

				try {
					foreach ( $chunks as $chunk ) {
						sync_many_posts_synchronously( $chunk );
						$this->stop_the_insanity();
					    $progress->tick();
					}
				} catch ( Exception $e ) {
					WP_CLI::error( $e->getMessage() );
				}

				$progress->finish();
			}
		} else {
			WP_CLI::success( "Audit passed, {$confirmed} posts verified" );
		}
	}
}
