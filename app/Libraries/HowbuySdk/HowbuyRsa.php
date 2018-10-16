<?php namespace App\Libraries\HowbuySdk;

use Log;

class HowbuyRsa{
    //好买地址
    var $url = null;
    //商户id
    var $corpId = null;
    //好买公钥
    var $howbuyPub= null;
    //魔方私钥
    var $mofangPri = null;
    //魔方私钥密码
    var $mofangPwd = null;

    function __construct() {
        $this->url= env('HOWBUY_DOMAIN', 'http://101.231.204.242:8483/licaimofang/coop/service.htm');
        $this->corpId = env('HOWBUY_CORPID', '000010');
        $this->howbuyPub= env('HOWBUY_PUB', 'howbuy_public.crt');
        $this->mofangPri= env('MOFANG_PRI', 'merchant_private.pfx');
        $this->mofangPwd= env('MOFANG_PWD', 'merchanttest');
    }
	/**
	 * 获取私钥
	 *
	 * @param filePath
	 * @param passwd
	 * @return 私钥
	 */
    function loadPrivateKey($filePath,$passwd){
        $key = $this->readPfx2Cert($filePath,$passwd);
        return $key;
    }

	/**
	 * 读取pfx文件
	 * @param file
	 * @param passwd
	 * @return 私钥
	 */
    function readPfx2Cert($filePath,$passwd){
        try{
            openssl_pkcs12_read(file_get_contents($filePath), $certs, $passwd);
            $prikeyid = $certs['pkey'];
            return $prikeyid;
        }catch(\Exception $e){
            print $e->getMessage();
            return false;
        }

    }
    /**
     * 加载指定路径证书文件，获取公钥
     *
     * @param keyPath
     *            证书文件路径
     * @return 公钥
     */
    function loadPublicKey($filePath){
        $key = $this->readX509($filePath);
        return $key;
    }

	/**
	 * 读取X509证书
	 *
	 * @param file
	 * @return 公钥
	 */
    function readX509($filePath){
        $pubkey = openssl_pkey_get_public(file_get_contents($filePath));
        return $pubkey;
    }

	/**
	 * 加密
	 *
	 * @param 数据
	 * @param 公钥
	 * @return 加密数据
	 */
    function encrypt_data($data,$public_key){
        $MAX_DECRYPT_BLOCK = 245;
        $inputLen = strlen($data);
        $offset = 0;
        $str = '';
        $i = 0;
        while ($inputLen - $offset > 0) {
            if ($inputLen - $offset > $MAX_DECRYPT_BLOCK) {
                $tmp = substr($data,$offset,$MAX_DECRYPT_BLOCK);
                openssl_public_encrypt($tmp,$encrypted,$public_key,OPENSSL_PKCS1_PADDING);
                $str .= $encrypted;
            } else {
                $tmp = substr($data,$offset,$inputLen - $offset);
                openssl_public_encrypt($tmp,$encrypted,$public_key,OPENSSL_PKCS1_PADDING);
                $str .= $encrypted;
            }
            $i++;
            $offset = $i * $MAX_DECRYPT_BLOCK;
        }
        return $str;
    }

	/**
	 * 解密
	 *
	 * @param 数据
	 * @param 私钥
	 * @return 解密数据
	 */
    function decrypt_data($data,$priv_key){
        $MAX_DECRYPT_BLOCK = 256;
        $inputLen = strlen($data);
        $offset = 0;
        $str = '';
        $i = 0;
        while ($inputLen - $offset > 0) {
            if ($inputLen - $offset > $MAX_DECRYPT_BLOCK) {
                $tmp = substr($data,$offset,$MAX_DECRYPT_BLOCK);
                openssl_private_decrypt($tmp,$encrypted,$priv_key,OPENSSL_PKCS1_PADDING);
                $str .= $encrypted;
            } else {
                $tmp = substr($data,$offset,$inputLen - $offset);
                openssl_private_decrypt($tmp,$encrypted,$priv_key,OPENSSL_PKCS1_PADDING);
                $str .= $encrypted;
            }
            $i++;
            $offset = $i * $MAX_DECRYPT_BLOCK;
        }
        return $str;
    }

