<?php

namespace NewspackCustomContentMigrator\Command\PublisherSpecific;

use \NewspackCustomContentMigrator\Command\InterfaceCommand;
use \Newspack_WXR_Exporter;
use \PDO, \PDOException;
use \stdClass;
use \WP_CLI;

/**
 * Custom migration scripts for Latin Finance.
 */
class LatinFinanceMigrator implements InterfaceCommand {

	private $site_title  = 'LatinFinance';
	private $site_url    = 'https://www.latinfinance.com';
	private $export_path = \WP_CONTENT_DIR;

	private $pdo = null;
	private $authors = array();
	private $tags = array();
	private $custom_tag_slugs = array( 'daily-briefs', 'free-content', 'magazine', 'web-articles' );
	private $post_slugs = array();


	/**
	 * @var null|InterfaceCommand Instance.
	 */
	private static $instance = null;

	/**
	 * Constructor.
	 */
	private function __construct() {}

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
			'newspack-content-migrator latinfinance-import-from-mssql',
			[ $this, 'cmd_import_from_mssql' ],
			[
				'shortdesc' => 'Imports content from MS SQL DB as posts.',
				'synopsis'  => [],
			]
		);
	}

	/**
	 * Callable for 'newspack-content-migrator latinfinance-import-from-mssql'.
	 * 
	 * @param array $pos_args   WP CLI command positional arguments.
	 * @param array $assoc_args WP CLI command positional arguments.
	 */
	public function cmd_import_from_mssql( $pos_args, $assoc_args ) {
		
		WP_CLI::line( "Doing latinfinance-import-from-mssql..." );
		
		// Setup MSSQL DB connection
		$this->set_pdo();

		// Load all Authors and Tags (we'll convert to Categories) from the MSSQL DB
		$this->set_authors();
		$this->set_tags();
				
		// Setup query vars for MSSQL DB for content types
		$limit = 1000; // row limit per batch
		$start_id = 1; // 1; rows greater than or equal to this ID value

		// Export posts while return value isn't null
		// ...and set the new start_id equal to the returned id (last id processed) plus 1
		while( null !== ( $start_id = $this->export_posts( $limit, $start_id ) ) ) {
			$start_id += 1;
		}
		
		exit();

		// Check for duplicate post slugs
		foreach ( $this->post_slugs as $slug => $urls ) {
			if( count( $urls ) > 1 ) {
				WP_CLI::warning( 'Duplicate content "' . $slug .'" for urls: ' . print_r( $urls, true ) );
			}
		}

		// todo: handle duplicate emails
		// $this->check_author_emails();
		// $this->log_to_csv( $this->authors, $this->export_path  . '/latinfinance-authors.csv');

		// $this->set_tags_parent_slugs(); // only for CSV output
		// todo: handle duplicate child categories names across different parents
		// todo: do we need to import Descriptions from some categories? don't worry!! they can recreate by hand, 
		// todo: test for $this->custom_tag_slugs
		// $this->check_tags_slugs();
		// $this->log_to_csv( $this->tags, $this->export_path  . '/latinfinance-tags.csv');

		// Append neccessary Categories to WXR <channel>
		$data = [
			'site_title'  => $this->site_title,
			'site_url'    => $this->site_url,
			'export_file' => $this->export_path  . '/latinfinance-categories.xml',
			'posts'       => [],
			'terms'       => $this->get_tags_as_terms(),
		];
		// if( ! empty( $terms ) ) $data['terms'] = $terms;
		Newspack_WXR_Exporter::generate_export( $data );
		WP_CLI::success( sprintf( "\n" . 'Categories exported to file %s ...', $data[ 'export_file' ] ) );

	}


	/**
	 * Exports
	 * 
	 */


	// returns null or the last id (integer) of nodeId that was processed
	private function export_posts( $limit, $start_id ) {

		// Setup data array WXR for post content
		$data = [
			'site_title'  => $this->site_title,
			'site_url'    => $this->site_url,
			'export_file' => $this->export_path  . '/latinfinance-posts-' . $start_id . '.xml',
			'posts'       => [],
		];

		// Get published posts for the content types
		$result = $this->pdo->prepare("
			SELECT TOP " . intval( $limit ) . "
				cmsDocument.nodeId, cmsDocument.versionId, cmsDocument.expireDate,
				cmsContentXML.xml
			FROM cmsDocument
			JOIN cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			JOIN cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			JOIN cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias in('dailyBriefArticle', 'magazineArticle', 'webArticle')
			WHERE cmsDocument.published = 1	
			AND cmsDocument.nodeId >= " . intval( $start_id ) . "
			ORDER BY cmsDocument.nodeId
		");
		$result->execute();

		// keep track of last row processed
		$last_id = null;

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			// load xml column
			$xml = simplexml_load_string( $row['xml'] );
			
			// get authors for this post
			$authors = $this->get_authors_and_increment( (string) $xml->authors );

			// set slug and old site url
			$slug = (string) $xml['urlName'];
			$url_from_path = $this->get_url_from_path( (string) $xml['path'] );

			// Track dublicate content slugs
			if( isset( $this->post_slugs[$slug] ) ) {
				$this->post_slugs[$slug][] = $url_from_path;
			}
			else {
				$this->post_slugs[$slug] = array( $url_from_path );
			}
			
			// Test expireDate
			if( null !== $row['expireDate'] ) {
				WP_CLI::warning( 'Post expireDate exists "' . $row['expireDate'] .'" for node ' . $row['nodeId']);
			}

			// Add values to a single post array
			$post = [

				'title'   => (string) $xml['nodeName'],
				'url'    => $slug,
				'content' => (string) $xml->body,
				'excerpt' => (string) $xml->snippet,
				'categories' => $this->get_cats_and_increment( (string) $xml->tags ),

				// WXR <wp:author><wp:author_login> will create user accounts
				// but <item><dc:creator> doesn't support multiple authors so no point in creating user accounts...
				// do this post migration using: postmeta.newspack_lf_author
				// 'author'  => $authors['basic']

				// just use one date value, ignore createDate
				'date'    => (string) $xml->displayDate,

				// Convert to tags: <metaKeywords><![CDATA[Arcos Dorados, McDonald's, Argentina]]></metaKeywords>
				'tags' => preg_split( '/,\s*/', trim( (string) $xml->metaKeywords ), -1, PREG_SPLIT_NO_EMPTY ),

				'meta'    => [

					// these will be converted to Guest Authors in CoAuthorsPlus
					'newspack_lf_author' => json_encode( $authors['full'] ),

					// this will be used for yoast primary category
					'newspack_lf_original_type' => (string) $xml['nodeTypeAlias'],

					// helpful to catch changes in future imports
					'newspack_lf_original_id' => (string) $row['nodeId'],
					'newspack_lf_original_version' => (string) $row['versionId'],
					'newspack_lf_checksum' => md5( serialize( $row ) ),

					// helpful for redirects if needed
					'newspack_lf_original_url' => $url_from_path,

				],
							
			]; // post

			// Add "content type" category
			switch( (string) $xml['nodeTypeAlias'] ) {
				case 'dailyBriefArticle': $post['categories'][] = 'Daily Briefs'; break;
				case 'magazineArticle': $post['categories'][] = 'Magazine'; break;
				case 'webArticle': $post['categories'][] = 'Web Articles'; break;
			}
			
			// Add additional category
			if ( (int) $xml->isFree === 1 ) {
				$post['categories'][] = 'Free Content';
			}

			// Featured image: <image><![CDATA[64047]]></image>
			if( ! empty ( $xml->image ) ) {

				// must be single integer
				if( ! preg_match('/^[0-9]+$/', (string) $xml->image ) ) {
					WP_CLI::error( 'Featured image is not single integer ' . (string) $xml->image .' for node ' . $row['nodeId']);
				}
				
				$featured_image = $this->get_featured_image( (string) $xml->image );

				if( null !== $featured_image ) {

					$post['featured_image'] = $featured_image['url'];
					$post['meta']['newspack_lf_featured_image'] = json_encode( $featured_image );
					$post['meta']['newspack_lf_featured_image_checksum'] = md5( serialize( $featured_image ) );
				
				} // null featured image

			} // xml->image

			// todo: body: <img
			
			// Append to data posts
			$data['posts'][] = $post;

			// increment last id processed
			$last_id = (int) $row['nodeId'];

		} // while content

		// return from function at this point if no rows were processed
		// todo: set a return line above the while loop if PDO->rowCount() could return a consistant "0 results" row count...
		if( $last_id === null ) return null;

		// $this->log_to_dump( $data['posts'], $this->export_path  . '/latinfinance-posts.txt'); exit();
		
		// Create WXR file
		// Newspack_WXR_Exporter::generate_export( $data );
		WP_CLI::success( sprintf( "\n" . 'Posts exported to file %s ...', $data[ 'export_file' ] ) );

		return $last_id;

	}


	/**
	 * Checks
	 *
	 */
	
	private function check_author_emails() {

		$emails = array();

		foreach( $this->authors as $id => $node ) {
			
			// must have post content
			if( $node['post_count'] === 0 ) continue;

			// email to test
			$email = $node['email'];

			if( empty($email) ) continue;

			// test if already exists (can't have duplicate email addresses)
			if( isset( $emails[$email] ) ) {
				$emails[$email]++;
				WP_CLI::warning( 'Duplicate email "' . $email .'" for node: ' . print_r( $node , true) );				
			}
			else {
				$emails[$email] = 1;
			}
		
		}
	}

	private function check_tags_slugs() {

		$slugs = array();

		foreach( $this->tags as $id => $node ) {
			
			// must have post content
			if( $node['post_count'] === 0 ) continue;

			// slug to test
			$slug = $node['slug'];

			// test if already exists (can't have duplicate tag ("category") slugs)
			if( isset( $slugs[$slug] ) ) {
				$slugs[$slug]++;
				WP_CLI::warning( 'Duplicate tag (category) "' . $slug .'" for node: ' . print_r( $node , true) );				
			}
			else {
				$slugs[$slug] = 1;
			}
		
		}
	}

	/**
	 * Getters
	 *
	 */

	// null
	// single id
	// id,id,id
	private function get_authors_and_increment( $node ) {

		$basic = array();
		$full = array();

		if( ! empty( $node ) ) {

			$ids = explode(',', $node );
			foreach( $ids as $id ) {

				// if the author doesn't exist, we can't do anything...just continue
				if( empty( $this->authors[$id] ) ) continue;

				// only add matching key/values to each output
				$basic[] = array_intersect_key( $this->authors[$id], array( 'name' => 1, 'email' => 1) );
				$full[] = array_intersect_key( $this->authors[$id], array( 'id' => 1, 'name' => 1, 'email' => 1, 'slug' => 1) );
				$this->authors[$id]['post_count']++;

			}

		} // not empty

		return [
			'basic' => $basic,
			'full' => $full,
		];

	}

	// null
	// single id
	// id,id,id
	private function get_cats_and_increment( $node ) {

		if( empty( $node ) ) return array();
		$ids = explode(',', $node );
		
		$out = array();
		foreach( $ids as $id ) {
			// only add matching key/values to each output
			$out[] = array_intersect_key( $this->tags[$id], array( 'name' => 1, 'slug' => 1) );
			$this->tags[$id]['post_count']++;
		}

		return $out;

	}

	/*
		<Image id="1333" key="d0c96cf0-bb9d-44aa-8122-bd1fee73617c" parentID="1156" level="3" creatorID="0" sortOrder="3" createDate="2017-09-05T13:30:33" updateDate="2018-11-13T15:00:15" nodeName="2013Oscars_Hagenbuch_Academy.jpg" urlName="2013oscars_hagenbuch_academyjpg" path="-1,53377,1156,1333" isDoc="" nodeType="1032" writerName="bgilbert@w3trends.com" writerID="0" version="cd8c6c5e-fd3d-4968-9b4f-12bd60d91302" template="0" nodeTypeAlias="Image"><umbracoFile><![CDATA[{src: '/media/1004/2013oscars_hagenbuch_academy.jpg', crops: []}]]></umbracoFile><umbracoWidth><![CDATA[1826]]></umbracoWidth><umbracoHeight><![CDATA[1323]]></umbracoHeight><umbracoBytes><![CDATA[190068]]></umbracoBytes><umbracoExtension><![CDATA[jpg]]></umbracoExtension></Image>

		<umbracoFile><![CDATA[{src: '/media/6252/casa-dos-ventos-rio-do-vento.jpg', crops: []}]]>
		https://www.latinfinance.com/media/6252/casa-dos-ventos-rio-do-vento.jpg
		
		<umbracoFile><![CDATA[{src: '/media/6296/iberdrola-mexico-cogeneración-bajío-power-plant.png', crops: []}]]></umbracoFile>
		https://www.latinfinance.com/media/6296/iberdrola-mexico-cogeneraci%C3%B3n-baj%C3%ADo-power-plant.png

		<umbracoFile><![CDATA[{src: '/media/2133/avianca_767-200_at_el_dorado.jpg', crops: []}]]></umbracoFile>
		https://www.latinfinance.com/media/2133/avianca_767-200_at_el_dorado.jpg
	*/
	private function get_featured_image( $node_id ) {

		$result = $this->pdo->prepare("
			SELECT xml
			FROM cmsContentXml
			WHERE nodeId = ?
		");
		$result->execute( array( $node_id ) );
			
		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   
		
			$xml = simplexml_load_string( $row['xml'] );
			
			// umbracoFile is not proper JSON so use preg_match
			preg_match("/CDATA\[{src: '([^']+)'/", $row['xml'], $image_matches);

			return [
				'id' => (string) $xml['id'],
				'name' => (string) $xml['nodeName'],
				'url' => $image_matches[1],
				'xml' => $row['xml'], // for checksum and postmeta
			];
		
		}  

		return null;

	}

	private function get_tags_as_terms() {
		
		$terms = array();
		
		foreach( $this->tags as $id => $tag ) {

			// only create terms if used for a post
			// todo: if( $tag['post_count'] === 0 ) continue;

			$term = new stdClass();
			$term->taxonomy = 'category';
			$term->name = $tag['name'];
			$term->slug = $tag['slug'];
			$term->parent = $tag['parent'];
			
			$terms[ $id ] = $term;

		}

		return $terms;

	}

	// example: "-1,1051,1080,32617,32834,32876,32886" (last element is current element)
	// used by content and tags
	private function get_url_from_path( $path ) {
	
		// remove the -1 and Home path
		$path = preg_replace('/^-1,1051,/', '', $path);

		$nodes = explode(',', $path );

		$result = $this->pdo->prepare("
			SELECT
			CAST(cmsContentXml.xml as xml).value('(/*/@urlName)[1]', 'varchar(max)') as urlName,
			CAST(cmsContentXml.xml as xml).value('(/*/@level)[1]', 'int') as level
			FROM cmsContentXml
			WHERE nodeId in(" . implode(',', array_fill(0, count($nodes), '?')) . ")
			ORDER by level
		");
		$result->execute( $nodes );

		$url = '';
		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   
			$url .= '/' . $row['urlName'];
		}  

		return $url;

	}


	/**
	 * Logging
	 *
	 */

	 private function log_to_csv( $data, $path ) {
		$file = fopen($path, 'w');
		
		$header = array_keys(reset($data));
		fputcsv($file, $header);

		foreach ($data as $row) {
			fputcsv($file, $row);
		}

		fclose($file);
	}

	private function log_to_dump( $data, $path ) {
		ob_start();
		var_dump( $data );
		file_put_contents( $path, ob_get_clean() );
	}


	/**
	 * Setters
	 *
	 */

	private function set_authors() {

		$result = $this->pdo->prepare("
			select cmsDocument.nodeId, cmsContentXML.xml
			from cmsDocument
			join cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			join cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			join cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias = 'author'
			where cmsDocument.published = 1	
		");
		$result->execute();

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   
			
			$xml = simplexml_load_string( $row['xml'] );
			
			$this->authors[ (string) $row['nodeId'] ] = [
				'id' => (string) $row['nodeId'],
				'name' => (string) $xml['nodeName'],
				'slug' => (string) $xml['urlName'],
				'email' => (string) $xml->email,
				'post_count' => 0,
			];

		}  

	}

	// Use PDO to create a connection to the DB.
	// php requires: php.ini => extension=pdo_sqlsrv
	// client requires: IP Address whitelisted
	// todo: fix collation/character sets
	// plan on my local
	private function set_pdo() {

		try {  
			$this->pdo = new PDO( "sqlsrv:Server=;Database=LatinFinanceUmbraco", NULL, NULL);   
			$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );  
		}  
		catch( PDOException $e ) {  
			WP_CLI::error( 'SQL Server error ' . $e->getCode() . ': ' . $e->getMessage() );
		}  

	}

	// Set tags (convert to Categories under a parent category named 'Topics')
	// result will look like:
	//		Topics
	//			-> Bonds
	//		DailyBriefs
	//		etc
	private function set_tags( ) {

		// Order by level to assure WXR importing will create parent before child
		$result = $this->pdo->prepare("
			select cmsDocument.nodeId, cmsContentXML.xml,
				CAST(cmsContentXML.xml as xml).value('(/*/@level)[1]', 'int') as level
			from cmsDocument
			join cmsContentXML on cmsContentXML.nodeId = cmsDocument.nodeId
			join cmsContent on cmsContent.nodeId = cmsDocument.nodeId
			join cmsContentType on cmsContentType.nodeId = cmsContent.contentType
				and cmsContentType.alias in('tag','tags')
			where cmsDocument.published = 1	
			order by level
		");
		$result->execute();

		while ( $row = $result->fetch( PDO::FETCH_ASSOC ) ){   

			$xml = simplexml_load_string( $row['xml'] );
			
			$this->tags[ (string) $row['nodeId'] ] = [
				'id' => (string) $row['nodeId'],
				'name' => (string) $xml['nodeName'],
				'slug' => (string) $xml['urlName'],
				'post_count' => 0,
				'parent' => (string) $xml['parentID'],
				'parent_slug' => '',
				'url' => $this->get_url_from_path( (string) $xml['path'] ),
				'description' => (string) $xml->sidebarWidget,
			];

		}  

	}

	private function set_tags_parent_slugs() {

		foreach( $this->tags as $id => $node ) {
			
			$parent = $node['parent'];

			// special case for top level category where parent is "1051/Home"
			if( $parent == '1051' ) {
				$this->tags[$id]['parent_slug'] = '';
				continue;	
			}

			// if parent id doesn't match a node
			if ( !isset( $this->tags[$parent] ) ) {
				WP_CLI::error( 'Tag parent not found for node: ' . print_r( $this->tags[$id] , true) );				
			}

			// set node's parent slug from parent node's slug
			$this->tags[$id]['parent_slug'] = $this->tags[$parent]['slug'];
			
		}

	}

}
