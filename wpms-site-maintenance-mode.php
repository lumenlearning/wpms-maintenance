<?php
/*
Plugin Name: WPMS Site Maintenance Mode
Plugin URI: http://wordpress.org/extend/plugins/wpms-site-maintenance-mode/
Description: Provides an interface to make a WPMS network unavailable to everyone during maintenance, except the admin.
Original Author: I.T. Damager
Author: 7 Media Web Solutions, LLC
Author URI: http://www.7mediaws.org/
Version: 1.0.3
License: GPL

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.
*/

class wpms_sitemaint {

	var $sitemaint;
	var $retryafter;
	var $message;

	function wpms_sitemaint() {
		add_action('init',array(&$this,'wpms_sitemaint_init'),1);
		add_action('network_admin_menu',array(&$this,'add_admin_subpanel'));
	}

	function wpms_sitemaint_init() {
		$this->apply_settings();
		if ($this->sitemaint) return $this->shutdown();
	}

	function add_admin_subpanel() {
		add_submenu_page('settings.php', __('WPMS Site Shutdown'), __('WPMS Sitedown'), 'manage_network_options', 'wpms_site_maint', array(&$this,'adminpage'));
	}

	function set_defaults() {
		// do not edit here - use the admin screen
		$this->sitemaint = 0;
		$this->retryafter = 60;
		$this->message = '
		<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
		<html xmlns="http://www.w3.org/1999/xhtml">
		<head>
			<title>' . get_site_option('site_name') . ' is undergoing routine maintenance</title>
			<meta http-equiv="Content-Type" content="' . get_bloginfo('html_type') . '; ' . get_bloginfo('charset') . '" />
			<link rel="stylesheet" href="' . WP_PLUGIN_URL . '/wpms-site-maintenance-mode/css/style.css" type="text/css" media="screen" />
		</head>
		<body>
 
			<div id="content-outer">
				<div id="content">
					<img src="' . WP_PLUGIN_URL . '/wpms-site-maintenance-mode/images/coffee_machine-256.png" class="motivation-maker" />	
					<h1>We are on a quick coffee break.</h1>
					<p>Our ' . get_site_option('site_name') . ' network is undergoing maintenance that will last <strong>' . $this->retryafter . ' minutes at the most</strong>.</p>
					<p>We apologize for the inconvenience, and we are doing out best to get things back to working order.</p>
				</div>
			</div>
		</body>
		</html>';
	}

	function apply_settings($settings = false) {
		if (!$settings) $settings = get_site_option('wpms_sitemaint_settings');
		if (is_array($settings)) foreach($settings as $setting => $value) $this->$setting = $value;
		else $this->set_defaults();
	}

	function save_settings() {
		global $wpdb, $updated, $configerror;
		check_admin_referer();
		// validate all input!
		if (preg_match('/^[0-9]+$/',$_POST['sitemaint'])) $sitemaint = intval($_POST['sitemaint']);
		else $configerror[] = 'sitemaint must be numeric. Default: 0 (Normal site operation)';

		if ($_POST['retryafter']>0) $retryafter = intval($_POST['retryafter']);
		else $configerror[] = 'Retry After must be greater than zero minutes. Default: 60';

		//$wpdb->escape() or addslashes not needed -- string is compacted into an array then serialized before saving in db
		if (trim($_POST['message'])) $message = (get_magic_quotes_gpc()) ? stripslashes(trim($_POST['message'])) : trim($_POST['message']);
		else $configerror[] = 'Please enter a message to display to visitors when the site is down. (HTML OK!)';

		if (is_array($configerror)) return $configerror;

		$settings = compact('sitemaint','retryafter','message');
		foreach($settings as $setting => $value) if ($this->$setting != $value) $changed = true;
		if ($changed) {
			update_site_option('wpms_sitemaint_settings', $settings);
			$this->apply_settings($settings);
			return $updated = true;
		}
	}

	function delete_settings() {
		global $wpdb, $updated, $wp_object_cache;
		$settings = get_site_option('wpms_sitemaint_settings');
		if ($settings) {
			$wpdb->query("DELETE FROM $wpdb->sitemeta WHERE `meta_key` = 'wpms_sitemaint_settings'");
			if (is_object($wp_object_cache) && $wp_object_cache->cache_enabled == true) wp_cache_delete('wpms_sitemaint_settings','site-options');
			$this->set_defaults();
			return $updated = true;
		}
	}

	function urlend($end) {
		return (substr($_SERVER['REQUEST_URI'], strlen($end)*-1) == $end) ? true : false;
	}

