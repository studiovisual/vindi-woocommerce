<?php

use App\Providers\Subscriptions;
use Carbon\Carbon;

class VindiPaymentProcessor
{
    /**
     * Order type is invalid.
     */
    const ORDER_TYPE_INVALID = 0;

    /**
     * Order type is Subscription Payment.
     */
    const ORDER_TYPE_SUBSCRIPTION = 1;

    /**
     * Order type is Single Payment.
     */
    const ORDER_TYPE_SINGLE = 2;

    /**
     * @var WC_Order
     */
    private $order;

    /**
     * @var VindiPaymentGateway
     */
    private $gateway;

    /**
     * @var VindiSettings
     */
    private $vindi_settings;

    /**
     * @var VindiLogger
     */
    private $logger;

    /**
     * @var VindiRoutes
     */
    private $routes;

    /**
     * @var VindiControllers
     */
    private $controllers;

    /**
     * Payment Processor contructor.
     *
     * @param WC_Order $order The order to be processed
     * @param VindiPaymentGateway $gateway Current payment gateway
     * @param VindiSettings $vindi_settings The VindiSettings instance
     * @param VindiControllers $controllers VindiControllers instance
     */
    public function __construct(WC_Order $order, VindiPaymentGateway $gateway, VindiSettings $vindi_settings, VindiControllers $controllers)
    {

        $this->order = $order;
        $this->gateway = $gateway;
        $this->vindi_settings = $vindi_settings;
        $this->logger = $vindi_settings->logger;
        $this->routes = $vindi_settings->routes;
        $this->controllers = $controllers;
        $this->single_freight = $this->vindi_settings->get_option('shipping_and_tax_config') == "yes" ? true : false;

    }

    /**
     * Check if the order contains any subscription.
     *
     * @return int Order type
     */
    public function get_order_type()
    {

        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($this->order, array('any'))) {
            return static::ORDER_TYPE_SUBSCRIPTION;
        }

