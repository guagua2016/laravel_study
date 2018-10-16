<?php namespace App\Libraries\YmSdk;
use App\YingmiPaymentMethod;
use Log;
use App\YingmiAccount;
use Storage;
use App\GlobalId;
use Hash;
use App\Libraries\Rpc;
use App\Libraries\AccountService;

class YmHelper {
    //盈米实时接口前缀
    var $realUrl = null;
    //盈米文件接口前缀
    var $fileUrl = null;
    //商户api key
    var $apiKey = null;
    //商户api secret
    var $apiSecret = null;
    //盈米服务器证书
    var $ymCrt = null;
    //魔方服务器证书
    var $ymMfPublic = null;
    //魔方私钥
    var $ymMfPrivateKey = null;
    //魔方私钥密码
    var $ymMfPrivateKeyPassword = null;
    //不显示日志的rpc

    function __construct()
    {
        $this->realUrl = env('YM_REAL_URL', 'https://api-test.frontnode.net/v1');
        $this->fileUrl = env('YM_FILE_URL', 'https://file-test.frontnode.net/v1');
        $this->apiKey = env('YM_API_KEY', '4862bf6d-7b84-4dc4-921c-eeddfd6ec651');
        $this->apiSecret = env('YM_API_SECRET', 'z0TZjqK48mM0pTpxNlzeFcXuqiXTX6qu');
        $this->ymCrt = base_path('app/Libraries/YmSdk/').env('YM_ROOT_CRT','ym_root.crt');
        $this->ymMfPublic = base_path('app/Libraries/YmSdk/').env('YM_MF_PUBLIC','ym_mf_public.crt');
        $this->ymMfPrivateKey = base_path('app/Libraries/YmSdk/').env('YM_MF_PRIVATE','ym_mf_private.key');
        $this->ymMfPrivateKeyPassword = env('YM_MF_PRIVATE_PASSWORD', '123456');
    }

    function test()
    {
        print_r($this->realUrl);
        print_r("\n");
        print_r($this->fileUrl);
        print_r("\n");
        print_r($this->apiKey);
        print_r("\n");
        print_r($this->apiSecret);
        print_r("\n");
        print_r($this->ymCrt);
        print_r("\n");
        print_r($this->ymMfPublic);
        print_r("\n");
        print_r($this->ymMfPrivateKey);
        print_r("\n");
    }

    function getSig($method, $path, $params, $secret)
    {
        if (isset($params['sig'])) {
            unset($params['sig']);
        }
        $unified_string = $method . ":" . $path . ":";
        ksort($params);
        $params_kv = [];
        foreach ($params as $key => $value) {
            if ($value === null || (is_array($value) && $value == []) || (is_string($value) && trim($value) == '')) {
                continue;
            }
            $params_kv[] = $key . "=" . $value;
        }
        $params_str = implode("&", $params_kv);
        $unified_string .= $params_str;

        return $this->getSignature($unified_string, $secret);
    }

    function getSignature($str, $key)
    {
        $signature = "";

        if (function_exists('hash_hmac')) {
            $signature = base64_encode(hash_hmac("sha1", $str, $key, true));
        } else {
            $blocksize = 64;
            $hashfunc = 'sha1';
            if (strlen($key) > $blocksize) {
                $key = pack('H*', $hashfunc($key));
            }
            $key = str_pad($key, $blocksize, chr(0x00));
            $ipad = str_repeat(chr(0x36), $blocksize);
            $opad = str_repeat(chr(0x5c), $blocksize);
            $hmac = pack(
                'H*', $hashfunc(
                    ($key ^ $opad) . pack(
                        'H*', $hashfunc(
                            ($key ^ $ipad) . $str
                        )
                    )
                )
            );
            $signature = base64_encode($hmac);
        }

        return $signature;
    }

