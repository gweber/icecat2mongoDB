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
		'http' => array(
			'header'  => "Authorization: Basic " . base64_encode("$icecat_user:$icecat_pass") . 
				"\nAccept-Encoding: gzip\n" 
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
				$product[supplier] 	= 	(int) $value['Supplier_id'];
				$product[cat_id]	=	(int) $value['Catid'];
				$product[sku] 		= 	$value['Prod_ID'];
				$product[name]	= 	$value['Model_Name'];
				$product[topseller]	=	(int) $value['Product_View'];
				
				if ( $value['Quality'] == 'REMOVED' ) {
					
					$delete++;
					
					$mdb_product->update(
						array( 'product_id' => $product[id] ),  
						array(
							'$set' => array(
								'status' => (int) 4,  
								'actor' => 'daily checker remove',
								'data_quality'	=>	'REMOVED',
								'update' => new MongoDate()
							) 
						) 
					); 
					
					//$del_q = mysql_query("delete from product_feature where product_id in ($alldelete_str)");
					//$del_q = mysql_query("delete from product_description where product_id in ($alldelete_str)");
					//echo "REMOVE: ". mysql_affected_rows($del_q) . " descriptions deleted\n";
	
					// delete related product if it's removed, keep them if not
					//$del_q = mysql_query("delete from product_related where rel_product_id in ($remove_str)");
					//echo "REMOVE: ". mysql_affected_rows($del_q) . " to-relations  deleted\n";
					
					// delete all relations from the products, removed or reset; on reset, it will be read and parsed afterwards
					//mysql_query("delete from product_related where product_id in ($alldelete_str)");
					//echo "REMOVE: ". mysql_affected_rows($del_q) . " from-relations deleted\n";
					
				}else {
					
					// quality is ice or supplier
					
					// product not found (new?)
					if  ( !$f_product = $mdb_product->findOne( array('product_id' => (int)$product[id]  ) ) ) {
						$mdb_product->insert(array (
							'product_id' 	=> 	(int) $product[id],
							'status' 		=> 	(int) 5, 
							'actor' 		=> 	'daily checker insert', 
							'supplier_id'	=>	(int) $product[supplier],
							'product_sku'	=>	$product[sku],
							'update'		=> 	new MongoDate(), 
							'data_quality'	=>	$value[Quality],
							'category'		=>	(int) $product[cat_id],
							'name'		=>	$product[name],
							'topseller'		=>	$product[topseller]
							)    
						);
						debug(1,"$product[id] inserted and marked for fetch\n");
					} else {
						$mdb_product->update(
							array('product_id' => (int) $product[id] ),  
							array('$set' => array(
									'status' 		=> 	(int) 5, 
									'actor' 		=> 	'daily checker update', 
									'supplier_id'	=>	(int) $product[supplier],
									'product_sku'	=>	$product[sku],
									'update'		=> 	new MongoDate(), 
									'data_quality'	=>	$value[Quality],
									'category'		=>	(int) $product[cat_id],
									'name'		=>	$product[name],
									'topseller'		=>	$product[topseller]
								) 
							) 
						);
						// delete makes no sense if there was no product in db
						deleteFile($product[id]);
						debug(1,"$product[id] marked for revalidation\n");
					}
					
				}
			}
		}		
	}
} else {
	echo "error on file_str\n";
}



?>