<?

	include_once __DIR__ . '/../libs/vendor/autoload.php';

	class SyncDropbox extends IPSModule {
		
		//This one needs to be available on our OAuth client backend.
		//Please contact us to register for an identifier: https://www.symcon.de/kontakt/#OAuth
		private $oauthIdentifer = "sync_dropbox";
		
		public function Create() {
			//Never delete this line!
			parent::Create();
			
			$this->RegisterPropertyString("Token", "");
			
			$this->RegisterPropertyInteger("SizeLimit", 20); //In Megabytes
			
			$this->RegisterPropertyInteger("ReSyncInterval", 60); //In Minutes
			
			//Start first Sync after 60 seconds
			$this->RegisterTimer("Sync", 60 * 1000, "SDB_Sync(\$_IPS['TARGET']);");
			
			//ReSync is done after within the defined interval the first Sync
			//ReSync will not be started if an Upload is currently running 
			$this->RegisterTimer("ReSync", 0, "SDB_ReSync(\$_IPS['TARGET']);");
			
			//Disable uploading by default. Upload will be started after Sync/ReSync
			$this->RegisterTimer("Upload", 0, "SDB_Upload(\$_IPS['TARGET']);");
			
		}
	
		public function ApplyChanges() {
			//Never delete this line!
			parent::ApplyChanges();
			
			$this->RegisterOAuth($this->oauthIdentifer);
		}
		
		private function RegisterOAuth($WebOAuth) {
			$ids = IPS_GetInstanceListByModuleID("{F99BF07D-CECA-438B-A497-E4B55F139D37}");
			if(sizeof($ids) > 0) {
				$clientIDs = json_decode(IPS_GetProperty($ids[0], "ClientIDs"), true);
				$found = false;
				foreach($clientIDs as $index => $clientID) {
					if($clientID['ClientID'] == $WebOAuth) {
						if($clientID['TargetID'] == $this->InstanceID)
							return;
						$clientIDs[$index]['TargetID'] = $this->InstanceID;
						$found = true;
					}
				}
				if(!$found) {
					$clientIDs[] = Array("ClientID" => $WebOAuth, "TargetID" => $this->InstanceID);
				}
				IPS_SetProperty($ids[0], "ClientIDs", json_encode($clientIDs));
				IPS_ApplyChanges($ids[0]);
			}
		}
	
		/**
		* This function will be called by the register button on the property page!
		*/
		public function Register() {
			
			//Return everything which will open the browser
			return "https://oauth.ipmagic.de/authorize/".$this->oauthIdentifer."?username=".urlencode(IPS_GetLicensee());
			
		}
		
		private function FetchAccessToken($code) {
			
			$this->SendDebug("FetchAccessToken", "Use Authentication Code to get our precious Access Token!", 0);
			
			//Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
			$options = array(
				'http' => array(
					'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
					'method'  => "POST",
					'content' => http_build_query(Array("code" => $code))
				)
			);
			$context = stream_context_create($options);
			$result = file_get_contents("https://oauth.ipmagic.de/access_token/".$this->oauthIdentifer, false, $context);

			$data = json_decode($result);
			
			if(!isset($data->token_type) || $data->token_type != "bearer") {
				die("Bearer Token expected");
			}
			
			return $data->access_token;

		}
		
		/**
		* This function will be called by the OAuth control. Visibility should be protected!
		*/
		protected function ProcessOAuthData() {

			//Lets assume requests via GET are for code exchange. This might not fit your needs!
			if($_SERVER['REQUEST_METHOD'] == "GET") {
		
				if(!isset($_GET['code'])) {
					die("Authorization Code expected");
				}
				
				$token = $this->FetchAccessToken($_GET['code']);
				
				$this->SendDebug("ProcessOAuthData", "OK! Let's save the Access Token permanently", 0);

				IPS_SetProperty($this->InstanceID, "Token", $token);
				IPS_ApplyChanges($this->InstanceID);
			
			} else {
				
				//Just print raw post data!
				echo file_get_contents("php://input");
				
			}

		}
		
		//Source: https://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
		private function formatBytes($size, $precision = 2) {
			$base = log($size, 1024);
			$suffixes = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');   
		
			if($size == 0) {
				return "0 B";
			} else {
				return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
			}
		}	
		
		public function GetConfigurationForm() {
			
			$data = json_decode(file_get_contents(__DIR__ . "/form.json"));
			
			if($this->ReadPropertyString("Token")) {
				
				//Add some space
				$data->actions[] = [
					"type" => "Label",
					"caption" => ""
				];		
				
				$dropbox = new Dropbox\Dropbox($this->ReadPropertyString("Token"));
				$account = $dropbox->users->get_current_account();
				if(!$account || isset($account["error_summary"])) {
					
					$data->actions[] = [
						"type" => "Label",
						"caption" => "There seems to be something wrong. Please try to reregister."
					];					
					
				} else {
					
					$space = $dropbox->users->get_space_usage();
					
					$data->actions[] = [
						"type" => "Label",
						"caption" => "Owner: " . $account["name"]["display_name"]
					];
					
					//var_dump($space);
					
					$data->actions[] = [
						"type" => "Label",
						"caption" => "Space: " . $this->formatBytes($space["used"]) . " / " . $this->formatBytes($space["allocation"]["allocated"])
					];
					
					if(intval($this->GetBuffer("BackupSize")) > 0) {
						$data->actions[] = [
							"type" => "Label",
							"caption" => "Backup Size: " . $this->formatBytes($this->GetBuffer("BackupSize"))
						];
					}
					
					if($this->GetBuffer("FileQueue") != "") {
						$fileQueue = json_decode($this->GetBuffer("FileQueue"), true);
						
						if(sizeof($fileQueue["add"]) > 0 || sizeof($fileQueue["update"]) > 0 || sizeof($fileQueue["delete"]) > 0) {
							$data->actions[] = [
								"type" => "Label",
								"caption" => "Sync in progress... " . sprintf("Remaining = Add: %d, Update: %d, Remove: %d", sizeof($fileQueue["add"]), sizeof($fileQueue["update"]), sizeof($fileQueue["delete"]))
							];
						}
					}
					
					//Add Sync button
					$data->actions[] = [
						"type" => "Button",
						"caption" => "Force Sync",
						"onClick" => "echo SDB_Sync(\$id);"
					];					
					
				}
			
			}

			return json_encode($data);			
			
		}		
		
		private function IgnoreFile($file) {
			
			//always compare lower case
			$file = strtolower($file);
			
			$path_info = pathinfo($file);
			
			//Do not include modules for now. We will probably want to add this as an optional switch
			if(substr($file, 0, 7) == "modules") {
				return true;
			}
			
			//We do not require to backup sessions
			if(substr($file, 0, 7) == "session") {
				return true;
			}
			
			//Filter Thumbs.db and .DS_Store. Dropbox will ignore uploads anyway
			if($path_info['basename'] == "thumbs.db") {
				return true;
			}
			if($path_info['basename'] == ".ds_store") {
				return true;
			}
			
			//For Windows we need to apply some filters
			if(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' || IPS_GetKernelPlatform() == "Windows x64") {
				if($file == "mime.types") {
					return true;
				}
				if($file == "cacert.pem") {
					return true;
				}
				if($file == "php.ini") {
					return true;
				}
				if(substr($file, 0, 8) == "webfront") {
					return true;
				}
				if(substr($file, 0, 5) == "forms") {
					return true;
				}				
				if(substr($file, 0, 6) == "locale") {
					return true;
				}
				if(substr($file, 0, 4) == "logs") {
					return true;
				}
				if(substr($file, 0, 6) == "tzdata") {
					return true;
				}				
				if(isset($path_info["extension"]) && in_array($path_info["extension"], ["dll", "exe"])) {
					return true;
				}
			}
			
			return false;
			
		}
		
		private function GetDestinationFolder() {
			
			return IPS_GetLicensee();
			
		}
		
		private function CalculateFileQueue($fileCache) {
			
			$fileQueue = ["add" => [], "update" => [], "delete" => []];
			
			//Build the index'ed version from the FileCache
			$fileIndex = [];
			foreach($fileCache as $file) {
				$fileIndex[$file["path_lower"]] = $file; 
			}

			$baseDir = IPS_GetKernelDir();
			
			$backupSize = 0;
			$backupSkip = 0;
			$uploadSize = 0;
			
			//Iterate through the locale filesystem and add all files not in the index
			$searchDir = function($dir) use ($baseDir, $fileIndex, &$fileQueue, &$searchDir, &$backupSize, &$backupSkip, &$uploadSize) {
				$files = scandir ($baseDir . $dir);
				foreach($files as $file) {
					if($file == "." || $file == "..") {
						//Ignore special folders
					}
					else if(is_dir($baseDir . $dir . $file)) {
						$searchDir($dir . $file . "/");
					} else {
						if(!$this->IgnoreFile($dir . $file)) {
							$filesize = filesize($baseDir . $dir . $file);
	
							//If the file grew over the limit we will keep the last valid file in the backup
							if($filesize > $this->ReadPropertyInteger("SizeLimit") * 1024 * 1024) {
								//Skip files that are too big
								$this->SendDebug("Search", sprintf("Skipping too big file... %s. Size: %s", $dir . $file, $this->formatBytes($filesize)), 0);
	
								//Sum skipped files for statistics
								$backupSkip++;
							} else {
								//Lets sum up every file we want to backup
								$backupSize += $filesize;
								
								//Add any new files
								if(!isset($fileIndex[strtolower("/" . $this->GetDestinationFolder() . "/" . $dir . $file)])) {
									$fileQueue["add"][] = $dir . $file;
									$uploadSize += $filesize;
								} else {
									//Update file if the file's timestamp is newer
									$matchFilesize = $fileIndex[strtolower("/" . $this->GetDestinationFolder() . "/" . $dir . $file)]["size"] == $filesize;
									$matchChecksum = true; //FIXME
									
									if(!$matchFilesize || !$matchChecksum) {
										$fileQueue["update"][] = $dir . $file;
										$uploadSize += $filesize;
									}
								}
							}
						} else {
							//Check if ignored files got somehow into the index. If yes, delete them
							if(isset($fileIndex[strtolower("/" . $this->GetDestinationFolder() . "/" . $dir .$file)])) {
								$fileQueue["delete"][] = $dir .$file;
							}
						}
					}
				}
			};
			$searchDir("");
			
			$this->SendDebug("Search", sprintf("Total Backup Size: %s", $this->formatBytes($backupSize)), 0);
			$this->SendDebug("Search", sprintf("Required Upload Size: %s", $this->formatBytes($uploadSize)), 0);
			
			$this->SetBuffer("BackupSize", json_encode($backupSize));
			$this->SetBuffer("BackupSkip", json_encode($backupSkip));
			
			return $fileQueue;
			
		}
		
		public function Sync() {
			
			$this->SetTimerInterval("Sync", 0);
			
			$dropbox = new Dropbox\Dropbox($this->ReadPropertyString("Token"));
			
			$targets = $dropbox->files->list_folder("", false);
			
			$fileCache = [];
			
			//Only update file cache if the target folder already exists
			foreach ($targets["entries"] as $target) {
				if($target["path_lower"] == strtolower("/" . $this->GetDestinationFolder())) {
					$files = $dropbox->files->list_folder("/" . $this->GetDestinationFolder(), true);
					
					if(!$files) {
						echo "Error while running Sync";
						return;
					}
					
					if($files["has_more"]) {
						die("FIXME: Listing is incomplete. More items to read!");
					}
					
					$fileCache = $files["entries"];
					$this->SendDebug("Sync", sprintf("We have %d files in your Dropbox", sizeof($fileCache)), 0);
				}
			}
			
			//Save all entries for partial sync
			$this->SetBuffer("FileCache", json_encode($fileCache));
			
			//Build the add/update/delete queue
			$fileQueue = $this->CalculateFileQueue($fileCache);
			$this->SendDebug("Sync", sprintf("Sync = Add: %d, Update: %d, Remove: %d", sizeof($fileQueue["add"]), sizeof($fileQueue["update"]), sizeof($fileQueue["delete"])), 0);				
			
			//Save the FileQueue which the Upload function will process
			$this->SetBuffer("FileQueue", json_encode($fileQueue));
			
			if(sizeof($fileQueue["add"]) > 0 || sizeof($fileQueue["update"]) > 0 || sizeof($fileQueue["delete"]) > 0) {
				//Start Upload
				$this->SendDebug("Sync", "Upload will start in 10 seconds...", 0);			
				$this->SetTimerInterval("Upload", 10 * 1000);
			} else {
				$this->SendDebug("Sync", "Done. Everything is up to date.", 0);
			}
			
			//Start ReSync
			$this->SetTimerInterval("ReSync", $this->ReadPropertyInteger("ReSyncInterval") * 60 * 1000);
			
		}
		
		public function ReSync() {
			
			//Load the current FileQueue
			$fileQueue = json_decode($this->GetBuffer("FileQueue"), true);
			
			
			
		}
		
		public function Upload() {
		
			$this->SetTimerInterval("Upload", 0);

			$dropbox = new Dropbox\Dropbox($this->ReadPropertyString("Token"));
			
			$baseDir = IPS_GetKernelDir();
			
			//Load the current FileQueue
			$fileQueue = json_decode($this->GetBuffer("FileQueue"), true);
			
			//Upload new files first
			if(sizeof($fileQueue["add"]) > 0) {
				//Upload to Dropbox
				$this->SendDebug("Upload", sprintf("Adding file... %s. Size %s", $fileQueue["add"][0], $this->formatBytes(filesize($baseDir . $fileQueue["add"][0]))), 0);
				$dropbox->files->upload("/" . $this->GetDestinationFolder() . "/" . $fileQueue["add"][0], $baseDir . $fileQueue["add"][0]);

				//Remove successful upload
				array_shift($fileQueue["add"]);
				
				//Start timer for next upload
				$this->SetTimerInterval("Upload", 1000);
			} else if(sizeof($fileQueue["update"]) > 0) {
				
				$this->SetTimerInterval("Upload", 1000);
			} else if(sizeof($fileQueue["delete"]) > 0) {
				
				$this->SetTimerInterval("Upload", 1000);
			} else {
				
				$this->SendDebug("Upload", "Finished", 0);
				$this->SetTimerInterval("Upload", 0);
			}
			
			//Show progress if there is anything left to do
			if(sizeof($fileQueue["add"]) > 0 || sizeof($fileQueue["update"]) > 0 || sizeof($fileQueue["delete"]) > 0) {
				$this->SendDebug("Upload", sprintf("Remaining = Add: %d, Update: %d, Remove: %d", sizeof($fileQueue["add"]), sizeof($fileQueue["update"]), sizeof($fileQueue["delete"])), 0);
			}
			
			//Save the updated FileQueue
			$this->SetBuffer("FileQueue", json_encode($fileQueue));
			
			//Save timestamp of last action
			$this->SetBuffer("LastUpload", time());
			
		}
		
	}

?>
