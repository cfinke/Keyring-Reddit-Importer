<?php

/*
Plugin Name: Reddit for Keyring
Description: Adds a Reddit service and importer to Keyring and Keyring Social Importers
Version: 1.0
Author: Christopher Finke
Author URI: http://www.chrisfinke.com/
License: GPL2
Depends: Keyring, Keyring Social Importers
*/

function keyring_reddit_enable_service( $services ) {
	$services[] = plugin_dir_path( __FILE__ ) . 'keyring-reddit/keyring-reddit-service.php';
	
	return $services;
}

add_filter( 'keyring_services', 'keyring_reddit_enable_service' );

function keyring_reddit_enable_importer( $importers ) {
	$importers[] = plugin_dir_path( __FILE__ ) . 'keyring-reddit/keyring-reddit-importer.php';
	
	return $importers;
}

add_filter( 'keyring_importers', 'keyring_reddit_enable_importer' );