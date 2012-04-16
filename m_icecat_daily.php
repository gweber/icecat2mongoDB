<?php

//Ne te quaesiveris extra

$mongo = new Mongo();
$mdb = $mongo->icecat;
$mdb_product = $mdb->product;

@include "config.inc.php";

$debug	= 1;
function debug ($level, $message){
	global $debug;
	if ($debug & $level ){
		echo "debug[$level]: $message";
	}
}

function deleteFiles($idarray) {
	global $xmlstorage;
	foreach($idarray as $tempid => $tempvalue){
		if ( file_exists( $xmlstorage . $tempvalue .".xml") ) {	
			unlink($xmlstorage . $tempvalue .".xml");
			$files_deleted++;
		} else if ( file_exists( $xmlstorage . $tempvalue .".xml.gz") ){
			unlink($xmlstorage . $tempvalue .".xml.gz");
			$files_deleted++;
		} else {	
			// error: fnf
		}
	}
	debug(1,"DELETE: $files_deleted were removed\n");
}

function deleteFile($tempvalue) {
	global $xmlstorage;
	if ( file_exists( $xmlstorage . $tempvalue .".xml") ) {	
		unlink($xmlstorage . $tempvalue .".xml");
		$files_deleted++;
	} else if ( file_exists( $xmlstorage . $tempvalue .".xml.gz") ){
		debug(1,"file found\n");
		unlink($xmlstorage . $tempvalue .".xml.gz");
		$files_deleted++;
	} else {	
		debug(1,"file not found\n");
		// error: fnf
	}
	
	debug(1,"DELETE: $files_deleted were removed\n");
}

function getDaily() {
	global $icecat_user, $icecat_pass, $daily_url, $dailyxmlstorage;	
	
	$context = stream_context_create(array(
		'http' => array( 'header'  =>
			"Authorization: Basic " . base64_encode("$icecat_user:$icecat_pass") . "\n" . 
			"Accept-Encoding: gzip\n" 
		)
	));
	
	$today = date('Ymd');
	if ( file_exists( $dailyxmlstorage . $today .".xml") ) {	// is file already in fetch_data?
		debug(1, "file " . $dailyxmlstorage . $today .".xml locally known\n");
		return file_get_contents($dailyxmlstorage . $today.".xml");
	} else if ( file_exists( $dailyxmlstorage . $today .".xml.gz") ){
		debug(1, "file " . $dailyxmlstorage . $today .".xml.gz locally known\n");
		return gzinflate( substr( file_get_contents($dailyxmlstorage . $today . ".xml.gz"), 11) );
	} else {	 
		$readurl = $daily_url;
		$file_str = file_get_contents($readurl,false,$context);	// load the file over http
		if ($file_str) {	// read was successful
			file_put_contents($dailyxmlstorage . $today .".xml.gz", $file_str);
			debug(1, "daily: " . strlen($file_str) ." bytes fetched and ". $dailyxmlstorage . $today .".xml.gz written\n");
			return( gzinflate( substr($file_str, 11))  );
		} else {
			die("error on fetch");
		}
	}
}// function getDaily

if ($file_str = getDaily()){
	$xml_array = json_decode(json_encode(simplexml_load_string($file_str)), TRUE);

	foreach($xml_array["files.index"]["file"] as $k => $v){
		foreach ($v as $index => $value){
			if ($index == "@attributes"){
				
				$product[id] 		= 	(int) $value['Product_ID'];
				$product[manufacturer] 	= 	(int) $value['Supplier_id'];
				$product[cat_id]	=	(int) $value['Catid'];
				$product[sku] 		= 	$value['Prod_ID'];
				$product[name]	= 	$value['Model_Name'];
				$product[topseller]	=	(int) $value['Product_View'];
				
				$history = array();
				if ( $f_product = $mdb_product->findOne( array('icecat_id' => (int)$product[id]  )) ){
					$history[$f_product['update']->sec] = array('actor' =>$f_product['actor'], 'status' =>$f_product['status'] );
				}
				
				$update = array();
				if ( $value['Quality'] == 'REMOVED' ) {
					// just mark as remove, keep file for dont know right now
					$update['status']		= 	(int) 4;
					$update['actor']		=	'daily checker';
					$update['data_quality']	=	'REMOVED';
					debug(1,"$product[id] removed\n");
				}else {
					$update['icecat_id'] 		=	(int) $product[id] );
					$update['status']		= 	(int) 5;	// mark for high prio refetch
					$update['actor']		=	'daily checker';
					$update['data_quality']	=	$value[Quality];
					$update['manufacturer_id']	=	(int) $product[manufacturer];
					$update['product_sku']	=	$product[sku];
					$update['update']		= 	new MongoDate();
					$update['category']		=	(int) $product[cat_id];
					$update['name']		=	$product[name];
					$update['topseller']		=	$product[topseller];
					
					deleteFile($product[id]);
					debug(1,"$product[id] marked for refetch\n");
				}
				$mdb_product->update(
					array('icecat_id' => (int) $product[id] ),  
					array(
						'$addToSet'	=> array ('history' => $history), 
						'$set' 		=> $update
						) ,
					array('upsert'	=> true)
				);
			}
		}		
	}
} else {
	echo "error on file_str\n";
}



?>