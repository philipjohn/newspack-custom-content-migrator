<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use NewspackCustomContentMigrator\Utils\Logger;
use \NewspackCustomContentMigrator\Logic\Taxonomy as TaxonomyLogic;
use \NewspackCustomContentMigrator\Logic\Posts as PostLogic;
use \NewspackCustomContentMigrator\Logic\CoAuthorPlus as CoAuthorPlusLogic;
use \NewspackCustomContentMigrator\Logic\ContentDiffMigrator;
use \WP_CLI;

/**
 * Custom migration scripts for VTDigger.
 */
class VTDiggerMigrator implements InterfaceCommand {

	// VTD CPTs.
	const OBITUARY_CPT = 'obituary';
	const LETTERS_TO_EDITOR_CPT = 'letters_to_editor';
	const LIVEBLOG_CPT = 'liveblog';
	const OLYMPICS_BLOG_CPT = 'olympics';
	const NEWSBRIEF_CPT = 'news-brief';
	const ELECTION_CPT = 'election_brief';
	const CARTOONS_CPT = 'cartoons';
	const BUSINESSBRIEFS_CPT = 'business_briefs';

	// GAs for CPTs.
	const OBITUARIES_GA_NAME = 'VTD Obituaries';
	const LETTERS_TO_EDITOR_GA_NAME = 'Opinion';
	const NEWS_BRIEFS_GA_NAME = 'VTD staff';
	const LIVEBLOG_GA_NAME = 'Liveblogs';
	const ELECTION_GA_NAME = 'Election Briefs';
	const OLYMPICS_GA_NAME = 'Olympics Blog';

	// VTD Taxonomies.
	const COUNTIES_TAXONOMY = 'counties';
	const SERIES_TAXONOMY = 'series';

	// WP tag names.
	const ALL_LIVEBLOGS_TAG_NAME = 'news in brief';
	const LETTERSTOTHEEDITOR_TAG_NAME = 'letters to the editor';
	const SERIES_TAG_NAME = 'series';

	// WP Category names.
	const LIVEBLOGS_CAT_NAME = 'Liveblogs';
	const OLYMPICS_BLOG_CAT_NAME = 'Olympics Blog';
	const OBITUARIES_CAT_NAME = 'Obituaries';
	const ELECTION_BLOG_CAT_NAME = 'Election Blog';
	const CARTOONS_CAT_NAME = 'Cartoons';
	const BUSINESSBRIEFS_CAT_NAME = 'Business Briefs';

	// This postmeta will tell us which CPT this post was originally, e.g. 'liveblog'.
	const META_VTD_CPT = 'newspack_vtd_cpt';

	// This postmeta will tell us if authors have already been migrated for this post.
	const META_AUTHORS_MIGRATED = 'newspack_vtd_authors_migrated';

	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var TaxonomyLogic
	 */
	private $taxonomy_logic;

	/**
	 * @var PostLogic.
	 */
	private $posts_logic;

	/**
	 * @var CoAuthorPlusLogic.
	 */
	private $cap_logic;

