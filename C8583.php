<?php
include_once('JAK8583.class.php');

class C8583 extends JAK8583 {
	public function getTwoByteVLI($data_length, $has_secondary_bitmap) {

		$full_data_length = 4; // First we have the MTI
		$full_data_length += 8; // 8 bytes for the primary BITMAP
		if($has_secondary_bitmap) {
			$full_data_length += 8; // 8 bytes for the secondard BITMAP
		}
		$full_data_length += $data_length;

		$full_result = (float)($full_data_length / 256);
		$quotient = (int)$full_result;
		$remainder = (int) (($full_result - $quotient)*256);
		return array($quotient, $remainder);
	}

	public function getLengthFromTwoByteVLI($byte1, $byte2) {
		$full_result = ($byte1 + ($byte2 / 256)) * 256;
		return $full_result;
	}

	//calculate bitmap array data from data elements
	public function getBitMapArray() {	
		$tmp	= sprintf("%064d", 0);    
		$tmp2	= sprintf("%064d", 0);  
		$data = $this->getData();  
		foreach ($data as $key=>$val) {
			if ($key<65) {
				$tmp[$key-1]	= 1;
			}
			else {
				$tmp[0]	= 1;
				$tmp2[$key-65]	= 1;
			}
		}

		$ret = array(
				'has_secondary' => FALSE,
				'primary' => array(),
				'secondary' => array()
			    );

		// Work out the secondard BITMAP
		if ($tmp[0]==1) {
			$ret['has_secondary'] = TRUE;
			while ($tmp2!='') {
				$ret['secondary'][] = base_convert(substr($tmp2, 0, 8), 2, 10);
				$tmp2	= substr($tmp2, 8, strlen($tmp2)-8);
			}
		}

		// Work out the primary BITMAP
		while ($tmp!='') {
			$ret['primary'][] = base_convert(substr($tmp, 0, 8), 2, 10);
			$tmp	= substr($tmp, 8, strlen($tmp)-8);
		}

		return $ret;
	}

	public function getISOString($mti, $bma, $ren_data) {
		$isoString = $mti;
		foreach($bma['primary'] as $byte) {
			$str = str_pad(base_convert($byte, 10, 16), 2, "0", STR_PAD_LEFT);
			$isoString .= str_pad(base_convert($byte, 10, 16), 2, "0", STR_PAD_LEFT);
		}

		if($bma['has_secondary']) {
			foreach($bma['secondary'] as $byte) {
				$isoString .= str_pad(base_convert($byte, 10, 16), 2, "0", STR_PAD_LEFT);
			}
		}

		$isoString .= $ren_data;

		return $isoString;
	}    

}
