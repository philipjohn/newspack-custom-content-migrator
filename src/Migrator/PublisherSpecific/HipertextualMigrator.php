<?php

namespace NewspackCustomContentMigrator\Migrator\PublisherSpecific;

use \WP_CLI;
use \NewspackCustomContentMigrator\Migrator\InterfaceMigrator;
use \NewspackCustomContentMigrator\MigrationLogic\Attachments as AttachmentsLogic;

/**
 * Custom migration scripts for Hipertextual.
 */
class HipertextualMigrator implements InterfaceMigrator {

	/**
	 * @var null|InterfaceMigrator Instance.
	 */
	private static $instance = null;

	/**
	 * @var AttachmentsLogic
	 */
	private $attachments_logic;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->attachments_logic = new AttachmentsLogic();
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
		// Bit of a hack doing it this way but ¯\_(ツ)_/¯.

		// Convert Markdown headings.
		add_filter( 'np_meta_to_content_value', [ $this, 'convert_markdown_headings' ], 10, 3 );

		// Add missing headings to some sections.
		add_filter( 'np_meta_to_content_value', [ $this, 'add_section_headings' ], 10, 3 );
	}

	/**
	 * Convert markdown headings
	 *
	 * @return string Converted content
	 */
	public function convert_markdown_headings( $value, $key, $post_id ) {

		// Look for markdown headings, skip if there aren't any.
		$find = \preg_match_all( '/(#+\S+.+)\n/', $value, $matches );
		if ( ! $find || 0 === $find ) {
			return $value;
		}

		// Markdown to look for and HTML tags to replace them with.
		$replacements = [
			'#####' => 'h5',
			'####'  => 'h4',
			'###'   => 'h3',
			'##'    => 'h2',
			'#'     => 'h1',
		];

		foreach ( $matches[1] as $match ) {

			// Loop through each markdown replacement needed.
			foreach( $replacements as $search => $replace ) {
				if ( false !== \strpos( $match, $search ) ) {
					// Remove the markdown from the heading, leaving just the text.
					$title = \str_replace( $search, '', $match );

					// Wrap the heading with the relevant HTML tags.
					$title = sprintf( '<%1$s>%2$s</%1$s>', $replace, $title );

					// Replace the original string in the meta value with our new HTML string.
					$value = str_replace( $match, $title, $value );
				}
			}

		}

		return $value;
	}

	/**
	 * Add section headings to some sections.
	 *
	 * @return string Converted content.
	 */
	public function add_section_headings( $value, $key, $post_id ) {

		// Things
		$headings = [
			'conclusion' => '<h2>Conclusión</h2>',
			'pros'       => '<h2 class="pros">Pros</h2>',
			'contras'    => '<h2 class="contras">Contras</h2>'
		];

		if ( ! in_array( $key, array_keys( $headings ) ) ) {
			return $value;
		}

		foreach ( $headings as $meta_key => $heading ) {
			if ( $meta_key === $key ) {
				// Append the relevant heading on to the content.
				$value = $heading . $value;
			}
		}

		return $value;

	}

}
