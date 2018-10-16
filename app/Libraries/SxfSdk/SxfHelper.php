<?php namespace App\Libraries\SxfSdk;
use App\GlobalId;
use App\TppBankList;
use App\TppList;
class SxfHelper
{

    /**
     * PHP DES 加密程式
     *
     * @param $key 密鑰（八個字元內）
     * @param $encrypt 要加密的明文
     * @return string 密文
     */
    public static function encrypt ($key, $encrypt)
    {
        // 根據 PKCS#7 RFC 5652 Cryptographic Message Syntax (CMS) 修正 Message 加入 Padding
        $block = mcrypt_get_block_size(MCRYPT_DES, MCRYPT_MODE_ECB);
        $pad = $block - (strlen($encrypt) % $block);
        $encrypt .= str_repeat(chr($pad), $pad);

        // 不需要設定 IV 進行加密
        $passcrypt = mcrypt_encrypt(MCRYPT_DES, $key, $encrypt, MCRYPT_MODE_ECB);
        return base64_encode($passcrypt);
    }

    /**
     * PHP DES 解密程式
     *
     * @param $key 密鑰（八個字元內）
     * @param $decrypt 要解密的密文
     * @return string 明文
     */
    public static function decrypt ($key, $decrypt)
    {
        // 不需要設定 IV
        $str = mcrypt_decrypt(MCRYPT_DES, $key, base64_decode($decrypt), MCRYPT_MODE_ECB);

        // 根據 PKCS#7 RFC 5652 Cryptographic Message Syntax (CMS) 修正 Message 移除 Padding
        $pad = ord($str[strlen($str) - 1]);
        return substr($str, 0, strlen($str) - $pad);
    }

