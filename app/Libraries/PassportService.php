<?php
namespace App\Libraries;

use DB;
use Log;

use App\Libraries\JobStatus;
use App\Libraries\Rpc;

use Carbon\Carbon;

class PassportService
{
    /**
     * 获取用户脱敏手机号
     * @params:
     *   uids
     */
    public static function getSafeUserInfo($uid)
    {
        $uids = [$uid];
        $infos = static::getSafeUserInfos($uids);
        if ($infos->has($uid)) {
            return $infos->get($uid);
        }

        return null;
    }

    /**
     * 获取脱敏的用户信息
     * @params:
     *   uids
     */
    public static function getSafeUserInfos($uids, $asArray = false)
    {
        $data = [
            'uids' => $uids,
        ];

        $host = env('SECRET_DOMAIN', 'http://secret.licaimofang.com');
        $path = '/20180322/passport/get/user/phone';
        $result = Rpc::get($host, $path, $data);

        $infos = [];
        if (isset($result['result'])) {
            $infos = $result['result'];
        }

        if ($asArray) {
            return $infos;
        } else {
            return collect($infos);
        }
    }


    /**
     * 获取用户信息
     * @params:
     *   uids
     */
    public static function getUserInfos($uids, $asArray = false)
    {
        // $data = [
        //     'business' => $bid,
        //     'uids' => $uids,
        //     'message' => $message,
        //     'attrs' => $attrs,
        // ];

        // $host = env('DOMAIN_SMS', 'http://kun.cms.mofanglicai.com.cn');
        // $result = Rpc::post($host, '/20160518/sms/content', $data);
        // if ($result['code'] == 20000) {
        //     return [true, 'Queued', $result['result']];
        // } else {
        //     return [false, $result['message'], $result['result']];
        // }

        if ($asArray) {
            return [
                '1000000001' => (object) ['id' => 1000000001, 'mobile' => '186****2049'],
            ];
        } else {
            return collect([
                '1000000001' => (object) ['id' => 1000000001, 'mobile' => '186****2049'],
            ]);

        }


    }



}
