<?php namespace App\Libraries\TradeSdk\Strategy;
use DB;
use Log;
use App\FundInfos;
use App\FundClose;
use App\Libraries\TradeSdk\Strategy\UserHelper;
use App\Libraries\TradeDate;
use App\TsOrderFund;

class MatchmakingHelper{
    use TradeDate;
    //该类主要作用为根据调仓，赎回，购买加载可以操作的基金池
    //初始化传入type 0 适用于在线服务，1 适用于历史交易
    function __construct($load_type=0)
    {
    }

    public static function test()
    {
    }

    public static function match($uid,$txnId,$portfolio_id,$strategy,$balance=[])
    {
        $order = ['add'=>[],'del'=>[]];
        $codes = [];
        foreach($strategy['del'] as $row){
            $codes[] = $row['code'];
        }
        foreach($strategy['add'] as $row){
            $codes[] = $row['code'];
        }
        $tag = UserHelper::setTag(['fund_type'=>[]],['codes'=>$codes]);
        $holding = UserHelper::getHolding($uid,$portfolio_id);
        foreach($strategy['del'] as $row){
            if(strpos($row['portfolio_id'],'ZH')!==false){
                continue;
            }
            $time = self::getTradeDate($row['code'],1);
            if(!$time){
                continue;
            }
            $share = 0;
            $amount = 0;
            $share_total = 0;
            $amount_total = 0;
            $row['amount'] = abs($row['amount']);
            foreach($holding['holding'] as $h){
                if($h['code']!=$row['code'])continue;
                if($h['portfolio_id']!=$row['portfolio_id'])continue;
                if($h['pay_method']!=$row['pay_method'])continue;
                $amount_total += $h['amount'];
                $share_total += $h['share'];
                if(strtotime(date('Y-m-d H:i:s')) >= strtotime($h['redeemable_date']." 15:00:00")){
                    $amount += $h['amount'];
                    $share += $h['share'];
                }else{
                    $amount_total += $h['amount'];
                }
            }
            foreach($holding['buying'] as $h){
                if($h['code']!=$row['code'])continue;
                if($h['portfolio_id']!=$row['portfolio_id'])continue;
                if($h['pay_method']!=$row['pay_method'])continue;
                $amount_total += $h['amount'];
            }
            foreach($holding['bonusing'] as $h){
                if($h['code']!=$row['code'])continue;
                if($h['portfolio_id']!=$row['portfolio_id'])continue;
                if($h['pay_method']!=$row['pay_method'])continue;
                $amount_total += $h['amount'];
            }
            if($row['amount'] <= $amount){
                $share_del = round($share*$row['amount']/$amount,2);
                $amount_del = $row['amount'];
                if(isset($tag[$row['code']])){
                    if(isset($tag[$row['code']][7])){
                        if($share_del<$tag[$row['code']][7]){
                            $share_del = $tag[$row['code']][7];
                        }
                    }
                    if(isset($tag[$row['code']][6])){
                        if(($share-$share_del)<$tag[$row['code']][6]){
                            $share_del = $share;
                        }
                    }
                }else{
                    $share_del = $share;
                }
            }else{
                if($amount < $amount_total){
                    $share_del = $share;
                    $amount_del = $amount;
                }else{
                    $share_del = $share;
                    //调仓中无法赎回
                    $amount_del = $row['amount'];
                }
            }
            if($share_del<=0){
                continue;
            }
            $fund = FundInfos::where('fi_code',$row['code'])->first();
            $order['del'][] = ['name'=>$fund['fi_name'],'code'=>$row['code'],'amount'=>$amount_del,'share'=>round($share_del,4),'pay_method'=>$row['pay_method'],'portfolio_id'=>$row['portfolio_id'],'scheduled_date'=>$time];
        }
        foreach($balance as $pay_method=>$list){
            if($list['status'] == 0){
                $del_amount = 0;
                foreach($strategy['add'] as $row){
                    if($row['pay_method'] == $pay_method){
                        if(isset($tag[$row['code']])&& isset($tag[$row['code']][1])){
                            if($tag[$row['code']][1] > $del_amount){
                                $del_amount = $tag[$row['code']][1];
                            }
                        }
                    }
                }
                $list['amount'] -= $del_amount;
                if($list['amount'] <= $del_amount){
                    continue;
                }
            }
            foreach($strategy['add'] as $row){
                if($list['amount'] <= 0)break;
                $time = self::getTradeDate($row['code'],0);
                if(!$time){
                    continue;
                }
                if($row['pay_method'] == $pay_method){
                    if($list['amount'] >= $row['amount']){
                        if($tag[$row['code']][1] > $row['amount'])continue;
                        $fund = FundInfos::where('fi_code',$row['code'])->first();
                        $order['add'][] = ['name'=>$fund['fi_name'],'code'=>$row['code'],'amount'=>$row['amount'],'pay_method'=>$pay_method,'portfolio_id'=>$row['portfolio_id'],'scheduled_date'=>$time];
                        $list['amount'] -= $row['amount'];
                    }else{
                        if($list['status'] == 0){
                            if(isset($tag[$row['code']])){
                                if($tag[$row['code']][1] > $list['amount'])continue;
                                if($tag[$row['code']][1] > ($row['amount']-$list['amount'])){
                                    if($row['amount'] >= ($tag[$row['code']][1]*2)){
                                        $amount = $row['amount'] - $tag[$row['code']][1];
                                    }else{
                                        continue;
                                    }
                                }else{
                                    $amount = $list['amount'];
                                }
                                $fund = FundInfos::where('fi_code',$row['code'])->first();
                                $order['add'][] = ['name'=>$fund['fi_name'],'code'=>$row['code'],'amount'=>$amount,'pay_method'=>$pay_method,'portfolio_id'=>$row['portfolio_id'],'scheduled_date'=>$time];
                                $list['amount'] -= $amount;
                            }
                        }else{
                            if(isset($tag[$row['code']])){
                                if($tag[$row['code']][1] > $list['amount'])continue;
                                $fund = FundInfos::where('fi_code',$row['code'])->first();
                                $order['add'][] = ['name'=>$fund['fi_name'],'code'=>$row['code'],'amount'=>$list['amount'],'pay_method'=>$pay_method,'portfolio_id'=>$row['portfolio_id'],'scheduled_date'=>$time];
                                $list['amount'] = 0;

                            }
                        }
                    }
                }
            }
            if($list['status']==1 && $list['amount']>0){
                for($i=0;$i<count($order['add']);$i++){
                    if($order['add'][$i]['pay_method'] == $pay_method){
                        if(!isset($tag[$order['add'][$i]['code']][25]) || $tag[$order['add'][$i]['code']][25]>=100000){
                            $order['add'][$i]['amount'] += $list['amount'];
                            $list['amount'] =0;
                            break;
                        }
                    }
                }
                if($list['amount'] > 0){
                    $ts_funds = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereIn('ts_trade_type',[31,51,61,63])->where('ts_pay_method',$pay_method)->get();
                    $add_tag = $codes;
                    foreach($ts_funds as $row){
                        $add_tag[] = $row['ts_fund_code'];
                    }
                    $tag = UserHelper::setTag(['fund_type'=>[]],['codes'=>$add_tag]);
                    foreach($ts_funds as $row){
                        if(!isset($tag[$row['ts_fund_code']][25]) || $tag[$row['ts_fund_code']][25]>=1000000){
                            if($tag[$row['ts_fund_code']][1] > $list['amount'])continue;
                            $time = self::getTradeDate($row['ts_fund_code'],0);
                            $order['add'][] = ['name'=>$row['ts_fund_name'],'code'=>$row['ts_fund_code'],'amount'=>$list['amount'],'pay_method'=>$pay_method,'portfolio_id'=>$row['ts_portfolio_id'],'scheduled_date'=>$time];
                            $list['amount'] =0;
                            break;
                        }
                    }
                }
            }
        }
        return $order;
    }

