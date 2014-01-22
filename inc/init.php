<?
require 'sdk/src/facebook.php';
	
//connect to facebook
$facebook = new Facebook($fb_settings);
$user = $facebook->getUser();

//initialize session
if(isset($_GET['sid'])){
	$a = session_id($_GET['sid']);
}else{
	$a = session_id();
}
if ($a == '') session_start();
if (!isset($_SESSION['safety'])) {
	session_regenerate_id(true);
	$_SESSION['safety'] = true;
}
$_SESSION['sessionid'] = session_id();


//connect to db
$db_url=parse_url(getenv("CLEARDB_DATABASE_URL"));
$db_dns='mysql:host='.$db_url["host"].';dbname='.substr($db_url["path"],1);
$db=new PDO($db_dns, $db_url["user"], $db_url["pass"]);

//perform callback from blockchain.info for adding funds
include 'inc/callback.php';

if($user){
	try {
		$user_profile = $facebook->api('/me');
		
		//load translation file if exists
		if(file_exists('trans/'.$user_profile['locale'].'.txt')){
			$cur_language=unserialize(file_get_contents('trans/'.$user_profile['locale'].'.txt'));
		}

	} catch (FacebookApiException $e) {
		error_log($e);
		$user = null;
	}
}

if($user){
	$friends=$facebook->api('/me/friends');
	$friendIds=array();
	foreach($friends['data'] as $friend){
		$friendIds[]=$friend['id'];
	}
	
	if(user_activated()===false){
		//user's first access!
		$user_create_query=$db->prepare('insert into `user` (`fb_id`,`activated`) values (?, ?)');
		$user_create_query->execute(array($user,date('Y-m-d H:i:s')));
	}
	
	//revert old transactions
	revert_old_tx();
	
	//load transactions
	load_tx();
	
	//get current btc send address
	$addr_query=$db->prepare('select `btc_addr` from `addr` where `fb_id` = ? order by `date` desc limit 1');
	$addr_query->execute(array($user));
	$addr_data=$addr_query->fetchAll();
	
	if(count($addr_data)===0){
		$btc_addr=create_btc_send_addr();
		if($btc_addr===false){
			$post_status=_tr("Error generating new Bitcoin address.");
		}
	}else{
		$btc_addr=$addr_data[0]['btc_addr'];
	}
	
	include 'inc/post.php';
}
