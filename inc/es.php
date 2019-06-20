<?php
/**
 * This file contains ES utility functions.
 *
 * @package JPSEP
 */

namespace JPSEP\ES;

use Exception;
use Jetpack_Search;
use stdClass;
use WP_Post;

/**
 * Perform an ES query.
 *
 * @param array $es_args Elasticsearch DSL.
 * @return array
 */
function es_query( array $es_args ): array {
	$jetpack_search = Jetpack_Search::instance();
	$result         = $jetpack_search->search( $es_args );
	if ( is_wp_error( $result ) ) {
		return [];
	}

	return $result;
}

/**
 * Get the post data stored in Elasticsearch for a given post ID.
 *
 * @param int $post_id Post ID.
 * @return array|bool Array on success, false on failure.
 */
function get_post_data( int $post_id ) {
	$result = es_query(
		[
			'query'  => [ 'term' => compact( 'post_id' ) ],
			'fields' => [ '_source' ],
		]
	);

	return $result['results']['hits'][0]['_source'] ?? false;
}

/**
 * Audit a post in Elasticsearch.
 *
 * @throws Exception On error or audit failure.
 *
 * @param int|WP_Post $post Post ID or object.
 * @return bool True on success.
 */
function audit_post( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		throw new Exception( 'Invalid post' );
	}

	$es_data = get_post_data( $post->ID );
	if ( ! $es_data ) {
		throw new Exception( 'Missing' );
	}

	/**
	 * Audit a post.
	 *
	 * To fail the audit, return a string containing the reason for
	 * the failure.
	 *
	 * @param bool    $result  Audit result.
	 * @param WP_Post $post    Post object.
	 * @param array   $es_data Data in Elasticsearch.
	 */
	$result = apply_filters( 'jpsep_audit_post', true, $post, $es_data );

	if ( true !== $result ) {
		throw new Exception( $result );
	}

	return true;
}

/**
 * Audit many posts in bulk. This can be orders-of-magnitude faster than
 * checking posts individually.
 *
 * @throws Exception When invalid data is passed to the function or if ES
 *                   doesn't appear to be working.
 *
 * @param array $posts WP_Post objects.
 * @return bool|array Array of errors indexed by post ID on failure, true on
 *                    success.
 */
function audit_posts( array $posts ) {
	$errors           = [];
	$id_indexed_posts = [];

	foreach ( $posts as $key => $post ) {
		if ( ! $post instanceof WP_Post ) {
			throw new Exception(
				'Invalid post at index ' . $key . (
					is_scalar( $post ) ? ": {$post}" : ': Type ' . gettype( $post )
				)
			);
		}

		$id_indexed_posts[ $post->ID ] = $post;
	}

	$es_result = es_query(
		[
			'query'  => [
				'terms' => [
					'post_id' => array_keys( $id_indexed_posts ),
				],
			],
			'fields' => [ '_source' ],
			'size'   => count( $posts ),
		]
	);

	if ( ! isset( $es_result['results']['hits'] ) ) {
		throw new Exception( 'Invalid response from ES: ' . wp_json_encode( $es_result ) );
	}

	foreach ( $es_result['results']['hits'] as $hit ) {
		$post = $id_indexed_posts[ $hit['_source']['post_id'] ];

		/**
		 * Filter documented in {@see audit_post()}.
		 */
		$result = apply_filters( 'jpsep_audit_post', true, $post, $hit['_source'] );

		if ( true !== $result ) {
			$errors[ $post->ID ] = $result;
		}

		// Remove the post from the list, checking off that we've validated it.
		unset( $id_indexed_posts[ $post->ID ] );
	}

	// If there were any posts remaining in the list, those are missing from ES.
	foreach ( $id_indexed_posts as $post ) {
		$errors[ $post->ID ] = 'Missing';
	}

	return $errors ?: true;
}

/**
 * Get post counts from ES by post type.
 *
 * @return array Associative array of $post_type => $count.
 */
function get_post_counts_by_type_and_status_from_es(): array {
	$results = es_query(
		[
			'size'         => 1,
			'query'        => [
				'match_all' => new stdClass(),
			],
			'aggregations' => [
				'post_type' => [
					'terms'        => [
						'field' => 'post_type',
					],
					'aggregations' => [
						'post_status' => [
							'terms' => [
								'field' => 'post_status',
							],
						],
					],
				],
			],
		]
	);

	$counts           = [];
	$post_type_bucket = $results['results']['aggregations']['post_type']['buckets'] ?? [];
	foreach ( $post_type_bucket as $bucket ) {
		$counts[ $bucket['key'] ] = array_column( $bucket['post_status']['buckets'], 'doc_count', 'key' );
	}

	return $counts;
}

/**
 * Get post counts from database by post type.
 *
 * @return array Associative array of $post_type => $count.
 */
function get_post_counts_by_type_from_db(): array {
	$post_types = get_post_types( [ 'public' => true ] );
	$counts     = array_map(
		function( $post_type ) {
			return array_filter( (array) wp_count_posts( $post_type ) );
		},
		$post_types
	);

	return array_combine(
		$post_types,
		$counts
	);
}
