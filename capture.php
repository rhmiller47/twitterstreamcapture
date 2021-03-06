<?php

// ----- params -----
set_time_limit(0);
error_reporting(E_ALL);
include "ini.php";


// ----- connection -----
dbconnect();
connectsocket();


function connectsocket() {

	global $user,$pass,$querybins,$path_local;

	//echo "<pre>";

	logit("error.log","connecting to API socket");
	$pid = getmypid();
	file_put_contents($path_local . "logs/procinfo", $pid . "|" . time());

	$wait = 5000000;

	$tweetcounter = 0;
	$tweetbucket = array();
	
	// prepare queries
	
	$querylist = array();
	
	//print_r($querybins);
	
	foreach($querybins as $binname => $bin) {
		//echo $bin . "|";
		$queries = explode(",", $bin);
		foreach($queries as $query) {
			$querylist[$query] = preg_replace("/\'/", "", $query);
		}
		$querybins[$binname] = $queries;
	}
	
	
	
	//print_r($querylist);
	//print_r($querybins);
		
	$query = array("track" => implode(",", $querylist));
	
	
	
	//print_r($query);
	flush();
	
	
	// Twitter recommends 90 seconds timeout
	// https://dev.twitter.com/docs/streaming-apis/connecting
	
	// streaming API endpoint: https://stream.twitter.com/1.1/statuses/sample.json 
	// doc: https://dev.twitter.com/docs/api/1.1/get/statuses/sample
	
	$fp = fsockopen("ssl://stream.twitter.com", 443, $errno, $errstr, 90);		
	
	if(!$fp){
		
		logit("error.log","fsock error: " . $errstr . "(" . $errno . ")");
	
	} else {
	
		logit("error.log","connected - query: " . $query["track"]);
	
		$request = "GET /1.1/statuses/sample.json HTTP/1.1\r\n";
		$request .= "Host: stream.twitter.com\r\n";
		$request .= "Authorization: Basic " . base64_encode($user . ':' . $pass) . "\r\n\r\n";
				
		fwrite($fp, $request);
		stream_set_timeout($fp, 90);
		
		$start = NULL;
        $timeout = 90; 					// timeout if idle (http://php.net/manual/en/function.feof.php) 
        $start = microtime(true);
        //echo $start;
        
        
        /* another attempt
        $streamarr = array($fp);
        $w = $e = null;

        while(stream_select($streamarr, $w, $e, 15) !== false && ) {
        	$json = fgets($fp);
        	print_r($json);
        	print_r($streamarr);
        	flush();
        	sleep(1);
        };
        exit;
        */

		while(!safe_feof($fp,$start) && (microtime(true) - $start) < $timeout) {

			$json = fgets($fp);



			//echo $json;
			//flush();
			
			$data = json_decode($json, true);
			
			if(isset($data["disconnect"])) {
				$discerror = implode(",",$data["disconnect"]);
			}
			
			if($data) {
				
				//if($tweetcounter >= 6) { exit; }
				//print_r($data);
				//flush();

				$tweetcounter++;
				
				$tweetbucket[] = $data;
				
				if(count($tweetbucket) == 300) {
					processtweets($tweetbucket);
					$tweetbucket = array();
				}
			}
		}
	
		logit("error.log","connection dropped or timed out - error " . $discerror);
		
		fclose($fp);
	}
}


function safe_feof($fp, &$start = NULL) {
	//global $start;
	$start = microtime(true); 
	return feof($fp);
}   


