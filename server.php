<?php
include_once('C8583.php');

define('SEC_SOCKET_TIMEOUT', 20);

class TimeoutException extends Exception {}
class EOFException extends Exception {}

$outbound_queue = array();

$socket = stream_socket_server("tcp://0.0.0.0:20007", $errno, $errstr);
if (!$socket) {
    echo "$errstr ($errno)\n";
} else {
    while ($conn = stream_socket_accept($socket)) {
        stream_set_timeout($conn, SEC_SOCKET_TIMEOUT);
        for(;;) {
            try {
                rxPacket($conn, $outbound_queue);
                while($data = array_shift($outbound_queue)) {
                  txPacket($conn, $data['mti'], $data['data']);
                }
            } catch(TimeoutException $ex) {
                logLine('Timed out sending or receiving data');
            } catch(EOFException $ex) {
                logLine('EXCEPTION: ' . $ex->getMessage());
                break;
            } catch(Exception $ex) {
                logLine('EXCEPTION: ' . $ex->getMessage());
                break;
            }
        }

        fclose($conn);
        logLine("Socket closed");
    }
    fclose($socket);
}

function txPacket($fp, $mti, $data) {
    if(!$fp) { throw new Exception('Socket error'); }

    logLine('Sending a: ' . $mti);
    $jak	= new C8583();
    $uid = uniqid();
    $rand = rand(1, 6);

    //add data
    $jak->addMTI($mti);
    foreach($data as $key => $value) {
      $jak->addData($key, $value);
    }

    $iso = $jak->getISO();
    $header = $jak->getTwoByteVLI($iso);
    fwrite($fp, pack('C', intval($header[0])) . pack('C', intval($header[1])) . $iso);
    $sstatus = stream_get_meta_data($fp);
    if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
    if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }
}

function rxPacket($fp, &$outbound_queue) {
    if(!$fp) { throw new Exception('Socket error'); }

    $jak	= new C8583();

    // Get the first byte from the stream, ensuring the connection is decent
    $b1 = fread($fp, 1);
    $sstatus = stream_get_meta_data($fp);
    if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
    if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }

    // Get the second byte from the stream, ensuring the connection is decent
    $b2 = fread($fp, 1);
    $sstatus = stream_get_meta_data($fp);
    if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
    if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }

    $byte1 = array_shift(unpack('C', $b1));
    $byte2 = array_shift(unpack('C', $b2));

    // ren will hold the remote-length of the data we must expect
    $ren = $jak->getLengthFromTwoByteVLI($byte1, $byte2);
    logLine("REN: " . $ren);

    // Fetch $ren bytes of data from the stream, ensuring the connection is decent
    $iso = fread($fp, $ren);
    $sstatus = stream_get_meta_data($fp);
    if(isset($sstatus['timed_out']) && $sstatus['timed_out'] == 1) { throw new TimeoutException('Timeout'); }
    if(isset($sstatus['eof']) && $sstatus['eof'] == 1) { throw new EOFException('EOF'); }

    //add data
    $jak->addISO($iso);
    logLine('ISO: '. $iso);
    logLine('MTI: '. $jak->getMTI());
    logLine('Bitmap: '. $jak->getBitmap());
    logLine('Data Element: ' . print_r($jak->getData(), TRUE));

    if($jak->getMTI() == '0800') {
      $jdata = $jak->getData();
      $jdata[39] = '0000';
      $outbound_queue[] = array('data' => $jdata,'mti' => '0810');
      logLine('Echo Request: Loaded reply packet');
    }

    if($jak->getMTI() == '0200') {
      $jdata = $jak->getData();
      $jdata[7] = date('mdHis');
      $jdata[21] = uniqid();
      $jdata[39] = '0000';
      $outbound_queue[] = array('data' => $jdata,'mti' => '0210');
      logLine('Transaction Request: Loaded reply packet');
    }
}


function logLine($line) {
    echo date('Y-m-d H:i:s') . "] ";
    echo $line;
    echo "\n";
} 
