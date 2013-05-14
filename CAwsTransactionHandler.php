<?php
include_once('IClickatellTransactionHandler.php');
include('aws.phar');

use Aws\Sqs\SqsClient;
use Aws\DynamoDb\DynamoDbClient;

Class CAwsTransactionHandler implements IClickatellTransactionHandler {

	private $_aws_key;
	private $_aws_secret;
	private $_aws_region;
	private $_aws_read_queue;
	private $_aws_read_queue_url;
	private $_aws_success_queue;
	private $_aws_success_queue_url;
	private $_aws_fail_queue;
	private $_aws_fail_queue_url;

	private $_aws_transaction_table;

	private $_aws_sqs_client;
	private $_aws_ddb_client;

	/**  
	 * constructor
	 */
	public function __construct($aws_key, $aws_secret, $aws_region, $aws_read_queue, $aws_success_queue, $aws_fail_queue, $aws_transaction_table) {
		$this->_aws_region = $aws_region;
		$this->_aws_key = $aws_key;
		$this->_aws_secret = $aws_secret;
		$this->_aws_read_queue = $aws_read_queue;
		$this->_aws_success_queue = $aws_success_queue;
		$this->_aws_fail_queue = $aws_fail_queue;

		$this->_aws_transaction_table = $aws_transaction_table;

		$this->_aws_sqs_client = SqsClient::factory(array(
					'key'    => $this->_aws_key,
					'secret' => $this->_aws_secret,
					'region' => $this->_aws_region
					));

		$queueUrl = $this->_aws_sqs_client->getQueueUrl(array('QueueName'=>$this->_aws_read_queue));
		$this->_aws_read_queue_url = $queueUrl->get('QueueUrl');

		$queueUrl = $this->_aws_sqs_client->getQueueUrl(array('QueueName'=>$this->_aws_success_queue));
		$this->_aws_success_queue_url = $queueUrl->get('QueueUrl');

		$queueUrl = $this->_aws_sqs_client->getQueueUrl(array('QueueName'=>$this->_aws_fail_queue));
		$this->_aws_fail_queue_url = $queueUrl->get('QueueUrl');

		$this->_aws_ddb_client = DynamoDbClient::factory(array(
					'key'    => $this->_aws_key,
					'secret' => $this->_aws_secret,
					'region' => $this->_aws_region
					));

	}

	/**
	 * Must return array
	 *  uid => unique request id
	 *  request_data => array
	 */
	public function getNextRequest() {
		$this->logLine('Checking for a new request on AWS queue');
		$ret = NULL;

		$result = $this->_aws_sqs_client->receiveMessage(array(
					'QueueUrl' => $this->_aws_read_queue_url
					));

		if(!$result) { return FALSE; }

		$messages = $result->get('Messages');
		if(is_array($messages)) {
			$message = array_shift($messages);
			$ret['uid'] = $message['ReceiptHandle'];
			$ret['request_data'] = array();

			$jdata = json_decode($message['Body'], TRUE);
			if($jdata) {
				$this->logLine('The request message has been decoded');
				$ret['request_data'] = $jdata;
			} else {
				$this->logLine('The request message is not in JSON format');
			}

			if(!isset($ret['request_data']['packet_creator_class'])) {
				$ret['request_data']['packet_creator_class'] = 'CClickatelTransactionPacketCreator';
			}

			@include_once($ret['request_data']['packet_creator_class'] . ".php");

			if(!class_exists($ret['request_data']['packet_creator_class'])) {
				throw new Exception('Packet Creator Not Found: ' . $ret['request_data']['packet_creator_class']);
			}

		}

		return $ret;
	}

	/**
	 * This function will be called when we get a success notification from the network
	 */
	public function requestSucceess($uid, $request_data) {

		$this->_aws_sqs_client->deleteMessage(array(
					'QueueUrl' => $this->_aws_read_queue_url,
					'ReceiptHandle' => $uid
					));

		$this->logLine('Original request removed');

		$msg = array(
				'QueueUrl'    => $this->_aws_success_queue_url,
				'MessageBody' => json_encode($request_data)
			    );

		$this->_aws_sqs_client->sendMessage($msg);
		$this->logLine('Request moved to successfull');

		return TRUE;
	}

	/**
	 * This function will be called when we get a failure notification from the network
	 */
	public function requestFailure($uid, $request_data) {

		$this->_aws_sqs_client->deleteMessage(array(
					'QueueUrl' => $this->_aws_read_queue_url,
					'ReceiptHandle' => $uid
					));

		$this->logLine('Original request removed');

		$msg = array(
				'QueueUrl'    => $this->_aws_fail_queue_url,
				'MessageBody' => json_encode($request_data)
			    );

		$this->_aws_sqs_client->sendMessage($msg);
		$this->logLine('Request moved to failed');

		return TRUE;
	}

	/**
	 * Log a line to the console
	 */
	public function logLine($line) {
		echo 'AWSHandler: ' . date('Y-m-d H:i:s') .' ';
		echo $line . "\n";
	}

	/**
	 * Put a transaction in the store in a specific state
	 */
	public function putTransactionInStore($id, $state, $data) {
		$result = $this->_aws_ddb_client->putItem(array(
					'TableName' => $this->_aws_transaction_table,
					'Item' => $this->_aws_ddb_client->formatAttributes(array(
							'transactionId'      => $id,
							'state'    => $state,
							'data' => $data,
							'transmissionCount' => 0
							)),
					'ReturnConsumedCapacity' => 'TOTAL'
					));
	}

	/**
	 * Get transaction from store
	 */
	public function getTransactionFromStore($id) {
		$result = $this->_aws_ddb_client->getItem(array(
					'ConsistentRead' => true,
					'TableName' => $this->_aws_transaction_table,
					'Key'       => array(
						'transactionId'   => array('S' => $id),
						)
					));

		if($result) {
			$ret = array('transactionId' => NULL,'state' => NULL,'transmissionCount'=>0,'data'=>NULL);
			$ret['transactionId'] = $result['Item']['transactionId']['S'];
			$ret['state'] = $result['Item']['state']['S'];
			if(isset($result['Item']['transmissionCount'])) {
				$ret['transmissionCount'] = intval($result['Item']['transmissionCount']['N']);
			}
			$ret['data'] = $result['Item']['data']['S'];
			return $ret;
		}
		return NULL;
	}

	/**
	 * Count the number of transactions in a specific state
	 */
	public function countTransactionsInState($state) {
		$res = $this->_aws_ddb_client->scan(array(
					'TableName' => $this->_aws_transaction_table,
					'Count' => TRUE,
					'ScanFilter' => array(
						'state' => array(
							'AttributeValueList' => array(
								array('S' => $state)
								),
							'ComparisonOperator' => 'EQ'
							),
						)
					));

		return intval($res->get('Count'));

	}

	/**
	 * Set transaction state
	 */
	public function setTransactionState($id, $state) {
		$result = $this->_aws_ddb_client->updateItem(array(
					'TableName' => $this->_aws_transaction_table,
					'Key' => array(
						'transactionId'   => array('S' => $id),
						),
					'AttributeUpdates' => array(
						'state' => array('Value' => array('S' => $state),'Action' => 'PUT')
						)
					)
				);
	}

	/**
	 * Set transaction state
	 */
	public function getTransactionState($id) {
	  $transaction = $this->getTransactionFromStore($id);
	  return ($transaction && isset($transaction['state'])) ? $transaction['state'] : NULL;
	}

	/**
	 * Get the number of times we have transmitted the transaction request
	 */
	public function incrementTransactionTransmissionCount($id) {
	  $transaction = $this->getTransactionFromStore($id);
	  if($transaction && isset($transaction['transmissionCount'])) {
	    $count = $transaction['transmissionCount'];
	    $count++;
		$result = $this->_aws_ddb_client->updateItem(array(
					'TableName' => $this->_aws_transaction_table,
					'Key' => array(
						'transactionId'   => array('S' => $id),
						),
					'AttributeUpdates' => array(
						'transmissionCount' => array('Value' => array('N' => $count),'Action' => 'PUT')
						)
					)
				);
	  }
	}

	/**
	 * Return the number if times we have transmitted the transaction
	 */
	public function getTransactionTransmissionCount($id) {
	  $transaction = $this->getTransactionFromStore($id);
	  return ($transaction && isset($transaction['transmissionCount'])) ? $transaction['transmissionCount'] : -1;
	}

}
