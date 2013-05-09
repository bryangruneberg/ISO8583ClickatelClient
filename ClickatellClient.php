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
	private $_max_transaction_wait_age = 30;
	private $_max_transaction_tries = 4;

	/* Private array to keep track of replies we are expecting */
	private $_pending_replies = array();
	private $_transaction_tracker = array();
	private $_transaction_raw_data_tracker = array();	

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

		for(;;) {
			try {
				$now = time();

				if($now - $this->_start_at >= $this->_max_client_age) {
				  $age = $now - $this->_start_at;
				  if(count($this->_pending_replies) <= 0) {
				     $this->logLine('I have been alive for ' .$age.' seconds, and there are no pending messages. Now is a good time for suicide.');
				     break;
				  }
				}

				if(count($this->_pending_replies)) {
					$this->logLine('We are waiting for replies, reading from the socket');
					foreach($this->_pending_replies as $tid => $data) {
						$age = time() - $data['loaded'];
						$this->logLine('Transaction ' .$tid.' is ' . $age . ' seconds old');
						if($age > $this->_max_transaction_wait_age) {
							if(isset($this->_pending_replies[$tid]['type']) && $this->_pending_replies[$tid]['type'] == 'echo-request') {
							  throw new ProtocolException('Network echo-request timed out. This should not happen.');
							}

							$this->logLine('Transaction ' .$tid.' is oldaged. Removing it from the pending queue');
							unset($this->_pending_replies[$tid]);
						}
					}

					if(count($this->_pending_replies)) {
						try {
							$this->rxPacket();
						} catch(TimeoutException $ex) {
							$this->logLine('No data received yet. Moving on...');
						}
					}
				}
				if(!$this->_last_echo_request || ($now - $this->_last_echo_request) >= $this->_seconds_between_network_ping) {
					$this->_last_echo_request = $now;
					$this->logLine('Time for echo request');
					$this->txNetworkEchoRequest();
					$this->logLine('Sent echo request');
				}

				if($rawRequestData = $this->_transaction_handler->getNextRequest()) {


					$creatorClass = $rawRequestData['request_data']['packet_creator_class'];
					$packet = NULL;

					try {
						$packet = $creatorClass::filterCreatePacketFromRawData($rawRequestData['request_data']);
						$stan = $this->getNextStan();
						$packet['11'] = $stan;
						ksort($packet);
						$this->_transaction_raw_data_tracker[$packet[18]] = $rawRequestData;
					} catch(Exception $ex) {
						$this->logLine('There was an issue creating the datapacket: ' . $ex->getMessage());
					}

					if(is_array($packet) && isset($this->_transaction_tracker[$packet[18]]) && $this->_transaction_tracker[$packet[18]] >= $this->_max_transaction_tries) {
						$this->logLine('Transaction retry count exceeded. Wont retry this transaction now.');
						$this->logLine('TODO: fail the packet using the handler');
					} else if(is_array($packet)) {
						$tries = (isset($this->_transaction_tracker[$packet[18]])) ? $this->_transaction_tracker[$packet[18]] + 1 : 1;
						$this->logLine('Sending data transaction request... [try '.$tries.']');
						$this->txTransactionRequest($packet[18], $packet, '0200');
						$this->logLine('Transmitted!');
					} else {
						$this->logLine('There was an issue creating the datapacket: NULL PACKET');
					}

				}


				sleep(rand(1,2));
			} catch(TimeoutException $ex) {
				$this->logLine('Timeout sending or receiving data');
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
		$transactionId = uniqid();
		$data = array(
				11 => $stan,
				18 => $transactionId,
				70 => 301,
			     );

		$this->_pending_replies[$transactionId] = array('data' => $data, 'mti' => 0800, 'loaded' => time(), 'type' => 'echo-request');
		$this->txPacket('0800', $data);
	}

	/**
	 * Send a transaction request over the wire
	 */
	public function txTransactionRequest($transactionId, $data, $mti = '0200') {
		if(!$this->_socket_fp) { throw new ConnectionException('Socket error'); }
	
		if(isset($this->_pending_replies[$transactionId])) {
		  $this->logLine('Transaction ' . $transactionId . ' has already been sent. Refusing to overwrite.');
		  return;
		}

		$this->_pending_replies[$transactionId] = array('data' => $data, 'mti' => $mti, 'loaded' => time(), 'type' => 'transaction');
		$this->_transaction_tracker[$transactionId] = isset( $this->_transaction_tracker[$transactionId] ) ?  $this->_transaction_tracker[$transactionId] + 1 : 1;
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
		fwrite($this->_socket_fp, pack('C', intval($header[0])) . pack('C', intval($header[1])));
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }

		// Now send the MTI
		fwrite($this->_socket_fp, $mti);
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }

		// Now send the bitmaps
		foreach($bma['primary'] as $byte) {
			fwrite($this->_socket_fp, pack('C', intval($byte)));
			$sstatus = stream_get_meta_data($this->_socket_fp);
			if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
			if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
		}
		if($bma['has_secondary']) {
			foreach($bma['secondary'] as $byte) {
				fwrite($this->_socket_fp, pack('C', intval($byte)));
				$sstatus = stream_get_meta_data($this->_socket_fp);
				if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
				if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
			}
		}

		// Now write the data
		fwrite($this->_socket_fp, implode($jak->getData()));
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
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
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
		$this->logLine('Got byte 1');

		// Get the second byte from the stream, ensuring the connection is decent
		$b2 = fread($this->_socket_fp, 1);
		$sstatus = stream_get_meta_data($this->_socket_fp);
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
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
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
		if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
		$this->logLine('Got MTI: ' . $mti);
		$ren -= 4;
		$this->logLine("REN NOW: " . $ren);

		// Get the primary BITMAP (8bytes)
		$bma = array('has_secondary' => FALSE, 'primary' => array(),'secondary' => array());
		for($i = 0; $i <= 7; $i++) {
			$b1 = fread($this->_socket_fp, 1);
			$sstatus = stream_get_meta_data($this->_socket_fp);
			if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
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
				if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
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
		if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
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
		if(!isset($this->_pending_replies[$jdata[18]])) { throw new ProtocolException('Received a Client Transaction ID that we do not know about.'); }

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
		// We can remove this from pending replies
		unset($this->_pending_replies[$jdata[18]]);
	}

	/**
	 * Process a transaction reply message
	 */
	public function rxTransactionResponse(C8583 $jak) {
		$this->logLine('MTI: Transaction Response');
		$jdata = $jak->getData();
		$rawData = isset($this->_transaction_raw_data_tracker[$jdata[18]]) ? $this->_transaction_raw_data_tracker[$jdata[18]] : array();
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

		  $this->_transaction_tracker[$jdata[18]] = 0;
		  unset($this->_transaction_raw_data_tracker[$jdata[18]]);
		}

		// We can remove this from pending replies
		unset($this->_pending_replies[$jdata[18]]);
	}
}
