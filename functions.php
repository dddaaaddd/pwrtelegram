<?php
/*
Copyright 2016 Daniil Gentili
(https://daniil.it)

This file is part of the PWRTelegram API.
the PWRTelegram API is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. 
The PWRTelegram API is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Affero General Public License for more details. 
You should have received a copy of the GNU General Public License along with the PWRTelegram API. 
If not, see <http://www.gnu.org/licenses/>.
*/

function find_txt($msgs) {
	$ok = "";
	foreach ($msgs as $msg) {
		foreach ($msg as $key => $val) { 
			if ($key == "text" && $val == $_REQUEST["file_id"]) $ok = "y";
		}
		if ($ok == "y") return $msg->reply_id;
	}
}

/**
 * Returns the size of a file without downloading it, or -1 if the file
 * size could not be determined.
 *
 * @param $url - The location of the remote file to download. Cannot
 * be null or empty.
 *
 * @return The size of the file referenced by $url, or -1 if the size
 * could not be determined.
 */
 
function curl_get_file_size($url) {
	// Assume failure.
	$result = -1;

	$curl = curl_init(str_replace(' ', '%20', $url));

	// Issue a HEAD request and follow any redirects.
	curl_setopt( $curl, CURLOPT_NOBODY, true );
	curl_setopt( $curl, CURLOPT_HEADER, true );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );

	$data = curl_exec( $curl );
	curl_close( $curl );

	if($data) {
		$content_length = "unknown";
		$status = "unknown";
		if( preg_match( "/^HTTP\/1\.[01] (\d\d\d)/", $data, $matches ) ) {
			$status = (int)$matches[1];
		}

		if( preg_match( "/Content-Length: (\d+)/", $data, $matches ) ) {
			$content_length = (int)$matches[1];
		}

		// http://en.wikipedia.org/wiki/List_of_HTTP_status_codes
		if( $status == 200 || ($status > 300 && $status <= 308) ) {
			$result = $content_length;
		}
	}
	return $result;
}

/**
 * Check if tg-cli ever contacted contacted username, if not send a /start command
 *
 * @param $me - The username to check
 *
 * @return true if user is in dialoglist or if it was contacted successfully, false if it couldn't be contacted.
 */
function checkbotuser($me) {
	include 'telegram_connect.php';
	global $url;
	// $me is the bot's username
	$me = curl($url . "/getMe")["result"]["username"]; // get my username
	// Get all of the usernames
	$usernames = array();
	ini_set("log_errors", 0);
	foreach ($telegram->getDialogList() as $username){ $usernames[] = $username->username; };
	ini_set("log_errors", 1);
	// If never contacted bot send start command
	if(!in_array($me, $usernames)) {
		if(!$telegram->msg("@".$me, "/start")) return false;
	}
	return true;
}


/**
 * Check dir exists
 *
 * @param $dir - The dir to check
 *
 * @return true if dir exists or if it was created successfully, false if it couldn't be created.
 */
function checkdir($dir) {
	if (!file_exists($dir)) {
		if(!mkdir($dir, 0777, true)) return false;
	}
	return true;
}

/**
 * Remove symlink and destination path
 *
 * @param $symlink - The symlink to delete
 *
 * @return void
 */
function unlink_link($symlink) {
	$rpath = readlink($symlink);
	unlink($symlink);
	unlink($rpath);
}

/**
 * Download given file id and return json with error or downloaded path
 *
 * @param $file_id - The file id of the file to download
 *
 * @return json with error or file path
 */
