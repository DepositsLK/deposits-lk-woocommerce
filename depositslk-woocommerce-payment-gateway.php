<?php
/*
Plugin Name: Deposits.lk - Woocommerce | Automating Your Bank Deposit Slips
Plugin URI: https://www.deposits.lk
Description: Deposits.lk allows you to automate your bank deposit slips
Version: 1.0.0
Author: SurfEdge
Author URI: https://www.surfedge.lk
*/

define('DEPOSITSLK_PLUGIN_BASE_DIR', WP_PLUGIN_URL ."/". plugin_basename(dirname(__FILE__)));
define('DEPOSITSLK_BASE_SERVER_URL', "https://bank.deposits.lk/");

function depositslk_woocommerce_gateway_depositslk_init(){
	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

	class WC_Gateway_Depositslk extends WC_Payment_Gateway{

		// Constructor
		public function __construct(){
			$this->id = "depositslk";
			$this->icon = DEPOSITSLK_PLUGIN_BASE_DIR . '/assets/img/logo.png';
			$this->method_title = "Deposits.lk";
			$this->method_description = "Automating Your Bank Deposit Slips";
			$this->has_fields = false;
			$this->checkout_url = DEPOSITSLK_BASE_SERVER_URL."deposit/checkout";


			$this->init_form_fields();
			$this->init_settings();

			$this->title = 'Bank Deposits';
			$this->description 	= $this->get_option('description');
			$this->api_key 	= $this->get_option('api_key');
			$this->api_secret 	= $this->get_option('api_secret');

			add_action('init', array(&$this, 'get_server_response'));
            add_action('woocommerce_api_'.strtolower("WC_Gateway_Depositslk"),'get_server_response'); 

			if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) ); 
				add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
			}else{
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
                add_action('woocommerce_receipt', array(&$this, 'receipt_page'));
            }

		} // END - Constructor

		// Init Form Fields
		function init_form_fields(){
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable Plugin', 'woocommerce'),
					'type' => 'checkbox',
					'label' => __('Enable Deposits.lk', 'woocommerce'),
					'default' =>'yes'
				),
				'description' => array(
					'title' 			=> __('Description:', 'woocommerce'),
					'type' 			=> 'textarea',
					'default' 		=> __('Deposit via your preferred bank', 'woocommerce'),
					'description' 	=> __('This controls the description which the user sees during checkout.', 'woocommerce'),
					'desc_tip' 		=> true
				),
      			'api_key' => array(
					'title' 		=> __('API KEY', 'woocommerce'),
					'type' 			=> 'text',
					'description' 	=> __('API Key provided by Deposits.lk'),
					'desc_tip' 		=> true
				),
				'api_secret' => array(
					'title' 		=> __('API Secret', 'woocommerce'),
					'type' 			=> 'text',
					'description' 	=> __('API Secret provided by Deposits.lk'),
					'desc_tip' 		=> true
				)
			);
		} // END - Init Form Fields

		// Process Payment
		function process_payment( $order_id ) {
		    global $woocommerce;
		    $order = new WC_Order( $order_id );

		    $order->update_status('pending', __( 'Awaiting Bank Deposit', 'woocommerce' ));

		    $woocommerce->cart->empty_cart();

		    if ( version_compare( WOOCOMMERCE_VERSION, '2.1.0', '>=' ) ) {
			  	$checkout_payment_url = $order->get_checkout_payment_url( true );
			} else {
				$checkout_payment_url = get_permalink( get_option ( 'woocommerce_pay_page_id' ) );
			}

			return array(
				'result' => 'success', 
				'redirect' => add_query_arg(
					'order-pay', 
					$order->id, 
					add_query_arg(
						'key', 
						$order->order_key, 
						$checkout_payment_url						
					)
				)
			);
		} // END -  Process Payment

		// Reciept Page
		function receipt_page($order){
			echo '<p><strong>Thank you for your order.</strong><br/>The payment page will open soon.</p>';
			echo $this->redirect_to_website_form($order);
		} // END - Reciept Page

		// Redirect to Checkout Form
		function redirect_to_website_form($order_id){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$order->update_status('wc-pending-slip', __( 'Awaiting Bank Deposit', 'woocommerce' ));
			
			$redirect_url = $order->get_checkout_order_received_url();

			if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
				$notify_url = add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
			}

			$txnid = $order_id.'_'.date("ymds");

			$form_args = array(
				'api_key' => $this->api_key,
                'return_url' => $redirect_url,
                'cancel_url' => $redirect_url,
                'notify_url' => $notify_url,

                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'currency' => get_woocommerce_currency(),

                'order_id' => $order_id,
                'order_desc' => '',
                'amount' => $order->get_total(),
                'platform' => 'woocommerce'
			);
           	
           	$item_index = 1;
            foreach ($order->get_items() as $item) {
                $form_args['order_desc'] .= $item->get_name();
                if ($item_index != count($order->get_items())){
            		$form_args['order_desc'] .= ", ";
            	}
            	$item_index;
            }

            $form_args_html_array = array();
			
			foreach($form_args as $key => $value){
				array_push($form_args_html_array, "<input type='hidden' name='$key' value='$value'/>");
			}

			return '<form action="'.$this->checkout_url.'" method="post">
				' . implode('', $form_args_html_array) . '
				<input type="submit" id="depositslk_merchant_form_submit" value="Pay via Deposits.lk" /> 
			</form>
			<script type="text/javascript">
				jQuery(function(){
				jQuery("body").block({
					message: "Redirecting you to Deposits.lk Payment Gateway to make the payment.",
					overlayCSS: {
						background		: "#fff",
						opacity			: 0.8
					},
					css: {
						padding			: 20,
						textAlign		: "center",
						color			: "#333",
						border			: "1px solid #eee",
						backgroundColor	: "#fff",
						cursor			: "wait",
					}
				});
				jQuery("#depositslk_merchant_form_submit").click();});
				</script>
			';
		} // END - Redirect to Checkout Form

	}

	// Get Callback Response
	function get_server_response(){
		global $woocommerce;
		
		if (isset($_REQUEST['order_id']) && isset($_REQUEST['payment_id'])){
			
			$order_id = sanitize_text_field($_REQUEST['order_id']);
			$payment_id = sanitize_text_field($_REQUEST['payment_id']);
			$amount = sanitize_text_field($_REQUEST['amount']);
			$currency = sanitize_text_field($_REQUEST['currency']);
			$status_code = sanitize_text_field($_REQUEST['status_code']);
			$slip_image = sanitize_text_field($_REQUEST['slip_image']);
			$md5sig = sanitize_text_field($_REQUEST['md5sig']);

			if ($order_id == "" || $payment_id == "" || $amount == "" || $currency == "" || $slip_image == "" || $md5sig == ""){
				return false;
			}

			try{
				$order = new WC_Order($order_id);
				
				if ($order->status == "completed"){
					return false;
				}

				$wcplugin = new WC_Gateway_Depositslk();
				$api_secret = $wcplugin->api_secret;

				$md5hash = strtoupper(md5($payment_id.$order_id.$amount.$status_code.$currency.strtoupper(md5($api_secret))));

				if ($md5sig === $md5hash){
					if ($status_code == "2"){
						depositslk_send_email_notification($order);
						$order->update_status('wc-pending-approval');
						depositslk_send_email_notification($order);
						$order->add_order_note('Successful with Deposits.lk<br><a href= "'.esc_url($slip_image).'" target="_blank">Click Here</a> to View the Bank Slip');

					}else if ($status_code == "0"){
						$order->update_status('wc-pending-slip');
					
					}else if ($status_code == "-1"){
						$order->update_status('failed');
						$order->add_order_note('Deposits.lk - Transaction Failed');
					
					}else{
						$order->update_status('failed');
						$order->add_order_note('Deposits.lk - Invalid Status Code');
					}
				
				}else{
					$order->update_status('failed');
					$order->add_order_note('Deposits.lk - Merchant Authentication Failure');
					$woocommerce->cart->empty_cart();
				}
				
				
			}catch(Exception $e){
                $msg = "Error";
			}

			wp_redirect($order->get_checkout_order_received_url());
			exit;
		}
	} // END - Get Callback Response

	// Add the Gateway to WooCommerce
	function woocommerce_add_gateway_depositslk_gateway($methods) {
		$methods[] = 'WC_Gateway_Depositslk';
		return $methods;
	}// END - Add the Gateway to WooCommerce

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_gateway_depositslk_gateway' );
}

