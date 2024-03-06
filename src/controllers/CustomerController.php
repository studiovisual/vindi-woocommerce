<?php

class CustomerController {

  /**
   * @var VindiSettings
   */
  private $vindi_settings;

  /**
   * @var VindiRoutes
   */
  private $routes;

  function __construct(VindiSettings $vindi_settings) {
    $this->vindi_settings = $vindi_settings;
    $this->routes         = $vindi_settings->routes;

    // Fires immediately after a new user is registered.
    add_action('user_register', array($this, 'create'));
    add_action('user_register', array($this, 'saveExtraFields'), 9);
    add_action('user_new_form', array($this, 'addExtraFields'));
    add_action('user_profile_update_errors', array($this, 'validateExtraFields'));

    // Fires immediately after an existing user is updated.
    add_action('delete_user',    array($this, 'delete'));
    add_action('profile_update', array($this, 'update'));

    add_filter('woocommerce_json_search_found_customers', array($this, 'checkRegistryCode'));
  }

  /**
   * When a new user is created within the WP, it is reflected in the Vindi.
   *
   * @since 1.0.0
   * @version 1.0.0
   */
  function create($user_id, $order = null, $returnId = false) {
    $customer    = new WC_Customer($user_id);
    $user        = $customer->get_data();
    $name        = (!$user['first_name']) ? $user['display_name'] : $user['first_name'] . ' ' . $user['last_name'];
    $notes       = null;
    $cpf_or_cnpj = null;
    $metadata    = null;
    $phones      = [];
    $metadata    = array();

    if($customer->get_meta('billing_cellphone')) {
      $phones[] = array(
        'phone_type' => 'mobile',
        'number'     => preg_replace('/\D+/', '', '55' . $customer->get_meta('billing_cellphone'))
      );
    }

    if($customer->get_billing_phone()) {
      $phones[] = array(
        'phone_type' => 'landline',
        'number'     => preg_replace('/\D+/', '', '55' . $customer->get_billing_phone())
      );
    }

    if('2' === $customer->get_meta('billing_persontype')) {
      // Pessoa jurídica
      $name        = $customer->get_billing_company();
      $cpf_or_cnpj = $customer->get_meta('billing_cnpj');
      $notes       = sprintf('Nome: %s %s', $customer->get_billing_first_name(), $customer->get_billing_last_name());

      if($this->vindi_settings->send_nfe_information())
        $metadata['inscricao_estadual'] = $customer->get_meta('billing_ie');
    } 
    else {
      // Pessoa física
      $cpf_or_cnpj = $customer->get_meta('billing_cpf');
      $notes       = '';

      if($this->vindi_settings->send_nfe_information())
        $metadata['carteira_de_identidade'] = $customer->get_meta('billing_rg');
    }

    $address = [
      'street'             => ($customer->get_billing_address_1()) ? $customer->get_billing_address_1() : WC()->countries->get_base_address(),
      'number'             => ($customer->get_meta('billing_number')) ? $customer->get_meta('billing_number') : get_option('woocommerce_store_number', ''),
      'additional_details' => ($customer->get_billing_address_2()) ?  $customer->get_billing_address_2() : WC()->countries->get_base_address_2(),
      'zipcode'            => ($customer->get_billing_postcode()) ? $customer->get_billing_postcode() : WC()->countries->get_base_postcode(),
      'neighborhood'       => ($customer->get_meta('billing_neighborhood')) ? $customer->get_meta('billing_neighborhood') : get_option('woocommerce_store_neighborhood', ''),
      'city'               => ($customer->get_billing_city()) ? $customer->get_billing_city() : WC()->countries->get_base_city(),
      'state'              => ($customer->get_billing_state()) ? $customer->get_billing_state() : WC()->countries->get_base_state(),
      'country'            => ($customer->get_billing_country()) ? $customer->get_billing_country() : WC()->countries->get_base_country(),
    ];

    $createdUser = $this->routes->createCustomer(
      array(
        'name'          => $name,
        'email'         => ($user['email']) ? $user['email'] : '',
        'code'          => 'WC-USER-'.$user['id'],
        'address'       => apply_filters('vindi_user_address', $address),
        'phones'        => $phones,
        'registry_code' => $cpf_or_cnpj ? $cpf_or_cnpj : '',
        'notes'         => $notes ? $notes : '',
        'metadata'      => !empty($metadata) ? $metadata : '',
      )
    );

    // Saving customer in the user meta WP
    if(isset($createdUser['id']))
      update_user_meta($user_id, 'vindi_customer_id', $createdUser['id']);

    if($returnId)
        return $createdUser['id'];

    return $createdUser;
  }


