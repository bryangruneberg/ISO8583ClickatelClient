<?php
include_once('C8583.php');

/**
 * a simple exception for timeouts
 */
class TimeoutException extends Exception {}

/**
 * a simple exception for EOF
 */
class EOFException extends Exception {}

/**
 * a simple exception for Protocol Errors
 */
class ProtocolException extends Exception {}

/**
 * a simple exception for Connection Errors
 */
class ConnectionException extends Exception {}

/**
 * ClickatelClient class that will send and receive ISO8583 packets as created by the C8583 class
 */
class ClickatelClient {
	/* Private variables for socket management */
	private $_socket_fp;
	private $_socket_ip;
	private $_socket_port;
	private $_socket_errno;
	private $_socket_errstr;
	private $_socket_connect_timeout = 20;
	private $_socket_read_timeout = 8;
	private $_seconds_between_network_ping = 60;
	private $_max_transaction_tries = 4000;

	/* Private variable to keep track of the last time we send an echo request */
	private $_last_echo_request = 0;

	/* pointer to a IClickatellTransactionHandler instance */
	private $_transaction_handler = NULL;

	/* vars to track our age and suicide time */
	private $_start_at;
	private $_max_client_age = 4800; // 2 hours

	/**
	 * Constructor that takes an IP address and a Port
	 */
	public function __construct($ip, $port, IClickatellTransactionHandler $txHandler) {
		$this->_socket_ip = $ip;
		$this->_socket_port = $port;
		$this->_transaction_handler = $txHandler;

		$this->_start_at = time();
	} 

	/**
	 * Function to connect to the provided ip and port
	 */
	public function connect() {
		$this->_socket_fp = stream_socket_client("tcp://".$this->_socket_ip.":" . $this->_socket_port, $this->_socket_errno, $this->_socket_errstr, $this->_socket_connect_timeout);

		if (!$this->_socket_fp) {
			$this->logLine("Socket Not Connected: " . $this->_socket_errstr . " (".$this->_socket_errno . ")" );
			return FALSE;
		}

		$this->logLine("Socket Connected");
		stream_set_timeout($this->_socket_fp, $this->_socket_read_timeout);
		return TRUE;
	}

	/**
	 * Disconnect the socket, and clean up
	 */
	public function disconnect() {
		if($this->_socket_fp) { fclose($this->_socket_fp); }
		unset($this->_socket_fp);
	}

	/**
	 * Log a line (at the moment straight to console)
	 */
	public function logLine($line) {
		echo 'ClickClient: ' . date('Y-m-d H:i:s') . "] ";
		echo $line;
		echo "\n";
	} 

	/**
	 * Return the next Systems Trace Audit Number (rolling incremental number from 000001 to 999999)
	 */
	public function getNextStan() {
		if(!file_exists('stan.count')) { file_put_contents('stan.count', 0); }

		$current_stan = intval(file_get_contents('stan.count'));
		$current_stan++;

		file_put_contents('stan.count', $current_stan);

		return $current_stan;
	}

