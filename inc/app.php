<?
//localization
$cur_language=array();
function _tr($str){
	global $cur_language;
	foreach($cur_language as $index=>$value){
		if($value===$str){
			return $cur_language['str_tr_'.substr($index,9)];
		}
	}
	return $str;
}

//error recording
function record_error($blob){
	global $db;
	if(isset($_SERVER ['HTTP_X_FORWARDED_FOR'])){
		$clientIP = $_SERVER ['HTTP_X_FORWARDED_FOR'];
	}elseif(isset($_SERVER ['HTTP_X_REAL_IP'])){
		$clientIP = $_SERVER ['HTTP_X_REAL_IP'];
	}else{
		$clientIP = $_SERVER['REMOTE_ADDR'];
	}
	$error_query=$db->prepare('insert into `error_log` (`data`,`date`,`ip`) values (?, ?, ?)');
	return $error_query->execute(array($blob,date('Y-m-d H:i:s'),$clientIP));
}


//return activated date of a fb user
//defaults to currently logged in user if none specified
function user_activated($fb_id=false){
	global $user, $db;
	if($fb_id===false) $fb_id=$user;
	if($fb_id===false) return false;
	$user_query=$db->prepare('select * from `user` where `fb_id` = ?');
	$user_query->execute(array($fb_id));
	$user_row=$user_query->fetchAll();
	if(count($user_row)===0) return false;
	return $user_row[0]['activated'];
}

//load the current user's transactions
function load_tx(){
	global $db, $user, $tx_data, $tx_count, $balance, $fb_tx_fee, 
		$display_count, $ordered_tx, $last_page, $display_page, $tx_per_page;
	$tx_query=$db->prepare('select * from `tx` where `fb_id` = ? order by `tx_id` asc');
	$tx_query->execute(array($user));
	$tx_data=$tx_query->fetchAll();
	
	//calculate balance
	$balance='0';
	$display_count=$tx_count=count($tx_data);
	for($i=0;$i<$tx_count;++$i){
		if((int)$tx_data[$i]['type']===1){
			$balance=$tx_data[$i]['balance']=$balance+$tx_data[$i]['amount'];
		}elseif((int)$tx_data[$i]['type']===2){
			if($tx_data[$i]['fb_recip']==='fee'){
				--$display_count;
			}else{
				$c_tx_fee=$i+1<$tx_count && $tx_data[$i+1]['fb_recip']==='fee' ? $tx_data[$i+1]['amount'] : 0;
				$balance=$tx_data[$i]['balance']=$balance-$tx_data[$i]['amount']-$c_tx_fee;
			}
		}
	}
	$ordered_tx=array_reverse($tx_data);
	$last_page=(int)ceil($display_count/$tx_per_page);
	$display_page=isset($_GET['p']) && is_numeric($_GET['p']) && (int)$_GET['p']<=$last_page ? (int)$_GET['p'] : (int)1;
}

function revert_old_tx(){
	global $db, $user, $revert_duration;
	$revert_query=$db->prepare("SELECT `tx`.`tx_id`,`tx`.`fb_id`,`tx`.`amount` FROM `tx` left join `user` on `user`.`fb_id`=`tx`.`fb_id` where `fb_recip` = ? and `date` < ? and `type`=1 and `user`.`activated` is null");
	$revert_query->execute(array($user, date('Y-m-d',strtotime('-'.$revert_duration))));
	$revert_data=$revert_query->fetchAll();

	if(count($revert_data)){
		$revert_tx_query=$db->prepare("UPDATE `tx` SET `fb_id`='revert', `type`=3, `hash`=? WHERE `tx_id`=?");
		$revert_tx_query_2=$db->prepare('insert into `tx` (`fb_id`,`type`,`btc_addr`,`fb_recip`,`date`,`amount`,`hash`) values (?, ?, ?, ?, ?, ?, ?)');
		foreach($revert_data as $tx){
			if($revert_tx_query->execute(array($tx['fb_id'],$tx['tx_id']))){
				$revert_tx_query_2->execute(array($user,'1',null,$tx['fb_id'],date('Y-m-d H:i:s'),$tx['amount'],'revert'));
			}
		}
	}
}

function random_string($length = 20) {
	$characters = '1234567890abcdefghijklmnopqrstuvwxyz';
	$string = "";    
	for ($p = 0; $p < $length; $p++) {
	    $string .= $characters[mt_rand(0, strlen($characters)-1)];
	}
	return $string;
}

