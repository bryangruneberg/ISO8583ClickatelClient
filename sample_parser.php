<?php
include_once('JAK8583.class.php');

$iso = '08008020600000000000040000000000000012345619bryankellygruneberg03120301';
$iso = '0800002060000000000012345619bryankellygruneberg03120';
$iso = '08108020400020000000400000000000000020585713518aa071d49b00000301';

$jak	= new JAK8583();

//add data
$jak->addISO($iso);


//get parsing result
print 'ISO: '. $iso. "\n";
print 'MTI: '. $jak->getMTI(). "\n";
print 'Bitmap: '. $jak->getBitmap(). "\n";
print 'Data Element: '; print_r($jak->getData());



?>
