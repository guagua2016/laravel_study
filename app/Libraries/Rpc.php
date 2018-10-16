<?php namespace App\Libraries;

use Log;
use Session;

class Rpc
{
    // public function __construct($show = false){
    //     $this->show = $show;
    //     $this->time_start= microtime(true);
    // }

    // public function __destruct(){
    //     if ($this->show) {
    //         $this->time_end = microtime(true);
    //         $this->time = $this->time_end - $this->time_start;
    //         Log::info(sprintf("rpc %s in %d ms ", $this->key, $this->time * 1000));
    //     }
    // }

    public static function get($host, $path, $params = false, $showUrl = false)
    {
        if (!$params) {
            $url = sprintf("%s%s", $host, $path);
        } else {
            $url = sprintf("%s%s?%s", $host, $path, http_build_query($params));
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,$url);

        $sid = '';
        if (isset($_COOKIE['laravel_session'])) {
            $sid = $_COOKIE['laravel_session'];
            curl_setopt($ch, CURLOPT_COOKIE, sprintf('laravel_session=%s', $sid));
        }

        $sha1Data = date('YmdHis');
        $sha1Salt = env('SHA_KEY',env('APP_KEY', ''));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            sprintf('X-Koudai-Token: %s|%s', $sha1Data, sha1($sha1Data.$sha1Salt)),
        ]);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);


        $response = curl_exec ($ch);

        curl_close ($ch);
        // var_dump($response);

        $json =  json_decode($response, true);
        if ($showUrl || $json == false || env('APP_DEBUG_RPC', false)) {
            $context = [
                'url' => $url,
                'sid' => $sid,
                'sid2' => Session::getId(),
                'json' => $json,
                // 'raw' => substr(strip_tags($response), 0, 500),
            ];
            if ($json == false) {
                Log::error('Rpc::get error:', $context);
            } else {
                Log::info('Rpc::get result', $context);
            }
        }

        if (!isset($json['code'])) {
            return ['code' => 20401, 'message' => "bad response", 'result' => $json, 'raw' => $response];
        } else {
            return $json;
        }
    }

    public static function post($host, $path, $data = false, $params = false, $showUrl = false)
    {
        if (!$params) {
            $url = sprintf("%s%s", $host, $path);
        } else {
            $url = sprintf("%s%s?%s", $host, $path, http_build_query($params));
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_POST, 1);

        $postData = '';
        if ($data) {
            if (is_string($data)) {
                $postData = $data;
            } else {
                $postData = http_build_query($data);
            }

            curl_setopt($ch, CURLOPT_POSTFIELDS,$postData);
        }

        $sid = '';
        if (isset($_COOKIE['laravel_session'])) {
            $sid = $_COOKIE['laravel_session'];
            curl_setopt($ch, CURLOPT_COOKIE, sprintf('laravel_session=%s', $sid));
        }

        $sha1Data = date('YmdHis');
        $sha1Salt = env('APP_KEY', '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            sprintf('X-Koudai-Token: %s|%s', $sha1Data, sha1($sha1Data.$sha1Salt)),
        ]);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec ($ch);

        curl_close ($ch);
        // var_dump($response);

        $json =  json_decode($response, true);
        if ($showUrl || $json == false || env('APP_DEBUG_RPC', false)) {
            $context = [
                'url' => $url,
                'postData' => $postData,
                // 'sid' => $sid,
                // 'sid2' => Session::getId(),
                'json' => $json,
                // 'raw' => substr(strip_tags($response), 0, 500),
            ];
            if ($json == false) {
                Log::error('Rpc::post error:', $context);
            } else {
                Log::info('Rpc::post result', $context);
            }
        }

        if (!isset($json['code'])) {
            return ['code' => 20401, 'message' => "bad response", 'result' => $json, 'raw' => $response];
        } else {
            return $json;
        }
    }

}
