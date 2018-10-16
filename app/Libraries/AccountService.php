<?php
namespace App\Libraries;

use App\YingmiAccount;
use App\YingmiPaymentMethod;
use DB;
use Log;

use App\Libraries\JobStatus;
use App\Libraries\Rpc;

use App\YingmiPaymentProviders;

use Carbon\Carbon;
use Artisan;
use Hash;

class AccountService
{
    /**
     * 获取用户脱敏手机号
     * @params:
     *   uids
     */
    public static function getYmAccountInfo($uid)
    {
        $info = null;

        try {
            $data = [
                'user_id' => $uid,
            ];

            $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
            $result = Rpc::get($host, '/20180322/yingmi/account/info/get', $data);

            if (isset($result['code']) && $result['code'] == 20000) { // success 合并内容到method
                $info = $result['result'];
            }
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
            Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." get passport user info failed");
        }

        return $info;
    }

    public static function openYmAccount($uid, $accountId, $name, $phone, $id_no, $bank, $card, $paymentMethodId)
    {
        // 将身份信息存入trade
        $model = YingmiAccount::find($uid);
        if (!$model) {
            $model = new YingmiAccount();
            $model->ya_uid = $uid;
            $model->ya_account_id = $accountId;
            $model->ya_name = static::formatName($name);  // todo anonymous
            $model->ya_identity_type = 0;
            $model->ya_identity_no = static::formatIdNo($id_no);  // todo anonymous
            $model->ya_phone = static::formatPhone($phone);  // todo anonymous
            $model->ya_active = 1;
            $model->ya_risk_grade = 10;
            $model->ya_origin = 3;
            $model->save();
        }

        $model = YingmiPaymentMethod::firstOrNew(['yp_uid'=>$uid,'yp_account_id'=>$accountId, 'yp_payment_method_id'=>$paymentMethodId]);
        $model->yp_uid = $uid;
        $model->yp_account_id = $accountId;
        $model->yp_payment_method_id = $paymentMethodId;
        $model->yp_phone = static::formatPhone($phone); // todo anonymous
        $model->yp_payment_no = static::formatCard($card); // todo anonymous
        $model->yp_payment_type = $bank;
        $model->yp_enabled = 1; //融合版本 新用户首次绑卡时，默认将该卡设为主卡
        $model->save();

        Artisan::call('ts:populate_pay_method', ['--uid'=>$uid]);

        $provider = YingmiPaymentProviders::where('yp_payment_type', $bank)->first();
        if (!$provider) {
            $provider = (object) ['yp_name' => '未知'];
        }

        // 将身份敏感信息存入passport
        try {
            $data = [
                'account_id' =>  $accountId,
                'name' => $name,
                'phone' => $phone,
                'id_no' => $id_no,
                'bank' => $bank,
                'bank_name' => $provider->yp_name,
                'card' => $card,
                'payment_id' => $paymentMethodId,
            ];
            $params = [
                'uid' => $uid,
            ];
            $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
            $result = Rpc::post($host, '/20180322/yingmi/account/open', $data, $params);

            if (isset($result['code']) && $result['code'] == 20000) { // success
                return ['code'=>20000, 'message'=>'开户成功'];
            } else {
                if (isset($result['code']) && isset($result['message'])) {
                    return $result;
                } else {
                    return ['code'=>20001, 'message'=>'开户绑卡失败，请联系客服处理'];
                }
            }
        } catch (\Exception $e) {
            Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed $uid");
        }

        return ['code'=>20001, 'message'=>'开户绑卡失败，请联系客服处理'];
    }

    public static function setYmPassword($uid, $passwd)
    {
        $ym_account = YingmiAccount::find($uid);
        if(!$ym_account){
            return ['code'=>20001, 'message'=>'用户未开户'];
        }

        if($ym_account->ya_password == null){
            $ym_account->ya_password = Hash::make($passwd); //todo do not save
            $ym_account->save(); // todo do not save

            // 将用户的交易密码存储在passport中
            try {
                $data = [
                    'new' => $passwd,
                ];
                $params = [
                    'uid' => $uid,
                ];
                $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
                $result = Rpc::post($host, '/20180322/yingmi/account/password/set', $data, $params);
                // Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed", [$request->all(), $ym_account->ya_password]);
                if (isset($result['code']) && $result['code'] == 20000) { // success
                    return ['code' => 20000, 'message' => '交易密码设置成功'];
                } else {
                    if (isset($result['code']) && isset($result['message'])) {
                        return $result;
                    } else {
                        return ['code' => 20001, 'message' => '交易密码设置出现问题，请联系客服处理'];
                    }
                }
            } catch (\Exception $e) {
              // Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed", $request->all());
                return ['code'=>20001, 'message'=>'交易密码设置出现问题，请联系客服处理'];
            }
        }else{
            return ['code'=>20001, 'message'=>'交易密码已经设定，如需修改请到密码修改页'];
        }
    }

    public static function resetYmPassword($uid, $old, $new)
    {
        $ym_account = YingmiAccount::where('ya_uid', $uid)->first();
        if(!$ym_account){
            return ['code'=>20001, 'message'=>'用户未开户'];
        }

        if($ym_account->ya_password == null){
            return ['code'=>20001, 'message'=>'未设定交易密码，请先设定交易密码'];
        }


        $ret = ['code'=>20001, 'message'=>'开户绑卡失败，请联系客服处理'];
        try {
            $data = [
                'old' => $old,
                'new' => $new,
            ];
            $params = [
                'uid' => $uid,
            ];
            $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
            $result = Rpc::post($host, '/20180322/yingmi/account/password/set', $data, $params);

            if (isset($result['code']) && $result['code'] == 20000) { // success
                $ret = ['code'=>20000, 'message'=>'密码重设成功'];
            } else {
                if (isset($result['code']) && isset($result['message'])) {
                    $ret = $result;
                } else {
                    $ret = ['code'=>20001, 'message'=>'密码重设失败，请联系客服处理'];
                }
            }
        } catch (\Exception $e) {
            // Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed", $request->all());
            $ret = ['code'=>20001, 'message'=>'开户绑卡失败，请联系客服处理'];
        }

        if ($ret['code'] == 20000) {
            $ym_account->ya_password = Hash::make($new);
            $ym_account->save();
        }

        return $ret;
    }

    public static function verifyPassword($uid, $passwd)
    {
        try {
            $data = [
                'password' => $passwd
            ];
            $params = [
                'uid' => $uid,
            ];
            $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
            $result = Rpc::post($host, '/20180322/yingmi/account/password/verify', $data, $params);
            if (isset($result['code']) && $result['code'] == 20000) { // success
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed $uid");
            return false;
        }
    }


    public static function forgetYmPasswordPrepare($uid, $name, $id_no)
    {
        try {
            $data = [
                'name' => $name,
                'id_no' => $id_no,
            ];
            $params = [
                'uid' => $uid,
            ];
            $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
            $result = Rpc::post($host, '/20180322/yingmi/account/password/prepare', $data, $params);

            if (isset($result['code']) && $result['code'] == 20000) { // success
                return $result;
            } else {
                if (isset($result['code']) && isset($result['message'])) {
                    return $result;
                } else {
                    return ['code'=>20001, 'message'=>'发送密码重设验证码失败，请联系客服处理'];
                }
            }
        } catch (\Exception $e) {
            Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed $uid");
        }
        return ['code'=>20001, 'message'=>'发送重设密码验证码失败，请联系客服处理'];
    }

    public static function forgetYmPasswordConfirm($uid, $verifyCode, $passwd)
    {

        try {
            $data = [
                'code' => $verifyCode,
                'new' => $passwd,
            ];
            $params = [
                'uid' => $uid,
            ];
            $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
            $result = Rpc::post($host, '/20180322/yingmi/account/password/forget', $data, $params);
            if (isset($result['code']) && $result['code'] == 20000) { // success
                return $result;
            } else {
                if (isset($result['code']) && isset($result['message'])) {
                    return $result;
                } else {
                    return ['code'=>20001, 'message'=>'重设密码失败，请联系客服处理'];
                }
            }
        } catch (\Exception $e) {
            Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed $uid");
        }
        return ['code'=>20001, 'message'=>'重设密码失败，请联系客服处理'];
    }

    public static function bindYmBankCard($uid, $accountId, $phone, $bank, $card, $paymentMethodId)
    {
        // 将身份信息存入trade
        $model = YingmiPaymentMethod::firstOrNew(['yp_uid'=>$uid,'yp_account_id'=>$accountId, 'yp_payment_method_id'=>$paymentMethodId]);
        $model->yp_uid = $uid;
        $model->yp_account_id = $accountId;
        $model->yp_payment_method_id = $paymentMethodId;
        $model->yp_phone = static::formatPhone($phone); // todo anonymous
        $model->yp_payment_no = static::formatCard($card); // todo anonymous
        $model->yp_payment_type = $bank;
        $model->yp_enabled = 1; //融合版本 新用户首次绑卡时，默认将该卡设为主卡
        $model->save();

        Artisan::call('ts:populate_pay_method', ['--uid'=>$uid]);

        $provider = YingmiPaymentProviders::where('yp_payment_type', $bank)->first();
        if (!$provider) {
            $provider = (object) ['yp_name' => '未知'];
        }

        // 将身份敏感信息存入passport
        try {
            $data = [
                'account_id' =>  $accountId,
                'phone' => $phone,
                'bank' => $bank,
                'bank_name' => $provider->yp_name,
                'card' => $card,
                'payment_id' => $paymentMethodId,
            ];
            $params = [
                'uid' => $uid,
            ];
            $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
            $result = Rpc::post($host, '/20180322/yingmi/payment/bind', $data, $params);

            if (isset($result['code']) && $result['code'] == 20000) { // success
                return ['code'=>20000, 'message'=>'绑卡成功'];
            } else {
                if (isset($result['code']) && isset($result['message'])) {
                    return $result;
                } else {
                    return ['code'=>20001, 'message'=>'绑卡失败，请联系客服处理'];
                }
            }
        } catch (\Exception $e) {
            Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed $uid");
        }

        return ['code'=>20001, 'message'=>'绑卡失败，请联系客服处理'];
    }

    public static function unbindYmBankCard($uid, $payId)
    {
            $method = YingmiPaymentMethod::where('yp_uid', $uid)
                //->where('yp_account_id', $ym_account->ya_account_id)
                ->where('yp_payment_method_id', $payId)
                ->first();
            if($method){
                $method->delete();
            }

            // todo 添加RPC更新secret 传入
            // uid
            // payment_method_id
            // 银行卡尾号后4位 因为有用户向客服申请更换银行卡的情况 这个时候YingmiPaymentMethod中对应的记录会删除
            // 去secret中ym_payment_method表中 uid paymethod_id 银行卡后四位相同的最新的一张卡（id最大的一张），置ym_delete=1
            //

            // 将身份敏感信息存入passport
            $unbind_tag = false; // 是否调用成功
            try {
                $data = [
                    'paymethod_id' => $payId,
                ];
                $params = [
                    'uid' => $uid,
                ];
                $host = env('SECRET_DOMAIN', 'http://passport.licaimofang.com');
                $result = Rpc::post($host, '/20180322/yingmi/payment/unbind', $data, $params);

                if (isset($result['code']) && $result['code'] == 20000) { // success
                    $unbind_tag = true;
                }
            } catch (\Exception $e) {
                Log::info("[XXNMH:PYY:$uid] ".__FUNCTION__." failed $uid");
            }

            if (!$unbind_tag) {
                // todo send sms alert to admin
            }

            Artisan::call('ts:populate_pay_method', ['--uid'=>$uid]);

            return ['code'=>20000, 'message'=>'银行卡解绑成功'];
    }

    public static function formatPhone($phone)
    {
        $result = substr($phone, 0, 3) . '****' . substr($phone, -4);

        return $result;
    }

    public static function formatName($name)
    {
        // $result = mb_substr($name, 0, 1);
        // $len = mb_strlen($name);
        // for ($i=1; $i<$len; $i++) {
        //     $result .= '*';
        // }
        // return $result;
        return $name;
    }

    public static function formatIdNo($id_no)
    {
        $result = '';
        $last4 = substr($id_no, -4);
        $len = strlen($id_no);
        for ($i=0; $i<$len-4; $i++) {
            $result .= '*';
        }

        return $result.$last4;
    }

    public static function formatCard($card)
    {
        $result = '';

        $first4 = substr($card, 0, 4);
        $last4 = substr($card, -4);
        $len = strlen($card);

        $result = $first4;

        for ($i=0; $i<$len-8; $i++) {
            $result .= '*';
        }

        $result .= $last4;

        return $result;
    }


}
