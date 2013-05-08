<?php
include_once('conf.php');
include_once('CAwsTransactionHandler.php');

$handler = new CAwsTransactionHandler(CLICKA_AWS_KEY,CLICKA_AWS_SECRET,CLICKA_AWS_REGION,'JUSTINE_CLICKATEL_INTEGRATION_TEST','JUSTINE_CLICKATEL_INTEGRATION_TEST_SUCCESS', 'JUSTINE_CLICKATEL_INTEGRATION_TEST_FAILED');

$req = $handler->getNextRequest();
if($req) {
  $handler->requestSucceess($req['uid'], $req['request_data']);
}
