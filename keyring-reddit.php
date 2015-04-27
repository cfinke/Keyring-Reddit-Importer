<?php

/*
Plugin Name: Reddit for Keyring
Description: Adds a Reddit service and importer to Keyring and Keyring Social Importers
Version: 1.1
Author: Christopher Finke
Author URI: http://www.chrisfinke.com/
License: GPL2
Depends: Keyring, Keyring Social Importers
*/

require( plugin_dir_path( __FILE__ ) . 'keyring-reddit/keyring-reddit-importer.php' );

function keyring_reddit_enable_service( $services ) {
	$services[] = plugin_dir_path( __FILE__ ) . 'keyring-reddit/keyring-reddit-service.php';
	
	return $services;
}

add_filter( 'keyring_services', 'keyring_reddit_enable_service' );
