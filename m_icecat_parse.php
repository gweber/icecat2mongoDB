<?php
//Ego Dominus Deus tuus, qui eduxi te de terra Aegypti, de domo servitutis.
//config

$mongo = new Mongo();
$mdb = $mongo->icecat;
$mdb_product = $mdb->product;

@include "config.inc.php";

$config[related_insert]			= true;
$config[related_unknown_insert]	= true;

// some globals
$known_vocabulary	=	array();	// saves known voc ids to reduce queries


# debug: 		0	try to be quiet
#			1	show only some strings (does sql, no show off it)
#			2	turn sql off
#			4	show sql queries

$debug = 5;

function debug ($level, $message){
	global $debug,$id;
	if ($debug & $level ){
		echo "debug[$level|$id]: $message";
	}
}

function echocolor($text,$color="normal",$back=0) { 
  $colors = array('light_red'  => "[1;31m", 'light_green' => "[1;32m", 'yellow'     => "[1;33m", 
                  'light_blue' => "[1;34m", 'magenta'     => "[1;35m", 'light_cyan' => "[1;36m", 
                  'white'      => "[1;37m", 'normal'      => "[0m",    'black'      => "[0;30m", 
                  'red'        => "[0;31m", 'green'       => "[0;32m", 'brown'      => "[0;33m", 
                  'blue'       => "[0;34m", 'cyan'        => "[0;36m", 'bold'       => "[1m", 
                  'underscore' => "[4m",    'reverse'     => "[7m" ); 
  $out = $colors["$color"]; 
  $ech = chr(27)."$out"."$text".chr(27)."[0m"; 
  if($back)   { 
    return $ech; 
  }  else { 
    echo $ech; 
  } 
}


function check_related_products( $related ){
	global $id;
	$insert = $delete = 0;
	// load all related ids into known array, speedup on lookups and check if products were removed
	$check_q = mysql_query("select product_related_id from product_related where product_id='$id'");
	if (mysql_num_rows($check_q)){
		while ($check_f=mysql_fetch_row($check_q)){
			$known[$check_f[0]] = 1;
		}
	}
	
	if (count($related) ) {				
		foreach ($related as $k => $v){
		
			$pr['PRID'] 			= $v["@attributes"]["ID"];
			$pr['Category_ID']		= $v["@attributes"]["Category_ID"];
			$pr['Preferred']			= $v["@attributes"]["Preferred"];
			$pr['product_id']  		= $v["Product"]["@attributes"]["ID"];
			$pr['Prod_id']	 		= mysql_real_escape_string($v["Product"]["@attributes"]["Prod_id"]);
			$pr['Name'] 			= mysql_real_escape_string($v["Product"]["@attributes"]["Name"]);
			$pr['ThumbPic'] 		= mysql_real_escape_string($v["Product"]["@attributes"]["ThumbPic"]);
			$pr['supplier_id']		= $v["Product"]["Supplier"]["@attributes"]["ID"];
			$pr['supplier_name']		= $v["Product"]["Supplier"]["@attributes"]["Name"];
			
			if (strlen($pr[product_id])) {			
				if (!$known[$pr[PRID]]  ){ // product is not known as related
					$sql_insert[]=array('relatedID' =>"$pr[PRID]", 'id' =>"$id", 'relatedPrID' => "$pr[product_id]", 'Preferred' => "$pr[Preferred]");
				} else {
					unset($known[$pr[PRID]] ); // delete the key from array
				}
				// if product is not known in generell, insert it
				if (!known_product($pr[product_id]) ){	
					// insert the related product metadata
					mydb_query("insert into product (product_id, supplier_id, prod_id, user_id, name, thumb_pic) values ( '$pr[product_id]', '$pr[supplier_id]', '$pr[Prod_id]', 1, '$pr[Name]', '$pr[ThumbPic]' )",1);
					debug(1, echocolor("product with metadata inserted $pr[product_id] $pr[supplier_id] $pr[Name]\n",'cyan',1) );
				}else {	// product is known, but not as related ... do some update if needed (userid <= 10)
					update_db( 'product', $pr[product_id], 
							array(	'supplier_id'	=>	$pr[supplier_id], 
									'thumb_pic'	=>	$pr[ThumbPic]	, 
									'name'		=>	$pr[Name], 
									'catid'		=>	$pr[Category_ID] 
							),
							" and user_id <=10"
					);
				}
			}
		}
		if  (count($sql_insert)){
			foreach($sql_insert as $k => $v){
				if ($sql_pr_values) { $sql_pr_values .= ",";}
				$sql_pr_values .= "('$v[relatedID]', '$v[id]', '$v[relatedPrID]', '$v[Preferred]') ";
				$insert++;
			}
			mydb_query("insert into product_related (product_related_id, product_id, rel_product_id, preferred_option) values $sql_pr_values",1);
		}
		// if there is something left in $known, it's to delete
		if (count($known)){
			foreach ($known as $kk => $kv){
				if ($sql_delete){ $sql_delete .= ","; }
				$sql_delete .= $kk;
				$delete++;
			}
			mydb_query("delete from product_related where product_related_id in ($sql_delete) ",1);
		}
	}
	debug(1,"product related: ". echocolor($insert,'green',1) ." inserted, " . echocolor($delete,'red',1) ." deleted\n");
}
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////
///////////////////////////////////////////////////////////////////////////////