    //type = 0 购买 1赎回 status=true 用于判断当前是否可交易
    public static function getTradeDate($code,$type,$status=false){
        $fund = FundInfos::where('fi_code',$code)->first();
        $day = date('Y-m-d');
        $time = date('H:i:s');
        if($fund){
            if($type==0){
                if(in_array($fund['fi_yingmi_subscribe_status'],[0,6])){
                    return date('Y-m-d H:i:s');
                }else{
                    if($status){
                        return false;
                    }
                    $close = FundClose::where('fc_fund_code',$code)->where('fc_date','>=',date('Y-m-d'))->orderBy('fc_date','ASC')->get();
                    $day_list =[];
                    foreach($close as $row){
                        $day_list[$row['fc_date']] = 1;
                    }
                    for($i=1;$i<=15;$i++){
                        $day1 = self::tradeDatewithTime($day, $time, $i);
                        if(!isset($day_list[$day1])){
                            return $day1." 10:00:00";
                        }
                    }
                    return false;
                }
            }else{
                if(in_array($fund['fi_yingmi_subscribe_status'],[0,5])){
                    return date('Y-m-d H:i:s');
                }else{
                    if($status){
                        return false;
                    }
                    $close = FundClose::where('fc_fund_code',$code)->where('fc_date','>=',date('Y-m-d'))->orderBy('fc_date','ASC')->get();
                    $day_list =[];
                    foreach($close as $row){
                        $day_list[$row['fc_date']] = 1;
                    }
                    for($i=1;$i<=15;$i++){
                        $day1 = self::tradeDatewithTime($day, $time, $i);
                        if(!isset($day_list[$day1])){
                            return $day1." 10:00:00";
                        }
                    }
                    return false;
                }
            }
        }
    }
}
