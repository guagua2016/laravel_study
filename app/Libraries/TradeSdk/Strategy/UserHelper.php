<?php namespace App\Libraries\TradeSdk\Strategy;
use App\TsShareFund;
use App\TsShareFundDetail;
use App\TsOrderFund;
use App\TsOrder;
use App\TsPlan;
use App\TsShareFundRedeeming;
use App\TsShareFundBonusing;
use App\Libraries\MfHelper;
use App\FundInfos;
use App\BaseFundStatus;
use App\RaFundNav;
use App\TsPlanBalance;
use App\FundFee;
use App\BaseDailyLimit;
use App\TsBlackList;
use App\YingmiPortfolioShareDetail;
use DB;
use Log;

//该类主要作用为处理用户所有数据
class UserHelper {
    //获取持仓
    public static function getHolding($uid,$portfolio_id=false){
        $holding = [
                    'holding'=>[],
                    'buying'=>[],
                    'bonusing'=>[],
                    'redeeming'=>[],
                    'yingmi'=>[],
                    ];
        if(empty($uid)){
            return $holding;
        }
        if($portfolio_id){
            $share_funds = TsShareFundDetail::where('ts_uid',$uid)->where('ts_portfolio_id',$portfolio_id)->where('ts_share','>',0)->get();
        }else{
            $share_funds = TsShareFundDetail::where('ts_uid',$uid)->where('ts_share','>',0)->get();
        }
        foreach($share_funds as $row){
            $holding['holding'][] = ['code'=>$row['ts_fund_code'],'share'=>$row['ts_share'],'amount'=>$row['ts_share']*$row['ts_nav'],'date'=>$row['ts_trade_date'],'redeemable_date'=>$row['ts_redeemable_date'],'pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id']];
        }
        if($portfolio_id){
            $orders = TsOrderFund::where('ts_uid',$uid)->where('ts_portfolio_id',$portfolio_id)->whereIn('ts_trade_type',[30,31,50,51,61,63])->whereIn('ts_trade_status',[0,1])->get();
        }else{
            $orders = TsOrderFund::where('ts_uid',$uid)->whereIn('ts_trade_type',[30,31,50,51,61,63])->whereIn('ts_trade_status',[0,1])->get();
        }
        foreach($orders as $order){
            $holding['buying'][] = ['id'=>$order['ts_txn_id'],'code'=>$order['ts_fund_code'],'amount'=>$order['ts_placed_amount'],'date'=>$order['ts_trade_date'],'ack_date'=>$order['ts_acked_date'],'pay_method'=>$order['ts_pay_method'],'portfolio_id'=>$order['ts_portfolio_id']];
        }

