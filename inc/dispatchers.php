<?php
/**
 * This file contains Sync dispatching utilities
 *
 * @package JPSEP
 */

namespace JPSEP\Dispatcher;

use Exception;
use Jetpack_Sync_Actions;
use Jetpack_Sync_Modules;
use Jetpack_Sync_Settings;
use WP_Post;


/**
 * Tell Jetpack Sync that a post updated.
 *
 * @param int|WP_Post $post Post ID or object.
 */
function sync_post( $post ) {
	$post = get_post( $post );
	Jetpack_Sync_Modules::get_module( 'posts' )->wp_insert_post( $post->ID, $post );
}

/**
 * Use Jetpack's "Full Sync" module to bulk sync many posts.
 *
 * Note that only one full sync can run at a time, so this checks to see if
 * one is already running, and if so, throws an exception.
 *
 * @throws Exception If sync is already running.
 *
 * @param array $post_ids Post IDs to sync.
 * @return bool True on success, false on failure.
 */
function sync_many_posts( array $post_ids ): bool {
	$module = Jetpack_Sync_Modules::get_module( 'full-sync' );
	if ( $module->is_started() && ! $module->is_finished() ) {
		throw new Exception( __( 'A full sync is already running', 'jpsep' ) );
	}
	return Jetpack_Sync_Actions::do_full_sync( [ 'posts' => $post_ids ] );
}

/**
 * Synchronously sync content to Jetpack.
 *
 * This is largely copied from jetpack-cli, to be used outside of WP-CLI,
 * e.g. in a POST handler or REST request.
 *
 * @throws Exception On error.
 *
 * @param array $post_ids Post IDs.
 * @return bool True on success.
 */
function sync_many_posts_synchronously( array $post_ids ) {
	if ( ! Jetpack_Sync_Actions::sync_allowed() ) {
		throw new Exception( __( 'Jetpack Sync is not allowed', 'jpsep' ) );
	}

	$log = [];

	// Get the original settings so that we can restore them later.
	$original_settings = Jetpack_Sync_Settings::get_settings();

	// Initialize sync settings so we can sync as quickly as possible.
	Jetpack_Sync_Settings::update_settings(
		[
			'sync_wait_time'           => 0,
			'enqueue_wait_time'        => 0,
			'queue_max_writes_sec'     => 10000,
			'max_queue_size_full_sync' => 100000,
		]
	);

	$modules = [
		'posts' => $post_ids,
	];

	try {
		// Kick off a full sync.
		if ( ! Jetpack_Sync_Actions::do_full_sync( $modules ) ) {
			throw new Exception( __( 'Could not start a new full sync', 'jpsep' ) );
		}

		$log[] = __( 'Initialized sync', 'jpsep' );

		// Keep sending to WPCOM until there's nothing to send.
		$i = 1;
		do {
			/* translators: 1: batch iteration number */
			$log[] = sprintf( __( 'Starting batch %1$d', 'jpsep' ), $i );

			$result = Jetpack_Sync_Actions::$sender->do_full_sync();
			if ( is_wp_error( $result ) ) {
				$queue_empty_error = ( 'empty_queue_full_sync' === $result->get_error_code() );
				if ( ! $queue_empty_error || ( $queue_empty_error && ( 1 === $i ) ) ) {
					throw new Exception(
						sprintf(
							/* translators: %s is an error code  */
							__( 'Sync errored with code: %s', 'jpsep' ),
							$result->get_error_code()
						)
					);
				}
			}
			$i++;
		} while ( $result && ! is_wp_error( $result ) );
	} catch ( Exception $e ) {
		// Log the error; the exception will be thrown back to the caller below.
		$log[] = $e->getMessage();
	}

	// Reset sync settings to original.
	Jetpack_Sync_Settings::update_settings( $original_settings );

	/* @todo Do something with the log. */

	// If there was an exception, re-throw it.
	if ( isset( $e ) && $e instanceof Exception ) {
		throw $e;
	}

	return true;
}
