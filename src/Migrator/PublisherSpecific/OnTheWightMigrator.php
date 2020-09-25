<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \WP_CLI;
use PHPHtmlParser\Dom as Dom;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\WpBlockManipulator as WpBlockManipulator;
use \NewspackContentConverter\ContentPatcher\ElementManipulators\SquareBracketsElementManipulator as SquareBracketsElementManipulator;

/**
 * Custom migration scripts for On The Wight.
 */
class OnTheWightMigrator implements InterfaceMigrator {

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
			'newspack-content-migrator onthewight-tags-to-pages',
			[ $this, 'cmd_tags_to_pages' ],
			[
				'shortdesc' => 'Migrates On The Wight Tags containing HTML description to Pages, and does redirect corrections.',
				'synopsis'  => [
					[
						'type'        => 'flag',
						'name'        => 'dry-run',
						'description' => __('Perform a dry run, making no changes.'),
						'optional'    => true,
					],
				],
			]
		);
		WP_CLI::add_command(
			'newspack-content-migrator onthewight-helper-analyze-used-shortcodes',
			[ $this, 'cmd_helper_analyze_used_shortcodes' ],
			[
				'shortdesc' => 'Helper command, scans all content for used shortcodes, outputs the shortcode designations with Post count or exact Post IDs where they were used.',
			]
		);
	}

	/**
	 * Callable for the `newspack-content-migrator onthewight-helper-analyze-used-shortcodes`.
	 */
	public function cmd_helper_analyze_used_shortcodes( $args, $assoc_args ) {
		if ( ! is_plugin_active( 'newspack-content-converter/newspack-content-converter.php' ) ) {
			WP_CLI::error( '🤭 The Newspack Content Converter plugin is required for this command to work. Please install and activate it first.' );
		}

		global $wpdb;
		$block_manipulator = new WpBlockManipulator;
		$shortcodes = [];

		WP_CLI::line( 'Fetching Posts...' );
		$results = $wpdb->get_results( $wpdb->prepare( sprintf ("SELECT ID FROM %s WHERE post_status = 'publish' and post_type = 'post';", $wpdb->prefix . 'posts' ) ) );
		if ( ! $results ) {
			WP_CLI::error( 'No public Posts found 🤭 Highly dubious!' );
		}

		foreach ( $results as $k => $result ) {

			$post_id = (int) $result->ID;
			$post    = get_post( $post_id );
			$content = $post->post_content;

			// Get WP Shortcode blocks.
			$shortcode_block_matches = $block_manipulator->match_wp_block( 'wp:shortcode', $content );
			if ( null === $shortcode_block_matches ) {
				continue;
			}

			// Loop through the preg_match_all result with Shortcode Blocks matches.
			foreach ( $shortcode_block_matches[0] as $key => $match ) {

				$shortcode_block = $shortcode_block_matches[0][ $key ][0];

				// Now get the Shortcodes inside this block.
				$shortcode_designations_matches = $this->match_all_shortcode_designations( $shortcode_block );
				if ( ! isset( $shortcode_designations_matches[1][0] ) || empty( $shortcode_designations_matches[1][0] ) ) {
					continue;
				}

				// Check if this designation was saved to the $shortcodes before.
				$key_existing = null;
				foreach ( $shortcodes as $k => $shortcodes_found_element ) {
					if ( $shortcode_designations_matches[1] === $shortcodes_found_element[ 'shortcode_matches' ] ) {
						$key_existing = $k;
						break;
					}
				}

				// Add to list of shortcodes, and the Post ID too.
				if ( ! is_null( $key_existing ) ) {
					$shortcodes[ $key_existing ][ 'ids' ][] = $post_id;
				} else {
					$shortcodes[] = [
						'shortcode_matches' => $shortcode_designations_matches[1],
						'ids' => [ $post_id ]
					];
				}
			}
		}

		// Output found shortcodes ordered ascending by number of Posts they're used in.
		$results_shortcodes_by_usage = [];
		foreach ( $shortcodes as $shortcode ) {
			$results_shortcodes_by_usage[ count( $shortcode['ids'] ) ] .=
				( isset( $results_shortcodes_by_usage[ count( $shortcode['ids'] ) ] ) ? "\n" : '' ) .
				sprintf(
					'👉 %s',
					implode( $shortcode['shortcode_matches'], ' > ' )
				) .
				"\n" .
				'total IDs ' . count( $shortcode['ids'] ) . ': ' . implode( $shortcode['ids'], ',' );
		}
		ksort( $results_shortcodes_by_usage );
		WP_CLI::line( implode( "\n", $results_shortcodes_by_usage ) );
	}

	/**
	 * Result of preg_match_all matching all shortcode designations.
	 *
	 * @param string $content
	 *
	 * @return mixed
	 */
	private function match_all_shortcode_designations( $content ) {
		$pattern_shortcode_designation = '|
			\[          # shortcode opening bracket
			([^\s/\]]+) # match the shortcode designation string (which is anything except space, forward slash, and closing bracket)
			[^\]]+      # zero or more of any char except closing bracket
			\]          # closing bracket
		|xim';
		preg_match_all( $pattern_shortcode_designation, $content, $matches );

		return $matches;
	}

	/**
	 * Callable for the `newspack-content-migrator cmd_tags_to_pages command`.
	 */
	public function cmd_tags_to_pages( $args, $assoc_args ) {
		$dry_run = $assoc_args['dry-run'] ? true : false;

		if ( ! class_exists( \Red_Item::class ) ) {
			WP_CLI::error( '🤭 The johngodley/redirection plugin is required for this command to work. Please first install and activate it.' );
		}

		WP_CLI::confirm( "❗ Warning/info ❗ Only run this command once since re-running it would create duplicate Pages and redirection rules. There's also the `--dry-run` flag you can use. Continue?" );

		$tags = get_tags();
		if ( ! $tags ) {
			WP_CLI::error( 'No tags were found. Most unusual... 🤔' );
		}

		// Check the parent Page for Pages we're about to create.
		$parent_page_slug = 'about';
		$parent_page      = get_page_by_path( $parent_page_slug );
		if ( ! $parent_page ) {
			WP_CLI::error( sprintf(
				"Could not find parent Page with slug '%s'... 🤭 Please edit this Migrator, update the hard-coded parent Page slug, and then give it another spin.",
				$parent_page_slug
			) );
		}

		if ( ! $dry_run ) {
			// Update Tag Base URL and rewrite rules to use `/tag/{TAG_SLUG}` URL schema for Tags.
			$this->update_wp_tag_base_and_existing_rewrite_rules( 'about/', 'tag/' );
		}

		// His name is Dom. Probably short for Dominic. (who says we can't have fun while migrating content... :) )
		$dom_parser = new Dom;

		foreach ( $tags as $tag ) {

			$is_tag_converted_to_page = false;

			// Don't create Pages for Tags without description.
			if ( ! empty( $tag->description ) ) {

				$dom_parser->loadStr( $tag->description );
				$h1_node = $dom_parser->find( 'h1', 0 );
				if ( ! $h1_node ) {
					continue;
				}

				// Get the rest of the description without the heading part.
				$heading_html                = $h1_node->outerHtml();
				$description_without_heading = trim( substr(
					$tag->description,
					strpos( $tag->description, $heading_html ) + strlen( $heading_html )
				) );

				// If there's some more HTML in the description, create a Page for the Tag.
				if ( $this->has_string_html( $description_without_heading ) ) {

					if ( $dry_run ) {
						WP_CLI::line( sprintf( '👍 creating Page from Tag %s', $tag->slug ) );
						WP_CLI::line( sprintf( "-> adding post_meta to the new Page: '%s' = '%s'", '_migrated_from_tag', $tag->slug ) );
					} else {
						// Create a Page.
						$post_details = array(
							'post_title'   => $h1_node->text,
							'post_content' => $description_without_heading,
							'post_parent'  => $parent_page->ID,
							'post_name'    => $tag->slug,
							'post_author'  => 1,
							'post_type'    => 'page',
							'post_status'  => 'publish',
						);
						$new_page_id  = wp_insert_post( $post_details );
						if ( 0 === $new_page_id || is_wp_error( $new_page_id ) ) {
							WP_CLI::error( sprintf(
								"Something went wrong when trying to create a Page from Tag term_id = %d. 🥺 So sorry about that...",
								$tag->term_id
							) );
						}

						// Add meta to the new page to indicate which tag it came from.
						add_post_meta( $new_page_id, '_migrated_from_tag', $tag->slug );

						WP_CLI::line( sprintf( '👍 created Page ID %d from Tag %s', $new_page_id, $tag->slug ) );
					}

					// Create a redirect rule to redirect this Tag's legacy URL to the new Page.
					$url_from = '/tag/' . $tag->slug . '[/]?';
					if ( $dry_run ) {
						WP_CLI::line( sprintf( '-> creating Redirect Rule from `%s` to the new Page', $url_from ) );
					} else {
						$this->create_redirection_rule(
							'Archive Tag to Page -- ' . $tag->slug,
							$url_from,
							get_the_permalink( $new_page_id )
						);

						WP_CLI::line( sprintf( '-> created Redirect Rule from `%s` to %s', $url_from, get_the_permalink( $new_page_id ) ) );
					}

					$is_tag_converted_to_page = true;
				}
			}

			if ( ! $is_tag_converted_to_page ) {
				WP_CLI::line( sprintf( '✓ creating redirection rule for updated Tag URL %s', $tag->slug ) );

				if ( $dry_run ) {
					continue;
				}

				// Redirect config: if we didn't create a Page, redirect this Tag's old URL `/about/{TAG_SLUG}` to the new `/tag/{TAG_SLUG}` URL.
				$this->create_redirection_rule(
					'Archive Tag to new URL -- ' . $tag->slug,
					'/about/' . $tag->slug . '[/]?',
					'/tag/' . $tag->slug
				);
			}
		}

		WP_CLI::line( "All done! 🙌 Oh, and you'll probably want to run `wp newspack-content-converter reset` next, and run the conversion for these new pages, too." );
	}

	/**
	 * Checks whether the given string contains HTML.
	 *
	 * @param $string
	 *
	 * @return bool
	 */
	private function has_string_html( $string ) {
		$dom_parser = new Dom;
		$dom_parser->loadStr( $string );

		$children_nodes = $dom_parser->getChildren();
		if ( ! $children_nodes ) {
			return false;
		}

		foreach ( $children_nodes as $node ) {
			if ( $node instanceof \PHPHtmlParser\Dom\HtmlNode ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Updates WP's Tag URL schema config, and updates existing rewrite rules too.
	 *
	 * @param string $old_tag_base E.g. 'about/'.
	 * @param string $new_tag_base E.g. 'tag/'.
	 */
	private function update_wp_tag_base_and_existing_rewrite_rules( $old_tag_base, $new_tag_base ) {

		// 1/2 Update the Tag base; if the Tag Base" option is left empty, WP will use the `/tag/{TAG_SLUG}` schema by default, so let's do that!
		update_option( 'tag_base', '' );

		// 2/2 Update existing WP rewrite rules from `/about/{TAG_SLUG}` to `/tag/{TAG_SLUG}`.
		$rewrite_rules         = get_option( 'rewrite_rules' );
		$updated_rewrite_rules = [];

		foreach ( $rewrite_rules as $pattern => $url ) {
			if ( 0 === strpos( $pattern, $old_tag_base ) ) {
				$updated_pattern                           = $new_tag_base . substr( $pattern, strlen( $old_tag_base ) );
				$updated_rewrite_rules[ $updated_pattern ] = $url;
			} else {
				$updated_rewrite_rules[ $pattern ] = $url;
			}
		}

		if ( $rewrite_rules != $updated_rewrite_rules ) {
			update_option( 'rewrite_rules', $updated_rewrite_rules );
		}
	}

	/**
	 * Creates a redirection rule with the johngodley/redirection plugin.
	 *
	 * @param string $title    Title for this redirect rule.
	 * @param string $url_from A regex flavored URL, param such as is used by Red_Item::create().
	 * @param string $url_to   An absolute URL to redirect to.
	 */
	private function create_redirection_rule( $title, $url_from, $url_to ) {
		\Red_Item::create( [
			'action_code' => 301,
			'action_data' => [
				'url' => $url_to,
			],
			'action_type' => 'url',
			'group_id'    => 1,
			'match_data'  => [
				'source' => [
					'flag_case'     => false,
					'flag_query'    => 'exact',
					'flag_regex'    => true,
					'flag_trailing' => false,
				],
			],
			'match_type' => 'url',
			'position' => 1,
			'title' => $title,
			'url' => $url_from,
		] );
	}
}
