<?php
/**
 * Creation and edition of subscriptions with reflection within Vindi
 *
 * Warning, by default, this class does not return any status.
 *
 * @since 1.2.0
 *
 */

class SubscriptionsController {

  /**
   * @var VindiRoutes
   */
  private $routes;

  /**
   * @var CustomerController
   */
  private $customers_controller;

  /**
   * @var VindiSettings
   */
  private $vindi_settings;

  function __construct(VindiSettings $vindi_settings, CustomerController $customers_controller) {
    $this->vindi_settings       = $vindi_settings;
    $this->routes               = $vindi_settings->routes;
    $this->customers_controller = $customers_controller;

    add_action('wp_insert_post', array($this, 'create'), 10, 3);
  }

  /**
   * When the user creates a subscription in Woocomerce, it is created in the Vindi.
   *
   * @since 1.2.0
   * @version 1.2.0
   */
  function create($post_id, $post, $update, $recreated = false) {
    if(!is_admin())
      return;

    // Check if the post is a draft
    if(strpos(get_post_status($post_id), 'draft') !== false)
      return;
    
    // Check if the post is a subscription
    if(get_post_type($post_id) != 'shop_subscription')
      return;

    if(empty(apply_filters('vindi_subscription_created', true, $post_id)))
      return;
    // Check if it's a new post
    // The $update value is unreliable because of the auto_draft functionality
    if(!empty(get_post_meta($post_id, 'vindi_subscription_id', true))) 
      return $this->update($post_id);

    $subscription = wcs_get_subscription($post_id);
    $items        = $subscription->get_items();
    $item         = reset($items);

    if(empty($item))
      return;

    $product      = $item->get_product();
    $user         = $subscription->get_user();

    if(empty($user))
      return;

    $customer     = $this->customers_controller->update($user->ID);

    if(empty($customer))
      return;
  
    $data['customer_id']         = $customer['id'];
    $data['payment_method_code'] = 'bank_slip';

    if(VindiHelpers::is_subscription_type($product) || VindiHelpers::is_variable($product)) {
      $vindi_plan = VindiHelpers::get_plan_from_order_item($item);

      if(empty($vindi_plan))
        return;

      $data['plan_id'] = $vindi_plan;
      $data['code']    = 'WC-' . $post_id;
    }

    $subscriptionData = $this->routes->createSubscription($data);

    if(isset($subscriptionData['id']))
      update_post_meta($post_id, 'vindi_subscription_id', $subscriptionData['id']);

    if(isset($subscriptionData['bill']['id'])) {
      $order = $subscription->get_parent();

      if(empty($order)) {
        WCS_Admin_Meta_Boxes::create_pending_parent_action_request($subscription);

        $order = $subscription->get_parent();
      }
        
      update_post_meta($order->get_id(), 'vindi_bill_id', $subscriptionData['bill']['id']);
    }

    if(isset($subscriptionData['next_billing_at']))
      update_post_meta($post_id, '_schedule_next_payment', date('Y-m-d H:i:s', strtotime($subscriptionData['next_billing_at'])));
  }

  /**
   * When the user trashes a product in Woocomerce, it is deactivated in the Vindi.
   *
   * @since 1.2.0
   * @version 1.2.0
   */
  function update($post_id) {
    if(get_post_status($post_id) != 'wc-cancelled')
      return;

    $subscription_id = get_post_meta($post_id, 'vindi_subscription_id');

    $this->routes->suspendSubscription($subscription_id);
  }

}