function processtweets($tweetbucket) {
	
	// todo: modify tweet insertion to no longer take into account the bins
	global $path_local;
	
	$list_tweets = array();
	$list_hashtags = array();
	$list_urls = array();
	$list_mentions = array();
		
	// running through every single tweet	
	foreach($tweetbucket as $data) {
		
		// adding the expanded url to the tweets text to search in them like twiter does
		foreach($data["entities"]["urls"] as $url) {
			$data["text"] .= " " . $url["expanded_url"];
		}
	
		//from_user_lang 	from_user_tweetcount 	from_user_followercount 	from_user_realname
		$t = array();
		$t["id"] = $data["id_str"];
		$t["created_at"] = date("Y-m-d H:i:s",strtotime($data["created_at"]));
		$t["from_user_name"] = addslashes($data["user"]["screen_name"]);
		$t["from_user_id"] = $data["user"]["id"];
		$t["from_user_lang"] = $data["user"]["lang"];
		$t["from_user_tweetcount"] = $data["user"]["statuses_count"];
		$t["from_user_followercount"] = $data["user"]["followers_count"];
		$t["from_user_friendcount"] = $data["user"]["friends_count"];
		$t["from_user_realname"] = addslashes($data["user"]["name"]);
		$t["source"] = addslashes($data["source"]);
		$t["location"] = addslashes($data["user"]["location"]);
		$t["geo_lat"] = 0;
		$t["geo_lng"] = 0;
		if($data["geo"] != null) {
			$t["geo_lat"] = $data["geo"]["coordinates"][0];
			$t["geo_lng"] = $data["geo"]["coordinates"][1];
		}
		$t["text"] = addslashes($data["text"]);
		$t["to_user_id"] = $data["in_reply_to_user_id_str"];
		$t["to_user_name"] = addslashes($data["in_reply_to_screen_name"]);
		$t["in_reply_to_status_id"] = $data["in_reply_to_status_id_str"];
		
		$list_tweets[] = "('" . implode("','",$t) . "')";
		
		
		if(count($data["entities"]["hashtags"]) > 0) {
			foreach($data["entities"]["hashtags"] as $hashtag) {
				$h = array();
				$h["tweet_id"] = $t["id"];
				$h["created_at"] = $t["created_at"];
				$h["from_user_name"] = $t["from_user_name"];
				$h["from_user_id"] = $t["from_user_id"];
				$h["text"] = addslashes($hashtag["text"]);

				$list_hashtags[] = "('" . implode("','",$h) . "')";
			}
		}
		
		if(count($data["entities"]["urls"]) > 0) {
			foreach($data["entities"]["urls"] as $url) {
				$u = array();
				$u["tweet_id"] = $t["id"];
				$u["created_at"] = $t["created_at"];
				$u["from_user_name"] = $t["from_user_name"];
				$u["from_user_id"] = $t["from_user_id"];
				$u["url"] = $url["url"];
				$u["url_expanded"] = addslashes($url["expanded_url"]);

				$list_urls[] = "('" . implode("','",$u) . "')";
			}
		}
		
		if(count($data["entities"]["user_mentions"]) > 0) {
			foreach($data["entities"]["user_mentions"] as $mention) {
				$m = array();
				$m["tweet_id"] = $t["id"];
				$m["created_at"] = $t["created_at"];
				$m["from_user_name"] = $t["from_user_name"];
				$m["from_user_id"] = $t["from_user_id"];
				$m["to_user"] = $mention["screen_name"];
				$m["to_user_id"] = $mention["id_str"];
				
				$list_mentions[] = "('" . implode("','",$m) . "')";
			}
		}
	}
	
	
	// distribute tweets into bins


	if(count($list_tweets) > 0) {

		$sql = "INSERT IGNORE INTO streamsample_tweets (id,created_at,from_user_name,from_user_id,from_user_lang,from_user_tweetcount,from_user_followercount,from_user_friendcount,from_user_realname,source,location,geo_lat,geo_lng,text,to_user_id,to_user_name,in_reply_to_status_id) VALUES ". implode(",", $list_tweets);
	
		$sqlresults = mysql_query($sql);
		if(!$sqlresults) {
			logit("error.log","insert error: " . $sql);
		} else { 
			$pid = getmypid();
			file_put_contents($path_local . "logs/procinfo", $pid . "|" . time());
		}
	}
	
	if(count($list_hashtags) > 0) {

		$sql = "INSERT IGNORE INTO streamsample_hashtags (tweet_id,created_at,from_user_name,from_user_id,text) VALUES ". implode(",", $list_hashtags);
		
		$sqlresults = mysql_query($sql);
		if(!$sqlresults) { logit("error.log","insert error: " . $sql); }
	}
	
	if(count($list_urls) > 0) {
		
		$sql = "INSERT IGNORE INTO streamsample_urls (tweet_id,created_at,from_user_name,from_user_id,url,url_expanded) VALUES ". implode(",", $list_urls);
									
		$sqlresults = mysql_query($sql);
		if(!$sqlresults) { logit("error.log","insert error: " . $sql); }
	}
	
	if(count($list_mentions) > 0) {
		
		$sql = "INSERT IGNORE INTO streamsample_mentions (tweet_id,created_at,from_user_name,from_user_id,to_user,to_user_id) VALUES ". implode(",", $list_mentions);
						
		$sqlresults = mysql_query($sql);
		if(!$sqlresults) { logit("error.log","insert error: " . $sql); }
	}
}

// +++++ logging +++++

function logit($file,$message) {
	
	global $path_local;
	
	$file = $path_local . "logs/" . $file;
	$message = date("Y-m-d H:i:s") . " " . $message . "\n";
	file_put_contents($file, $message, FILE_APPEND);
}

// +++++ database connection functions +++++

function dbconnect() {
	global $hostname,$database,$dbuser,$dbpass,$db;
	$db = mysql_connect($hostname,$dbuser,$dbpass) or die("Database error");
	mysql_select_db($database, $db);
	mysql_set_charset('utf8',$db);
}

function dbclose() {
	global $db;
	mysql_close($db);
}

?>