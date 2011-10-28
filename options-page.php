<?php

class HTTPAuthenticationOptionsPage {

  private $auth_plugin;

  function HTTPAuthenticationOptionsPage($auth_plugin) {
    $this->auth_plugin = $auth_plugin;
    add_action('admin_menu', array(&$this, 'add_options_page'));

    if (!empty($_POST)) {
      if ($_POST['action'] === 'delete') {
        if (!empty($_POST['org'])) {
          $this->auth_plugin->deleteOrg($_POST['org']);
        }
      }
      else if (!empty($_POST['org'])) {
        $this->auth_plugin->addOrg($_POST['org']);
      }

      $this->message = $this->auth_plugin->flash;
    }
  }

  function add_options_page() {
    add_options_page('OSU Permissions', 'OSU Permissions', 'manage_network', 'osu_permissions', array(&$this, '_display_options_page'));
  }
  
  function _display_options_page() {
    if (! current_user_can('manage_network')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }
?>
<style>
  .osu_permissions_flash {
    overflow: auto; 
  }
  
  .osu_permissions_flash div {
    background-color: #FFFF09;
    float: left;
    padding: 10px;
  }
  
  ul#permitted_orgs_list {
    list-style-type: square;
    margin-left: 25px;
  }
  
  ul#permitted_orgs_list form {
    display: inline;  
  }
  
  #new_org_form {
    margin-left: 25px;  
  }
</style>
<div class="wrap">
  <h2>Manage site creation permissions</h2>
  
  <?php if (!empty($this->message)) { ?>
  <div class="osu_permissions_flash">
    <div><?php echo $this->message; ?></div>
  </div>
  <?php } ?>
  
  <h4>Add a new permitted org</h4>
  <form id="new_org_form" method="post">
    <input type="text" name="org" />
    <input type="hidden" name="action" value="create" />
    <button type="submit">Add</button>
  </form>
  
  <div>
    <h4>Current permitted orgs</h4>
    <ul id="permitted_orgs_list">
      <?php
        global $blog_id;
        $orgs = $this->auth_plugin->get_permitted_orgs();
        foreach ($orgs as $org) { ?>
          <li>
          <?php echo $org; ?>
          <form method="post">
            <input type="hidden" name="action" value="delete" />
            <input type="hidden" name="org" value="<?php echo $org; ?>" />
            <button type="submit">Delete</button>
          </form>
          </li>
          <?php
        }
      ?>
    </ul>
  </div>
  
</div>
<?php
  }
}
