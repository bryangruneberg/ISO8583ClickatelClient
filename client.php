<?php
include('conf.php');
include('ClickatellClient.php');

$max_client_tries = 10;
for($i=0; $i <= $max_client_tries; $i++) {
    try {
        $client = new ClickatelClient(CLICKA_SERVER, CLICKA_PORT);
        $client->connect();
        $client->loop();
    } catch(Exception $ex) {
        $client->logLine('EXCEPTION: ' . $ex->getMessage());
        $client->disconnect();
        unset($client);
        sleep( rand(1, 5));
    }
}
