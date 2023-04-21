<?php

declare(strict_types=1);

namespace Dropbox;

    class Dropbox
    {
        private static $token;
        private static $timeout;
        public $files;
        public $users;

        public function __construct($access_token, $timeout)
        {
            self::$token = $access_token;
            self::$timeout = $timeout;
            $this->files = new Files();
            $this->users = new Users();
        }

        /*
         * Main function for handling post requests.
         */
        public static function postRequest($endpoint, $headers, $data, $json = true)
        {
            $ch = curl_init($endpoint);
            array_push($headers, 'Authorization: Bearer ' . self::$token);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::$timeout);
            $r = curl_exec($ch);
            curl_close($ch);

            if ($json) {
                $j = json_decode($r, true);
                if (!$j) {
                    throw new \Exception($r);
                }
                if (isset($j['error'])) {
                    throw new \Exception($j['error_summary']);
                }
                return $j;
            } else {
                return $r;
            }
        }
    }

    class Users
    {
        public function get_current_account()
        {
            $endpoint = 'https://api.dropboxapi.com/2/users/get_current_account';
            $headers = [
                'Content-Type: application/json'
            ];
            $postdata = 'null';
            return Dropbox::postRequest($endpoint, $headers, $postdata);
        }

        public function get_space_usage()
        {
            $endpoint = 'https://api.dropboxapi.com/2/users/get_space_usage';
            $headers = [
                'Content-Type: application/json'
            ];
            $postdata = 'null';
            return Dropbox::postRequest($endpoint, $headers, $postdata);
        }
    }

    class Files
    {
        /**
         * deletes a file or folder at a given path
         */
        public function delete($path)
        {
            $endpoint = 'https://api.dropboxapi.com/2/files/delete';
            $headers = [
                'Content-Type: application/json'
            ];
            $postdata = json_encode(['path' => $path]);
            return Dropbox::postRequest($endpoint, $headers, $postdata);
        }

        /**
         * gets the contents of a folder
         */
        public function list_folder($path, $recursive = false, $include_media_info = false, $include_has_explicit_shared_members = false)
        {
            $endpoint = 'https://api.dropboxapi.com/2/files/list_folder';
            $headers = [
                'Content-Type: application/json',
            ];
            $postdata = json_encode(['path' => $path, 'recursive' => $recursive, 'include_media_info' => $include_media_info, 'include_has_explicit_shared_members' => $include_has_explicit_shared_members]);
            return Dropbox::postRequest($endpoint, $headers, $postdata);
        }

        /**
         * continue listing the contents of a folder given a cursor from list_folder or
         *     a previous call of list_folder_continue
         */
        public function list_folder_continue($cursor)
        {
            $endpoint = 'https://api.dropboxapi.com/2/files/list_folder/continue';
            $headers = [
                'Content-Type: application/json',
            ];
            $postdata = json_encode(['cursor' => $cursor]);
            return Dropbox::postRequest($endpoint, $headers, $postdata);
        }

        /*
         * $file_data can be either raw string data or a path to a file
         * @parameter $mode: "add", "update", "overwrite"
         */
        public function upload($target_path, $file_data, $mode = 'add')
        {
            $endpoint = 'https://content.dropboxapi.com/2/files/upload';
            $headers = [
                'Content-Type: application/octet-stream',
                "Dropbox-API-Arg: {\"path\": \"$target_path\", \"mode\": \"$mode\"}"
            ];
            if (file_exists($file_data)) {
                $postdata = file_get_contents($file_data);
            } else {
                $postdata = $file_data;
            }
            return Dropbox::postRequest($endpoint, $headers, $postdata)['path_display'];
        }
    }