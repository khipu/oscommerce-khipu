<?php
chdir('../../../../');
require('includes/application_top.php');
include(DIR_WS_LANGUAGES . $language . '/' . FILENAME_CHECKOUT_PROCESS);
require(DIR_WS_CLASSES . 'payment.php');
$payment_modules = new payment(khipu_notify);
$my_receiver_id =  MODULE_PAYMENT_KHIPU_CLIENT_ID;
$secret = MODULE_PAYMENT_KHIPU_CLIENT_SECRET;

// Leer los parametros enviados por khipu
$api_version = $_POST['api_version'];

$valid_notification = false;
if ($api_version == '1.3') {
	$notification_token = $_POST['notification_token'];

	$concatenated = "receiver_id=$my_receiver_id&notification_token=$notification_token";
	$hash = hash_hmac('sha256', $concatenated , $secret);
	$url = 'https://khipu.com/api/1.3/getPaymentNotification';
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, 'https://khipu.com/api/1.3/getPaymentNotification');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POST, true);

	$data = array('receiver_id' => $my_receiver_id , 'notification_token' => $notification_token , 'hash' => $hash);

	curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
	$output = curl_exec($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);

	$notification = json_decode($output);

	if($notification->receiver_id == $my_receiver_id) {
		$valid_notification = true;
	}
	$transaction_id = $notification->transaction_id;
} else if ($api_version == '1.2') {
	$receiver_id = $_POST['receiver_id'];
	$notification_id = $_POST['notification_id'];
	$subject = $_POST['subject'];
	$amount = $_POST['amount'];
	$currency = $_POST['currency'];
	$custom = $_POST['custom'];
	$transaction_id = $_POST['transaction_id'];
	$payer_email = $_POST['payer_email'];

	// La firma digital enviada por khipu
	$notification_signature = $_POST['notification_signature'];
			
	// Creamos el string para enviar
	// Todos los parametros debene enviarse en este mismo orden
	$to_send = 'api_version='.urlencode($api_version).
		'&receiver_id='.urlencode($receiver_id).
		'&notification_id='.urlencode($notification_id).
		'&subject='.urlencode($subject).
		'&amount='.urlencode($amount).
		'&currency='.urlencode($currency).
		'&transaction_id='.urlencode($transaction_id).
		'&payer_email='.urlencode($payer_email).
		'&custom='.urlencode($custom);

	// Usamos CURL para hacer POST HTTP
	$ch = curl_init("https://khipu.com/api/1.3/verifyPaymentNotification");
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_POST, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_POSTFIELDS, $to_send."&notification_signature=".urlencode($notification_signature));
	$response = curl_exec($ch);		  
	curl_close($ch); 
	if ($response == 'VERIFIED' && $receiver_id == $my_receiver_id) {
		$valid_notification = true;
	}
}

if ($valid_notification) {
	$invoice = substr($transaction_id, strpos($transaction_id, '-')+1);

	$order_status_id = MODULE_PAYMENT_KHIPU_STATUS_VERIFIED;
	tep_db_query("update " . TABLE_ORDERS . " set orders_status = '" . $order_status_id . "', last_modified = now() where orders_id = '" . $invoice . "'");

	$sql_data_array = array('orders_id' => $invoice,
		'orders_status_id' => $order_status_id,
		'date_added' => 'now()',
		'customer_notified' => 1,
		'comments' => 'Verificado por khipu');

	tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);

	//update the stock  
	for ($i=0, $n=sizeof($order->products); $i<$n; $i++) { // PRODUCT LOOP STARTS HERE
		// Stock Update
		if (STOCK_LIMITED == 'true') {
			if (DOWNLOAD_ENABLED == 'true') {
				$stock_query_raw = "SELECT products_quantity, pad.products_attributes_filename 
					FROM " . TABLE_PRODUCTS . " p
					LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES . " pa
					ON p.products_id=pa.products_id
					LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
					ON pa.products_attributes_id=pad.products_attributes_id
					WHERE p.products_id = '" . tep_get_prid($order->products[$i]['id']) . "'";
					// Will work with only one option for downloadable products
					// otherwise, we have to build the query dynamically with a loop
					$products_attributes = $order->products[$i]['attributes'];
					if (is_array($products_attributes)) {
						$stock_query_raw .= " AND pa.options_id = '" . $products_attributes[0]['option_id'] . "' AND pa.options_values_id = '" . $products_attributes[0]['value_id'] . "'";
					}
				$stock_query = tep_db_query($stock_query_raw);
			} else {
				$stock_query = tep_db_query("select products_quantity from " . TABLE_PRODUCTS . " where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
			}
			if (tep_db_num_rows($stock_query) > 0) {
				$stock_values = tep_db_fetch_array($stock_query);
				// do not decrement quantities if products_attributes_filename exists
				if ((DOWNLOAD_ENABLED != 'true') || (!$stock_values['products_attributes_filename'])) {
					$stock_left = $stock_values['products_quantity'] - $order->products[$i]['qty'];
				} else {
					$stock_left = $stock_values['products_quantity'];
				}
				tep_db_query("update " . TABLE_PRODUCTS . " set products_quantity = '" . $stock_left . "' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
				if ( ($stock_left < 1) && (STOCK_ALLOW_CHECKOUT == 'false') ) {
					tep_db_query("update " . TABLE_PRODUCTS . " set products_status = '0' where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");
				}
			}
		}

		// Update products_ordered (for bestsellers list)
		tep_db_query("update " . TABLE_PRODUCTS . " set products_ordered = products_ordered + " . sprintf('%d', $order->products[$i]['qty']) . " where products_id = '" . tep_get_prid($order->products[$i]['id']) . "'");

		// Let's get all the info together for the email
		$total_weight += ($order->products[$i]['qty'] * $order->products[$i]['weight']);
		$total_tax += tep_calculate_tax($total_products_price, $products_tax) * $order->products[$i]['qty'];
		$total_cost += $total_products_price;

		// Let's get the attributes
		$products_ordered_attributes = '';
		if ( (isset($order->products[$i]['attributes'])) && (sizeof($order->products[$i]['attributes']) > 0) ) {
			for ($j=0, $n2=sizeof($order->products[$i]['attributes']); $j<$n2; $j++) {
				$products_ordered_attributes .= "\n\t" . $order->products[$i]['attributes'][$j]['option'] . ' ' . $order->products[$i]['attributes'][$j]['value'];
			}
		} 

		// Let's format the products model       
		$products_model = '';      
		if ( !empty($order->products[$i]['model']) ) {
			$products_model = ' (' . $order->products[$i]['model'] . ')';
		} 

		// Let's put all the product info together into a string
		$products_ordered .= $order->products[$i]['qty'] . ' x ' . $order->products[$i]['name'] . $products_model . ' = ' . $currencies->display_price($order->products[$i]['final_price'], $order->products[$i]['tax'], $order->products[$i]['qty']) . $products_ordered_attributes . "\n";
	}        // PRODUCT LOOP ENDS HERE
}

require('includes/application_bottom.php');
?>
