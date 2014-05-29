<?php

require_once('ITransactionPacketCreator.php');

class CJustine2013TransactionPacketCreator implements ITransactionPacketCreator {

	public static function filterCreatePacketFromRawData($data) {
		$transactionId = uniqid();

		$_product_map = array(
				'59' => '60', // CellC
				'331' => '30', // MTN
				'1233' => '50', // 8TA
				'894' => '60', // Virgin Mobile (via CellC)
				'576' => '40', // Vodacom
				);

		if(!isset($data['entrant'])) { throw new Exception('Entrant data is required'); }
		if(!isset($data['entrant']['msisdn'])) { throw new Exception('Entrant MSISDN data is required'); }
		if(!isset($data['entrant']['network'])) { throw new Exception('Entrant Network data is required'); }
		if(!isset($_product_map[$data['entrant']['network']])) { throw new Exception('Entrant Network product does not map'); }
		if(!isset($data['prize'])) { throw new Exception('Prize data is required'); }
		if(!isset($data['prize']['id'])) { throw new Exception('Prize ID data is required'); }
		if(!isset($data['prize']['type'])) { throw new Exception('Prize TYPE data is required'); }

		$value = 0;
		switch($data['prize']['type']) {
		  case 'voucher250': $value=25000; break;
		  case 'voucher500': $value=50000; break;
		}

		$packet = array(
				4 => $value,
				12 => date('His'),
				13 => date('md'),
				15 => '0' . $data['entrant']['msisdn'],
				18 => 'js13pz' . $data['prize']['id'],
				19 => $_product_map[$data['entrant']['network']],
			       );

		return $packet;
	}
}