	/**
	 * The main activity loop
	 * This function sends and receives data
	 * Every _seconds_between_network_ping the loop will send an echo request
	 */
	public function loop() {
		if(!$this->_socket_fp) { throw new ConnectionException('Socket error'); }

		try {
			$transactionId = 'netecho';
			$transaction = $this->_transaction_handler->getTransactionFromStore($transactionId);
			if($transaction && $transaction['state'] == 'PENDING') {
				$this->logLine('Flushing out a stale netecho request');
				$this->_transaction_handler->setTransactionState($transactionId, 'NEW');
			}
		} catch(Exception $ex) {
				$this->logLine('Error checking for stale netecho requests.');
				return;
		}

		for(;;) {
			try {
				$now = time();

				$pending_transactions = intval($this->_transaction_handler->countTransactionsInState('PENDING'));

				if($now - $this->_start_at >= $this->_max_client_age) {
					$age = $now - $this->_start_at;
					if($pending_transactions <= 0) {
						$this->logLine('I have been alive for ' .$age.' seconds, and there are no pending messages. Now is a good time for suicide.');
						break;
					}
				}

				if(!$this->_last_echo_request || ($now - $this->_last_echo_request) >= $this->_seconds_between_network_ping) {
					$this->_last_echo_request = $now;
					$this->logLine('Time for echo request');
					$this->txNetworkEchoRequest();
					$this->logLine('Sent echo request');

					try {
						$this->rxPacket();
					} catch(TimeoutException $ex) {
						$this->logLine('No data received from echo-request yet. Moving on... ['.$ex->getMessage().']');
					}
				}

				if($rawRequestData = $this->_transaction_handler->getNextRequest()) {

					$creatorClass = $rawRequestData['request_data']['packet_creator_class'];
					$packet = NULL;

					try {
						$packet = $creatorClass::filterCreatePacketFromRawData($rawRequestData['request_data']);
						$stan = $this->getNextStan();
						$packet['11'] = $stan;
						ksort($packet);
						$transaction = $this->_transaction_handler->getTransactionFromStore($packet[18]);
						if(!$transaction) {
							$this->_transaction_handler->putTransactionInStore($packet[18], 'NEW', json_encode($rawRequestData));
						} else {
							$this->_transaction_handler->setTransactionData($packet[18], json_encode($rawRequestData));
						}
					} catch(Exception $ex) {
						$this->logLine('There was an issue creating the datapacket: ' . $ex->getMessage());
						$packet = NULL;
					}

					if(is_array($packet) && $this->_transaction_handler->getTransactionTransmissionCount($packet[18]) >= $this->_max_transaction_tries) {
						$this->logLine('Transaction retry count exceeded. Wont retry this transaction now.');

						if(isset($rawRequestData['uid']) && isset($rawRequestData['request_data'])) {
							$this->_transaction_handler->requestError($rawRequestData['uid'], $rawRequestData['request_data']);
						}
						$this->_transaction_handler->setTransactionState($packet[18], 'ERROR');
					} else if(is_array($packet)) {
						$tries = $this->_transaction_handler->getTransactionTransmissionCount($packet[18]) + 1;
						$this->logLine('Sending data transaction request... [try '.$tries.']');
						$this->txTransactionRequest($packet[18], $packet);
						$this->logLine('Transmitted!');
					} else {
						$this->logLine('There was an issue creating the datapacket: NULL PACKET');
					}

				}

				$pending_transactions = intval($this->_transaction_handler->countTransactionsInState('PENDING'));
				if($pending_transactions) {
					$this->logLine('We are waiting for replies, reading from the socket');
					try {
						$this->rxPacket();
					} catch(TimeoutException $ex) {
						$this->logLine('No data received yet. Moving on... ['.$ex->getMessage().']');
					}
				}


				sleep(rand(1,2));
			} catch(TimeoutException $ex) {
				$this->logLine('Timeout sending or receiving data... ['.$ex->getMessage().']');
				sleep(rand(1,2));
				continue;
			} catch(EOFException $ex) {
				$this->logLine('EXCEPTION: ' . $ex->getMessage());
				break;
			} catch(ProtocolException $ex) {
				$this->logLine('PROTOCOL EXCEPTION: ' . $ex->getMessage());
				break;
			} catch(ConnectionException $ex) {
				$this->logLine('SOCKET EXCEPTION: ' . $ex->getMessage());
				break;
			} catch(Exception $ex) {
				$this->logLine('EXCEPTION: ' . $ex->getMessage());
				break;
			}
		}
	}

	/**
	 * Send a network echo request over the wire
	 */
	public function txNetworkEchoRequest() {
		if(!$this->_socket_fp) { throw new ConnectionException('Socket error'); }


		$stan = $this->getNextStan();
		$transactionId = 'netecho';
		$data = array(
				11 => $stan,
				18 => $transactionId,
				70 => 301,
			     );

		$transaction = $this->_transaction_handler->getTransactionFromStore($transactionId);
		if(!$transaction) {
		  $this->_transaction_handler->putTransactionInStore($transactionId, 'NEW', json_encode($data));
		  $transaction = $this->_transaction_handler->getTransactionFromStore($transactionId);
		}

		if($transaction && $transaction['state'] == 'PENDING') {
		  throw new ProtocolException('Network echo request not received.');
		}

		$this->_transaction_handler->setTransactionState($transactionId, 'PENDING');

		$this->txPacket('0800', $data);
	}