  /**
   * When a user is updated within the WP, it is reflected in the Vindi.
   *
   * @since 1.0.0
   * @version 1.0.0
   */
  function update($user_id, $order = null) {
    $vindi_customer_id = get_user_meta($user_id, 'vindi_customer_id', true);
    
    // Check meta Vindi ID
    if(empty($vindi_customer_id))
      return $this->create($user_id, $order);

    // Check user exists in Vindi
    $vindiUser = $this->routes->findCustomerById($vindi_customer_id);

    if(!$vindiUser)
      return $this->create($user_id);

    $customer = new WC_Customer($user_id);
    $user     = $customer->get_data();
    $phones   = $vindi_phones = [];

    foreach($vindiUser['phones'] as $phone)
      $vindi_phones[$phone['phone_type']] = $phone['id'];
    
    if($customer->get_meta('billing_cellphone')) {
      $mobile = array(
        'phone_type' => 'mobile',
        'number'     => preg_replace('/\D+/', '', '55' . $customer->get_meta('billing_cellphone'))
      );

      if($vindi_phones['mobile']) 
        $mobile['id'] = $vindi_phones['mobile'];

      $phones[] = $mobile;
    }
    
    if($customer->get_billing_phone()) {
      $landline = array(
        'phone_type' => 'landline',
        'number'     => preg_replace('/\D+/', '', '55' . $customer->get_billing_phone())
      );
      
      if (isset($vindi_phones['landline'])) 
        $landline['id'] = $vindi_phones['landline'];

      $phones[] = $landline;
    }

    $name        = (!$user['first_name']) ? $user['display_name'] : $user['first_name'] . ' ' . $user['last_name'];
    $notes       = null;
    $cpf_or_cnpj = null;
    $metadata    = null;    
    $metadata    = array();

    if('2' === $customer->get_meta('billing_persontype')) {
      // Pessoa jurídica
      $name        = $customer->get_billing_company();
      $cpf_or_cnpj = $customer->get_meta('billing_cnpj');
      $notes       = sprintf('Nome: %s %s', $customer->get_billing_first_name(), $customer->get_billing_last_name());

      if($this->vindi_settings->send_nfe_information())
        $metadata['inscricao_estadual'] = $customer->get_meta('billing_ie');
    } 
    else {
      // Pessoa física
      $cpf_or_cnpj = $customer->get_meta('billing_cpf');
      $this->vindi_settings->logger->log(sprintf('Order cpf -> %s', $cpf_or_cnpj));
      $this->vindi_settings->logger->log(sprintf('Customer cpf -> %s', $customer->get_meta('billing_cpf')));
      $notes = '';

      if($this->vindi_settings->send_nfe_information())
        $metadata['carteira_de_identidade'] = $customer->get_meta('billing_rg');
      
      $this->vindi_settings->logger->log(sprintf('Order rg -> %s', $customer->get_meta('billing_rg')));
    }

    $address = [
      'street'             => ($customer->get_billing_address_1()) ? $customer->get_billing_address_1() : WC()->countries->get_base_address(),
      'number'             => ($customer->get_meta('billing_number')) ? $customer->get_meta('billing_number') : get_option('woocommerce_store_number', ''),
      'additional_details' => ($customer->get_billing_address_2()) ?  $customer->get_billing_address_2() : WC()->countries->get_base_address_2(),
      'zipcode'            => ($customer->get_billing_postcode()) ? $customer->get_billing_postcode() : WC()->countries->get_base_postcode(),
      'neighborhood'       => ($customer->get_meta('billing_neighborhood')) ? $customer->get_meta('billing_neighborhood') : get_option('woocommerce_store_neighborhood', ''),
      'city'               => ($customer->get_billing_city()) ? $customer->get_billing_city() : WC()->countries->get_base_city(),
      'state'              => ($customer->get_billing_state()) ? $customer->get_billing_state() : WC()->countries->get_base_state(),
      'country'            => ($customer->get_billing_country()) ? $customer->get_billing_country() : WC()->countries->get_base_country(),
    ];

    // Update customer profile
    $updatedUser = $this->routes->updateCustomer(
      $vindi_customer_id,
      array(
        'name'          => $name,
        'email'         => ($user['email']) ? $user['email'] : '',
        'code'          => 'WC-USER-'.$user['id'],
        'address'       => apply_filters('vindi_user_address', $address),
        'phones'        => $phones,
        'registry_code' => $cpf_or_cnpj ? $cpf_or_cnpj : '',
        'notes'         => $notes ? $notes : '',
        'metadata'      => !empty($metadata) ? $metadata : '',
      )
    );
    
    return $updatedUser;
  }