    static function rest_rpc($path, $params, $type, $isFile = false,$fileName = false)
    {
        if(!in_array($path, ['/product/getPoAdjustments', '/product/getPoDetail'])){
            Log::info("10000:yingmi rpc path=$path,type=$type, params =",$params);
        }

        $ym = new YmHelper();
        if($isFile){
            $host  = $ym->fileUrl;
        }else{
            $host  = $ym->realUrl;
        }
        $params['key'] = $ym->apiKey;
        $params['ts'] = date(DATE_ISO8601, time());
        $params['nonce'] = md5(time() . mt_rand(0, 1000));
        $params['sigVer'] = 1;
        $params['sig'] = $ym->getSig(strtoupper($type), $path, $params, $ym->apiSecret);
        $ch = curl_init();
        $url = sprintf("%s%s", $host, $path);
        if (strtoupper($type) == 'GET') {
            $str = '';
            foreach ($params as $key => $row) {
                if (is_array($row)) {
                    foreach ($row as $tmp => $tmp_value) {
                        if ($tmp_value != '' && $tmp_value != null) {
                            $str .= '&' . $key . '=' . $tmp_value;
                        }
                    }
                    unset($params[$key]);
                } elseif ($row == '' || $row == null) {
                    continue;
                }
            }
            $url = sprintf("%s?%s", $url, http_build_query($params));
            if (strstr($url, '?')) {
                $url .= $str;
            } else {
                $url .= '?' . substr($str, 1);
            }
        } else {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($params) {
                if (is_string($params)) {
                    $postData = $params;
                } else {
                    $postData = http_build_query($params);
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

            }
        }
        if($isFile){
            if($fileName === false ){
                $fileName = explode('/',$path);
                $fileName = $fileName[count($fileName)-1];
            }
            $file_path = base_path('storage/app/').$fileName;
            $fp_output = fopen($file_path, 'w');
            curl_setopt($ch, CURLOPT_FILE, $fp_output);
        }else{
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        curl_setopt($ch, CURLOPT_PORT, 443);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSLCERT, $ym->ymMfPublic);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, "PEM");
        curl_setopt($ch, CURLOPT_SSLKEY, $ym->ymMfPrivateKey);
        curl_setopt($ch, CURLOPT_SSLKEYTYPE, "PEM");
        // curl_setopt($ch, CURLOPT_CAINFO, $ym->ymCrt);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $rtn= curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $total_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);

        $dns_time = curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
        $connect_time = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $pre_trans_time = curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME);
        $first_byte_time = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);

        if (!$response) {
            Log::error('10000:yingmi rpc raw result', [
                'result' => $response,
                'rtn' => $rtn,
                'rc' => curl_errno($ch),
                'strerror' => curl_error($ch)
            ]);
        } else {
            if(true || !in_array($path, ['/product/getPoAdjustments', '/product/getPoDetail','/product/getPoList'])){
                Log::info("10000:yingmi rpc raw request=$path and exec_time=$total_time and dns_time==$dns_time and connect_time=$connect_time and pre_trans_time=$pre_trans_time and first_byte_time=$first_byte_time and result =$response and rtn=$rtn");
            }
        }
        curl_close($ch);

        if($isFile){
            fclose($fp_output);
            if($rtn == '200'){
                return $file_path;
            }else{
                return false;
            }
        }

        if($rtn == '200'){
            try {
                $json = json_decode($response, true);
                if ($json == false) {
                    return ['code'=>20000, 'result'=>$response];
                }
                return ['code'=>20000, 'result'=>$json];
            } catch (\Exception $e) {
                $msg = 'query error 1: url-->'.$url.'<--params-->'.json_encode($params);
                Log::warning('10000:yingmi exception error 1:'.$e.$msg);
                return ['code'=>-1, 'msg'=>$msg];
            }
        }else{
            try {
                $json = json_decode($response, true);
                $msg = 'query error 2: url-->'.$url.'<--params-->'.json_encode($params);
                Log::warning('10000:yingmi exception error 2:'.$response.' and '.$msg);
                if ($json == false) {
                    return ['code'=>-1,'result'=>$response];
                }
                return $json;
            } catch (\Exception $e) {
                $msg = 'query error 3: url-->'.$url.'<--params-->'.json_encode($params);
                Log::warning('10000:yingmi exception error 3:'.$e.$msg);
                return ['code'=>-1,'msg'=>$msg];
            }
        }
        $msg = 'query error 4: url-->'.$url.'<--params-->'.json_encode($params);
        Log::warning('10000:yingmi exception error 4:'.$msg);
        return ['code'=>-1,'msg'=>$msg];
    }

    static function rest_rpc_file($path, $params, $type,$path_md5=false){
        $file_path = YmHelper::rest_rpc($path,$params,$type,true);
        $fileName = explode('/',$path);
        $fileName = $fileName[count($fileName)-1];
        if($file_path){
            if($path_md5 === false){
                $path_md5 = $path.'.md5';
            }
            $md5_file_path = YmHelper::rest_rpc($path_md5,$params,$type,true,$fileName.'.md5');
            if($md5_file_path){
                if(strtolower(md5_file($file_path)) == file_get_contents($md5_file_path)){
                    if(Storage::exists($fileName.'.md5')){
                        // Storage::delete($fileName.'.md5');
                    }
                    return $file_path;
                }
            }
        }
        if(Storage::exists($fileName)){
            // Storage::delete($fileName);
        }
        if(Storage::exists($fileName.'.md5')){
            // Storage::delete($fileName.'.md5');
        }
        return false;
    }

    //获取盈米用户id
    public static function getYmId($uid){
        $ymid = YingmiAccount::where('ya_uid',$uid)->first();
        if($ymid){
            return $ymid['ya_account_id'];
        }
        return false;
    }

    //获取一个交易id
    public static function getTradeId(){
        //填写进去啊坤哥
        $id = GlobalId::next(400000000);
        return $id;
    }

    //判断交易密码
    public static function checkTrade($uid, $password){
        return AccountService::verifyPassword($uid, $password);
    }

    public static function formatBankLimit($limit)
    {
        if($limit >= 10000){
            $limit = round((float)($limit)/10000.00,2).'万元';
        }else{
            $limit = round($limit).'元';
        }

        return $limit;
    }

    public static function formatFundCode($code)
    {
        return sprintf('%06d', $code);
    }

}