function getFile($id){
	global $xmlstorage;
	$file_str='';
	if (file_exists($xmlstorage . $id .".xml") ) {	// is file already in fetch_data?
		$file_str = file_get_contents($xmlstorage . $id .".xml");	// load the file as string
		debug(1,"file " . $xmlstorage. $id .".xml locally known and read\n");
	} else if (file_exists($xmlstorage . $id .".xml.gz") ){
		$filename = $xmlstorage . $id .".xml.gz";
		$file_str = gzinflate( substr( file_get_contents($filename), 11) );
		//$file_str = shell_exec("zcat $filename");
		debug(1, echocolor( "file " . $filename . " known, read ". strlen($file_str) ." bytes\n", 'green', 1) );
	} else {
		debug(1, echocolor("unknown file error\n",'red',1) );
		return false;
	}
	return  json_decode(json_encode(simplexml_load_string($file_str)), TRUE);
}

$lastid=0;
while(1){
	if ( $db_product_array = $mdb->product->findOne( array(
			'status' => 10, 
			//'icecat_id' => array('$gt' => $lastid) 
		))){
		$history = array();
		$history[$db_product_array['update']->sec] = array('actor' =>$db_product_array['actor'], 'status' =>$db_product_array['status'] );
		
		$id = $db_product_array['icecat_id'];
		$lastid=$id;
		//debug(1,"$id " . $db_product_array[name] ." loaded from mongoDB\n");
		$product_array = array();
		$xml_array = array();
		$xml_array = getFile($id);
		
		if ( count($xml_array) ) {
			$feature = array();
			$cf	= array();
			$cfg  = array();
			$fg = array();
			$cfg_array = array();
			$pf = array();
			$pfg = array();
		
			$product_array=$xml_array['Product']['@attributes'];
			if (count($product_array)){
		
				// remove the product
				//$mdb_product->remove(	array('_id' => $db_product_array['_id']), true );
			
				$manufacturer_id =  (int) $xml_array['Product']['Supplier']['@attributes']['ID'];
				$category = $xml_array['Product']['Category'];
				$cat_id = (int) $category['@attributes']['ID'];
			
				//'product_sku'	=>	$product[sku],
				$update_array= array();
				$update_array = array(
					'icecat_id'			=> (int) $id,
					'status' 			=> 	(int) 20, 
					'name'			=> $product_array['Name'],
					'manufacturer_id' 	=> (int) $manufacturer_id,
					'product_sku'		=> $product_array['Prod_id'],
					'category'			=> (int) $cat_id,
					'data_quality'		=> $product_array['Quality'],
					'update'			=> 	new MongoDate(), 
					'actor' 			=> 	'parser'
				);
			
				if ($xml_array['Product']['SummaryDescription']['ShortSummaryDescription']){
					$update_array['ShortSummary'] = $xml_array['Product']['SummaryDescription']['ShortSummaryDescription'];
				}else {
					debug(1,"no short desc\n");
				}
				if ($xml_array['Product']['SummaryDescription']['LongSummaryDescription']){
					$update_array['LongSummary'] = $xml_array['Product']['SummaryDescription']['LongSummaryDescription'];
				}else {
					debug(1,"no long desc\n");
				}
							
				if ( $product_array['LowPic'] ){
					$update_array['LowPic'] = $product_array['LowPic'];
					if ( $product_array['LowPicSize'] ){
						$update_array['LowPicSize'] = (int) $product_array['LowPicSize'];
					}
				}

				if ( $product_array['HighPic'] ){
					$update_array['HighPic'] = $product_array['HighPic'];
					if ( $product_array['HighPicSize'] ){
						$update_array['HighPicSize'] = (int) $product_array['HighPicSize'];
					}
				}
				if ( $product_array['ThumbPic'] ){
					$update_array['ThumbPic'] = $product_array['ThumbPic'];
					if ( $product_array['ThumbPicSize'] ){
						$update_array['ThumbPicSize'] = (int) $product_array['ThumbPicSize'];
					}
				}
				
				$product_family_id = $xml_array["Product"]["ProductFamily"]["@attributes"]["ID"];
				if (strlen($product_family_id)){
					//debug(1,"product family: $product_family_id\n");
					$update_array[product_family] = (int) $product_family_id;
				}
				foreach ($xml_array["Product"]["EANCode"] as $k => $v){
					$gtin = trim($v['EAN']);
					if (strlen($gtin)){
						//debug(1,"eancode: $gtin\n");
						$update_array[gtin][] = $gtin;
					}
				}
				
				if (count($xml_array['Product']['ProductDescription'])){
					$desc = $xml_array['Product']['ProductDescription']['@attributes'];
					//debug(1,"description: ". $desc['ID'] ." ". $desc['LongDesc'] ."\n");
					
					if ($desc[langid]){
						//			'". mysql_real_escape_string($desc['langid']) ."',
					}
					
					if ($desc[ShortDesc]){
						$update_array[ShortDesc] = $desc['ShortDesc'];
					}
					if ($desc[LongDesc]){
						$update_array[LongDesc] = $desc['LongDesc'];
					}
					if ($desc[WarrantyInfo]){
						$update_array[WarrantyInfo] = $desc['WarrantyInfo'];
					}
					if ($desc[URL]){
						$update_array[URL] = $desc['URL'];
					}
					if ($desc[PDFURL]){
						$update_array[PDFURL] = $desc['PDFURL'];
						if ($desc[PDFSize]){
							$update_array[PDFSize] = (int) $desc['PDFSize'];
						}
					}
					
					if ($desc[ManualPDFURL]){
						$update_array[ManualPDFURL] = $desc['ManualPDFURL'];
						if ($desc[ManualPDFSize]){
							$update_array[ManualPDFSize] = (int) $desc['ManualPDFSize'];
						}
					}
				} else {
					//debug(1,"no description available\n"); // yeah sad but true
				}
				
				foreach ($xml_array["Product"]["CategoryFeatureGroup"] as $k => $v){
					$cfg_id = $v['@attributes']['ID'];
					$fg[cfg_No] = $v['@attributes']['No'];
					$fg[id] =  $v['FeatureGroup']['@attributes']['ID'];
					$fg[value] =  $v['FeatureGroup']['Name']['@attributes']['Value'];
					$fg[langid] =  $v['FeatureGroup']['Name']['@attributes']['langid'];
					$fg[nameid]=  $v['FeatureGroup']['Name']['@attributes']['ID'];
					$cfg_array[$cfg_id] = $fg;
				}
				
				/*
				foreach ($xml_array["Product"]["ProductFeature"] as $k => $v) {
					if ( !strlen($v['@attributes']['Value'])) { $v['@attributes']['Value'] = $v['@attributes']['Presentation_Value']; }
					if ( strlen($v['@attributes']['Value'])){
						update_db('product_feature', $v['@attributes']['ID'], array(
							"product_id"			=>	$id,
							"category_feature_id"	=> 	$v['@attributes']['CategoryFeature_ID'],
							"value"				=>	$v['@attributes']['Value']),
							'', 1
						);
						
						$feature[id]			= $v['Feature']['@attributes']['ID'];
						$feature[name_id]		= $v['Feature']['Name']['@attributes']['ID'];
						$feature[name_langid]	= $v['Feature']['Name']['@attributes']['langid'];
						$feature[name_value]	= $v['Feature']['Name']['@attributes']['Value'];
						$feature[measure_id] 	= $v['Feature']['Measure']['@attributes']['ID'];
						
						if (strlen($feature[id])){
							if (!$checked[f][$feature[id]]){
								if ( $feature_query = mydb_query("select feature_id,sid from feature where feature_id = '$feature[id]'",1) ){
									$sid=$feature_query[1];
								}
								voc_check($feature[name_id], $sid, $feature[name_langid], $feature[name_value]);
								$checked[f][$feature[id]]=1;
							}
							// feature_group
							$cfg[id] = $v['@attributes']['CategoryFeatureGroup_ID'];
							$cfg[no] = $cfg_array[$cfg[id]]['cfg_No'];
							$cf[id] = $v['@attributes']['CategoryFeature_ID'];
							$fg[id] = $cfg_array[$cfg[id]][id];
							if (strlen($fg[id]) ){
								
								if ( !$checked[fg][$fg[id]]) {
									if ( $fg_query = mydb_query("select feature_group_id, sid from feature_group where feature_group_id = '$fg[id]'",1) ) {
										$sid = $fg_query[1];
									} else  {
										$sid = getsid();
										mydb_query("insert into feature_group_id, sid) values ($fg[id], $sid)",1);
										debug(1, echocolor("feature_group inserted $fg[id], $sid\n", 'red', 1) );
									}
									voc_check($cfg_array[$cfg[id]][nameid], $sid, $cfg_array[$cfg[id]][langid], $cfg_array[$cfg[id]][value]);
									$checked[fg][$fg[id]]=1;
								}
								if (strlen($cfg[id])){
									if (!$checked[cfg][$cfg[id]]){
										update_db('category_feature_group', $cfg[id], 
											array(
												"catid"			=>	$cat_id,
												"No" 			=> 	$cfg[no],
												"feature_group_id"	=>	$fg[id]
											),'',1);
										$checked[cfg][$cfg[id]] = 1;	
									}
									
									
									if (strlen($cf[id])){
										if ( !$checked[cf][$cf[id]]) {
											update_db('category_feature', $cf[id], 
												array(
													"feature_id"				=>	$feature[id],
													"catid"					=>	$cat_id,
													"no"						=>	$v['@attributes']['No'],
													"searchable"				=>	$v['@attributes']['Searchable'],
													"category_feature_group_id"	=>	$cfg[id],
													"mandatory"				=>	$v['@attributes']['Mandatory']
												), '', 1);
											$checked[cf][$cf[id]] = 1;
										}
									} // cf id
								} // cfg id
							}//fg id
						} // feature id
					} // strlen feature value
				}
				*/
				
				/*
				$delarray = array();
				$ignore_fields = array("copid","prices","topseller");
				foreach($db_product_array as $key => $value){
					// ignore keys in ignore_fields
					if ($key <> '_id' && ! in_array($key, $ignore_fields) ){	// ign _id 
						// if update_array hasn't the key, unset the field in product
						if ( !$update_array[$key] ){ 
							$delarray[$key] = 1;
						}
					}
				}
				if (count($delarray)){
					$mdb_product->update(
						array('icecat_id' => $id),  
						array('$unset' =>  $delarray )
					);
					debug(1,"unsetting $id \n");
				}
				*/
				
				$mdb_product->update(
						array('icecat_id' => $id),  
						array(
							'$set' 		=> $update_array,
							'$addToSet'	=> array('history' => $history)							
						),
						array('upsert' => true)
				);
				if ($debug){
					//var_dump($update_array);
					//echo "next product\n";
				} else {
					//echo "$id parsed\n";
				}
				
			} else {// product array	
				"no product_array ...\n";
				if (file_exists($xmlstorage . $id .".xml.gz") ){
					unlink($xmlstorage . $id.".xml.gz");
				}
				$mdb_product->update(
					array('icecat_id' => $id),  
					array(
						'$set' 		=> array('status' => (int) 5, 'actor' => 'parser no product array' ) ,
						'$addToSet'	=> array('history' => $history)
					) 
				);
				debug(1,echocolor("$id marked for refetch\n","red",1));

			}
			$wait = fgets(STDIN);
	
		} else { // error in xml-handling?
			if (file_exists($xmlstorage . $id .".xml.gz") ){
				unlink($xmlstorage . $id.".xml.gz");
			}
			$mdb_product->update(
				array('icecat_id' => $id),  
				array(
					'$set' 		=> array('status' => (int) 5, 'actor' => 'parser error on xml' ) ,
					'$addToSet'	=> array('history' => $history)				
				)
			);
			debug(1,echocolor("$id marked for refetch\n","red",1));
		}// count xml array

	} else {
		// sleep and wait for new data
		sleep(10);
	}
} // while 1

?>
