<?php

namespace NewspackCustomContentMigrator\Migrator\General;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;

class MetaToContentMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceMigrator|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceMigrator::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator migrate-meta-to-content',
			[ $this, 'cmd_migrate_meta_to_content' ],
			[
				'shortdesc' => 'Migrate content stored in post meta into post_content.',
				'synopsis'  => [
					[
						'type'        => 'positional',
						'name'        => 'meta-keys',
						'description' => 'Key, or list of keys, of the custom field to convert. A list (comma-separated) will be processed in the order in which they are provided to the command.',
						'optional'    => false,
						'repeating'   => false,
					],
					[
						'type'        => 'assoc',
						'name'        => 'post-id',
						'description' => 'ID of a specific post to convert.',
						'optional'    => true,
						'repeating'   => false,
					],
				],
			]
		);

	}

	/**
	 * Migrate content from custom fields to post_content
	 */
	public function cmd_migrate_meta_to_content( $args, $assoc_args ) {

		// Get the meta key(s).
		$meta_keys = explode( ',', $args[0] );
		if ( empty( $meta_keys ) ) {
			WP_CLI::error( 'No/invalid meta keys specified. Please use the --meta-keys parameter.' );
		}

		// Grab the post ID, if there is one.
		$post_id = isset( $assoc_args[ 'post-id' ] ) ? (int) $assoc_args['post-id'] : false;

		// Grab the posts to convert then.
		if ( $post_id ) {
			$posts = is_null( get_post( $post_id ) ) ? [] : [ get_post( $post_id ) ];
		} else {
			// Build an OR meta query so we get posts with at least one of the keys.
			$meta_query = [
				'relation' => 'AND',
				[
					// This part checks for the existence of at least one meta key.
					'relation' => 'OR',
					// This is where the foreach below will insert the given meta keys.
				],
			];

			// Add each of the meta keys to the 'OR' part of the query.
			foreach( $meta_keys as $key ) {
				$meta_query[0][] = [ 'key' => $key, 'compare' => 'EXISTS' ];
			}

			// Get all the posts.
			$posts = get_posts( [
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'meta_query'     => $meta_query,
			] );
		}

		if ( empty( $posts ) ) {
			WP_CLI::error( 'No posts found.' );
		}

		foreach ( $posts as $post ) {

			// Already got content? Skip it!
			if ( ! empty( get_post_field( 'post_content', $post->ID ) ) ) {
				WP_CLI::info( sprintf( 'Skipping %d because it has post_content already.', $post->ID ) );
			}

			// Set up our new post content.
			$post_content = '';

			// Loop through meta keys, in order, adding their values to post content.
			foreach ( $meta_keys as $key ) {

				$value = get_post_meta( $post->ID, $key, true );

				/**
				 * Filter the value stored in post content.
				 *
				 * Allows for the value of a post meta field storing post content
				 * to be modified before being added to post_content. This is useful
				 * for dealing with special types of content.
				 *
				 * @param $value    The value of the post meta being migrated.
				 * @param $key      The meta key name for this value.
				 * @param $post_id  The ID of the post being migrated.
				 */
				$post_content .= apply_filters( 'np_meta_to_content_value', $value, $key, $post->ID );
			}

			$update = wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => $post_content,
			] );
			if ( is_wp_error( $update ) ) {
				WP_CLI::warning( sprintf( 'Post %d failed to update.', $post->ID ) );
			} else {
				add_post_meta( $post->ID, '_np_meta_migration', sprintf(
					'Migrated meta keys "%s" in that order into post_content at %s.',
					implode( ',', $meta_keys ),
					date('c')
				) );
				WP_CLI::success( sprintf( 'Successfully updated post %d', $post->ID ) );
			}

		}

		wp_cache_flush();

	}

}
