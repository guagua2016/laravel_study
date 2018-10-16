<?php
namespace App\Libraries;

use App\Libraries\Rpc;

class MessageService
{
    //
    // 消息盒子
    // @param :
    //        $uids 用户id 'all' 或 [$uid]
    //        $title 站内信标题
    //        $content 站内信内容
    //        $time 显示发送的时间，不传则显示当前时间
    public static function message($uids, $title, $content, $time = null)
    {
        try{
            $data = [
                'uids'    => $uids,
                'title'   => $title,
                'content' => $content,
                'time'    => $time,
            ];
            $host = env('DOMAIN_CMS',  'http://zhangzhe.cms.mofanglicai.com.cn');

            return Rpc::post($host, '/20160920/message/users', $data);
        }catch (\Exception $e){
            Log::error(sprintf("Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));

            return false;
        }
    }
}
