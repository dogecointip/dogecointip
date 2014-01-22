<?

function validate_post($post, $friendIds=array()){
	switch($post['action']){
		case 'new_send_addr':
			return true;
		case 'send_to_fb':
		case 'send_to_btc':
			$is_intra_fb=$post['action']==='send_to_fb';
			if($is_intra_fb && 
				(
					!isset($post['fb_recip']) || 
					!in_array($post['fb_recip'],$friendIds)
				)
			){
				return "Error: Invalid recipient!";
			}
			if(!$is_intra_fb && 
				(
					!isset($post['btc_addr']) || 
					strlen($post['btc_addr'])<27 || 
					strlen($post['btc_addr'])>34
				)
			){
				return "Error: Invalid Bitcoin Address!";
			}
			if(!isset($post['amount'])){
				return "Error: Must specify an amount!";
			}
			if(!is_numeric($post['amount'])){
				return "Error: Invalid send amount!";
			}
			$amount=btc_to_satoshi($post['amount']);
			if((string)round($amount)!==(string)($amount)){
				return "Error: Send amount must be at least 1 satoshi (1/100000000 BTC)";
			}
			if($amount<=0){
				return "Error: Invalid send amount!";
			}
			return true;
		default: return false;
	}
}

if($_POST && isset($_POST['action'])){
	$post_passed=validate_post($_POST,$friendIds);
	if($post_passed===true){
		switch($_POST['action']){
			case 'new_send_addr':
				$btc_addr=create_btc_send_addr();
				if($btc_addr===false){
					$post_status=_tr("Error generating new Bitcoin address.");
				}else{
					$post_status=_tr("New Bitcoin Address Generated!");
				}
				break;
			case 'send_to_fb':
			case 'send_to_btc':
				$is_intra_fb=$_POST['action']==='send_to_fb';
				$amount=btc_to_satoshi($_POST['amount']);
				if($amount+($is_intra_fb ? $fb_tx_fee : $btc_tx_fee)>$balance){
					$post_status=_tr("Error: Insufficient Funds for Transfer");
					break;
				}
				$timestamp=date('Y-m-d H:i:s');
				$btc_tx_query=$db->prepare('insert into `tx` (`fb_id`,`type`,`btc_addr`,`fb_recip`,`date`,`amount`,`hash`) values (?, ?, ?, ?, ?, ?, ?)');
				if($is_intra_fb){
					$btc_tx_query->execute(array($user,'2',null,$_POST['fb_recip'],$timestamp,$amount,null));
					$btc_tx_query->execute(array($user,'2',null,'fee',$timestamp,$fb_tx_fee,null));
					$btc_tx_query->execute(array($_POST['fb_recip'],'1',null,$user,$timestamp,$amount,null));
					$post_status=_tr("Bitcoins sent successfully!");
				
					//notify recipient of the transaction! (if not running locally)
					if(isset($facebook)){
						try{
							$app_token = file_get_contents("https://graph.facebook.com/oauth/access_token?" .
								"client_id=" . $fb_settings['appId'] .
								"&client_secret=" . $fb_settings['secret'] .
								"&grant_type=client_credentials");
							$app_token = str_replace("access_token=", "", $app_token);
							$notification_response=$facebook->api('/'.$_POST['fb_recip'].'/notifications', 'post', array(
								'href'=> '',
								'access_token'=> $app_token,
								'template'=> '@['.$user.'] has just sent you '.$_POST['amount'].' BTC!'
							));
						}catch(Exception $e){
							$post_status.=' '._tr('This user has not installed this app and will not be notified automatically. Please send them a message so they can access their Bitcoins.').' '.'<a href="javascript:" class="request-user" data-recip="'.$_POST['fb_recip'].'" data-amount="'.$_POST['amount'].'">'._tr('Send Request to Install App').'</a> or <a href="javascript:" class="post-to-recip-wall" data-recip="'.$_POST['fb_recip'].'" data-amount="'.$_POST['amount'].'">'._tr('Post to Their Wall').'</a>';
						}
					}
				}else{
					$response=file_get_contents('https://blockchain.info/merchant/'.urlencode($blockchain_guid).'/payment?password='.urlencode($blockchain_pw).'&to='.urlencode($_POST['btc_addr']).'&amount='.urlencode($amount));
					if($response===false){
						$_POST['error-message']=$post_status=_tr('Error Connecting to Blockchain.info! Please try again later.');
						record_error(serialize($_POST));
					}else{
						$json_feed = json_decode($response);
						if(property_exists($json_feed,'error')){
							$_POST['error-message']=$post_status=$json_feed->error;
							record_error(serialize($_POST));
						}else{
							$btc_tx_query->execute(array($user,'2',$_POST['btc_addr'],null,$timestamp,$amount,$json_feed->tx_hash));
							$btc_tx_query->execute(array($user,'2',null,'fee',$timestamp,$btc_tx_fee,null));
							$post_status='<a href="https://blockchain.info/tx/'.urlencode($json_feed->tx_hash).'" target="_blank">'.$json_feed->message.'</a>';
						}
					}
				}
				load_tx();
				break;
		}
	}else{
		//display error message
		$post_status=_tr($post_passed);
	}
}
