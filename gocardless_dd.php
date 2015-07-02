<?php

require_once 'UK_Direct_Debit/Form/Main.php';
require_once 'CRM/Core/Payment.php';
require_once 'lib/GoCardless.php';

class uk_co_vedaconsulting_payment_gocardlessdd extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;

  /**
   * mode of operation: live or test
   *
   * @var object
   */
  protected $_mode = null;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   * @param $paymentProcessor
   */
  function __construct( $mode, &$paymentProcessor ) {
    $this->_mode             = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('Gocardless Processor');
  }

  /**
   * singleton function used to manage this object
   *
   * @param string $mode the mode of operation: live or test
   * @param $paymentProcessor
   * @return object
   * @static
   *
   */
  static function &singleton( $mode, &$paymentProcessor, &$paymentForm = NULL, $force = FALSE ) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null ) {
        self::$_singleton[$processorName] = new self( $mode, $paymentProcessor );
    }
    return self::$_singleton[$processorName];
  }

  /**
   * This function checks to see if we have the right config values
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig() {
    $config = CRM_Core_Config::singleton();
    $error  = array();

    if ( empty( $this->_paymentProcessor['user_name'] ) ) {
      $error[] = ts( 'The "Bill To ID" is not set in the Administer CiviCRM Payment Processor.' );
    }

    /* TO DO
     * Add check to ensure password is also set
     * Also the URL's for api site
     */

    if ( !empty( $error ) ) {
      return implode( '<p>', $error );
    }
    else {
      return NULL;
    }
  }

  function gocardless_dd_civicrm_config( &$config ) {

    $template =& CRM_Core_Smarty::singleton( );

    $batchingRoot = dirname( __FILE__ );

    $batchingDir = $batchingRoot . DIRECTORY_SEPARATOR . 'templates';

    if ( is_array( $template->template_dir ) ) {
        array_unshift( $template->template_dir, $batchingDir );
    } else {
        $template->template_dir = array( $batchingDir, $template->template_dir );
    }

    // also fix php include path
    $include_path = $batchingRoot . PATH_SEPARATOR . get_include_path( );
    set_include_path( $include_path );

  }

  function gocardless_dd_civicrm_xmlMenu( &$files ) {
    $files[] = dirname(__FILE__)."/xml/Menu/CustomTestForm.xml";
  }

 function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  function doTransferCheckout( &$params, $component ) {

    $paymentProcessorType = CRM_Core_PseudoConstant::paymentProcessorType(false, null, 'name');
    $paymentProcessorTypeId = CRM_Utils_Array::key('Gocardless', $paymentProcessorType);
    $domainID  = CRM_Core_Config::domainID();

      $sql  = " SELECT user_name ";
      $sql .= " ,      password ";
      $sql .= " ,      signature ";
      $sql .= " ,      subject ";
      $sql .= " FROM civicrm_payment_processor ";
      $sql .= " WHERE payment_processor_type_id = %1 ";
      $sql .= " AND is_test= %2 ";
      $sql .= " AND domain_id = %3 ";

      $isTest = 0;
      if ($this->_mode == 'test') {
        $isTest = 1;
      }

      $sql_params = array( 1 => array( $paymentProcessorTypeId, 'Integer' )
                     , 2 => array( $isTest, 'Int' )
                     , 3 => array( $domainID, 'Int' )
                     );

      $dao = CRM_Core_DAO::executeQuery( $sql, $sql_params);

      if ($dao->fetch()) {

          $app_id       = $dao->user_name;
          $app_secret   = $dao->password;
          $merchant_id  = $dao->signature;
          $access_token = $dao->subject;


      }

    $account_details = array(
      'app_id'        => $app_id,
      'app_secret'    => $app_secret,
      'merchant_id'   => $merchant_id,
      'access_token'  => $access_token,
    );

    // Fail nicely if no account details set
    if ( ! $account_details['app_id'] && ! $account_details['app_secret']) {
      echo '<p>First sign up to <a href="http://gocardless.com">GoCardless</a> and
        copy your sandbox API credentials from the \'Developer\' tab into the top of
        this script.</p>';
      exit();
    }
    
    // Set $environment to 'production' if live. Default is 'sandbox'
    if ($this->_mode == 'live') {
      GoCardless::$environment = 'production';
    }

    // Initialize GoCardless
    GoCardless::set_account_details($account_details);

    $goCardLessParams = array();

    $goCardLessParams['amount']           = $params['amount'];
    $goCardLessParams['interval_length']  = $params['frequency_interval'];
    $goCardLessParams['interval_unit']    = $params['frequency_unit'];


    $url    = ( $component == 'event' ) ? 'civicrm/event/register' : 'civicrm/contribute/transact';
    $cancel = ( $component == 'event' ) ? '_qf_Register_display'   : '_qf_Main_display';
    $returnURL = CRM_Utils_System::url( $url,
                                         "_qf_ThankYou_display=1&qfKey={$params['qfKey']}"."&cid={$params['contactID']}",
                                         true, null, false );

    $goCardLessParams['redirect_uri'] = $returnURL;


    $goCardLessParams['user'] = array(
        'email'            => isset($params['email-5'])         ? $params['email-5'] : NULL,
        'first_name'       => isset($params['first_name'])      ? $params['first_name']: NULL,
        'last_name'        => isset($params['last_name'])       ? $params['last_name']: NULL,
        'billing_address1' => isset($params['street_address'])  ? $params['street_address']: NULL,
        'billing_town'     => isset($params['city'])            ? $params['city']: NULL,
        'billing_postcode' => isset($params['postal_code'])     ? $params['postal_code']: NULL,
        'country_code'     => 'GB'

      );


    // Allow further manipulation of the arguments via custom hooks ..
    CRM_Utils_Hook::alterPaymentProcessorParams($this, $params, $goCardLessParams);

    $subscription_url = GoCardless::new_subscription_url($goCardLessParams);
    CRM_Utils_System::redirect($subscription_url);

  }

  public function handlePaymentNotification() {
    CRM_Core_Error::debug_log_message( 'uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification' );
    CRM_Core_Error::debug_log_message( '$_GET[]:'  . print_r( $_GET, true ) );
    CRM_Core_Error::debug_log_message( '$_POST[]:' . print_r( $_POST, true ) );

    CRM_Core_Error::debug( 'Smart Debit handlePaymentNotification');

    require_once 'CRM/Utils/Array.php';
    require_once 'CRM/Core/Payment/SmartDebitIPN.php';

    $module = CRM_Utils_Array::value( 'module', $_GET );
    if ( empty( $_GET ) ) {
        $rpInvoiceArray = array();
        $rpInvoiceArray = explode( '&' , $_POST['rp_invoice_id'] );
        foreach ( $rpInvoiceArray as $rpInvoiceValue ) {
            $rpValueArray = explode ( '=' , $rpInvoiceValue );
            if ( $rpValueArray[0] == 'm' ) {
                $value = $rpValueArray[1];
            }
        }
        CRM_Core_Error::debug_log_message('uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification #2');

        $SmartDebitIPN = new CRM_Core_Payment_SmartDebitIPN();
    } else {
        CRM_Core_Error::debug_log_message('uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification #3');
        $value         = CRM_Utils_Array::value( 'module', $_GET );
        $SmartDebitIPN = new CRM_Core_Payment_SmartDebitIPN();
    }
    CRM_Core_Error::debug_log_message('uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification value='.$value);

    switch ( strtolower( $value ) ) {
        case 'contribute':
            $SmartDebitIPN->main( 'contribute' );
            break;
        case 'event':
            $SmartDebitIPN->main( 'event' );
            break;
        default     :
            require_once 'CRM/Core/Error.php';
            CRM_Core_Error::debug_log_message( "Could not get module name from request url" );
            echo "Could not get module name from request url<p>";
            break;
    }
  }

}
