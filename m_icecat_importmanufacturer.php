<?php

// step XX: for building a reliable stock, i import and rely on the icecat-data.
//	as is dont won't to be pending on one source, i abstrect even (hardly *g*) their data
//	as initial data in a virgin database, i ideal setup
//	TODO: as icecat sees a manufacturer as a supplier of data, this word is misleading. 
//	therefor, i will change the other scripts to reflect this matter.
//
//	you will need the csv of supplier from the icecat-homepage
//	create a collection "manufacturer" on the database


$mongo = new Mongo();
$mdb = $mongo->icecat;
$mdb_manufacturer = $mdb->manufacturer;

$manufacturer_file = file_get_contents("supplier.txt");

$line_array = explode("\n",$manufacturer_file);
$i = 0;
foreach ($line_array as $line => $linestr){
	if ( $i++ == 0) { continue; }	// skip first line (header)
	$fields = explode("\t",$linestr);
		
	$manufacturer = array();
	$manufacturer[icecat_id]	=	(int) $fields[0];
	$manufacturer[name] 		=	trim($fields[2]);
	$manufacturer[alias][] 		=	trim($fields[2]);	// add it to alias for the magic of find in array on matching
	
	if (strlen($fields[3])>1){
		$manufacturer[logo]	=	trim($fields[3]); 	// sounds good
	}
	if (strlen($fields[4])>1){
		$manufacturer[thumblogo]	=	trim($fields[4]); 	// sounds good
	}
	if (strlen($fields[13])>1 && $fields[13] != $fields[2] && !in_array($fields[13], $manufacturer[alias]) ){
		$manufacturer[alias][]		=	trim($fields[13]);
	}
	if (strlen($fields[15])>1 && $fields[15] != $fields[2] && !in_array($fields[15], $manufacturer[alias]) ){
		$manufacturer[alias][]		=	trim($fields[15]);
	}
	if (strlen($fields[16])>1){
		$manufacturer[sku_regex]	=	trim($fields[16]); 	// sounds good for later
	}
	
	echo $mdb_manufacturer->update(
				array('icecat_id' 	=> $manufacturer[manufacturer_id]),  
				array('$set' 		=> $manufacturer ),
				array('upsert' 		=> true)
	);
}




?>
