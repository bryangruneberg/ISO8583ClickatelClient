<?php
include_once('conf.php');
include_once('CAwsTransactionHandler.php');

$handler = new CAwsTransactionHandler(CLICKA_AWS_KEY,CLICKA_AWS_SECRET,CLICKA_AWS_REGION,'JUSTINE_CLICKATEL_INTEGRATION_TEST','JUSTINE_CLICKATEL_INTEGRATION_TEST_SUCCESS', 'JUSTINE_CLICKATEL_INTEGRATION_TEST_FAILED','js2013_transaction');

$req = $handler->putTransactionInStore('js123','pending','test');
$req = $handler->putTransactionInStore('js1234','new','test');
$req = $handler->putTransactionInStore('js1235','new','test');
$res = $handler->getTransactionFromStore('js123');
print_r($res);
$res = $handler->getTransactionFromStore('js1234');
print_r($res);
print $handler->countTransactionsInState('pending') . "\n";
print $handler->countTransactionsInState('new') . "\n";
print $handler->countTransactionsInState('old') . "\n";
$req = $handler->setTransactionState('js123','done');
print $handler->getTransactionState('js123') . "\n";
$handler->incrementTransactionTransmissionCount('js123');
$handler->incrementTransactionTransmissionCount('js123');
$handler->incrementTransactionTransmissionCount('js123');
print $handler->getTransactionTransmissionCount('js123') . "\n";
