<?php
include('ClickatellClient.php');
$max_client_tries = 10;
for($i=0; $i <= $max_client_tries; $i++) {
    try {
        $client = new ClickatelClient("127.0.0.1", "20007");
        $client->connect();
        $client->loop();
    } catch(Exception $ex) {
        $client->logLine('EXCEPTION: ' . $ex->getMessage());
        $client->disconnect();
        unset($client);
        sleep( rand(1, 5));
    }
}
