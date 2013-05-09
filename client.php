<?php
include('conf.php');
include('ClickatellClient.php');
include_once('CAwsTransactionHandler.php');

$max_client_tries = 1;
for($i=0; $i <= $max_client_tries; $i++) {
    $client = NULL;
    try {
	$handler = new CAwsTransactionHandler(CLICKA_AWS_KEY,CLICKA_AWS_SECRET,CLICKA_AWS_REGION,'JUSTINE_CLICKATEL_INTEGRATION_TEST','JUSTINE_CLICKATEL_INTEGRATION_TEST_SUCCESS', 'JUSTINE_CLICKATEL_INTEGRATION_TEST_FAILED');
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