	function shutdown() {
		global $wpdb;
		get_currentuserinfo();
		if (is_super_admin()) return; //allow admin to use site normally
		if ($wpdb->blogid == 1 && $this->urlend('wp-login.php')) return; //I told you *not* to log out, but you did anyway. duh!
		if ($this->sitemaint == 2 && $wpdb->blogid != 1) return; //user blogs on, main blog off
		if ($this->sitemaint == 1 && $wpdb->blogid == 1) return; //main blog on, user blogs off
		header('HTTP/1.1 503 Service Unavailable');
		header('Retry-After: '.$this->retryafter*60); //seconds
		if (!$this->urlend('feed/') && !$this->urlend('trackback/') && !$this->urlend('xmlrpc.php')) echo stripslashes($this->message);
		exit();
	}

	function adminpage() {
		global $updated, $configerror;
		get_currentuserinfo();
		if (!is_super_admin()) die(__('<p>You do not have permission to access this page.</p>'));
		if ($_POST['action'] == 'update') {
			if ($_POST['reset'] != 1) $this->save_settings();
			else $this->delete_settings();
		}
		if ($updated) { ?>
<div id="message" class="updated fade"><p><?php _e('Options saved.') ?></p></div>
<?php	} elseif (is_array($configerror)) { ?>
<div class="error"><p><?php echo implode('<br />',$configerror); ?></p></div>
<?php }
if ($this->sitemaint == 1) { ?>
  <div class="error"><p><?php _e('WARNING: YOUR USER BLOGS ARE CURRENTLY DOWN!'); ?></p></div>
<?php }
if ($this->sitemaint == 2) { ?>
  <div class="error"><p><?php _e('WARNING: YOUR MAIN BLOG IS CURRENTLY DOWN!'); ?></p></div>
<?php }
if ($this->sitemaint == 3) { ?>
  <div class="error"><p><?php _e('WARNING: YOUR ENTIRE SITE IS CURRENTLY DOWN!'); ?></p></div>
<?php } ?>
<div class="wrap">
  <h2><?php _e('WPMS Site Maintenace'); ?></h2>
  <fieldset>
  <p><?php _e('This plugin shuts down your site for maintenance by sending feed readers, bots, and browsers an http response code 503 and the Retry-After header'); ?> (<a href="ftp://ftp.isi.edu/in-notes/rfc2616.txt" target="_blank">rfc2616</a>). <?php _e('It displays your message except when feeds, trackbacks, or other xml pages are requested.'); ?></p>
  <p><?php _e('Choose site UP or DOWN, retry time (in minutes) and your message.'); ?></p>
  <p><em><?php _e('The site will remain fully functional for admin users.'); ?> <span style="color:#CC0000;"><?php _e('Do not log out while the site is down!'); ?></span><br />
  <?php _e('If you log out (and lock yourself out of the site) visit'); ?> <?php bloginfo_rss('home') ?>/wp-login.php <?php _e('to log back in.'); ?></em></p>
  <form name="sitemaintform" method="post" action="">
    <p><label><input type="radio" name="sitemaint" value="0"<?php checked(0, $this->sitemaint); ?> /> <?php _e('SITE UP (Normal Operation)'); ?></label><br />
       <label><input type="radio" name="sitemaint" value="1"<?php checked(1, $this->sitemaint); ?> /> <?php _e('USER BLOGS DOWN, MAIN BLOG UP!'); ?></label><br />
       <label><input type="radio" name="sitemaint" value="2"<?php checked(2, $this->sitemaint); ?> /> <?php _e('MAIN BLOG DOWN, USER BLOGS UP!'); ?></label><br />
       <label><input type="radio" name="sitemaint" value="3"<?php checked(3, $this->sitemaint); ?> /> <?php _e('ENTIRE SITE DOWN!'); ?></label></p>
    <p><label><?php _e('Retry After'); ?> <input name="retryafter" type="text" id="retryafter" value="<?php echo $this->retryafter; ?>" size="3" /> <?php _e('minutes.'); ?></label></p>
    <p><label><?php _e('HTML page displayed to site visitors:'); ?><br />
      <textarea name="message" cols="125" rows="10" id="message"><?php echo stripslashes($this->message); ?></textarea></label></p>
	<p>&nbsp;</p>
	<p><label><input name="reset" type="checkbox" value="1" /> <?php _e('Reset all settings to default'); ?></label></p>
    <p class="submit">
      <input name="action" type="hidden" id="action" value="update" />
      <input type="submit" name="Submit" value="Update Settings" />
    </p>
  </form>
  </fieldset>
</div>
<?php
	}
}

//begin execution
if (defined('ABSPATH')) $wpms_sitemaint = new wpms_sitemaint();