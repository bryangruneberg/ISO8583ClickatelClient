<?php 

require_once('ITransactionPacketCreator.php');

class CClickatelTransactionPacketCreator implements ITransactionPacketCreator {
  public static function filterCreatePacketFromRawData($data) {
    throw new Exception('You should implement a ITransactionPacketCreator');
  }
} 