	/**
	 * 签名
	 *
	 * @param 数据
	 * @param 私钥
	 * @return 签名数据
	 */
    function sign_data($data,$priv_key){
        $data = hash('sha512', $data);
        openssl_sign($data, $signature, $priv_key,OPENSSL_ALGO_SHA1);
        return $signature;
    }

	/**
	 * 验签
	 *
	 * @param 数据
	 * @param 公钥
	 * @return 1 or 0 or -1
	 */
    function verifySignature($data,$public_key,$sign){
        $data = hash('sha512',$data);
        $is_verify = openssl_verify($data,$sign,$public_key,OPENSSL_ALGO_SHA1);
        return $is_verify;
    }

	/**
	 * 判断是否合法json
	 *
	 * @param 数据
	 * @return true o false
     */
    function is_json($data){
        $js = json_decode($data,true);
        if(is_null($js))return false;
        if(json_last_error() == JSON_ERROR_NONE){
            return $js;
        }else{
            return false;
        }
    }

	/**
	 * 请求好买数据
	 *
	 * @param 数据
	 * @return array('code'=>'','msg'=>'','data'=>'')
     */
    static function hpost($data){
        if($data=='')return [];
        $howbuy = new HowbuyRsa();
        $howbuyPublicKey = $howbuy->loadPublicKey(dirname(__FILE__)."/".$howbuy->howbuyPub);
        $merchantPrivateKey = $howbuy->loadPrivateKey(dirname(__FILE__)."/".$howbuy->mofangPri, $howbuy->mofangPwd);
        $js = json_encode($data);
        $encrypt_data = $howbuy->encrypt_data($js,$howbuyPublicKey);
        $sign = $howbuy->sign_data($encrypt_data,$merchantPrivateKey);
        $tmp = array("corpId"=>$howbuy->corpId,"data"=>base64_encode($encrypt_data),"sign"=>base64_encode($sign));
        $howbuyContent = HttpClient::quickPost($howbuy->url,$tmp);
        if(!$howbuyContent){
            return array('code'=>-1,'msg'=>'请求通讯失败','data'=>'');
        }
        $howbuyResult = $howbuy->is_json($howbuyContent);
        if($howbuyResult&&$howbuyResult['contentType']==2&&isset($howbuyResult['content'])&&isset($howbuyResult['content']['data'])&&isset($howbuyResult['content']['sign'])){
            $howbuyData = base64_decode($howbuyResult['content']['data']);
            $howbuySign = base64_decode($howbuyResult['content']['sign']);
            $verify = $howbuy->verifySignature($howbuyData,$howbuyPublicKey,$howbuySign);
            if($verify){
                $js_data = $howbuy->decrypt_data($howbuyData,$merchantPrivateKey);
                $result = $howbuy->is_json($js_data);
                if(is_array($result)){
                    if(isset($result['status']) && isset($result['data'])){
                        $result = $result['data'];
                        $result = $howbuy->is_json($result);
                    }
                }
                //
                // 请保留如下的log信息, 以便和好买对账
                //
                if ($data['opType'] == 'HB301' || $data['opType'] == 'HB321') {
                    Log::debug(sprintf('howbuy raw data:[ "json" => %s, "req" => %s, "raw_data" => %s, "raw_sign" => %s]', $js_data, $js, $howbuyResult['content']['data'], $howbuyResult['content']['sign']));
                }
                return array('code'=>1,'msg'=>'success','data'=>$result);
            }else{
                return array('code'=>-1,'msg'=>'验签失败','data'=>'');
            }
        }elseif($howbuyResult&&isset($howbuyResult['data'])&&isset($howbuyResult['sign'])){
            $howbuyData = base64_decode($howbuyResult['data']);
            $howbuySign = base64_decode($howbuyResult['sign']);
            $verify = $howbuy->verifySignature($howbuyData,$howbuyPublicKey,$howbuySign);
            if($verify){
                $js_data = $howbuy->decrypt_data($howbuyData,$merchantPrivateKey);
                $result = $howbuy->is_json($js_data);
                if(is_array($result)){
                    if(isset($result['status']) && isset($result['data'])){
                        $result = $result['data'];
                        $result = $howbuy->is_json($result);
                    }
                }
                //
                // 请保留如下的log信息, 以便和好买对账
                //
                if ($data['opType'] == 'HB301' || $data['opType'] == 'HB321') {
                    Log::debug(sprintf('howbuy raw data:[ "json" => %s, "req" => %s, "raw_data" => %s, "raw_sign" => %s]', $js_data, $js, $howbuyResult['data'], $howbuyResult['sign']));
                }
                return array('code'=>1,'msg'=>'success','data'=>$result);
            }else{
                return array('code'=>-1,'msg'=>'验签失败','data'=>'');
            }
        }else{
            return array('code'=>-1,'msg'=>'返回数据异常','data'=>trim(strip_tags($howbuyContent)));
        }
    }

