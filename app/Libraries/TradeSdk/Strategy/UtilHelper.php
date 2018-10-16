<?php namespace App\Libraries\TradeSdk\Strategy;
use App\TsOrderFund;
use App\TsOrder;
use DB;
use Log;
use App\Libraries\TradeSdk\Strategy\UserHelper;
use App\TsPlan;
use App\TsPlanFund;
use App\TsTxnId;
use App\TsBlackList;
use App\YingmiWalletFund;
use App\FundInfos;
use Carbon\Carbon;

//该类主要作用为工具类，用于修复计划等功能
class UtilHelper {

    //修复订单中无法购买的基金，调整计划购买同类基金，同时加入黑名单
    public static function fixPlan2NoBuyFund($txnId){
        $plan = TsPlan::where('ts_txn_id',$txnId)->where('ts_status',1)->first();
        if($plan){
            $uid = $plan['ts_uid'];
            $funds = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereIn('ts_error_code',['0316','2101', '9114', '0512','0104','0123'])->get();
            if(!$funds->isEmpty()){
                $black_funds = [];
                foreach($funds as $fund){
                    $black_funds[] = $fund['ts_fund_code'];
                }
                $black_list = UserHelper::getBlackList($plan['ts_uid']);
                $black_list = array_merge($black_list,$black_funds);
                $strategy = self::SrcDst2StrategyWithOutPlaced($txnId);
                $del = [];
                $add = [];
                $all_add = [];
                $all_del= [];
                $check_add = [];
                //加入黑名单
                self::importBlackList($uid,$black_funds);
                foreach($strategy['add'] as $row){
                    if(!isset($add[$row['pay_method']])){
                        $add[$row['pay_method']] = [];
                    }
                    $check_add[] = $row['code'];
                    if(in_array($row['code'],$black_funds)){
                        continue;
                    }
                    $add[$row['pay_method']][] = $row['code'];
                    $all_add[] = $row['code'];
                }
                if(!array_intersect($black_funds,$check_add)){
                    Log::info('fix_plan_unable_fund:'.$uid.':'.$txnId.':不需要修复 finished: '.' unfinished: ');
                    return [20000,'不需要修复',[],[]];
                }
                foreach($strategy['del'] as $row){
                    if(!isset($del[$row['pay_method']])){
                        $del[$row['pay_method']] = [];
                    }
                    $del[$row['pay_method']][$row['code']] = 1;
                    $all_del[$row['code']] = 1;
                }
                $fund_pool = new FundPoolHelper();
                $fund_pool->setBlackList($black_list);
                $fund_list = $fund_pool->getFundPool();
                $finished = [];
                $unfinished = [];
                foreach($funds as $fund){
                    $type = $fund_list['global_fund_type'][$fund['ts_fund_code']];
                    $add_funds = array_intersect($fund_list['pool_fund'][$type]['buy'],$add[$fund['ts_pay_method']]);
                    $change_fund = false;
                    //获取同卡同类型已购买一级基金
                    if($add_funds){
                        foreach($add_funds as $row){
                            if(!isset($all_del[$row])){
                                $change_fund = $row;
                                break;
                            }
                        }
                    }
                    //获取不同卡同类型已购买一级基金
                    if(!$change_fund){
                        $add_funds = array_intersect($fund_list['pool_fund'][$type]['buy'],$all_add);
                        foreach($add_funds as $row){
                            if(!isset($all_del[$row])){
                                $change_fund = $row;
                                break;
                            }
                        }
                    }
                    //获取同卡同类型已购买二级基金
                    if(!$change_fund){
                        $add_funds = array_intersect($fund_list['pool_fund'][$type]['stay'],$add[$fund['ts_pay_method']]);
                        foreach($add_funds as $row){
                            if(!isset($all_del[$row]) && !in_array($row,$black_list)){
                                $change_fund = $row;
                                break;
                            }
                        }
                    }
                    //获取不同卡同类型已购买二级基金
                    if(!$change_fund){
                        $add_funds = array_intersect($fund_list['pool_fund'][$type]['stay'],$all_add);
                        foreach($add_funds as $row){
                            if(!isset($all_del[$row]) && !in_array($row,$black_list)){
                                $change_fund = $row;
                                break;
                            }
                        }
                    }
                    //获取同类型未购买一级基金
                    if(!$change_fund){
                        foreach($fund_list['pool_fund'][$type]['buy'] as $row){
                            if(!isset($all_del[$row])){
                                $change_fund = $row;
                                break;
                            }
                        }
                    }
                    //获取同类型未购买二级基金
                    if(!$change_fund){
                        foreach($fund_list['pool_fund'][$type]['stay'] as $row){
                            if(!isset($all_del[$row]) && !in_array($row,$black_list)){
                                $change_fund = $row;
                                break;
                            }
                        }
                    }
                    if($change_fund){
                        $time = date('Y-m-d H:i:s');
                        $old_plan = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$fund['ts_portfolio_id'])->where('ts_pay_method',$fund['ts_pay_method'])->where('ts_fund_code',$fund['ts_fund_code'])->where('ts_status',1)->first();
                        if($old_plan){
                            TsPlanFund::where('id',$old_plan['id'])->update(['ts_status'=>-1,'updated_at'=>$time]);
                            $new_plan = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$fund['ts_portfolio_id'])->where('ts_pay_method',$fund['ts_pay_method'])->where('ts_fund_code',$change_fund)->first();
                            $amount = $old_plan['ts_amount_dst'] - $old_plan['ts_amount_src'];
                            if($new_plan){
                                TsPlanFund::where('id',$new_plan['id'])->update(['ts_amount_dst'=>DB::raw("ts_amount_dst+{$amount}"),'ts_status'=>1,'updated_at'=>date('Y-m-d H:i:s')]);
                            }else{
                                $new_plan = ['ts_txn_id'=>$txnId,'ts_uid'=>$old_plan['ts_uid'],'ts_portfolio_id'=>$old_plan['ts_portfolio_id'],'ts_pay_method'=>$old_plan['ts_pay_method'],
                        'ts_fund_code'=>$change_fund,'ts_amount_src'=>0,'ts_amount_dst'=>$amount,'ts_status'=>1,'created_at'=>$time,'updated_at'=>$time
                            ];
                                $m = new TsPlanFund($new_plan);
                                $m->save();
                            }
                            $finished[] = ['code_src'=>$fund['ts_fund_code'],'code_dst'=>$change_fund,'amount'=>$amount,'pay_method'=>$old_plan['ts_pay_method'],'portfolio_id'=>$old_plan['ts_portfolio_id'],'txnId'=>$txnId];
                        }
                    }else{
                        $unfinished[] = $fund['ts_fund_code'];
                    }
                }
                if($finished){
                    if(!$unfinished){
                        Log::info('fix_plan_unable_fund:'.$uid.':'.$txnId.':完成 finished: '. json_encode($finished).' unfinished:'.json_encode($unfinished));
                        return [20000,'全部完成',$finished,$unfinished];
                    }else{
                        Log::info('fix_plan_unable_fund:'.$uid.':'.$txnId.':部分完成 finished: '. json_encode($finished).' unfinished:'.json_encode($unfinished));
                        return [20001,'部分完成,估计是基金池不够了,请联系晓彬',$finished,$unfinished];
                    }
                }else{
                    if(!$unfinished){
                        Log::info('fix_plan_unable_fund:'.$uid.':'.$txnId.':不需要修复 finished: '.' unfinished: ');
                        return [20000,'不需要修复',[],[]];
                    }else{
                        Log::info('fix_plan_unable_fund:'.$uid.':'.$txnId.':修复失败 finished: '. json_encode($finished).' unfinished:'.json_encode($unfinished));
                        return [30000,'修复失败，估计是基金池不够了,请联系晓彬',$finished,$unfinished];
                    }
                }
            }else{
                Log::info('fix_plan_unable_fund:'.$uid.':'.$txnId.':不需要修复 finished: '.' unfinished: ');
                return [20000,'不需要修复',[],[]];
            }
        }else{
            Log::info('fix_plan_unable_fund:'.':'.$txnId.':订单不存在 finished: '.' unfinished: ');
            return [40000,'订单不存在',[],[]];
        }

    }

    //转入转出
    public static function fixPlanInOut($txnId,$in_txnId=false){
        $order_id = $txnId;
        $paymethod = TsOrderFund::where('ts_portfolio_txn_id',$order_id)->whereIn('ts_trade_type',[62,64])->whereIn('ts_trade_status',[5,6])->distinct('ts_pay_method')->lists('ts_pay_method');
        foreach($paymethod as $pay){
            $del = TsOrderFund::where('ts_portfolio_txn_id',$order_id)->where('ts_pay_method',$pay)->whereIn('ts_trade_type',[62,64])->whereIn('ts_trade_status',[5,6])->sum('ts_acked_amount');
            $add = TsOrderFund::where('ts_portfolio_txn_id',$order_id)->where('ts_pay_method',$pay)->whereIn('ts_trade_type',[61,63,31])->whereIn('ts_trade_status',[5,6])->sum('ts_acked_amount');
            $max_date = TsOrderFund::where('ts_portfolio_txn_id',$order_id)->where('ts_pay_method',$pay)->whereIn('ts_trade_type',[61,63,31])->whereIn('ts_trade_status',[5,6])->max('ts_acked_date');
            if(!$max_date){
                $max_date = TsOrderFund::where('ts_portfolio_txn_id',$order_id)->where('ts_pay_method',$pay)->whereIn('ts_trade_type',[62,64])->whereIn('ts_trade_status',[5,6])->max('ts_acked_date');
            }
            $plan = TsPlan::where('ts_txn_id',$order_id)->where('ts_type',3)->first();
            $dt = Carbon::parse($max_date." 14:55:00");
            if($plan && $plan['ts_type'] = 3 && $del-$add>0){
                if($in_txnId){
                    $next_order_id = $in_txnId;
                    $new_plan = TsPlan::where('ts_txn_id',$next_order_id)->first();
                }else{
                    $new_plan = TsPlan::where('ts_uid',$plan['ts_uid'])->where('id','>',$plan['id'])->where('ts_type',3)->orderBy('id','asc')->first();
                }
                $out = TsOrderFund::where('ts_portfolio_txn_id',$order_id)->where('ts_pay_method',$pay)->where('ts_trade_type',97)->where('ts_trade_status',6)->first();
                if($out){
                    TsOrderFund::where('ts_portfolio_txn_id',$order_id)->where('ts_pay_method',$pay)->where('ts_trade_type',97)->where('ts_trade_status',6)->update(['ts_placed_share'=>round($del-$add,2),'ts_placed_amount'=>round($del-$add,2),'ts_acked_share'=>round($del-$add,2),'ts_acked_amount'=>round($del-$add,2)]);
                }else{
                    $orders = TsOrder::where('ts_txn_id',$order_id)->where('ts_portfolio_id','not like','ZH%')->limit(1)->get();
                    $buy_fund = YingmiWalletFund::where('id','>',0)->first();
                    foreach($orders as $row){
                        $chargeTxnId = TsTxnId::makeFundTxnId($order_id);
                        $sub = [
                        'ts_txn_id' => $chargeTxnId,
                        'ts_uid' => $plan['ts_uid'],
                        'ts_portfolio_id' => $row['ts_portfolio_id'],
                        'ts_portfolio_txn_id' => $order_id,
                        'ts_fund_code' => $buy_fund['yw_fund_code'],
                        'ts_fund_name' => $buy_fund['yw_fund_name'],
                        'ts_trade_type' => 97,
                        'ts_trade_status' => 6,
                        'ts_placed_amount' => round($del-$add,2),
                        'ts_placed_share' => round($del-$add,2),
                        'ts_acked_share' => round($del-$add,2),
                        'ts_acked_amount' => round($del-$add,2),
                        'ts_pay_method' => $pay,
                        'ts_accepted_at' => $dt,
                        'ts_scheduled_at' => $dt,
                        'ts_placed_date' => $dt->toDateString(),
                        'ts_placed_time' => $dt->toTimeString(),
                        'ts_acked_date' => $dt->toDateString(),
                        'ts_origin' => 8,
                        ];
                        $m = new TsOrderFund($sub);
                        $m->save();
                    }
                }
                $in = TsOrderFund::where('ts_portfolio_txn_id',$new_plan['ts_txn_id'])->where('ts_pay_method',$pay)->where('ts_trade_type',98)->where('ts_trade_status',6)->first();
                if($in){
                    TsOrderFund::where('ts_portfolio_txn_id',$new_plan['ts_txn_id'])->where('ts_pay_method',$pay)->where('ts_trade_type',98)->where('ts_trade_status',6)->update(['ts_placed_share'=>round($del-$add,2),'ts_placed_amount'=>round($del-$add,2),'ts_acked_share'=>round($del-$add,2),'ts_acked_amount'=>round($del-$add,2)]);
                }else{
                    $orders = TsOrder::where('ts_txn_id',$new_plan['ts_txn_id'])->where('ts_portfolio_id','not like','ZH%')->limit(1)->get();
                    $buy_fund = YingmiWalletFund::where('id','>',0)->first();
                    foreach($orders as $row){
                        $chargeTxnId = TsTxnId::makeFundTxnId($new_plan['ts_txn_id']);
                        $sub = [
                        'ts_txn_id' => $chargeTxnId,
                        'ts_uid' => $plan['ts_uid'],
                        'ts_portfolio_id' => $row['ts_portfolio_id'],
                        'ts_portfolio_txn_id' => $new_plan['ts_txn_id'],
                        'ts_fund_code' => $buy_fund['yw_fund_code'],
                        'ts_fund_name' => $buy_fund['yw_fund_name'],
                        'ts_trade_type' => 98,
                        'ts_trade_status' => 6,
                        'ts_placed_amount' => round($del-$add,2),
                        'ts_placed_share' => round($del-$add,2),
                        'ts_acked_amount' => round($del-$add,2),
                        'ts_acked_share' => round($del-$add,2),
                        'ts_pay_method' => $pay,
                        'ts_accepted_at' => $dt,
                        'ts_scheduled_at' => $dt,
                        'ts_placed_date' => $dt->toDateString(),
                        'ts_placed_time' => $dt->toTimeString(),
                        'ts_acked_date' => $dt->toDateString(),
                        'ts_origin' => 8,
                        ];
                        $m = new TsOrderFund($sub);
                        $m->save();
                    }
                }
            }
        }
    }

    public static function SrcDst2StrategyWithOutPlaced($txnId){
        $fundPlan = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_status',1)->get();
        $strategy = ['add'=>[],'del'=>[]];
        foreach($fundPlan as $row){
            $change = $row['ts_amount_dst']-$row['ts_amount_src'];
            if($change >0){
                    $tmp = ['code'=>$row['ts_fund_code'],'amount'=>round(abs($change)-$row['ts_amount_placed'],2),'pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id']];
                    $strategy['add'][] = $tmp;
            }elseif($change <0){
                    $tmp = ['code'=>$row['ts_fund_code'],'amount'=>round($row['ts_amount_placed']-abs($change),2),'pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id']];
                    $strategy['del'][] = $tmp;
            }
        }
        return $strategy;
    }

    public static function importBlackList($uid,$black_list){
        foreach($black_list as $code){
            $black = TsBlackList::where('ts_uid',$uid)->where('ts_fund_code',$code)->first();
            if(!$black){
                $fund = FundInfos::where('fi_code',$code)->first();
                TsBlackList::insert(['ts_uid'=>$uid,'ts_fund_code'=>$code,'ts_fund_name'=>$fund['fi_name'],'ts_company_id'=>$fund['fi_company_id'],'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
            }
        }
    }
    
    //修复调仓中反转问题:反转如购买后调仓，然后撤销购买，或者调仓中的调仓，第一次调仓中的某个基金购买失败，这种发生在调仓前的状态出现反转则调用，反转分两种层面，第一层面为大的订单反转，如撤单，第二层面是单个基金出现问题(所有正在确认中的最终失败了就算)，算作反转
    public static function  fixPlan2Reversal($txnId){
        $plan = TsPlan::where('ts_txn_id',$txnId)->where('ts_type',3)->orderBy('id','DESC')->first();
        if(!$plan){
            return [30000,'the txnId '.$txnId.' is not the adjust plan'];
        }
        $balance = TsPlanBalance::where('ts_aborted_txn_id',$txnId)->first();         
        if($balance){
            return [30001,'the txnId '.$txnId.' is not the of the adjust plan chain'];
        }
        $balance = TsPlanBalance::where('ts_txn_id',$txnId)->first();         
        if($balance && $balance['ts_aborted_txn_id']){
            $txnIds = []; 
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
            $del_orders = TsOrderFund::whereIn('ts_portfolio_txn_id',$txnIds)->where('ts_trade_type',64)->whereIn('ts_trade_status',[0,1,5,6])->get();
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
            $add_orders = TsOrderFund::whereIn('ts_portfolio_txn_id',$txnIds)->where('ts_trade_type',63)->whereIn('ts_trade_status',[0,1,5,6])->get();
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
                $src = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$pay_method)->sum('ts_amount_src'); 
                $dst = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$pay_method)->sum('ts_amount_dst'); 
                if(isset($add_amount[$pay_method])){
                    if($del-$add_amount[$pay_method] > ($dst-$src)){
                        $balances[$pay_method] =  $del-$add_amount[$pay_method] - ($dst-$src);
                    }
                }else{
                    if($del > ($dst-$src)){
                        $balances[$pay_method] =  $del - ($dst-$src);
                    }
                }
            }
            print_r($balances);
            $blacks = TsPlanFund::where('ts_txn_id',$plan['ts_txn_id'])->get();
            $black_list = [];
            foreach($blacks as $row){
                if($row['ts_amount_src'] > $row['ts_amount_dst']){
                    $black_list[] = $row['ts_fund_code'];
                }
            }
            print_r($black_list);
            foreach($balances as $pay_method=>$amount){
                $order = TsOrder::where('ts_txn_id',$plan['ts_txn_id'])->where('ts_portfolio_id','not like','ZH%')->first();
                
                $result = TshelperZxb::makeBuyPlan($plan['ts_uid'], $order['ts_portfolio_id'], $pay_method, $amount, $plan['ts_risk'],null,$black_list);
                print_r($result);
                if($result && isset($result[2]) && isset($result[2]['list'])){
                    $tmp_amount = 0;
                    foreach($result[2]['list'] as $row){
                        $tmp_amount += $row['amount'];
                    }
                    $change_amount = 0;
                    if(abs($amount - $tmp_amount) > 1 && $amount > $tmp_amount){
                        $change_amount = $amount-$tmp_amount;
                    }
                    foreach($result[2]['list'] as $row){
                        $fund = TsPlanFund::where('ts_txn_id',$plan['ts_txn_id'])->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$pay_method)->where('ts_fund_code',$row['code'])->first();
                        if($fund){
                            $fund->ts_amount_dst = $fund['ts_amount_dst'] + $row['amount'] + $change_amount*$row['ratio'];
                            $fund->ts_status = 1;
                            $fund->save();
                        }else{
                            $sub = ['ts_txn_id'=>$plan['ts_txn_id'],'ts_uid'=>$plan['ts_uid'],'ts_portfolio_id'=>$order['ts_portfolio_id'],'ts_pay_method'=>$pay_method,'ts_fund_code'=>$row['code'],'ts_amount_src'=>0,'ts_amount_dst'=>($row['amount'] + $change_amount*$row['ratio']),'ts_status'=>1];
                            $m = new TsPlanFund($sub);
                            $m->save();
                        }
                    }
                }
            }
            if($plan['ts_status'] == 5){
                TsPlan::where('ts_txn_id',$txnId)->where('ts_type',3)->update(['ts_status'=>1]);
            }
        }
    }
}
