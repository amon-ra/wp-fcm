<?php

  static $RESOURCES_VERSION = '37';
  static $SAVE_POST_NONCE_KEY = 'onesignal_meta_box_nonce';
  static $SAVE_POST_NONCE_ACTION = 'onesignal_meta_box';
  static $SAVE_CONFIG_NONCE_KEY = 'onesignal_config_page_nonce';
  static $SAVE_CONFIG_NONCE_ACTION = 'onesignal_config_page';
  
  /**
   * Save the meta when the post is saved.
   * @param int $post_id The ID of the post being saved.
   */
  public static function on_save_post($post_id, $post, $updated) {
	  if ($post->post_type == 'wdslp-wds-log') {
		  // Prevent recursive post logging
		  return;
	  }
    /*
		 * We need to verify this came from the our screen and with proper authorization,
		 * because save_post can be triggered at other times.
		 */
    // Check if our nonce is set.
    if (!isset( $_POST[OneSignal_Admin::$SAVE_POST_NONCE_KEY] ) ) {
	    // This is called on every new post ... not necessary to log it.
	    // onesignal_debug('Nonce is not set for post ' . $post->post_title . ' (ID ' . $post_id . ')');
      return $post_id;
    }

    $nonce = $_POST[OneSignal_Admin::$SAVE_POST_NONCE_KEY];

    // Verify that the nonce is valid.
    if (!wp_verify_nonce($nonce, OneSignal_Admin::$SAVE_POST_NONCE_ACTION)) {
	    onesignal_debug('Nonce is not valid for ' . $post->post_title . ' (ID ' . $post_id . ')');
      return $post_id;
    }

    /*
		 * If this is an autosave, our form has not been submitted,
		 * so we don't want to do anything.
		 */
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
      return $post_id;
    }

    /* OK, it's safe for us to save the data now. */

	  /* Some WordPress environments seem to be inconsistent about whether on_save_post is called before transition_post_status
		 * Check flag in case we just sent a notification for this post (this on_save_post is called after a successful send)
		*/
	  $just_sent_notification = (get_post_meta($post_id, 'onesignal_notification_already_sent', true) == true);

	  if ($just_sent_notification) {
		  // Reset our flag
		  update_post_meta($post_id, 'onesignal_notification_already_sent', false);
		  onesignal_debug('A notification was just sent, so ignoring on_save_post. Resetting check flag.');
		  return;
	  }

	  if (array_key_exists('onesignal_meta_box_present', $_POST)) {
		  update_post_meta($post_id, 'onesignal_meta_box_present', true);
		  onesignal_debug('Set post metadata "onesignal_meta_box_present" to true.');
	  } else {
		  update_post_meta($post_id, 'onesignal_meta_box_present', false);
		  onesignal_debug('Set post metadata "onesignal_meta_box_present" to false.');
	  }

	  /* Even though the meta box always contains the checkbox, if an HTML checkbox is not checked, it is not POSTed to the server */
	  if (array_key_exists('send_onesignal_notification', $_POST)) {
		  update_post_meta($post_id, 'onesignal_send_notification', true);
		  onesignal_debug('Set post metadata "onesignal_send_notification" to true.');
	  } else {
		  update_post_meta($post_id, 'onesignal_send_notification', false);
		  onesignal_debug('Set post metadata "onesignal_send_notification" to false.');
	  }
  }  
  public static function add_onesignal_post_options() {
    // If there is an error message we should display, display it now
    function admin_notice_error() {
        $onesignal_transient_error = get_transient('onesignal_transient_error');
        if ( !empty($onesignal_transient_error) ) {
            delete_transient( 'onesignal_transient_error' );
            echo $onesignal_transient_error;
        }
    }
    add_action( 'admin_notices', 'admin_notice_error');

      // Add our meta box for the "post" post type (default)
    add_meta_box('onesignal_notif_on_post',
                 'OneSignal Push Notifications',
                 array( __CLASS__, 'onesignal_notif_on_post_html_view' ),
                 'post',
                 'side',
                 'high');

    // Then add our meta box for all other post types that are public but not built in to WordPress
    $args = array(
      'public'   => true,
      '_builtin' => false
    );
    $output = 'names';
    $operator = 'and';
    $post_types = get_post_types( $args, $output, $operator );
    foreach ( $post_types  as $post_type ) {
      add_meta_box(
        'onesignal_notif_on_post',
        'OneSignal Push Notifications',
        array( __CLASS__, 'onesignal_notif_on_post_html_view' ),
        $post_type,
        'side',
        'high'
      );
    }
  }


  /**
   * Render Meta Box content.
   * @param WP_Post $post The post object.
   */
  public static function fcm_notif_on_post_html_view($post) {
    $post_type = $post->post_type;
    //$onesignal_wp_settings = OneSignal::get_onesignal_settings();
    $options = get_option('fcm_setting');

    // Add an nonce field so we can check for it later.
    wp_nonce_field($SAVE_POST_NONCE_ACTION, $SAVE_POST_NONCE_KEY, true);

    // Our plugin config setting "Automatically send a push notification when I publish a post from the WordPress editor"
    $settings_send_notification_on_wp_editor_post = $options['post-new'];

    /* This is a scheduled post and the user checked "Send a notification on post publish/update". */
    $post_metadata_was_send_notification_checked = (get_post_meta($post->ID, 'onesignal_send_notification', true) == true);

    // We check the checkbox if: setting is enabled on Config page, post type is ONLY "post", and the post has not been published (new posts are status "auto-draft")
    $meta_box_checkbox_send_notification = ($settings_send_notification_on_wp_editor_post &&  // If setting is enabled
                                            $post->post_type == "post" &&  // Post type must be type post for checkbox to be auto-checked
                                            in_array($post->post_status, array("future", "draft", "auto-draft", "pending"))) || // Post is scheduled, incomplete, being edited, or is awaiting publication
                                            ($post_metadata_was_send_notification_checked);



    ?>
	    <input type="hidden" name="onesignal_meta_box_present" value="true"></input>
      <input type="checkbox" name="send_onesignal_notification" value="true" <?php if ($meta_box_checkbox_send_notification) { echo "checked"; } ?>></input>
      <label>
        <?php if ($post->post_status == "publish") {
          echo "Send notification on " . $post_type . " update";
        } else {
          echo "Send notification on " . $post_type . " publish";
        } ?>
      </label>
    <?php
  }
 
  function fcm_transition_post($new_status, $old_status, $post){

  	  if (array_key_exists('send_onesignal_notification', $_POST)) {
		  update_post_meta($post_id, 'onesignal_send_notification', true);
		  onesignal_debug('Set post metadata "onesignal_send_notification" to true.');
	  } else {
		  update_post_meta($post_id, 'onesignal_send_notification', false);
		  onesignal_debug('Set post metadata "onesignal_send_notification" to false.');
	  }
  }
  
  ?>
