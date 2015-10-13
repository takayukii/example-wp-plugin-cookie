<?php
/*
Plugin Name: Cookie-sso
Version: 0.1-alpha
Description: PLUGIN DESCRIPTION HERE
Author: YOUR NAME HERE
Author URI: YOUR SITE HERE
Plugin URI: PLUGIN SITE HERE
Text Domain: cookie-sso
Domain Path: /languages
*/

/**
 * Setup CodeSniffer
 * $ composer global require 'squizlabs/php_codesniffer=*'
 * $ git clone https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards.git ~/.composer/vendor/squizlabs/php_codesniffer/CodeSniffer/Standards/WordPress
 * $ phpcs --config-set installed_paths ~/.composer/vendor/squizlabs/php_codesniffer/CodeSniffer/Standards/WordPress
 *
 * Execute CodeSniffer
 * $ phpcs -p -s -v --standard=WordPress-Core cookie-sso.php
 * $ phpcbf -p -s -v --standard=WordPress-Core cookie-sso.php
 */

require_once 'vendor/autoload.php';

use GuzzleHttp\Client;

function check_sso_cookie() {

	$isLocalLoggedIn = ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] );

	if ( $isLocalLoggedIn ) {
		return;
	}

	$ssoid = $_COOKIE['SSOSID'];
	$hasNoSsoId = empty( $ssoid );

	if ( $hasNoSsoId ) {
		return;
	}

	$client = new GuzzleHttp\Client( [ 'base_uri' => 'http://platform.example.com/' ] );

	try {

		$res = $client->request('GET', 'users/auth-check?level=2', [
			'headers' => [
				'Cookie' => "REALSSID=$ssoid",
			],
		]);
		$auth = json_decode( $res->getBody(), true );

		$isAuthSuccess = ! empty( $auth['auth'] );

		if ( ! $isAuthSuccess ) {
			throw new Exception( 'no auth' );
		}

		$cookies = $res->getHeader( 'Set-Cookie' );
		$newSsoId = '';
		foreach ( $cookies as $cookie ) {
			if ( preg_match( '/.*SSOSID.*/', $cookie ) ) {
				$parts = preg_split( '/;/', $cookie );
				$newSsoId = trim( preg_split( '/=/', $parts[0] )[1] );
			}
		}

		setcookie( 'SSOSID', $newSsoId, 0, '/', '.example.com' );
		setcookie( 'REALSSID', $newSsoId, 0, '/', '.example.com' );

		// ここでまずユーザーを作成 or 更新した上でログイン出来るか試してみる
		$login = $auth['auth']['id'];
		$person = [
			'user_login' => $login,
			'user_pass' => 'test1',
			'first_name' => 'f1',
			'last_name' => 'l1',
			'display_name' => $login,
		];

		if ( $id = username_exists( $login ) ) {
			$person['ID'] = $id;
			wp_update_user( $person );
		} else {
			wp_insert_user( $person );
		}

		$auth = wp_signon([
			'user_login' => $login,
			'user_password' => 'test1',
			'remember' => false,
		], false);

		if ( ! is_wp_error( $auth ) ) {
			wp_redirect( $_SERVER['REQUEST_URI'] );
			exit;
		}
	} catch (Exception $e) {

		error_log( $e );

	}
}

if ( 'wp-login.php' !== $pagenow && 'wp-register.php' !== $pagenow ) { add_action( 'template_redirect', 'check_sso_cookie' ); }

?>