        $redeeming_amount = [];
        if($portfolio_id){
            $orders = TsOrderFund::where('ts_uid',$uid)->where('ts_portfolio_id',$portfolio_id)->whereIn('ts_trade_type',[41,43,62,64])->whereIn('ts_trade_status',[0,1])->get();
        }else{
            $orders = TsOrderFund::where('ts_uid',$uid)->whereIn('ts_trade_type',[41,43,62,64])->whereIn('ts_trade_status',[0,1])->get();
        }
        foreach($orders as $order){
            if($order['ts_acked_amount']==0){
                $ts = TsShareFundRedeeming::where('ts_order_id',$order['ts_txn_id'])->first();
                if($ts['ts_trade_nav']!=0){
                    $nav = $ts['ts_trade_nav'];
                }else{
                    $nav = $ts['ts_latest_nav'];
                }
                $amount = $order['ts_placed_share'] * $nav;
            }else{
                $amount = $order['ts_acked_amount'];
            }
            if(!isset($redeeming_amount[$order['ts_pay_method']])){
                $redeeming_amount[$order['ts_pay_method']] = 0;
            }
            $redeeming_amount[$order['ts_pay_method']] += $amount;
            $holding['redeeming'][] = ['id'=>$order['ts_txn_id'],'code'=>$order['ts_fund_code'],'share'=>$order['ts_placed_share'],'amount'=>$amount,'date'=>$order['ts_trade_date'],'ack_date'=>$order['ts_acked_date'],'pay_method'=>$order['ts_pay_method'],'portfolio_id'=>$order['ts_portfolio_id']];
        }
        //盈米赎回
        if($portfolio_id){
            $orders = TsOrderFund::where('ts_uid',$uid)->where('ts_portfolio_id','like','ZH%')->whereIn('ts_trade_type',[41,43,62,64])->whereIn('ts_trade_status',[0,1])->get();
            foreach($orders as $order){
                if($order['ts_acked_amount']!=0){
                    $ts = TsShareFundRedeeming::where('ts_order_id',$order['ts_txn_id'])->first();
                    if($ts['ts_trade_nav']!=0){
                        $nav = $ts['ts_trade_nav'];
                    }else{
                        $nav = $ts['ts_latest_nav'];
                    }
                    $amount = $order['ts_placed_share'] * $nav;
                }else{
                    $amount = $order['ts_acked_amount'];
                }
                if(!isset($redeeming_amount[$order['ts_pay_method']])){
                    $redeeming_amount[$order['ts_pay_method']] = 0;
                }
                $redeeming_amount[$order['ts_pay_method']] += $amount;
                $holding['redeeming'][] = ['id'=>$order['ts_txn_id'],'code'=>$order['ts_fund_code'],'share'=>$order['ts_placed_share'],'amount'=>$amount,'date'=>$order['ts_trade_date'],'ack_date'=>$order['ts_acked_date'],'pay_method'=>$order['ts_pay_method'],'portfolio_id'=>$order['ts_portfolio_id']];
            }
        }
        $redeeming = self::getAdjustRedeemingAmount($uid,$redeeming_amount);
        foreach($redeeming as $row){
            $holding['redeeming'][] = $row;
        }
        if($portfolio_id){
            $orders = TsShareFundBonusing::where('ts_uid',$uid)->where('ts_portfolio_id',$portfolio_id)->get();
        }else{
            $orders = TsShareFundBonusing::where('ts_uid',$uid)->get();
        }
        foreach($orders as $order){
            $holding['bonusing'][] = ['code'=>$order['ts_fund_code'],'amount'=>$order['ts_bonus_amount'],'record_date'=>$order['ts_record_date'],'dividend_date'=>$order['ts_dividend_date'],'pay_method'=>$order['ts_pay_method'],'portfolio_id'=>$order['ts_portfolio_id']];
        }
        //盈米分红
        $orders = TsShareFundBonusing::where('ts_uid',$uid)->where('ts_portfolio_id','like','ZH%')->get();
        foreach($orders as $order){
            $holding['bonusing'][] = ['code'=>$order['ts_fund_code'],'amount'=>$order['ts_bonus_amount'],'record_date'=>$order['ts_record_date'],'dividend_date'=>$order['ts_dividend_date'],'pay_method'=>$order['ts_pay_method'],'portfolio_id'=>$order['ts_portfolio_id']];
        }
        $holding['yingmi'] = self::getYingmiPortfolioHolding($uid);
        return $holding;
    }

    /** holding = [
                    'holding'=>[['code'=>'','share'=>'','amount'=>'','date'=>'','redeemable_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'buying'=>[['id'=>'','code'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'redeeming'=>[['id'=>,'code'=>'','share'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'bonusing'=>[['code'=>'','share'=>'','amount'=>'','record_date'=>'','dividend_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'yingmi'=>[['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>''],['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>'']],
                ]
    **/

    //传入持仓，基金池
    public static function mergeHolding($holding,$fund_list,$amount=0){
        $merge = ['redeem'=>0,'cur'=>$amount];
        $merge_yingmi = [];
        $hold_code = [];//已持有的
        $hold_code_list = [];//已持有的
        $type_list = [];
        $code_list = []; //所有持有的，包含正在赎回
        $codes = [];
        $pay_code = [];
        foreach($holding['holding'] as $row){
            if(!isset($hold_code[$row['pay_method']])){
                $hold_code[$row['pay_method']] = [];
            }
            $codes[] = $row['code'];
            $hold_code[$row['pay_method']][$row['code']] = 1;
            $hold_code_list[$row['code']] = 1;
            if(isset($fund_list['fund_type'][$row['code']])){
                $type = $fund_list['fund_type'][$row['code']];
            }else{
                $type = -1;
            }
            if(isset($merge[$type])){
                $merge[$type] += $row['amount'];
            }else{
                $merge[$type] = $row['amount'];
            }
            if(isset($type_list[$type])){
                $type_list[$type][] = $row['code'];
            }else{
                $type_list[$type] = [];
                $type_list[$type][] = $row['code'];
            }
            if(isset($code_list[$row['code']])){
                $code_list[$row['code']] += $row['amount'];
            }else{
                $code_list[$row['code']] = $row['amount'];
            }
            $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
            if(isset($pay_code[$code])){
                $pay_code[$code] += $row['amount'];
            }else{
                $pay_code[$code] = $row['amount'];
            }
        }
        foreach($holding['buying'] as $row){
            $codes[] = $row['code'];
            if(isset($fund_list['fund_type'][$row['code']])){
                $type = $fund_list['fund_type'][$row['code']];
            }else{
                $type = -1;
            }
            if(isset($merge[$type])){
                $merge[$type] += $row['amount'];
            }else{
                $merge[$type] = $row['amount'];
            }
            if(isset($type_list[$type])){
                $type_list[$type][] = $row['code'];
            }else{
                $type_list[$type] = [];
                $type_list[$type][] = $row['code'];
            }
            if(isset($code_list[$row['code']])){
                $code_list[$row['code']] += $row['amount'];
            }else{
                $code_list[$row['code']] = $row['amount'];
            }
            $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
            if(isset($pay_code[$code])){
                $pay_code[$code] += $row['amount'];
            }else{
                $pay_code[$code] = $row['amount'];
            }
        }
        foreach($holding['bonusing'] as $row){
            $codes[] = $row['code'];
            if(isset($fund_list['fund_type'][$row['code']])){
                $type = $fund_list['fund_type'][$row['code']];
            }else{
                $type = -1;
            }
            if(isset($merge[$type])){
                $merge[$type] += $row['amount'];
            }else{
                $merge[$type] = $row['amount'];
            }
            if(isset($type_list[$type])){
                $type_list[$type][] = $row['code'];
            }else{
                $type_list[$type] = [];
                $type_list[$type][] = $row['code'];
            }
            if(isset($code_list[$row['code']])){
                $code_list[$row['code']] += $row['amount'];
            }else{
                $code_list[$row['code']] = $row['amount'];
            }
            $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
            if(isset($pay_code[$code])){
                $pay_code[$code] += $row['amount'];
            }else{
                $pay_code[$code] = $row['amount'];
            }
        }
        foreach($holding['yingmi'] as $list){
            foreach($list['list'] as $row){
                $codes[] = $row['code'];
                if(isset($fund_list['fund_type'][$row['code']])){
                    $type = $fund_list['fund_type'][$row['code']];
                }else{
                    $type = -1;
                }
                if(isset($merge[$type])){
                    $merge[$type] += $row['amount'];
                }else{
                    $merge[$type] = $row['amount'];
                }
                if(isset($merge_yingmi[$type])){
                    $merge_yingmi[$type] += $row['amount'];
                }else{
                    $merge_yingmi[$type] = $row['amount'];
                }
                if(isset($code_list[$row['code']])){
                    $code_list[$row['code']] += $row['amount'];
                }else{
                    $code_list[$row['code']] = $row['amount'];
                }
                $code = $row['code']."|".$list['pay_method']."|".$list['portfolio_id'];
                if(isset($pay_code[$code])){
                    $pay_code[$code] += $row['amount'];
                }else{
                    $pay_code[$code] = $row['amount'];
                }
            }
        }
        foreach($holding['redeeming'] as $row){
            $codes[] = $row['code'];
            $merge['redeem'] += $row['amount'];
            if(isset($fund_list['fund_type'][$row['code']])){
                $type = $fund_list['fund_type'][$row['code']];
            }else{
                $type = -1;
            }
            if(isset($code_list[$row['code']])){
                $code_list[$row['code']] += $row['amount'];
            }else{
                $code_list[$row['code']] = $row['amount'];
            }
        }
        $change = [];
        foreach($merge as $type=>$amount){
            if(abs(array_sum($merge))>0.0000001){
                $change[$type] = $amount/array_sum($merge);
            }
        }
        return ['merge'=>$merge,'merge_yingmi'=>$merge_yingmi,'change'=>$change,'type_list'=>$type_list,'code_list'=>$code_list,'codes'=>array_keys(array_flip($codes)),'hold_code'=>$hold_code,'hold_code_list'=>$hold_code_list,'pay_code'=>$pay_code,'amount'=>array_sum($merge)];
    }

    public static function setTag($fund_list,$hold,$day=false){
        $codes = array_merge(array_keys($fund_list['fund_type']),$hold['codes']);
        $fee = FundFee::whereIn('ff_code',$codes)->orderBy('ff_min_value','asc')->get();
        $fund_info = [];
        $fund_old_info = [];
        if($day){
            $fund_old_info = BaseFundStatus::where('fs_date',$day)->whereIn('fs_fund_code',$codes)->get();
            if(!$fund_old_info && strtotime($day)<strtotime('2016-09-03')){
                $fund_old_info = BaseFundStatus::where('fs_date','2016-09-03')->whereIn('fs_fund_code',$codes)->get();
            }
        }
        $fund_info = FundInfos::whereIn('fi_code',$codes)->get();
        $daily_limit= BaseDailyLimit::whereIn('fd_fund_code',$codes)->orderBy('fd_begin_date','ASC')->get();
        $tmp = [];
                /**
                    1:个人首次申购最低金额
                    2:个人追加申购最低金额
                    3:个人最高申购金额
                    4:个人定投申购最低金额
                    5:个人定投申购最高金额
                    6:个人持有最低份额
                    7:个人赎回最低份额
                    8:个人转换最低份额
                    9:赎回时间序列
                    10:赎回手续费序列
                    21:公司id
                    22:起购起点
                    23:申购状态
                    24:转入转出状态
                    25:每日限购
                    26:申购时间序列
                    27:申购手续费序列
                    **/
        foreach($fund_info as $row){
            if(!isset($tmp[sprintf('%06d',$row['fi_code'])])){
                $tmp[sprintf('%06d',$row['fi_code'])] = [];
            }
            $tmp[sprintf('%06d',$row['fi_code'])][21] = $row['fi_company_id'];
            //if(strtotime($day)<strtotime('2016-08-03')){
            //    $tmp[sprintf('%06d',$row['fi_code'])][22] = 1;
            //}else{
                $tmp[sprintf('%06d',$row['fi_code'])][22] = $row['fi_yingmi_amount'];
            //}
            $tmp[sprintf('%06d',$row['fi_code'])][23] = $row['fi_yingmi_subscribe_status'];
	   // if($row['fi_code'] == '161125'){
           //     $tmp[sprintf('%06d',$row['fi_code'])][23] = 4;
	   // }
            $tmp[sprintf('%06d',$row['fi_code'])][24] = $row['fi_yingmi_transfor_status'];
        }
        foreach($fund_old_info as $row){
            //if($day && strtotime($day)<strtotime('2016-08-03')){
            //    $tmp[sprintf('%06d',$row['fs_fund_code'])][23] = 0;
            //}else{
               // $tmp[sprintf('%06d',$row['fs_fund_code'])][23] = $row['fs_subscribe_status'];
            //}
       //     $tmp[sprintf('%06d',$row['fs_fund_code'])][24] = $row['fs_transfor_status'];
        }
        foreach($daily_limit as $row){
            if(!isset($tmp[sprintf('%06d',$row['fd_fund_code'])])){
                $tmp[sprintf('%06d',$row['fd_fund_code'])] = [];
                $tmp[sprintf('%06d',$row['fd_fund_code'])][25] = $row['fd_limit'];
            }else{
                $tmp[sprintf('%06d',$row['fd_fund_code'])][25] = $row['fd_limit'];
            }
        }
        foreach($fee as $row){
            if(!isset($tmp[$row['ff_code']])){
                $tmp[$row['ff_code']] = [];
            }
            switch($row['ff_type']){
                case 10:
               //     if($day && strtotime($day)<strtotime('2016-08-03')){
                        $tmp[$row['ff_code']][1] = $row['ff_fee'];
               //     }else{
            //            $tmp[$row['ff_code']][1] = $row['ff_fee'];
               //     }
                    break;
                case 11:
                //    if($day && strtotime($day)<strtotime('2016-08-03')){
                //        $tmp[$row['ff_code']][2] = 1;
                //    }else{
                        $tmp[$row['ff_code']][2] = $row['ff_fee'];
                 //   }
                    break;
                case 12:
                    if(isset($tmp[$row['ff_code']][3])){
                        if($tmp[$row['ff_code']][3] > $row['ff_fee']){
                            $tmp[$row['ff_code']][3] = $row['ff_fee'];
                        }
                    }else{
                        $tmp[$row['ff_code']][3] = $row['ff_fee'];
                    }
                    break;
                case 18:
                    if(isset($tmp[$row['ff_code']][3])){
                        if($tmp[$row['ff_code']][3] > $row['ff_fee']){
                            $tmp[$row['ff_code']][3] = $row['ff_fee'];
                        }
                    }else{
                        $tmp[$row['ff_code']][3] = $row['ff_fee'];
                    }
                    break;
                case 13:
                    $tmp[$row['ff_code']][4] = $row['ff_fee'];
                    break;
                case 14:
                    $tmp[$row['ff_code']][5] = $row['ff_fee'];
                    break;
                case 15:
                    $tmp[$row['ff_code']][6] = $row['ff_fee'];
                    break;
                case 16:
                    $tmp[$row['ff_code']][7] = $row['ff_fee'];
                    break;
                case 17:
                    $tmp[$row['ff_code']][8] = $row['ff_fee'];
                    break;
                case 6:
                    if(isset($tmp[$row['ff_code']][9])){
                        $tmp[$row['ff_code']][9][] = $row['ff_max_value'];
                    }else{
                        $tmp[$row['ff_code']][9] = [$row['ff_max_value']];
                    }
                    if(isset($tmp[$row['ff_code']][10])){
                        $tmp[$row['ff_code']][10][] = $row['ff_fee'];
                    }else{
                        $tmp[$row['ff_code']][10] = [$row['ff_fee']];
                    }
                    break;
                case 5:
                    if(isset($tmp[$row['ff_code']][26])){
                        $tmp[$row['ff_code']][26][] = $row['ff_max_value'];
                    }else{
                        $tmp[$row['ff_code']][26] = [$row['ff_max_value']];
                    }
                    if(isset($tmp[$row['ff_code']][27])){
                        $tmp[$row['ff_code']][27][] = $row['ff_fee'];
                    }else{
                        $tmp[$row['ff_code']][27] = [$row['ff_fee']];
                    }
                    break;
                default:
                    break;
            }
        }
        return $tmp;
    }

    public static function daysbetweendates($date1, $date2){
        $date1 = strtotime($date1);
        $date2 = strtotime($date2);
        $days = ceil(abs($date1 - $date2)/86400);
        return $days;
    }

    public static function getHoldNum($amount){
        $list = ['50000'=>2,'100000'=>3,'1000000'=>4];
        foreach($list as $k=>$v){
            if($amount<=$k){
                return $v;
            }
        }
        return 4;
    }

    public static function getHoldLimit($amount){
        $list = ['50000'=>10,'500000'=>100,'1000000'=>1000];
        foreach($list as $k=>$v){
            if($amount<=$k){
                return $v;
            }
        }
        return 10000;
    }

    public static function getHoldRatio($amount){
        $list = ['50000'=>0.05,'500000'=>0.03,'1000000'=>0.01];
        foreach($list as $k=>$v){
            if($amount<=$k){
                return $v;
            }
        }
        return 0.01;
    }

    public static function getBuyFee($code,$sum,$tags,$older=false){
        $fee = 0;
        $fee_discount = FundFee::buyingRate(); //0.2折
        $fee_limit = 0.000; //底线
        //$fee_discount = 0.1 ;//0.2折
        //$fee_limit = 0 ;//底线
        if(isset($tags[$code][26])){
            for($i = 0; $i < count($tags[$code][26]);$i++){
                if($i!=count($tags[$code][26])-1 && $tags[$code][26][$i]> $sum){
                    if($older){
                        $fee = $tags[$code][27][$i];
                    }else{
                        $fee = $tags[$code][27][$i]*$fee_discount;
                    }
                    break;
                }else{
                    if($older){
                        $fee = $tags[$code][27][$i];
                    }else{
                        $fee = $tags[$code][27][$i]*$fee_discount;
                    }
                }
            }
        }

        if($fee >= 1){
            $cost = $fee;
        }else{
            if($fee>=$fee_limit){
                $cost = $sum-($sum/(1+$fee));
            }else{
                if(isset($tags[$code][26])){
                    $cost = $sum-($sum/(1+$fee_limit));
                }else{
                    $cost = 0;
                }
            }
        }
        return $cost;
    }

    public static function getYingmiPortfolioHolding($uid){
        $share_funds = TsShareFundDetail::where('ts_uid',$uid)->where('ts_portfolio_id','like','ZH%')->where('ts_share','>',0)->get();
        $yingmi = [];
        $tmp = [];
        foreach($share_funds as $row){
            $key = $row['ts_portfolio_id']."|".$row['ts_pay_method'];
            if(!isset($tmp[$key])){
                $pay_method = explode(':',$row['ts_pay_method']);
                $pay_method = $pay_method[1];
                $shares = YingmiPortfolioShareDetail::where('yp_uid',$uid)->where('yp_portfolio_id',$row['ts_portfolio_id'])->where('yp_payment_method',$pay_method)->first();
                $tmp[$key] = ['list'=>[],'pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id'],'lower'=>$shares['yp_lower_redeem_ratio'],
                'upper'=>$shares['yp_higher_redeem_ratio'],'tag'=>$shares['yp_can_adjust']];
            }
            $tmp[$key]['list'][] = ['code'=>$row['ts_fund_code'],'share'=>$row['ts_share'],'amount'=>$row['ts_nav']*$row['ts_share']];
        }
        $yingmi = array_values($tmp);
        return $yingmi;
    }

    public static function doing($holding,$strategy){
        $date = date('Y-m-d');
        foreach($strategy['add'] as $row){
            $holding['buying'][] = ['id'=>'','code'=>$row['code'],'amount'=>$row['amount'],'date'=>$date,'ack_date'=>$date,'pay_method'=>$row['pay_method'],'portfolio_id'=>$row['portfolio_id']];
        }
        if($strategy['del'] && $strategy['add']){
            $holding['redeeming'] = [];
        }
        foreach($strategy['del'] as $row){
            $amount = $row['amount'];
            for($j = 0;$j< count($holding['yingmi']);$j++){
                if($row['amount'] <=0)break;
                if($holding['yingmi'][$j]['pay_method'] != $row['pay_method']){
                    continue;
                }
                for($i = 0;$i< count($holding['yingmi'][$j]['list']);$i++){
                    if($holding['yingmi'][$j]['list'][$i]['code'] == $row['code']){
                        if($row['amount'] >= $holding['yingmi'][$j]['list'][$i]['amount']){
                            $row['amount'] -= $holding['yingmi'][$j]['list'][$i]['amount'];
                            $holding['yingmi'][$j]['list'][$i]['share'] = 0;
                            $holding['yingmi'][$j]['list'][$i]['amount'] = 0;
                        }else{
                            $holding['yingmi'][$j]['list'][$i]['share'] -= $holding['yingmi'][$j]['list'][$i]['share']*$row['amount']/$holding['yingmi'][$j]['list'][$i]['amount'];
                            $holding['yingmi'][$j]['list'][$i]['amount'] -= $row['amount'];
                            $row['amount'] = 0;
                            break;
                        }
                    }
                }
            }
            for($i = 0;$i< count($holding['holding']);$i++){
                if($row['amount'] <=0)break;
                if($holding['holding'][$i]['pay_method'] != $row['pay_method']){
                    continue;
                }
                if($holding['holding'][$i]['code'] == $row['code']){
                    if($row['amount'] >= $holding['holding'][$i]['amount']){
                        $row['amount'] -= $holding['holding'][$i]['amount'];
                        $holding['holding'][$i]['share'] = 0;
                        $holding['holding'][$i]['amount'] = 0;
                    }else{
                        $holding['holding'][$i]['share'] -= $holding['holding'][$i]['share']*$row['amount']/$holding['holding'][$i]['amount'];
                        $holding['holding'][$i]['amount'] -= $row['amount'];
                        $row['amount'] = 0;
                        break;
                    }
                }
            }
            for($i = 0;$i< count($holding['bonusing']);$i++){
                if($row['amount'] <=0)break;
                if($holding['bonusing'][$i]['pay_method'] != $row['pay_method']){
                    continue;
                }
                if($holding['bonusing'][$i]['code'] == $row['code']){
                    if($row['amount'] >= $holding['bonusing'][$i]['amount']){
                        $row['amount'] -= $holding['bonusing'][$i]['amount'];
                        $holding['bonusing'][$i]['amount'] = 0;
                    }else{
                        $holding['bonusing'][$i]['amount'] -= $row['amount'];
                        $row['amount'] = 0;
                        break;
                    }
                }
            }
            for($i = 0;$i< count($holding['buying']);$i++){
                if($row['amount'] <=0)break;
                if($holding['buying'][$i]['pay_method'] != $row['pay_method']){
                    continue;
                }
                if($holding['buying'][$i]['code'] == $row['code']){
                    if($row['amount'] >= $holding['buying'][$i]['amount']){
                        $row['amount'] -= $holding['buying'][$i]['amount'];
                        $holding['buying'][$i]['amount'] = 0;
                    }else{
                        $holding['buying'][$i]['amount'] -= $row['amount'];
                        break;
                    }
                }
            }
        }
        return $holding;
    }

    public static function getBlackList($uid){
        $black_list = [];
        if($uid){
            $blacks = TsBlackList::where('ts_uid',$uid)->get();
            foreach($blacks as $row){
                if ($row->ts_fund_code == '*') {
                    if ($row->ts_company_id) {
                        $codes = FundInfos::where('fi_company_id', $row->ts_company_id)->lists('fi_code_str');
                        $black_list = array_merge($black_list, $codes->toArray());
                    }
                } else {
                    $black_list[] = $row['ts_fund_code'];
                }
            }
        }
        return $black_list;
    }

    //获取赎回确认但是没花和没到账的钱,传入的参数redeeming为未确认的，用于把前面调仓订单所有赎回-所有购买-赎回未确认的=确认但没花的钱
    public static function getAdjustRedeemingAmount($uid,$redeeming){
        $plan = TsPlan::where('ts_uid',$uid)->where('ts_type',3)->where('ts_status',1)->orderBy('id','DESC')->first();
        if($plan){
            $txnId = $plan['ts_txn_id'];
        }else{
            return [];
        }
        $balance = TsPlanBalance::where('ts_txn_id',$txnId)->first();
        $txnIds = [$txnId];
        if($balance && $balance['ts_aborted_txn_id']){
            $aborted = $balance['ts_aborted_txn_id'];
            while(true){
                $txnIds[] = $aborted;
                $balance = TsPlanBalance::where('ts_txn_id',$aborted)->first();
                if($balance && $balance['ts_aborted_txn_id']){
                    $aborted = $balance['ts_aborted_txn_id'];
                }else{
                    break;
                }
            }
        }
            /**
            $plan_funds = TsPlanFund::where('ts_txn_id',$txnId)->get();
            foreach($plan_funds as $row){
                if($row['ts_amount_dst'] > $row['ts_amount_src']){
                    if(strtotime($plan['ts_start_at']) <= strtotime('2018-02-23')){
                        if(strtotime($row['updated_at']) > strtotime('2018-02-23 05:00:00')){
                            if($row['ts_amount_src'] ==0 && $row['ts_amount_placed'] ==0){
                                TsPlanFund::where('id',$row['id'])->delete();
                            }else{
                                TsPlanFund::where('id',$row['id'])->update(['ts_amount_dst'=>($row['ts_amount_src']+$row['ts_amount_placed'])]);
                            }
                        }
                    }
                }
            }
            **/
        $del_orders = TsOrderFund::whereIn('ts_portfolio_txn_id',$txnIds)->whereIn('ts_trade_type',[62,64])->whereIn('ts_trade_status',[0,1,5,6])->get();
        $del_amount = [];
        foreach($del_orders as $row){
            if(!isset($del_amount[$row['ts_pay_method']])){
                $del_amount[$row['ts_pay_method']] = 0;
            }
            if($row['ts_acked_amount'] && $row['ts_acked_amount']!=0){
                $del_amount[$row['ts_pay_method']] += $row['ts_acked_amount'];
            }else{
                $share = $row['ts_placed_share'];
                $ra_nav = RaFundNav::where('ra_code',$row['ts_fund_code'])->where('ra_date','<=',$row['ts_trade_date'])->orderBy('ra_date','DESC')->first();
                $del_amount[$row['ts_pay_method']] += $share*$ra_nav['ra_nav'];
            }
        }
        $add_orders = TsOrderFund::whereIn('ts_portfolio_txn_id',$txnIds)->whereIn('ts_trade_type',[61,63])->whereIn('ts_trade_status',[0,1,5,6])->get();
        $add_amount = [];
        foreach($add_orders as $row){
            if(!isset($add_amount[$row['ts_pay_method']])){
                $add_amount[$row['ts_pay_method']] = 0;
            }
            if($row['ts_acked_amount'] && $row['ts_acked_amount']!=0){
                $add_amount[$row['ts_pay_method']] += $row['ts_acked_amount'];
            }else{
                $add_amount[$row['ts_pay_method']] += $row['ts_placed_amount'];
            }
        }
        $balances = [];
        foreach($del_amount as $pay_method=>$del){
            if(isset($add_amount[$pay_method])){
                $balances[$pay_method] =  $del-$add_amount[$pay_method];
            }else{
                $balances[$pay_method] =  $del;
            }
        }
        $list = [];
        $order = TsOrder::where('ts_txn_id',$plan['ts_txn_id'])->where('ts_portfolio_id','not like','ZH%')->first();
        foreach($balances as $pay_method=>$amount){
            $new_amount = $amount;
            if(isset($redeeming[$pay_method])){
                $new_amount -= $redeeming[$pay_method];
            }
            if($new_amount<=0)continue;
            $list[] = ['id'=>$order['ts_txn_id'],'code'=>'001826','share'=>$new_amount,'amount'=>$new_amount,'date'=>date('Y-m-d'),'ack_date'=>date('Y-m-d'),'pay_method'=>$pay_method,'portfolio_id'=>$order['ts_portfolio_id']];
        }
        return $list;
    }

}
