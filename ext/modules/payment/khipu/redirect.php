<?php
chdir('../../../../');
require __DIR__ . '/../../../../ext/vendor/autoload.php';
require('includes/application_top.php');
require(DIR_WS_CLASSES . 'payment.php');
require(DIR_WS_CLASSES . 'order.php');
$payment_modules = new payment(khipu_notify);
$receiver_id =  MODULE_PAYMENT_KHIPU_CLIENT_ID;
$secret = MODULE_PAYMENT_KHIPU_CLIENT_SECRET;

$configuration = new Khipu\Configuration();
$configuration->setSecret($secret);
$configuration->setReceiverId($receiver_id);
$configuration->setPlatform('oscommerce-khipu', '2.5.0');

$client = new Khipu\ApiClient($configuration);
$payments = new Khipu\Client\PaymentsApi($client);

$order_id = substr($_POST['transaction_id'], strpos($_POST['transaction_id'], '-')+1);
$order = new order();
$order->order($order_id);
$order_total_query = tep_db_query("select value from " . TABLE_ORDERS_TOTAL . " where orders_id = '" . (int)$order_id . "' and class = 'ot_total'");
$order_total = tep_db_fetch_array($order_total_query);

$currencies = new currencies();

$options = array(
    "transaction_id" => $_POST['transaction_id']
    , "body" => $_POST['body']
    , "return_url" => $_POST['return_url']
    , "notify_url" => $_POST['notify_url']
    , "api_version" => $_POST['api_version']
    , "payer_email" => $_POST['payer_email']
);

try {
    $createPaymentResponse = $payments->paymentsPost(
        $_POST['subject']
        , $order->info['currency']
        , number_format($order_total['value'],$currencies->currencies[$order->info['currency']]['decimal_places'],"." , "")
        , $options
    );
} catch (\Khipu\ApiException $e) {
    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<h1>Error " . $e->getCode() . ": " . $e->getMessage() . "</h1>";
    $error = $e->getResponseObject();
    if (method_exists($error, "getErrors")) {
        echo "<ul>";
        foreach ($error->getErrors() as $errorItem) {
            echo "<li><strong>" . $errorItem->getField() . "</strong>: " . $errorItem->getMessage() . "</li>";
        }
        echo "</ul>";
        return;
    }
    echo "</body></html>";
    return;
}

header('Location: ' . $createPaymentResponse->getPaymentUrl());
require('includes/application_bottom.php');
?>