	/**
	 * Send a transaction request over the wire
	 */
	public function txTransactionRequest($transactionId, $data) {
		if(!$this->_socket_fp) { throw new ConnectionException('Socket error'); }

		$transaction = $this->_transaction_handler->getTransactionFromStore($transactionId);
		if(!$transaction) {
			$this->logLine('Transaction ' . $transactionId . ' has not been stored. Refusing to transmit.');
			return;
		}


		$mti = '0200';
		if(isset($transaction['state']) && $transaction['state'] != 'NEW'  ) {
			$this->logLine('Transaction ' . $transactionId . ' is '.$transaction['state'].'. Setting MTI to 0201.');
			$mti = '0201';
		}

		if(isset($transaction['state']) && $transaction['state'] == 'NEW'  ) {
		  $this->_transaction_handler->setTransactionState($transactionId,'PENDING');
		}

		$this->_transaction_handler->incrementTransactionTransmissionCount($transactionId);
		$this->txPacket($mti, $data);
	}

	/**
	 * Transmit packet data to the server
	 */
	public function txPacket($mti, $data) {
		if(!$this->_socket_fp) { throw new ConnectionException('Socket error'); }

		$jak	= new C8583();

		//add data
		$jak->addMTI($mti);
		foreach($data as $key => $value) {
			$jak->addData($key, $value);
		}

		$bma = $jak->getBitMapArray();

		$header = $jak->getTwoByteVLI(strlen(implode($jak->getData())),$bma['has_secondary']);

		// First send the VLI
		$written = fwrite($this->_socket_fp, pack('C', intval($header[0])) . pack('C', intval($header[1])));
		if(!$written || $written != 2) { throw new TimeoutException('Timeout writing VLI'); }

		// Now send the MTI
		$written = fwrite($this->_socket_fp, $mti);
		if(!$written || $written != 4) { throw new TimeoutException('Timeout writing MTI'); }

		// Now send the bitmaps
		foreach($bma['primary'] as $byte) {
			$written = fwrite($this->_socket_fp, pack('C', intval($byte)));
			if(!$written || $written != 1) { throw new TimeoutException('Timeout writing a primary bitmap byte'); }
		}

		if($bma['has_secondary']) {
			foreach($bma['secondary'] as $byte) {
				$written = fwrite($this->_socket_fp, pack('C', intval($byte)));
				if(!$written || $written != 1) { throw new TimeoutException('Timeout writing a seondary bitmap byte'); }
			}
		}

		// Now write the data
		$dta = implode($jak->getData());
		$written = fwrite($this->_socket_fp, $dta);
		if(!$written || $written != strlen($dta)) { throw new TimeoutException('Timeout writing the data (dta)'); }
		
		$this->logLine('Transmitted ['.$mti.'] : ' . print_r($data, TRUE));
	}

