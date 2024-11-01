<?php
defined( 'ABSPATH' ) OR exit;

/*
Plugin Name: Websitez.com
Plugin URI:  https://websitez.com/documentation#wordpress
Description: Turn anonymous website traffic into leads and personalize your website for visitors.
Version:     1.1
Author:      Joshua Odmark
Author URI:  http://joshuaodmark.com
License:     GPL2

Copyright 2022 Joshua Odmark (email : josh@joshuaodmark.com)
(Websitez.com Plugin) is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.

Websitez.com Plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with Websitez.com Plugin.
*/

if (isset($_SERVER['WEBSITEZ_DEBUG'])) {
    if (!defined('WP_DEBUG')) {
        define('WP_DEBUG', true);
    }
    if (!defined('WP_DEBUG_LOG')) {
        define('WP_DEBUG_LOG', true);
    }
    if (!defined('SCRIPT_DEBUG')) {
        define('SCRIPT_DEBUG', true);
    }
    if (!defined('SAVEQUERIES')) {
        define('SAVEQUERIES', true);
    }
}

register_activation_hook(   __FILE__, array( 'Websitez_Com_Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( 'Websitez_Com_Plugin', 'on_deactivation' ) );
register_uninstall_hook(    __FILE__, array( 'Websitez_Com_Plugin', 'on_uninstall' ) );

add_action( 'plugins_loaded', array( 'Websitez_Com_Plugin', 'init' ) );

/*
 * Class Websitez_Com_Plugin
 */

if( ! class_exists('Websitez_Com_Plugin')) {
    class Websitez_Com_Plugin
    {
        public static $websitez_url = 'https://websitez.com';
        private static $websitez_api = 'https://api.websitez.com/v1';
        private static $site_token_key = 'websitez_token_hash';
        private static $site_hash_key = 'websitez_site_hash';
        private static $site_watch_key = 'websitez_watch_pixel';
        private static $site_detect_key = 'websitez_detect_pixel';
        private static $websitez_api_token_key = 'websitez_api_token';
        private static $site_personalization_key = 'websitez_personalize_snippet';
        private static $after_activation_key = 'websitez_activation_key';

        protected static $instance;

        public static function init()
        {
            is_null(self::$instance) AND self::$instance = new self;
            return self::$instance;
        }

        public static function get_api_url(){
            if (isset($_SERVER['WEBSITEZ_DEBUG'])) {
                return 'http://host.docker.internal:8080';
            }

            return self::$websitez_api;
        }

        public function __construct()
        {
            add_action('admin_menu', array($this, 'websitez_com_page'));
            add_action('admin_init', array($this, 'setup_sections'));
            add_action('admin_init', array($this, 'setup_fields'));
            add_action('update_option_' . Websitez_Com_Plugin::$site_hash_key, function ($old_value, $value, $option) {
                if ($old_value !== $value) {
                    Websitez_Com_Plugin::update_site();
                }
            }, 10, 3);
            add_action('wp_footer', array($this, 'place_pixel'));
            add_action('wp_head', array($this, 'place_personalization'));
            add_action('parse_request', array($this, 'validate_registration'), 0);
            add_action('admin_init', array($this, 'validate_token') );
            if (isset($_SERVER['WEBSITEZ_DEBUG'])) {

            }
        }

        public function validate_registration($wp_query)
        {
            if (array_key_exists('websitez_token', $_GET)) {
                exit(get_option(self::$site_token_key, ''));
            }
        }

        public static function on_activation()
        {
            if (!current_user_can('activate_plugins'))
                return;
            $plugin = isset($_REQUEST['plugin']) ? sanitize_text_field($_REQUEST['plugin']) : '';
            check_admin_referer("activate-plugin_{$plugin}");

            # Uncomment the following line to see the function in action
            # exit( var_dump( $_GET ) );

            if (false === get_option(self::$site_token_key)) {
                self::get_token();
                update_option( self::$after_activation_key, 'activated' );
            }
        }

        public static function on_deactivation()
        {
            if (!current_user_can('activate_plugins'))
                return;
            $plugin = isset($_REQUEST['plugin']) ? sanitize_text_field($_REQUEST['plugin']) : '';
            check_admin_referer("deactivate-plugin_{$plugin}");

            # Uncomment the following line to see the function in action
            # exit( var_dump( $_GET ) );
        }

        public static function on_uninstall()
        {
            if (!current_user_can('activate_plugins'))
                return;
            check_admin_referer('bulk-plugins');

            // Important: Check if the file is the one
            // that was registered during the uninstall hook.
            if (__FILE__ != WP_UNINSTALL_PLUGIN)
                return;

            # Uncomment the following line to see the function in action
            # exit( var_dump( $_GET ) );

            delete_option(self::$site_token_key);
            delete_option(self::$site_hash_key);
            delete_option(self::$site_watch_key);
            delete_option(self::$site_detect_key);
            delete_option(self::$websitez_api_token_key);
            delete_option(self::$site_personalization_key);
        }

        public static function get_token()
        {
            $url = Websitez_Com_Plugin::get_api_url() . '/generate_token';
            $response = wp_remote_post($url, ['body' => json_encode(['site_name' => get_bloginfo('name'), 'wpurl' => get_bloginfo('wpurl'), 'email' => get_bloginfo('admin_email')]), 'headers' => ['Content-Type' => 'application/json']]);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (array_key_exists('token', $data)) {
                update_option(self::$site_token_key, $data['token']);
            }
        }

        public static function validate_token(){
            if ( is_admin() && get_option(self::$after_activation_key) == 'activated' ) {
                delete_option(self::$after_activation_key);
                $token = get_option(self::$site_token_key);
                $url = Websitez_Com_Plugin::get_api_url() . '/validate_token';
                $response = wp_remote_post($url, ['body' => json_encode(['token' => $token]), 'headers' => ['Content-Type' => 'application/json']]);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);

                if (array_key_exists('success', $data) && $data['success'] == true) {
                    if (array_key_exists('record_visits_pixel', $data['site'])) {
                        update_option(self::$site_watch_key, $data['site']['record_visits_pixel']);
                    }
                    if (array_key_exists('real_time_detect', $data['site'])) {
                        update_option(self::$site_detect_key, $data['site']['real_time_detect']);
                    }
                    if (array_key_exists('hash', $data['site'])) {
                        update_option(self::$site_hash_key, $data['site']['hash']);
                    }
                    if (array_key_exists('api_key', $data)) {
                        update_option(self::$websitez_api_token_key, $data['api_key']);
                    }
                }
            }
        }

        public static function update_site()
        {
            $api_token = get_option(Websitez_Com_Plugin::$websitez_api_token_key);
            if ($api_token !== false) {
                $site_hash = get_option(Websitez_Com_Plugin::$site_hash_key);
                $url = Websitez_Com_Plugin::get_api_url() . '/get_pixels';
                $response = wp_remote_get($url, ['headers' => ['Content-Type' => 'application/json']]);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                if (is_array($data) && array_key_exists('record_visits_pixel', $data)) {
                    if (array_key_exists('record_visits_pixel', $data)) {
                        update_option(self::$site_watch_key, $data['record_visits_pixel']);
                    }
                    if (array_key_exists('real_time_detect', $data)) {
                        update_option(self::$site_detect_key, $data['real_time_detect']);
                    }
                }
            }
        }

        public function place_pixel()
        {
            $html = '';
            if ($watch_pixel = get_option(self::$site_watch_key)) {
                $watch_pixel = base64_decode($watch_pixel);
                $site_hash = get_option(Websitez_Com_Plugin::$site_hash_key);
                $watch_pixel = str_replace("##REPLACE_ME###", $site_hash, $watch_pixel);
                $html .= $watch_pixel;
            }
            if ($detect_pixel = get_option(self::$site_detect_key)) {
                $detect_pixel = base64_decode($detect_pixel);
                $site_hash = get_option(Websitez_Com_Plugin::$site_hash_key);
                $detect_pixel = str_replace("##REPLACE_ME###", $site_hash, $detect_pixel);
                $html .= $detect_pixel;
            }
            echo wp_kses($html, ['script' => [
                'type' => true,
                'async' => true
            ]]);
        }

        public function place_personalization()
        {
            $html = '';
            if ($personalization = get_option(self::$site_personalization_key)) {
                if (strlen($personalization) > 0) {
                    $personalization = base64_decode($personalization);
                    $html .= $personalization;
                }
            }
            echo wp_kses($html, 'post');
        }

        public function websitez_com_page()
        {
            add_menu_page(
                __( 'Websitez.com', 'plugin_name_short' ),
                __( 'Websitez.com', 'plugin_name_short' ),
                'manage_options',
                'websitez-com/websitez-com.php',
                array($this, 'websitez_com_leads_html')
            );
            add_submenu_page(
                'websitez-com/websitez-com.php',
                __( 'Personalize', 'personalize' ),
                __( 'Personalize', 'personalize' ),
                'manage_options',
                'websitez_com_personalize',
                array($this, 'websitez_com_personalize_html')
            );
            add_submenu_page(
                'websitez-com/websitez-com.php',
                __( 'Settings', 'settings' ),
                __( 'Settings', 'settings' ),
                'manage_options',
                'websitez_com_settings',
                array($this, 'websitez_com_page_html')
            );
        }

        public function websitez_com_page_html()
        {
            // check user capabilities
            if (!current_user_can('manage_options')) {
                return;
            }
            ?>
            <div class="wrap">
                <div style="float: right;">
                    <a href="<?php echo Websitez_Com_Plugin::$websitez_url; ?>/settings?upgrade" target="_blank" class="button button-primary">Upgrade Your Plan</a>
                </div>
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <p><?php _e('Configure your Websitez.com account here to enable the plugin.', 'settings_first_para'); ?></p>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('websitez_com');
                    do_settings_sections('websitez_com');
                    submit_button(__('Save Settings', 'textdomain'));
                    ?>
                </form>
            </div>
            <?php
        }

        public function websitez_com_leads_html()
        {
            // check user capabilities
            if (!current_user_can('manage_options')) {
                return;
            }
            $limited = false;
            $site_hash = "";
            $leads = [];
            $api_token = get_option(Websitez_Com_Plugin::$websitez_api_token_key);
            if ($api_token !== false) {
                $site_hash = get_option(self::$site_hash_key);
                $url = Websitez_Com_Plugin::get_api_url() . '/companies/sites/' . $site_hash;
                $response = wp_remote_get($url, ['headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_token]]);
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                $not_verified = false;
                if (array_key_exists('companies', $data)) {
                    $leads = $data['companies'];
                    if (array_key_exists('limited', $data) && $data['limited'] != false) {
                        $limited = $data['limited'];
                    }
                } elseif (array_key_exists('error', $data) && strpos($data['error'], 'verified') !== false){
                    $not_verified = true;
                }
            }
            ?>
            <div class="wrap">
                <div style="float: right;">
                    <a href="<?php echo Websitez_Com_Plugin::$websitez_url; ?>/settings?upgrade" target="_blank" class="button button-primary">Upgrade Your Plan</a>
                </div>
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <p><?php _e('View the most recent visitors to your website. For historical visitors, please visit your Websitez.com dashboard.', 'leads_first_para'); ?></p>
                <table style="width: 100%;">
                    <?php
                    if (count($leads) > 0) {
                    ?>
                    <tr>
                        <th width="60%">Company Name</th>
                        <th width="10%">Visits</th>
                        <th width="10%">Unique Visits</th>
                        <th width="20%">Last Visit</th>
                    </tr>
                    <?php
                        if ($limited != false) {
                        ?>
                        <tr>
                            <td style="padding: 15px; background-color: #bcdcf5;" colspan="4" align="center">
                                <?php echo wp_kses($limited, 'post'); ?>
                            </td>
                        </tr>
                        <?php
                        }
                        foreach ($leads as &$lead) {
                            ?>
                            <tr style="padding: 5px; margin-bottom: 15px; ">
                                <td><b><a href="<?php echo Websitez_Com_Plugin::$websitez_url . "/companies/view/" . wp_kses($lead[4], "post") . "/" . wp_kses($site_hash, "post"); ?>" target="_blank"><?php echo wp_kses($lead[0]['name'], "post"); ?></a></b><br \><small><a href="http://<?php echo esc_url($lead[0]['domain']); ?>" target="_blank"><?php echo esc_url($lead[0]['domain']); ?></a></small></td>
                                <td><?php echo wp_kses($lead[1], 'post'); ?></td>
                                <td><?php echo wp_kses($lead[2], 'post'); ?></td>
                                <td><?php echo wp_kses($lead[3], 'post'); ?></td>
                            </tr>
                            <?php
                        }
                        ?>
                        <tr>
                            <td colspan="4" align="center">
                                <a href="<?php echo Websitez_Com_Plugin::$websitez_url."/dashboard"; ?>" target="_blank">View More Companies</a>
                            </td>
                        </tr>
                        <?php
                    } elseif ($not_verified){
                    ?>
                        <tr>
                            <td><p>For leads to show up, you must <b>verify your email</b>.</p></td>
                        </tr>
                    <?php
                    } else {
                        ?>
                        <tr>
                            <td><p><b>There are no leads yet.</b></p></td>
                        </tr>
                    <?php } ?>
                </table>
            </div>
            <?php
        }

        public function websitez_com_personalize_html()
        {
            // check user capabilities
            if (!current_user_can('manage_options')) {
                return;
            }
            ?>
            <div class="wrap">
                <div style="float: right;">
                    <a href="<?php echo Websitez_Com_Plugin::$websitez_url; ?>/documentation#personalization" target="_blank" class="button button-primary">Documentation</a> <a href="<?php echo Websitez_Com_Plugin::$websitez_url; ?>/settings?upgrade" target="_blank" class="button button-primary">Upgrade Your Plan</a>
                </div>
                <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
                <p><?php _e('Customize your website in real-time based on visitors coming to your website.', 'personalize_first_para'); ?></p>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('websitez_com_personalize');
                    do_settings_sections('websitez_com_personalize');
                    submit_button(__('Save Settings', 'textdomain'));
                    ?>
                </form>
            </div>
            <?php
        }

        public function setup_sections()
        {
            add_settings_section('websitez_section', 'Websitez.com Configuration', array($this, 'section_callback'), 'websitez_com');
            add_settings_section('personalize_section', 'Personalize', array($this, 'section_callback'), 'websitez_com_personalize');
        }

        public function section_callback($arguments)
        {
            switch ($arguments['id']) {
                case 'websitez_section':
                    echo 'Configure the Websitez.com service to start identifying anonymous visitors.';
                    break;
            }
        }

        public function setup_fields()
        {
            $fields = array(
                array(
                    'uid' => Websitez_Com_Plugin::$websitez_api_token_key,
                    'label' => 'Websitez.com API Key',
                    'section' => 'websitez_section',
                    'type' => 'text',
                    'options' => false,
                    //'placeholder' => 'DD/MM/YYYY',
                    //'helper' => 'Does this help?',
                    'supplemental' => 'If you have your own Websitez.com API Key, add it here!',
                    //'default' => '',
                    'page' => 'websitez_com'
                ),
                array(
                    'uid' => Websitez_Com_Plugin::$site_hash_key,
                    'label' => 'Websitez.com Site Hash',
                    'section' => 'websitez_section',
                    'type' => 'text',
                    'options' => false,
                    //'placeholder' => 'DD/MM/YYYY',
                    //'helper' => 'Does this help?',
                    'supplemental' => 'The hash is found on the individual site page at Websitez.com',
                    //'default' => '',
                    'page' => 'websitez_com'
                ),
                array(
                    'uid' => Websitez_Com_Plugin::$site_personalization_key,
                    'label' => 'Personalization Snippet',
                    'section' => 'personalize_section',
                    'type' => 'textarea',
                    'options' => false,
                    //'placeholder' => 'DD/MM/YYYY',
                    //'helper' => 'Does this help?',
                    //'supplemental' => 'If you have your own Websitez.com API Key, add it here!',
                    'default' => 'PHNjcmlwdCB0eXBlPSJ0ZXh0L2phdmFzY3JpcHQiIGFzeW5jPTE+CndpbmRvdy5hZGRFdmVudExpc3RlbmVyKCdjb21wYW55LnZpc2l0JywgZnVuY3Rpb24gKGUpIHsKICAgIGNvbnNvbGUubG9nKCdXZWxjb21lIHZpc2l0b3IgZnJvbSAnICsgZS5kZXRhaWwubmFtZSArICcgYW5kIGRvbWFpbiAnICsgZS5kZXRhaWwuZG9tYWluKQp9KTsKPC9zY3JpcHQ+',
                    'page' => 'websitez_com_personalize'
                )
            );

            foreach ($fields as $field) {
                add_settings_field($field['uid'], $field['label'], array($this, 'field_callback'), $field['page'], $field['section'], $field);
                $options = [];
                if ($field['uid'] == 'websitez_personalize_snippet') {
                    $options = ['sanitize_callback' => 'base64_encode'];
                }
                register_setting($field['page'], $field['uid'], $options);
            }
        }


        public function field_callback($arguments)
        {
            $value = get_option($arguments['uid']); // Get the current value, if there is one
            if (!$value) { // If no value exists
                $value = $arguments['default']; // Set to our default
            }
            if ($arguments['uid'] == 'websitez_personalize_snippet') {
                $value = base64_decode($value);
            }

            // Check which type of field we want
            switch ($arguments['type']) {
                case 'text': // If it is a text field
                    printf('<input style="width: 100%%;" name="%1$s" id="%1$s" type="%2$s" placeholder="%3$s" value="%4$s" />', $arguments['uid'], $arguments['type'], $arguments['placeholder'], $value);
                    break;
                case 'textarea': // If it is a textarea
                    printf('<textarea style="width: 100%%;" name="%1$s" id="%1$s" placeholder="%2$s" rows="5" cols="50">%3$s</textarea>', $arguments['uid'], $arguments['placeholder'], $value);
                    break;
                case 'select': // If it is a select dropdown
                    if (!empty ($arguments['options']) && is_array($arguments['options'])) {
                        $options_markup = ’;
                        foreach ($arguments['options'] as $key => $label) {
                            $options_markup .= sprintf('<option value="%s" %s>%s</option>', $key, selected($value, $key, false), $label);
                        }
                        printf('<select name="%1$s" id="%1$s">%2$s</select>', $arguments['uid'], $options_markup);
                    }
                    break;
            }

            // If there is help text
            if ($helper = $arguments['helper']) {
                printf('<span class="helper"> %s</span>', $helper); // Show it
            }

            // If there is supplemental text
            if ($supplimental = $arguments['supplemental']) {
                printf('<p class="description">%s</p>', $supplimental); // Show it
            }
        }
    }
}

Websitez_Com_Plugin::init();
