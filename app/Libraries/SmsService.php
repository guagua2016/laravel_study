<?php
namespace App\Libraries;

use DB;
use Log;
use App\MessageGlobalIds;
use App\SmsContents;
use App\SmsTemplates;
use App\SmsSents;

use App\Libraries\JobStatus;
use App\Libraries\Rpc;

use Carbon\Carbon;

class SmsService
{
    /**
     * 向短信contents/sents表插入记录
     * @para:
     *       bid => 业务id(交易:13),
     *       target => 发送对象uid(单个或者uid数组), (object) ['id' => <uid>, 'mobile' => <mobile>],
     *       stype  => 短信类型(0：模板短信,1：内容短信)
     *       data => 数据, 魔板短信['templateno' => $template, 'params' => $params], 内容短信为短信内容
     *       attrs => [//可选
     *           channel => 短信发送渠道(0：云通讯,1：移动梦网)
     *           subid => 业务子id(0：固收，1：基金),
     *           retry => 发送重试次数(不传默认是1次),
     *           effect => 消息生效时间,
     *           expired => 消息失效时间,
     *       ]
     */
    public static function postUserSms($bid, $uids, $message, $attrs = [])
    {
        $data = [
            'business' => $bid,
            'uids' => $uids,
            'message' => $message,
            'attrs' => $attrs,
        ];

        $host = env('DOMAIN_SMS', 'http://kun.cms.mofanglicai.com.cn');
        $result = Rpc::post($host, '/20160518/sms/content', $data);
        if ($result['code'] == 20000) {
            return [true, 'Queued', $result['result']];
        } else {
            return [false, $result['message'], $result['result']];
        }
    }

    public static function postUserTemplate($bid, $uids, $template, $params, $attrs = [])
    {
        $data = [
            'business' => $bid,
            'uids' => $uids,
            'template' => $template,
            'params' => $params,
            'attrs' => $attrs,
        ];

        $host = env('DOMAIN_SMS', 'http://kun.cms.mofanglicai.com.cn');
        $result = Rpc::post($host, '/20160518/sms/template', $data);
        if ($result['code'] == 20000) {
            return [true, 'Queued', $result['result']];
        } else {
            return [false, $result['message'], $result['result']];
        }
    }

    public static function postMobileSms($bid, $mobiles, $message, $attrs = [])
    {
        $data = [
            'business' => $bid,
            'mobiles' => $mobiles,
            'message' => $message,
            'attrs' => $attrs,
        ];

        $host = env('DOMAIN_SMS', 'http://kun.sms.mofanglicai.com.cn');
        Log::info("[SMSDEBUG:KUN:START] host=$host", $data);
        $result = Rpc::post($host, '/20160518/sms/content', $data);
        Log::info("[SMSDEBUG:KUN:END]", $result);
        if ($result['code'] == 20000) {
            return [true, 'Queued', $result['result']];
        } else {
            $ret =  [false, [], []];
            if (isset($result['message'])) {
                $ret[1] = $result['message'];
            }
            if (isset($result['result'])) {
                $ret[2] = $result['result'];
            }
            return $ret;
        }
    }

    public static function postMobileTemplate($bid, $mobiles, $template, $params, $attrs = [])
    {
        $data = [
            'business' => $bid,
            'mobiles' => $mobiles,
            'template' => $template,
            'params' => $params,
            'attrs' => $attrs,
        ];

        $host = env('DOMAIN_SMS', 'http://kun.cms.mofanglicai.com.cn');
        $result = Rpc::post($host, '/20160518/sms/template', $data);
        if ($result['code'] == 20000) {
            return [true, 'Queued', $result['result']];
        } else {
            return [false, $result['message'], $result['result']];
        }
    }

    public static function smsAlert($msg, $mobiles = '', $bid = 13)
    {
        $map = collect([
            'kun' => '18610562049', // Likun Liu
            'yitao' => '18800067859', // Yitao Sheng
            'pp' => '13811710773', // Youyi Pan
            'jiaoyang' =>  '15652008886', // Yang Jiao
            'xiaobin' =>  '13031127095', // Xiaobin Zhu
            'zhangzhe' => '13671318228', // Zhe Zhang
            'zhangliang' => '18618365810', // Liang Zhang
        ]);

        if (is_string($mobiles)) {
            $mobiles = explode(',', $mobiles);
        }

        if (!is_array($mobiles)) {
            return false;
        }

        if (empty($mobiles)) {
            $mobiles = ['pp', 'zhangzhe'];
        }


        $targets = [];
        foreach ($mobiles as $k) {
            $target = $map->get($k);
            if ($target) {
                $targets[] = $target;
            }
        }

        $attrs = [
            'channel' => 1,
            'stype' => 4,
        ];
        try {
            $sms = static::postMobileSms($bid, $targets, $msg, $attrs);
            Log::info('10000:mf_portfolio send msg ' . $msg . ' to', $targets);
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }

        return true;
    }

}
