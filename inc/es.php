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
 * @return \stdClass
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
 * @return stdClass|bool Object on success, false on failure.
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
	 * @param bool     $result  Audit result.
	 * @param WP_Post  $post    Post object.
	 * @param stdClass $es_data Data in Elasticsearch.
	 */
	$result = apply_filters( 'jpsep_audit_post', true, $post, $es_data );

	if ( true !== $result ) {
		throw new Exception( $result );
	}

	return true;
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