	/**
	 * 数据加密签名
	 *
	 * @param 数据
	 * @return array('postData'=>array('data'=>'','sign'=>'','corpId'=>''),'url'=>'');
     */
    static function hdata($data){
        if($data=='')return [];
        $howbuy = new HowbuyRsa();
        $howbuyPublicKey = $howbuy->loadPublicKey(dirname(__FILE__)."/".$howbuy->howbuyPub);
        $merchantPrivateKey = $howbuy->loadPrivateKey(dirname(__FILE__)."/".$howbuy->mofangPri, $howbuy->mofangPwd);
        $js = json_encode($data);
        $encrypt_data = $howbuy->encrypt_data($js,$howbuyPublicKey);
        $sign = $howbuy->sign_data($encrypt_data,$merchantPrivateKey);
        $tmp = array('url'=>$howbuy->url,'data'=>array("corpId"=>$howbuy->corpId,"data"=>base64_encode($encrypt_data),"sign"=>base64_encode($sign)));
        return $tmp;
    }

	/**
	 * 数据解密
	 *
	 * @param 数据
	 * @return array('postData'=>array('data'=>'','sign'=>'','corpId'=>''),'url'=>'');
     */
    static function hdecrypt($data,$sign){
        $howbuy = new HowbuyRsa();
        $howbuyPublicKey = $howbuy->loadPublicKey(dirname(__FILE__)."/".$howbuy->howbuyPub);
        $merchantPrivateKey = $howbuy->loadPrivateKey(dirname(__FILE__)."/".$howbuy->mofangPri, $howbuy->mofangPwd);
        $howbuyData = base64_decode($data);
        $howbuySign = base64_decode($sign);
        $verify = $howbuy->verifySignature($howbuyData,$howbuyPublicKey,$howbuySign);
        if($verify){
            $js_data = $howbuy->decrypt_data($howbuyData,$merchantPrivateKey);
            $result = $howbuy->is_json($js_data);
            if(is_array($result)){
                if(isset($result['status']) && isset($result['data'])){
                    $result = $result['data'];
                    $result = $howbuy->is_json($result);
                }
            }
            return array('code'=>1,'msg'=>'success','data'=>$result);
        }else{
            return array('code'=>-1,'msg'=>'验签失败','data'=>'');
        }
    }


}
/**
   $data = array();
   $data["corpCustNo"] = "000010";
   $data["corpCustIP"] = "114.242.248.51";
   $data["requestTime"] = "2015-06-17 17:47:01";
   $data["applyId"] = "123233211";

   $data["opType"] = "HB106";
   //$data["custName"] = "正常";
   //$data["idNo"] = "123456789123456789";
   //$data["mobile"] = "12546387619";
   print_r(HowbuyRsa::hpost($data));
**/
?>
