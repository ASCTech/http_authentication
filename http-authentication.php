<?php
/*
Plugin Name: OSU Wordpress MU Shibboleth
Version: 0.2
Description: DO NOT UPDATE THIS PLUGIN THROUGH THE ADMIN INTERFACE. Authenticates users with Shibboleth. Only users that have an existing wordpress account are authenticated. Based on HTTP Authentication by Daniel Westermann-Clark found at https://dev.webadmin.ufl.edu/~dwc/2011/07/04/http-authentication-4-0/
Author: John Colvin
*/
require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'options-page.php');

class HTTPAuthenticationPlugin {

  private $permitted_orgs_option_name;
  public $flash;

  function HTTPAuthenticationPlugin() {
    $this->permitted_orgs_option_name = 'site_creation_permitted_orgs';
    $this->flash = '';
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
  margin: -5em auto 0 auto;
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
    $login_uri = $this->get_login_uri(wp_login_url());
    echo "\t" . '<p id="http-authentication-link"><a class="button-primary" href="' . htmlspecialchars($login_uri) . '">Log In with Shibboleth</a></p>' . "\n";
  }

  function get_login_uri($target='') {
    $login_uri = '/Shibboleth.sso/DS';

    if (!empty($target)) {
      $login_uri .= '?target=' . urlencode($target);
    }

    return $login_uri;
  }

  /*
   * Generate a password for the user. This plugin does not require the
   * administrator to enter this value, but we need to set it so that user
   * creation and editing works.
   */
  function generate_password($username, $password1, $password2) {
      $password1 = $password2 = wp_generate_password();
  }

  /*
   * Logout the user by redirecting them to the logout URI.
   */
  function logout() {
    $logout_uri = '/Shibboleth.sso/Logout?target=' . home_url();

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

  function get_shib_username() {
    $username = '';
    foreach (array('REMOTE_USER', 'REDIRECT_REMOTE_USER') as $key) {
      if (isset($_SERVER[$key])) {
        $username = str_replace('@osu.edu', '', $_SERVER[$key]);
      }
    }
    return $username;
  }

  /*
   * If the REMOTE_USER or REDIRECT_REMOTE_USER evironment variable is set, use it
   * as the username. This assumes that you have externally authenticated the user.
   */
  function check_remote_user() {
    $username = $this->get_shib_username();

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

  function addOrg($org_number) {
    $current_orgs = $this->get_permitted_orgs();

    $orgs = $current_orgs ?: array();
    if (in_array($org_number, $orgs)) {
      $this->flash = "This org is already permitted";
      return true;
    }

    $orgs[] = $org_number;

    if ($current_orgs === false) {
      $result = add_option($this->permitted_orgs_option_name, $orgs);
    }
    else {
      $result = update_option($this->permitted_orgs_option_name, $orgs);
    }
    
    $this->flash = ($result === true) ? 'Org successfully added' : 'Could not add this org';
    return $result;
  }

  function deleteOrg($org_number) {
    $orgs = $this->get_permitted_orgs();
    $key = array_search($org_number, $orgs);
    if ($key !== false) {
      unset($orgs[$key]);
      $result = update_option($this->permitted_orgs_option_name, $orgs);
      $this->flash = ($result === true) ? 'Successfully removed' : 'Could not remove this org';
      return $result;
    }

    return true;
  }

  function get_permitted_orgs() {
    return get_option($this->permitted_orgs_option_name);
  }

  private function is_in_permitted_org() {
    if (isset($_SERVER['HTTP_DEPARTMENTNUMBER'])) {
      $permitted_orgs = $this->get_permitted_orgs();
      $org_keys = array('departmentNumber', 'HTTP_DEPARTMENTNUMBER');
      foreach ($org_keys as $org_key) {
        if (isset($_SERVER[$org_key])) {
          $orgs = explode(';', $_SERVER[$org_key]);
        }
      }

      if (!isset($orgs)) {
        return false;
      }

      foreach ($orgs as $org) {
        if (in_array($org, $permitted_orgs)) {
          return true;
        }
      }
    }
    return false;
  }

  private function has_permitted_affiliation() {
    if (isset($_SERVER['HTTP_AFFILIATION'])) {
      $affiliation_keys = array('affiliation', 'HTTP_AFFILIATION');
      foreach ($affiliation_keys as $affiliation_key) {
        if (isset($affiliation_key)) {
          $affiliations = explode(';', $_SERVER[$affiliation_key]);
        }
      }

      if (!isset($affiliations)) {
        return false;
      }

      foreach ($affiliations as $affiliation) {
        if ($affiliation == 'staff@osu.edu' || $affiliation == 'faculty@osu.edu') {
          return true;
        }
      }
    }
    return false;
  }

  function can_create_site() {
    return $this->has_permitted_affiliation() && $this->is_in_permitted_org();
  }

}

// Load the plugin hooks, etc.
$http_authentication_plugin = new HTTPAuthenticationPlugin();
$options_page = new HTTPAuthenticationOptionsPage($http_authentication_plugin);
?>
