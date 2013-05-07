<?php
include_once('JAK8583.class.php');

$jak	= new JAK8583();
$uid = 'bryankellygruneberg';
$rand = 123456;

//add data
$jak->addMTI('0800');
$jak->addData(19, 120);
$jak->addData(11, $rand);
$jak->addData(18, $uid);
//$jak->addData(70, '301');

//get iso string
print $uid . "\n";
print $rand . "\n";
print $jak->getISO();
print "\n";
print_r($jak->getData());