function download($file_id) {
	include '../db_connect.php';
	global $url, $pwrtelegram_storage, $homedir, $methods, $botusername, $token;
	$me = curl($url . "/getMe")["result"]["username"]; // get my username
	$selectstmt = $pdo->prepare("SELECT * FROM dl WHERE file_id=? AND bot=? LIMIT 1;");
	$selectstmt->execute(array($file_id, $me));
	$select = $selectstmt->fetch(PDO::FETCH_ASSOC);

	if($selectstmt->rowCount() == "1" && checkurl($pwrtelegram_storage . $select["file_path"])) {
		$newresponse["ok"] = true;
		$newresponse["result"]["file_id"] = $select["file_id"];
		$newresponse["result"]["file_path"] = $select["file_path"];
		$newresponse["result"]["file_size"] = $select["file_size"];
		return $newresponse;
	}
	$gmethods = array_keys($methods);
	include 'telegram_connect.php';
	$path = '';
	$result = curl($url . "/getFile?file_id=" . $_REQUEST['file_id']);
	if(isset($result["result"]["file_path"]) && $result["result"]["file_path"] != "" && checkurl("https://api.telegram.org/file/bot".$token."/".$result["result"]["file_path"])) {
		$file_path = $result["result"]["file_path"];
		$path = str_replace('//', '/', $homedir . "/storage/" . $me . "/" . $file_path);
		$dl_url = "https://api.telegram.org/file/bot".$token."/".$file_path;
		if(!(file_exists($path) && filesize($path) == curl_get_file_size($dl_url))){
			if(!checkdir(dirname($path))) return array("ok" => false, "error_code" => 400, "description" => "Couldn't create storage directory.");
			set_time_limit(0);
			$fp = fopen ($path, 'w+');
			$ch = curl_init(str_replace(" ","%20", $dl_url));
			curl_setopt($ch, CURLOPT_TIMEOUT, 50);
			// write curl response to file
			curl_setopt($ch, CURLOPT_FILE, $fp);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			// get curl response
			curl_exec($ch);
			curl_close($ch);
			fclose($fp);
		}
		$file_path = $me . "/" . $file_path;
	}
	if(!file_exists($path)) {
		if(!checkbotuser($me)) return array("ok" => false, "error_code" => 400, "description" => "Couldn't initiate chat.");
		$result = array("ok" => false);
		$count = 0;
		while($result["ok"] != true && $count < count($gmethods)) {
			$result = curl($url . "/send" . $gmethods["$count"] . "?chat_id=" . $botusername . "&" . $gmethods["$count"] . "=" . $file_id);
			$count++;
		};
		$count--;
		if($result["ok"] == false) return array("ok" => false, "error_code" => 400, "description" => "Couldn't forward file to download user.");
		if($result["result"]["message_id"] == false) return array("ok" => false, "error_code" => 400, "description" => "Reply message id is empty.");
		$result = curl($url . "/sendMessage?reply_to_message_id=" . $result["result"]["message_id"] . "&chat_id=" . $botusername . "&text=" . $file_id);
		if($result["ok"] == false) return array("ok" => false, "error_code" => 400, "description" => "Couldn't send file id.");
		$result = $telegram->getFile($me, $file_id, $methods[$gmethods[$count]]);
		$path = $result->{"result"};
		if($path == "") return array("ok" => false, "error_code" => 400, "description" => "Couldn't download file.");
		$file_path = $me . preg_replace('/.*\.telegram-cli\/downloads/', '', $path);
		$ext = '';
		$format = '';
		$codec = '';
		try {
			$mediaInfo = new Mhor\MediaInfo\MediaInfo();
			$mediaInfoContainer = $mediaInfo->getInfo($path);
			$general = $mediaInfoContainer->getGeneral();
			try {
				$ext = $general->get("file_extension");
			} catch(Exception $e) { ; };
			try {
				$format = preg_replace("/.*\s/", "", $general->get("format_extensions_usually_used"));
			} catch(Exception $e) { ; };
			try {
				$codec = preg_replace("/.*\s/", "", $general->get("codec_extensions_usually_used"));
			} catch(Exception $e) { ; };
			if($format == "") $format = $codec;
		} catch(Exception $e) { ; };
		if($ext != $format && $format != "") {
			$file_path = $file_path . "." . $format;
		}

	}
	if(!file_exists($path)) return array("ok" => false, "error_code" => 400, "description" => "Couldn't download file (file does not exist).");
	$file_size = filesize($path);
	$newresponse["ok"] = true;
	$newresponse["result"]["file_id"] = $file_id;
	$newresponse["result"]["file_path"] = $file_path;
	$newresponse["result"]["file_size"] = $file_size;

	$delete_stmt = $pdo->prepare("DELETE FROM dl WHERE file_id=? AND bot=?;");
	$delete = $delete_stmt->execute(array($file_id, $me));
	$insert_stmt = $pdo->prepare("INSERT INTO dl (file_id, file_path, file_size, bot, real_file_path) VALUES (?, ?, ?, ?, ?);");
	$insert = $insert_stmt->execute(array($file_id, $file_path, $file_size, $me, $path));

	return $newresponse;
}

