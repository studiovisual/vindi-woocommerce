<?php

/**
 * Merging all the controllers needed to
 * communication between the Vindi API.
 *
 * @return void;
 */

class VindiControllers {

  /**
   * @var CustomerController
   */
  public $customers;

  /**
   * @var PlansController
   */
  public $plans;

  /**
   * @var ProductController
   */
  public $products;

  /**
   * @var SubscriptionsController
   */
  public $subscriptions;

  function __construct(VindiSettings $vindi_settings) {
    $this->includes();

    $this->customers      = new CustomerController($vindi_settings);
    $this->plans          = new PlansController($vindi_settings);
    $this->products       = new ProductController($vindi_settings);
    $this->subscriptions  = new SubscriptionsController($vindi_settings, $this->customers);
  }


  function includes(){
    require_once WC_Vindi_Payment::getPath() . '/controllers/CustomerController.php';
    require_once WC_Vindi_Payment::getPath() . '/controllers/PlansController.php';
    require_once WC_Vindi_Payment::getPath() . '/controllers/ProductController.php';
    require_once WC_Vindi_Payment::getPath() . '/controllers/SubscriptionsController.php';
  }

}
