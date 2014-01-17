<?php

/**
 * Reddit service definition for Keyring.
 * http://www.reddit.com/dev/api/
 */

class Keyring_Service_Reddit extends Keyring_Service_OAuth2 {
	const NAME  = 'reddit';
	const LABEL = 'Reddit';

	function __construct() {
		parent::__construct();

		// Enable "basic" UI for entering key/secret
		if ( ! KEYRING__HEADLESS_MODE ) {
			add_action( 'keyring_reddit_manage_ui', array( $this, 'basic_ui' ) );
			add_filter( 'keyring_reddit_basic_ui_intro', array( $this, 'basic_ui_intro' ) );
		}

		$creds = $this->get_credentials();
		$this->app_id  = $creds['app_id'];
		$this->key     = $creds['key'];
		$this->secret  = $creds['secret'];

		$this->set_endpoint( 'authorize',    'https://ssl.reddit.com/api/v1/authorize', 'GET' );
		$this->set_endpoint( 'access_token', 'https://' . $this->key . ':' . $this->secret . '@ssl.reddit.com/api/v1/access_token', 'POST' );
		$this->set_endpoint( 'self',         'https://oauth.reddit.com/api/v1/me.json',   'GET' );

		$this->authorization_header = 'Bearer';
		
		$this->redirect_uri = Keyring_Util::admin_url( self::NAME, array( 'action' => 'verify' ) );

		$this->requires_token( true );

		add_filter( 'keyring_reddit_request_token_params', array( $this, 'filter_request_token' ) );
		add_filter( 'keyring_reddit_verify_token_params', array( $this, 'filter_verify_token' ) );
		add_action( "pre_keyring_reddit_verify", array( $this, 'pre_verify' ) );
	}

	function basic_ui_intro() {
		echo '<p>' . sprintf( __( "To get started, <a href='https://ssl.reddit.com/prefs/apps'>register an OAuth client on Reddit</a>. The most important setting is the <strong>redirect uri</strong>, which should be set to <code>%s</code>. You can set the other values to whatever you like.", 'keyring' ), Keyring_Util::admin_url( 'reddit', array( 'action' => 'verify' ) ) ) . '</p>';
		echo '<p>' . __( "Once you've saved those changes, copy the <strong>API key</strong> value into the <strong>API Key</strong> field, and the <strong>CLIENT SECRET</strong> value into the <strong>API Secret</strong> field and click save.", 'keyring' ) . '</p>';
	}

	function get_display( Keyring_Access_Token $token ) {
		return $token->get_meta( 'username' );
	}
	
	/**
	 * Add scope to the outbound URL, and allow developers to modify it
	 * @param  array $params Core request parameters
	 * @return Array containing originals, plus the scope parameter
	 */
	function filter_request_token( $params ) {
		$params['scope'] = 'identity,history';
		$params['duration'] = 'permanent';

		if ( $scope = implode( ',', apply_filters( 'keyring_reddit_scope', array() ) ) )
			$params['scope'] = $scope;

		// Can't include nonces in the redirect URI because it has to match what we listed in the app.
		$params['redirect_uri'] = remove_query_arg( array( 'kr_nonce', 'nonce' ), $params['redirect_uri'] );

		return $params;
	}
	
	function filter_verify_token( $params ) {
		$params['redirect_uri'] = remove_query_arg( array( 'kr_nonce', 'nonce' ), $params['redirect_uri'] );

		return $params;
	}
	
	function pre_verify( $request ) {
		// This offers no security, but it does make it work.
		$_REQUEST['kr_nonce'] = wp_create_nonce( 'keyring-verify' );
		$_REQUEST['nonce'] = wp_create_nonce( 'keyring-verify-' . $this->get_name() );
	}
	
	static function fix_redirect_uri() {
		// URI coming back from Reddit is borked, using too many question marks
		// e.g., http://example.com/foo.php?service=reddit&action=verify?state=[...]
		if ( strpos( $_SERVER['QUERY_STRING'], "service=reddit&action=verify?state" ) !== false ) {
			wp_safe_redirect( "?" . str_replace( "verify?state", "verify&state", $_SERVER['QUERY_STRING'] ) );
			die;
		}
	}
	
	function build_token_meta( $token ) {
		$this->set_token(
			new Keyring_Access_Token(
				$this->get_name(),
				$token['access_token'],
				array()
			)
		);
		
		$response = $this->request( $this->self_url, array( 'method' => $this->self_method ) );
		
		if ( Keyring_Util::is_error( $response ) ) {
			$meta = array();
		} else {
			$meta = array(
				'username' => $response->name,
				'user_id'  => $response->id,
			);
		}

		return apply_filters( 'keyring_access_token_meta', $meta, 'reddit', $token, $response, $this );
	}
}

add_action( 'keyring_load_services', array( 'Keyring_Service_Reddit', 'init' ) );
add_action( 'admin_init', array( 'Keyring_Service_Reddit', 'fix_redirect_uri' ) );