/**
 * Gets info from file id
 *
 * @param $file_id - The file id to recognize
 *
 * @return json with error or file info
 */
function get_finfo($file_id){
	global $methods, $url, $botusername;
	$methods = array_keys($methods);
	$me = curl($url . "/getMe")["result"]["username"]; // get my username

	if(!checkbotuser($me)) return array("ok" => false, "error_code" => 400, "description" => "Couldn't initiate chat.");
	$result = array("ok" => false);
	$count = 0;
	while($result["ok"] != true && $count < count($methods)) {
		$result = curl($url . "/send" . $methods["$count"] . "?chat_id=" . $botusername . "&" . $methods["$count"] . "=" . $file_id);
		$count++;
	};
	$count--;
	$info["ok"] = $result["ok"];
	if($info["ok"] == true) {
		$info["file_type"] = $methods[$count];
		$info["file_id"] = $file_id;
		if($info["file_type"] == "photo") $info["file_size"] = $result["result"][$methods[$count]][0]["file_size"]; else $info["file_size"] = $result["result"][$methods[$count]]["file_size"];
	}
	return $info;
}

/**
 * Upload given file/URL/file id
 *
 * @param $file - The file/URL/file to upload
 *
 * @param $name - The file name to use when uploading, can be empty
 * (in this case the file name will be obtained from the given file path/URL)
 *
 * @param $type - The type of file to use when uploading:
 * can be document, photo, audio, voice, sticker, file or empty
 * (in this case the type will default to file)
 *
 * @param $detect - Boolean, enables or disables metadata detection, defaults to false
 *
 * @param $forcename - Boolean, enables or disables file name forcing, defaults to false
 * If set to false the file name to be stored in the database will be set to empty and the 
 * associated file id will be reused the next time a file with the same hash and with $forcename set to false is sent.
 *
 * @return json with error or file id
 */

