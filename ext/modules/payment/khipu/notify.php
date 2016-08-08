<?php
chdir('../../../../');
require __DIR__ . '/../../../../ext/vendor/autoload.php';
require('includes/application_top.php');
require(DIR_WS_CLASSES . 'payment.php');
require(DIR_WS_CLASSES . 'order.php');
$payment_modules = new payment(khipu_notify);
$my_receiver_id =  MODULE_PAYMENT_KHIPU_CLIENT_ID;
$secret = MODULE_PAYMENT_KHIPU_CLIENT_SECRET;


if($_POST['api_version'] == '1.3') {
    $configuration = new Khipu\Configuration();
    $configuration->setSecret($secret);
    $configuration->setReceiverId($my_receiver_id);
    $configuration->setPlatform('oscommerce-khipu', '2.4.3');

    $client = new Khipu\ApiClient($configuration);
    $payments = new Khipu\Client\PaymentsApi($client);


    $paymentsResponse =  $payments->paymentsGet($_POST['notification_token']);

    if ($paymentsResponse->getReceiverId() != $my_receiver_id) {
        print 'rejected - Wrong receiver';
        exit(0);
    }

    $transaction_id = $paymentsResponse->getTransactionId();

    $order_id = substr($transaction_id, strpos($transaction_id, '-')+1);
    $order = new order();
    $order->order($order_id);
    $order_total_query = tep_db_query("select value from " . TABLE_ORDERS_TOTAL . " where orders_id = '" . (int)$order_id . "' and class = 'ot_total'");
    $order_total = tep_db_fetch_array($order_total_query);

    if( $paymentsResponse->getStatus() != 'done') {
        print 'rejected - payment not done';
        exit(0);
    }

    if($order->info['currency'] != $paymentsResponse->getCurrency() || $paymentsResponse->getAmount() != $order_total['value'] ) {
        print 'rejected - Wrong amount';
        exit(0);
    }

    $valid_notification = true;


} else {
    print 'rejected - invalid api version';
    exit(0);
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
