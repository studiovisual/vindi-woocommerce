<?php

if (! defined('ABSPATH')) {
  die();
	exit; // Exit if accessed directly
}

class FrontendFilesLoader {

  /**
   * @var VindiSettings
   */
  public $vindi_settings;

  function __construct(VindiSettings $vindi_settings) {
    $this->vindi_settings = $vindi_settings;

    add_action('wp_enqueue_scripts', array($this, 'frontendFiles'));
    add_action('admin_enqueue_scripts', array($this, 'adminFiles'));
  }

  public function adminFiles() {
    wp_register_script('jquery-mask', plugins_url('/assets/js/jquery.mask.min.js', plugin_dir_path(__FILE__)), array('jquery'), VINDI_VERSION, true);
    wp_register_script('vindi_woocommerce_admin_js', plugins_url('/assets/js/admin.js', plugin_dir_path(__FILE__)), array('jquery', 'jquery-mask'), VINDI_VERSION, true);
    wp_enqueue_script('vindi_woocommerce_admin_js');
    wp_register_style('vindi_woocommerce_admin_style', plugins_url('/assets/css/admin.css', plugin_dir_path(__FILE__)), array(), VINDI_VERSION);
    wp_enqueue_style('vindi_woocommerce_admin_style');
  }

  public function frontendFiles() {
    wp_register_script('imask', plugins_url('/assets/js/imask.min.js', plugin_dir_path(__FILE__)), array(), VINDI_VERSION, true);
    wp_register_script('vindi_woocommerce_frontend_js', plugins_url('/assets/js/frontend.js', plugin_dir_path(__FILE__)), array('jquery', 'imask'), VINDI_VERSION, true);
    wp_enqueue_script('vindi_woocommerce_frontend_js');
    wp_register_style('vindi_woocommerce_style', plugins_url('/assets/css/frontend.css', plugin_dir_path(__FILE__)), array(), VINDI_VERSION);
    wp_enqueue_style('vindi_woocommerce_style');

    wp_localize_script(
      'vindi_woocommerce_frontend_js',
      'vindi_woocommerce',
      array(
        'api_public_key'            => $this->vindi_settings->get_api_key_public(),
        'payment_profiles_endpoint' => 'https://' . ($this->vindi_settings->get_is_active_sandbox() == 'yes' ? 'sandbox-' : '') . 'app.vindi.com.br/api/v1/public/payment_profiles',
      )
    );
  }

}