// Custom Order Statuses

// Register Pending Slip Status
function depositslk_register_pending_slip_order_status() {
	$label = "Pending Bank Slip";
    register_post_status( 'wc-pending-slip', array(
        'label'                     => $label,
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( $label.' (%s)', $label.' (%s)' )
    ) );
}

// Register Pending Approval Status
function depositslk_register_pending_approval_order_status() {
	$label = "Pending Bank Slip Approval";
    register_post_status( 'wc-pending-approval', array(
        'label'                     => $label,
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( $label.' (%s)', $label.' (%s)' )
    ) );
}
// END - Custom Order Statuses

// Add Pending Approval Status to list of WC Order statuses
function depositslk_add_custom_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
 
    foreach ( $order_statuses as $key => $status ) {
 
        $new_order_statuses[ $key ] = $status;
 
        if ( 'wc-pending' === $key ) {
        	$new_order_statuses['wc-pending-slip'] = "Pending Bank Slip";
            $new_order_statuses['wc-pending-approval'] = "Pending Bank Slip Approval";
        }
    }
 
    return $new_order_statuses;
}

function depositslk_add_admin_additional_scripts(){
	wp_enqueue_style('depositslk-admin-styles', plugin_dir_url(__FILE__). 'assets/css/admin-style.css');
}

function depositslk_send_email_notification( $order ) {
	// Get all WC_emails objects
    $email_notifications = WC()->mailer()->get_emails();
    
    if ($order->has_status('pending') || $order->has_status('pending-slip') ){
    	$email_notifications['WC_Email_New_Order']->trigger( $order->get_order_number() );
    
    }else if ($order->has_status('pending-approval')){
    	$email_notifications['WC_Email_Customer_On_Hold_Order']->trigger( $order->get_order_number() );
    }
    
}

add_filter( 'wc_order_statuses', 'depositslk_add_custom_order_statuses' );

add_action( 'init', 'depositslk_register_pending_slip_order_status');
add_action( 'init', 'depositslk_register_pending_approval_order_status');
add_action( 'plugins_loaded', 'depositslk_woocommerce_gateway_depositslk_init' );
add_action('admin_enqueue_scripts', 'depositslk_add_admin_additional_scripts');