<?php
/*
Plugin Name: HTTP Authentication
Version: 4.1
Plugin URI: https://dev.webadmin.ufl.edu/~dwc/2011/07/04/http-authentication-4-0/
Description: Authenticate users using basic HTTP authentication (<code>REMOTE_USER</code>). This plugin assumes users are externally authenticated, as with <a href="http://www.gatorlink.ufl.edu/">GatorLink</a>.
Author: Daniel Westermann-Clark
Author URI: https://dev.webadmin.ufl.edu/~dwc/
*/

class HTTPAuthenticationPlugin {

	function HTTPAuthenticationPlugin() {
		add_action('login_head', array(&$this, 'add_login_css'));
		add_action('login_footer', array(&$this, 'add_login_link'));
		add_action('wp_logout', array(&$this, 'logout'));
		add_filter('login_url', array(&$this, 'bypass_reauth'));
		add_filter('authenticate', array(&$this, 'authenticate'), 10, 3);
	}

	function add_login_css() {
?>
<style type="text/css">
p#http-authentication-link {
	margin:	-5em auto 0 auto;
	position: absolute;
	text-align: center;
	width: 100%;
}
</style>
<?php
	}

	/*
	 * Add a link to the login form to initiate external authentication.
	 */
	function add_login_link() {

		$login_uri = 'http://ruby-test.asc.ohio-state.edu/Shibboleth.sso?target=' . wp_login_url();
		$auth_label = 'Shibboleth';

		echo "\t" . '<p id="http-authentication-link"><a class="button-primary" href="' . htmlspecialchars($login_uri) . '">Log In with ' . htmlspecialchars($auth_label) . '</a></p>' . "\n";
	}

	/*
	 * Generate a password for the user. This plugin does not require the
	 * administrator to enter this value, but we need to set it so that user
	 * creation and editing works.
	 */
	function generate_password($username, $password1, $password2) {
		if (! $this->allow_wp_auth()) {
			$password1 = $password2 = wp_generate_password();
		}
	}

	/*
	 * Logout the user by redirecting them to the logout URI.
	 */
	function logout() {
		$logout_uri = 'http://ruby-test.asc.ohio-state.edu/Shibboleth.sso/Logout?target=' . home_url();

		wp_redirect($logout_uri);
		exit();
	}

	/*
	 * Remove the reauth=1 parameter from the login URL, if applicable. This allows
	 * us to transparently bypass the mucking about with cookies that happens in
	 * wp-login.php immediately after wp_signon when a user e.g. navigates directly
	 * to wp-admin.
	 */
	function bypass_reauth($login_url) {
		$login_url = remove_query_arg('reauth', $login_url);

		return $login_url;
	}

	/*
	 * Can we fallback to built-in WordPress authentication?
	 */
	function allow_wp_auth() {
		return false;
	}

	/*
	 * Authenticate the user, first using the external authentication source.
	 * If allowed, fall back to WordPress password authentication.
	 */
	function authenticate($user, $username, $password) {
		$user = $this->check_remote_user();

		if (! is_wp_error($user)) {
			$user = new WP_User($user->ID);
		}

		return $user;
	}

	/*
	 * If the REMOTE_USER or REDIRECT_REMOTE_USER evironment variable is set, use it
	 * as the username. This assumes that you have externally authenticated the user.
	 */
	function check_remote_user() {
		$username = '';

		foreach (array('REMOTE_USER', 'REDIRECT_REMOTE_USER') as $key) {
			if (isset($_SERVER[$key])) {
				$username = str_replace('@osu.edu', '', $_SERVER[$key]);
			}
		}

		if (! $username) {
			return new WP_Error('empty_username', '<strong>ERROR</strong>: No REMOTE_USER or REDIRECT_REMOTE_USER found.');
		}

		// Create new users automatically, if configured
		$user = get_userdatabylogin($username);
		if (! $user)  {
			$user = new WP_Error('authentication_failed', __('<strong>ERROR</strong>: You may not have access to this site or you may have entered an invalid username or incorrect password.'));
		}

		return $user;
	}

  /*
   * Fill the specified URI with the site URI and the specified return location.
   */
  function _generate_uri($uri, $redirect_to) {
    // Support tags for staged deployments
    $base = $this->_get_base_url();

    $tags = array(
      'host' => $_SERVER['HTTP_HOST'],
      'base' => $base,
      'site' => home_url(),
      'redirect' => $redirect_to,
    );

    foreach ($tags as $tag => $value) {
      $uri = str_replace('%' . $tag . '%', $value, $uri);
      $uri = str_replace('%' . $tag . '_encoded%', urlencode($value), $uri);
    }

    // Support previous versions with only the %s tag
    if (strstr($uri, '%s') !== false) {
      $uri = sprintf($uri, urlencode($redirect_to));
    }

    return $uri;
  }

  /*
   * Return the base domain URL based on the WordPress home URL.
   */
  function _get_base_url() {
    $home = parse_url(home_url());
    $base = str_replace(array($home['path'], $home['query'], $home['fragment']), '', home_url());

    return $base;
  }

}

// Load the plugin hooks, etc.
$http_authentication_plugin = new HTTPAuthenticationPlugin();
?>
