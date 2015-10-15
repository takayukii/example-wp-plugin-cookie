<?php
/*
Plugin Name: COOKIE-SSO
Version: 0.1-alpha
Description: This is sample plugin for Single Sign On using cookie between wordpress and other site
*/

/**
 * CodeSnifferのセットアップ
 * $ composer global require 'squizlabs/php_codesniffer=*'
 * $ git clone https://github.com/WordPress-Coding-Standards/WordPress-Coding-Standards.git ~/.composer/vendor/squizlabs/php_codesniffer/CodeSniffer/Standards/WordPress
 * $ phpcs --config-set installed_paths ~/.composer/vendor/squizlabs/php_codesniffer/CodeSniffer/Standards/WordPress
 *
 * CodeSnifferの実行
 * Validate
 * $ phpcs -p -s -v --standard=WordPress-Core cookie-sso.php
 *
 * CodeSnifferによる自動修正
 * $ phpcbf -p -s -v --standard=WordPress-Core cookie-sso.php
 */

require_once 'vendor/autoload.php';

use GuzzleHttp\Client;

class Cookie_Sso_Class {

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		global $pagenow;
		if ( 'wp-login.php' !== $pagenow && 'wp-register.php' !== $pagenow ) {
			add_action( 'template_redirect', [ $this, 'auth_with_sso_id' ] );
		}
		add_action( 'admin_menu', [ $this, 'create_admin_menu' ] );
	}

	/**
	 * 管理画面メニュー生成
	 */
	function create_admin_menu() {
		add_options_page(
			'COOKIE-SSO', // title属性
			'COOKIE-SSO', // 管理画面メニュー
			'manage_options', // 権限
			'cookie-sso-options', // スラッグ出力例 http://wp.example.com/wp-admin/options-general.php?page=cookie-sso-options
			[ &$this, 'my_option_page' ] // メソッド
		);
		add_action( 'admin_init', [ $this, 'my_admin_init' ] );
	}

	/**
	 * 管理画面表示
	 */
	function my_option_page() {
		?>
		<div class="wrap">
			<h2>COOKIE-SSO 設定画面</h2>
			<form id="cookie-sso-form" method="post" action="">
				<?php
				// nonceは12時間毎に変化する
				wp_nonce_field( 'cookie-sso-nonce-key', 'cookie-sso-nonce-name' );
				// nonceの出力例
				// <input type="hidden" id="cookie-sso-nonce-name" name="cookie-sso-nonce-name" value="3f86788d8e">
				// <input type="hidden" name="_wp_http_referer" value="/wp-admin/options-general.php?page=cookie-sso-options">
				?>
				<h3>プラットフォーム設定</h3>
				<table class="form-table">
					<tr valign="top">
						<th scope="row"><label>ホストURL</label></th>
						<td>
							<input type="text" name="host-url" value="<?php echo esc_attr( get_option( 'host-url' ) );?>" />
							<p class="description">e.g. http://platform.example.com</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><label>ログイン画面の相対パス</label></th>
						<td>
							<input type="text" name="login" value="<?php echo esc_attr( get_option( 'login' ) );?>" />
							<p class="description">e.g. /users/login?level=2</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row"><label>認証チェックAPIの相対パス</label></th>
						<td>
							<input type="text" name="auth-api" value="<?php echo esc_attr( get_option( 'auth-api' ) );?>" />
							<p class="description">e.g. /users/auth-check?level=1</p>
						</td>
					</tr>
				</table>
				<p><input type="submit" value="保存" class="button button-primary button-large" /></p>
			</form>
		</div>
		<?php
	}

	/**
	 * 保存時の処理
	 */
	function my_admin_init() {

		if ( ! isset( $_POST['cookie-sso-nonce-name'] ) || ! $_POST['cookie-sso-nonce-name'] ) {
			return;
		}
		if ( ! check_admin_referer( 'cookie-sso-nonce-key', 'cookie-sso-nonce-name' ) ) {
			return;
		}

		$this->update_option_with_postdata( 'host-url' );
		$this->update_option_with_postdata( 'login' );
		$this->update_option_with_postdata( 'auth-api' );

	}

	/**
	 * WPのオプションを更新する
	 * @param $key
	 */
	function update_option_with_postdata( $key ) {
		$value = '';
		if ( isset( $_POST[ $key ] ) && $_POST[ $key ] ) {
			$value = $_POST[ $key ];
		}
		update_option( $key, $value );
	}

	function check_if_option_completed() {
		return ! empty( get_option( 'host-url' ) ) && ! empty( get_option( 'login' ) ) && ! empty( get_option( 'auth-api' ) );
	}

	/**
	 * 認証状態を取得しWPにログインする（ページ遷移時に都度実行される）
	 */
	function auth_with_sso_id() {

		// 管理者以外のユーザーの場合はツールバーを非表示にする
		if ( ! current_user_can( 'manage_options' ) ) {
			add_filter( 'show_admin_bar', '__return_false' );
		}

		$is_option_completed = $this->check_if_option_completed();
		if ( ! $is_option_completed ) {
			return;
		}

		$is_wp_local_loggedin = ! empty( $_COOKIE[ LOGGED_IN_COOKIE ] );

		if ( $is_wp_local_loggedin ) {
			return;
		}

		$ssoid = $_COOKIE['SSOSID'];
		$has_no_ssoId = empty( $ssoid );

		if ( $has_no_ssoId ) {
			return;
		}

		$auth = $this->get_remote_auth_check( $ssoid );
		$is_auth_success = ! empty( $auth ) && ! empty( $auth['auth'] );

		if ( ! $is_auth_success ) {
			// 仮にレベル1よりも上位の認証を求める際はログイン画面へリダイレクトする（実装例）
			wp_redirect( get_option( 'host-url' ).get_option( 'login' ).'&redirect='.get_site_url().$_SERVER['REQUEST_URI'] );
			exit;
		}

		$is_wp_login_success = $this->create_user_and_signin( $auth );
		if ( $is_wp_login_success ) {
			// ログイン後のクッキーを読み直し表示する
			wp_redirect( $_SERVER['REQUEST_URI'] );
			exit;
		}

	}

	/**
	 * 認証状態を取得する
	 * @param $ssoid
	 * @return array - auth
	 */
	function get_remote_auth_check( $ssoid ) {

		$auth = null;
		try {

			$client = new GuzzleHttp\Client( [ 'base_uri' => get_option( 'host-url' ) ] );

			$res = $client->request('GET', get_option( 'auth-api' ), [
				'headers' => [
					'Cookie' => "REALSSID=$ssoid",
				],
			]);
			$auth = json_decode( $res->getBody(), true );
			$this->update_cookies( $res );

		} catch (Exception $e) {

			// HTTP STATUS が403の時等はGuzzleで例外出力される
			error_log( $e );

		}
		return $auth;

	}

	/**
	 * ブラウザのCookieを更新する
	 * @param $res
	 */
	function update_cookies( $res ) {

		$cookies = $res->getHeader( 'Set-Cookie' );
		$new_ssoid = '';
		foreach ( $cookies as $cookie ) {
			if ( preg_match( '/.*SSOSID.*/', $cookie ) ) {
				$parts = preg_split( '/;/', $cookie );
				$new_ssoid = trim( preg_split( '/=/', $parts[0] )[1] );
			}
		}

		setcookie( 'SSOSID', $new_ssoid, 0, '/', '.example.com' );
		setcookie( 'REALSSID', $new_ssoid, 0, '/', '.example.com' );
	}

	/**
	 * WPローカルのユーザーを作成 or 更新の上サインインする
	 * @param $auth
	 * @return bool
	 */
	function create_user_and_signin( $auth ) {

		$login = $auth['auth']['id'];
		$person = [
			'user_login' => $login,
			'user_pass' => 'test1', // 使われないパスワードであるため乱数で更新する
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

		if ( is_wp_error( $auth ) ) {
			return false;
		}
		return true;
	}
}

new Cookie_Sso_Class();

?>
