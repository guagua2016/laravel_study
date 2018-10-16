<?php
namespace App\Libraries;

use App\Libraries\Rpc;

class JPushService
{
    //
    // 推送给所有用户
    // @param : $message 消息内容 $platfrom in:all.ios.android
    //
    public static function postAll($message, $platform = 'all')
    {
        $data = [
            'message' => $message,
            'platform' => $platform,
        ];

        $host = env('DOMAIN_CMS',  'http://zhangzhe.cms.mofanglicai.com.cn');
        return Rpc::post($host, '/20160920/jpush/all', $data);
    }

    //
    // 推送给指定用户
    // @param $uids  array 推送用户
    //        $message 推送消息
    //        $platform in:all,ios.android 发送平台
    //
    public static function postUsers($uids, $message, $platform = 'all')
    {
        $data = [
            'uids' => $uids,
            'message' => $message,
            'platform' => $platform,
        ];

        $host = env('DOMAIN_CMS',  'http://zhangzhe.cms.mofanglicai.com.cn');
        return Rpc::post($host, '/20160920/jpush/users', $data);
    }
}