function upload($file, $name = "", $type = "", $detect = false, $forcename = false) {
	if($file == "") return array("ok" => false, "error_code" => 400, "description" => "No file specified.");
	if($name == "") $file_name = basename($file); else $file_name = basename($name);
	if($detect == "") $detect = false;
	if($type == "") $type = "file";
	if($type == "file") $detect = true;
	if($forcename == "") $forcename = false;
	if($forcename) $name = basename($name); else $name = "";

	include '../db_connect.php';
	global $pwrtelegram_api, $token, $url, $pwrtelegram_storage, $methods, $homedir, $botusername;
	include 'telegram_connect.php';
	$me = curl($url . "/getMe")["result"]["username"]; // get my username

	if(!checkdir($homedir . "/ul/" . $me)) return array("ok" => false, "error_code" => 400, "description" => "Couldn't create storage directory.");
	$path = $homedir . "/ul/" . $me . "/" . $file_name;

	if(file_exists($file)) {
		$size = filesize($file);
		if($size < 1) return array("ok" => false, "error_code" => 400, "description" => "File too small.");
		if($size > 1610612736) return array("ok" => false, "error_code" => 400, "description" => "File too big.");
		if(!rename($file, $path)) return array("ok" => false, "error_code" => 400, "description" => "Couldn't rename file.");
	} else if(checkurl($file)){
		$size = curl_get_file_size($file);
		//if($size < 1) return array("ok" => false, "error_code" => 400, "description" => "File too small.");
		if($size > 1610612736) return array("ok" => false, "error_code" => 400, "description" => "File too big.");
		set_time_limit(0);
		$fp = fopen ($path, 'w+');
		$ch = curl_init(str_replace(" ","%20",$file));
		curl_setopt($ch, CURLOPT_TIMEOUT, 50);
		// write curl response to file
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		// get curl response
		curl_exec($ch);
		curl_close($ch);
		fclose($fp);
	} else if(!preg_match('/[^A-Za-z0-9\-\_]/', $file)) {
		$info = get_finfo($file);
		if($info["ok"] != true) return array("ok" => false, "error_code" => 400, "description" => "Couldn't get info from file id.");
		if($info["file_type"] == "") return array("ok" => false, "error_code" => 400, "description" => "File type is empty.");
		if($type == "file") $type = $info["file_type"];
		if($type != $info["file_type"] || $forcename == true) {
			$downloadres = download($file);
			if($res["result"]["file_path"] != "") return array("ok" => false, "error_code" => 400, "description" => "Couldn't download file from file id.");
			if(!rename($res["result"]["file_path"], $path)) return array("ok" => false, "error_code" => 400, "description" => "Couldn't rename file.");
			$size = filesize($path);
		} return curl($url . "/send" . $type . "?" . $type . "=" . $file . http_build_query($_REQUEST));
	} else {
		if(filter_var($file, FILTER_VALIDATE_URL)) return array("ok" => false, "error_code" => 400, "description" => "Couldn't use the provided URL.");
		return array("ok" => false, "error_code" => 400, "description" => "Couldn't use the provided file id/URL.");
	}
	if($type == "file") {
		$mime = '';
		try {
			$mediaInfo = new Mhor\MediaInfo\MediaInfo();
			$mediaInfoContainer = $mediaInfo->getInfo($path);
			$mime = $mediaInfoContainer->getGeneral()->get("internet_media_type");
		} catch(Exception $e) { ; };
		if($mime == "") $mime = mime_content_type($path);
		$ext = pathinfo($path)["extension"];
		if (preg_match('/^image\/.*/', $mime) && preg_match('/gif|png|jpeg|jpg|bmp|tif/', $ext)) {
			$type = "photo";
		} else if(preg_match('/^video\/.*/', $mime) && 'mp4' == $ext) {
			$type = "video";
		} else if($ext == "webp"){
			$type = "sticker";
		} else if(preg_match('/^audio\/.*/', $mime) && preg_match('/mp3|flac/', $ext)) {
			$type = "audio";
		} else if(preg_match('/^audio\/ogg/', $mime)) {
			$type = "voice";
		} else {
			$type = "document";
		}
	};
	$params = $_REQUEST;
	$newparams = array();
	if($detect == true) { // This is pretty useless unless I figure out a way to pass the parameters to tg-cli.
			switch($type) {
				case "audio":
					$mediaInfo = new Mhor\MediaInfo\MediaInfo();
					$mediaInfoContainer = $mediaInfo->getInfo($path);
					$general = $mediaInfoContainer->getGeneral();
					foreach (array("performer" => "performer", "track_name" => "title") as $orig => $param) {
						try {
							$newparams[$param] = $general->get($orig);
						} catch(Exception $e) {
						};
					};
					$newparams["duration"] = shell_exec("ffprobe -show_format ".escapeshellarg($path)." 2>&1 | sed -n '/duration/s/.*=//p;s/\..*//g'  | sed 's/\..*//g' | tr -d '\n'");
					break;
				case "voice":
					$newparams["duration"] = shell_exec("ffprobe -show_format ".escapeshellarg($path)." 2>&1 | sed -n '/duration/s/.*=//p;s/\..*//g'  | sed 's/\..*//g' | tr -d '\n'");
					break;
				case "video":
					$mediaInfo = new Mhor\MediaInfo\MediaInfo();
					$mediaInfoContainer = $mediaInfo->getInfo($path);
					$general = $mediaInfoContainer->getGeneral();
					foreach (array("width" => "width", "height" => "height") as $orig => $param) { try { $newparams[$param] = $general->get($orig)->__toString(); } catch(Exception $e) { ; }; };
					$newparams["duration"] = shell_exec("ffprobe -show_format ".escapeshellarg($path)." 2>&1 | sed -n '/duration/s/.*=//p;s/\..*//g'  | sed 's/\..*//g' | tr -d '\n'");
					$newparams["caption"] = $file_name;
					break;
				case "photo":
					$newparams["caption"] = $file_name;
					break;
				case "document":
					$newparams["caption"] = $file_name;
					break;
			}
	}

	$file_hash = hash_file('sha256', $path);
	$select_stmt = $pdo->prepare("SELECT file_id FROM ul WHERE file_hash=? AND type=? AND bot=? AND filename=?;");
	$select_stmt->execute(array($file_hash, $type, $me, $name));
	$file_id = $select_stmt->fetchColumn();
	$count = $select_stmt->rowCount();

	if($file_id == "") {
		if(!checkbotuser($me)) { unlink($path); return array("ok" => false, "error_code" => 400, "description" => "Couldn't initiate chat."); };
		if($size < 40000000){
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_HTTPHEADER, array( "Content-Type:multipart/form-data" )); 
			curl_setopt($ch, CURLOPT_URL, $url . "/send" . $type . "?" . http_build_query($newparams));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
			curl_setopt($ch, CURLOPT_POSTFIELDS, array('chat_id' => $botusername, $type => new \CURLFile($path))); 
			$result = json_decode(curl_exec($ch), true);
			curl_close($ch);
			if($type == "photo") $file_id = end($result["result"][$type])["file_id"]; else $file_id = $result["result"][$type]["file_id"];
		}
		if($file_id != "") {
			unlink($path);
			$insert_stmt = $pdo->prepare("INSERT INTO ul (file_id, file_hash, type, bot, filename) VALUES (?, ?, ?, ?, ?);");
			$insert_stmt->execute(array($file_id, $file_hash, $type, $me, $name));
			$count = $insert_stmt->rowCount();
			if($count != "1") return array("ok" => false, "error_code" => 400, "description" => "Couldn't store data into database.");
		} else {
			$result = $telegram->pwrsendFile("@" . $me, $methods[$type], $path);
			unlink($path);
			if($result->{'error'} != "") return array("ok" => false, "error_code" => $result->{'error_code'}, "description" => $result->{'error'});
			$message_id = $result->{'id'};
			if($message_id == "") return array("ok" => false, "error_code" => 400, "description" => "Message id is empty.");
			if($count == "0") {
				$insert_stmt = $pdo->prepare("INSERT INTO ul (file_hash, type, bot, filename) VALUES (?, ?, ?, ?);");
				$insert_stmt->execute(array($file_hash, $type, $me, $name));
				$count = $insert_stmt->rowCount();
				if($count != "1") return array("ok" => false, "error_code" => 400, "description" => "Couldn't store data into database.");
			}
			if(!$telegram->replymsg($message_id, "exec_this " . json_encode(array("file_hash" => $file_hash, "type" => $type, "bot" => $me, "filename" => $name)))) return array("ok" => false, "error_code" => 400, "description" => "Couldn't send reply data.");
			$response = curl($pwrtelegram_api . "/bot" . $token . "/getupdates");
			$select_stmt = $pdo->prepare("SELECT file_id FROM ul WHERE file_hash=? AND type=? AND bot=? AND filename=?;");
			$select_stmt->execute(array($file_hash, $type, $me, $name));
			$file_id = $select_stmt->fetchColumn();
		}
		if($file_id == "") return array("ok" => false, "error_code" => 400, "description" => "Couldn't get file id. Please run getupdates and process messages before sending another file.");
	} else unlink($path);
	if($params["caption"] == "" && isset($newparams["caption"]) && $newparams["caption"] != "") $params["caption"] = $newparams["caption"];
	$params[$type] = $file_id;
	$res = curl($url . "/send" . $type . "?" . http_build_query($params));
	return $res;
}

?>