<?php

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*
function Keyring_Reddit_Importer() {


class Keyring_Reddit_Importer extends Keyring_Importer_Base {
	const SLUG              = 'reddit';    // e.g. 'twitter' (should match a service in Keyring)
	const LABEL             = 'Reddit';    // e.g. 'Twitter'
	const KEYRING_SERVICE   = 'Keyring_Service_Reddit';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?

	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your statuses into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all statuses." ) );

		if ( isset( $_POST['auto_import'] ) )
			$_POST['auto_import'] = true;
		else
			$_POST['auto_import'] = false;

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'options';
		} else {
			$this->set_option( array(
				'category'    => (int) $_POST['category'],
				'tags'        => explode( ',', $_POST['tags'] ),
				'author'      => (int) $_POST['author'],
				'auto_import' => $_POST['auto_import'],
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		$url = "https://oauth.reddit.com/user/cfinke/overview.json";

		if ( $this->auto_import ) {
			// Get most recent checkin we've imported (if any), and its date so that we can get new ones since then
			$latest = get_posts( array(
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'tax_query'   => array( array(
					'taxonomy' => 'keyring_services',
					'field'    => 'slug',
					'terms'    => array( $this->taxonomy->slug ),
					'operator' => 'IN',
				) ),
			) );
			
			// If we have already imported some, then start since the most recent
			if ( $latest ) {
				$raw_data = get_post_meta( $latest[0]->ID, 'raw_import_data', true );
				$url = add_query_arg( 'before', $raw_data->data->name, $url );
			}
		} else {
			if ( $this->get_option( 'after' ) ) {
				$url = add_query_arg( 'after', $this->get_option( 'after' ), $url );
				$url = add_query_arg( 'count', 0, $url );
			}
		}
		
		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-reddit-importer-failed-download', __( 'Failed to download your activity from Reddit. Please wait a few minutes and try again.' ) );
		}

		// Make sure we have some statuses to parse
		if ( ! is_object( $importdata ) || ! isset( $importdata->data ) || empty( $importdata->data->children ) ) {
			$this->finished = true;
			$this->set_option( 'after', null );
			return;
		}
		
		foreach ( $importdata->data->children as $post ) {
			switch ( $post->kind ) {
				case 't1':
					$post_title = sprintf( __( 'Commented on %s' ), $post->data->link_title );
					$post_content = html_entity_decode( $post->data->body_html );
					$reddit_permalink = 'http://reddit.com/r/' . $post->data->subreddit . '/' . array_pop( explode( '_', $post->data->link_id ) ) . '/';
				break;
				case 't3':
					$post_title = $post->data->title;
					$post_content = '<p><a href="' . esc_url( $post->data->url ) . '">' . esc_html( $post->data->title ) . '</a></p>';
					$reddit_permalink = 'http://reddit.com' . $post->data->permalink;
				break;
			}

			// Parse/adjust dates
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $post->data->created_utc );
			$post_date = get_date_from_gmt( $post_date_gmt );

			$tags = $this->get_option( 'tags' );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Other bits
			$post_author = $this->get_option( 'author' );
			$post_status = 'publish';

			$reddit_id = $post->data->id;
			$reddit_raw = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'reddit_id',
				'reddit_permalink',
				'tags',
				'reddit_raw'
			);
			
			$this->set_option( 'after', $post->data->name );
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			// See the end of extract_posts_from_data() for what is in here
			extract( $post );

			if (
				!$reddit_id
			||
				$wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = 'reddit_id' AND meta_value = %s", $reddit_id ) )
			||
				$post_id = post_exists( $post_title, $post_content, $post_date )
			) {
				// Looks like a duplicate
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );

				if ( is_wp_error( $post_id ) )
					return $post_id;

				if ( !$post_id )
					continue;

				$post['ID'] = $post_id;

				// Track which Keyring service was used
				wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

				// Update Category
				wp_set_post_categories( $post_id, $post_category );

				add_post_meta( $post_id, 'reddit_id', $reddit_id );

				if ( count( $tags ) )
					wp_set_post_terms( $post_id, implode( ',', $tags ) );

				add_post_meta( $post_id, 'raw_import_data', $reddit_raw );
				add_post_meta( $post_id, 'reddit_permalink', $reddit_permalink );

				$imported++;

				do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
			}
		}
		
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} // end function Keyring_Reddit_Importer


add_action( 'init', function() {
	Keyring_Reddit_Importer(); // Load the class code from above
	keyring_register_importer(
		'reddit',
		'Keyring_Reddit_Importer',
		plugin_basename( __FILE__ ),
		__( 'Download all of your Reddit comments and submissions.', 'keyring' )
	);
} );