	/**
	 * @var ContentDiffMigrator Instance.
	 */
	private $contentdiff;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->logger = new Logger();
		$this->taxonomy_logic = new TaxonomyLogic();
		$this->posts_logic = new PostLogic();
		$this->cap_logic = new CoAuthorPlusLogic();
		global $wpdb;
		$this->contentdiff = new ContentDiffMigrator( $wpdb );
	}

	/**
	 * Singleton get_instance().
	 *
	 * @return InterfaceCommand|null
	 */
	public static function get_instance() {
		$class = get_called_class();
		if ( null === self::$instance ) {
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * See InterfaceCommand::register_commands.
	 */
	public function register_commands() {
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-liveblogs',
			[ $this, 'cmd_liveblogs' ],
			[
				'shortdesc' => 'Migrates the Liveblog CPTs.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-letterstotheeditor',
			[ $this, 'cmd_letterstotheeditor' ],
			[
				'shortdesc' => 'Migrates the Letters to the Editor CPT.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-obituaries',
			[ $this, 'cmd_obituaries' ],
			[
				'shortdesc' => 'Migrates the Obituaries CPT.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-counties',
			[ $this, 'cmd_counties' ],
			[
				'shortdesc' => 'Migrates Counties taxonomy to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-series',
			[ $this, 'cmd_series' ],
			[
				'shortdesc' => 'Migrates Series taxonomy to Categories.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-authors',
			[ $this, 'cmd_authors' ],
			[
				'shortdesc' => 'Migrates ACF Authors to GAs.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-helper-remove-subcategories',
			[ $this, 'cmd_helper_remove_subcategories' ],
			[
				'shortdesc' => 'Removes subcategories of given parent category if post count is 0.',
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'parent-cat-id',
						'optional'  => false,
						'repeating' => false,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-helper-get-nonobituaries-post-ids',
			[ $this, 'cmd_helper_get_nonobituaries_post_ids' ],
			[
				'shortdesc' => 'Gets post IDs of all posts that were not obituaries.',
				'synopsis'  => [],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-import-posts-gas',
			[ $this, 'cmd_import_posts_gas' ],
			[
				'shortdesc' => "Imports all posts' associated Guest Authors from the file generated by `newspack-content-migrator co-authors-export-posts-and-gas`.",
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'php-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'imported-post-ids-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'dry-run',
						'optional'  => true,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-cartoons-cpt',
			[ $this, 'cmd_cartoons' ],
			[
				'shortdesc' => 'Convert Cartoons CTP to posts.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-businessbriefs-cpt',
			[ $this, 'cmd_businessbriefs' ],
			[
				'shortdesc' => 'Convert Business Briefs CTP to posts.',
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-restore-reusable-blocks-in-local-posts-from-live-table',
			[ $this, 'cmd_restore_reusable_blocks_in_local_posts_from_live_table' ],
			[
				'shortdesc' => "In order to restore usage of reusable blocks (which have been removed from local posts' post_content), runs through all local published posts, finds these records in live posts table (table name hardcoded) and sets local posts' post_content to those in live table.",
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-delete-pressrelease-content',
			[ $this, 'cmd_delete_pressrelease_content' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-migrate-series-redo-tags-differently',
			[ $this, 'cmd_series_redo_tags_differently' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-thumb-ids',
			[ $this, 'cmd_update_thumb_ids' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-export-from-live',
			[ $this, 'cmd_update_categories_export_from_live' ],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-import-to-staging',
			[ $this, 'cmd_update_categories_import_to_staging' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'content-diff-imported-post-ids-log-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'live-post-ids-to-categories-data-php-file',
						'optional'  => false,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-import-to-staging-1-get-post-ids-mapping',
			[ $this, 'cmd_update_categories_import_to_staging__1_get_post_ids_mapping' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'content-diff-imported-post-ids-log-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'live-post-ids-to-categories-data-php-file',
						'optional'  => false,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-import-to-staging-1-get-attachments-ids-mapping',
			[ $this, 'cmd_update_categories_import_to_staging__1_get_attachments_ids_mapping' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'content-diff-imported-post-ids-log-file',
						'optional'  => false,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-import-to-staging-2-get-all-posts-categories',
			[ $this, 'cmd_update_categories_import_to_staging__2_get_all_posts_categories' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'mapping-ids-php-file',
						'optional'  => false,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-import-to-staging-3-set-all-regular-posts-categories',
			[ $this, 'cmd_update_categories_import_to_staging__3_set_all_regular_posts_categories' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'mapping-ids-php-file',
						'optional'  => false,
						'repeating' => false,
					],
					// [
					// 	'type'      => 'assoc',
					// 	'name'      => 'path-to-logs',
					// 	'optional'  => false,
					// 	'repeating' => false,
					// ],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-import-to-staging-3-import-categories-to-staging',
			[ $this, 'cmd_update_categories_import_to_staging__3_import_categories_to_staging' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'mapping-ids-php-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'path-to-logs',
						'optional'  => false,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-update-categories-import-to-staging-3-update-attachment-ids-in-post-content-after-reimport',
			[ $this, 'cmd_update_categories_import_to_staging__3_update_att_ids_in_postcontent_after_reimport' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'att-ids-mapping-php-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'mapping-post-ids-php-file',
						'optional'  => false,
						'repeating' => false,
					],
					[
						'type'      => 'assoc',
						'name'      => 'local-hostname-aliases-csv',
						'optional'  => false,
						'repeating' => false,
					],
					// [
					// 	'type'      => 'assoc',
					// 	'name'      => 'logs-path',
					// 	'optional'  => false,
					// 	'repeating' => false,
					// ],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-reset-postcontent-and-hiding-of-featured-images',
			[ $this, 'cmd_reset_post_content_and_hiding_of_feat_imgs' ],
			[
				'synopsis'  => [
					[
						'type'      => 'assoc',
						'name'      => 'mapping-ids-php-file',
						'optional'  => false,
						'repeating' => false,
					],
				],
			],
		);
		WP_CLI::add_command(
			'newspack-content-migrator vtdigger-featimgs-hiding',
			[ $this, 'cmd_featimgs_hiding' ],
		);
	}

	public function cmd_featimgs_hiding( array $args, array $assoc_args ) {
		$was_hidden   = require '/Users/ivanuravic/www/vtdiggerstaging/app/setup5_featimgs/postIDs_where_featimg_is_hidden.php';
		$newly_hidden = require '/Users/ivanuravic/www/vtdiggerstaging/app/setup5_featimgs/hide-featured-image-newly-hidden.php';

		// $was_hidden   = [ 11, 22, 33, 44 ];
		// $newly_hidden =         [ 33, 44, 55, 66 ];

		$will_be_unhidden = array_diff( $was_hidden, $newly_hidden );
		$will_be_hidden   = array_diff( $newly_hidden, $was_hidden );
		// Other IDs will remain as they are.

		global $wpdb;
		$dont_have_feat = [];
		$have_feat = [];
		foreach ( $will_be_unhidden as $unhide_post_id ) {
			$meta = $wpdb->get_var( "select post_id from {$wpdb->postmeta} where meta_key = '_thumbnail_id' and post_id = $unhide_post_id" );
			if ( $meta ) {
				$have_feat[] = $unhide_post_id;
			} else {
				$dont_have_feat[] = $unhide_post_id;
			}
		}

		$will_be_unhidden = $have_feat;

		file_put_contents( '/Users/ivanuravic/www/vtdiggerstaging/app/setup5_featimgs/featimg_recap__when_writing_this.txt', 'Max published post on live when writing this is 547803 ' );
		file_put_contents( '/Users/ivanuravic/www/vtdiggerstaging/app/setup5_featimgs/featimg_recap__will_be_unhidden.txt', implode( "\n", $will_be_unhidden ) );
		file_put_contents( '/Users/ivanuravic/www/vtdiggerstaging/app/setup5_featimgs/featimg_recap__will_be_hidden.txt', implode( "\n", $will_be_hidden ) );

	}

	public function cmd_update_categories_import_to_staging__3_update_att_ids_in_postcontent_after_reimport( array $args, array $assoc_args ) {
		// $logs_path = $assoc_args['logs-path'];
		$local_hostname_aliases = explode( ",", $assoc_args['local-hostname-aliases-csv'] );
		if ( empty( $local_hostname_aliases ) ) {
			WP_CLI::error( 'No local hostnames provided' );
		}
		$att_ids_mapping_file = $assoc_args['att-ids-mapping-php-file'];
		if ( ! file_exists( $att_ids_mapping_file ) ) {
			WP_CLI::error( 'File not found: ' . $att_ids_mapping_file );
		}
		$post_ids_mapping_file = $assoc_args['mapping-post-ids-php-file'];
		if ( ! file_exists( $post_ids_mapping_file ) ) {
			WP_CLI::error( 'File not found: ' . $post_ids_mapping_file );
		}

		$att_ids_mapping = require $att_ids_mapping_file;
		$post_ids_mapping = require $post_ids_mapping_file;

		$this->contentdiff->update_blocks_ids( array_values( $post_ids_mapping ), $att_ids_mapping, $local_hostname_aliases, $logs_path = null );

		WP_CLI::line( 'Done' );
	}

	public function cmd_reset_post_content_and_hiding_of_feat_imgs( array $args, array $assoc_args ) {
		global $wpdb;

		// postIDs_mapping_live2staging_all.php
		$postIDs_mapping_live2staging_all_php_file = $assoc_args['mapping-ids-php-file'];
		if ( ! file_exists( $postIDs_mapping_live2staging_all_php_file ) ) {
			WP_CLI::error( 'File not found: ' . $postIDs_mapping_live2staging_all_php_file );
		}
		$post_ids_mapping = require $postIDs_mapping_live2staging_all_php_file;

		$path = 'restoredpostcontent';
		if ( ! file_exists( $path ) ) {
			mkdir( $path );
		}

		$post_ids_all = $this->posts_logic->get_all_posts_ids( 'post', [ 'publish', 'future' ] );

		$i = 0;
		foreach ( $post_ids_mapping as $live_id => $staging_id ) {
			WP_CLI::line( sprintf( "(%d)/(%d) liveID %d => stagingID %d", $i+1, count($post_ids_mapping), $live_id, $staging_id ) );

			// Restore post_content.
			$live_row   = $wpdb->get_row( $wpdb->prepare( "select ID, post_content from live_posts where ID = %d", $live_id ), ARRAY_A );
			$local_row  = $wpdb->get_row( $wpdb->prepare( "select ID, post_content from {$wpdb->posts} where ID = %d", $staging_id ), ARRAY_A );
			if ( ! $live_row || ! $local_row || ! $live_row['ID'] || ! $local_row['ID'] ) {
				$d = 1;
			}

			$wpdb->update(
				$wpdb->posts,
				[ 'post_content' => $live_row['post_content'], ],
				[ 'ID' => $staging_id, ]
			);

			file_put_contents( $path . "/{$staging_id}_1_before.txt", $local_row['post_content'] );
			file_put_contents( $path . "/{$staging_id}_2_after.txt", $live_row['post_content'] );

			$key_staging_id_in_posts_ids_all = array_search( $staging_id, $post_ids_all );
			if ( $key_staging_id_in_posts_ids_all ) {
				unset( $post_ids_all[$key_staging_id_in_posts_ids_all] );
			}

			$i++;
		}

		// save unaffected IDs
		file_put_contents( 'restoredpostcontent_postIdsStagingNotFoundOnLive.php', '<?php return ' . var_export( $post_ids_all, true ) . ';' );

	}

	public function cmd_update_categories_export_from_live( array $args, array $assoc_args ) {
		/**
		 * EXPORT CATEGORIES FROM LIVE
		 * get IDs for posts and CPTs
		 * put array into file with
		 *   - 'CPT'
		 *   - 'live_post_ID'
		 *   - 'category_tree_paths' subarrays
		 */
		global $wpdb;

		$post_types = [
			'post',
			'obituary',
			'letters_to_editor',
			'liveblog',
			'olympics',
			'news-brief',
			'election_brief',
		];
		$post_ids = $this->posts_logic->get_all_posts_ids( $post_types, [ 'publish', 'future', 'draft', 'pending', 'private', 'inherit' ] );

		// ====== Start get counties for posts.
		$post_ids_counties = [];
		// Get all term_ids, term_taxonomy_ids and term names with 'counties' taxonomy.
		$counties_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::COUNTIES_TAXONOMY
			),
			ARRAY_A
		);
		// Loop through all 'counties' terms.
		foreach ( $counties_terms as $key_county_term => $county_term ) {
			$term_id = $county_term['term_id'];
			$term_taxonomy_id = $county_term['term_taxonomy_id'];
			$term_name = $county_term['name'];
			// Get all objects for this 'county' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
			foreach ( $object_ids as $object_id ) {
				$post_ids_counties[$object_id][] = $term_name;
			}
		}
		// ====== End get counties for posts.

		$data = [];
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$post_type = $wpdb->get_var( $wpdb->prepare( "select post_type from {$wpdb->posts} where ID = %d", $post_id ) );

			$counties = isset( $post_ids_counties[$post_id] ) ? $post_ids_counties[$post_id] : [];

			$categories_data = [];
			$categories = wp_get_post_categories( $post_id );
			foreach ( $categories as $category_id ) {
				$cat_object = get_category( $category_id );
				$categories_data[] = [
					'category_term_id'=> $cat_object->term_id,
					'category_name'   => $cat_object->name,
				];
			}

			$data[ $post_id ] = [
				'live_post_id' => $post_id,
				'post_type' => $post_type,
				// Arrays[ with Subarrays[ containing 'category_term_id' and 'category_name' ] ]
				'categories' => $categories_data,
				// Array[ with string elements ]
				'counties' => $counties,
			];
		}

		// Write to php file as readable array.
		$printable_array = var_export( $data, true );
		file_put_contents( 'live_post_ids_to_categories_data.php', '<?php return ' . $printable_array . ';' );
	}

	/**
	 * Gets all live_post_ID => staging_post_ID mappings.
	 *
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_update_categories_import_to_staging__1_get_attachments_ids_mapping( array $pos_args, array $assoc_args ) {
		global $wpdb;

		/*
		 * Get all post IDs mappings.
		 */

		// Get ID mappings from DB.
		$att_ids_mapping_db = [];
		$postmeta_att_ids_updates = $wpdb->get_results(
			"select pm.post_id as new_post_id, pm.meta_value as old_post_id
			from {$wpdb->postmeta} pm
			join {$wpdb->posts} p on p.ID = pm.post_id 
			where pm.meta_key = 'newspackcontentdiff_live_id'
			and p.post_type = 'attachment';",
			ARRAY_A
		);
		foreach ( $postmeta_att_ids_updates as $postmeta_att_ids_update ) {
			$att_ids_mapping_db[ $postmeta_att_ids_update['old_post_id'] ] = $postmeta_att_ids_update['new_post_id'];
		}

		// Get ID mappings from cdiff log.
		$att_ids_mapping_cdiff = [];
		$cdiff_log = $assoc_args['content-diff-imported-post-ids-log-file'];
		if ( ! file_exists( $cdiff_log ) ) {
			WP_CLI::error( 'File not found: ' . $cdiff_log );
		}
		$cdiff_lines = explode( "\n", file_get_contents( $cdiff_log) );
		foreach ( $cdiff_lines as $cdiff_line ) {
			$line_decoded = json_decode( $cdiff_line, true );
			if ( ! $line_decoded ) {
				continue;
			}
			if ( 'attachment' != $line_decoded['post_type'] ) {
				continue;
			}
			$att_ids_mapping_cdiff[ $line_decoded['id_old'] ] = $line_decoded['id_new'];
		}

		// Merge with cdiff log data.
		foreach ( $att_ids_mapping_cdiff as $id_old => $id_new ) {
			if ( ! isset( $att_ids_mapping_db[ $id_old ] ) ) {
				$att_ids_mapping_db[ $id_old ] = $id_new;
			}
		}

		// Final tally -- map all existing post ids from all sources, plus fill in the blanks directly from live_posts.
		$att_ids_mapping = [];
		$staging_ids_not_found_on_live = [];
		$att_ids = $this->posts_logic->get_all_posts_ids( 'attachment' );
		foreach ( $att_ids as $key_att_id => $att_id ) {

			WP_CLI::line( sprintf( '(%d)/(%d) %d', $key_att_id + 1, count( $att_ids ), $att_id ) );

			// Fill from $post_ids_mapping_db.
			$old_att_id = array_search( $att_id, $att_ids_mapping_db );
			if ( $old_att_id ) {
				$att_ids_mapping[ $old_att_id ] = $att_id;
				continue;
			}

			// Fill from $post_ids_mapping_cdiff.
			$old_att_id = array_search( $att_id, $att_ids_mapping_cdiff );
			if ( $old_att_id ) {
				$att_ids_mapping[ $old_att_id ] = $att_id;
				continue;
			}

			// If still null, fill from live_posts.
			$old_att_id = null;
			$local_row = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->posts} where ID = %d", $att_id ), ARRAY_A );
			$live_row  = $wpdb->get_row( $wpdb->prepare( "select * from live_posts where ID = %d", $att_id ), ARRAY_A );
			if ( $local_row && $live_row ) {
				if (
					$live_row['post_title'] == $local_row['post_title']
					&& $live_row['post_name'] == $local_row['post_name']
					&& $live_row['post_date'] == $local_row['post_date']
				) {
					// It's the same ID.
					$old_att_id = $att_id;
				}
			}
			if ( $old_att_id ) {
				$att_ids_mapping[ $old_att_id ] = $att_id;
				continue;
			}

			// No match found.
			$staging_ids_not_found_on_live[] = $att_id;
		}

		file_put_contents( 'attIDs_mapping_live2staging_all.php', '<?php return ' . var_export( $att_ids_mapping, true ) . ';' );
		file_put_contents( 'attIDs_staging_NOT_FOUND_ON_LIVE.log', implode( "\n", $staging_ids_not_found_on_live ) );
		$hol_up = true;
	}

	/**
	 * Gets all live_post_ID => staging_post_ID mappings.
	 *
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_update_categories_import_to_staging__1_get_post_ids_mapping( array $pos_args, array $assoc_args ) {
		global $wpdb;

		/*
		 * Get all post IDs mappings.
		 */

		// Get ID mappings from DB.
		$post_ids_mapping_db = [];
		$postmeta_post_ids_updates = $wpdb->get_results(
			"select pm.post_id as new_post_id, pm.meta_value as old_post_id
			from {$wpdb->postmeta} pm
			join {$wpdb->posts} p on p.ID = pm.post_id 
			where pm.meta_key = 'newspackcontentdiff_live_id'
			and p.post_type = 'post';",
			ARRAY_A
		);
		foreach ( $postmeta_post_ids_updates as $postmeta_post_ids_update ) {
			$post_ids_mapping_db[ $postmeta_post_ids_update['old_post_id'] ] = $postmeta_post_ids_update['new_post_id'];
		}

		// Get ID mappings from cdiff log.
		$post_ids_mapping_cdiff = [];
		$cdiff_log = $assoc_args['content-diff-imported-post-ids-log-file'];
		if ( ! file_exists( $cdiff_log ) ) {
			WP_CLI::error( 'File not found: ' . $cdiff_log );
		}
		$cdiff_lines = explode( "\n", file_get_contents( $cdiff_log) );
		foreach ( $cdiff_lines as $cdiff_line ) {
			$line_decoded = json_decode( $cdiff_line, true );
			if ( ! $line_decoded ) {
				continue;
			}
			if ( 'post' != $line_decoded['post_type'] ) {
				continue;
			}
			$post_ids_mapping_cdiff[ $line_decoded['id_old'] ] = $line_decoded['id_new'];
		}

		// Merge with cdiff log data.
		foreach ( $post_ids_mapping_cdiff as $id_old => $id_new ) {
			if ( ! isset( $post_ids_mapping_db[ $id_old ] ) ) {
				$post_ids_mapping_db[ $id_old ] = $id_new;
			}
		}

		// Final tally -- map all existing post ids from all sources, plus fill in the blanks directly from live_posts.
		$post_ids_mapping = [];
		$staging_ids_not_found_on_live = [];
		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {

			WP_CLI::line( sprintf( '(%d)/(%d) %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Fill from $post_ids_mapping_db.
			$old_post_id = array_search( $post_id, $post_ids_mapping_db );
			if ( $old_post_id ) {
				$post_ids_mapping[ $old_post_id ] = $post_id;
				continue;
			}

			// Fill from $post_ids_mapping_cdiff.
			$old_post_id = array_search( $post_id, $post_ids_mapping_cdiff );
			if ( $old_post_id ) {
				$post_ids_mapping[ $old_post_id ] = $post_id;
				continue;
			}

			// If still null, fill from live_posts.
			$old_post_id = null;
			$local_row = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->posts} where ID = %d", $post_id ), ARRAY_A );
			$live_row  = $wpdb->get_row( $wpdb->prepare( "select * from live_posts where ID = %d", $post_id ), ARRAY_A );
			if ( $local_row && $live_row ) {
				if (
					$live_row['post_title'] == $local_row['post_title']
					&& $live_row['post_name'] == $local_row['post_name']
					&& $live_row['post_date'] == $local_row['post_date']
				) {
					// It's the same ID.
					$old_post_id = $post_id;
				}
			}
			if ( $old_post_id ) {
				$post_ids_mapping[ $old_post_id ] = $post_id;
				continue;
			}

			// No match found.
			$staging_ids_not_found_on_live[] = $post_id;
		}

		file_put_contents( 'postIDs_mapping_live2staging_all.php', '<?php return ' . var_export( $post_ids_mapping, true ) . ';' );
		file_put_contents( 'postIDs_staging_NOT_FOUND_ON_LIVE.log', implode( "\n", $staging_ids_not_found_on_live ) );
		$hol_up = true;
	}

	/**
	 * Reimports all categories to staging, and reimports series tags.
	 *
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_update_categories_import_to_staging__3_import_categories_to_staging( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$path_to_logs = $assoc_args['path-to-logs'];
		// postIDs_mapping_live2staging_all.php
		$postIDs_mapping_live2staging_all_php_file = $assoc_args['mapping-ids-php-file'];
		if ( ! file_exists( $postIDs_mapping_live2staging_all_php_file ) ) {
			WP_CLI::error( 'File not found: ' . $postIDs_mapping_live2staging_all_php_file );
		}

		$post_ids_mapping = require $postIDs_mapping_live2staging_all_php_file;


		/**
		 * Start import.
		 */


		WP_CLI::line( self::NEWSBRIEF_CPT . ' ...' );
		// None : 'vtd_categories__newsbriefsIDs_LiveIDsNotFoundOnStaging.log';
		$lines = explode( "\n", file_get_contents( $path_to_logs .'/'. 'vtd_categories__newsbriefsIDs.log' ) );
		foreach ( $lines as $key_line => $line ) {
			if ( empty( $line ) ) {
				continue;
			}
			// Decode line.
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				$d = 1;
			}

			// Get data.
			// json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id ] )
			$live_id = $line_decoded['live_id'];
			$staging_id = $line_decoded['staging_id'];
			// Progress.
			WP_CLI::line( sprintf( "%s (%d)/(%d) liveID %d stagingID %d", self::NEWSBRIEF_CPT, $key_line+1, count($lines)-1, $live_id, $staging_id ) );

			// Should remain uncategorized. Remove all categories.
			$set = wp_set_post_categories( $staging_id, [], $append = false );
			if ( ! $set || is_wp_error( $set ) ) {
				$d = 1;
				$this->logger->log( 'ERR_newsbriefs_catNotReset.log', $staging_id );
			}
		}


		// LIVEBLOG_CPT
		$liveblogs_parent_cat_id = get_cat_ID( self::LIVEBLOGS_CAT_NAME );
		if ( 0 == $liveblogs_parent_cat_id ) {
			$liveblogs_parent_cat_id = wp_insert_category( [ 'cat_name' => self::LIVEBLOGS_CAT_NAME ] );
		}
		if ( ! $liveblogs_parent_cat_id ) {
			WP_CLI::error( 'Could not get or create parent category: ' . self::LIVEBLOGS_CAT_NAME );
		}
		WP_CLI::line( self::LIVEBLOG_CPT . ' ...' );
		// None : 'vtd_categories__liveblogIDs_LiveIDsNotFoundOnStaging.log';
		$lines = explode( "\n", file_get_contents( $path_to_logs .'/'. 'vtd_categories__liveblogIDs.log' ) );
		foreach ( $lines as $key_line => $line ) {
			if ( empty( $line ) ) {
				continue;
			}
			// Decode line.
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				$d = 1;
			}

			// Get data.
			// // json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ] )
			$live_id = $line_decoded['live_id'];
			$staging_id = $line_decoded['staging_id'];
			$category_names = $line_decoded['category_names'];
			// Progress.
			WP_CLI::line( sprintf( "%s (%d)/(%d) liveID %d stagingID %d", self::LIVEBLOG_CPT, $key_line+1, count($lines)-1, $live_id, $staging_id ) );

			// If no categories, assign parent liveblogs category.
			if ( empty( $category_names ) ) {

				$set = wp_set_post_categories( $staging_id, [ $liveblogs_parent_cat_id ], $append = false );
				if ( ! $set || is_wp_error( $set ) ) {
					$d = 1;
					$this->logger->log( 'ERR_liveblogs_catNotSet.log', $staging_id );
				}

			} else {

				// Get or recreate this category under $parent_cat_id parent.
				$post_categories = [];
				foreach ( $category_names as $category_name ) {
					$subcat_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( $category_name, $liveblogs_parent_cat_id );
					if ( ! $subcat_id ) {
						$d = 1;
						$this->logger->log( 'ERR_liveblogs_catNotCreated.log', "cat_name '{$category_name}' liveblogs_parent_cat_id {$liveblogs_parent_cat_id}" );
					}
					$post_categories[] = $subcat_id;
				}

				$set = wp_set_post_categories( $staging_id, $post_categories, $append = false );
				if ( ! $set || is_wp_error( $set ) ) {
					$d = 1;
					$this->logger->log( 'ERR_liveblogs_catNotSet.log', $staging_id );
				}

			}
		}


		// OLYMPICS_BLOG_CPT
		// None : 'vtd_categories__olympicsIDs_LiveIDsNotFoundOnStaging.log'
		// $olympics_ids = 'vtd_categories__olympicsIDs.log';
		// json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ]
		// JUST ONE POST -- DO MANUALLY.


		// ELECTION_CPT
		$election_parent_cat_id = get_cat_ID( self::ELECTION_BLOG_CAT_NAME );
		if ( 0 == $election_parent_cat_id ) {
			$election_parent_cat_id = wp_insert_category( [ 'cat_name' => self::ELECTION_BLOG_CAT_NAME ] );
		}
		if ( ! $election_parent_cat_id ) {
			WP_CLI::error( 'Could not get or create parent category: ' . self::ELECTION_BLOG_CAT_NAME );
		}
		WP_CLI::line( self::ELECTION_CPT . ' ...' );
		// None : 'vtd_categories__electionbriefsIDs_LiveIDsNotFoundOnStaging.log'
		$lines = explode( "\n", file_get_contents( $path_to_logs .'/'. 'vtd_categories__electionbriefsIDs.log' ) );
		foreach ( $lines as $key_line => $line ) {
			if ( empty( $line ) ) {
				continue;
			}
			// Decode line.
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				$d = 1;
			}

			// Get data.
			// json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ]
			$live_id = $line_decoded['live_id'];
			$staging_id = $line_decoded['staging_id'];
			$category_names = $line_decoded['category_names'];
			// Progress.
			WP_CLI::line( sprintf( "%s (%d)/(%d) liveID %d stagingID %d", self::ELECTION_CPT, $key_line+1, count($lines)-1, $live_id, $staging_id ) );

			// If no categories, assign parent electionblogs category.
			if ( empty( $category_names ) ) {

				$set = wp_set_post_categories( $staging_id, [ $election_parent_cat_id ], $append = false );
				if ( ! $set || is_wp_error( $set ) ) {
					$d = 1;
					$this->logger->log( 'ERR_electionblogs_catNotSet.log', $staging_id );
				}

			} else {

				// Get or recreate this category under $parent_cat_id parent.
				$post_categories = [];
				foreach ( $category_names as $category_name ) {
					$subcat_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( $category_name, $election_parent_cat_id );
					if ( ! $subcat_id ) {
						$d = 1;
						$this->logger->log( 'ERR_electionblogs_catNotCreated.log', "cat_name '{$category_name}' electionblogs_parent_cat_id {$election_parent_cat_id}" );
					}
					$post_categories[] = $subcat_id;
				}

				$set = wp_set_post_categories( $staging_id, $post_categories, $append = false );
				if ( ! $set || is_wp_error( $set ) ) {
					$d = 1;
					$this->logger->log( 'ERR_electionblogs_catNotSet.log', $staging_id );
				}

			}
		}


		// LETTERS_TO_EDITOR_CPT
		$letters_missing_ids = 'vtd_categories__letterstoeditorIDs_LiveIDsNotFoundOnStaging.log';
		$letters_ids = 'vtd_categories__letterstoeditorIDs.log';
		// json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ]
		// NONE OF THESE HAVE CATEGORIES.
		// ON STAGING THESE GOT "OPINION" CATEGORY. I WASN'T AWARE OF THAT AND I WOULD HAVE REMOVED ALL CATS, BUT AM LEAVING "OPINION".
		// SPECIAL TAG IS OK, not touching that either.


		// OBITUARY_CPT
		$obituaries_cat_id = get_cat_ID( self::OBITUARIES_CAT_NAME );
		if ( ! $obituaries_cat_id ) {
			$obituaries_cat_id = wp_insert_category( [ 'cat_name' => self::OBITUARIES_CAT_NAME ] );
		}
		if ( ! $obituaries_cat_id ) {
			WP_CLI::error( 'Could not get or create parent category: ' . self::OBITUARIES_CAT_NAME );
		}
		WP_CLI::line( self::OBITUARY_CPT . ' ...' );
		// None : 'vtd_categories__obituariesIDs_LiveIDsNotFoundOnStaging.log'
		$lines = explode( "\n", file_get_contents( $path_to_logs .'/'. 'vtd_categories__obituariesIDs.log' ) );
		foreach ( $lines as $key_line => $line ) {
			if ( empty( $line ) ) {
				continue;
			}
			// Decode line.
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				$d = 1;
			}

			// Get data.
			// json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id ]
			$live_id = $line_decoded['live_id'];
			$staging_id = $line_decoded['staging_id'];
			// Progress.
			WP_CLI::line( sprintf( "%s (%d)/(%d) liveID %d stagingID %d", self::OBITUARY_CPT, $key_line+1, count($lines)-1, $live_id, $staging_id ) );

			// Just this one cat.
			$set = wp_set_post_categories( $staging_id, [ $obituaries_cat_id ], $append = false );
			if ( ! $set || is_wp_error( $set ) ) {
				$d = 1;
				$this->logger->log( 'ERR_obituaries_catNotSet.log', $staging_id );
			}

		}


		WP_CLI::line( self::COUNTIES_TAXONOMY . ' ...' );
		$county_name_to_cat_id = $this->get_county_to_category_tree();
		$counties_ids_not_found_on_staging = 'vtd_categories__countiesIDs_LiveIDsNotFoundOnStaging.log';
		$lines = explode( "\n", file_get_contents( $path_to_logs .'/'. 'vtd_categories__countiesIDs.log' ) );
		foreach ( $lines as $key_line => $line ) {
			if ( empty( $line ) ) {
				continue;
			}
			// Decode line.
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				$d = 1;
			}

			// Get data.
			// json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'county_name' => $term_name ] )
			$live_id = $line_decoded['live_id'];
			$staging_id = $line_decoded['staging_id'];
			$county_name = $line_decoded['county_name'];
			// Progress.
			WP_CLI::line( sprintf( "%s (%d)/(%d) liveID %d stagingID %d originalCountyName %s", self::COUNTIES_TAXONOMY, $key_line+1, count($lines)-1, $live_id, $staging_id, $county_name ) );

			// Get the destination category.
			$destination_cat_id = $county_name_to_cat_id[$county_name] ?? null;
			if ( is_null( $destination_cat_id ) ) {
				$d = 1;
			}
			// APPEND the destination County category.
			$set = wp_set_post_categories( $staging_id, [ $destination_cat_id ], $append = true );
			if ( ! $set || is_wp_error( $set ) ) {
				$d = 1;
				$this->logger->log( 'ERR_counties_catNotSet.log', $staging_id );
			}
		}


		WP_CLI::line( self::SERIES_TAXONOMY . ' ...' );
		$series_ids_not_found_on_staging = 'vtd_tags__seriesIDs_LiveIDsNotFoundOnStaging.log';
		$lines = explode( "\n", file_get_contents( $path_to_logs .'/'. 'vtd_tags__seriesIDs.log' ) );
		foreach ( $lines as $key_line => $line ) {
			if ( empty( $line ) ) {
				continue;
			}
			// Decode line.
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				$d = 1;
			}

			// Get data.
			// {"live_id":"192218","staging_id":"444938","tag_name":"Bill Mathis"}
			$live_id = $line_decoded['live_id'];
			$staging_id = $line_decoded['staging_id'];
			$tag_name = $line_decoded['tag_name'];
			// Progress.
			WP_CLI::line( sprintf( "%s (%d)/(%d) liveID %d stagingID %d seriesName %s", self::SERIES_TAXONOMY, $key_line+1, count($lines)-1, $live_id, $staging_id, $tag_name ) );

			// Tags, append.
			// $series_tag_specific_name;
			$set = wp_set_post_tags( $staging_id, [ self::SERIES_TAG_NAME, $tag_name ], $append = true );
			if ( ! $set || is_wp_error( $set ) ) {
				$d = 1;
				$this->logger->log( 'ERR_series_tagNotSet.log', $staging_id );
			}

		}

	}

	public function cmd_update_categories_import_to_staging__3_set_all_regular_posts_categories( array $pos_args, array $assoc_args ) {
		global $wpdb;

		// postIDs_mapping_live2staging_all.php
		$postIDs_mapping_live2staging_all_php_file = $assoc_args['mapping-ids-php-file'];
		if ( ! file_exists( $postIDs_mapping_live2staging_all_php_file ) ) {
			WP_CLI::error( 'File not found: ' . $postIDs_mapping_live2staging_all_php_file );
		}
		$post_ids_mapping = require $postIDs_mapping_live2staging_all_php_file;
		// $path_to_logs     = $assoc_args['path-to-logs'];

		$post_type_post_ids_staging = $wpdb->get_col(
			"select ID
			from vtdWP_posts vwp 
			where vwp.post_type = 'post'
			and vwp.ID not in(
				select wpm.post_id ID 
				from vtdWP_postmeta wpm
				join vtdWP_posts wp
				on wp.ID = wpm.post_id 
				where wpm.meta_key = 'newspack_vtd_cpt'	
			);"
		);


		// Get last processed key.
		$log_last_processed_post_id_key = 'regularposts__lastProcessedPostIdKey.log';
		$last_processed_post_id_key = null;
		if ( file_exists( $log_last_processed_post_id_key ) ) {
			$last_processed_post_id_key = file_get_contents( $log_last_processed_post_id_key );
		}

		foreach ( $post_type_post_ids_staging as $key_staging_id => $staging_id ) {

			if ( $last_processed_post_id_key && $key_staging_id <= $last_processed_post_id_key ) {
				continue;
			}

			WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_staging_id+1, count($post_type_post_ids_staging), $staging_id ) );

			// Get live_id.
			$live_id = array_search( $staging_id, $post_ids_mapping );
			if ( ! $live_id ) {
				$this->logger->log( 'regularposts_stagingIds_NotFoundLiveIds.log', $staging_id );
				continue;
			}

			// Get categories.
			$categories = $this->get_categories_for_post( 'live_', $live_id );
			// Remove categories.
			if ( 1 == count( $categories ) && ( 'Uncategorized' == $categories[0]['name'] ) ) {
				$set = wp_set_post_categories( $staging_id, [], $append = false );
				continue;
			}

			// Get local category IDs.
			$post_cat_ids = [];
			foreach ( $categories as $category ) {
				$cat_name = $category['name'];
				$cat_id = get_cat_ID( $cat_name );
				if ( ! $cat_id ) {
					// Create.
					$cat_id = wp_insert_category( [ 'cat_name' => $cat_name ] );
					if ( ! $cat_id || is_wp_error( $cat_id ) ) {
						$d = 1;
						$this->logger->log( 'regularposts_ERRCatNotInserted.log', $cat_name );
						continue;
					}
				}

				$post_cat_ids[] = $cat_id;
			}

			// Set cats -- do not append.
			$set = wp_set_post_categories( $staging_id, $post_cat_ids, $append = false  );
			if ( false == $set || is_wp_error( $set ) ) {
				$this->logger->log( 'regularposts_ERRSettingCats.log', sprintf( "ErrSettingCats postID %d cats '%s' err '%s'", $post_id, json_encode( $all_post_categories ), ( is_wp_error( $set ) ? $set->get_error_message() : 'na' ) ), $this->logger::WARNING );
			}

			file_put_contents( $log_last_processed_post_id_key, $key_staging_id );

		}

		// Execution planning.
		// series is a taxonomy. regular post_type = 'posts' are using it.
		// should we reassign categories for regular posts like this then?
		// regular posts should get reassigned categories first of all, because additional modifiers are,
		//      categories
		//      counties -- appended
		//      (tags, not cats -- series)
		// So solution is to run category reassignment for regular posts first, then run for counties once again
		//  --> appending twice won't change anything. TEST THIS


		// ==============================
		// Alternative way for getting regular post_type = 'post' IDs is as follows, though more complicated.

		// // Get all IDs from logs.
		// $files = [
		// 	'vtd_categories__countiesIDs.log',
		// 	'vtd_categories__electionbriefsIDs.log',
		// 	'vtd_categories__letterstoeditorIDs.log',
		// 	'vtd_categories__liveblogIDs.log',
		// 	'vtd_categories__newsbriefsIDs.log',
		// 	'vtd_categories__obituariesIDs.log',
		// 	'vtd_categories__olympicsIDs.log',
		// ];
		//
		// $all_cpt_ids = [];
		// foreach ( $files as $file ) {
		// 	$lines = explode( "\n", file_get_contents( $path_to_logs . '/' . $file ) );
		// 	foreach ( $lines as $key_line => $line ) {
		// 		if ( empty( $line ) ) {
		// 			continue;
		// 		}
		// 		// Decode line.
		// 		$line_decoded = json_decode( $line, true );
		// 		if ( ! $line_decoded ) {
		// 			$d = 1;
		// 		}
		//
		// 		// Get data
		// 		$live_id = $line_decoded['live_id'];
		// 		$staging_id = $line_decoded['staging_id'];
		// 		$all_cpt_ids[ $live_id ] = $staging_id;
		// 	}
		// }
		//
		// // we don't need to work on these because they're on live only, and we're reimporting on staging.
		// $files_live_ids_not_found_on_staging = [
		// 	'vtd_categories__countiesIDs_LiveIDsNotFoundOnStaging.log',
		// 	'vtd_categories__letterstoeditorIDs_LiveIDsNotFoundOnStaging.log',
		// ];

		// Get all posts on staging which weren't cpts.
		// Get their categories.

	}

	/**
	 * Pulls all categories from all live posts into log files.
	 * Pulls series tags into log files as well.
	 *
	 * @param array $pos_args
	 * @param array $assoc_args
	 *
	 * @return void
	 */
	public function cmd_update_categories_import_to_staging__2_get_all_posts_categories( array $pos_args, array $assoc_args ) {
		global $wpdb;

		// postIDs_mapping_live2staging_all.php
		$postIDs_mapping_live2staging_all_php_file = $assoc_args['mapping-ids-php-file'];
		if ( ! file_exists( $postIDs_mapping_live2staging_all_php_file ) ) {
			WP_CLI::error( 'File not found: ' . $postIDs_mapping_live2staging_all_php_file );
		}
		$post_ids_mapping = require $postIDs_mapping_live2staging_all_php_file;

		/*
		 * == cmd_liveblogs
		 */

		WP_CLI::log( self::NEWSBRIEF_CPT . ' ...' );
		$live_ids = $wpdb->get_col( $wpdb->prepare( "select ID from live_posts where post_type=%s ;", self::NEWSBRIEF_CPT ) );
		foreach ( $live_ids as $live_id ) {
			$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
			if ( ! is_null( $staging_id ) ) {
				$this->logger->log( 'vtd_categories__newsbriefsIDs.log', json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id ] ) );
			} else {
				$this->logger->log( 'vtd_categories__newsbriefsIDs_LiveIDsNotFoundOnStaging.log', $live_id );
			}
		}

		WP_CLI::log( self::LIVEBLOG_CPT . ' ...' );
		$live_ids = $wpdb->get_col( $wpdb->prepare( "select ID from live_posts where post_type=%s;", self::LIVEBLOG_CPT ) );
		foreach ( $live_ids as $live_id) {
			$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
			if ( ! is_null( $staging_id ) ) {
				$categories = $this->get_categories_for_post( 'live_', $live_id );

				$category_names = [];
				if ( ! empty( $categories ) ) {
					// Get category names.
					foreach ( $categories as $category ) {
						// Check if any parents are != 0.
						if ( 0 != $category['parent'] ) {
							// Non-0 parent.
							$d=1;
						}
						if ( 'Uncategorized' == $category['name'] ) {
							continue;
						}

						$category_names[] = html_entity_decode( $category['name'] );
					}
				}

				$this->logger->log( 'vtd_categories__liveblogIDs.log',
					json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ] )
				);
			} else {
				$this->logger->log( 'vtd_categories__liveblogIDs_LiveIDsNotFoundOnStaging.log', $live_id );
			}
		}

		// OLYMPICS_BLOG_CPT
		WP_CLI::log( self::OLYMPICS_BLOG_CPT . ' ...' );
		$live_ids = $wpdb->get_col( $wpdb->prepare( "select ID from live_posts where post_type=%s;", self::OLYMPICS_BLOG_CPT ) );
		foreach ( $live_ids as $live_id) {
			$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
			if ( ! is_null( $staging_id ) ) {
				$categories = $this->get_categories_for_post( 'live_', $live_id );

				$category_names = [];
				if ( ! empty( $categories ) ) {
					// Get category names.
					foreach ( $categories as $category ) {
						// Check if any parents are != 0.
						if ( 0 != $category['parent'] ) {
							// Non-0 parent.
							$d=1;
						}
						if ( 'Uncategorized' == $category['name'] ) {
							continue;
						}

						$category_names[] = html_entity_decode( $category['name'] );
					}
				}

				$this->logger->log( 'vtd_categories__olympicsIDs.log',
					json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ] )
				);
			} else {
				$this->logger->log( 'vtd_categories__olympicsIDs_LiveIDsNotFoundOnStaging.log', $live_id );
			}
		}

		// ELECTION_CPT
		WP_CLI::log( self::ELECTION_CPT . ' ...' );
		$live_ids = $wpdb->get_col( $wpdb->prepare( "select ID from live_posts where post_type=%s ;", self::ELECTION_CPT ) );
		foreach ( $live_ids as $live_id) {
			$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
			if ( ! is_null( $staging_id ) ) {
				$categories = $this->get_categories_for_post( 'live_', $live_id );
				$category_names = [];
				if ( ! empty( $categories ) ) {
					// Get category names.
					foreach ( $categories as $category ) {
						// Check if any parents are != 0.
						if ( 0 != $category['parent'] ) {
							// Non-0 parent.
							$d=1;
						}
						if ( 'Uncategorized' == $category['name'] ) {
							continue;
						}
						$category_names[] = html_entity_decode( $category['name'] );
					}
				}

				$this->logger->log( 'vtd_categories__electionbriefsIDs.log',
					json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ] )
				);
			} else {
				$this->logger->log( 'vtd_categories__electionbriefsIDs_LiveIDsNotFoundOnStaging.log', $live_id );
			}
		}

		WP_CLI::log( self::LETTERS_TO_EDITOR_CPT . ' ...' );
		$live_ids = $wpdb->get_col( $wpdb->prepare( "select ID from live_posts where post_type = %s;", self::LETTERS_TO_EDITOR_CPT ) );
		foreach ( $live_ids as $live_id) {
			$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
			if ( ! is_null( $staging_id ) ) {
				$categories = $this->get_categories_for_post( 'live_', $live_id );
				$category_names = [];
				if ( ! empty( $categories ) ) {
					// Get category names.
					foreach ( $categories as $category ) {
						// Check if any parents are != 0.
						if ( 0 != $category['parent'] ) {
							// Non-0 parent.
							$d=1;
						}
						if ( 'Uncategorized' == $category['name'] ) {
							continue;
						}
						$category_names[] = html_entity_decode( $category['name'] );
					}
				}

				$this->logger->log( 'vtd_categories__letterstoeditorIDs.log',
					json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'category_names' => $category_names ] )
				);
			} else {
				$this->logger->log( 'vtd_categories__letterstoeditorIDs_LiveIDsNotFoundOnStaging.log', $live_id );
			}
		}

		// OBITUARY_CPT
		$live_ids = $wpdb->get_col( $wpdb->prepare( "select ID from live_posts where post_type='%s';", self::OBITUARY_CPT ) );
		foreach ( $live_ids as $live_id ) {
			$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
			if ( ! is_null( $staging_id ) ) {
				$this->logger->log( 'vtd_categories__obituariesIDs.log', json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id ] ) );
			} else {
				$this->logger->log( 'vtd_categories__obituariesIDs_LiveIDsNotFoundOnStaging.log', $live_id );
			}
		}

		// COUNTIES_TAXONOMY
		// Get all term_ids, term_taxonomy_ids and term names with 'counties' taxonomy.
		$counties_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name
				from live_term_taxonomy tt
				join live_terms t on t.term_id = tt.term_id
				where tt.taxonomy = '%s';",
				self::COUNTIES_TAXONOMY
			),
			ARRAY_A
		);
		// Loop through all 'counties' terms.
		foreach ( $counties_terms as $key_county_term => $county_term ) {
			$term_id = $county_term['term_id'];
			$term_taxonomy_id = $county_term['term_taxonomy_id'];
			$term_name = html_entity_decode( $county_term['name'] );

			// Get all objects for this 'county' term's term_taxonomy_id.
			$live_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from live_term_relationships vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
			foreach ( $live_ids as $live_id ) {
				$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
				if ( ! is_null( $staging_id ) ) {
					$this->logger->log( 'vtd_categories__countiesIDs.log', json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'county_name' => $term_name ] ) );
				} else {
					$this->logger->log( 'vtd_categories__countiesIDs_LiveIDsNotFoundOnStaging.log', $live_id );
				}
			}
		}

		// SERIES_TAXONOMY
		$series_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name
				from live_term_taxonomy tt
				join live_terms t on t.term_id = tt.term_id
				where tt.taxonomy = '%s';",
				self::SERIES_TAXONOMY
			),
			ARRAY_A
		);
		// Loop through all 'series' terms.
		foreach ( $series_terms as $key_series_term => $series_term ) {
			$term_id = $series_term['term_id'];
			$term_taxonomy_id = $series_term['term_taxonomy_id'];
			$term_name = html_entity_decode( $series_term['name'] );
			// Get all objects for this 'series' term's term_taxonomy_id.
			$live_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from live_term_relationships vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
			foreach ( $live_ids as $live_id ) {
				$staging_id = isset( $post_ids_mapping[$live_id] ) ? $post_ids_mapping[$live_id] : null;
				if ( ! is_null( $staging_id ) ) {
					$this->logger->log( 'vtd_tags__seriesIDs.log', json_encode( [ 'live_id' => $live_id, 'staging_id' => $staging_id, 'tag_name' => $term_name ] ) );
				} else {
					$this->logger->log( 'vtd_tags__seriesIDs_LiveIDsNotFoundOnStaging.log', $live_id );
				}
			}
		}

	}

	/**
	 * @param $table_prefix
	 * @param $post_id
	 *
	 * @return array {
	 * Associative array with sub arrays containing data about categories with keys:
	 *      'term_id' Cat term_id
	 *      'parent' Cat parent
	 *      'name' Cat name
	 * }
	 */
	private function get_categories_for_post( $table_prefix, $post_id ) {
		global $wpdb;

		$table_term_relationships = esc_sql( $table_prefix . 'term_relationships' );
		$table_term_taxonomy = esc_sql( $table_prefix . 'term_taxonomy' );
		$table_terms = esc_sql( $table_prefix . 'terms' );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"select wtt.term_id, wtt.parent, wt.name
				from {$table_term_relationships} wtr 
				join {$table_term_taxonomy} wtt on wtt.term_taxonomy_id = wtr.term_taxonomy_id 
				join {$table_terms} wt on wt.term_id = wtt.term_id
				where wtr.object_id = %d
				and wtt.taxonomy = 'category'; ",
				$post_id
			),
			ARRAY_A
		);

		return $results;
	}

	public function cmd_update_categories_import_to_staging( array $pos_args, array $assoc_args ) {
		/**
		 * IMPORT CATEGORIES TO STAGING
		 * get file data
		 * loop through all posts, match them by meta and cdiff log
		 *      x skip by press release -- no need, since those have been deleted from staging already
		 *      - merge cdiff log with meta
		 *      - log if post not found
		 * get new categories IDs
		 *      - use cdiff log {"category_term_id_updates":{"1":"1","17":null,"23":"163962","26":null,"27":null,"29":null,"30":null,"43":null,"45":null,"48":null,"164095":"161357","164103":"162353","164106":"163963","164108":"161361","161316":null,"164142":"163902","164145":"163901","155205":null,"8011":"8011","164096":"162362","164097":"162363","164098":"162364","164099":"162365","164100":"162366","164101":"162367","164102":"163964","164105":"162368","164109":"161362","164115":"161368","164119":"161372","164123":"161376","164110":"161363","164112":"161365","164113":"161366","164114":"161367","164111":"161364","164116":"161369","164117":"161370","164118":"161371","164120":"161373","164121":"161374","164122":"161375","164124":"161377","164125":"161378","164126":"161379","164127":"161380"}}
		 *      - compare names, log if not found
		 * modify list according to custom scripts
		 * update post categories
		 */
		global $wpdb;

		$mem_usage_mb_start = memory_get_usage()/1024/1024;

		// Get ID mappings from cdiff log.
		$cdiff_log = $assoc_args['content-diff-imported-post-ids-log-file'];
		if ( ! file_exists( $cdiff_log ) ) {
			WP_CLI::error( 'File not found: ' . $cdiff_log );
		}
		$cdiff_ids_mappings = [];
		$cdiff_lines = explode( "\n", file_get_contents( $cdiff_log) );
		foreach ( $cdiff_lines as $cdiff_line ) {
			$line_decoded = json_decode( $cdiff_line, true );
			if ( ! $line_decoded ) {
				continue;
			}
			if ( 'post' != $line_decoded['post_type'] ) {
				continue;
			}
			$cdiff_ids_mappings[ $line_decoded['id_old'] ] = $line_decoded['id_new'];
		}

		// Get live post ID category data from pre-generated php file.
		$old_post_ids_to_categories_data = require $assoc_args['live-post-ids-to-categories-data-php-file'];
		if ( ! $old_post_ids_to_categories_data ) {
			WP_CLI::error( 'File not found: live_post_ids_to_categories_data.php' );
		}
		$press_release_ga_id = 410477;
		$press_release_wpuser_id = 2;

		// Get post ID mappings from DB.
		$post_ids_mapping = [];
		$postmeta_post_ids_updates = $wpdb->get_results(
			"select pm.post_id as new_post_id, pm.meta_value as old_post_id
			from {$wpdb->postmeta} pm
			join {$wpdb->posts} p on p.ID = pm.post_id 
			where pm.meta_key = 'newspackcontentdiff_live_id'
			and p.post_type = 'post';",
			ARRAY_A
		);
		foreach ( $postmeta_post_ids_updates as $postmeta_post_ids_update ) {
			$post_ids_mapping[ $postmeta_post_ids_update['old_post_id'] ] = $postmeta_post_ids_update['new_post_id'];
		}

		// Merge with cdiff log data.
		foreach ( $cdiff_ids_mappings as $id_old => $id_new ) {
			if ( ! isset( $post_ids_mapping[ $id_old ] ) ) {
				$post_ids_mapping[ $id_old ] = $id_new;
			}
		}

		// prepare special CPT categories
		$liveblog_cpt_special_category_id = get_cat_ID( self::LIVEBLOGS_CAT_NAME );
		if ( ! $liveblog_cpt_special_category_id ) {
			$liveblog_cpt_special_category_id = wp_insert_category( [ 'cat_name' => self::LIVEBLOGS_CAT_NAME ] );
		}
		$olympics_cpt_special_category_id = get_cat_ID( self::OLYMPICS_BLOG_CAT_NAME );
		if ( ! $olympics_cpt_special_category_id ) {
			$olympics_cpt_special_category_id = wp_insert_category( [ 'cat_name' => self::OLYMPICS_BLOG_CAT_NAME ] );
		}
		$election_cpt_special_category_id = get_cat_ID( self::ELECTION_BLOG_CAT_NAME );
		if ( ! $election_cpt_special_category_id ) {
			$election_cpt_special_category_id = wp_insert_category( [ 'cat_name' => self::ELECTION_BLOG_CAT_NAME ] );
		}
		$obituaries_cat_id = get_cat_ID( self::OBITUARIES_CAT_NAME );
		if ( ! $obituaries_cat_id ) {
			$obituaries_cat_id = wp_insert_category( [ 'cat_name' => self::OBITUARIES_CAT_NAME ] );
		}
		$businessbriefs_cat_id = get_cat_ID( self::BUSINESSBRIEFS_CAT_NAME );
		if ( 0 == $businessbriefs_cat_id ) {
			$businessbriefs_cat_id = wp_insert_category( [ 'cat_name' => self::BUSINESSBRIEFS_CAT_NAME ] );
		}
		$cartoons_cat_id = get_cat_ID( self::CARTOONS_CAT_NAME );
		if ( 0 == $cartoons_cat_id ) {
			$cartoons_cat_id = wp_insert_category( [ 'cat_name' => self::CARTOONS_CAT_NAME ] );
		}

		// Get new Counties Categories IDs.
		$county_name_to_cat_id = $this->get_county_to_category_tree();

		// Get last processed key.
		$log_last_processed_post_id_key = 'vtdigger-update-categories-import-to-staging__lastProcessedPostIdKey.log';
		$last_processed_post_id_key = null;
		if ( file_exists( $log_last_processed_post_id_key ) ) {
			$last_processed_post_id_key = file_get_contents( $log_last_processed_post_id_key );
		}

		// loop through all posts, match them by meta and cdiff log
		// 		 - log if post not found
		// 		 - reset and reassign categories to posts
		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {

// $test_ids = [
// 	// 282679,476380,477523,478014,479374,479686,480119,480235,480315,409660,
// ];
// if ( ! in_array( $post_id, $test_ids ) ) {
// 	continue;
// }

// if ( 423976 != $post_id ) {
// 	continue;
// }

			if ( $last_processed_post_id_key && $key_post_id <= $last_processed_post_id_key ) {
				continue;
			}

			WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Get old post ID.
			$old_post_id = array_search( $post_id, $post_ids_mapping );
			if ( ! $old_post_id ) {

				// Search in `live_posts` and check if ID same.
				$local_row = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->posts} where ID = %d", $post_id ), ARRAY_A );
				$live_row = $wpdb->get_row( $wpdb->prepare( "select * from live_posts where ID = %d", $post_id ), ARRAY_A );
				if ( $local_row && $live_row ) {
					if (
						$live_row['post_title'] == $local_row['post_title']
						&& $live_row['post_name'] == $local_row['post_name']
						&& $live_row['post_date'] == $local_row['post_date']
					) {
						// Same ID.
						$old_post_id = $post_id;
						$this->logger->log( 'vtdigger-update-categories-import-to-staging__matchedSamePostIDLiveAsStaging.log', $post_id, false );
					}
				}
				// Log not found ID.
				if ( ! $old_post_id ) {
					$this->logger->log( 'vtdigger-update-categories-import-to-staging__postIdsStagingNotFoundOnLive.log', $post_id, false );
					WP_CLI::line( 'Live postID not found, skip' );
					file_put_contents( $log_last_processed_post_id_key, $key_post_id );
					continue;
				}
			} else {
				$live_row = $local_row = null;
			}

			//
			// Get post categories data.
			//
			$post_data = $old_post_ids_to_categories_data[ $old_post_id ];
			if ( ! $post_data && $old_post_id ) {
				if ( ! $live_row ) {
					$live_row = $wpdb->get_row( $wpdb->prepare( "select * from live_posts where ID = %d", $old_post_id ), ARRAY_A );
				}
				if ( $live_row && ( 'business_briefs' == $live_row['post_type'] ) ) {
					$post_data['post_type'] = self::BUSINESSBRIEFS_CPT;
				} elseif ( $live_row && ( self::CARTOONS_CPT == $live_row['post_type'] ) ) {
					$post_data['post_type'] = self::CARTOONS_CPT;
				} else {
					$missing_cpt = 1;
				}
			}
			if ( ! $post_data ) {
				$hold_up = 1;
			}

			// Get plain categories IDs.
			// $post_data['categories'] is Array[ with Subarrays[ containing 'category_term_id' and 'category_name' ] ]
			$categories_plain_ids = [];
			// Newsbriefs should remain uncategorized.
			if ( self::NEWSBRIEF_CPT == $post_data['post_type'] ) {
				$categories_plain_ids = [];
			} elseif ( self::BUSINESSBRIEFS_CPT == $post_data['post_type'] ) {
				// Business briefs should get just that one category, no appending to existing categories.
				$categories_plain_ids[] = $businessbriefs_cat_id;
			} elseif ( self::CARTOONS_CPT == $post_data['post_type'] ) {
				// Cartoons should get just that one category, no appending to existing categories.
				$categories_plain_ids[] = $cartoons_cat_id;
			} elseif ( self::OBITUARY_CPT == $post_data['post_type'] ) {
				// Obituaries should get just that one category, no appending to existing categories.
				$categories_plain_ids[] = $obituaries_cat_id;
			} else {
				foreach ( $post_data['categories'] as $category_data ) {
					$cat_data_id = get_cat_ID( $category_data['category_name'] );
					if ( 0 == $cat_data_id || ! $cat_data_id ) {
						$cat_data_id = wp_insert_category( [ 'cat_name' => $category_data['category_name'] ] );
					}
					$categories_plain_ids[] = $cat_data_id;
				}
			}

			// Get CPT special category.
			$cpt_special_category_id = null;
			if ( self::LIVEBLOG_CPT == $post_data['post_type'] ) {
				$cpt_special_category_id = $liveblog_cpt_special_category_id;
			} elseif ( self::OLYMPICS_BLOG_CPT == $post_data['post_type'] ) {
				$cpt_special_category_id = $olympics_cpt_special_category_id;
			} elseif ( self::ELECTION_CPT == $post_data['post_type'] ) {
				$cpt_special_category_id = $election_cpt_special_category_id;
			}
			// All liveblogs categories are subcategories of that liveblog.
			if ( ! is_null( $cpt_special_category_id ) && ! empty( $categories_plain_ids ) ) {
				$new_categories_plain_ids = [];
				foreach ( $categories_plain_ids as $category_plain_id ) {
					$category_name = get_cat_name( $category_plain_id );
					$new_category_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( $category_name, $cpt_special_category_id );
					if ( is_null( $new_category_id ) || ! $new_category_id ) {
						$hold_up = 1;
					}
					$new_categories_plain_ids[] = $new_category_id;
				}
				if ( count( $new_categories_plain_ids ) != count( $categories_plain_ids ) ) {
					$hold_up = 1;
				}
				$categories_plain_ids = $new_categories_plain_ids;
			}

			// Get Counties categories.
			$counties_categories = [];
			foreach ( $post_data['counties'] as $county_name ) {
				if ( empty( $county_name ) ) {
					$hold_up = 1;
				}
				if ( ! isset( $county_name_to_cat_id[ $county_name ] ) ) {
					throw new \Exception( 'County not found: ' . $county_name );
				}
				$counties_categories[] = $county_name_to_cat_id[ $county_name ];
			}

			// Get all post categories to this array.
			$all_post_categories = [];
			// Add CPT cat.
			if ( $cpt_special_category_id ) {
				$all_post_categories[] = $cpt_special_category_id;
			}
			// Add plain Categories.
			if ( ! empty( $categories_plain_ids ) ) {
				foreach ( $categories_plain_ids as $category_plain_id ) {
					$all_post_categories[] = $category_plain_id;
				}
			}
			// Add counties Categories.
			if ( ! empty( $counties_categories ) ) {
				foreach ( $counties_categories as $county_category_id ) {
					$all_post_categories[] = $county_category_id;
				}
			}

			// Set cats.
			if ( ! empty( $all_post_categories ) ) {
				$set = wp_set_post_categories( $post_id, $all_post_categories, $append = false  );
				if ( false == $set || is_wp_error( $set ) ) {
					$this->logger->log( 'vtdigger-update-categories-import-to-staging__errSettingCats.log', sprintf( "ErrSettingCats postID %d cats '%s' err '%s'", $post_id, json_encode( $all_post_categories ), ( is_wp_error( $set ) ? $set->get_error_message() : 'na' ) ), $this->logger::WARNING );
				}
				$this->logger->log( 'vtdigger-update-categories-import-to-staging__postIdsStagingUpdated.log', sprintf( "Updated postID %d total cats %d", $post_id, count( $all_post_categories ) ), $this->logger::SUCCESS );
			} else {
				$this->logger->log( 'vtdigger-update-categories-import-to-staging__postIdsStagingNoCategories.log', "No cats for postID ${post_id}", $this->logger::WARNING );
			}

			file_put_contents( $log_last_processed_post_id_key, $key_post_id );
		}

		$mem_usage_mb_end = memory_get_usage()/1024/1024;
		WP_CLI::line( 'Done.' );
	}

	public function cmd_update_thumb_ids( array $args, array $assoc_args ) {
		global $wpdb;

		$log_last_processed_post_id_key = 'vtdigger-update-thumb-ids__lastProcessedPostIdKey.log';

		// - get all att ID updates from DB
		//     - merge with att ID updates from CDiff log
		// - do a script which updates meta from old to new
		//     - log post IDs

		$results = $wpdb->get_results(
			" select pm.post_id as new_att_id, pm.meta_value as old_att_id
			from {$wpdb->postmeta} pm
			join {$wpdb->posts} p on p.ID = pm.post_id 
			where pm.meta_key = 'newspackcontentdiff_live_id'
			and p.post_type = 'attachment'; ",
			ARRAY_A
		);
		$att_ids_updates = [];
		foreach ( $results as $result ) {
			$att_ids_updates[ $result['old_att_id'] ] = $result['new_att_id'];
		}

		// // Also check /tmp/launch/content-diff__imported-post-ids.log for additional att ID updates.
		// $att_ids_updates_file = [];
		// $lines = explode( "\n", file_get_contents( '/Users/ivanuravic/www/vtdiggerstaging/app/setup/content-diff__imported-post-ids.log' ) );
		// foreach ( $lines as $line ) {
		// 	$line_decoded = json_decode( $line, true );
		// 	if ( ! $line_decoded ) {
		// 		continue;
		// 	}
		// 	if ( 'post' != $line_decoded['post_type'] ) {
		// 		continue;
		// 	}
		// 	$att_ids_updates_file[$line_decoded['id_old'] ] = $line_decoded['id_new'];
		// }
		// Hardcoded unique att ID updates from CDiff log obtained like shown in commented code here:
		$att_ids_updates_file = [ 339834 => 542478, 339970 => 542479, 417729 => 542480, 417730 => 542481, 417769 => 542482, 417866 => 542483, 417907 => 542484, 417958 => 542485, 417983 => 542486, 417985 => 542487, 418000 => 542488, 418071 => 542489, 418075 => 542490, 418093 => 542491, 418096 => 542492, 418110 => 542493, 418200 => 542494, 418202 => 542495, 418214 => 542496, 418257 => 542497, 418284 => 542498, 418285 => 542499, 418299 => 542500, 418309 => 542501, 418310 => 542502, 418317 => 542503, 418318 => 542504, 418376 => 542505, 418437 => 542506, 418454 => 542507, 418485 => 542508, 418498 => 542509, 418550 => 542510, 418595 => 542511, 418608 => 542512, 418703 => 542513, 418724 => 542514, 418772 => 542515, 418848 => 542516, 418856 => 542517, 418861 => 542518, 418864 => 542519, 418872 => 542520, 418876 => 542521, 418887 => 542522, 418952 => 542523, 418974 => 542524, 419011 => 542525, 419053 => 542526, 419070 => 542527, 419095 => 542528, 419114 => 542529, 419115 => 542530, 419117 => 542531, 419139 => 542532, 419173 => 542533, 419199 => 542534, 419205 => 542535, 419271 => 542536, 419272 => 542537, 419273 => 542538, 419286 => 542539, 419287 => 542540, 419288 => 542541, 419289 => 542542, 419290 => 542543, 419324 => 542544, 419327 => 542545, 419328 => 542546, 419347 => 542547, 419408 => 542548, 419409 => 542549, 419503 => 542550, 419527 => 542551, 419539 => 542552, 419568 => 542553, 419890 => 542554, 420703 => 542555, 420900 => 542556, 421151 => 542557, 421250 => 542558, 421854 => 542559, 306369 => 542560, 339051 => 542561, 410394 => 542562, 410761 => 542563, 410999 => 542564, 411093 => 542565, 411095 => 542566, 411097 => 542567, 411211 => 542568, 411688 => 542569, 411690 => 542570, 411945 => 542571, 412123 => 542572, 412223 => 542573, 412714 => 542574, 417690 => 542575, 417720 => 542576, 417722 => 542577, 417726 => 542578, 417738 => 542579, 417751 => 542580, 417824 => 542581, 417848 => 542582, 417873 => 542583, 417877 => 542584, 417880 => 542585, 417884 => 542586, 417897 => 542587, 417862 => 542588, 417882 => 542589, 417857 => 542590, 417854 => 542591, 417850 => 542592, 417793 => 542593, 417786 => 542594, 417783 => 542595, 417780 => 542596, 417895 => 542597, 417871 => 542598, 417911 => 542599, 417924 => 542600, 417928 => 542601, 417932 => 542602, 417936 => 542603, 417941 => 542604, 417905 => 542605, 417943 => 542606, 417903 => 542607, 417921 => 542608, 417919 => 542609, 417917 => 542610, 417915 => 542611, 417953 => 542612, 417957 => 542613, 417979 => 542614, 417990 => 542615, 418002 => 542616, 418017 => 542617, 418041 => 542618, 418054 => 542619, 418056 => 542620, 418059 => 542621, 418062 => 542622, 418067 => 542623, 417977 => 542624, 418069 => 542625, 417974 => 542626, 417970 => 542627, 417972 => 542628, 418077 => 542629, 418102 => 542630, 418109 => 542631, 418122 => 542632, 418153 => 542633, 418159 => 542634, 418160 => 542635, 418165 => 542636, 418168 => 542637, 418170 => 542638, 418144 => 542639, 418142 => 542640, 418140 => 542641, 418138 => 542642, 418157 => 542643, 418092 => 542644, 418089 => 542645, 418087 => 542646, 418085 => 542647, 418134 => 542648, 418204 => 542649, 418232 => 542650, 418241 => 542651, 418270 => 542652, 418268 => 542653, 418274 => 542654, 418276 => 542655, 418278 => 542656, 418151 => 542657, 418149 => 542658, 418147 => 542659, 418128 => 542660, 418124 => 542661, 418121 => 542662, 418119 => 542663, 418259 => 542664, 418261 => 542665, 418265 => 542666, 418263 => 542667, 418256 => 542668, 418236 => 542669, 418223 => 542670, 418218 => 542671, 418216 => 542672, 418252 => 542673, 418287 => 542674, 418290 => 542675, 418297 => 542676, 418300 => 542677, 418306 => 542678, 418311 => 542679, 418248 => 542680, 418314 => 542681, 418246 => 542682, 418243 => 542683, 418239 => 542684, 418374 => 542685, 418377 => 542686, 418386 => 542687, 418395 => 542688, 418402 => 542689, 418410 => 542690, 418426 => 542691, 418371 => 542692, 418362 => 542693, 418364 => 542694, 418366 => 542695, 418369 => 542696, 418358 => 542697, 418412 => 542698, 418420 => 542699, 418356 => 542700, 418354 => 542701, 418352 => 542702, 418444 => 542703, 418447 => 542704, 418456 => 542705, 418457 => 542706, 418460 => 542707, 418465 => 542708, 418470 => 542709, 418360 => 542710, 418381 => 542711, 418383 => 542712, 418385 => 542713, 418396 => 542714, 418393 => 542715, 418391 => 542716, 418388 => 542717, 418463 => 542718, 418480 => 542719, 418497 => 542720, 418503 => 542721, 418544 => 542722, 418553 => 542723, 418557 => 542724, 418566 => 542725, 418568 => 542726, 418572 => 542727, 418574 => 542728, 418577 => 542729, 418564 => 542730, 418527 => 542731, 418525 => 542732, 418523 => 542733, 418546 => 542734, 418579 => 542735, 418538 => 542736, 418581 => 542737, 418533 => 542738, 418531 => 542739, 418609 => 542740, 418613 => 542741, 418618 => 542742, 418632 => 542743, 418644 => 542744, 418656 => 542745, 418664 => 542746, 418668 => 542747, 418686 => 542748, 418688 => 542749, 418694 => 542750, 418699 => 542751, 418700 => 542752, 418704 => 542753, 418682 => 542754, 418606 => 542755, 418604 => 542756, 418602 => 542757, 418707 => 542758, 418652 => 542759, 418649 => 542760, 418646 => 542761, 418643 => 542762, 418710 => 542763, 418715 => 542764, 418802 => 542765, 418808 => 542766, 418811 => 542767, 418816 => 542768, 418820 => 542769, 418821 => 542770, 418831 => 542771, 418837 => 542772, 418840 => 542773, 418842 => 542774, 418846 => 542775, 418852 => 542776, 418677 => 542777, 418674 => 542778, 418671 => 542779, 418666 => 542780, 418773 => 542781, 418663 => 542782, 418661 => 542783, 418658 => 542784, 418654 => 542785, 418770 => 542786, 418857 => 542787, 418859 => 542788, 418865 => 542789, 418867 => 542790, 418870 => 542791, 418777 => 542792, 418877 => 542793, 418889 => 542794, 418529 => 542795, 418760 => 542796, 418758 => 542797, 418754 => 542798, 418725 => 542799, 418729 => 542800, 418731 => 542801, 418733 => 542802, 418893 => 542803, 418911 => 542804, 418923 => 542805, 418930 => 542806, 418949 => 542807, 418958 => 542808, 418963 => 542809, 418967 => 542810, 418941 => 542811, 418939 => 542812, 418937 => 542813, 418935 => 542814, 418955 => 542815, 418914 => 542816, 418916 => 542817, 418919 => 542818, 418921 => 542819, 419012 => 542820, 419016 => 542821, 419025 => 542822, 419027 => 542823, 419032 => 542824, 419035 => 542825, 419037 => 542826, 419008 => 542827, 419006 => 542828, 419004 => 542829, 419002 => 542830, 419029 => 542831, 418981 => 542832, 418992 => 542833, 418995 => 542834, 418997 => 542835, 419042 => 542836, 419083 => 542837, 419086 => 542838, 419092 => 542839, 419096 => 542840, 419100 => 542841, 419102 => 542842, 419068 => 542843, 419066 => 542844, 419060 => 542845, 419058 => 542846, 419098 => 542847, 419054 => 542848, 419047 => 542849, 419051 => 542850, 419049 => 542851, 419112 => 542852, 419126 => 542853, 419138 => 542854, 419160 => 542855, 419171 => 542856, 419184 => 542857, 419186 => 542858, 419190 => 542859, 419192 => 542860, 419195 => 542861, 419136 => 542862, 419133 => 542863, 419130 => 542864, 419128 => 542865, 419188 => 542866, 419124 => 542867, 419121 => 542868, 419119 => 542869, 419116 => 542870, 419202 => 542871, 419206 => 542872, 419226 => 542873, 419233 => 542874, 419248 => 542875, 419255 => 542876, 419265 => 542877, 419267 => 542878, 419274 => 542879, 419281 => 542880, 419200 => 542881, 419316 => 542882, 419158 => 542883, 419156 => 542884, 419154 => 542885, 419152 => 542886, 419175 => 542887, 419144 => 542888, 419146 => 542889, 419148 => 542890, 419150 => 542891, 419169 => 542892, 419321 => 542893, 419326 => 542894, 419330 => 542895, 419334 => 542896, 419231 => 542897, 419222 => 542898, 419224 => 542899, 419229 => 542900, 419337 => 542901, 419209 => 542902, 419212 => 542903, 419214 => 542904, 419218 => 542905, 419359 => 542906, 419362 => 542907, 419364 => 542908, 419388 => 542909, 419392 => 542910, 419236 => 542911, 419399 => 542912, 419405 => 542913, 419385 => 542914, 419383 => 542915, 419381 => 542916, 419379 => 542917, 419397 => 542918, 419354 => 542919, 419371 => 542920, 419373 => 542921, 419375 => 542922, 419377 => 542923, 419410 => 542924, 419418 => 542925, 419429 => 542926, 419494 => 542927, 419496 => 542928, 419498 => 542929, 419435 => 542930, 419504 => 542931, 419509 => 542932, 419452 => 542933, 419450 => 542934, 419448 => 542935, 419446 => 542936, 419506 => 542937, 419483 => 542938, 419481 => 542939, 419478 => 542940, 419475 => 542941, 419550 => 542942, 419563 => 542943, 419626 => 542944, 419630 => 542945, 419631 => 542946, 419636 => 542947, 419638 => 542948, 419640 => 542949, 419643 => 542950, 419645 => 542951, 419603 => 542952, 419601 => 542953, 419599 => 542954, 419647 => 542955, 419546 => 542956, 419544 => 542957, 419542 => 542958, 419540 => 542959, 419570 => 542960, 419652 => 542961, 419677 => 542962, 419655 => 542963, 419693 => 542964, 419723 => 542965, 419726 => 542966, 419738 => 542967, 419740 => 542968, 419743 => 542969, 419746 => 542970, 419692 => 542971, 419748 => 542972, 419753 => 542973, 419761 => 542974, 419729 => 542975, 419763 => 542976, 419611 => 542977, 419609 => 542978, 419607 => 542979, 419605 => 542980, 419775 => 542981, 419770 => 542982, 419787 => 542983, 419813 => 542984, 419817 => 542985, 419819 => 542986, 419821 => 542987, 419823 => 542988, 419825 => 542989, 419830 => 542990, 419832 => 542991, 419732 => 542992, 419690 => 542993, 419658 => 542994, 419660 => 542995, 419662 => 542996, 419666 => 542997, 419742 => 542998, 419810 => 542999, 419838 => 543000, 419842 => 543001, 419844 => 543002, 419850 => 543003, 419734 => 543004, 419854 => 543005, 419670 => 543006, 419856 => 543007, 419672 => 543008, 419675 => 543009, 419668 => 543010, 419794 => 543011, 419877 => 543012, 419862 => 543013, 419900 => 543014, 419907 => 543015, 419909 => 543016, 419925 => 543017, 419928 => 543018, 419930 => 543019, 419905 => 543020, 419902 => 543021, 419874 => 543022, 419923 => 543023, 419872 => 543024, 419870 => 543025, 419868 => 543026, 419918 => 543027, 419946 => 543028, 419943 => 543029, 419993 => 543030, 420011 => 543031, 420013 => 543032, 420019 => 543033, 420021 => 543034, 419954 => 543035, 419952 => 543036, 419967 => 543037, 419950 => 543038, 419998 => 543039, 419948 => 543040, 419940 => 543041, 419938 => 543042, 420017 => 543043, 419972 => 543044, 420024 => 543045, 420028 => 543046, 420076 => 543047, 420079 => 543048, 420095 => 543049, 420106 => 543050, 420107 => 543051, 420110 => 543052, 420115 => 543053, 420120 => 543054, 420133 => 543055, 420135 => 543056, 420137 => 543057, 420057 => 543058, 420061 => 543059, 420055 => 543060, 420044 => 543061, 420125 => 543062, 420039 => 543063, 420036 => 543064, 420141 => 543065, 420070 => 543066, 420153 => 543067, 420164 => 543068, 420209 => 543069, 420223 => 543070, 420219 => 543071, 420228 => 543072, 420230 => 543073, 420194 => 543074, 420236 => 543075, 420176 => 543076, 420178 => 543077, 420239 => 543078, 420118 => 543079, 420065 => 543080, 420232 => 543081, 420157 => 543082, 420150 => 543083, 420245 => 543084, 420255 => 543085, 420296 => 543086, 420316 => 543087, 420327 => 543088, 420329 => 543089, 420333 => 543090, 420336 => 543091, 420343 => 543092, 420352 => 543093, 420346 => 543094, 420325 => 543095, 420186 => 543096, 420262 => 543097, 420257 => 543098, 420073 => 543099, 420067 => 543100, 420190 => 543101, 420192 => 543102, 420260 => 543103, 420361 => 543104, 420363 => 543105, 420365 => 543106, 420373 => 543107, 420376 => 543108, 420281 => 543109, 420382 => 543110, 420180 => 543111, 420264 => 543112, 420384 => 543113, 420069 => 543114, 420279 => 543115, 420277 => 543116, 420274 => 543117, 420395 => 543118, 420428 => 543119, 420450 => 543120, 420453 => 543121, 420454 => 543122, 420457 => 543123, 420460 => 543124, 420462 => 543125, 420464 => 543126, 420432 => 543127, 420470 => 543128, 420474 => 543129, 420418 => 543130, 420414 => 543131, 420467 => 543132, 420412 => 543133, 420409 => 543134, 420407 => 543135, 420405 => 543136, 420478 => 543137, 420497 => 543138, 420521 => 543139, 420525 => 543140, 420500 => 543141, 420492 => 543142, 420494 => 543143, 420533 => 543144, 420483 => 543145, 420485 => 543146, 420536 => 543147, 420487 => 543148, 420489 => 543149, 420543 => 543150, 420556 => 543151, 420558 => 543152, 420562 => 543153, 420573 => 543154, 420583 => 543155, 420594 => 543156, 420610 => 543157, 420601 => 543158, 420624 => 543159, 420628 => 543160, 420632 => 543161, 420636 => 543162, 420579 => 543163, 420581 => 543164, 420634 => 543165, 420566 => 543166, 420568 => 543167, 420638 => 543168, 420571 => 543169, 420576 => 543170, 420646 => 543171, 420687 => 543172, 420701 => 543173, 420715 => 543174, 420718 => 543175, 420730 => 543176, 420739 => 543177, 420741 => 543178, 420691 => 543179, 420737 => 543180, 420682 => 543181, 420680 => 543182, 420723 => 543183, 420678 => 543184, 420676 => 543185, 420751 => 543186, 420779 => 543187, 420805 => 543188, 420807 => 543189, 420827 => 543190, 420830 => 543191, 420832 => 543192, 420788 => 543193, 420785 => 543194, 420754 => 543195, 420791 => 543196, 420777 => 543197, 420775 => 543198, 420773 => 543199, 420771 => 543200, 420838 => 543201, 420760 => 543202, 420840 => 543203, 420843 => 543204, 420845 => 543205, 420812 => 543206, 420858 => 543207, 420852 => 543208, 420862 => 543209, 420864 => 543210, 420866 => 543211, 420868 => 543212, 420870 => 543213, 420926 => 543214, 420931 => 543215, 420933 => 543216, 420903 => 543217, 420929 => 543218, 420925 => 543219, 420917 => 543220, 420919 => 543221, 420921 => 543222, 420923 => 543223, 420953 => 543224, 420965 => 543225, 420972 => 543226, 420977 => 543227, 420980 => 543228, 420987 => 543229, 421001 => 543230, 421004 => 543231, 421006 => 543232, 420986 => 543233, 420967 => 543234, 420969 => 543235, 421008 => 543236, 420952 => 543237, 420955 => 543238, 420950 => 543239, 420948 => 543240, 421029 => 543241, 421049 => 543242, 421072 => 543243, 421075 => 543244, 421082 => 543245, 421063 => 543246, 421084 => 543247, 421043 => 543248, 421040 => 543249, 421038 => 543250, 421036 => 543251, 421070 => 543252, 421023 => 543253, 421021 => 543254, 421019 => 543255, 421059 => 543256, 421017 => 543257, 421162 => 543258, 421169 => 543259, 421171 => 543260, 421173 => 543261, 421099 => 543262, 421101 => 543263, 421103 => 543264, 421105 => 543265, 421122 => 543266, 421120 => 543267, 421118 => 543268, 421116 => 543269, 421178 => 543270, 421192 => 543271, 421194 => 543272, 421200 => 543273, 421204 => 543274, 421226 => 543275, 421228 => 543276, 421239 => 543277, 421238 => 543278, 421205 => 543279, 421110 => 543280, 421108 => 543281, 421189 => 543282, 421133 => 543283, 421131 => 543284, 421129 => 543285, 421175 => 543286, 421150 => 543287, 421209 => 543288, 421221 => 543289, 421248 => 543290, 421207 => 543291, 421212 => 543292, 421254 => 543293, 421210 => 543294, 421114 => 543295, 421112 => 543296, 421186 => 543297, 421184 => 543298, 421182 => 543299, 421127 => 543300, 421217 => 543301, 421268 => 543302, 421288 => 543303, 421349 => 543304, 421354 => 543305, 421357 => 543306, 421367 => 543307, 421370 => 543308, 421374 => 543309, 421376 => 543310, 421356 => 543311, 421334 => 543312, 421336 => 543313, 421378 => 543314, 421332 => 543315, 421329 => 543316, 421325 => 543317, 421292 => 543318, 421270 => 543319, 421383 => 543320, 421395 => 543321, 421405 => 543322, 421414 => 543323, 421294 => 543324, 421439 => 543325, 421470 => 543326, 421445 => 543327, 421472 => 543328, 421474 => 543329, 421396 => 543330, 421399 => 543331, 421481 => 543332, 421390 => 543333, 421388 => 543334, 421382 => 543335, 421386 => 543336, 421489 => 543337, 421530 => 543338, 421560 => 543339, 421563 => 543340, 421569 => 543341, 421571 => 543342, 421583 => 543343, 421588 => 543344, 421549 => 543345, 421574 => 543346, 421521 => 543347, 421523 => 543348, 421576 => 543349, 421498 => 543350, 421496 => 543351, 421494 => 543352, 421492 => 543353, 421597 => 543354, 421605 => 543355, 421609 => 543356, 421615 => 543357, 421632 => 543358, 421644 => 543359, 421526 => 543360, 421531 => 543361, 421508 => 543362, 421506 => 543363, 421504 => 543364, 421501 => 543365, 421528 => 543366, 421637 => 543367, 421654 => 543368, 421519 => 543369, 421517 => 543370, 421634 => 543371, 421513 => 543372, 421511 => 543373, 421658 => 543374, 421686 => 543375, 421705 => 543376, 421709 => 543377, 421714 => 543378, 421725 => 543379, 421729 => 543380, 421703 => 543381, 421701 => 543382, 421716 => 543383, 421699 => 543384, 421697 => 543385, 421695 => 543386, 421691 => 543387, 421745 => 543388, 421771 => 543389, 421776 => 543390, 421780 => 543391, 421798 => 543392, 421766 => 543393, 421764 => 543394, 421784 => 543395, 421762 => 543396, 421760 => 543397, 421755 => 543398, 421733 => 543399, 421817 => 543400, 421809 => 543401, 421838 => 543402, 421841 => 543403, 421843 => 543404, 421847 => 543405, 421856 => 543406, 421859 => 543407, 421861 => 543408, 421816 => 543409, 348596 => 544811, 410413 => 544812, 412986 => 544813, 417632 => 544814, ];

		// Merge all results together.
		foreach ( $att_ids_updates_file as $old_id => $new_id ) {
			if ( isset( $att_ids_updates[$old_id] ) ) {
				continue;
			}
			$att_ids_updates[$old_id] = $new_id;
		}

		$last_processed_post_id_key = null;
		if ( file_exists( $log_last_processed_post_id_key ) ) {
			$last_processed_post_id_key = file_get_contents( $log_last_processed_post_id_key );
		}

		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			// Skip already processed posts.
			if ( $last_processed_post_id_key && $key_post_id <= $last_processed_post_id_key ) {
				continue;
			}
			WP_CLI::line( sprintf( '%d/%d %d', $key_post_id + 1, count( $post_ids ), $post_id ) );

			$current_postmeta_thumbnail_id = get_post_meta( $post_id, '_thumbnail_id', true );
			if ( ! $current_postmeta_thumbnail_id || empty( $current_postmeta_thumbnail_id ) ) {
				file_put_contents( $log_last_processed_post_id_key, $key_post_id );
				continue;
			}
			// Check if old_att_id is found in att_ids_updates.
			$new_thumbnail_id = isset( $att_ids_updates[ $current_postmeta_thumbnail_id ] ) ? $att_ids_updates[ $current_postmeta_thumbnail_id ] : null;
			if ( ! is_null( $new_thumbnail_id ) ) {
				update_post_meta( $post_id, '_thumbnail_id', $new_thumbnail_id );
				$this->logger->log( 'vtdigger-update-thumb-ids__updatedPostIDs.log', "postID {$post_id} previousAttId {$current_postmeta_thumbnail_id} newAttId {$new_thumbnail_id}", $this->logger::SUCCESS );
			}
			file_put_contents( $log_last_processed_post_id_key, $key_post_id );
		}

		$mem_usage_mb_end = memory_get_usage()/1024/1024;

		$d=1;
	}

	public function cmd_delete_pressrelease_content( array $pos_args, array $assoc_args ) {
		$author = 'Press Release';
		$ga_existing = $this->cap_logic->get_guest_author_by_display_name( $author );
		if ( ! $ga_existing ) {
			WP_CLI::error( "Guest Author $author does not exist." );
		}
		$wpuser_existing = $this->get_wpuser_by_display_name( $author );
		if ( ! $wpuser_existing ) {
			WP_CLI::error( "WP User $author does not exist." );
		}

		$post_ids_with_multiple_coauthors = [];
		$post_ids = $this->posts_logic->get_all_posts_ids();
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::line( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			$authors = $this->cap_logic->get_all_authors_for_post( $post_id );

			$matched = false;
			foreach ( $authors as $author ) {
				if ( 'stdClass' === $author::class && $ga_existing->ID == $author->ID ) {
					$matched = true;
				} elseif ( 'WP_User' === $author::class && $wpuser_existing->ID == $author->ID ) {
					$matched = true;
				}
			}

			if ( false === $matched ) {
				continue;
			}

			if ( true === $matched && count( $authors ) > 1 ) {
				$post_ids_with_multiple_coauthors[] = $post_id;
				WP_CLI::warning( sprintf( 'Multiple coauthors' ) );
				continue;
			}

			$deleted = wp_delete_post( $post_id, true );
			if ( false === $deleted || is_null( $deleted ) || empty( $deleted ) ) {
				// log err deleting post
				$this->logger->log( 'vtdigger-delete-pressrelease-content__errDeletingPost.log', sprintf( "Error deleting postID %d", $post_id ), $this->logger::WARNING );
				continue;
			}

			// log deleted post
			$this->logger->log( 'vtdigger-delete-pressrelease-content__deletedPost.log', sprintf( "Deleted postID %d", $post_id ), $this->logger::SUCCESS );
		}

		if ( empty( $post_ids_with_multiple_coauthors ) ) {
			WP_CLI::success( 'No $post_ids_with_multiple_coauthors' );
		} else {
			WP_CLI::warning( 'See $post_ids_with_multiple_coauthors' );
			$this->logger->log( 'vtdigger-delete-pressrelease-content__postsWMultipleAuthors.log', implode( "\n", $post_ids_with_multiple_coauthors ), false );
		}

		$debug = 1;
	}

	private function get_wpuser_by_display_name( $display_name ) {
		global $wpdb;

		$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE display_name = %s", $display_name ) );
		$user = get_user_by( 'ID', $user_id );

		return $user;
	}

	/**
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_restore_reusable_blocks_in_local_posts_from_live_table( array $pos_args, array $assoc_args ) {
		$live_posts_table_name = 'livevtdWP_posts';
		$qa_path                  = getcwd() . '/' . 'log_QAUpdatedPostContent';
		if ( ! file_exists( $qa_path ) ) {
			mkdir( $qa_path, 0777, true );
		}

		global $wpdb;

		// Insert Reusable blocks which keep the same IDs.
		$reusable_blocks_ids = [ 331411,333919,287702,292966,294143,294383,301399,306744,307109,310386,310997,313107,313203,315093,315566,316739,316908,319091,319446,319930,319934,320680,320764,322846,325577,326130,326131,326286,326405,326484,327395,328038,328824,328966,329459,329747,330401,331412,333921,335042,336393,339931,340591,340707,340755,341483,344897,344939,345130,349010,349835,350925,352401,352683,355836,356288,362916,370675,371526,372498,375812,375816,375948,376333,376463,382204,386426,390259,401522,408146,408494,410046,410893 ];
		foreach ( $reusable_blocks_ids as $id ) {
			$live_reusable_block = $wpdb->get_row( $wpdb->prepare( "select * from {$live_posts_table_name} where ID = %d", $id ), ARRAY_A );
			if ( ! $live_reusable_block ) {
				WP_CLI::error( "Live reusable block ID {$id} not found." );
			}
			$inserted = $wpdb->insert(
				$wpdb->posts,
				[
					// Keeps the same ID.
					"ID" => $live_reusable_block["ID"],
					// Hardcoded adminnewspack for simplicity.
					"post_author" => 1788,
					"post_date" => $live_reusable_block["post_date"],
					"post_date_gmt" => $live_reusable_block["post_date_gmt"],
					"post_content" => $live_reusable_block["post_content"],
					"post_title" => $live_reusable_block["post_title"],
					"post_excerpt" => $live_reusable_block["post_excerpt"],
					"post_status" => $live_reusable_block["post_status"],
					"comment_status" => $live_reusable_block["comment_status"],
					"ping_status" => $live_reusable_block["ping_status"],
					"post_password" => $live_reusable_block["post_password"],
					"post_name" => $live_reusable_block["post_name"],
					"to_ping" => $live_reusable_block["to_ping"],
					"pinged" => $live_reusable_block["pinged"],
					"post_modified" => $live_reusable_block["post_modified"],
					"post_modified_gmt" => $live_reusable_block["post_modified_gmt"],
					"post_content_filtered" => $live_reusable_block["post_content_filtered"],
					"post_parent" => $live_reusable_block["post_parent"],
					"guid" => $live_reusable_block["guid"],
					"menu_order" => $live_reusable_block["menu_order"],
					"post_type" => $live_reusable_block["post_type"],
					"post_mime_type" => $live_reusable_block["post_mime_type"],
					"comment_count" => $live_reusable_block["comment_count"],
				]
			);
			if ( ! $inserted ) {
				WP_CLI::error( "Failed to insert reusable block ID {$id}." );
			} else {
				WP_CLI::log( "Inserted reusable block with same ID {$id}." );
			}
		}

		// Insert Reusable blocks which will change their IDs.
		$reusable_blocks_ids = [ 412947,412949,412951,413143,413146,413157,413158,413162,413163,414673,418408 ];
		foreach ( $reusable_blocks_ids as $id ) {
			$live_reusable_block = $wpdb->get_row( $wpdb->prepare( "select * from {$live_posts_table_name} where ID = %d", $id ), ARRAY_A );
			if ( ! $live_reusable_block ) {
				WP_CLI::error( "Live reusable block ID {$id} not found." );
			}
			$inserted = $wpdb->insert(
				$wpdb->posts,
				[
					// Hardcoded adminnewspack for simplicity.
					"post_author" => 1788,
					"post_date" => $live_reusable_block["post_date"],
					"post_date_gmt" => $live_reusable_block["post_date_gmt"],
					"post_content" => $live_reusable_block["post_content"],
					"post_title" => $live_reusable_block["post_title"],
					"post_excerpt" => $live_reusable_block["post_excerpt"],
					"post_status" => $live_reusable_block["post_status"],
					"comment_status" => $live_reusable_block["comment_status"],
					"ping_status" => $live_reusable_block["ping_status"],
					"post_password" => $live_reusable_block["post_password"],
					"post_name" => $live_reusable_block["post_name"],
					"to_ping" => $live_reusable_block["to_ping"],
					"pinged" => $live_reusable_block["pinged"],
					"post_modified" => $live_reusable_block["post_modified"],
					"post_modified_gmt" => $live_reusable_block["post_modified_gmt"],
					"post_content_filtered" => $live_reusable_block["post_content_filtered"],
					"post_parent" => $live_reusable_block["post_parent"],
					"guid" => $live_reusable_block["guid"],
					"menu_order" => $live_reusable_block["menu_order"],
					"post_type" => $live_reusable_block["post_type"],
					"post_mime_type" => $live_reusable_block["post_mime_type"],
					"comment_count" => $live_reusable_block["comment_count"],
				]
			);
			if ( ! $inserted ) {
				WP_CLI::error( "Failed to insert reusable block ID {$id}." );
			} else {
				$this->logger->log( 'vtdigger-restore-reusable-blocks-in-local-posts-from-live-table__newReusableBlockIds.log', "inserted Reusable Block live:{$id} local:{$wpdb->insert_id}" );
			}
		}

		// Update post_content.
		$post_ids = $this->posts_logic->get_all_posts_ids( 'post', [ 'publish', 'future' ] );
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );
			// Match that post in local and live table.
			$local_post = $wpdb->get_row( $wpdb->prepare( "select * from {$wpdb->posts} where ID = %d", $post_id ), ARRAY_A );
			$live_post  = $wpdb->get_row(
				$wpdb->prepare(
					"select * from {$live_posts_table_name}
	                where post_name = %s
					and post_title = %s
					and post_status = %s
					and post_date = %s
					and post_type <> 'revision' ; ",
					$local_post['post_name'],
					$local_post['post_title'],
					$local_post['post_status'],
					$local_post['post_date']
				),
				ARRAY_A
			);
			if ( ! $live_post ) {
				$this->logger->log( 'vtdigger-restore-reusable-blocks-in-local-posts-from-live-table__postNotFoundInLive.log', "Could not find post in live table: {$post_id}", $this->logger::WARNING );
				continue;
			}

			// Update local post's post_content with live post's post_content.
			if ( ! empty( $live_post['post_content'] ) && ( $live_post['post_content'] !== $local_post['post_content'] ) ) {
				$updated = $wpdb->update(
					$wpdb->posts,
					[ 'post_content' => $live_post['post_content'] ],
					[ 'ID' => $post_id ],
				);
				if ( ( false !== $updated ) && ( $updated > 0 ) ) {
					$this->logger->log( 'vtdigger-restore-reusable-blocks-in-local-posts-from-live-table__updatedPostId.log', "Updated Post ID: {$post_id}" );
					file_put_contents( $qa_path . '/' . $post_id . '_1before.txt', $local_post['post_content'] );
					file_put_contents( $qa_path . '/' . $post_id . '_2after.txt', $live_post['post_content'] );
				}
			}
		}

		wp_cache_flush();
	}

	public function cmd_businessbriefs( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_businessbriefs.log';

		$cat_id = get_cat_ID( self::BUSINESSBRIEFS_CAT_NAME );
		if ( 0 == $cat_id ) {
			$cat_id = wp_insert_category( [ 'cat_name' => self::BUSINESSBRIEFS_CAT_NAME ] );
		}

		$businessbriefs_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s;", self::BUSINESSBRIEFS_CPT ) );

		foreach ( $businessbriefs_ids as $key_businessbriefs_id => $businessbrief_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_businessbriefs_id + 1, count( $businessbriefs_ids ), $businessbrief_id ) );

			// Convert to 'post' type.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $businessbrief_id ) );

			update_post_meta( $businessbrief_id, self::META_VTD_CPT, self::BUSINESSBRIEFS_CPT );

			wp_set_post_categories( $businessbrief_id, [ $cat_id ], false );
		}

		$this->logger->log( $log, implode( ',', $businessbriefs_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	public function cmd_cartoons( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_cartoons.log';

		$cat_id = get_cat_ID( self::CARTOONS_CAT_NAME );
		if ( 0 == $cat_id ) {
			$cat_id = wp_insert_category( [ 'cat_name' => self::CARTOONS_CAT_NAME ] );
		}

		$cartoons_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s;", self::CARTOONS_CPT ) );

		foreach ( $cartoons_ids as $key_cartoon_id => $cartoon_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_cartoon_id + 1, count( $cartoons_ids ), $cartoon_id ) );

			// Convert to 'post' type.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $cartoon_id ) );

			update_post_meta( $cartoon_id, self::META_VTD_CPT, self::CARTOONS_CPT );

			wp_set_post_categories( $cartoon_id, [ $cat_id ], false );
		}

		$this->logger->log( $log, implode( ',', $cartoons_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Works with:
	 *  - the .php file saved by \NewspackCustomContentMigrator\Command\General\CoAuthorPlusMigrator::cmd_import_posts_gas
	 *  - and the content-diff__imported-post-ids.log file created by Content Diff
	 * Assigns the Guest Authors to the posts and creates a log of GAs it created anew.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cmd_import_posts_gas( array $args, array $assoc_args ) {
		$php_file               = $assoc_args['php-file'];
		$imported_post_ids_file = $assoc_args['imported-post-ids-file'];
		$dry_run                = isset( $assoc_args['dry-run'] ) ? true : false;
		if ( ! file_exists( $php_file ) || ! file_exists( $imported_post_ids_file ) ) {
			WP_CLI::error( 'Wrong files provided.' );
		}

		$log_created_gas = 'log_created_gas.txt';

		// Get mapping old post ID => new post ID.
		$post_ids_old_new_map = [];
		foreach ( explode( "\n", file_get_contents( $imported_post_ids_file ) ) as $line ) {
			$line_decoded = json_decode( $line, true );
			if ( ! $line_decoded ) {
				continue;
			}
			if ( 'post' == $line_decoded['post_type'] ) {
				$post_ids_old_new_map[ $line_decoded['id_old'] ] = $line_decoded['id_new'];
			}
		}

		$posts_gas = include $php_file;
		foreach ( $posts_gas as $post_id => $ga_display_names ) {
			$ga_ids = [];
			foreach ( $ga_display_names as $ga_display_name ) {

				// Get or create GA by display name.
				$guest_author = $this->cap_logic->get_guest_author_by_display_name( $ga_display_name );
				if ( ! $guest_author ) {
					if ( ! $dry_run ) {
						$ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => $ga_display_name ] );
						$this->logger->log( $log_created_gas, sprintf( "Created Guest Author %s ID %s", $ga_display_name, $ga_id ) );
					} else {
						WP_CLI::line( sprintf( "Created Guest Author %s ID %s", $ga_display_name, "n/a" ) );
					}
				} else {
					$ga_id = $guest_author->ID;
				}
				$ga_ids[] = $ga_id;
			}

			// Get new ID and assign.
			if ( ! $dry_run ) {
				$new_post_id = isset( $post_ids_old_new_map[ $post_id ] ) ? $post_ids_old_new_map[ $post_id ] : $post_id;
				$this->cap_logic->assign_guest_authors_to_post( $ga_ids, $new_post_id );
			}
		}

		WP_CLI::success( sprintf( 'Done. See %s', $log_created_gas ) );
	}

	/**
	 * Outputs all Post IDs that were not obituaries.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_helper_get_nonobituaries_post_ids( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log_csv = 'post_ids_not_obituaries.csv';

		// all posts 71462
		"select count(ID) from vtdWP_posts where post_type = 'post';";
		// obituaries 455
		"select count(distinct post_id) as ID from vtdWP_postmeta where meta_key = 'newspack_vtd_cpt' and meta_value = 'obituary';";

		// -- posts that weren't obituaries 71007
		$ids = $wpdb->get_col(
			"select distinct ID from vtdWP_posts
			where post_type = 'post' and ID not in (
				select distinct post_id as ID from vtdWP_postmeta where meta_key = 'newspack_vtd_cpt' and meta_value = 'obituary'
			);"
		);

		$this->logger->log( $log_csv, implode( ',', $ids ), false );
		WP_CLI::success( sprintf( "Done. See %s", $log_csv ) );
	}

	/**
	 * Takes parent-cat-id from $assoc_args and removes subcategories if post count is 0.
	 * This is a helper command to clean up categories after migration.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_helper_remove_subcategories( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$parent_cat_id = $assoc_args['parent-cat-id'];
		$log = 'vtd_helper_remove_subcategories.log';
		$log_error = 'vtd_helper_remove_subcategories_err.log';

		$children_cat_term_taxonomy_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM $wpdb->term_taxonomy WHERE parent = %d", $parent_cat_id ) );
		foreach ( $children_cat_term_taxonomy_ids as $key_children_cat_term_taxonomy_id => $children_cat_term_taxonomy_id ) {
			WP_CLI::log( sprintf( '(%d)/(%d) %d', $key_children_cat_term_taxonomy_id + 1, count( $children_cat_term_taxonomy_ids ), $children_cat_term_taxonomy_id ) );
			$children_cat_post_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships WHERE term_taxonomy_id = %d", $children_cat_term_taxonomy_id ) );
			if ( 0 == $children_cat_post_count ) {
				WP_CLI::log( sprintf( 'Removing category %d', $children_cat_term_taxonomy_id ) );
				$deleted = wp_delete_term( $children_cat_term_taxonomy_id, 'category' );
				if ( is_wp_error( $deleted ) || false === $deleted || 0 === $deleted ) {
					WP_CLI::warning( sprintf( 'Error removing category %d: %s', $children_cat_term_taxonomy_id, is_object( $deleted ) ? $deleted->get_error_message() : '' ) );
					$this->logger->log( $log_error, sprintf( 'Error removing category %d: %s', $children_cat_term_taxonomy_id, is_object( $deleted ) ? $deleted->get_error_message() : '' ) );
				}
			} else {
				$this->logger->log( $log, sprintf( 'Category %d has %d posts, not removing.', $children_cat_term_taxonomy_id, $children_cat_post_count ) );
			}
		}
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-liveblogs`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_liveblogs( array $pos_args, array $assoc_args ) {
		global $wpdb;

		/**
		 * Move Liveblogs>Uncategorized to Liveblogs.
		 * Move
		 */

		/**
		 * Newsbriefs.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::NEWSBRIEF_CPT ) );
		$log = 'vtd_cpt_newsbriefs.log';
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::NEWS_BRIEFS_GA_NAME );
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s ;", self::NEWSBRIEF_CPT ) );
		// NewsBriefs posts remain uncategorized.
		$this->migrate_liveblog( self::NEWSBRIEF_CPT, false, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::NEWSBRIEF_CPT, $log ) );

		/**
		 * Liveblogs.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::LIVEBLOG_CPT ) );
		$log = 'vtd_cpt_liveblog.log';
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::LIVEBLOG_GA_NAME );
		$parent_cat_id = get_cat_ID( self::LIVEBLOGS_CAT_NAME );
		if ( 0 == $parent_cat_id ) {
			$parent_cat_id = wp_insert_category( [ 'cat_name' => self::LIVEBLOGS_CAT_NAME ] );
		}
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s;", self::LIVEBLOG_CPT ) );
		$this->migrate_liveblog( self::LIVEBLOG_CPT, $parent_cat_id, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::LIVEBLOGS_CAT_NAME, $log ) );

		/**
		 * Olympics Blog.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::OLYMPICS_BLOG_CPT ) );
		$log = 'vtd_cpt_olympicsblog.log';
		$parent_cat_id = get_cat_ID( self::OLYMPICS_BLOG_CAT_NAME );
		if ( 0 == $parent_cat_id ) {
			$parent_cat_id = wp_insert_category( [ 'cat_name' => self::OLYMPICS_BLOG_CAT_NAME ] );
		}
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::OLYMPICS_GA_NAME );
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s;", self::OLYMPICS_BLOG_CPT ) );
		$this->migrate_liveblog( self::OLYMPICS_BLOG_CPT, $parent_cat_id, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::OLYMPICS_BLOG_CPT, $log ) );

		/**
		 * Election Liveblogs.
		 */
		WP_CLI::log( sprintf( 'Migrating %s ...', self::ELECTION_CPT ) );
		$log = 'vtd_cpt_election.log';
		$parent_cat_id = get_cat_ID( self::ELECTION_BLOG_CAT_NAME );
		if ( 0 == $parent_cat_id ) {
			$parent_cat_id = wp_insert_category( [ 'cat_name' => self::ELECTION_BLOG_CAT_NAME ] );
		}
		$ga_id = $this->get_or_create_ga_id_by_display_name( self::ELECTION_GA_NAME );
		$post_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type=%s ;", self::ELECTION_CPT ) );
		$this->migrate_liveblog( self::ELECTION_CPT, $parent_cat_id, $ga_id, $post_ids, self::ALL_LIVEBLOGS_TAG_NAME );
		$this->logger->log( $log, implode( ',', $post_ids ), false );
		WP_CLI::log( sprintf( "Done with %s; see %s", self::ELECTION_CPT, $log ) );


		WP_CLI::log( 'Done.' );
		wp_cache_flush();
	}

	/**
	 * Gets or creates a GA by display name.
	 *
	 * @param string $display_name GA display name.
	 *
	 * @throws \RuntimeException If GA could not be created.
	 *
	 * @return int GA ID.
	 */
	private function get_or_create_ga_id_by_display_name( $display_name ) {
		$ga = $this->cap_logic->get_guest_author_by_display_name( $display_name );
		$ga_id = $ga->ID ?? null;
		if ( is_null( $ga_id ) ) {
			$ga_id = $this->cap_logic->create_guest_author( ['display_name' => $display_name] );
		}
		if ( ! $ga_id ) {
			throw new \RuntimeException( sprintf( 'Could not get/create Guest Author %s.', $display_name ) );
		}

		return $ga_id;
	}

	/**
	 * @param string   $liveblog_cpt  post_type of the liveblog.
	 * @param bool|int $parent_cat_id ID of the parent category for all liveblogs, or false if content should become uncategorized.
	 * @param int      $ga_id         GA ID to assign to all posts.
	 * @param array    $post_ids      Post IDs to migrate.
	 * @param string   $tag           Tag to append to all posts.
	 *
	 * @return void
	 */
	public function migrate_liveblog( string $liveblog_cpt, bool|int $parent_cat_id, int $ga_id, array $post_ids, string $tag ) {
		global $wpdb;

		// Convert to 'post' type.
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $post_id ) );

			// Tag, append.
			wp_set_post_tags( $post_id, $tag, true );

			// GA, not append, just this one GA.
			if ( $ga_id ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $ga_id ], $post_id, false );
			}

			// Update post Categories.
			if ( false == $parent_cat_id ) {
				/**
				 * Uncategorized.
				 */

				wp_set_post_categories( $post_id, [], false );
			} else {
				/**
				 * Migrate categories to this new post category.
				 */

				$category_ids = wp_get_post_categories( $post_id );

				// First empty current cats.
				wp_set_post_categories( $post_id, [], false );

				foreach ( $category_ids as $category_id ) {
					$category = get_category( $category_id );

					// Get or recreate this category under $parent_cat_id parent.
					$new_category_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( $category->name, $parent_cat_id );

					// Assign by appending
					wp_set_post_categories( $post_id, [ $new_category_id ], true );
				}
				// Or if no category, set $parent_cat_id.
				if ( empty( $category_ids ) ) {
					wp_set_post_categories( $post_id, [ $parent_cat_id ], false );
				}
			}

			// Set meta 'newspack_vtd_cpt' = $liveblog_cpt;
			update_post_meta( $post_id, self::META_VTD_CPT, $liveblog_cpt );
		}
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-letterstotheeditor`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_letterstotheeditor( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_letterstotheeditor.log';

		$letters_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type = %s;", self::LETTERS_TO_EDITOR_CPT ) );

		// Convert to 'post' type.
		foreach ( $letters_ids as $key_letter_id => $letter_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_letter_id + 1, count( $letters_ids ), $letter_id ) );

			// Update to type post.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post' where ID=%d;", $letter_id ) );

			// Set meta 'newspack_vtd_cpt' = 'letters_to_editor';
			update_post_meta( $letter_id, self::META_VTD_CPT, self::LETTERS_TO_EDITOR_CPT );

			// Tag, append.
			wp_set_post_tags( $letter_id, [ self::LETTERSTOTHEEDITOR_TAG_NAME ], true );
		}

		$this->logger->log( $log, implode( ',', $letters_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-obituaries`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_obituaries( array $pos_args, array $assoc_args ) {
		global $wpdb;
		$log = 'vtd_obituaries.log';
		$log_error = 'vtd_obituaries_error.log';

		// Get Obituaries category ID.
		$obituaries_cat_id = get_cat_ID( self::OBITUARIES_CAT_NAME );
		if ( ! $obituaries_cat_id ) {
			$obituaries_cat_id = wp_insert_category( [ 'cat_name' => self::OBITUARIES_CAT_NAME ] );
		}

		$obituaries_ids = $wpdb->get_col( $wpdb->prepare( "select ID from {$wpdb->posts} where post_type='%s';", self::OBITUARY_CPT ) );
		$obituaries_ids_dev = [
			// _thumbnail_id IDs w/ & wo/
			// 409943,394799,
			// name_of_deceased IDs w/ & wo/
			// 402320,402256,
			// date_of_birth IDs w/ & wo/
			// 402256,401553,
			// city_of_birth IDs w/ & wo/
			// 402256,401553,
			// state_of_birth IDs w/ & wo/
			// 402497, 402320,
			// date_of_death IDs w/ & wo/
			// 384051,384020,
			// city_of_death IDs w/ & wo/
			// 402256,401553,
			// state_of_death IDs w/ & wo/
			// 402497,402320,
			// details_of_services IDs w/ & wo/
			// 402320,402256,
			// obitbiography IDs w/ & wo/
			// 394221,394199,
			// obitfamily_information IDs w/ & wo/
			// 394221,394199,
		];

		// Convert to 'post' type.
		foreach ( $obituaries_ids as $key_obituary_id => $obituary_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_obituary_id + 1, count( $obituaries_ids ), $obituary_id ) );

			// Get all ACF.
			/*
			 * @var $_thumbnail_id E.g. has _thumbnail_id ID 409943, no _thumbnail_id ID 394799.
			 */
			$thumbnail_id = get_post_meta( $obituary_id, '_thumbnail_id', true ) != '' ? get_post_meta( $obituary_id, '_thumbnail_id', true ) : null;
			/*
			 * @var $name_of_deceased E.g. has name_of_deceased ID 402320, no name_of_deceased ID 402256.
			 */
			$name_of_deceased = get_post_meta( $obituary_id, 'name_of_deceased', true ) != '' ? get_post_meta( $obituary_id, 'name_of_deceased', true ) : null;
			/*
			 * @var string|null $date_of_birth E.g. has date_of_birth ID 402256, no date_of_birth ID 401553
			 */
			$date_of_birth = get_post_meta( $obituary_id, 'date_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'date_of_birth', true ) : null;
			/*
			 * @var string|null $city_of_birth E.g. has city_of_birth ID 402256, no city_of_birth ID 401553.
			 */
			$city_of_birth = get_post_meta( $obituary_id, 'city_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'city_of_birth', true ) : null;
			/*
			 * @var string|null $state_of_birth E.g. has state_of_birth ID 402497, no state_of_birth ID 402320.
			 */
			$state_of_birth = get_post_meta( $obituary_id, 'state_of_birth', true ) != '' ? get_post_meta( $obituary_id, 'state_of_birth', true ) : null;
			/*
			 * @var string|null $date_of_death E.g. has date_of_death ID 384051, no date_of_death ID 384020.
			 */
			$date_of_death = get_post_meta( $obituary_id, 'date_of_death', true ) != '' ? get_post_meta( $obituary_id, 'date_of_death', true ) : null;
			/*
			 * @var string|null $city_of_death E.g. has city_of_death ID 402256, no city_of_death ID 401553.
			 */
			$city_of_death = get_post_meta( $obituary_id, 'city_of_death', true ) != '' ? get_post_meta( $obituary_id, 'city_of_death', true ) : null;
			/*
			 * @var string|null $state_of_death E.g. has state_of_death ID 402497, no state_of_death ID 402320.
			 */
			$state_of_death = get_post_meta( $obituary_id, 'state_of_death', true ) != '' ? get_post_meta( $obituary_id, 'state_of_death', true ) : null;
			/*
			 * @var string|null $details_of_services E.g. has details_of_services ID 402320, no details_of_services ID 402256.
			 */
			$details_of_services = get_post_meta( $obituary_id, 'details_of_services', true ) != '' ? get_post_meta( $obituary_id, 'details_of_services', true ) : null;
			/*
			 * @var string|null $obitbiography E.g. has obitbiography ID 394221, no obitbiography ID 394199.
			 */
			$obitbiography = get_post_meta( $obituary_id, 'obitbiography', true ) != '' ? get_post_meta( $obituary_id, 'obitbiography', true ) : null;
			/*
			 * @var string|null $obitfamily_information E.g. has obitfamily_information ID 394221, no obitfamily_information ID 394199.
			 */
			$obitfamily_information = get_post_meta( $obituary_id, 'obitfamily_information', true ) != '' ? get_post_meta( $obituary_id, 'obitfamily_information', true ) : null;

			// Possible characters for replacing for other types of content.
			$not_used_dev = [
				' ' => '',
			];

			$details_of_services = trim( apply_filters( 'the_content', trim( $details_of_services ) ) );
			$details_of_services = str_replace( "\r\n", "\n", $details_of_services );
			$details_of_services = str_replace( "\n", "", $details_of_services );
			$obitbiography = trim( apply_filters( 'the_content', trim( $obitbiography ) ) );
			$obitbiography = str_replace( "\r\n", "\n", $obitbiography );
			$obitbiography = str_replace( "\n", "", $obitbiography );
			$obitfamily_information = trim( apply_filters( 'the_content', trim( $obitfamily_information ) ) );
			$obitfamily_information = str_replace( "\r\n", "\n", $obitfamily_information );
			$obitfamily_information = str_replace( "\n", "", $obitfamily_information );

			$acf_args = [
				'_thumbnail_id' => $thumbnail_id,
				'name_of_deceased' => $name_of_deceased,
				'date_of_birth' => $date_of_birth,
				'city_of_birth' => $city_of_birth,
				'state_of_birth' => $state_of_birth,
				'date_of_death' => $date_of_death,
				'city_of_death' => $city_of_death,
				'state_of_death' => $state_of_death,
				'details_of_services' => $details_of_services,
				'obitbiography' => $obitbiography,
				'obitfamily_information' => $obitfamily_information,
			];
			$acf_additional_args = [
				'submitter_firstname' => get_post_meta( $obituary_id, 'submitter_firstname' ),
				'submitter_lastname' => get_post_meta( $obituary_id, 'submitter_lastname' ),
				'submitter_email' => get_post_meta( $obituary_id, 'submitter_email' ),
				'display_submitter_info' => get_post_meta( $obituary_id, 'display_submitter_info' ),
				'submitter_phone' => get_post_meta( $obituary_id, 'submitter_phone' ),
			];

			// New values.
			$post_content = $this->get_obituary_content( $acf_args );

			// Update to type post, set title and content.
			$wpdb->query( $wpdb->prepare( "update {$wpdb->posts} set post_type='post', post_content='%s' where ID=%d;", $post_content, $obituary_id ) );

			// Set meta 'newspack_vtd_cpt' = self::OBITUARY_CPT;
			update_post_meta( $obituary_id, self::META_VTD_CPT, self::OBITUARY_CPT );

			// Assign category for Obituaries.
			wp_set_post_categories( $obituary_id, [ $obituaries_cat_id ], true );
		}

		$this->logger->log( $log, implode( ',', $obituaries_ids ), false );

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done; see %s", $log ) );
	}

	/**
	 * @param array $replacements {
	 *     Keys are search strings, values are replacements. Expected and mandatory keys:
	 *
	 *     @type int|null    $thumbnail_id           Thumbnail ID.
	 *     @type string|null $name_of_deceased       Value for "{{name_of_deceased}}".
	 *     @type string|null $date_of_birth          Value for "{{date_of_birth}}".
	 *     @type string|null $city_of_birth          Value for "{{city_of_birth}}".
	 *     @type string|null $state_of_birth         Value for "{{state_of_birth}}".
	 *     @type string|null $date_of_death          Value for "{{date_of_death}}".
	 *     @type string|null $city_of_death          Value for "{{city_of_death}}".
	 *     @type string|null $state_of_death         Value for "{{state_of_death}}".
	 *     @type string|null $details_of_services    Value for "{{details_of_services}}".
	 *     @type string|null $obitbiography          Value for "{{obitbiography}}".
	 *     @type string|null $obitfamily_information Value for "{{obitfamily_information}}".
	 *
	 * @return void
	 */
	public function get_obituary_content( $replacements ) {
		$log_error = 'vtd_obituaries_template_error.log';

		$post_content = '';

		// Image.
		if ( ! is_null( $replacements['_thumbnail_id'] ) ) {
			$img_template = <<<HTML
<!-- wp:image {"align":"right","id":%d,"width":353,"sizeSlug":"large","linkDestination":"none","className":"is-resized"} -->
<figure class="wp-block-image alignright size-large is-resized"><img src="%s" alt="" class="wp-image-%d" width="353"/></figure>
<!-- /wp:image -->
HTML;
			$src = wp_get_attachment_url( $replacements['_thumbnail_id'] );
			if ( false == $src || empty( $src ) || ! $src ) {
				$this->logger->log( $log_error, sprintf( "not found src for _thumbnail_id %d", $replacements['_thumbnail_id'] ) );
			}

			$wp_image = sprintf( $img_template, $replacements['_thumbnail_id'], $src, $replacements['_thumbnail_id'] );
			$post_content .= $wp_image;
		}

		// name_of_deceased.
		if ( ! is_null( $replacements['name_of_deceased'] ) ) {
			$spaces = <<<HTML


HTML;
			if ( ! empty( $post_content ) ) {
				$post_content .= $spaces;
			}

			$wp_paragraph_template = <<<HTML
<!-- wp:paragraph -->
<p>{{name_of_deceased}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{name_of_deceased}}', $replacements['name_of_deceased'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// date_of_birth, city_of_birth, state_of_birth
		if ( ! is_null( $replacements['date_of_birth'] ) || ! is_null( $replacements['city_of_birth'] ) || ! is_null( $replacements['state_of_birth'] ) ) {

			// The first paragraph goes with or without date of birth, if any of the birth info is present.
			$wp_paragraph_1_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Born </strong>{{date_of_birth}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_1 = str_replace( '{{date_of_birth}}', ! is_null( $replacements['date_of_birth'] ) ? $replacements['date_of_birth'] : '', $wp_paragraph_1_template );
			$post_content .= $wp_paragraph_1;

			// Second paragraph goes only if either city_of_birth or state_of_birth is present.
			$wp_paragraph_2_template = <<<HTML


<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_2_values = $replacements['city_of_birth'] ?? '';
			$wp_paragraph_2_values .= ( ! empty( $wp_paragraph_2_values ) && ! empty( $replacements['state_of_birth'] ) ) ? ', ' : '';
			$wp_paragraph_2_values .= ! empty( $replacements['state_of_birth'] ) ? $replacements['state_of_birth'] : '';
			if ( ! empty( $wp_paragraph_2_values ) ) {
				$wp_paragraph_2 = sprintf( $wp_paragraph_2_template, $wp_paragraph_2_values );

				$post_content .= $wp_paragraph_2;
			}
		}

		// date_of_death, city_of_death, state_of_death
		if ( ! is_null( $replacements['date_of_death'] ) || ! is_null( $replacements['city_of_death'] ) || ! is_null( $replacements['state_of_death'] ) ) {

			// The first paragraph goes with or without date of death, if any of the death info is present.
			$wp_paragraph_1_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Died </strong>{{date_of_death}}</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_1 = str_replace( '{{date_of_death}}', ! is_null( $replacements['date_of_death'] ) ? $replacements['date_of_death'] : '', $wp_paragraph_1_template );
			$post_content .= $wp_paragraph_1;

			// Second paragraph goes only if either city_of_death or state_of_death is present.
			$wp_paragraph_2_template = <<<HTML


<!-- wp:paragraph -->
<p>%s</p>
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph_2_values = $replacements['city_of_death'] ?? '';
			$wp_paragraph_2_values .= ( ! empty( $wp_paragraph_2_values ) && ! empty( $replacements['state_of_death'] ) ) ? ', ' : '';
			$wp_paragraph_2_values .= ! empty( $replacements['state_of_death'] ) ? $replacements['state_of_death'] : '';
			if ( ! empty( $wp_paragraph_2_values ) ) {
				$wp_paragraph_2 = sprintf( $wp_paragraph_2_template, $wp_paragraph_2_values );

				$post_content .= $wp_paragraph_2;
			}
		}

		// details_of_services
		if ( ! empty( $replacements['details_of_services'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Details of services</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
{{details_of_services}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{details_of_services}}', $replacements['details_of_services'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// wp:separator
		$wp_paragraph_template = <<<HTML


<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->
HTML;
		$post_content .= $wp_paragraph_template;

		// obitbiography
		if ( ! empty( $replacements['obitbiography'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
{{obitbiography}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{obitbiography}}', $replacements['obitbiography'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		// obitfamily_information
		if ( ! empty( $replacements['obitfamily_information'] ) ) {
			$wp_paragraph_template = <<<HTML


<!-- wp:paragraph -->
<p><strong>Family information</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
{{obitfamily_information}}
<!-- /wp:paragraph -->
HTML;
			$wp_paragraph = str_replace( '{{obitfamily_information}}', $replacements['obitfamily_information'], $wp_paragraph_template );
			$post_content .= $wp_paragraph;
		}

		return $post_content;
	}

	/**
	 * Fetch or create the destination category tree:
	 *	Regional
	 *		Champlain Valley
	 *			Chittenden County
	 *				Burlington
	 *			Grand Isle County
	 *			Franklin County
	 *			Addison County
	 *		Northeast Kingdom
	 *			Orleans County
	 *			Essex County
	 *			Caledonia County
	 *		Central Vermont
	 *			Washington County
	 *			Lamoille County
	 *			Orange County
	 *		Southern Vermont
	 *			Windsor County
	 *			Rutland County
	 *			Bennington County
	 *			Windham County
	 **/
	private function get_county_to_category_tree() {

		// phpcs:disable -- leave this indentation for clear hierarchical overview.
		$regional_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Regional', 0 );
			$champlain_valley_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Champlain Valley', $regional_id );
				$chittenden_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Chittenden County', $champlain_valley_id );
					$burlington_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Burlington', $chittenden_county_id );
				$grand_isle_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Grand Isle County', $champlain_valley_id );
				$franklin_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Franklin County', $champlain_valley_id );
				$addison_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Addison County', $champlain_valley_id );
			$northeast_kingdom_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Northeast Kingdom', $regional_id );
				$orleans_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Orleans County', $northeast_kingdom_id );
				$essex_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Essex County', $northeast_kingdom_id );
				$caledonia_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Caledonia County', $northeast_kingdom_id );
			$central_vermont_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Central Vermont', $regional_id );
				$washington_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Washington County', $central_vermont_id );
				$lamoille_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Lamoille County', $central_vermont_id );
				$orange_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Orange County', $central_vermont_id );
			$southern_vermontt_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Southern Vermont', $regional_id );
				$windsor_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Windsor County', $southern_vermontt_id );
				$rutland_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Rutland County', $southern_vermontt_id );
				$bennington_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Bennington County', $southern_vermontt_id );
				$windham_county_id = $this->taxonomy_logic->get_or_create_category_by_name_and_parent_id( 'Windham County', $southern_vermontt_id );
		// phpcs:enable

		$county_name_to_cat_id = [
			'Addison' => $addison_county_id,
			'Bennington' => $bennington_county_id,
			'Caledonia' => $caledonia_county_id,
			'Chittenden' => $chittenden_county_id,
			'Essex' => $essex_county_id,
			'Franklin' => $franklin_county_id,
			'Grand Isle' => $grand_isle_county_id,
			'Lamoille' => $lamoille_county_id,
			'Orange' => $orange_county_id,
			'Orleans' => $orleans_county_id,
			'Rutland' => $rutland_county_id,
			'Washington' => $washington_county_id,
			'Windham' => $windham_county_id,
			'Windsor' => $windsor_county_id,
		];

		return $county_name_to_cat_id;
	}


	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-counties`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_counties( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$log = 'vtd_counties.log';

		WP_CLI::log( "Creating/fetching county category tree..." );
		$county_name_to_cat_id = $this->get_county_to_category_tree();

		// Get all term_ids, term_taxonomy_ids and term names with 'counties' taxonomy.
		$counties_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::COUNTIES_TAXONOMY
			),
			ARRAY_A
		);

		// Loop through all 'counties' terms.
		foreach ( $counties_terms as $key_county_term => $county_term ) {
			$term_id = $county_term['term_id'];
			$term_taxonomy_id = $county_term['term_taxonomy_id'];
			$term_name = $county_term['name'];

			$this->logger->log( $log, sprintf( "(%d)/(%d) %d %d %s", $key_county_term + 1, count( $counties_terms ), $term_id, $term_taxonomy_id, $term_name ), true );

			// Get all objects for this 'county' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);

			// Get the destination category.
			$destination_cat_id = $county_name_to_cat_id[$term_name] ?? null;
			// We should have all 'counties' on record. Double check.
			if ( is_null( $destination_cat_id ) ) {
				throw new \RuntimeException( sprintf( "County term_id=%d term_taxonomy_id=%d name=%s is not mapped by the migrator script.", $term_id, $term_taxonomy_id, $term_name ) );
			}

			// Assign the destination category to all objects.
			foreach ( $object_ids as $object_id ) {
				$this->logger->log( $log, sprintf( "post_id=%d to category_id=%d", $object_id, $destination_cat_id ), true );
				wp_set_post_categories( $object_id, [ $destination_cat_id ], true );
			}

			// Remove the custom taxonomy from objects, leaving just the newly assigned category.
			$wpdb->query(
				$wpdb->prepare(
					"delete from {$wpdb->term_relationships} where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
		}

		WP_CLI::success( "Done. See {$log}." );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-series`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_series( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$log = 'vtd_series.log';

		// Get all term_ids, term_taxonomy_ids and term names with 'series' taxonomy.
		$seriess_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::SERIES_TAXONOMY
			),
			ARRAY_A
		);

		// Loop through all 'series' terms.
		foreach ( $seriess_terms as $key_series_term => $series_term ) {
			$term_id = $series_term['term_id'];
			$term_taxonomy_id = $series_term['term_taxonomy_id'];
			$term_name = $series_term['name'];

			// Get all objects for this 'series' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);

			$this->logger->log( $log, sprintf( "(%d)/(%d) %d %d %s count=%d", $key_series_term + 1, count( $seriess_terms ), $term_id, $term_taxonomy_id, $term_name, count( $object_ids ) ), true );
			if ( 0 == count( $object_ids ) ) {
				WP_CLI::log( "0 posts, skipping." );
				continue;
			}

			// Assign the tag to posts/objects.
			foreach ( $object_ids as $object_id ) {
				$this->logger->log( $log, sprintf( "post_id=%d tag='%s'", $object_id, self::SERIES_TAG_NAME ), true );

				// Tag, append.
				wp_set_post_tags( $object_id, [ self::SERIES_TAG_NAME ], true );
			}

			// Remove this term from objects, leaving just the newly assigned category.
			$wpdb->query(
				$wpdb->prepare(
					"delete from {$wpdb->term_relationships} where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
		}

		WP_CLI::success( "Done. See {$log}." );
	}

	/**
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_series_redo_tags_differently( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$log = 'vtd_series_redo_tags_differently.log';

		// Get all term_ids, term_taxonomy_ids and term names with 'series' taxonomy.
		$series_terms = $wpdb->get_results(
			$wpdb->prepare(
				"select tt.term_id as term_id, tt.term_taxonomy_id as term_taxonomy_id, t.name as name 
				from {$wpdb->term_taxonomy} tt
				join {$wpdb->terms} t on t.term_id = tt.term_id 
				where tt.taxonomy = '%s';",
				self::SERIES_TAXONOMY
			),
			ARRAY_A
		);

		// Loop through all 'series' terms.
		foreach ( $series_terms as $key_series_term => $series_term ) {
			$term_id = $series_term['term_id'];
			$term_taxonomy_id = $series_term['term_taxonomy_id'];
			$term_name = $series_term['name'];

			// Get all objects for this 'series' term's term_taxonomy_id.
			$object_ids = $wpdb->get_col(
				$wpdb->prepare(
					"select object_id from {$wpdb->term_relationships} vwtr where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);

			$this->logger->log( $log, sprintf( "(%d)/(%d) %d %d %s count=%d", $key_series_term + 1, count( $series_terms ), $term_id, $term_taxonomy_id, $term_name, count( $object_ids ) ), true );
			if ( 0 == count( $object_ids ) ) {
				WP_CLI::log( "0 posts, skipping." );
				continue;
			}

			// Assign the tag to posts/objects.
			foreach ( $object_ids as $object_id ) {
				$this->logger->log( $log, sprintf( "post_id=%d tag='%s'", $object_id, self::SERIES_TAG_NAME ), true );

				// Tag, append.
				wp_set_post_tags( $object_id, [ self::SERIES_TAG_NAME ], true );
			}

			// Remove this term from objects, leaving just the newly assigned category.
			$wpdb->query(
				$wpdb->prepare(
					"delete from {$wpdb->term_relationships} where term_taxonomy_id = %d;",
					$term_taxonomy_id
				)
			);
		}

		WP_CLI::success( "Done. See {$log}." );
	}

	/**
	 * Callable for `newspack-content-migrator vtdigger-migrate-authors`.
	 *
	 * @param array $pos_args   Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function cmd_authors( array $pos_args, array $assoc_args ) {
		global $wpdb;

		$logs = [
			'previously_migrated_skipping'                  => 'vtd_authors__previously_migrated_skipping.log',
			'created_gas_from_acf'                          => 'vtd_authors__created_gas_from_acf.log',
			'created_gas_from_wpusers'                      => 'vtd_authors__created_gas_from_wpusers.log',
			'post_ids_obituaries'                           => 'vtd_authors__post_ids_obituaries.log',
			'post_ids_letters_to_editor'                    => 'vtd_authors__post_ids_letters_to_editor.log',
			'assigned_gas_post_ids'                         => 'vtd_authors__assigned_gas_post_ids.log',
			'already_assigned_gas_post_ids_pre_authornames' => 'vtd_authors__already_assigned_gas_post_ids_pre_authornames.log',
			'already_assigned_gas_post_ids'                 => 'vtd_authors__already_assigned_gas_post_ids.log',
			'post_ids_failed_author'                        => 'vtd_authors__post_ids_failed_author.log',
			'post_has_no_authors_at_all'                    => 'vtd_authors__post_has_no_authors_at_all.log',
			// DEV helper, things not yet done, just log and skip these:
			'post_ids_was_newsbrief_not_assigned'           => 'vtd_authors__post_id_was_newsbrief_not_assigned.log',
			'post_ids_was_liveblog_not_assigned'            => 'vtd_authors__post_id_was_liveblog_not_assigned.log',
		];

		// Local caching var.
		$cached_authors_meta = [];

		// Get/create GA for CPTs.
		$obituaries_ga = $this->cap_logic->get_guest_author_by_display_name( self::OBITUARIES_GA_NAME );
		$obituaries_ga_id = $obituaries_ga->ID ?? null;
		if ( ! $obituaries_ga_id ) {
			$obituaries_ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => self::OBITUARIES_GA_NAME ] );
		}
		$letters_to_editor_ga = $this->cap_logic->get_guest_author_by_display_name( self::LETTERS_TO_EDITOR_GA_NAME );
		$letters_to_editor_ga_id = $letters_to_editor_ga->ID ?? null;
		if ( ! $letters_to_editor_ga_id ) {
			$letters_to_editor_ga_id = $this->cap_logic->create_guest_author( [ 'display_name' => self::LETTERS_TO_EDITOR_GA_NAME ] );
		}


		WP_CLI::log( "Fetching Post IDs..." );
		$post_ids = $this->posts_logic->get_all_posts_ids( 'post', [ 'publish', 'future', 'draft', 'pending', 'private' ] );
		// $post_ids = [386951,]; // DEV test.

		// Loop through all posts and create&assign GAs.
		foreach ( $post_ids as $key_post_id => $post_id ) {
			WP_CLI::log( sprintf( "(%d)/(%d) %d", $key_post_id + 1, count( $post_ids ), $post_id ) );

			// Skip some specific dev IDs.
			if ( in_array( $post_id, [ 410385 ] ) ) {
				WP_CLI::log( "skipping DEV ID" );
				continue;
			}

			// Skip if already imported.
			if ( get_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true ) ) {
				$this->logger->log( $logs[ 'previously_migrated_skipping' ], sprintf( "PREVIOUSLY_MIGRATED_SKIPPING post_id=%d", $post_id ), true );
				continue;
			}

			// Get if this post used to be a CPT.
			$was_cpt = get_post_meta( $post_id, self::META_VTD_CPT, true );

			// Assign specific GAs to ex CPTs.
			if ( $was_cpt && ( self::OBITUARY_CPT == $was_cpt ) ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $obituaries_ga_id ], $post_id);
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				$this->logger->log( $logs[ 'post_ids_obituaries' ], sprintf( "OBITUARY post_id=%d", $post_id ), true );
				continue;
			} elseif ( $was_cpt && ( self::LETTERS_TO_EDITOR_CPT == $was_cpt ) ) {
				$this->cap_logic->assign_guest_authors_to_post( [ $letters_to_editor_ga_id ], $post_id );
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				$this->logger->log( $logs['post_ids_letters_to_editor'], sprintf( "LETTERS_TO_EDITOR post_id=%d", $post_id ), true );
				continue;
			}
			//
			// WIP -- for now just log and skip those CPTs we don't know how to handle yet. Answers will come soon and this part will be refined.
			//
			elseif ( $was_cpt && ( self::NEWSBRIEF_CPT == $was_cpt ) ) {
				$this->logger->log( $logs['post_ids_was_newsbrief_not_assigned'], sprintf( "NEWSBRIEF post_id=%d", $post_id ), true );
				WP_CLI::log( 'not sure how to handle this CPT, skipping for now' );
				continue;
			} elseif ( $was_cpt && ( self::LIVEBLOG_CPT == $was_cpt ) ) {
				$this->logger->log( $logs['post_ids_was_liveblog_not_assigned'], sprintf( "LIVEBLOG post_id=%d", $post_id ), true );
				WP_CLI::log( 'not sure how to handle this CPT, skipping for now' );
				continue;
			}

			// Skip if it already has GAs assigned.
			$existing_ga_ids = $this->cap_logic->get_posts_existing_ga_ids( $post_id );
			if ( ! empty( $existing_ga_ids ) ) {
				$this->logger->log( $logs['already_assigned_gas_post_ids_pre_authornames'], sprintf( "HAS_GAs post_id=%d", $post_id ), true );
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				continue;
			}

			/**
			 * Get author names for this post.
			 * Author names are terms with 'author' taxonomy. And the actual data for these authors can either be located in ACF 'vtd_team' Post objects,
			 * or can be regular WP Users.
			 */
			$author_names = $wpdb->get_col(
				$wpdb->prepare(
					"select name
					from {$wpdb->terms} where term_id in (
						select term_id
						from {$wpdb->term_taxonomy} vwtt
						where taxonomy = 'author' and term_taxonomy_id in (
							select term_taxonomy_id
							from {$wpdb->term_relationships} vwtr
							where object_id = %d
						)
					);",
					$post_id
				)
			);

			// GA IDs for this Post.
			$ga_ids = [];

			/**
			 * First try and get or create GAs based on $author_names.
			 */
			if ( ! empty( $author_names ) ) {

				foreach ( $author_names as $author_name ) {
					WP_CLI::log( "author_name=" . $author_name );

					// Get existing GA.
					$ga = $this->cap_logic->get_guest_author_by_display_name( $author_name );
					$ga_id = $ga->ID ?? null;

					// Create GA if it doesn't exist.
					if ( is_null( $ga_id ) ) {

						/**
						 * 1/2 First try and create GA from ACF author Post object with this name.
						 */
						$acf_author_meta = isset( $cached_authors_meta[ $author_name ] )
							? $cached_authors_meta[ $author_name ]
							: $this->get_acf_author_meta( $author_name );
						if ( ! is_null( $acf_author_meta ) ) {
							// Cache entry.
							if ( ! isset( $cached_authors_meta[ $author_name ] ) ) {
								$cached_authors_meta[ $author_name ] = $acf_author_meta;
							}
							// Create GA.
							$ga_id = $this->create_ga_from_acf_author( $author_name, $acf_author_meta );
							$this->logger->log( $logs['created_gas_from_acf'], sprintf( "CREATED_FROM_ACF post_id=%d name='%s' ga_id=%d", $post_id, $author_name, $ga_id ), true );
						}

						/**
						 * 2/2 Next try and create GA from WP User with this display_name.
						 */
						if ( is_null( $ga_id ) ) {
							$ga_id = $this->create_ga_from_wp_user( $post_id, $author_name, $logs['created_gas_from_wpusers'] );

						}
					}

					// Add new or existing.
					if ( $ga_id ) {
						$ga_ids[] = $ga_id;
					}
				} // Done foreach $author_names.

			} else {

				/**
				 * Next try and create GAs just from pure WP User author.
				 */
				// Get WP User author's display_name as $author_name.
				$author_name = $wpdb->get_var(
					$wpdb->prepare(
						"select u.display_name
						from {$wpdb->users} u
						join {$wpdb->posts} p on p.post_author = u.ID 
						where p.ID = %d ; ",
						$post_id
					)
				);
				if ( $author_name ) {
					$ga_id = $this->create_ga_from_wp_user( $post_id, $author_name, $logs['created_gas_from_wpusers'] );
					if ( $ga_id ) {
						$ga_ids[] = $ga_id;
					}
				}
			}


			// This is where author creation/assignment failed completely, GA was not created or fetched from known sources.
			if ( empty( $ga_ids ) ) {
				$this->logger->log( $logs['post_ids_failed_author'], sprintf( "FAILED_AUTHOR___SKIPPED post_id=%d author_name='%s'", $post_id, $author_name ), true );
				// Continue to next $post_id.
				continue;
			}

			// Assign GAs to post and log all post_ids.
			$existing_ga_ids = $this->cap_logic->get_posts_existing_ga_ids( $post_id );
			$new_ga_ids = array_unique( array_merge( $existing_ga_ids, $ga_ids ) );
			if ( $existing_ga_ids != $new_ga_ids ) {
				$this->cap_logic->assign_guest_authors_to_post( $new_ga_ids, $post_id );
				update_post_meta( $post_id, self::META_AUTHORS_MIGRATED, true );
				$this->logger->log( $logs['assigned_gas_post_ids'], sprintf( "NEWLY_ASSIGNED_GAS post_id=%d ga_ids=%s", $post_id, implode( ',', $new_ga_ids ) ), true );
			} elseif ( ! empty( $existing_ga_ids ) ) {
				$this->logger->log( $logs['already_assigned_gas_post_ids'], sprintf( "ALREADY_ASSIGNED post_id=%d ga_ids=%s", $post_id, implode( ',', $new_ga_ids ) ), true );
			} else {
				$this->logger->log( $logs['post_has_no_authors_at_all'], sprintf( "NO_AUTHORS_AT_ALL post_id=%d", $post_id ), true );
			}

		}

		wp_cache_flush();
		WP_CLI::log( sprintf( "Done, see %s ", implode( ', ', array_keys( $logs ) ) ) );
	}

	/**
	 * Creates GA from WP User with display_name or user_nicename same as $author_name.
	 *
	 * @param int    $post_id     Post ID being processed (for logging).
	 * @param string $author_name Display name.
	 * @param string $log         Log file name.
	 *
	 * @return int|null GA ID or null.
	 */
	public function create_ga_from_wp_user( int $post_id, string $author_name, string $log ) {
		global $wpdb;

		// Try and get WP user with display_name.
		$wp_user_row = $wpdb->get_row(
			$wpdb->prepare(
				"select * from {$wpdb->users} where display_name = %s; ",
				$author_name
			),
			ARRAY_A
		);

		// Next, try and get a WP user with that user_nicename.
		if ( is_null( $wp_user_row ) ) {
			$wp_user_row = $wpdb->get_row(
				$wpdb->prepare(
					"select * from {$wpdb->users} where user_nicename = %s; ",
					$author_name
				),
				ARRAY_A
			);

			// Get $author_name from display_name.
			if ( $wp_user_row ) {

				/**
				 * Handle exceptions manually.
				 */
				// This user has 'Commentary' for display_name, let's not use that.
				if ( 'opinion' == $wp_user_row['user_nicename'] ) {
					$author_name = 'Opinion';
				} elseif ( 'stacey1' == $wp_user_row['user_nicename'] ) {
					// This is a weird one. This user has 'stacey 2' for display_name, let's not use that.
					$author_name = 'stacey1';
				} elseif ( 'ben-heintz' == $wp_user_row['user_nicename'] ) {
					// This user has 'Underground Workshop' for display_name, let's not use that.
					$author_name = 'Ben Heintz';
				} else {
					$author_name = $wp_user_row['display_name'];
				}
			}
		}

		// Next, try and get a WP user with that user_login.
		if ( is_null( $wp_user_row ) ) {
			$wp_user_row = $wpdb->get_row(
				$wpdb->prepare(
					"select * from {$wpdb->users} where user_login = %s; ",
					$author_name
				),
				ARRAY_A
			);

			// Get $author_name from display_name.
			if ( $wp_user_row ) {
				/**
				 * Handle exceptions manually.
				 */
				// This user has 'Underground Workshop' for display_name, let's not use that.
				if ( 'Ben Heintz' == $wp_user_row['user_login'] ) {
					$author_name = 'Ben Heintz';
				} elseif ( 'Ben Opinion' == $wp_user_row['user_login'] ) {
					// This user has 'Commentary' for display_name, let's not use that.
					$author_name = 'Opinion';
				} else {
					$author_name = $wp_user_row['display_name'];
				}
			}
		}

		// Still nothing. Exit.
		if ( is_null( $wp_user_row ) ) {
			return null;
		}

		$social_sources = '';
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'aim', true ) )        ? 'AIM: ' .                  get_user_meta( $wp_user_row['ID'], 'aim', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'yim', true ) )        ? 'Yahoo IM: ' .             get_user_meta( $wp_user_row['ID'], 'yim', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'jabber', true ) )     ? 'Jabber / Google Talk: ' . get_user_meta( $wp_user_row['ID'], 'jabber', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'facebook', true ) )   ? 'Facebook: ' .             get_user_meta( $wp_user_row['ID'], 'facebook', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'instagram', true ) )  ? 'Instagram: ' .            get_user_meta( $wp_user_row['ID'], 'instagram', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'linkedin', true ) )   ? 'LinkedIn: ' .             get_user_meta( $wp_user_row['ID'], 'linkedin', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'myspace', true ) )    ? 'MySpace: ' .              get_user_meta( $wp_user_row['ID'], 'myspace', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'pinterest', true ) )  ? 'Pinterest: ' .            get_user_meta( $wp_user_row['ID'], 'pinterest', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'soundcloud', true ) ) ? 'SoundCloud: ' .           get_user_meta( $wp_user_row['ID'], 'soundcloud', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'twitter', true ) )    ? 'Twitter: @' .             get_user_meta( $wp_user_row['ID'], 'twitter', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'youtube', true ) )    ? 'YouTube: ' .              get_user_meta( $wp_user_row['ID'], 'youtube', true ) . '. ' : null;
		$social_sources .= ! empty( get_user_meta( $wp_user_row['ID'], 'wikipedia', true ) )  ? 'Wikipedia: ' .            get_user_meta( $wp_user_row['ID'], 'wikipedia', true ) . '. ' : null;

		$bio = ! empty( get_user_meta( $wp_user_row['ID'], 'description', true ) ) ? get_user_meta( $wp_user_row['ID'], 'description', true ) : null;

		$description = $social_sources
		               . ( ( ! empty( $social_sources ) && ! empty( $bio ) ) ? ' ' : '' )
		               . $bio;

		$ga_args = [
			'display_name' => $author_name,
			'user_email'   => $wp_user_row['user_email'] ?? null,
			'website'      => $wp_user_row['user_url'] ?? null,
			'description'  => ! empty( $description ) ? $description : null,
			// Their WP Users have an external plugin which extends avatar abilities, but these are not used.
			// 'avatar'       => null,
		];

		// Create GA
		$ga_id = $this->cap_logic->create_guest_author( $ga_args );

		// Link to WP User.
		$wp_user = get_user_by( 'ID', $wp_user_row['ID'] );
		$this->cap_logic->link_guest_author_to_wp_user( $ga_id, $wp_user );

		$this->logger->log( $log, sprintf( "CREATED_FROM_WPUSER ga_id=%d name='%s' post_id=%d linked_wp_user_id=%d", $ga_id, $author_name, $post_id, $wp_user_row['ID'] ), true );

		return $ga_id;
	}

	/**
	 * @param string $author_name
	 * @param array  $acf_author_meta
	 *
	 * @return int GA ID.
	 */
	public function create_ga_from_acf_author( string $author_name, array $acf_author_meta ) {
		// Compose $media_link for bio.
		$media_link = '';
		if ( isset( $acf_author_meta['vtd_social_media_handle'] ) && ! empty( $acf_author_meta['vtd_social_media_handle'] ) && isset( $acf_author_meta['vtd_social_media_link'] ) && ! empty( $acf_author_meta['vtd_social_media_link'] ) ) {
			// $media_link is a <a> element if both handle and link given.
			$media_link = sprintf( "<a href=\"%s\" target=\"_blank\">%s</a>", $acf_author_meta['vtd_social_media_link'], $acf_author_meta['vtd_social_media_handle'] );
		} elseif ( isset( $acf_author_meta['vtd_social_media_link'] ) && ! empty( $acf_author_meta['vtd_social_media_link'] ) ) {
			// $media_link is a <a> element if just link given.
			$media_link = sprintf( "<a href=\"%s\" target=\"_blank\">%s</a>", $acf_author_meta['vtd_social_media_link'], $acf_author_meta['vtd_social_media_link'] );
		} elseif ( isset( $acf_author_meta['vtd_social_media_handle'] ) && ! empty( $acf_author_meta['vtd_social_media_handle'] ) ) {
			// $media_link text.
			$media_link = $acf_author_meta['vtd_social_media_handle'];
		}

		// Compose GA description.
		// Start with the title.
		$description = ( isset( $acf_author_meta['vtd_title'] ) && ! empty( $acf_author_meta['vtd_title'] ) ) ? $acf_author_meta['vtd_title'] . '. ' : '';
		// Add media link.
		$description .= $media_link ? $media_link . ' ' : '';
		// Add bio.
		$description .= ( isset( $acf_author_meta['vtd_bio'] ) && ! empty( $acf_author_meta['vtd_bio'] ) ) ? $acf_author_meta['vtd_bio'] : '';

		// Leaving out:
		// 'office_phone', 'cell_phone', 'google_phone', 'vtd_department' (e.g. vtd_department e.g. a:1:{i:0;s:8:"newsroom";})

		$ga_args = [
			'display_name' => $author_name,
			'user_email'   => $acf_author_meta['vtd_email'] ?? null,
			'website'      => $acf_author_meta['vtd_social_media_link'] ?? null,
			'description'  => $description,
			'avatar'       => $acf_author_meta['_thumbnail_id'] ?? null,
		];

		// Create GA
		$ga_id = $this->cap_logic->create_guest_author( $ga_args );

		return $ga_id;
	}

	/**
	 * Gets ACF vtd_team meta from author name.
	 *
	 * @param string $author_name
	 *
	 * @return array|null
	 */
	public function get_acf_author_meta( string $author_name ) : array|null {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"select ID
				from {$wpdb->posts}
				where post_title = %s and post_type = 'vtd_team'; ",
				$author_name
			)
		);
		if ( ! $post_id ) {
			return null;
		}

		$post_meta = $wpdb->get_row(
			$wpdb->prepare(
				"select meta_key, meta_value
				from {$wpdb->postmeta}
				where post_id = %d 
				and meta_key in ( '_thumbnail_id', 'vtd_email', 'vtd_title', 'vtd_bio', 'vtd_social_media_handle', 'vtd_social_media_link', 'office_phone', 'cell_phone', 'google_phone', 'vtd_department' ) ; ",
				$post_id
			),
			ARRAY_A
		);

		return $post_meta;
	}
}