    public static function httpRequestPost($url, $param)
    {
        $curl = curl_init(SxfHelper::absoluteUrl($url));
        curl_setopt($curl, CURLOPT_HEADER, 0); // 过滤HTTP头
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json;charset=utf-8'
        ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);// 显示输出结果
        curl_setopt($curl, CURLOPT_POST, true); // post传输数据
        curl_setopt($curl, CURLOPT_POSTFIELDS, $param);// post传输数据
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);//SSL证书认证
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);//2严格认证,0不认证
        $responseText = curl_exec($curl);
        if (curl_errno($curl) || $responseText === false) {
            return false;
        }

        curl_close($curl);

        //$data = json_decode($responseText, true);

        if ($responseText === null) {

            return false;
        }

        return $responseText;
    }


    /**
     * @param $reqData
     * @param $pi_key
     * 签名
     */
    public static function reqsign($signData, $pi_key)
    {
        openssl_sign($signData, $sign, $pi_key, OPENSSL_ALGO_SHA1);

        return base64_encode($sign);
    }



    function __construct() {
        $this->name = "随行付";
        $this->clientId= env('SXF_CORPID', '600000000001044');
        $this->sxfPub= env('SXF_PUB', 'public.pem');
        $this->mofangPri= env('SXF_PRI', 'private.pem');
        $this->mofangPwd= env('SXF_PWD', '12345678');
    }

    public function getBalance(){
        $tranCd = 'DF1001';//交易码
        $url = env('SXF_BALANCE_URL','http://106.37.197.53:38080/paygateway/queryAcc.do');
        $data = $this->packing($url,$tranCd,['tranCd'=>$tranCd]);
        if($data){
            return $data['bl'];
        }
    }

    public function getBankName(){
        $tpp = TppList::where('tl_payment_name',$this->name)->first();
        $bank_list = TppBankList::where('tb_pay_id',$tpp['id'])->get();
        $list = [];
        foreach($bank_list as $row){
            $list[$row['tb_bank_code']] = $row['tb_self_name'];
        }
        return $list;
    }

    //list = ['amount'=>,'bank'=>,'bank_name'=>,'name'=>,'card'=>,'uid'=>,'order_id'=>];
    public function pay($list){
        $clientId = $this->clientId;//商户编号
        $tranCd = 'DF1003';//交易码
        $payTyp         = '01';//代付类型
        $totalPayCount  = 0;//总笔数
        $totalPayAmt    = 0;//总金额
        $payItems = [];
        $n = 1;
        $banks = $this->getBankName();
        $new_list = [];
        $result = [];
        foreach($list as $row){
            $totalPayCount++;
            $totalPayAmt += $row['amount'];
            $payItemId = GlobalId::next(4000000000000000000);
            $new_list[$payItemId] = $row;
            $row['no'] = $payItemId;
            $row['status'] = true;
            $row['number'] = 0;
            $row['trade_time'] = date('Y-m-d H:i:s');
            $result[] = $row;
            $payItems[] = array(
                'payItemId'     => $payItemId,
                'seqNO'         => $n,
                'payAmt'        => $row['amount'],
                'actNm'         => $row['name'],
                'actNo'         => $row['card'],
                'actTyp'        => '01',
                'bnkCd'         => $row['bank'],
                'bnkNm'         => $banks[$row['bank']],
                'smsFlg'        => 0,
            );
            $n++;
        }
        $reqData = array(
            'clientId'          => $clientId,
            'payTyp'            => $payTyp,
            'totalPayCount'     => $totalPayCount,
            'totalPayAmt'       => $totalPayAmt,
            'payItems'          => $payItems
        );
        $url = env('SXF_PAY_URL','http://106.37.197.53:38080/paygateway/pay.do');
        $res = $this->packing($url,$tranCd,$reqData);
        if($res){
            $result = [];
            foreach($res['payResultList'] as $row){
                if($row['resCd'] == '00'){
                    $status = true;
                }else{
                    $status = false;
                }
                $new = $new_list[$row['payItemId']];
                $new['status'] = $status;
                $new['no'] = $row['payItemId'];
                $new['number'] = $res['reqId'];
                $new['trade_time'] = $res['trade_time'];
                $result[] = $new;
            }
        }
        return $result;
    }

    public function getPayResult($payItemId){
        $tranCd = 'DF1004';//交易码
        $url = env('SXF_PAYRESULT_URL','http://106.37.197.53:38080/paygateway/queryPayResult.do');
        $res = $this->packing($url,$tranCd,['payItemId'=>$payItemId]);
        if($res){
            $status = 4;
            if($res['tranSts'] == '01'){
                $status = 0;
            }elseif($res['tranSts'] == '02'){
                $status = 2;
            }elseif($res['tranSts'] == '03'){
                $status = 3;
            }elseif($res['tranSts'] == '00'){
                $status = 1;
            }
            return ['status'=>$status,'msg'=>$res['tranMsg']];
        }
        return ['status'=>4,'msg'=>'待人工确认'];
    }

    public function packing($url,$tranCd,$originData){
        $reqData = json_encode($originData);
        // 读取公钥
        $pu_key = file_get_contents(dirname(__FILE__)."/".$this->sxfPub);
        // 读取私钥
        $pi_key = file_get_contents(dirname(__FILE__)."/".$this->mofangPri);
        // 数据加密

        $key = $this->mofangPwd;

        $encodeData = SxfHelper::encrypt($key,$reqData);
        // 签名加密
        $sign = SxfHelper::reqsign($encodeData,$pi_key);
        $reqId = GlobalId::next(400000000);
        $clientId       = $this->clientId;//商户编号
        $version        = '1.0';//版本号
        $data= array(
            'clientId'  => $clientId,
            'reqId'     => $reqId,
            'tranCd'    => $tranCd,
            'version'   => $version,
            'reqData'   => $encodeData,
            'sign'      => $sign
        );

        $data = json_encode($data);
        $data = stripslashes($data);

        $pageContents = SxfHelper::httpRequestPost($url,$data);
        $de_json = json_decode($pageContents,TRUE);

        $clientId    =  $de_json['clientId'];
        $reqId       =  $de_json['reqId'];
        $resCode     =  $de_json['resCode'];
        $resData     =  $de_json['resData'];
        $resMsg      =  $de_json['resMsg'];
        $serverId    =  $de_json['serverId'];
        $sign        =  $de_json['sign'];
        $tranCd      =  $de_json['tranCd'];
        $version     =  $de_json['version'];
        if('000000'==$resCode){
            //验签
            $sign_result= openssl_verify($resData, base64_decode($sign), $pu_key);
            if($sign_result=='1'){
                // 解密业务数据
                $de_data = SxfHelper::decrypt($key,$resData);
                $de_data = json_decode($de_data,true);
                $de_data['reqId'] = $reqId;
                $de_data['trade_time'] = date('Y-m-d H:i:s');
                return $de_data;
            }
        }elseif('100402'==$resCode){
            return ['payItemId'=>$originData['payItemId'],'tranSts'=>'02','tranMsg'=>'请求失败'];
        }
        return false;
    }
    
    public static function absoluteUrl($url, $param = null)
    {
        if ($param !== null) {
            $parse = parse_url($url);
            $port = '';
            if (($parse['scheme'] == 'http') && (empty($parse['port']) || $parse['port'] == 80)) {
                $port = '';
            } else {
                $port = $parse['port'];
            }
            $url = $parse['scheme'] . '//' . $parse['host'] . $port . $parse['path'];

            if (!empty($parse['query'])) {
                parse_str($parse['query'], $output);
                $param = array_merge($output, $param);
            }
            $url .= '?' . http_build_query($param);
        }

        return $url;
    }

}
?>
