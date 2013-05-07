<?php
include_once('JAK8583.class.php');

class C8583 extends JAK8583 {
    public function getTwoByteVLI($data) {
        $full_result = (float)(strlen($data) / 256);
        $quotient = (int)$full_result;
        $remainder = (int) (($full_result - $quotient)*256);
        return array($quotient, $remainder);
    }

    public function getLengthFromTwoByteVLI($byte1, $byte2) {
        $full_result = ($byte1 + ($byte2 / 256)) * 256;
        return $full_result;
    }
}
