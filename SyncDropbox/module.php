<?php

declare(strict_types=1);

    include_once __DIR__ . '/../libs/Dropbox.php';

    class SyncDropbox extends IPSModule
    {
        //This one needs to be available on our OAuth client backend.
        //Please contact us to register for an identifier: https://www.symcon.de/kontakt/#OAuth
        private $oauthIdentifer = 'sync_dropbox';

        //You normally do not need to change this
        private $oauthServer = 'oauth.ipmagic.de';

        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->RegisterPropertyBoolean('Active', true);

            //Legacy. We want to use an Attribute instead.
            $this->RegisterPropertyString('Token', '');
            $this->RegisterAttributeString('Token', '');

            //Flag if the Token is the old persistent one, or a Refresh Token
            $this->RegisterAttributeBoolean('Refresh', false);

            $this->RegisterPropertyInteger('TimeLimit', 90);

            $this->RegisterPropertyString('PathFilter', '');

            $this->RegisterPropertyInteger('SizeLimit', 20); //In Megabytes

            $this->RegisterPropertyInteger('ReSyncInterval', 60); //In Minutes

            $this->RegisterPropertyInteger('UploadLimit', 5); //In Minutes

            $this->RegisterVariableInteger('LastFinishedBackup', $this->Translate('Last finished backup'), 'UnixTimestamp', 0);

            if (!IPS_VariableProfileExists('Megabytes.Dropbox')) {
                IPS_CreateVariableProfile('Megabytes.Dropbox', VARIABLETYPE_FLOAT);
                IPS_SetVariableProfileText('Megabytes.Dropbox', '', ' MB');
                IPS_SetVariableProfileDigits('Megabytes.Dropbox', 2);
            }

            $this->RegisterVariableFloat('TransferredMegabytes', $this->Translate('Transferred Megabytes'), 'Megabytes.Dropbox', 1);

            //Start first Sync after a short wait period (will be set in ApplyChanges)
            $this->RegisterTimer('Sync', 0, "SDB_Sync(\$_IPS['TARGET']);");

            //ReSync is done after within the defined interval the first Sync
            //ReSync will not be started if an Upload is currently running
            $this->RegisterTimer('ReSync', 0, "SDB_ReSync(\$_IPS['TARGET']);");

            //Disable uploading by default. Upload will be started after Sync/ReSync
            $this->RegisterTimer('Upload', 0, "SDB_Upload(\$_IPS['TARGET']);");
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();

            // Only call this in READY state. On startup the WebOAuth instance might not be available yet
            if (IPS_GetKernelRunlevel() == KR_READY) {
                $this->RegisterOAuth($this->oauthIdentifer);
            }

            if (!$this->ReadPropertyBoolean('Active')) {
                $this->SetTimerInterval('Sync', 0);
                $this->SetTimerInterval('ReSync', 0);
                $this->SetTimerInterval('Upload', 0);
                $this->SetBuffer('FileQueue', '');
                $this->SetBuffer('FileCache', '');
            } else {
                //Start first Sync after 10 seconds while in KR_READY, otherwise wait 5 minutes
                if (IPS_GetKernelRunlevel() == KR_READY && $this->GetStatus() == IS_ACTIVE) {
                    $this->SetTimerInterval('Sync', 10 * 1000);
                } else {
                    $this->SetTimerInterval('Sync', 5 * 60 * 1000);
                }
            }
        }

        private function RegisterOAuth($WebOAuth)
        {
            $ids = IPS_GetInstanceListByModuleID('{F99BF07D-CECA-438B-A497-E4B55F139D37}');
            if (count($ids) > 0) {
                $clientIDs = json_decode(IPS_GetProperty($ids[0], 'ClientIDs'), true);
                $found = false;
                foreach ($clientIDs as $index => $clientID) {
                    if ($clientID['ClientID'] == $WebOAuth) {
                        if ($clientID['TargetID'] == $this->InstanceID) {
                            return;
                        }
                        $clientIDs[$index]['TargetID'] = $this->InstanceID;
                        $found = true;
                    }
                }
                if (!$found) {
                    $clientIDs[] = ['ClientID' => $WebOAuth, 'TargetID' => $this->InstanceID];
                }
                IPS_SetProperty($ids[0], 'ClientIDs', json_encode($clientIDs));
                IPS_ApplyChanges($ids[0]);
            }
        }

        /**
         * This function will be called by the register button on the property page!
         */
        public function Register()
        {

            //Return everything which will open the browser
            return 'https://' . $this->oauthServer . '/authorize/' . $this->oauthIdentifer . '?username=' . urlencode(IPS_GetLicensee());
        }

        private function FetchRefreshToken($code)
        {
            $this->SendDebug('FetchRefreshToken', 'Use Authentication Code to get our precious Refresh Token!', 0);

            //Exchange our Authentication Code for a permanent Refresh Token and a temporary Access Token
            $options = [
                'http' => [
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query(['code' => $code])
                ]
            ];
            $context = stream_context_create($options);
            $result = file_get_contents('https://' . $this->oauthServer . '/access_token/' . $this->oauthIdentifer, false, $context);

            $data = json_decode($result);

            if (!isset($data->token_type) || strtolower($data->token_type) != 'bearer') {
                die('Bearer Token expected');
            }

            //Save temporary access token
            $this->FetchAccessToken($data->access_token, time() + $data->expires_in);

            //Return RefreshToken
            return $data->refresh_token;
        }

        /**
         * This function will be called by the OAuth control. Visibility should be protected!
         */
        protected function ProcessOAuthData()
        {

            //Lets assume requests via GET are for code exchange. This might not fit your needs!
            if ($_SERVER['REQUEST_METHOD'] == 'GET') {
                if (!isset($_GET['code'])) {
                    die('Authorization Code expected');
                }

                $token = $this->FetchRefreshToken($_GET['code']);

                $this->SendDebug('ProcessOAuthData', "OK! Let's save the Refresh Token permanently", 0);

                $this->WriteAttributeString('Token', $token);
                $this->WriteAttributeBoolean('Refresh', true);
                $this->UpdateFormField('Token', 'caption', 'Token: ' . substr($token, 0, 16) . '...');
            } else {

                //Just print raw post data!
                echo file_get_contents('php://input');
            }
        }

        private function FetchAccessToken($Token = '', $Expires = 0)
        {

            //Return our persistent Token (for compatibility with old DropBox Tokens)
            if (!$this->ReadAttributeBoolean('Refresh')) {
                //Prefer attribute if it is set
                if ($this->ReadAttributeString('Token')) {
                    return $this->ReadAttributeString('Token');
                }
                //Fallback to legacy property value
                return $this->ReadPropertyString('Token');
            }

            //Exchange our Refresh Token for a temporary Access Token
            if ($Token == '' && $Expires == 0) {

                //Check if we already have a valid Token in cache
                $data = $this->GetBuffer('AccessToken');
                if ($data != '') {
                    $data = json_decode($data);
                    if (time() < $data->Expires) {
                        $this->SendDebug('FetchAccessToken', 'OK! Access Token is valid until ' . date('d.m.y H:i:s', $data->Expires), 0);
                        return $data->Token;
                    }
                }

                $this->SendDebug('FetchAccessToken', 'Use Refresh Token to get new Access Token!', 0);

                //If we slipped here we need to fetch the access token
                $options = [
                    'http' => [
                        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'method'  => 'POST',
                        'content' => http_build_query(['refresh_token' => $this->ReadAttributeString('Token')])
                    ]
                ];
                $context = stream_context_create($options);
                $result = file_get_contents('https://' . $this->oauthServer . '/access_token/' . $this->oauthIdentifer, false, $context);

                $data = json_decode($result);

                if (!isset($data->token_type) || strtolower($data->token_type) != 'bearer') {
                    die('Bearer Token expected');
                }

                //Update parameters to properly cache it in the next step
                $Token = $data->access_token;
                $Expires = time() + $data->expires_in;

                //Update Refresh Token if we received one! (This is optional)
                if (isset($data->refresh_token)) {
                    $this->SendDebug('FetchAccessToken', "NEW! Let's save the updated Refresh Token permanently", 0);

                    $this->WriteAttributeString('Token', $data->refresh_token);
                    $this->UpdateFormField('Token', 'caption', 'Token: ' . substr($data->refresh_token, 0, 16) . '...');
                }
            }

            $this->SendDebug('FetchAccessToken', 'CACHE! New Access Token is valid until ' . date('d.m.y H:i:s', $Expires), 0);

            //Save current Token
            $this->SetBuffer('AccessToken', json_encode(['Token' => $Token, 'Expires' => $Expires]));

            //Return current Token
            return $Token;
        }

        //Source: https://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
        private function formatBytes($size, $precision = 2)
        {
            $base = log($size, 1024);
            $suffixes = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

            if ($size == 0) {
                return '0 B';
            } else {
                return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
            }
        }

        //Source: https://github.com/dropbox/dropbox-api-content-hasher/pull/2
        private function dropbox_hash_stream($stream, $chunksize = 8 * 1024)
        {
            // Based on:
            // https://www.dropbox.com/developers/reference/content-hash
            // https://github.com/dropbox/dropbox-api-content-hasher/blob/master/python/dropbox_content_hasher.py

            // milky-way-nasa.jpg:
            // block 1: 4194304 bytes, 2a846fa617c3361fc117e1c5c1e1838c336b6a5cef982c1a2d9bdf68f2f1992a
            // block 2: 4194304 bytes, c68469027410ea393eba6551b9fa1e26db775f00eae70a0c3c129a0011a39cf9
            // block 3: 1322815 bytes, 7376192de020925ce6c5ef5a8a0405e931b0a9a8c75517aacd9ca24a8a56818b
            // --------
            // file     9711423 bytes, 485291fa0ee50c016982abbfa943957bcd231aae0492ccbaa22c58e3997b35e0

            $BLOCK_SIZE = 4 * 1024 * 1024;

            $streamhasher = hash_init('sha256');
            $blockhasher = hash_init('sha256');

            $current_block = 1;
            $current_blocksize = 0;
            while (!feof($stream)) {
                $max_bytes_to_read = min($chunksize, $BLOCK_SIZE - $current_blocksize);
                $chunk = fread($stream, $max_bytes_to_read);
                if (strlen($chunk) == 0) {
                    // This stream was a multiple of $BLOCK_SIZE; this "block" is empty
                    // and shouldn't be hashed.
                    break;
                }
                hash_update($blockhasher, $chunk);
                $current_blocksize += $max_bytes_to_read;

                if ($current_blocksize == $BLOCK_SIZE) {
                    $blockhash = hash_final($blockhasher, true);
                    #print('block ' . $current_block . ': ' . bin2hex($blockhash) . "\n");
                    hash_update($streamhasher, $blockhash);
                    $blockhasher = hash_init('sha256');
                    $current_block += 1;
                    $current_blocksize = 0;
                }
            }

            if ($current_blocksize > 0) {
                $blockhash = hash_final($blockhasher, true);
                #print('block ' . $current_block . ': ' . bin2hex($blockhash) . "\n");
                hash_update($streamhasher, $blockhash);
            }

            $filehash = hash_final($streamhasher);
            return $filehash;
        }

        private function dropbox_hash_file($path)
        {
            $handle = fopen($path, 'r');
            $hash = $this->dropbox_hash_stream($handle);
            fclose($handle);
            return $hash;
        }

        public function GetConfigurationForm()
        {
            $data = json_decode(file_get_contents(__DIR__ . '/form.json'));

            //This option is only relevant for older IP-Symcon versions. Since IP-Symcon 5.6 max_execution_time is always unlimited
            $data->elements[1]->items[0]->visible = $this->HasTimeLimit();
            $data->elements[1]->items[1]->visible = $this->HasTimeLimit();

            if ($this->FetchAccessToken()) {
                $dropbox = new Dropbox\Dropbox($this->FetchAccessToken(), $this->ReadPropertyInteger('UploadLimit') * 60);
                $account = $dropbox->users->get_current_account();
                if (!$account || isset($account['error_summary'])) {

                    //Show warning
                    $data->actions[0]->visible = true;
                } else {

                    //Hide the register button
                    $data->actions[1]->visible = false;

                    //Show token excerpt
                    if ($this->FetchAccessToken()) {
                        $data->actions[2]->caption = $this->Translate('Token') . ': ' . substr($this->FetchAccessToken(), 0, 16) . '...';
                    }

                    $space = $dropbox->users->get_space_usage();

                    $data->actions[3]->visible = true;
                    $data->actions[3]->caption = $this->Translate('Owner') . ': ' . $account['name']['display_name'];

                    $data->actions[4]->visible = true;
                    $data->actions[4]->caption = $this->Translate('Used Space') . ': ' . $this->formatBytes($space['used']) . ' / ' . $this->formatBytes($space['allocation']['allocated']);

                    if (intval($this->GetBuffer('BackupSize')) > 0) {
                        $data->actions[5]->visible = true;
                        $data->actions[5]->caption = $this->Translate('Backup Size') . ': ' . $this->formatBytes(intval($this->GetBuffer('BackupSize')));
                    }

                    $data->actions[6]->visible = true;
                    if (intval($this->GetBuffer('LastFinishedSync')) > 0) {
                        $data->actions[6]->caption = $this->Translate('Last Synchronization') . ': ' . date('d.m.Y H:i', intval($this->GetBuffer('LastFinishedSync')));
                    } else {
                        $data->actions[6]->caption = $this->Translate('Last Synchronization') . ': ' . $this->Translate('Never');
                    }

                    if ($this->GetBuffer('FileQueue') != '') {
                        $fileQueue = json_decode(gzdecode($this->GetBuffer('FileQueue')), true);
                        if (count($fileQueue['add']) > 0 || count($fileQueue['update']) > 0 || count($fileQueue['delete']) > 0) {
                            $data->actions[8]->visible = true;
                        } else {
                            $data->actions[9]->visible = true;
                        }
                    } else {
                        $data->actions[9]->visible = true;
                    }
                }
            }

            return json_encode($data);
        }

        private function HasTimeLimit()
        {
            return IPS_GetKernelVersion() != '0.0' && floatval(IPS_GetKernelVersion()) < 5.6;
        }

        private function IgnorePath($file)
        {
            //Any non UTF-8 filename will break everything. Therefore we need to filter them
            //See: https://stackoverflow.com/a/1523574/10288655 (Regex seems to be faster than mb_check_encoding)
            if (!preg_match('%^(?:
              [\x09\x0A\x0D\x20-\x7E]            # ASCII
            | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
            | \xE0[\xA0-\xBF][\x80-\xBF]         # excluding overlongs
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
            | \xED[\x80-\x9F][\x80-\xBF]         # excluding surrogates
            | \xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
            | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
            | \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
            )*$%xs', $file)) {
                return true;
            }

            //Always compare lower case
            $file = mb_strtolower($file);

            //Check against file filter
            if ($this->ReadPropertyString('PathFilter') != '') {
                $filters = explode(';', $this->ReadPropertyString('PathFilter'));
                foreach ($filters as $filter) {
                    if (substr($file, 0, strlen($filter)) == $filter) {
                        return true;
                    }
                }
            }

            //Some faulty scripts can produce invalid filenames that start with a backslash. Dropbox will not upload them
            if ($file[0] == '\\') {
                return true;
            }

            $path_info = pathinfo($file);

            //We do not require to backup sessions
            if (substr($file, 0, 7) == 'session') {
                return true;
            }

            //Filter Thumbs.db and .DS_Store. Dropbox will ignore uploads anyway
            if ($path_info['basename'] == 'thumbs.db') {
                return true;
            }
            if ($path_info['basename'] == '.ds_store') {
                return true;
            }

            //For Windows we want to exclude logs as well, which are not in a separate folder
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                if (substr($file, 0, 4) == 'logs') {
                    return true;
                }
            }

            return false;
        }

        private function GetDestinationFolder()
        {
            return IPS_GetLicensee();
        }

        private function CalculateFileQueue(& $fileCache)
        {
            $fileQueue = ['add' => [], 'update' => [], 'delete' => []];

            $baseDir = IPS_GetKernelDir();

            $backupSize = 0;
            $backupSkip = 0;
            $uploadSize = 0;

            $touchedFiles = [];

            //Iterate through the locale filesystem and add all files not in the index
            $searchDir = function ($dir) use ($baseDir, &$fileCache, &$fileQueue, &$searchDir, &$backupSize, &$backupSkip, &$uploadSize, &$touchedFiles)
            {
                $this->UpdateFormField('UploadProgress', 'caption', sprintf($this->Translate('Scanning... %s'), $dir));

                $files = scandir($baseDir . $dir);
                foreach ($files as $file) {
                    //Ignore special folders
                    if ($file == '.' || $file == '..') {
                        continue;
                    }

                    //Ignore specified files and folders
                    if ($this->IgnorePath($dir . $file)) {
                        continue;
                    }

                    if (is_dir($baseDir . $dir . $file)) {
                        $searchDir($dir . $file . '/');
                    } else {
                        $touchedFiles[] = mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $dir . $file);

                        $filesize = filesize($baseDir . $dir . $file);

                        //If the file grew over the limit we will keep the last valid file in the backup
                        if ($filesize > $this->ReadPropertyInteger('SizeLimit') * 1024 * 1024) {
                            //Skip files that are too big
                            $this->SendDebug('Index', sprintf('Skipping too big file... %s. Size: %s', $dir . $file, $this->formatBytes($filesize)), 0);

                            //Sum skipped files for statistics
                            $backupSkip++;
                        } else {
                            //Lets sum up every file we want to backup
                            $backupSize += $filesize;

                            //Add any new files
                            if (!isset($fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $dir . $file)])) {
                                $fileQueue['add'][] = $dir . $file;
                                $uploadSize += $filesize;
                            } else {
                                //First sync. Lets match the hash
                                if (is_string($fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $dir . $file)])) {
                                    if ($this->dropbox_hash_file($baseDir . $dir . $file) != $fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $dir . $file)]) {
                                        $fileQueue['update'][] = $dir . $file;
                                        $uploadSize += $filesize;
                                    } else {
                                        $fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $dir . $file)] = filemtime($baseDir . $dir . $file);
                                    }
                                } elseif (is_int($fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $dir . $file)])) {
                                    if (filemtime($baseDir . $dir . $file) != $fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $dir . $file)]) {
                                        $fileQueue['update'][] = $dir . $file;
                                        $uploadSize += $filesize;
                                    }
                                }
                            }
                        }
                    }
                }
            };
            $searchDir('');

            //all untouched files in the fileCache need to be deleted
            $fileQueue['delete'] = array_values(array_diff(array_keys($fileCache), $touchedFiles));

            $this->SendDebug('Index', sprintf('Total Backup Size: %s, Upload Size: %s', $this->formatBytes($backupSize), $this->formatBytes($uploadSize)), 0);

            $this->SetBuffer('BackupSize', $backupSize);
            $this->SetBuffer('BackupSkip', $backupSkip);

            //Send new Backup Size
            $this->UpdateFormField('BackupSize', 'visible', true);
            $this->UpdateFormField('BackupSize', 'caption', $this->Translate('Backup Size') . ': ' . $this->formatBytes(intval($this->GetBuffer('BackupSize'))));

            return $fileQueue;
        }

        public function Sync()
        {
            $this->SetStatus(IS_ACTIVE);

            $this->SetTimerInterval('Sync', 0);

            if (!$this->ReadPropertyBoolean('Active')) {
                return;
            }

            if (!$this->FetchAccessToken()) {
                return;
            }

            //Show some progress
            $this->UpdateFormField('UploadProgress', 'visible', true);
            $this->UpdateFormField('UploadProgress', 'caption', $this->Translate('Sync in progress...'));
            $this->UpdateFormField('ForceSync', 'visible', false);

            if ($this->HasTimeLimit()) {
                set_time_limit($this->ReadPropertyInteger('TimeLimit'));
            }

            $dropbox = new Dropbox\Dropbox($this->FetchAccessToken(), $this->ReadPropertyInteger('UploadLimit') * 60);

            $targets = $dropbox->files->list_folder('', false);

            if (!$targets) {
                $this->UpdateFormField('UploadProgress', 'visible', false);
                $this->UpdateFormField('ForceSync', 'visible', true);

                echo 'Sync Error: Cannot load target folders!';
                return;
            }

            $fileCache = [];

            //Only update file cache if the target folder already exists
            foreach ($targets['entries'] as $target) {
                if ($target['path_lower'] == mb_strtolower('/' . $this->GetDestinationFolder())) {
                    $files = null;
                    while ($files == null || $files['has_more']) {
                        if ($files == null) {
                            $files = $dropbox->files->list_folder('/' . $this->GetDestinationFolder(), true);
                        } else {
                            $files = $dropbox->files->list_folder_continue($files['cursor']);
                        }

                        if (!$files) {
                            echo 'Sync Error: Cannot load already uploaded files!';
                            return;
                        }

                        foreach ($files['entries'] as $file) {
                            if ($file['.tag'] == 'file') {
                                $fileCache[$file['path_lower']] = $file['content_hash'];
                            }
                        }
                    }
                }
            }

            //Build the add/update/delete queue. Will also update the fileCache!
            $fileQueue = $this->CalculateFileQueue($fileCache);

            //Save all entries for partial sync
            $compressedFileCache = gzencode(json_encode($fileCache, JSON_THROW_ON_ERROR));
            $this->SendDebug('Sync', sprintf('We have %d files in your Dropbox (FileCache: %s)', count($fileCache), $this->formatBytes(strlen($compressedFileCache))), 0);

            //Save the FileQueue which the Upload function will process
            $compressedFileQueue = gzencode(json_encode($fileQueue, JSON_THROW_ON_ERROR));
            $this->SendDebug('Sync', sprintf('Sync = Add: %d, Update: %d, Remove: %d (FileQueue: %s)', count($fileQueue['add']), count($fileQueue['update']), count($fileQueue['delete']), $this->formatBytes(strlen($compressedFileQueue))), 0);

            //Show error if we have too many files
            if ((strlen($compressedFileCache) >= 512 * 1024) || (strlen($compressedFileQueue) >= 512 * 1024)) {
                $this->SetStatus(IS_EBASE + 1);

                $this->UpdateFormField('UploadProgress', 'visible', false);
                $this->UpdateFormField('ForceSync', 'visible', true);

                return;
            }

            $this->SetBuffer('FileCache', $compressedFileCache);
            $this->SetBuffer('FileQueue', $compressedFileQueue);

            //Start Upload if there is anything to do
            if (count($fileQueue['add']) > 0 || count($fileQueue['update']) > 0 || count($fileQueue['delete']) > 0) {
                //Start Upload
                $this->SendDebug('Sync', 'Upload will start in 10 seconds...', 0);
                $this->SetTimerInterval('Upload', 10 * 1000);
                $this->UpdateFormField('UploadProgress', 'caption', $this->Translate('Upload will start in 10 seconds...'));
            } else {
                $this->SendDebug('Sync', 'Done. Everything is up to date.', 0);
                $this->SetBuffer('LastFinishedSync', time());
                $this->UpdateFormField('LastFinishedSync', 'caption', $this->Translate('Last Synchronization') . ': ' . date('d.m.Y H:i', time()));
                $this->UpdateFormField('UploadProgress', 'visible', false);
                $this->UpdateFormField('ForceSync', 'visible', true);
            }

            //Start ReSync. At least 60 minutes.
            $this->SetTimerInterval('ReSync', max($this->ReadPropertyInteger('ReSyncInterval'), 60) * 60 * 1000);
        }

        public function ReSync()
        {
            if (!$this->ReadPropertyBoolean('Active')) {
                return;
            }

            if ($this->HasTimeLimit()) {
                set_time_limit($this->ReadPropertyInteger('TimeLimit'));
            }

            //Load the current FileQueue
            $fileQueue = json_decode(gzdecode($this->GetBuffer('FileQueue')), true);

            //If there are any pending uploading we will skip the resync
            if (count($fileQueue['add']) > 0 || count($fileQueue['update']) > 0 || count($fileQueue['delete']) > 0) {
                if (intval($this->GetBuffer('LastUpload')) + 15 * 60 > time()) {
                    $this->SendDebug('ReSync', 'Forced. Upload seems to be stuck', 0);
                } else {
                    $this->SendDebug('ReSync', 'Skipping. Upload has not completed yet', 0);
                    return;
                }
            }

            //Show some progress
            $this->UpdateFormField('UploadProgress', 'visible', true);
            $this->UpdateFormField('UploadProgress', 'caption', $this->Translate('ReSync in progress...'));
            $this->UpdateFormField('ForceSync', 'visible', false);

            //Load the current FileCache
            $fileCache = json_decode(gzdecode($this->GetBuffer('FileCache')), true);

            //Build the add/update/delete queue. Will also update the fileCache!
            $fileQueue = $this->CalculateFileQueue($fileCache);

            //Save the updated FileCache
            $compressedFileCache = gzencode(json_encode($fileCache, JSON_THROW_ON_ERROR));
            $this->SendDebug('ReSync', sprintf('We have %d files in your Dropbox (FileCache: %s)', count($fileCache), $this->formatBytes(strlen($compressedFileCache))), 0);
            $this->SetBuffer('FileCache', $compressedFileCache);

            //Save the updated FileQueue
            $compressedFileQueue = gzencode(json_encode($fileQueue, JSON_THROW_ON_ERROR));
            $this->SendDebug('ReSync', sprintf('ReSync = Add: %d, Update: %d, Remove: %d (FileQueue: %s)', count($fileQueue['add']), count($fileQueue['update']), count($fileQueue['delete']), $this->formatBytes(strlen($compressedFileQueue))), 0);
            $this->SetBuffer('FileQueue', $compressedFileQueue);

            //Start Upload if there is anything to do
            if (count($fileQueue['add']) > 0 || count($fileQueue['update']) > 0 || count($fileQueue['delete']) > 0) {
                //Start Upload
                $this->SendDebug('ReSync', 'Upload will start in 10 seconds...', 0);
                $this->SetTimerInterval('Upload', 10 * 1000);
                $this->UpdateFormField('UploadProgress', 'caption', $this->Translate('Upload will start in 10 seconds...'));
            } else {
                $this->SendDebug('ReSync', 'Done. Everything is up to date.', 0);
                $this->SetBuffer('LastFinishedSync', time());
                $this->UpdateFormField('LastFinishedSync', 'caption', $this->Translate('Last Synchronization') . ': ' . date('d.m.Y H:i', time()));
                $this->UpdateFormField('UploadProgress', 'visible', false);
                $this->UpdateFormField('ForceSync', 'visible', true);
            }
        }

        public function Upload()
        {
            $this->SetTimerInterval('Upload', 0);

            if (!$this->ReadPropertyBoolean('Active')) {
                return;
            }

            if ($this->HasTimeLimit()) {
                set_time_limit($this->ReadPropertyInteger('TimeLimit'));
            }

            $dropbox = new Dropbox\Dropbox($this->FetchAccessToken(), $this->ReadPropertyInteger('UploadLimit') * 60);

            $baseDir = IPS_GetKernelDir();

            //Load the current FileCache
            $fileCache = json_decode(gzdecode($this->GetBuffer('FileCache')), true);

            //Load the current FileQueue
            $fileQueue = json_decode(gzdecode($this->GetBuffer('FileQueue')), true);

            //Upload new files first
            if (count($fileQueue['add']) > 0) {
                //Upload to Dropbox
                $this->SendDebug('Upload', sprintf('Adding file... %s. Size %s', $fileQueue['add'][0], $this->formatBytes(filesize($baseDir . $fileQueue['add'][0]))), 0);
                $dropbox->files->upload('/' . $this->GetDestinationFolder() . '/' . $fileQueue['add'][0], $baseDir . $fileQueue['add'][0]);

                //Add uploaded file to fileCache
                $fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $fileQueue['add'][0])] = filemtime($baseDir . $fileQueue['add'][0]);

                //Add to upload statistic
                $this->SetValue('TransferredMegabytes', $this->GetValue('TransferredMegabytes') + (filesize($baseDir . $fileQueue['add'][0]) / 1024 / 1024));

                //Remove successful upload
                array_shift($fileQueue['add']);

                //Start timer for next upload
                $this->SetTimerInterval('Upload', 1000);
            } elseif (count($fileQueue['update']) > 0) {
                //Upload to Dropbox
                $this->SendDebug('Upload', sprintf('Updating file... %s. Size %s', $fileQueue['update'][0], $this->formatBytes(filesize($baseDir . $fileQueue['update'][0]))), 0);
                $dropbox->files->upload('/' . $this->GetDestinationFolder() . '/' . $fileQueue['update'][0], $baseDir . $fileQueue['update'][0], 'overwrite');

                //Update uploaded file in fileCache
                $fileCache[mb_strtolower('/' . $this->GetDestinationFolder() . '/' . $fileQueue['update'][0])] = filemtime($baseDir . $fileQueue['update'][0]);

                //Add to upload statistic
                $this->SetValue('TransferredMegabytes', $this->GetValue('TransferredMegabytes') + (filesize($baseDir . $fileQueue['update'][0]) / 1024 / 1024));

                //Remove successful upload
                array_shift($fileQueue['update']);

                $this->SetTimerInterval('Upload', 1000);
            } elseif (count($fileQueue['delete']) > 0) {
                //Delete from Dropbox (Remove first path element to prevent leaking the license username)
                $this->SendDebug('Upload', sprintf('Deleting file... %s', substr($fileQueue['delete'][0], strpos($fileQueue['delete'][0], '/', 1) + 1)), 0);
                $dropbox->files->delete($fileQueue['delete'][0]);

                //Update uploaded file in fileCache
                unset($fileCache[mb_strtolower($fileQueue['delete'][0])]);

                //Remove successful upload
                array_shift($fileQueue['delete']);

                $this->SetTimerInterval('Upload', 1000);
            } else {
                //We are done
                $this->SendDebug('Upload', 'Finished', 0);
                $this->SetTimerInterval('Upload', 0);
                $this->SetBuffer('LastFinishedSync', time());
                $this->UpdateFormField('LastFinishedSync', 'caption', $this->Translate('Last Synchronization') . ': ' . date('d.m.Y H:i', time()));

                //Update variable with last finished backup timestamp
                $this->SetValue('LastFinishedBackup', time());
            }

            //Show progress if there is anything left to do
            if (count($fileQueue['add']) > 0 || count($fileQueue['update']) > 0 || count($fileQueue['delete']) > 0) {
                $this->SendDebug('Upload', sprintf('Remaining = Add: %d, Update: %d, Remove: %d', count($fileQueue['add']), count($fileQueue['update']), count($fileQueue['delete'])), 0);
                $this->UpdateFormField('UploadProgress', 'caption', sprintf($this->Translate('Add: %d, Update: %d, Remove: %d'), count($fileQueue['add']), count($fileQueue['update']), count($fileQueue['delete'])));
            } else {
                $this->UpdateFormField('UploadProgress', 'visible', false);
                $this->UpdateFormField('ForceSync', 'visible', true);
            }

            //Save the updated FileCache
            $this->SetBuffer('FileCache', gzencode(json_encode($fileCache, JSON_THROW_ON_ERROR)));

            //Save the updated FileQueue
            $this->SetBuffer('FileQueue', gzencode(json_encode($fileQueue, JSON_THROW_ON_ERROR)));

            //Save timestamp of last action
            $this->SetBuffer('LastUpload', time());
        }
    }