  /**
   * When a user is deleted within the WP, it is reflected in the Vindi.
   *
   * @since 1.0.0
   * @version 1.0.0
   */
  function delete($user_id) {
    $vindi_customer_id = get_user_meta($user_id, 'vindi_customer_id', true);

    // Check meta Vindi ID
    if(empty($vindi_customer_id))
      return;

    // Check user exists in Vindi
    $vindiUser = $this->routes->findCustomerById($vindi_customer_id);

    if(empty($vindiUser))
      return;

    // Delete customer profile
    $this->routes->deleteCustomer($vindi_customer_id);
  }

  /**
   * Add extra fields to the user new form
   *
   * @since 1.5.0
   * @version 1.5.0
   */
  function addExtraFields() {
    echo
      '<table class="form-table" role="presentation">
          <tbody>
              <tr class="form-field">
                  <th scope="row"><label for="billing_cpf">CPF</label></th>
                  <td><input name="billing_cpf" type="tel" id="billing_cpf" value="' . (isset($_POST['billing_cpf']) ? $_POST['billing_cpf'] : '') . '" /></td>
              </tr>
              <tr class="form-field">
                  <th scope="row"><label for="billing_cnpj">CNPJ</label></th>
                  <td><input name="billing_cnpj" type="tel" id="billing_cnpj" value="' . (isset($_POST['billing_cnpj']) ? $_POST['billing_cnpj'] : '') . '" /></td>
              </tr>
              <tr class="form-field">
                  <th scope="row"><label for="billing_phone">Telefone</label></th>
                  <td><input name="billing_phone" type="tel" id="billing_phone" value="' . (isset($_POST['billing_phone']) ? $_POST['billing_phone'] : '') . '" /></td>
              </tr>
          </tbody>
      </table>';  
  }

  /**
   * Save extra fields data
   * 
   * @param int $user_id User ID
   *
   * @since 1.5.0
   * @version 1.5.0
   */
  function saveExtraFields($user_id) {
    if(!empty($_POST['billing_cpf'])) {
      update_user_meta($user_id, 'billing_persontype', 1);
      update_user_meta($user_id, 'billing_cpf', preg_replace('/[^0-9]/', '', wc_clean($_POST['billing_cpf'])));
    }
    elseif(!empty($_POST['billing_cnpj'])) {
      update_user_meta($user_id, 'billing_persontype', 2);
      update_user_meta($user_id, 'billing_cnpj', preg_replace('/[^0-9]/', '', wc_clean($_POST['billing_cnpj'])));
    }

    if(!empty($_POST['billing_phone']))
      update_user_meta($user_id, 'billing_phone', preg_replace('/[^0-9]/', '', wc_clean($_POST['billing_phone'])));
  }

  /**
   * Validate extra fields data
   * 
   * @param WP_Error $errors WP_Error object
   *
   * @since 1.5.0
   * @version 1.5.0
   */
  function validateExtraFields($errors) {
    if(empty($_POST['billing_cpf']) && empty($_POST['billing_cnpj']))
        $errors->add('empty_cpf_cnpj', __('<strong>Erro</strong>: Insira um CPF ou um CNPJ', VINDI));
    elseif(!empty($_POST['billing_cpf']) && !VindiValidators::isValidCPF(preg_replace('/[^0-9]/', '', wc_clean($_POST['billing_cpf']))))
      $errors->add('invalid_cpf', __('<strong>Erro</strong>: Insira um CPF válido', VINDI));
    elseif(!empty($_POST['billing_cnpj']) && !VindiValidators::isValidCNPJ(preg_replace('/[^0-9]/', '', wc_clean($_POST['billing_cnpj']))))
      $errors->add('invalid_cnpj', __('<strong>Erro</strong>: Insira um CNPJ válido', VINDI));

    if(!empty($_POST['billing_phone']) && !VindiValidators::isValidPhone(preg_replace('/[^0-9]/', '', wc_clean($_POST['billing_phone']))))
      $errors->add('invalid_phone', __('<strong>Erro</strong>: Insira um telefone válido', VINDI));
  }

  /**
   * Check if the customer has registry code
   * 
   * @param array $found_customers Customers array
   *
   * @since 1.5.0
   * @version 1.5.0
   */
  function checkRegistryCode($found_customers) {
    $found_customers = array_filter($found_customers, function($customer) {
      $registry_code = get_user_meta($customer, 'billing_cpf', true);

      if(empty($registry_code))
        $registry_code = get_user_meta($customer, 'billing_cnpj', true);

      return !empty($registry_code);
    },
    ARRAY_FILTER_USE_KEY);

    return $found_customers;
  }

}