	/**
	 * Receive packet data
	 */
	public function rxPacket() {
		if(!$this->_socket_fp) { throw new ConnectionException('Socket error'); }

		$jak = new C8583();

		// Get the first byte from the stream, ensuring the connection is decent
		$b1 = fread($this->_socket_fp, 1);
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout reading byte 1'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
		$this->logLine('Got byte 1');

		// Get the second byte from the stream, ensuring the connection is decent
		$b2 = fread($this->_socket_fp, 1);
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout reading byte 2'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
		$this->logLine('Got byte 2');

		$byte1 = array_shift(unpack('C', $b1));
		$byte2 = array_shift(unpack('C', $b2));

		// ren will hold the remote-length of the data we must expect
		$ren = $jak->getLengthFromTwoByteVLI($byte1, $byte2);
		$this->logLine("REN: " . $ren);

		// Get the 4 byte MTI
		$mti = fread($this->_socket_fp, 4);
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout reading MTI'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
		$this->logLine('Got MTI: ' . $mti);
		$ren -= 4;
		$this->logLine("REN NOW: " . $ren);

		// Get the primary BITMAP (8bytes)
		$bma = array('has_secondary' => FALSE, 'primary' => array(),'secondary' => array());
		for($i = 0; $i <= 7; $i++) {
			$b1 = fread($this->_socket_fp, 1);
			$sstatus = stream_get_meta_data($this->_socket_fp);
			if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout reading primary bitmap ['.$i.']'); }
			if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
			$ren -= 1;
			$bma['primary'][] = array_shift(unpack('C', $b1));
		}
		$this->logLine('Fetched primary bitmap');
		$this->logLine("REN NOW: " . $ren);

		if($bma['primary'][0] & 128) {
			$bma['has_secondary'] = TRUE;
		}

		if($bma['has_secondary']) {
			for($i = 0; $i <= 7; $i++) {
				$b1 = fread($this->_socket_fp, 1);
				$sstatus = stream_get_meta_data($this->_socket_fp);
				if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout reading secondary bitmap ['.$i.']'); }
				if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
				$ren -= 1;
				$bma['secondary'][] = array_shift(unpack('C', $b1));
			}
			$this->logLine('Fetched secondary bitmap');
			$this->logLine("REN NOW: " . $ren);
		}

		// Fetch $ren bytes of data from the stream, ensuring the connection is decent
		$ren_data = fread($this->_socket_fp, $ren);
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout reading data ['.$ren.']'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }

		$iso = $jak->getISOString($mti, $bma, $ren_data);

		$retJak = new C8583();
		$this->logLine("====== RESPONSE DATA FOLLOWS =======");

		//add data
		$retJak->addISO($iso);
		$this->logLine('MTI: '. $retJak->getMTI());
		$this->logLine('Data Element: ' . print_r($retJak->getData(), TRUE));
		$this->logLine("====== RESPONSE DATA DONE =======");

		$jdata = $retJak->getData();
		if(!isset($jdata[18])) { throw new ProtocolException('No Client Transaction ID found in packet'); }

		$transaction = $this->_transaction_handler->getTransactionFromStore($jdata[18]);	
		if(!$transaction) { throw new ProtocolException('Received a Client Transaction ID that we do not know about.'); }

		switch($retJak->getMTI()) {
			case '0810':
				$this->rxNetworkEchoReply($retJak);
				break;
			case '0210':
				$this->rxTransactionResponse($retJak);
				break;
			default:
				throw new ProtocolException('No idea what to do with MTI: ' . $retJak->getMTI());
		}
	}

	/**
	 * Process an echo reply message
	 */
	public function rxNetworkEchoReply(C8583 $jak) {
		$this->logLine('MTI: Echo Response');
		$jdata = $jak->getData();
		$this->_transaction_handler->setTransactionState($jdata[18],'COMPLETE');
	}

	/**
	 * Process a transaction reply message
	 */
	public function rxTransactionResponse(C8583 $jak) {
		$this->logLine('MTI: Transaction Response');
		$jdata = $jak->getData();

		$transaction = $this->_transaction_handler->getTransactionFromStore($jdata[18]);	
		if(!$transaction) { throw new ProtocolException('Trying to rxTransactionResponse on a transaction that we do not know about.'); }
		$rawData = json_decode($transaction['data'], TRUE);
		if(!$rawData) {
			$rawData = array();
		}

		if(is_array($rawData) && count($rawData) && isset($rawData['uid'])) {
			if(isset($jdata[39]) && $jdata[39] == "0000") {
				$rd = $rawData['request_data'];
				$rd['jdata'] = $jdata;
				$this->_transaction_handler->requestSucceess($rawData['uid'], $rd);
			} else {
				$rd = $rawData['request_data'];
				$rd['jdata'] = $jdata;
				$this->_transaction_handler->requestFailure($rawData['uid'], $rd);
			}
		}

		$this->_transaction_handler->setTransactionState($jdata[18],'COMPLETE');
	}
}
