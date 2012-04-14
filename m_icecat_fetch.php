<?php

@include "config.inc.php"

$context = stream_context_create(array(
	'http' => array(
		'header'  => "Authorization: Basic " . base64_encode("$icecat_user:$icecat_pass") . "\n" . 
			"Accept-Encoding: gzip\n" 
	)
));


$mongo = new Mongo();
$mdb = $mongo->icecat;
$mdb_product = $mdb->product;

while (1){
	// high prio fetch first
	if (! $db_product = $mdb_product->findOne(array( 'status' => 5, 'product_id'	=> array( '$gt' => 1) ) ) ) {
		// normal fetch
		if (! $db_product = $mdb_product->findOne(array( 'status' => 1, 'product_id'	=> array( '$gt' => 1) ) ) ) {
			// nothing to do? weird
			exit("done\n");
		}
	}
	$id = $db_product['product_id'];
	//var_dump($db_product);
	echo "$id => ";
	
	if ( $id && is_numeric($id) ){
		$status = 0;
		// if file exists, read it, else just get it, update user_id and get next
		if ( file_exists( $xmlstorage . $id .".xml") ) {	// is file already in fetch_data?
			echo "file " . $xmlstorage . $id .".xml locally known\n";
			$status = 10;
		} else if ( file_exists( $xmlstorage . $id .".xml.gz") ){
			echo "file " . $xmlstorage . $id .".xml.gz locally known\n";
			$status = 10;
		} else {	
			$readurl = $iceurl . $id .".xml";
			echo "[" . $db_product[status] . "] ";
			echo "read => ";
			$file_str = file_get_contents($readurl,false,$context);	// load the file over http
					if ($file_str) {	// read was successful
				file_put_contents($xmlstorage . $id .".xml.gz", $file_str);
				echo strlen($file_str) ." bytes fetched and gz written\n";
				$status = 10;
			} else {
				echo "no result from fetch\n";
				$status = 2;
			}
		}
		$mdb_product->update(
				array('product_id' => $id),  
				array('$set' => array('status' => (int) $status,  'actor' => 'file fetcher', 'update' => new MongoDate() ) ) 
		);
	} // if id
$wait = fgets(STDIN);
} // while 1
?>
