<?php

if(!defined('ABSPATH'))
  exit;

/**
 * Vindi Payment PIX Gateway class
 *
 * Extended by individual payment gateways to handle payments
 *
 * @class   VindiPixGateway
 * @extends VindiPaymentGateway
 * @since   1.6.0
 */

class VindiPixGateway extends VindiPaymentGateway {
   
  /**
   * vindi_settings
   *
   * @var VindiSettings
   */
  public $vindi_settings;

  /**
   * controllers
   *
   * @var VindiControllers
   */
  public $controllers;
  
  /**
   * Constructor for the gateway
   *
   * @param  VindiSettings $vindi_settings
   * @param  VindiControllers $controllers
   * 
   * @return void
   */
  public function __construct(VindiSettings $vindi_settings, VindiControllers $controllers) {
    $this->id                   = 'vindi-pix';
    $this->icon                 = apply_filters('vindi_woocommerce_pix_icon', '');
    $this->method_title         = __('Vindi - PIX', VINDI);
    $this->method_description   = __('Aceitar pagamentos via PIX utilizando a Vindi.', VINDI);
    $this->has_fields           = true;

    $this->supports             = array(
      'subscriptions',
      'products',
      'subscription_cancellation',
      'subscription_reactivation',
      'subscription_suspension',
      'subscription_amount_changes',
      'subscription_payment_method_change',
      'subscription_payment_method_change_customer',
      'subscription_payment_method_change_admin',
      'subscription_date_changes',
      'multiple_subscriptions',
      'pre-orders'
    );

    $this->init_form_fields();

    // Load the settings.
    $this->init_settings();

    add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'thankYouPage'));

    parent::__construct($vindi_settings, $controllers);

    $this->title       = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->enabled     = $this->get_option('enabled');

  }
    
  /**
   * Should return payment type for payment processing
   *
   * @return string Payment type for payment processing
   */
  public function type(): string {
    return 'pix';
  }
  
  /**
   * Create form fields in admin
   *
   * @return void
   */
  public function init_form_fields(): void {
    $this->form_fields = array(
      'enabled' => array(
        'title'   => __('Habilitar/Desabilitar', VINDI),
        'label'   => __('Habilitar pagamento via PIX com Vindi', VINDI),
        'type'    => 'checkbox',
        'default' => 'no',
      ),
      'title'   => array(
        'title'       => __('Título', VINDI),
        'type'        => 'text',
        'description' => __('Título que o cliente verá durante o processo de pagamento.', VINDI),
        'default'     => __('PIX', VINDI),
      )
    );
  }
  
  /**
   * Render payment method on frontend
   *
   * @return void
   */
  public function payment_fields(): void {
    $id           = $this->id;
    $user_country = $this->get_country_code();

    if(empty($user_country)) {
      _e('Selecione o País para visualizar as formas de pagamento.', VINDI);

      return;
    }

    if(!$this->routes->acceptPix()) {
      _e('Este método de pagamento não é aceito.', VINDI);

      return;
    }

    if($is_trial = $this->vindi_settings->get_is_active_sandbox())
      $is_trial = $this->routes->isMerchantStatusTrialOrSandbox();
    
    $this->vindi_settings->get_template('pix-checkout.html.php', compact('id', 'is_trial'));
  }
  
  /**
   * Render thank you page
   *
   * @param  int $order_id The id of the order
   * 
   * @return void
   */
  public function thankYouPage(int $order_id): void {
    $order = wc_get_order($order_id);

    if($order->get_payment_method() != 'vindi-pix')
      return;

    $vindi_order = get_post_meta($order_id, 'vindi_order', true);

    $this->vindi_settings->get_template(
        'pix-download.html.php',
        compact('vindi_order')
    );
  }

}