        return static::ORDER_TYPE_SINGLE;
    }

    /**
     * Find or create a customer within Vindi using the given credentials.
     *
     * @return int Vindi customer id
     */
    public function getCustomer(): int {
        $current_user   = $this->order->get_user();
        $vindi_customer = 0;
        
        if(!empty($current_user))
            $vindi_customer = get_user_meta($current_user->ID, 'vindi_customer_id', true);

        if(!empty($current_user) && empty($vindi_customer))
            $vindi_customer = $this->controllers->customers->update($current_user->ID, $this->order);

        return isset($vindi_customer['id']) ? $vindi_customer['id'] : $vindi_customer;
    }

    /**
     * Build the credit card payment type.
     *
     * @param int $customer_id Vindi customer id
     *
     * @return array
     */
    public function get_cc_payment_type($customer_id)
    {
        if (method_exists($this->gateway, 'verify_user_payment_profile') && $this->gateway->verify_user_payment_profile()) {
            return false;
        }

        return array(
            'customer_id' => $customer_id,
            'holder_name' => sanitize_text_field($_POST['vindi_cc_fullname']),
            'card_expiration' => filter_var($_POST['vindi_cc_monthexpiry'], FILTER_SANITIZE_NUMBER_INT) . '/' . filter_var($_POST['vindi_cc_yearexpiry'], FILTER_SANITIZE_NUMBER_INT),
            'card_number' => filter_var($_POST['vindi_cc_number'], FILTER_SANITIZE_NUMBER_INT),
            'card_cvv' => filter_var($_POST['vindi_cc_cvc'], FILTER_SANITIZE_NUMBER_INT),
            'payment_method_code' => $this->payment_method_code(),
            'payment_company_code' => sanitize_text_field($_POST['vindi_cc_paymentcompany']),
        );
    }

    /**
     * Build the bank slip payment type.
     *
     * @param int $customer_id Vindi customer id
     *
     * @return array
     */
    public function get_bank_slip_payment_type($customer_id)
    {        
        if (method_exists($this->gateway, 'verify_user_payment_profile') && $this->gateway->verify_user_payment_profile()) {
            return false;
        }
        return array(
            'customer_id' => $customer_id,
            'payment_method_code' => $this->payment_method_code(),

        );
    }

    /**
     * Check if payment method is "Credit Card"
     *
     * @return bool
     */
    public function is_cc()
    {
        return 'cc' === $this->gateway->type();
    }

    /**
     * Check if payment method is "Bank Slip"
     *
     * @return bool
     */
    public function is_bank_slip()
    {

        return 'bank_slip' === $this->gateway->type();
    }

    /**
     * Retrieve payment method code
     *
     * @return string Vindi payment method code
     */
    public function payment_method_code()
    {
        return $this->is_cc() ? 'credit_card' : ($this->is_bank_slip() ? 'bank_slip' : 'pix');
    }

    /**
     * Interrupt the payment process and throw an error if needed.
     * Log the message, add it to the order note and send an alert to the user
     *
     * @param string $message The error message
     * @param bool $throw_exception When true an exception is thrown
     *
     * @return bool Always returns false
     *
     * @throws Exception
     */
    public function abort($message, $throw_exception = false)
    {
        $this->order->add_order_note($message);
        wc_add_notice($message, 'error');
        if ($throw_exception) {
            throw new Exception($message);
        }

        return false;
    }

    /**
     * Check if the order type is valid and process it.
     *
     * @return array|void
     *
     * @throws Exception
     */
    public function process() {
        switch ($orderType = $this->get_order_type()) {
            case static::ORDER_TYPE_SINGLE:
            case static::ORDER_TYPE_SUBSCRIPTION:

                return $this->processOrder();
            case static::ORDER_TYPE_INVALID:
            default:

                return $this->abort(__('Falha ao processar carrinho de compras. Verifique os itens escolhidos e tente novamente.', VINDI), true);
        }
    }

    /**
     * Process current order.
     *
     * @return array
     *
     * @throws Exception
     */
    function processOrder() {
        $customer = $this->getCustomer();
        $user = wp_get_current_user();

        if (!empty($customer)) {
            $vindiCustomer = $this->routes->findCustomerById($customer);

            if (!$vindiCustomer) {
                $user = wp_get_current_user();
                delete_user_meta( $user->ID, 'vindi_customer_id' );
                $customer = '';
            } elseif (is_null($vindiCustomer['registry_code']) || is_null($vindiCustomer['email'])) {
                $customerClass = new CustomerController($this->vindi_settings);
                $customerClass->delete($user->ID);
                $customer = $customerClass->create($user->ID, null, true);
            }
        }

        if(empty($customer)) {
            $customerClass = new CustomerController($this->vindi_settings);
            $customer = $customerClass->create($user->ID, null, true);

            if (!$customer)
                $this->abort(__('Não foi possível obter os dados do cliente.', VINDI), true);
        }

        $gateway_token = ''; 
 
        if($this->is_cc())
            $gateway_token = sanitize_text_field($_POST['vindi_cc_gateway_token']);

        $this->check_trial_and_single_product();

        $bills                           = [];
        $order_post_meta                 = [];
        $bill_products                   = [];
        $subscription_products           = [];
        $subscriptions_ids               = [];
        $wc_subscriptions_ids            = [];
        $subscriptions_grouped_by_period = array();
        $order_items                     = $this->order->get_items();

        foreach($order_items as $order_item) {
            $product = $order_item->get_product();
            
            if(VindiHelpers::is_subscription_type($product)) {
                $product_id = $product->id;

                if(VindiHelpers::is_variable($product))
                    $product_id = $order_item['variation_id'];

                $period   = get_post_meta($product_id, '_subscription_period', true);
                $interval = get_post_meta($product_id, '_subscription_period_interval', true);

                $subscriptions_grouped_by_period[$period . $interval][] = $order_item;
                array_push($subscription_products, $order_item);

                continue;
            }

            array_push($bill_products, $order_item);
        }

        $this->check_multiple_subscriptions_of_same_period($subscriptions_grouped_by_period);
        
        foreach($subscription_products as $subscription_order_item) {
            if(empty($subscription_order_item))
                continue;

            try {
                $subscription       = $this->create_subscription($customer, $subscription_order_item, $gateway_token);
                $subscription_id    = $subscription['id'];
                $wc_subscription_id = $subscription['wc_id'];

                array_push($subscriptions_ids, $subscription_id);
                array_push($wc_subscriptions_ids, $wc_subscription_id);

                $subscription_bill                            = $subscription['bill'];
                $order_post_meta[$subscription_id]['cycle']   = $subscription['current_period']['cycle'];
                $order_post_meta[$subscription_id]['product'] = $subscription_order_item->get_product()->name;
                $order_post_meta[$subscription_id]['bill']    = $this->create_bill_meta_for_order($subscription_bill);
                $bills[]                                      = $subscription['bill'];
                
                if($message = $this->cancelDeniedBillStatus($subscription['bill']))
                    throw new Exception($message);

                update_post_meta($wc_subscription_id, 'vindi_subscription_id', $subscription_id);

                continue;
            } 
            catch(Exception $err) {
                $message = $err->getMessage();

                $this->cancelSubscriptionsOrders($wc_subscriptions_ids, $subscriptions_ids, $message);
            }
        }

        if(!empty($bill_products)) {
            try {
                $single_payment_bill = $this->create_bill($customer, $bill_products);
                $order_post_meta['single_payment']['product'] = 'Produtos Avulsos';
                $order_post_meta['single_payment']['bill']    = $this->create_bill_meta_for_order($single_payment_bill);
                $bills[]                                      = $single_payment_bill;

                if($message = $this->cancelDeniedBillStatus($single_payment_bill)) {
                    $this->order->update_status('cancelled', __($message, VINDI));

                    if($subscriptions_ids)
                        $this->suspendSubscriptions($subscriptions_ids);

                    $this->cancel_bills($bills, __('Algum pagamento do pedido não pode ser processado', VINDI));
                    $this->abort(__($message, VINDI), true);
                }
            } 
            catch(Exception $err) {
                $this->logger->log(sprintf('Deu erro na criação da conta %s', $single_payment_bill));

                $this->abort(__('Não foi possível criar o pedido.', VINDI), true);
            }
        }

        update_post_meta($this->order->id, 'vindi_order', $order_post_meta);
        WC()->session->__unset('current_payment_profile');
        WC()->session->__unset('current_customer');
        remove_action('woocommerce_scheduled_subscription_payment', 'WC_Subscriptions_Manager::prepare_renewal');

        return $this->finish_payment($bills);
    }

    private function check_trial_and_single_product()
    {
        if ($this->order_has_trial_and_simple_product()) {
            $message = __('Não é possível comprar produtos simples e assinaturas com trial no mesmo pedido!', VINDI);
            $this->order->update_status('failed', $message);
            wc_add_notice($message, 'error');

            throw new Exception($message);
        }
    }

    private function check_multiple_subscriptions_of_same_period($subscriptions_grouped_by_period)
    {
        foreach ($subscriptions_grouped_by_period as $subscription_group) {
            if (count($subscription_group) > 1) {
                $msg = 'Não é permitido criar um único pedido com múltiplas assinaturas de mesma periodicidade';
                $this->abort(__($msg, VINDI), true);
            }
        }
    }

    /**
     * Create a payment profile for the customer
     *
     * @param int $customer_id Vindi customer id
     *
     * @throws Exception
     */
    protected function create_payment_profile($customer_id) {
        $cc_info = $this->get_cc_payment_type($customer_id);

        if(false === $cc_info)
            return;

        $payment_profile = $this->routes->createCustomerPaymentProfile($cc_info);

        if(empty($payment_profile) || isset($payment_profile['errors'])) {
            $message = __('Falha ao registrar o método de pagamento. Verifique os dados e tente novamente.', VINDI);

            $this->abort(
                $message,
                 true
            );
        }

        if($this->gateway->verify_method())
            $this->verify_payment_profile($payment_profile['id']);

        return $payment_profile['gateway_token'];
    }

    /**
     * Check if the payment profile is valid
     *
     * @param int $payment_profile_id The customer's payment profile id
     *
     * @throws Exception
     */
    protected function verify_payment_profile($payment_profile_id) {
        if(!$this->routes->verifyCustomerPaymentProfile($payment_profile_id))
            $this->abort(__('Não foi possível realizar a verificação do seu cartão de crédito!', VINDI), true);
    }

    /**
     * Get the subscription/product expiration time in months
     *
     * @param WC_Order_Item_Product $item
     *
     * @return int
     */
    private function get_cycle_from_product_type($item)
    {
        $cycles = null;
        $product = method_exists($item, 'get_product') ? $item->get_product() : false;

        if ($item['type'] == 'sign_up_fee') {
            return 1;
        }

        if ($item['type'] == 'shipping' || $item['type'] == 'tax') {
            if ($this->vindi_settings->get_shipping_and_tax_config()) {
                return 1;

            }
            return $this->single_freight ? 1 : null;

        } elseif (!VindiHelpers::is_subscription_type($product)) {
            return 1;
        }

        if ($product) {
            $cycles = (int) get_post_meta($product->get_id(), '_subscription_length', true);
        }
        return $cycles > 0 ? $cycles : null;
    }

    /**
     * Check if the product needs to be shipped only once
     *
     * @param WC_Product $product Woocommerce product
     *
     * @return bool
     */
    private function is_one_time_shipping($product)
    {
        return get_post_meta($product->get_id(), '_subscription_one_time_shipping', true) == 'yes';
    }

    /**
     * Build the array of product(s), shipping, tax and discounts to send to Vindi
     *
     * @param string $order_type Order type. Possible values 'bill' and 'subscription', defaults to 'bill'
     * @param WC_Order_Item_Product|WC_Order_Item_Product[] $product The product to be built. If the order type is 'bill' this will be an array of WC_Order_Item_Product,
     * if it's 'subscription' it will be a single WC_Order_Item_Product.
     *
     * @return array
     *
     * @throws Exception
     */
    public function build_product_items($order_type = 'bill', $product = [])
    {
        $call_build_items = "build_product_items_for_{$order_type}";

        if (false === method_exists($this, $call_build_items)) {
            $this->abort(__("Ocorreu um erro ao gerar o seu pedido!", VINDI), true);
        }

        $product_items = [];
        $order_items = [];

        if ('bill' === $order_type) {
            $order_items = $this->build_product_from_order_item($order_type, $product);
        } else {
            $order_items[] = $this->build_product_from_order_item($order_type, $product);
        }
        $order_items[] = $this->build_shipping_item($order_items);
        $order_items[] = $this->build_tax_item($order_items);

        $order_items[] = $this->build_sign_up_fee_item($order_items);

        if ('bill' === $order_type) {
            $order_items[] = $this->build_discount_item_for_bill($order_items);
            $order_items[] = $this->build_interest_rate_item($order_items);
        }

        foreach ($order_items as $order_item) {
            if (!empty($order_item)) {
                $newProduct = $this->$call_build_items($order_item);
                $product_items[] = $newProduct;
            }
            continue;
        }

        if (empty($product_items)) {
            return $this->abort(__('Falha ao recuperar informações sobre o produto na Vindi. Verifique os dados e tente novamente.', VINDI), true);
        }

        return $product_items;
    }

    /**
     * Retrives the product(s) information. Adds the vindi_id, the type and the price to it.
     *
     * @param string $order_type ('subscription' or 'bill')
     * @param WC_Order_Item_Product|WC_Order_Item_Product[] $order_items. Subscriptions will pass only one order_item and
     * Bills will pass an array with all the products to be processed
     *
     * @return array
     */
    protected function build_product_from_order_item($order_type, $order_items) {
        if('bill' === $order_type) {
            foreach($order_items as $key => $order_item) {
                $product                       = $order_item->get_product();
                $order_items[$key]['type']     = 'product';
                $order_items[$key]['vindi_id'] = $this->routes->findProductByCode('WC-' . $product->id)['id'];
                $order_items[$key]['price']    = (float) $order_items[$key]['subtotal'] / $order_items[$key]['qty'];

            }

            return $order_items;
        }

        $product             = $order_items->get_product();
        $order_items['type'] = 'product';
        $product_id          = $product->id;

        if(VindiHelpers::is_variable($product))
            $product_id = $order_items['variation_id'];

        $get_vindi               = get_post_meta($product_id, 'vindi_product_id', true);
        $order_items['vindi_id'] = $get_vindi ? $get_vindi : $product->vindi_id;

        if($this->subscription_has_trial($product)) {
            $matching_item        = $this->get_trial_matching_subscription_item($order_items);
            $order_items['price'] = (float) $matching_item['subtotal'] / $matching_item['qty'];
        }
        else
            $order_items['price'] = (float) $order_items['subtotal'] / $order_items['qty'];
        
        
        return $order_items;
    }

    /**
     * Create the sign-up fee item to be added to the bill.
     *
     * @param WC_Order_Item_Product[] $order_items. Array with all items to add
     * the respective delivered value
     *
     * @return array
     */
    protected function build_sign_up_fee_item($order_items)
    {
        foreach ($order_items as $order_item) {
            if(!is_a($order_item, 'WC_Order_Item_Product'))
                continue;
                
            $product = method_exists($order_item, 'get_product') ? $order_item->get_product() : false;
            
            if(!$product)
                continue;

            $sign_up_fee = $product->get_meta('_subscription_sign_up_fee');

            if ($sign_up_fee != null && $sign_up_fee > 0) {
                
                $item = $this->routes->findOrCreateProduct("[WC] Taxa de adesão", "WC-SUF");
                
                $sign_up_fee_item = array(
                    'type' => 'sign_up_fee',
                    'vindi_id' => $item['id'],
                    'price' => (float) $sign_up_fee,
                    'qty' => 1,
                );
                
                $order_item['price'] -= $sign_up_fee;
                
                return $sign_up_fee_item;
            }
        }
    }

    /**
     * Create the shipping item to be added to the bill.
     *
     * @param WC_Order_Item_Product[] $order_items. Array with all items to add
     * the respective delivered value // TODO.
     *
     * @return array
     */
    protected function build_interest_rate_item($order_items)
    {

        $interest_rate_item = [];

        if (!($this->is_cc() && $this->installments() > 1 && $this->gateway->is_interest_rate_enabled())) {
            return $interest_rate_item;
        }

        $interest_rate = $this->gateway->get_interest_rate();
        $installments = $this->installments();
        $cart = WC()->cart;
        $cart_total = $cart->total;
        foreach ($cart->get_fees() as $index => $fee) {
            if ($fee->name == __('Juros', VINDI)) {
                $cart_total -= $fee->amount;
            }
        }
        $total_price = $cart_total * (1 + (($interest_rate / 100) * ($installments - 1)));
        $interest_price = (float) $total_price - $cart_total;

        $item = $this->routes->findOrCreateProduct("Juros", 'wc-interest-rate');
        $interest_rate_item = array(
            'type' => 'interest_rate',
            'vindi_id' => $item['id'],
            'price' => $interest_price,
            'qty' => 1,
        );
        return $interest_rate_item;
    }

    /**
     * Create the shipping item to be added to the bill.
     *
     * @param WC_Order_Item_Product[] $order_items. Array with all items to add
     * the respective delivered value // TODO.
     *
     * @return array
     */
    protected function build_shipping_item($order_items)
    {
        $shipping_item = [];
        $shipping_method = $this->order->get_shipping_method();
        $get_total_shipping = $this->order->get_total_shipping();

        if (empty($shipping_method)) {
            return $shipping_item;
        }

        foreach ($order_items as $order_item) {
            $wc_subscription = VindiHelpers::get_matching_subscription($this->order, $order_item);
            $product = $order_item->get_product();

            $one_time_shipping = $this->is_one_time_shipping($product) && $this->single_freight ? true : false;

            if (VindiHelpers::is_subscription_type($product) && !$one_time_shipping) {
                $shipping_method = $wc_subscription->get_shipping_method();
                $get_total_shipping = $wc_subscription->get_total_shipping();
            }

            if ($product->needs_shipping()) {
                $item = $this->create_shipping_product($shipping_method);
                $shipping_item = array(
                    'type' => 'shipping',
                    'vindi_id' => $item['id'],
                    'price' => $get_total_shipping,
                    'qty' => 1,
                );
            }
        }
        return $shipping_item;
    }
    
    private function create_shipping_product($shipping_method)
    {
        return $this->routes->findOrCreateProduct(
            sprintf("Frete (%s)", $shipping_method),
            sanitize_title($shipping_method)
        );
    }

    /**
     * Create the tax item.
     *
     * @param WC_Order_Item_Product[] $order_items. Products to calculate the tax amount
     *
     * @return array
     */
    protected function build_tax_item($order_items)
    {

        $total_tax = 0;
        $taxItem = [];

        foreach ($order_items as $order_item) {
            if (get_option('woocommerce_tax_total_display') === 'itemized') {
                if (!empty($order_item['type'])) {
                    if ($order_item['type'] === 'shipping') {
                        $total_tax += (float) ($this->order->get_shipping_tax());
                    } else {
                        $total_tax += (float) ($order_item->get_total_tax());
                    }
                }
            } else {
                !empty($order_item['type']) && $total_tax += ($order_item['type'] === 'shipping' ? (float) $this->order->get_shipping_tax() : (float) $order_item->get_total_tax());
            }
        }

        if ($total_tax > 0) {
            $item = $this->routes->findOrCreateProduct("Taxa", 'wc-tax');
            $taxItem = array(
                'type' => 'tax',
                'vindi_id' => $item['id'],
                'price' => (float) $total_tax,
                'qty' => 1,
            );
        }
        return $taxItem;
    }

    /**
     * Create discount item for a bill.
     *
     * @param WC_Order_Item_Product[] $order_items. All the products to calculate
     * the discount amount
     *
     * @return array
     */
    protected function build_discount_item_for_bill($order_items)
    {
        $discount_item = [];
        $coupons = array_values($this->vindi_settings->woocommerce->cart->get_coupons());
        $bill_total_discount = 0;
        foreach ($order_items as $order_item) {
            if (isset($order_item['subtotal']) && isset($order_item['total'])) {
                $bill_total_discount += (float) ($order_item['subtotal'] - $order_item['total']);
            }
        }

        if (empty($bill_total_discount)) {
            return $discount_item;
        }

        $item = $this->routes->findOrCreateProduct("Cupom de desconto", 'wc-discount');
        $discount_item = array(
            'type' => 'discount',
            'vindi_id' => $item['id'],
            'price' => (float) $bill_total_discount * -1,
            'qty' => 1,
        );

        return $discount_item;
    }

    /**
     * Create bill product item to send to the Vindi API.
     *
     * @param WC_Order_Item_Product $order_item. The product to be converted to
     * the correct product format.
     *
     * @return array
     */
    protected function build_product_items_for_bill($order_item)
    {
        $item = array(
            'product_id' => $order_item['vindi_id'],
            'quantity' => $order_item['qty'],
            'pricing_schema' => array(
                'price' => $order_item['price'],
                'schema_type' => 'per_unit',
            ),
        );

        if (
            'discount' == $order_item['type'] || 'shipping' == $order_item['type'] ||
            'tax' == $order_item['type'] || 'interest_rate' == $order_item['type'] || 'sign_up_fee' == $order_item['type']
        ) {
            $item = array(
                'product_id' => $order_item['vindi_id'],
                'amount' => $order_item['price'],
            );
        }

        return $item;
    }

    /**
     * Create subscription product item to send to the Vindi API.
     *
     * @param WC_Order_Item_Product $order_item. The product to be converted to
     * the correct product format.
     *
     * @return array
     */
    protected function build_product_items_for_subscription($order_item)
    {

        $plan_cycles = $this->get_cycle_from_product_type($order_item);
        $product_item = array(
            'product_id' => $order_item['vindi_id'],
            'quantity' => $order_item['qty'],
            'cycles' => $plan_cycles,
            'pricing_schema' => array(
                'price' => $order_item['price'],
                'schema_type' => 'per_unit',
            ),
        );

        if (!empty($this->order->get_total_discount()) && $order_item['type'] == 'line_item') {
            $product_item['discounts'] = [];
            $coupons = array_values($this->vindi_settings->woocommerce->cart->get_coupons());
            foreach ($coupons as $coupon) {
                if ($this->coupon_supports_product($order_item, $coupon)) {
                    $product_item['discounts'][] = $this->build_discount_item_for_subscription($coupon, $plan_cycles);
                }
            }
        }
        return $product_item;
    }

    /**
     * Verify that the coupon can be applied to the current product.
     *
     * @param WC_Order_Item_Product $order_item. The product.
     * @param WC_Coupon $coupon. The coupon.
     *
     * @return bool
     */
    protected function coupon_supports_product($order_item, $coupon)
    {
        $product_id = $order_item->get_product()->id;
        $included_products = $coupon->get_product_ids();
        $excluded_products = $coupon->get_excluded_product_ids();

        if (!empty($excluded_products)) {
            if (in_array($product_id, $excluded_products)) {
                // The coupon doesn't support the current product
                return false;
            }
        }
        if (!empty($included_products)) {
            if (!in_array($product_id, $included_products)) {
                // The coupon doesn't support the current product
                return false;
            }
        }

        return true;
    }

    /**
     * Create a discount item for a subscription.
     *
     * @param WC_Coupon $coupon. The coupon to be added.
     * @param int $plan_cycles. The amount of cycles that the subscription has.
     *
     * @return array
     */
    protected function build_discount_item_for_subscription($coupon, $plan_cycles = 0)
    {
        $discount_item = [];

        $amount = $coupon->get_amount();
        $discount_type = $coupon->get_discount_type();
        if ($discount_type == 'fixed_cart') {
            $discount_item['discount_type'] = 'amount';
            $discount_item['amount'] = $amount / $this->order->get_item_count();
            $discount_item['cycles'] = 1;
            return $discount_item;
        } elseif (strpos($discount_type, 'fixed') !== false) {
            $discount_item['discount_type'] = 'amount';
            $discount_item['amount'] = $amount;
        } elseif (strpos($discount_type, 'percent') !== false ||
                  strpos($discount_type, 'recurring_percent') !== false) {
            $discount_item['discount_type'] = 'percentage';
            $discount_item['percentage'] = $amount;
        }
        $discount_item['cycles'] = $this->config_discount_cycles($coupon, $plan_cycles);

        return $discount_item;
    }

    /**
     * Configure the discount cycles that the coupon will be used.
     *
     * @param WC_Coupon $coupon. The coupon to be added.
     * @param int $plan_cycles. The amount of cycles that the subscription has.
     *
     * @return int
     */
    protected function config_discount_cycles($coupon, $plan_cycles = 0)
    {
        $get_plan_length =
        function ($cycle_count, $plan_cycles) {
            if (!$cycle_count) {
                return null;
            }

            if ($plan_cycles) {
                return min($plan_cycles, $cycle_count);
            }
            return $cycle_count;
        };
        
        $cycle_count = get_post_meta($coupon->id, 'cycle_count', true);

        if ($coupon->get_discount_type() == 'recurring_percent') {
            $cycle_count = WC_Subscriptions_Coupon::get_coupon_limit($coupon->id);
        }

        switch ($cycle_count) {
            case '0':
                return null;
            default:
                return $get_plan_length($cycle_count, $plan_cycles);
        }
    }

    /**
     * Retrieve number of installments from order.
     * If the order contains subscriptions the return will be 1,
     * else it will be the amount selected by the user during checkout.
     *
     * @return int
     */
    protected function installments()
    {
        if (
            'credit_card' == $this->payment_method_code() &&
            isset($_POST['vindi_cc_installments'])
        ) {
            return filter_var($_POST['vindi_cc_installments'], FILTER_SANITIZE_NUMBER_INT);
        }

        return 1;
    }

    /**
     * Retrieve Plan for Vindi Subscription.
     *
     * @param WC_Order_Item_Product $order_item
     *
     * @return int|bool
     */
    public function get_plan_from_order_item($order_item)
    {
        $product = $order_item->get_product();

        if (isset($order_item['variation_id']) && $order_item['variation_id'] != 0) {
            $vindi_plan = get_post_meta($order_item['variation_id'], 'vindi_plan_id', true);
            if (empty($vindi_plan) || !is_numeric($vindi_plan) || is_null($vindi_plan) || $vindi_plan == 0) {
                $vindi_plan = get_post_meta($product->get_id(), 'vindi_plan_id', true);
            }
        } else {
            $vindi_plan = get_post_meta($product->get_id(), 'vindi_plan_id', true);
        }

        if (VindiHelpers::is_subscription_type($product) and !empty($vindi_plan)) {
            return $vindi_plan;
        }

        $this->abort(__('O produto selecionado não é uma assinatura.', VINDI), true);
    }

    /**
     * Create a subscription within Vindi
     *
     * @param int $customer_id ID of the customer that placed the order
     * @param WC_Order_item_product $order_item Item to add to the subscription
     * @param string $gateway_token The token to add to the subscription
     *
     * @return array
     * @throws Exception
     */
    protected function create_subscription($customer_id, $order_item, $gateway_token = '') {
        if($order_item == null || empty($order_item))
            return;

        $data['customer_id'] = $customer_id;
        $data['payment_method_code'] = $this->payment_method_code();
        $data['installments'] = $this->installments();
        $data['product_items'] = array();

        if(!empty($gateway_token))
            $data['payment_profile']['gateway_token'] = $gateway_token;

        $product = $order_item->get_product();

        if(VindiHelpers::is_subscription_type($product) || VindiHelpers::is_variable($product)) {
            $vindi_plan = $this->get_plan_from_order_item($order_item);
            $data['plan_id'] = $vindi_plan;
            $wc_subscription_id = VindiHelpers::get_matching_subscription($this->order, $order_item)->id;
            $data['code'] = strpos($wc_subscription_id, 'WC') > 0 ? $wc_subscription_id : 'WC-' . $wc_subscription_id;
        }

        $data['product_items'] = array_merge($data['product_items'], $this->build_product_items('subscription', $order_item));

        $validity = $this->get_validity();

        if (!is_null($validity)) {
            $data['start_at'] = $validity;
        }

        $subscription = $this->routes->createSubscription($data);
        
        if (!is_null($validity) && isset($subscription['id']) && !empty($subscription['id'])) {
            $subscription['bill'] = $this->advancePayment($subscription['id']);
        }

        if(!isset($subscription['id']) || empty($subscription['id'])) {
            $message = sprintf(__('Pagamento Falhou. (%s)', VINDI), $this->vindi_settings->api->last_error);
            throw new Exception($message);
        }

        $subscription['wc_id'] = $wc_subscription_id;

        if (isset($subscription['bill']['id'])) {
            update_post_meta($this->order->id, 'vindi_bill_id', $subscription['bill']['id']);
        }

        $this->cancelOldSubscriptions();

        return $subscription;
    }

    public function advancePayment($subscription_id) {
        if (empty($subscription_id))
            return null;

        $periodId = $this->routes->getPeriod($subscription_id);

        if (empty($periodId))
            return null;

        return $this->routes->advancePayment($periodId);
    }

    public function get_validity() {
        $subscriptions = $this->routes->getSubscriptions(Subscriptions::getVindiUserId( get_current_user_id()));

        if (isset($subscriptions['subscriptions'])) {
            foreach ($subscriptions['subscriptions'] as $subscription) {
                if ($subscription['status'] === "canceled" || $subscription['status'] === "expired")
                    continue;

                $perild_id = $this->routes->getPeriod($subscription['id']);
                $bill_id = $this->routes->getBillbyPeriod($perild_id);
                $bill_status = $this->routes->getBillStatus($bill_id);

                if ($bill_status !== "paid")
                    continue;

                if (isset($subscription['next_billing_at'])) {
                    $currentTime = Carbon::now();
                    $vindiDate = Carbon::parse($subscription['next_billing_at'])->startOfDay();

                    if ($vindiDate->lte($currentTime))
                        continue;

                    $currentTime->addYears(5);
                    
                    if ($vindiDate->gt($currentTime)) {
                        $currentTime->subDays(2);
                        return $currentTime->format("Y-m-d");
                    }

                    return $vindiDate->format("Y-m-d");
                }
            }
        }

        return null;
    }

    public function cancelOldSubscriptions() {
        $subscriptions = $this->routes->getSubscriptions(Subscriptions::getVindiUserId( get_current_user_id()));

        if (isset($subscriptions['subscriptions'][0])) {
            unset($subscriptions['subscriptions'][0]);
            foreach ($subscriptions['subscriptions'] as $subscription) {
                if ($subscription['status'] === 'future' || $subscription['status'] === 'active') {
                    $suspended = $this->routes->suspendSubscription($subscription['id']);

                    if (isset($suspended['subscription']['code'])) {
                        $wcSubscriptionId = str_replace('WC-', '', $suspended['subscription']['code']);
                        $wcSubscription = wcs_get_subscription($wcSubscriptionId);

                        if ($wcSubscription && !$wcSubscription->has_status('cancelled')) {
                            $wcSubscription->update_status('cancelled');
                        }
                    }
                }
            }
        }
    }

    /**
     * Cancel subscriptions and order in case of error on payment
     *
     * @param array $wc_subscriptions_ids Array with the IDs of woocommerce subscriptions that must be canceled
     * @param array $subscriptions_ids Array with the IDs of vindi subscriptions that must be suspended
     * @param string $message Error message
     */
    private function cancelSubscriptionsOrders($wc_subscriptions_ids, $subscriptions_ids, $message) {
        $this->suspendSubscriptions($subscriptions_ids);
        
        foreach($wc_subscriptions_ids as $wc_subscription_id) {
            $wc_subscription = wcs_get_subscription($wc_subscription_id);

            $wc_subscription->update_status('cancelled', __($message, VINDI));
        }
        
        $this->order->update_status('cancelled', __($message, VINDI));
        
        if($message)
            $this->abort(__(sprintf('Problema ao processar o pedido: %s', $message), VINDI), true);
    }

    /**
     * Create a bill within Vindi
     *
     * @param int $customer_id ID of the customer that placed the order
     * @param WC_Order_item_product[] $order_items Array with items to add to the bill.
     *
     * @return int
     * @throws Exception
     */
    protected function create_bill($customer_id, $order_items)
    {

        $data = array(
            'customer_id' => $customer_id,
            'payment_method_code' => $this->payment_method_code(),
            'bill_items' => $this->build_product_items('bill', $order_items),
            'code' => $this->order->id,
            'installments' => $this->installments(),
        );

        $bill = $this->routes->createBill($data);

        if (!$bill) {
            $this->logger->log(sprintf('Erro no pagamento do pedido %s.', $this->order->id));
            $message = sprintf(__('Pagamento Falhou. (%s)', VINDI), $this->vindi_settings->api->last_error);
            $this->order->update_status('failed', $message);

            throw new Exception($message);
        }

        if ($bill['id']) {
            $this->logger->log(sprintf('Update Bill: %s', json_encode($bill)));
            update_post_meta($this->order->id, 'vindi_bill_id', $bill['id']);
        }
        return $bill;
    }

    /**
     * Create bill meta array to add to the order
     *
     * @param array $bill The bill returned from Vindi API
     *
     * @return array
     */
    protected function create_bill_meta_for_order($bill)
    {

        $bill_meta['id'] = $bill['id'];
        $bill_meta['status'] = $bill['status'];
        if (isset($bill['charges']) && count($bill['charges'])) {
            $bill_meta['bank_slip_url'] = $bill['charges'][0]['print_url'];
        }
        return $bill_meta;
    }

    /**
     * Check if bill was rejected by Vindi
     *
     * @param array $bill The bill returned from Vindi API
     *
     * @return bool|string
     */
    protected function cancelDeniedBillStatus($bill) {
        if(empty($bill['charges']))
            return false;

        $last_charge        = end($bill['charges']);
        $transaction_status = $last_charge['last_transaction']['status'];
        $denied_status      = [
            'rejected' => 'Não foi possível processar seu pagamento. Por favor verifique os dados informados. ',
            'failure'  => 'Ocorreu um erro ao tentar aprovar a transação, tente novamente.'
        ];

        if(array_key_exists($transaction_status, $denied_status)) {
            if($this->is_cc() && $last_charge['last_transaction']['gateway_message'] != null)
                return apply_filters('vindi_error_message',  $denied_status[$transaction_status] . '<br />' . $last_charge['last_transaction']['gateway_message'], $last_charge['last_transaction']['gateway_response_code']);

            return $denied_status[$transaction_status];
        }

        return false;
    }

    /**
     * Suspend subscription within Vindi
     *
     * @param array $subscriptions_ids Array with the IDs of subscriptions that were processed
     */
    protected function suspendSubscriptions($subscriptions_ids) {
        foreach($subscriptions_ids as $subscription_id)
            $this->routes->suspendSubscription($subscription_id, true);
    }

    /**
     * Suspend bills within Vindi
     *
     * @param array $bills Array with the bills that were processed
     */
    protected function cancel_bills($bills, $comments = '')
    {
        foreach ($bills as $bill) {
            $this->routes->deleteBill($bill['id'], $comments);
        }
    }

    /**
     * Finish the payment
     *
     * @param array $bills Order bills returned from Vindi API
     *
     * @return array
     */
    protected function finish_payment($bills)
    {
        $this->vindi_settings->woocommerce->cart->empty_cart();

        $bills_status = [];
        foreach ($bills as $bill) {
            if ($bill['status'] == 'paid') {
                $data_to_log = sprintf('O Pagamento da fatura %s do pedido %s foi realizado com sucesso pela Vindi.', $bill['id'], $this->order->id);
                $status_message = __('O Pagamento foi realizado com sucesso pela Vindi.', VINDI);
            } else {
                $data_to_log = sprintf('Aguardando pagamento da fatura %s do pedido %s pela Vindi.', $bill['id'], $this->order->id);
                $status_message = __('Aguardando pagamento do pedido.', VINDI);
            }
            array_push($bills_status, $bill['status']);
            $this->logger->log($data_to_log);
        }
        if (sizeof($bills_status) == sizeof(array_keys($bills_status, 'paid'))) {
            $status = $this->vindi_settings->get_return_status();
        } else {
            $status = 'pending';
        }
        $this->order->update_status($status, $status_message);

        return array(
            'result'   => 'success',
            'bills'    => $bills,
            'redirect' => $this->order->get_checkout_order_received_url(),
        );
    }

    /**
     * Find or create the product within Vindi
     * and add the vindi id to the product array
     *
     * @param WC_Order_Item_Product $order_item
     *
     * @return WC_Product Woocommerce product array with a vindi id
     */
    protected function get_product($order_item)
     {
        $product = $order_item->get_product();
        $product_id = $order_item->get_id();
        $vindi_product_id = get_post_meta($product, 'vindi_product_id', true);

        if (!$vindi_product_id) {
            $vindi_product = 63;
            if (!VindiHelpers::is_subscription_type($product)) {
                $vindi_product = $this->controllers->products->create($product_id, '', '', true);
            } else {
                $vindi_product = $this->controllers->plans->create($product_id, '', '', true);
            }
            $vindi_product_id = $vindi_product['id'];
        }

        if (empty($vindi_product_id) || !$vindi_product_id) {
            $vindi_product_id = $this->routes->findProductByCode('WC-' . $product->id)['id'];

        }
        
        $product->vindi_id = (int) $vindi_product_id > 0 ? $vindi_product_id : 63;
        if($product->id === null) $product->id = 63;
      
        return $product;
    }

    /**
     * Check if the order has a subscription with trial and simple products.
     *
     * @since 1.0.0
     * @return bool
     */
    public function order_has_trial_and_simple_product()
    {
        $has_trial = false;
        $has_simple_product = false;
        $order_items = $this->order->get_items();
        foreach ($order_items as $order_item) {
            $product = $order_item->get_product();
            if ($this->subscription_has_trial($product)) {
                $has_trial = true;
                if ($has_simple_product) {
                    return true;
                }

            } else {
                $has_simple_product = true;
                if ($has_trial) {
                    return true;
                }

            }
        }
        return $has_trial && $has_simple_product;
    }

    /**
     * Check if the subscription has a trial period
     *
     * @param WC_Product $product
     * @return bool
     */
    protected function subscription_has_trial(WC_Product $product)
    {
        return VindiHelpers::is_subscription_type($product) && class_exists('WC_Subscriptions_Product') && WC_Subscriptions_Product::get_trial_length($product->get_id()) > 0;
    }

    /**
     * Get trial item quantity, subtotal and total price.
     *
     * @param WC_Order_Item_Product $order_item
     * @return WC_Order_Item_Product
     */
    protected function get_trial_matching_subscription_item(WC_Order_Item_Product $order_item)
    {
        $subscription = VindiHelpers::get_matching_subscription($this->order, $order_item);
        $matching_item = VindiHelpers::get_matching_subscription_item($subscription, $order_item);
        return $matching_item;
    }

}
