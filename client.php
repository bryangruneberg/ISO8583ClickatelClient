<?php
include('conf.php');
include('ClickatellClient.php');
include_once('CAwsTransactionHandler.php');

$max_client_tries = 10;
$max_age = 60*60*6; // 6 hours
$start = time();

for($i=1; $i <= $max_client_tries; $i++) {
    $age = time() - $start;

    if($age >= $max_age) {
      echo "We are too old. Time to die.\n";
      break;
    }
    

    $client = NULL;
    try {
	$handler = new CAwsTransactionHandler(CLICKA_AWS_KEY,CLICKA_AWS_SECRET,CLICKA_AWS_REGION,'JUSTINE_CLICKATEL_INTEGRATION_TEST','JUSTINE_CLICKATEL_INTEGRATION_TEST_SUCCESS', 'JUSTINE_CLICKATEL_INTEGRATION_TEST_FAILED','JUSTINE_CLICKATEL_INTEGRATION_TEST_ERRORS','js2013_transaction');
        $client = new ClickatelClient(CLICKA_SERVER, CLICKA_PORT, $handler);
    } catch (Exception $ex) {
	echo "Error starting AWS and Clickatel subsystems";
	echo "\n";
	echo $ex->getMessage() . "\n";
	exit;
    }


    try {
        $client->connect();
        $client->loop();
    } catch(Exception $ex) {
        $client->logLine('EXCEPTION: ' . $ex->getMessage());
        $client->disconnect();
        unset($client);
        sleep( rand(1, 5));
    }
}
