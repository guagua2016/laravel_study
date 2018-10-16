<?php namespace App\Libraries\TradeSdk;

use Log;
use App\Libraries\TradeSdk\Strategy\FundPoolHelper;
use App\Libraries\TradeSdk\Strategy\AssetAllocationHelper;
use App\Libraries\TradeSdk\Strategy\AssetStrategyHelper;
use App\Libraries\TradeSdk\Strategy\FundStrategyHelper;
use App\Libraries\TradeSdk\Strategy\MatchmakingHelper;
use App\Libraries\TradeSdk\Strategy\UserHelper;
use App\MfDeviationPool;
use DB;
use File;
use App\TsOrder;
use App\TsOrderFund;
use App\TsBlackList;
use App\UserRiskAnalyzeResult;
use App\TsPlan;
use App\TsPlanFund;
use App\TsPlanBalance;
use App\TsPlanBalanceOrder;
use App\TsPlanTmp;
use App\TsPlanFundTmp;
use App\TsWalletShare;
use Carbon\Carbon;
use App\FundFee;
use App\FundInfos;
use App\TsTxnId;
use App\Libraries\TradeDate;
use App\YingmiWalletFund;
use App\YingmiWalletShareDetail;
use App\YingmiPaymentMethod;
use App\YingmiPaymentProviders;
use App\TsHoldingFund;
use Artisan;

class TsHelperZxb {
    use TradeDate;

    function __construct()
    {
    }

    public static function getUserRiskValue($uid)
    {
        $row = TsOrder::where('ts_uid',$uid)
            // ->whereIn('ts_trade_type',[3])
             ->whereIn('ts_trade_type',[3,6]) //上面那行应该这样写
             ->whereNotIn('ts_trade_status',[-3,-2,-1,7, 9])
             ->whereRaw("ts_portfolio_id not like 'ZH%'") //应该添加这一行
             ->orderBy('id', 'DESC')
             ->orderBy('ts_txn_id','DESC')
             ->first();
        if($row && $row['ts_risk']!=0){
            return (float)round($row['ts_risk'],1);
        }else{
            $m = UserRiskAnalyzeResult::where('ur_uid', $uid)
                ->where('ur_risk', '>', 0)
                ->orderBy('updated_at', 'DESC')
                ->first();
            if($m){
                // $final_risk = (float)round($m->ur_risk,1);
                $final_risk = ceil($m->ur_risk * 10) / 10.0;
            }else{
                $m = UserRiskAnalyzeResult::where('ur_uid', $uid)
                    ->orderBy('updated_at', 'DESC')
                    ->first();
                if ($m) {
                    $final_risk = (float)round($m->ur_assign_risk,1);
                } else {
                    $final_risk = 0.5;
                }
            }
            return $final_risk;
        }
    }

    /**
     * @param $uid    用户ID
     * @param $portfolioId 组合ID
     * @param $paymethod 支付方式
     * @param $amount 下单金额
     * @param $risk   下单风险值
     *
     * @return [true/fase, message, txnId]
     */
    public static function makeBuyPlan($uid, $portfolioId, $paymethod, $amount, $risk = null, $invest_plan=null,$add_black_list=null,$pay_type=0)
    {
        // todo add holding=false logic
        if($risk==null){
            $risk = self::getUserRiskValue($uid);
        }
        if($portfolioId == -1 && $uid != '' && $uid != '-1'){
            Log::error('TsHelperZxb is error:'.$uid.' makeBuyPlan portfolioId=-1');
        }
        /**
        if($amount<500){
            return [30000, 'Failed:amount < 500', []];
        }
        **/
        $risk = (float)$risk;
        $asset = new AssetAllocationHelper();
        $position = $asset->getAssetAllocation($risk);
        $fund_pool = new FundPoolHelper();
        $black_list = self::getBlackList($uid);
        if($add_black_list){
            $black_list = array_merge($black_list, $add_black_list);
        }
        $fund_pool->setBlackList($black_list);
        $fund_list = $fund_pool->getFundPool();
        //$holding = UserHelper::getHolding($uid,$portfolioId);
        $holding = self::getLastHolding($uid);
        $hold = UserHelper::mergeHolding($holding,$fund_list,$amount);
        $tags = UserHelper::setTag($fund_list,$hold);
        $asset_strategy = new AssetStrategyHelper($hold);
        $asset_strategy->setPosition($position);
        if($uid){
            if(self::getAdjustStatus($uid)){
                //$ratio = $asset_strategy->buyAsset($amount);
                $ratio = $asset_strategy->additionalAsset($amount);
            }else{
                $ratio = $asset_strategy->additionalAsset($amount);
            }
        }else{
            $ratio = $asset_strategy->buyAsset($amount);
        }
        $fund_strategy = new FundStrategyHelper();
        $fund_strategy->setTag($tags);
        $fund_strategy->setHolding($holding);
        $fund_strategy->setHold($hold);
        $fund_strategy->setFundList($fund_list);

        // 如果是盈米宝购买，不使用paymethod， 而是从盈米宝中获取
        if ($pay_type == 1) {
            $walletPayment = TsWalletShare::getBuyList($uid, $amount);
            if (!$walletPayment) {
                return [20404, '盈米宝余额不足', []];
            }

            $fund_strategy->setPayMethod($walletPayment);
        } else {
            $fund_strategy->setPayMethod([$paymethod=>$amount]);
        }

        $strategy = $fund_strategy->strategy($ratio);
        $new_add_strategy = [];
        foreach($strategy['add'] as $row){
            $row['portfolio_id'] = $portfolioId;
            $new_add_strategy[] = $row;
        }
        $strategy['add'] = $new_add_strategy;
        //$score_src_re  = AssetStrategyHelper::checkDeviation($holding,$fund_list,$position);
        //$score_src = $score_src_re['percent'];
        $deviation = MfDeviationPool::where('mf_uid',$uid)->orderBy('id','DESC')->first();
        if($deviation){
            $score_src = $deviation['mf_percent'];
        }else{
            $score_src_re  = AssetStrategyHelper::checkDeviationNew($holding,$fund_list,$position,$risk);
            $score_src = $score_src_re['percent'];
        }
        $new_holding = UserHelper::doing($holding,$strategy);
        $new_hold = UserHelper::mergeHolding($new_holding,$fund_list);
        $score_dst_re  = AssetStrategyHelper::checkDeviationNew($new_holding,$fund_list,$position,$risk);
        $score_dst = $score_dst_re['percent'];
        $cost = 0;
        $old_cost = 0;
        $list = [];
        $all_ratio = 0;
        foreach($strategy['add'] as $row){
            $cost += $row['cost'];
            $old_cost += $row['old_cost'];
            $type = -1;
            if(isset($fund_list['fund_type'][$row['code']])){
                $type = $fund_list['fund_type'][$row['code']];
                $type = $fund_list['pool_type'][$type];
            }
            $list[] = ['code'=>$row['code'],'amount'=>$row['amount'],'ratio'=>round($row['amount']/$amount,4), 'type'=>$type]; //todo zxb add type
            $all_ratio += round($row['amount']/$amount,4);
        }
        if($list && $all_ratio!=1){
            $list[0]['ratio'] += (1-$all_ratio);
        }
        if($score_dst<0.0001){
            $score_dst = 0;
        }
        if($uid){
            $plan = ['ts_uid'=>$uid,'ts_risk'=>$risk,'ts_type'=>1,'ts_amount'=>$amount,'ts_fee'=>$cost,'ts_pay_type'=>$pay_type];
            $fundPlan = self::mergeSrc2Dst($hold['pay_code'],$new_hold['pay_code']);
            $planId = self::setPlan($plan,$fundPlan);
        }else{
            $planId = null;
        }
        $data = [
            'id'=>$planId,
            'fee'=>$cost,
            'old_fee'=>$old_cost,
            'score_src'=>floor(100-$score_src*100),
            'score_dst'=>floor(100-$score_dst*100),
            'list'=>$list
        ];

        return [20000, 'Succeed', $data];
    }

    public static function makeRedeemPlan($uid, $portfolioId, $percent, $pay_type=0){
        $risk = self::getUserRiskValue($uid);
        $risk = (float)$risk;
        $asset = new AssetAllocationHelper();
        $position = $asset->getAssetAllocation($risk);
        $fund_pool = new FundPoolHelper();
        $black_list = self::getBlackList($uid);
        $fund_pool->setBlackList($black_list);
        $fund_list = $fund_pool->getFundPool();
        $holding = UserHelper::getHolding($uid,$portfolioId);
        $hold = UserHelper::mergeHolding($holding,$fund_list);
        $tags = UserHelper::setTag($fund_list,$hold);
        $asset_strategy = new AssetStrategyHelper($hold);
        $asset_strategy->setPosition($position);
        if(self::getAdjustStatus($uid)){
            return [30000, '正在调仓中,无法赎回', []];
        }else{
            $ratio = $asset_strategy->redemptionAsset($percent);
        }
        $fund_strategy = new FundStrategyHelper();
        $fund_strategy->setTag($tags);
        $fund_strategy->setHolding($holding);
        $fund_strategy->setHold($hold);
        $fund_strategy->setFundList($fund_list);
        $strategy = $fund_strategy->strategy($ratio);
        //是否有持有小于7天的非货币基金
        $les7days = $fund_strategy->les7days;
        $new_holding = UserHelper::doing($holding,$strategy);
        $new_hold = UserHelper::mergeHolding($new_holding,$fund_list);
        $cost = 0;
        $list = [];
        $pay_method_list = [];
        foreach($strategy['del'] as $row){
            $cost += $row['cost'];
            $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
            $amount_total = $hold['pay_code'][$code];
            if(abs(round($row['amount'],2)-$amount_total)<0.01){
                $del_amount = round($amount_total,2);
            }else{
                $del_amount = round($row['amount'],2) ;
            }
            if(isset($pay_method_list[$row['pay_method']])){
                $pay_method_list[$row['pay_method']] += $del_amount;
            }else{
                $pay_method_list[$row['pay_method']] = $del_amount;
            }
            $list[] = ['code'=>$row['code'],'amount'=>$del_amount,'amount_total'=>$amount_total];
        }
        if($uid){
            $plan = ['ts_uid'=>$uid,'ts_risk'=>$risk,'ts_type'=>2,'ts_percent'=>$percent,'ts_fee'=>$cost,'ts_pay_type'=>$pay_type];
            $fundPlan = self::mergeSrc2Dst($hold['pay_code'],$new_hold['pay_code']);
            $planId = self::setPlan($plan,$fundPlan);
        }
        $pay_list = [];
        foreach($pay_method_list as $pay_method=>$pay_amount){
            $arr = explode(":",$pay_method);
            if(isset($arr[1])){
                $tmp = YingmiPaymentMethod::where('yp_payment_method_id',$arr[1])->first();
                $bank= YingmiPaymentProviders::where('yp_payment_type',$tmp['yp_payment_type'])->first();
                $bank_name = '卡号';
                if($bank){
                    $bank_name = $bank['yp_name'];
                }
                $str = $bank_name.'尾号('.substr($tmp['yp_payment_no'],-4).')预期金额为'.$pay_amount.'元';
                $pay_list[] = $str;
            }
        }
        $data = [
            'id'=>$planId,
            'fee'=>$cost,
            'old_fee'=>$cost,
            'list'=>$list,
            'pay_list'=>$pay_list,
            'seven_days'=>$les7days,
        ];
        return [20000, 'Succeed', $data];
    }

    public static function makeAdjustPlan($uid, $portfolioId){
        $risk = self::getUserRiskValue($uid);
        $risk = (float)$risk;
        $asset = new AssetAllocationHelper();
        $position = $asset->getAssetAllocation($risk);
        $fund_pool = new FundPoolHelper();
        $abord_id = self::getAdjustStatus($uid);
        if($abord_id){
            $without_list = self::SrcDst2Completed($abord_id);
        }else{
            $without_list = [];
        }
        $black_list = self::getBlackList($uid);
        $fund_pool->setBlackList($black_list);
        $fund_list = $fund_pool->getFundPool();
        $holding = UserHelper::getHolding($uid,$portfolioId);
        $hold = UserHelper::mergeHolding($holding,$fund_list);
        $tags = UserHelper::setTag($fund_list,$hold);
        $asset_strategy = new AssetStrategyHelper($hold);
        $asset_strategy->setPosition($position);
        $ratio = $asset_strategy->reallocationAsset();
        $fund_strategy = new FundStrategyHelper();
        $fund_strategy->setTag($tags);
        $fund_strategy->setHolding($holding);
        $fund_strategy->setHold($hold);
        $fund_strategy->setFundList($fund_list);
        if($without_list){
            $fund_strategy->setWithoutList($without_list);
        }
        $strategy = $fund_strategy->strategy($ratio);
        //是否有持有小于7天的非货币基金
        $les7days = $fund_strategy->les7days;
        $new_add_strategy = [];
        foreach($strategy['add'] as $row){
            $row['portfolio_id'] = $portfolioId;
            $new_add_strategy[] = $row;
        }
        $strategy['add'] = $new_add_strategy;
        $new_holding = UserHelper::doing($holding,$strategy);
        $new_hold = UserHelper::mergeHolding($new_holding,$fund_list);
        $cost = 0;
        $old_cost = 0;
        foreach($strategy['del'] as $row){
            $cost += $row['cost'];
            $old_cost += $row['cost'];
        }
        foreach($strategy['add'] as $row){
            $cost += $row['cost'];
            $old_cost += $row['old_cost'];
        }
        if($uid){
            $plan = ['ts_uid'=>$uid,'ts_risk'=>$risk,'ts_type'=>3,'ts_fee'=>$cost];
            $fundPlan = self::mergeSrc2Dst($hold['pay_code'],$new_hold['pay_code']);
            $planId = self::setPlan($plan,$fundPlan);
        }
        $change_list = [];
        foreach($fundPlan as $row){
            if(isset($change_list[$row['code']])){
                $change_list[$row['code']]['src'] += $row['src'];
                $change_list[$row['code']]['dst'] += $row['dst'];
            }else{
                $change_list[$row['code']] = ['src'=>$row['src'],'dst'=>$row['dst']];
            }
        }
        $list = [];
        $all_ratio = 0;
        foreach($change_list as $code=>$row){
            if($row['src'] ==0 && $row['dst'] == 0)continue;
            $all_ratio += round($row['dst']/$hold['amount'],4);
            $list[] = ['code'=>$code,'amount_src'=>round($row['src'],2),'amount_dst'=>round($row['dst'],2),'ratio'=>round($row['dst']/$hold['amount'],4)];
        }

        if($list && $all_ratio!=1){
            $list[0]['ratio'] += (1-$all_ratio);
        }
        $data = [
            'id'=>$planId,
            'fee'=>$cost,
            'old_fee'=>$old_cost,
            'list'=>$list,
            'seven_days'=>$les7days,
        ];

        return [20000, 'Succeed', $data];
    }

    /**
     * @param $planId 购买计划ID
     * @param $uid    用户ID
     * @param $portfolioId 组合ID
     * @param $paymethod 支付方式
     * @param $amount 下单金额
     *
     * @return [true/fase, message, txnId]
     */
    public static function placeBuyOrder($planId, $uid, $portfolioId, $paymethod, $amount, $risk = null, $invest_plan=null,$flag=false,$fr=1) {
        $txnId = self::executePlan($uid,$planId);
        if($txnId){
            $plan = TsPlan::where('ts_txn_id',$txnId)->first();
            $pay_type = $plan->ts_pay_type;
            if($plan){
                DB::connection('mysql')->beginTransaction();
                try{
                    $dt = Carbon::now();
                    $fundPlan = self::SrcDst2Strategy($txnId);

                    if(isset($fundPlan['add']) && empty($fundPlan['add'])){
                        throw new \Exception("failed");
                    }

                    $multiOrders = collect($fundPlan['add']);
                    $multiOrders = $multiOrders->groupBy('pay_method');

                    $buy_fund = YingmiWalletFund::where('id','>',0)->first();
                    $plan_fee_i = 1;

                    $chargeSubOrders = [];
                    $balancePayments = [];
                    foreach ($multiOrders as $payment => $multiOrder) {
                        $tsAmount = $multiOrder->sum('amount');
                        $balancePayments[$payment] = [
                            'status' => 1,
                            'amount' => $tsAmount,
                        ];

                        if ($plan_fee_i == 1) {
                            $plan_fee = self::getPlanFee($uid,$planId);
                        } else {
                            $plan_fee = 0;
                        }
                        $plan_fee_i = 0;

                        $order = [
                            'ts_txn_id' => $txnId,
                            'ts_uid' => $uid,
                            'ts_portfolio_id' => $portfolioId,
                            'ts_pay_method' => $payment,
                            'ts_trade_type' => 3,
                            'ts_trade_status' => 0,
                            'ts_placed_amount' => $tsAmount,
                            'ts_placed_percent' => 0,
                            'ts_accepted_at' => $dt,
                            'ts_scheduled_at' => $dt,
                            'ts_pay_type' => $pay_type,
                            'ts_pos_term' => $fr,
                            'ts_origin' => 8,
                            'ts_risk' => $plan['ts_risk'],
                            'ts_placed_fee' => $plan_fee,
                        ];
                        if(!empty($invest_plan)){
                            $order['ts_trade_type'] = 5;
                            $order['ts_invest_plan_id'] = $invest_plan;
                        }

                        // 保存订单
                        // dd($order, $suborders);
                        //
                        // 先将订单数据写入文件
                        //
                        static::writeTsOrderToFile($order);
                        //
                        // 然后再写入数据库
                        //
                        $m = new TsOrder($order);
                        $m->save();

                        $chargeTxnId = TsTxnId::makeFundTxnId($txnId);
                        if ($pay_type == 0) {
                            $chargeType = 10;
                            $chargeStatus = 0;
                        } else {
                            $chargeType = 19;
                            $chargeStatus = 0;
                        }
                        $chargeSubOrder = [
                            'ts_txn_id' => $chargeTxnId,
                            'ts_uid' => $uid,
                            'ts_portfolio_id' => $portfolioId,
                            'ts_portfolio_txn_id' => $txnId,
                            'ts_fund_code' => $buy_fund['yw_fund_code'],
                            'ts_fund_name' => $buy_fund['yw_fund_name'],
                            'ts_trade_type' => $chargeType,
                            'ts_trade_status' => $chargeStatus,
                            'ts_placed_amount' => $tsAmount,
                            'ts_placed_share' => 0,
                            'ts_pay_method' => $payment,
                            'ts_accepted_at' => $dt,
                            'ts_scheduled_at' => $dt,
                            'ts_origin' => 8,
                            'ts_placed_fee' =>0,
                        ];
                        if(!empty($invest_plan)){
                            $chargeSubOrder['ts_invest_plan_id'] = $invest_plan;
                        }
                        if($flag){
                            $chargeSubOrder['ts_trade_type'] = 98;
                            $chargeSubOrder['ts_trade_status'] = 6;
                        }

                        $chargeSubOrders[] = $chargeSubOrder;
                    }

                    if ($pay_type == 0) {
                        $fundPlan = MatchmakingHelper::match($uid,$txnId,$portfolioId,$fundPlan,[$paymethod=>['status'=>1,'amount'=>$plan['ts_amount']]]);
                    } else {
                        $fundPlan = MatchmakingHelper::match($uid,$txnId,$portfolioId,$fundPlan,$balancePayments);
                    }
                    if(isset($fundPlan['add']) && empty($fundPlan['add'])){
                        throw new \Exception("failed");
                    }
                    if(!empty($invest_plan)){
                        $fund_orders = self::getFundOrder($uid,$txnId,$fundPlan,$dt,2,$invest_plan, $pay_type);
                    }else{
                        $fund_orders = self::getFundOrder($uid,$txnId,$fundPlan,$dt,false,false,$pay_type);
                    }

                    $suborders = $fund_orders['order'];
                    $balance_orders = $fund_orders['balance_order'];

                    foreach($balance_orders as $sub){
                        $m = new TsPlanBalanceOrder($sub);
                        $m->save();
                    }

                    foreach ($chargeSubOrders as $chargeSubOrder) {
                        array_unshift($suborders, $chargeSubOrder);
                    }
                    foreach ($suborders as $sub) {
                        //
                        // 先将订单数据写入文件
                        //
                        static::writeTsOrderFundToFile($sub);
                        //
                        // 然后再写入数据库
                        //
                        $m = new TsOrderFund($sub);
                        $m->save();
                    }
                    self::executePlanFund($txnId,$fundPlan);
                    DB::connection('mysql')->commit();
                } catch(\Exception $e) {
                    // echo  $e->getMessage().$e->getTraceAsString();
                    Log::error('TsHelperZxb is error:'.'placeBuyOrder('.$planId.','.$uid.','.$portfolioId.','.$paymethod.','.$amount.','.$risk.','.$invest_plan.','.$flag.')'.$e->getMessage().$e->getTraceAsString());
                    DB::connection('mysql')->rollBack();
                    TsPlan::where('ts_txn_id',$txnId)->update(['ts_status'=>-1]);
                    return [30000, '订单错误', ''];
                }
            }
            return [20000, 'Succeed', $txnId];
        }else{
            return [30000, '该计划已存在', ''];
        }
    }

    /**
     * @param $planId 赎回计划ID
     * @param $uid    用户ID
     * @param $portfolioId 组合ID
     * @param $percent  赎回比例
     *
     * @return [true/fase, message, txnId]
     */
    public static function placeRedeemOrder($planId, $uid, $portfolioId, $percent,$fr=1) {
        $txnId = self::executePlan($uid,$planId);
        if($txnId){
            $plan = TsPlan::where('ts_txn_id',$txnId)->first();
            $pay_type = $plan->ts_pay_type;
            if($plan){
                DB::connection('mysql')->beginTransaction();
                try{
                    $dt = Carbon::now();
                    $fundPlan = self::SrcDst2Strategy($txnId);
                    $po_pay = [];
                    foreach($fundPlan['del'] as $row){
                        $key = $row['pay_method']."|".$row['portfolio_id'];
                        if(!isset($po_pay[$key])){
                            $po_pay[$key] = ['del'=>[],'add'=>[]];
                            $po_pay[$key]['del'][] = $row;
                        }else{
                            $po_pay[$key]['del'][] = $row;
                        }
                    }
                    $plan_fee_i = 0;
                    foreach($po_pay as $key=>$list){
                        $tmp = explode('|',$key);
                        $pay_method = $tmp[0];
                        $portfolio_id= $tmp[1];
                        if($plan_fee_i==0){
                            $plan_fee = self::getPlanFee($uid,$planId);
                        }else{
                            $plan_fee = 0;
                        }
                        $plan_fee_i++;
                        $plan_amount = self::getDelPlanAmount($uid,$planId,$portfolioId,$pay_method);
                        $order = [
                            'ts_txn_id' => $txnId,
                            'ts_uid' => $uid,
                            'ts_portfolio_id' => $portfolio_id,
                            'ts_pay_method' => $pay_method,
                            'ts_trade_type' => 4,
                            'ts_trade_status' => 0,
                            'ts_placed_amount' => $plan_amount,
                            'ts_placed_percent' => $plan['ts_percent'],
                            'ts_accepted_at' => $dt,
                            'ts_scheduled_at' => $dt,
                            'ts_pay_type' => $pay_type,
                            'ts_pos_term' => $fr,
                            'ts_origin' => 8,
                            'ts_risk' => $plan['ts_risk'],
                            'ts_placed_fee' => $plan_fee,
                            ];
                        static::writeTsOrderToFile($order);
                        $m = new TsOrder($order);
                        $m->save();
                    }
                    $yingmi_orders = self::executePlanFundYingmi($uid,$txnId,$fundPlan);
                    $fundPlan = MatchmakingHelper::match($uid,$txnId,$portfolioId,$fundPlan,[-1=>['status'=>1,'amount'=>0]]);
                    $fund_orders = self::getFundOrder($uid,$txnId,$fundPlan,$dt, false, false, $pay_type);
                    $suborders = $fund_orders['order'];
                    $balance_orders = $fund_orders['balance_order'];
                    foreach ($suborders as $sub) {
                        static::writeTsOrderFundToFile($sub);
                        $m = new TsOrderFund($sub);
                        $m->save();
                    }
                    foreach($yingmi_orders as $sub){
                        $m = new TsPlanBalanceOrder($sub);
                        $m->save();
                    }

                    //if ($pay_type == 1) {
                    //    $multiOrders = collect($suborders);
                    //    $multiOrders = $multiOrders->groupBy('ts_pay_method');
                    //    $redeem_fund = YingmiWalletFund::where('id','>',0)->first();

                    //    foreach ($multiOrders as $payment => $multiOrder) {
                    //        // 新增赎回到盈米宝订单
                    //        $walletOrderId = TsTxnId::makeFundTxnId($txnId);
                    //        $tsAmount = $multiOrder->sum('ts_placed_amount');
                    //        $walletOrder = [
                    //            'ts_txn_id' => $walletOrderId,
                    //            'ts_uid' => $uid,
                    //            'ts_portfolio_id' => $portfolioId,
                    //            'ts_portfolio_txn_id' => $txnId,
                    //            'ts_fund_code' => $redeem_fund['yw_fund_code'],
                    //            'ts_fund_name' => $redeem_fund['yw_fund_name'],
                    //            'ts_trade_type' => 42,
                    //            'ts_trade_status' => 0,
                    //            'ts_placed_amount' => $tsAmount,
                    //            'ts_placed_share' => $tsAmount,
                    //            'ts_pay_method' => $payment,
                    //            'ts_accepted_at' => $dt,
                    //            'ts_scheduled_at' => $dt,
                    //            'ts_origin' => 8,
                    //            'ts_placed_fee' => 0,
                    //        ];

                    //        static::writeTsOrderFundToFile($walletOrder);
                    //        $wallet = new TsOrderFund($walletOrder);
                    //        $wallet->save();
                    //    }
                    //}

                    foreach($balance_orders as $sub){
                        $m = new TsPlanBalanceOrder($sub);
                        $m->save();
                    }
                    self::executePlanFund($txnId,$fundPlan);
                    DB::connection('mysql')->commit();
                } catch(\Exception $e) {
                    // echo  $e->getMessage().$e->getTraceAsString();
                    Log::error('TsHelperZxb is error:'.'placeRedeemOrder('.$planId.', '.$uid.', '.$portfolioId.', '.$percent.')'.$e->getMessage().$e->getTraceAsString());
                    DB::connection('mysql')->rollBack();
                }
            }
            return [20000, 'Succeed', $txnId];
        }else{
            return [30000, '该计划已存在', ''];
        }
    }

    /**
     * @param $planId 调仓计划ID
     * @param $uid    用户ID
     * @param $portfolioId 组合ID
     *
     * @return [true/fase, message, txnId]
     */
    public static function placeAdjustOrder($planId, $uid, $portfolioId,$fr=1) {
        //if(self::getAdjustStatus($uid)){
        //    return [30000, '正在调仓中,无法再调仓', []];
        //}
        $plan = TsPlan::where('ts_uid',$uid)->where('ts_type',3)->where('ts_status',1)->orderBy('id','DESC')->first();
        if($plan && abs(strtotime($plan['ts_start_at']) - time())<3600){
            return [30000, '正在调仓中,无法再调仓', []];
        }
        $txnId = self::executePlan($uid,$planId);
        if($txnId){
            $plan = TsPlan::where('ts_txn_id',$txnId)->first();
            if($plan){
                DB::connection('mysql')->beginTransaction();
                try{
                    $dt = Carbon::now();
                    $fundPlan = self::SrcDst2Strategy($txnId);
                    $po_pay = [];
                    foreach($fundPlan['del'] as $row){
                        $key = $row['pay_method']."|".$row['portfolio_id'];
                        if(!isset($po_pay[$key])){
                            $po_pay[$key] = ['del'=>[],'add'=>[]];
                            $po_pay[$key]['del'][] = $row;
                        }else{
                            $po_pay[$key]['del'][] = $row;
                        }
                    }
                    foreach($fundPlan['add'] as $row){
                        $key = $row['pay_method']."|".$row['portfolio_id'];
                        if(!isset($po_pay[$key])){
                            $po_pay[$key] = ['del'=>[],'add'=>[]];
                            $po_pay[$key]['add'][] = $row;
                        }else{
                            $po_pay[$key]['add'][] = $row;
                        }
                    }
                    $plan_fee_i = 0;
                    foreach($po_pay as $key=>$list){
                        $tmp = explode('|',$key);
                        $pay_method = $tmp[0];
                        $portfolio_id= $tmp[1];
                        if($plan_fee_i==0){
                            $plan_fee = self::getPlanFee($uid,$planId);
                        }else{
                            $plan_fee = 0;
                        }
                        $plan_fee_i++;
                        $order = [
                            'ts_txn_id' => $txnId,
                            'ts_uid' => $uid,
                            'ts_portfolio_id' => $portfolio_id,
                            'ts_pay_method' => $pay_method,
                            'ts_trade_type' => 6,
                            'ts_trade_status' => 0,
                            'ts_placed_amount' => 0,
                            'ts_placed_percent' => 0,
                            'ts_accepted_at' => $dt,
                            'ts_scheduled_at' => $dt,
                            'ts_pos_term' => $fr,
                            'ts_origin' => 8,
                            'ts_risk' => $plan['ts_risk'],
                            'ts_placed_fee' => $plan_fee,
                            ];
                        if(strpos($portfolio_id,'ZH')!==false){
                            $order['ts_trade_type'] = 8;
                            $order['ts_placed_percent'] = 1;
                        }
                        static::writeTsOrderToFile($order);
                        $m = new TsOrder($order);
                        $m->save();
                    }
                    $yingmi_orders = self::executePlanFundYingmi($uid,$txnId,$fundPlan);
                    $balances = TsPlanBalance::where('ts_txn_id',$txnId)->get();
                    $new_balances = [];
                    foreach($balances as $row){
                        $new_balances[$row['ts_pay_method']] = ['status'=>$row['ts_status'],'amount'=>$row['ts_amount_avail']];
                        if($row['ts_amount_aborted'] !=0 && $row['ts_aborted_txn_id']){
                            $aborted_order = [];
                            $subTxnId = TsTxnId::makeFundTxnId($txnId);
                            $aborted_order['ts_txn_id'] = $subTxnId;
                            $aborted_order['ts_uid'] = $uid;
                            $aborted_order['ts_portfolio_id'] = $portfolio_id;
                            $aborted_order['ts_fund_code'] = '001826';
                            $aborted_order['ts_fund_name'] = '国寿安保增金宝货币';
                            $aborted_order['ts_pay_method'] = $row['ts_pay_method'];
                            $aborted_order['ts_trade_type'] = 98;
                            $aborted_order['ts_trade_status'] = 6;

                            $aborted_order['ts_placed_amount'] = $row['ts_amount_aborted'];
                            $aborted_order['ts_placed_share'] =  $row['ts_amount_aborted'];
                            $aborted_order['ts_acked_amount'] =  $row['ts_amount_aborted'];
                            $aborted_order['ts_acked_share'] =  $row['ts_amount_aborted'];

                            $aborted_order['ts_placed_date'] = date('Y-m-d');
                            $aborted_order['ts_redeem_pay_date'] = date('Y-m-d');
                            $aborted_order['ts_placed_time'] = date('H:i:s');
                            $aborted_order['ts_acked_date'] = date('Y-m-d');
                            $aborted_order['ts_accepted_at'] = date('Y-m-d H:i:s');
                            $aborted_order['ts_scheduled_at'] = date('Y-m-d H:i:s');
                            $aborted_order['ts_portfolio_txn_id'] = $txnId;
                            $aborted_order['ts_origin'] = 8;
                            $aborted_order['ts_audit'] = 1;
                            $m = new TsOrderFund($aborted_order);
                            $m->save();

                            $this_order = [];
                            $tmp_this_order = TsOrder::where('ts_txn_id',$row['ts_aborted_txn_id'])
                                        ->whereRaw("ts_portfolio_id not like 'ZH%'")->first(); //应该添加这一行
                            $subTxnId = TsTxnId::makeFundTxnId($row['ts_aborted_txn_id']);
                            $this_portfolio_id = $tmp_this_order['ts_portfolio_id'];
                            $this_order['ts_txn_id'] = $subTxnId;
                            $this_order['ts_uid'] = $uid;
                            $this_order['ts_portfolio_id'] = $this_portfolio_id;
                            $this_order['ts_fund_code'] = '001826';
                            $this_order['ts_fund_name'] = '国寿安保增金宝货币';
                            $this_order['ts_pay_method'] = $row['ts_pay_method'];
                            $this_order['ts_trade_type'] = 97;
                            $this_order['ts_trade_status'] = 6;

                            $this_order['ts_placed_amount'] = $row['ts_amount_aborted'];
                            $this_order['ts_placed_share'] =  $row['ts_amount_aborted'];
                            $this_order['ts_acked_amount'] =  $row['ts_amount_aborted'];
                            $this_order['ts_acked_share'] =  $row['ts_amount_aborted'];

                            $this_order['ts_placed_date'] = date('Y-m-d');
                            $this_order['ts_redeem_pay_date'] = date('Y-m-d');
                            $this_order['ts_placed_time'] = date('H:i:s');
                            $this_order['ts_acked_date'] = date('Y-m-d');
                            $this_order['ts_accepted_at'] = date('Y-m-d H:i:s');
                            $this_order['ts_scheduled_at'] = date('Y-m-d H:i:s');
                            $this_order['ts_portfolio_txn_id'] = $row['ts_aborted_txn_id'];
                            $this_order['ts_origin'] = 8;
                            $this_order['ts_audit'] = 1;
                            $m = new TsOrderFund($this_order);
                            $m->save();
                        }
                    }
                    $fundPlan = MatchmakingHelper::match($uid,$txnId,$portfolioId,$fundPlan,$new_balances);
                    self::updateBalance($txnId,$fundPlan);
                    $fund_orders = self::getFundOrder($uid,$txnId,$fundPlan,$dt,1);
                    $suborders = $fund_orders['order'];
                    $balance_orders = $fund_orders['balance_order'];
                    foreach ($suborders as $sub) {
                        static::writeTsOrderFundToFile($sub);
                        $m = new TsOrderFund($sub);
                        $m->save();
                    }
                    foreach($yingmi_orders as $sub){
                        $m = new TsPlanBalanceOrder($sub);
                        $m->save();
                    }
                    foreach($balance_orders as $sub){
                        $m = new TsPlanBalanceOrder($sub);
                        $m->save();
                    }
                    self::executePlanFund($txnId,$fundPlan);
                    DB::connection('mysql')->commit();
                } catch(\Exception $e) {
            //         echo  $e->getMessage().$e->getTraceAsString();
                    Log::error('TsHelperZxb is error:'.'placeAdjustOrder('.$planId.', '.$uid.', '.$portfolioId.')'.$e->getMessage().$e->getTraceAsString());
                    DB::connection('mysql')->rollBack();
                }
            }
            return [20000, 'Succeed', $txnId];
        }else{
            return [30000, '该计划已存在', ''];
        }
    }

    /**
     * @param $
     *
     * @return [true/fase, message, txnId]
     */
    public static function cancelOrder($uid,$txnId,$flag=false) {
        $plan = TsPlan::where('ts_txn_id',$txnId)->where('ts_uid',$uid)->where('ts_status','1')->first();
        if($plan){
            $time = self::tradeDatewithTime(date('Y-m-d'), date('H:i:s'), 0);
            $fund = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->get();
            $status = true;
            foreach($fund as $row){
                if($row['ts_trade_date'] != '0000-00-00'){
                    if(strtotime($row['ts_trade_date']) < strtotime($time)){
                        if($flag)continue;
                        $status = false;
                    }
                }
            }
            if($status){
                TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereNotIn('ts_trade_type', [10, 19])->whereIn('ts_trade_status',[0,1])->update(['ts_trade_status'=>7]);
                TsOrder::where('ts_txn_id',$txnId)->update(['ts_trade_status'=>7]);
                TsPlan::where('ts_txn_id',$txnId)->update(['ts_status'=>2]);
                TsPlanFund::where('ts_txn_id',$txnId)->update(['ts_status'=>2]);
                $dt = Carbon::now();
                if($plan['ts_type'] == 1){
                    $orders = TsOrder::where('ts_txn_id',$txnId)->get();
                    $buy_fund = YingmiWalletFund::where('id','>',0)->first();

                    foreach($orders as $row){
                        $chargeTxnId = TsTxnId::makeFundTxnId($txnId);

                        if ($plan['ts_pay_type'] == 0) {
                            $type = 20;
                        } else {
                            $type = 29;
                        }

                        $sub = [
                            'ts_txn_id' => $chargeTxnId,
                            'ts_uid' => $uid,
                            'ts_portfolio_id' => $row['ts_portfolio_id'],
                            'ts_portfolio_txn_id' => $txnId,
                            'ts_fund_code' => $buy_fund['yw_fund_code'],
                            'ts_fund_name' => $buy_fund['yw_fund_name'],
                            'ts_trade_type' => $type,
                            'ts_trade_status' => 0,
                            'ts_placed_amount' => 0,
                            'ts_placed_share' => $row['ts_placed_amount'],
                            'ts_pay_method' => $row['ts_pay_method'],
                            'ts_accepted_at' => $dt,
                            'ts_scheduled_at' => $dt,
                            'ts_origin' => 8,
                        ];
                        if($flag){
                            $sub['ts_trade_type'] = 97;
                            $sub['ts_trade_status'] = 6;
                        }
                        $m = new TsOrderFund($sub);
                        $m->save();
                    }
                }
                return [20000, 'Succeed', ''];
            }else{
                return [30000, '不可撤单', ''];
            }
        }else{
            return [30000, '不可撤单', ''];
        }
    }

    /**
     * @param $portfolioId
     *
     * @return [true/fase, message, txnId]
     */
    public static function getRedeemRatio($uid,$portfolioId) {
        $status = self::getAdjustStatus($uid);
        if($status){
            $data = ['status'=>false,'lower'=>0,'upper'=>0];
            return [20000, 'Succeed',$data];
        }
        $lower = 0.01;
        $upper = 1;
        $holding = UserHelper::getHolding($uid,$portfolioId);
        $fund_pool = new FundPoolHelper();
        $black_list = self::getBlackList($uid);
        $fund_pool->setBlackList($black_list);
        $fund_list = $fund_pool->getFundPool();
        $hold = UserHelper::mergeHolding($holding,$fund_list);
        if(isset($holding['yingmi'])){
            if(isset($hold['merge_yingmi']) && array_sum($hold['merge_yingmi'])/$hold['amount']<=0.2 && array_sum($hold['merge_yingmi'])!=0){
                $lower = round(ceil((array_sum($hold['merge_yingmi'])/$hold['amount'])*100)/100,2);
            }else{
                foreach($holding['yingmi'] as $row){
                    if($row['upper'] < $upper){
                        $upper = $row['upper'];
                    }
                    if($row['lower'] > $lower){
                        $lower = $row['lower'];
                    }
                }
                if($lower > $upper){
                    $lower = 1;
                    $upper = 1;
                }
            }
        }
        if($hold['amount']<1000){
            $lower = 1;
            $upper = 1;
        }
        $data = ['status'=>true,'lower'=>$lower,'upper'=>$upper];
        return [20000, 'Succeed',$data];
    }

    /**
     * @param $txnId 组合订单ID
     *
     * @return [true/fase, message, txnId]
     */
    public static function continueOrder($txnId,$transaction=false) {
        if(!$transaction){
            DB::connection('mysql')->beginTransaction();
        }
        try{
            $ts_plan = TsPlan::where('ts_txn_id',$txnId)->first();
            if($ts_plan && $ts_plan['ts_type'] == 3){
                $orders = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereIn('ts_trade_type',[20,30,31,50,51,61,63,40,41,62,64])->get();
            }else{
                $orders = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereIn('ts_trade_type',[30,31,50,51,61,63,40,41,43,62,64])->get();
            }
            $trade_date = self::tradeDatewithTime(date('Y-m-d'), date('H:i:s'), 0);
            foreach($orders as $order){
                $plan = TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->first();
                if(!$plan){
                    if(strpos($order['ts_portfolio_id'],'ZH')!==false){
                        $yingmi_order = TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->first();
                        if(!$yingmi_order){
                            $old_order = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->first();;
                            $balanceorder = [
                                'ts_txn_id'=>$txnId,
                                'ts_uid'=>$order['ts_uid'],
                                'ts_portfolio_id'=>$order['ts_portfolio_id'],
                                'ts_pay_method'=>$order['ts_pay_method'],
                                'ts_fund_code'=>$order['ts_fund_code'],
                                'ts_txn_order_id'=>$order['ts_txn_id'],
                                'ts_trade_type'=>1,
                                'ts_amount'=>abs($old_order['ts_amount_src'] - $old_order['ts_amount_dst']),
                                'ts_amount_placed'=>abs($old_order['ts_amount_src'] - $old_order['ts_amount_dst']),
                                'ts_amount_acked'=>0,
                                'ts_status'=>0,
                                'created_at'=>date('Y-m-d H:i:s'),
                                'updated_at'=>date('Y-m-d H:i:s'),
                            ];
                            TsPlanBalanceOrder::insert($balanceorder);
                        }
                    }else{
                        $old_order = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->first();;

                        if ($order['ts_trade_type'] == 20) {
                            $old_type = 2;

                            $balanceorder = [
                                'ts_txn_id'=>$txnId,
                                'ts_uid'=>$order['ts_uid'],
                                'ts_portfolio_id'=>$order['ts_portfolio_id'],
                                'ts_pay_method'=>$order['ts_pay_method'],
                                'ts_fund_code'=>$order['ts_fund_code'],
                                'ts_txn_order_id'=>$order['ts_txn_id'],
                                'ts_trade_type'=>$old_type,
                                'ts_amount'=>$order['ts_placed_amount'],
                                'ts_amount_placed'=>$order['ts_placed_amount'],
                                'ts_amount_acked'=>0,
                                'ts_status'=>0,
                                'created_at'=>date('Y-m-d H:i:s'),
                                'updated_at'=>date('Y-m-d H:i:s'),
                            ];

                        } else {
                            if($old_order['ts_amount_src'] > $old_order['ts_amount_dst']){
                                $old_type = 1;
                            }else{
                                $old_type = 0;
                            }

                            $balanceorder = [
                                'ts_txn_id'=>$txnId,
                                'ts_uid'=>$order['ts_uid'],
                                'ts_portfolio_id'=>$order['ts_portfolio_id'],
                                'ts_pay_method'=>$order['ts_pay_method'],
                                'ts_fund_code'=>$order['ts_fund_code'],
                                'ts_txn_order_id'=>$order['ts_txn_id'],
                                'ts_trade_type'=>$old_type,
                                'ts_amount'=>abs($old_order['ts_amount_src'] - $old_order['ts_amount_dst']),
                                'ts_amount_placed'=>abs($old_order['ts_amount_src'] - $old_order['ts_amount_dst']),
                                'ts_amount_acked'=>0,
                                'ts_status'=>0,
                                'created_at'=>date('Y-m-d H:i:s'),
                                'updated_at'=>date('Y-m-d H:i:s'),
                            ];
                        }

                        TsPlanBalanceOrder::insert($balanceorder);
                    }
                }
                $plan = TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->first();
                if($plan){
                    if($order['ts_trade_status'] == 0){
                        continue;
                    }
                    if(in_array($plan['ts_status'],[0,1,3,5])){
                        if($order['ts_trade_status'] == 1){
                            TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>1,'ts_amount_placed'=>DB::raw('ts_amount')]);
                        }elseif(in_array($order['ts_trade_status'],[-3,-2,-1,9])){
                            TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>-1]);
                        }elseif(in_array($order['ts_trade_status'],[6])){
                            if(in_array($order['ts_trade_type'], [62, 64])){
                                if(!empty($order['ts_redeem_pay_date']) && strtotime($trade_date) >= strtotime($order['ts_redeem_pay_date'])){
                                    $ts_acked_amount = $order['ts_acked_amount'] ;
                                    TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>5,'ts_amount_placed'=>DB::raw('ts_amount'),'ts_amount_acked'=>$ts_acked_amount]);
                                }else{
                                    $ts_acked_amount = 0;
                                    TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>1,'ts_amount_placed'=>DB::raw('ts_amount')]);
                                }
                            }else{
                                $ts_acked_amount = $order['ts_acked_amount'] ;
                                TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>5,'ts_amount_placed'=>DB::raw('ts_amount'),'ts_amount_acked'=>$ts_acked_amount]);
                            }
                        }elseif(in_array($order['ts_trade_status'],[5])){
                            if($order['ts_placed_amount'] !=0 ){
                                $ts_placed_amount = $order['ts_placed_amount'];
                            }else{
                                $ts_placed_amount = $plan['ts_amount'];
                            }
                            $placed = (($order['ts_acked_amount'] + $order['ts_acked_share'])/$ts_placed_amount) * $plan['ts_amount'];
                            if($placed > $plan['ts_amount']){
                                $placed = $plan['ts_amount'];
                            }
                            if($plan['ts_trade_type'] == 0){
                                $ts_acked_amount = $order['ts_acked_amount'] + $order['ts_acked_fee'];
                            }else{
                                $ts_acked_amount = $order['ts_acked_amount'] ;
                            }
                            if(in_array($order['ts_trade_type'], [62,64])){
                                if(!empty($order['ts_redeem_pay_date']) && strtotime($trade_date) >= strtotime($order['ts_redeem_pay_date'])){
                                    TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>3,'ts_amount_placed'=>$placed,'ts_amount_acked'=>$ts_acked_amount]);
                                }else{
                                    $ts_acked_amount = 0;
                                    TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>1,'ts_amount_placed'=>DB::raw('ts_amount')]);
                                }
                            }else{
                                TsPlanBalanceOrder::where('ts_txn_order_id',$order['ts_txn_id'])->update(['ts_status'=>3,'ts_amount_placed'=>$placed,'ts_amount_acked'=>$ts_acked_amount]);
                            }
                        }
                    }
                }else{
                    if(strpos($order['ts_portfolio_id'],'ZH')!==false){
                        if(in_array($order['ts_trade_status'],[6])){
                            if(in_array($order['ts_trade_type'],[62,64])){
                                if(!empty($order['ts_redeem_pay_date']) && strtotime($trade_date) >= strtotime($order['ts_redeem_pay_date'])){
                                    $ts_acked_amount = $order['ts_acked_amount'] ;
                                    TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->update(['ts_txn_order_id'=>$order['ts_txn_id'],'ts_status'=>5,'ts_amount_placed'=>DB::raw('ts_amount'),'ts_amount_acked'=>$ts_acked_amount]);
                                }else{
                                    $ts_acked_amount = 0;
                                    TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->update(['ts_txn_order_id'=>$order['ts_txn_id'],'ts_status'=>1,'ts_amount_placed'=>DB::raw('ts_amount')]);
                                }
                            }else{
                                $ts_acked_amount = $order['ts_acked_amount'] ;
                                TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->update(['ts_txn_order_id'=>$order['ts_txn_id'],'ts_status'=>5,'ts_amount_placed'=>DB::raw('ts_amount'),'ts_amount_acked'=>$ts_acked_amount]);
                            }
                        }elseif(in_array($order['ts_trade_status'],[1])){
                            TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->update(['ts_txn_order_id'=>$order['ts_txn_id'],'ts_status'=>1,'ts_amount_placed'=>DB::raw('ts_amount')]);
                        }elseif(in_array($order['ts_trade_status'],[-3,-2,-1,9])){
                            TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$order['ts_portfolio_id'])->where('ts_pay_method',$order['ts_pay_method'])->where('ts_fund_code',$order['ts_fund_code'])->update(['ts_txn_order_id'=>$order['ts_txn_id'],'ts_status'=>-1]);
                        }
                    }
                }
            }
            self::updateBalanceStatus($txnId);
            if(!$transaction){
                DB::connection('mysql')->commit();
            }
        } catch(\Exception $e) {
            Log::error('TsHelperZxb is error:'.'continueOrder('.$txnId.','.$transaction.')'.$e->getMessage().$e->getTraceAsString());
            if(!$transaction){
                DB::connection('mysql')->rollBack();
            }
        }

        return [20000, 'Succeed'];
    }

    public static function updateBalanceStatus($txnId){
        $ts_plan = TsPlan::where('ts_txn_id',$txnId)->first();
        if($ts_plan['ts_type'] == 1){
            $ts_order = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereIn('ts_trade_type', [10, 19])->where('ts_trade_status','<',0)->first();
            if($ts_order){
                TsPlan::where('ts_txn_id',$txnId)->update(['ts_status'=>-1]);
                return [20000, 'Succeed'];
            }
            if($ts_plan['ts_status'] == 2){
                $ts_order = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereIn('ts_trade_type', [20, 29])->where('ts_trade_status','>=',0)->first();
                if($ts_order){
                    return [20000, 'Succeed'];
                }else{
                    $ts_order = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->where('ts_trade_type',97)->where('ts_trade_status','>=',0)->first();
                    if(!$ts_order){
                        TsPlan::where('ts_txn_id',$txnId)->where('ts_status',2)->update(['ts_status'=>1]);
                        TsPlanFund::where('ts_txn_id',$txnId)->where('ts_status',2)->update(['ts_status'=>1]);
                    }
                }
            }
        }
        $orders = TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_trade_type', '!=', 2)->get();
        $change = [];
        $change_acked = [];
        $no_check = [];
        $failed = [];
        foreach($orders as $order){
            $key = $order['ts_txn_id']."|".$order['ts_portfolio_id']."|".$order['ts_pay_method']."|".$order['ts_fund_code'];
            if(in_array($order['ts_status'],[0,1,3,5])){
                if(isset($change[$key])){
                    if($order['ts_status'] == 0 || $order['ts_status'] == 1){
                        $change[$key] += $order['ts_amount'];
                        $no_check[$key] = 1;
                    }else{
                        $change[$key] += $order['ts_amount_placed'];
                    }
                }else{
                    if($order['ts_status'] == 0 || $order['ts_status'] == 1){
                        $change[$key] = $order['ts_amount'];
                        $no_check[$key] = 1;
                    }else{
                        $change[$key] = $order['ts_amount_placed'];
                    }
                }
                if(isset($change_acked[$key])){
                    if($order['ts_status'] == 3 || $order['ts_status'] == 5){
                        $change_acked[$key] += $order['ts_amount_acked'];
                    }
                }else{
                    if($order['ts_status'] == 3 || $order['ts_status'] == 5){
                        $change_acked[$key] = $order['ts_amount_acked'];
                    }
                }
            }else{
                $failed[$key] = 1;
            }
        }
        foreach($change as $key=>$amount){
            $amount = (float)$amount;
            $tmp = explode('|',$key);
            $portfolio_id = $tmp[1];
            $pay_method = $tmp[2];
            $code = $tmp[3];
            $plan_fund = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$portfolio_id)->where('ts_pay_method',$pay_method)->where('ts_fund_code',$code)->first();
            $ts_status = 1;
            $acked_amount = 0;
            $change_amount = abs($plan_fund['ts_amount_src'] - $plan_fund['ts_amount_dst']);
            if((abs($change_amount - $amount) <0.01 || $change_amount < $amount) && !isset($no_check[$key])){
                $ts_status = 5;
            }
            if($plan_fund['ts_status'] == -1){
                $ts_status = -1;
            }
            if(isset($change_acked[$key])){
                $acked_amount = $change_acked[$key];
            }
            if(isset($failed[$key])){
                unset($failed[$key]);
            }
            TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$portfolio_id)->where('ts_pay_method',$pay_method)->where('ts_fund_code',$code)->update(['ts_amount_placed'=>$amount,'ts_amount_acked'=>$acked_amount,'ts_status'=>$ts_status]);
        }
        foreach($failed as $key=>$row){
            $tmp = explode('|',$key);
            $portfolio_id = $tmp[1];
            $pay_method = $tmp[2];
            $code = $tmp[3];
            $plan_fund = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$portfolio_id)->where('ts_pay_method',$pay_method)->where('ts_fund_code',$code)->first();
            if($plan_fund['ts_status']!=2){
                if($plan_fund['ts_status'] == -1){
                    $ts_status = -1;
                }else{
                    $ts_status = 1;
                }
                TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$portfolio_id)->where('ts_pay_method',$pay_method)->where('ts_fund_code',$code)->update(['ts_amount_placed'=>0,'ts_amount_acked'=>0,'ts_status'=>$ts_status]);
            }
        }
        $balances = TsPlanBalance::where('ts_txn_id',$txnId)->get();
        foreach($balances as $row){
            $acked = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->where('ts_amount_src','>',DB::raw('ts_amount_dst'))->sum('ts_amount_acked');
            if(empty($acked)){
                $acked = 0;
            }
            $placed = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->where('ts_amount_src','<',DB::raw('ts_amount_dst'))->sum('ts_amount_placed');
            if(empty($placed)){
                $placed = 0;
            }

            if ($ts_plan && $ts_plan['ts_type'] == 3) {
                $placed_withdraw = TsOrderFund::where('ts_portfolio_txn_id', $txnId)
                    ->where('ts_pay_method', $row['ts_pay_method'])
                    ->where('ts_trade_type', 20)
                    ->whereIn('ts_trade_status', [0, 1, 5, 6])
                    ->sum('ts_placed_amount');
                if ($placed_withdraw) {
                    $placed += $placed_withdraw;
                }
            }

            $placed_acked  = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->where('ts_amount_src','<',DB::raw('ts_amount_dst'))->sum('ts_amount_acked');
            if(empty($placed_acked)){
                $placed_acked = 0;
            }

            if ($ts_plan && $ts_plan['ts_type'] == 3) {
                $placed_withdraw_acked = TsOrderFund::where('ts_portfolio_txn_id', $txnId)
                    ->where('ts_pay_method', $row['ts_pay_method'])
                    ->where('ts_trade_type', 20)
                    ->whereIn('ts_trade_status', [5, 6])
                    ->sum('ts_acked_amount');
                if ($placed_withdraw_acked) {
                    $placed_acked += $placed_withdraw_acked;
                }
            }

            $avail = $acked-$placed;
            $balance_status = 0;
            $last = TsPlanBalance::where('ts_aborted_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->first();
            if($last){
//                $last_status  = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$pay_method)->where('ts_amount_src','>',DB::raw('ts_amount_dst'))->whereNotIn('ts_status',[5,2,-1])->first();
                $last_status = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->whereIn('ts_trade_type',[62,64])->whereIn('ts_trade_status',[0,1])->first();
                if(!$last_status){
                    $last_orders = TsPlanBalanceOrder::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->whereIn('ts_status',[0,1])->first();
                    if(!$last_orders){
                        if(empty($row['ts_aborted_txn_id'])){
                            $balance_status = 1;
                        }else{
                            $pre = TsPlanBalance::where('ts_txn_id',$row['ts_aborted_txn_id'])->where('ts_pay_method',$row['ts_pay_method'])->first();
                            if(!$pre){
                                $balance_status = 1;
                            }
                            if($pre['ts_status'] == 1){
                                $balance_status = 1;
                            }
                        }
                    }
                }
                TsPlanBalance::where('ts_aborted_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->update(['ts_amount_aborted'=>$acked+$row['ts_amount_aborted']-$placed]);
                TsPlanBalance::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->update(['ts_amount_total'=>DB::raw("ts_amount_aborted + {$acked}"),'ts_amount_used'=>DB::raw("ts_amount_aborted + {$acked} - {$placed} + {$placed_acked}"),'ts_amount_freeze'=>$placed-$placed_acked,'ts_amount_avail'=>0,'ts_status'=>$balance_status]);
                $aborted_order = TsOrderFund::where('ts_portfolio_txn_id',$last['ts_txn_id'])->where('ts_pay_method',$row['ts_pay_method'])->where('ts_trade_type',98)->first();
                if($aborted_order){
                    $aborted_order->ts_placed_amount = $acked+$row['ts_amount_aborted']-$placed;
                    $aborted_order->ts_placed_share = $acked+$row['ts_amount_aborted']-$placed;
                    $aborted_order->ts_acked_amount = $acked+$row['ts_amount_aborted']-$placed;
                    $aborted_order->ts_acked_share = $acked+$row['ts_amount_aborted']-$placed;
                    $aborted_order->save();
                }else{
                    $aborted_TxnId = TsTxnId::makeFundTxnId($last['ts_txn_id']);
                    $tmp_aborted_order = TsOrder::where('ts_txn_id',$last['ts_txn_id'])
                                        ->whereRaw("ts_portfolio_id not like 'ZH%'")->first(); //应该添加这一行
                    $aborted_portfolio_id = $tmp_aborted_order['ts_portfolio_id'];
                    $aborted_order = [];
                    $aborted_order['ts_txn_id'] = $aborted_TxnId;
                    $aborted_order['ts_uid'] = $last['ts_uid'];
                    $aborted_order['ts_portfolio_id'] = $aborted_portfolio_id;
                    $aborted_order['ts_fund_code'] = '001826';
                    $aborted_order['ts_fund_name'] = '国寿安保增金宝货币';
                    $aborted_order['ts_pay_method'] = $row['ts_pay_method'];
                    $aborted_order['ts_trade_type'] = 98;
                    $aborted_order['ts_trade_status'] = 6;

                    $aborted_order['ts_placed_amount'] = $acked+$row['ts_amount_aborted']-$placed;
                    $aborted_order['ts_placed_share'] = $acked+$row['ts_amount_aborted']-$placed;
                    $aborted_order['ts_acked_amount'] = $acked+$row['ts_amount_aborted']-$placed;
                    $aborted_order['ts_acked_share'] = $acked+$row['ts_amount_aborted']-$placed;

                    $aborted_order['ts_placed_date'] = date('Y-m-d');
                    $aborted_order['ts_redeem_pay_date'] = date('Y-m-d');
                    $aborted_order['ts_placed_time'] = date('H:i:s');
                    $aborted_order['ts_acked_date'] = date('Y-m-d');
                    $aborted_order['ts_accepted_at'] = date('Y-m-d H:i:s');
                    $aborted_order['ts_scheduled_at'] = date('Y-m-d H:i:s');
                    $aborted_order['ts_portfolio_txn_id'] = $last['ts_txn_id'];
                    $aborted_order['ts_origin'] = 8;
                    $aborted_order['ts_audit'] = 1;
                    $m = new TsOrderFund($aborted_order);
                    $m->save();
                }

                $this_order = TsOrderFund::where('ts_portfolio_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->where('ts_trade_type',97)->first();
                if($this_order){
                    $this_order->ts_placed_amount = $acked+$row['ts_amount_aborted']-$placed;
                    $this_order->ts_placed_share = $acked+$row['ts_amount_aborted']-$placed;
                    $this_order->ts_acked_amount = $acked+$row['ts_amount_aborted']-$placed;
                    $this_order->ts_acked_share = $acked+$row['ts_amount_aborted']-$placed;
                    $this_order->save();
                }else{
                    $this_TxnId = TsTxnId::makeFundTxnId($txnId);
                    $tmp_this_order = TsOrder::where('ts_txn_id',$txnId)
                                        ->whereRaw("ts_portfolio_id not like 'ZH%'")->first(); //应该添加这一行
                    $this_portfolio_id = $tmp_this_order['ts_portfolio_id'];
                    $this_order = [];
                    $this_order['ts_txn_id'] = $this_TxnId;
                    $this_order['ts_uid'] = $last['ts_uid'];
                    $this_order['ts_portfolio_id'] = $this_portfolio_id;
                    $this_order['ts_fund_code'] = '001826';
                    $this_order['ts_fund_name'] = '国寿安保增金宝货币';
                    $this_order['ts_pay_method'] = $row['ts_pay_method'];
                    $this_order['ts_trade_type'] = 97;
                    $this_order['ts_trade_status'] = 6;

                    $this_order['ts_placed_amount'] = $acked+$row['ts_amount_aborted']-$placed;
                    $this_order['ts_placed_share'] = $acked+$row['ts_amount_aborted']-$placed;
                    $this_order['ts_acked_amount'] = $acked+$row['ts_amount_aborted']-$placed;
                    $this_order['ts_acked_share'] = $acked+$row['ts_amount_aborted']-$placed;

                    $this_order['ts_placed_date'] = date('Y-m-d');
                    $this_order['ts_redeem_pay_date'] = date('Y-m-d');
                    $this_order['ts_placed_time'] = date('H:i:s');
                    $this_order['ts_acked_date'] = date('Y-m-d');
                    $this_order['ts_accepted_at'] = date('Y-m-d H:i:s');
                    $this_order['ts_scheduled_at'] = date('Y-m-d H:i:s');
                    $this_order['ts_portfolio_txn_id'] = $txnId;
                    $this_order['ts_origin'] = 8;
                    $this_order['ts_audit'] = 1;
                    $m = new TsOrderFund($this_order);
                    $m->save();
                }


            }else{
                $last_status  = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->where('ts_amount_src','>',DB::raw('ts_amount_dst'))->whereNotIn('ts_status',[5,2,-1])->first();
                if(!$last_status){
                    if(empty($row['ts_aborted_txn_id'])){
                        $balance_status = 1;
                    }else{
                        $pre = TsPlanBalance::where('ts_txn_id',$row['ts_aborted_txn_id'])->where('ts_pay_method',$row['ts_pay_method'])->first();
                        if($pre && $pre['ts_status'] == 1){
                            $balance_status = 1;
                        }elseif(!$pre){
                            $balance_status = 1;
                        }
                    }
                }
                TsPlanBalance::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->update(['ts_amount_total'=>DB::raw("ts_amount_aborted + {$acked}"),'ts_amount_used'=>$placed_acked,'ts_amount_freeze'=>$placed-$placed_acked,'ts_amount_avail'=>DB::raw("ts_amount_aborted + {$avail}"),'ts_status'=>$balance_status]);
            }
        }
        if($ts_plan['ts_type']==3){
            $balances = TsPlanBalance::where('ts_txn_id',$txnId)->get();
            foreach($balances as $row){
                if($row['ts_status'] ==1 && $row['ts_amount_avail'] == 0 && $row['ts_amount_freeze'] == 0){
                    TsPlanFund::where('ts_txn_id',$txnId)->where('ts_pay_method',$row['ts_pay_method'])->where('ts_amount_src','<',DB::raw('ts_amount_dst'))->where('ts_status',1)->update(['ts_status'=>5]);
                }
            }
        }
        $fundPlan = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_amount_src','!=',DB::raw('ts_amount_dst'))->whereNotIn('ts_status',[5,-1])->first();
        if(!$fundPlan){
            if($ts_plan['ts_type']==3){
                $end_balance = TsPlanBalance::where('ts_txn_id',$txnId)->where('ts_amount_avail','!=',0)->first();
                if(!$end_balance){
                    $end_balance = TsPlanBalance::where('ts_txn_id',$txnId)->where('ts_status',0)->first();
                    if(!$end_balance){
                        $end_balance = TsPlanBalance::where('ts_txn_id',$txnId)->where('ts_amount_freeze','!=',0)->first();
                        if(!$end_balance){
                            TsPlan::where('ts_txn_id',$txnId)->where('ts_status',1)->update(['ts_status'=>5,'ts_end_at'=>date('Y-m-d H:i:s')]);
                        }
                    }
                }
            }else{
                TsPlan::where('ts_txn_id',$txnId)->where('ts_status',1)->update(['ts_status'=>5,'ts_end_at'=>date('Y-m-d H:i:s')]);
            }
        }
        if($ts_plan){
//            Artisan::call('mf:up_ev', ['uid' => $ts_plan['ts_uid']]);
        }
    }

    public static function placedPlanOrder($txnId){
        self::continueOrder($txnId);
        $plan = TsPlan::where('ts_txn_id',$txnId)->first();
        if($plan['ts_status'] != 1 || $txnId=='20170909A002762A' || $txnId == '20170917A000056A' || $txnId == '20170917B000055A'){
            return [20000, 'Succeed', $txnId];
        }
        if($plan){
            DB::connection('mysql')->beginTransaction();
            try{
                $pay_type = $plan['ts_pay_type'];
                $uid = $plan['ts_uid'];
                $portfolioId = $plan['ts_portfolio_id'];
                $dt = Carbon::now();
                $fundPlan = self::SrcDst2Strategy($txnId);
                //$yingmi_orders = self::executePlanFundYingmi($uid,$txnId,$fundPlan);
                $balances = TsPlanBalance::where('ts_txn_id',$txnId)->get();
                $new_balances = [];
                foreach($balances as $row){
                    $new_balances[$row['ts_pay_method']] = ['status'=>$row['ts_status'],'amount'=>$row['ts_amount_avail']];
                }
                if(empty($new_balances) && $plan['ts_type'] == 1){
                    $amount = 0;
                    $pay = null;
                    foreach($fundPlan['add'] as $row){
                        $amount += $row['amount'];
                        $pay = $row['pay_method'];
                    }
                    $new_balances[$pay] = ['status'=>1,'amount'=>$amount];
                }
                $fundPlan = MatchmakingHelper::match($uid,$txnId,$portfolioId,$fundPlan,$new_balances);
                self::updateBalance($txnId,$fundPlan);
                if($plan['ts_type'] == 3){
                    $fund_orders = self::getFundOrder($uid,$txnId,$fundPlan,$dt,1,false,$pay_type);
                }else{
                    $fund_orders = self::getFundOrder($uid,$txnId,$fundPlan,$dt,false,false,$pay_type);
                }
                $suborders = $fund_orders['order'];
                $balance_orders = $fund_orders['balance_order'];
                foreach ($suborders as $sub) {
                    static::writeTsOrderFundToFile($sub);
                    $m = new TsOrderFund($sub);
                    $m->save();
                }
                /**
                foreach($yingmi_order as $sub){
                    $m = new TsPlanBalanceOrder($sub);
                    $m->save();
                }
                **/
                foreach($balance_orders as $sub){
                    $m = new TsPlanBalanceOrder($sub);
                    $m->save();
                }
                self::executePlanFund($txnId,$fundPlan);
                DB::connection('mysql')->commit();
            } catch(\Exception $e) {
                     //echo  $e->getMessage().$e->getTraceAsString();
                Log::error('TsHelperZxb is error:'.'placedPlanOrder('.$txnId.')'.$e->getMessage().$e->getTraceAsString());
                DB::connection('mysql')->rollBack();
            }
        }
        return [20000, 'Succeed', $txnId];
    }


    /**
     * @param $txnId 组合订单ID
     *
     * @return [true/fase, message, txnId]
     */
    public static function checkOrder($txnId) {
        $plan = TsPlan::where('ts_txn_id',$txnId)->first();

        // 对于C与W因为是不生成计划的
        if (!$plan && in_array(substr($txnId, -1), ['C', 'W'])) {
            return [20000, 'Succeed'];
        }

        if(in_array($plan['ts_status'],[5,2])){
            return [20000, 'Succeed'];
        }elseif(in_array($plan['ts_status'],[0,1])){
            return [30001, 'Uncompleted'];
        }else{
            if($plan['ts_status'] == -1 && $plan['ts_type'] == 3){
                /**
                $parent_id = $txnId;
                while(true){
                    $balance = TsPlanBalance::where('ts_aborted_txn_id',$parent_id)->first();
                    if($balance){
                        $parent_id = $balance['ts_txn_id'];
                    }else{
                        break;
                    }
                }
                if($parent_id != $txnId){
                    $plan = TsPlan::where('ts_txn_id',$parent_id)->first();
                    if(in_array($plan['ts_status'],[0,1])){
                        return [30001, 'Uncompleted'];
                    }elseif(in_array($plan['ts_status'],[5,2])){
                        return [20000, 'Succeed'];
                    }else{
                        return [40001, 'Failed'];
                    }
                }else{
                    return [40001, 'Failed'];
                }
                **/
                $balance = TsPlanBalance::where('ts_txn_id',$txnId)->where(function ($query) {
                    $query->where('ts_status','!=', 1)->orWhere('ts_amount_avail', '!=', 0)->orWhere('ts_amount_freeze','!=',0);
                })->first();
                if($balance){
                    $balance = TsPlanBalance::where('ts_aborted_txn_id',$txnId)->first();
                    if($balance){
                        return [30001, 'Uncompleted'];
                    }else{
                        return [40001, 'Failed'];
                    }
                }else{
                    //
                    // 钱已全部到账
                    //
                    $rows = TsPlanBalance::where('ts_aborted_txn_id',$txnId)->get();
                    if ($rows->isEmpty() || abs($rows->sum('ts_amount_aborted')) < 0.001) {
                        return [20000, 'Succeed'];
                    } else {
                        return [20004, 'Aborted'];
                    }
                }
            }
            return [40001, 'Failed'];
        }
    }

    public static function executePlanFund($txnId,$fundPlan){
        foreach($fundPlan['del'] as $row){
            TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$row['portfolio_id'])->where('ts_pay_method',$row['pay_method'])->where('ts_fund_code',$row['code'])
                ->update(['ts_amount_placed'=>DB::raw("ts_amount_placed + {$row['amount']}")]);
        }
        foreach($fundPlan['add'] as $row){
            TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$row['portfolio_id'])->where('ts_pay_method',$row['pay_method'])->where('ts_fund_code',$row['code'])
                ->update(['ts_amount_placed'=>DB::raw("ts_amount_placed + {$row['amount']}")]);
        }
    }

    public static function executePlanFundYingmi($uid,$txnId,$fundPlan){
        $balance_orders = [];
        foreach($fundPlan['del'] as $row){
            if(strpos($row['portfolio_id'],'ZH')!==false){
                $row['amount'] = abs($row['amount']);
                TsPlanFund::where('ts_txn_id',$txnId)->where('ts_portfolio_id',$row['portfolio_id'])->where('ts_pay_method',$row['pay_method'])->where('ts_fund_code',$row['code'])
                    ->update(['ts_amount_placed'=>DB::raw("ts_amount_placed + {$row['amount']}")]);
                $balanceorder = [
                    'ts_txn_id'=>$txnId,
                    'ts_uid'=>$uid,
                    'ts_portfolio_id'=>$row['portfolio_id'],
                    'ts_pay_method'=>$row['pay_method'],
                    'ts_fund_code'=>$row['code'],
                    'ts_trade_type'=>1,
                    'ts_amount'=>$row['amount'],
                    'ts_amount_placed'=>0,
                    'ts_amount_acked'=>0,
                    'ts_status'=>0,
                ];
                $balance_orders[] = $balanceorder;
            }
        }
        return $balance_orders;
    }

    public static function executePlan($uid,$planId){
        $tmp = self::getPlan($uid,$planId);
        $plan = $tmp[0];
        $fundPlan = $tmp[1];
        if($plan){
            DB::connection('mysql')->beginTransaction();
            try{
                $dt = Carbon::now();
                if($plan['ts_type'] == 1){
                    $type = 3;
                }elseif($plan['ts_type'] == 2){
                    $type = 4;
                }elseif($plan['ts_type'] == 3){
                    $type = 6;
                }
                $time = date('Y-m-d H:i:s');
                if($type == 6){
                    $abort_plan = TsPlan::where('ts_uid',$uid)->where('ts_type',3)->where('ts_status',1)->orderBy('id','DESC')->first();
                    if($abort_plan){
                        if(abs(strtotime($abort_plan['ts_start_at']) - strtotime($time)) < 60){
                            DB::connection('mysql')->rollBack();
                            return false;
                        }
                    }
                }
                $txnId = TsTxnId::makePoTxnId($dt, $type, 1);
                $plan['ts_txn_id'] = $txnId;
                $plan['ts_status'] = 1;
                $plan['ts_start_at'] = $time;
                $plan['created_at'] = $time;
                $plan['updated_at'] = $time;
                TsPlan::insert($plan);
                $new_fund_plan = [];
                $pay_methods = [];
                $del_pay_methods = [];
                foreach($fundPlan as $row){
                    $row['ts_txn_id'] = $txnId;
                    $row['created_at'] = $time;
                    $row['updated_at'] = $time;
                    if (abs($row['ts_amount_dst'] - $row['ts_amount_src']) < 0.001) {
                        $row['ts_status'] = 5;
                    } else {
                        $row['ts_status'] = 1;
                    }
                    $new_fund_plan[] = $row;
                    $pay_methods[$row['ts_pay_method']] = 1;
                    if($row['ts_amount_dst']<$row['ts_amount_src']){
                        $del_pay_methods[$row['ts_pay_method']] = 1;
                    }
                }
                TsPlanFund::insert($new_fund_plan);
                if($type == 6){
                    $abort_id = self::getAdjustStatus($uid,$txnId);
                    if($abort_id){
                        TsPlan::where('ts_txn_id',$abort_id)->update(['ts_status'=>-1]);
                    }
                    foreach($pay_methods as $pay_method=>$row){
                        if(isset($del_pay_methods[$pay_method])){
                            $ts_status = 0;
                        }else{
                            $ts_status = 1;
                        }
                        $balance = ['ts_txn_id'=>$txnId,'ts_uid'=>$uid,'ts_pay_method'=>$pay_method,'ts_amount_aborted'=>0,'ts_amount_total'=>0,'ts_amount_avail'=>0,'ts_amount_freeze'=>0,
                                    'ts_amount_used'=>0,'ts_status'=>$ts_status,'created_at'=>date('Y-m-d'),'updated_at'=>date('Y-m-d')
                        ];
                        if($abort_id){
                            $last_balance = TsPlanBalance::where('ts_txn_id',$abort_id)->where('ts_pay_method',$pay_method)->first();
                            $balance['ts_aborted_txn_id'] = $abort_id;
                            if($last_balance){
                                $balance['ts_amount_total'] += $last_balance['ts_amount_avail'];
                                $balance['ts_amount_avail'] += $last_balance['ts_amount_avail'];
                                $balance['ts_amount_aborted'] += $last_balance['ts_amount_avail'];
                                if($last_balance['ts_status'] != 1){
                                    $balance['ts_status'] = 0;
                                }
                                TsPlanBalance::where('ts_txn_id',$abort_id)->where('ts_pay_method',$pay_method)->update(['ts_amount_avail'=>DB::raw("ts_amount_avail - {$last_balance['ts_amount_avail']}"),'ts_amount_used'=>DB::raw("ts_amount_used + {$last_balance['ts_amount_avail']}")]);
                            }
                        }
                        TsPlanBalance::insert($balance);
                    }
                }
                DB::connection('mysql')->commit();
                return $txnId;
            } catch(\Exception $e) {
                //echo  $e->getMessage().$e->getTraceAsString();
                Log::error('TsHelperZxb is error:'.$e->getMessage().$e->getTraceAsString());
                DB::connection('mysql')->rollBack();
                return false;
            }
        }
        return false;
    }

    public static function setPlan($plan,$fundPlan){
        $time = date('Y-m-d H:i:s');
        $plan['created_at'] = $time;
        $plan['updated_at'] = $time;
        $planId = TsPlanTmp::insertGetId($plan);
        $plan_funds = [];
        foreach($fundPlan as $row){
            $plan_funds[] = ['ts_plan_id'=>$planId,'ts_uid'=>$plan['ts_uid'],'ts_portfolio_id'=>$row['portfolio_id'],'ts_pay_method'=>$row['pay_method'],
                        'ts_fund_code'=>$row['code'],'ts_amount_src'=>$row['src'],'ts_amount_dst'=>$row['dst'],'created_at'=>$time,'updated_at'=>$time
                            ];
        }
        TsPlanFundTmp::insert($plan_funds);
        return $planId;
    }


    public static function getPlanFee($uid,$planId){
        $tmp = TsPlanTmp::where('ts_uid',$uid)->where('ts_plan_id',$planId)->first();
        if($tmp){
            return $tmp['ts_fee'];
        }
        return 0;
    }

    public static function getDelPlanAmount($uid,$planId,$portfolioId,$pay_method){
        $funds = TsPlanFundTmp::where('ts_uid',$uid)->where('ts_plan_id',$planId)->where('ts_portfolio_id',$portfolioId)->where('ts_pay_method',$pay_method)->get();
        $amount = 0;
        foreach($funds as $row){
            if($row['ts_amount_dst'] < $row['ts_amount_src']){
                $amount += $row['ts_amount_src'] - $row['ts_amount_dst'];
            }
        }
        return $amount;
    }

    public static function getPlan($uid,$planId){
        $tmp = TsPlanTmp::where('ts_uid',$uid)->where('ts_plan_id',$planId)->first();
        $plan = [];
        $fundPlan = [];
        if($tmp){
            $plan = ['ts_plan_id'=>$planId,'ts_uid'=>$tmp['ts_uid'],'ts_risk'=>$tmp['ts_risk'],'ts_type'=>$tmp['ts_type'],'ts_amount'=>$tmp['ts_amount'],'ts_percent'=>$tmp['ts_percent'],'ts_pay_type'=>$tmp['ts_pay_type']];
            $funds = TsPlanFundTmp::where('ts_uid',$uid)->where('ts_plan_id',$planId)->get();
            foreach($funds as $row){
                $fundPlan[] = ['ts_uid'=>$row['ts_uid'],'ts_portfolio_id'=>$row['ts_portfolio_id'],
                    'ts_pay_method'=>$row['ts_pay_method'],'ts_fund_code'=>$row['ts_fund_code'],'ts_amount_src'=>$row['ts_amount_src'],'ts_amount_dst'=>$row['ts_amount_dst']
                ];
            }
        }
        return [$plan,$fundPlan];
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

    //返回false表示不在调仓状态，true表示正在调仓中
    public static function getAdjustStatus($uid,$txnId=false){
        $status = false;
        if($txnId){
            $plan = TsPlan::where('ts_uid',$uid)->where('ts_txn_id','!=',$txnId)->where('ts_type',3)->orderBy('id','DESC')->first();
        }else{
            $plan = TsPlan::where('ts_uid',$uid)->where('ts_type',3)->orderBy('id','DESC')->first();
        }
        if($plan){
            if($plan['ts_status'] == 1){
                return $plan['ts_txn_id'];
            }
        }
        return $status;
    }

    public static function mergeSrc2Dst($list1,$list2){
        $result = [];
        foreach($list1 as $key=>$row){
            $arr = explode('|',$key);
            $code = $arr[0];
            $portfolio_id = $arr[2];
            $pay_method = $arr[1];
            if(isset($list2[$key])){
                $result[] = ['code'=>$code,'portfolio_id'=>$portfolio_id,'pay_method'=>$pay_method,'src'=>$row,'dst'=>$list2[$key]];
            }else{
                $result[] = ['code'=>$code,'portfolio_id'=>$portfolio_id,'pay_method'=>$pay_method,'src'=>$row,'dst'=>0];
            }
        }
        foreach($list2 as $key=>$row){
            $arr = explode('|',$key);
            $code = $arr[0];
            $portfolio_id = $arr[2];
            $pay_method = $arr[1];
            if(!isset($list1[$key])){
                $result[] = ['code'=>$code,'portfolio_id'=>$portfolio_id,'pay_method'=>$pay_method,'src'=>0,'dst'=>$row];
            }
        }
        return $result;
    }

    public static function SrcDst2Strategy($txnId){
        $fundPlan = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_status',1)->get();
        $strategy = ['add'=>[],'del'=>[]];
        foreach($fundPlan as $row){
            $change = $row['ts_amount_dst']-$row['ts_amount_src'];
            if($change >0){
                if(abs($change) - $row['ts_amount_placed'] >= 0.01){
                    $tmp = ['code'=>$row['ts_fund_code'],'amount'=>round(abs($change)-$row['ts_amount_placed'],2),'pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id']];
                    $strategy['add'][] = $tmp;
                }
            }elseif($change <0){
                if(abs($change) - $row['ts_amount_placed'] >= 0.01){
                    $tmp = ['code'=>$row['ts_fund_code'],'amount'=>round($row['ts_amount_placed']-abs($change),2),'pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id']];
                    $strategy['del'][] = $tmp;
                }
            }
        }
        return $strategy;
    }

    //获取已经执行的列表
    public static function SrcDst2Completed($txnId){
        $fundPlan = TsPlanFund::where('ts_txn_id',$txnId)->where('ts_status',1)->get();
        $strategy = ['added'=>[],'deled'=>[]];
        foreach($fundPlan as $row){
            $change = $row['ts_amount_dst']-$row['ts_amount_src'];
            if($change >0){
                if($row['ts_amount_placed'] >= 0.01){
                    $strategy['added'][] = $row['ts_fund_code'];
                }
            }elseif($change <0){
                if($row['ts_amount_placed'] >= 0.01){
                    $strategy['deled'][] = $row['ts_fund_code'];
                }
            }
        }
        return $strategy;
    }

    public static function getFundOrder($uid,$txnId,$fundPlan,$dt,$type=false,$invest_plan=false,$pay_type=0){
        $suborders = [];
        $balance_orders = [];
        foreach($fundPlan['del'] as $row){
            $subTxnId = TsTxnId::makeFundTxnId($txnId);

            if ($pay_type == 0) {
                $type1 = 40;
            } else {
                $type1 = 41;
            }
            $suborder = [
                'ts_txn_id' => $subTxnId,
                'ts_uid' => $uid,
                'ts_portfolio_id' => $row['portfolio_id'],
                'ts_portfolio_txn_id' => $txnId,
                'ts_fund_code' => $row['code'],
                'ts_fund_name' => $row['name'],
                'ts_trade_type' => $type1,
                'ts_trade_status' => 0,
                'ts_placed_amount' => $row['amount'],
                'ts_placed_share' => $row['share'],
                'ts_pay_method' => $row['pay_method'],
                'ts_accepted_at' => $dt,
                'ts_scheduled_at' => $row['scheduled_date'],
                'ts_origin' => 8,
                'ts_placed_fee' =>self::getFundFee($row['code'],$row['amount'],1),
            ];
            if($type==1){
                $suborder['ts_trade_type'] = 64;
                $suborder['ts_placed_amount'] = 0;
            }
            $balanceorder = [
                'ts_txn_id'=>$txnId,
                'ts_uid'=>$uid,
                'ts_portfolio_id'=>$row['portfolio_id'],
                'ts_pay_method'=>$row['pay_method'],
                'ts_fund_code'=>$row['code'],
                'ts_txn_order_id'=>$subTxnId,
                'ts_trade_type'=>1,
                'ts_amount'=>$row['amount'],
                'ts_amount_placed'=>0,
                'ts_amount_acked'=>0,
                'ts_status'=>0,
            ];
            $balance_orders[] = $balanceorder;
            $suborders[] = $suborder;
        }

        foreach($fundPlan['add'] as $row){
            $subTxnId = TsTxnId::makeFundTxnId($txnId);
            $suborder = [
                'ts_txn_id' => $subTxnId,
                'ts_uid' => $uid,
                'ts_portfolio_id' => $row['portfolio_id'],
                'ts_portfolio_txn_id' => $txnId,
                'ts_fund_code' => $row['code'],
                'ts_fund_name' => $row['name'],
                'ts_trade_type' => 31,
                'ts_trade_status' => 0,
                'ts_placed_amount' => $row['amount'],
                'ts_placed_share' => 0,
                //'ts_trade_date' => $row['trade_date'],
               // 'ts_trade_nav' => $row['trade_nav'],
                // 'ts_acked_date' => 'yt_acked_date',
                // 'ts_acked_amount' => 'yt_acked_amount',
                // 'ts_acked_share' => 'yt_acked_share',
                // 'ts_acked_fee' => 'yt_acked_fee',
                // 'ts_redeem_pay_date' => 'yt_redeem_pay_date',
                'ts_pay_method' => $row['pay_method'],
                'ts_accepted_at' => $dt,
                'ts_scheduled_at' => $row['scheduled_date'],
                'ts_origin' => 8,
                'ts_placed_fee' =>self::getFundFee($row['code'],$row['amount'],1),
                // 'ts_canceled_at' => ,
                // 'ts_pay_status' => 'yt_pay_status',
                // 'ts_error_code' => 'yt_error_code',
                // 'ts_error_msg' => 'yt_error_msg',
            ];
            if($type==1){
                $suborder['ts_trade_type'] = 63;
            }elseif($type==2){
                if(!empty($invest_plan)){
                    $suborder['ts_trade_type'] = 51;
                    $suborder['ts_invest_plan_id'] = $invest_plan;
                }
            }
            $balanceorder = [
                'ts_txn_id'=>$txnId,
                'ts_uid'=>$uid,
                'ts_portfolio_id'=>$row['portfolio_id'],
                'ts_pay_method'=>$row['pay_method'],
                'ts_fund_code'=>$row['code'],
                'ts_txn_order_id'=>$subTxnId,
                'ts_trade_type'=>0,
                'ts_amount'=>$row['amount'],
                'ts_amount_placed'=>0,
                'ts_amount_acked'=>0,
                'ts_status'=>0,
            ];
            $suborders[] = $suborder;
            $balance_orders[] = $balanceorder;
        }

        return ['order'=>$suborders,'balance_order'=>$balance_orders];
    }

    public static function getFundFee($code,$amount,$type){
        $amount = abs($amount);
        if($type == 1){
            $fee = FundFee::estimateFee2($code, 1, $amount);
        }else{
            $fee = FundFee::estimateFee2($code, 2, $amount);
        }
        return number_format($fee, 2, '.', '');
    }

    public static function getPortfolioFee($plan){
        $cost = 0;
        foreach($plan['add'] as $row){
            $cost += self::getFundFee($row['code'],$row['amount'],1);
        }
        foreach($plan['del'] as $row){
            $cost += self::getFundFee($row['code'],$row['amount'],2);
        }
        return $cost;
    }

    public static function updateBalance($txnId,$fundPlan){
        $balances = [];
        foreach($fundPlan['add'] as $row){
            if(isset($balances[$row['pay_method']])){
                $balances[$row['pay_method']] += $row['amount'];
            }else{
                $balances[$row['pay_method']] = $row['amount'];
            }
        }
        foreach($balances as $pay_method=>$amount){
            TsPlanBalance::where('ts_txn_id',$txnId)->where('ts_pay_method',$pay_method)->update([
                                                        'ts_amount_reserve'=>DB::raw("ts_amount_avail - {$amount}"),
                                                        'ts_amount_avail'=>DB::raw("ts_amount_avail - {$amount}"),
                                                        'ts_amount_freeze'=>DB::raw("ts_amount_freeze + {$amount}"),
                                                        ]);
        }
    }

    public static function writeTsOrderToFile($order, $path = null)
    {
        if (!$path) {
            $path = storage_path().sprintf('/app/ts_order-%s.txt', date('Ymd'));
        }

        //'ts_txn_id' => $txnId,
        // ''
        // 'ts_uid' => $uid,
        // 'ts_portfolio_id' => $portfolio_id,
        // 'ts_pay_method' => $pay_method,
        // ''
        // 'ts_trade_type' => 4,
        // 'ts_trade_status' => 0,
        // 'ts_placed_amount' => 0,
        // ''
        // 'ts_placed_percent' => $plan['ts_percent'],
        // 'ts_placed_fee' => 0,
        // 'ts_accepted_at' => $dt,
        // 'ts_scheduled_at' => $dt,
        // 'ts_risk' => $plan['ts_risk'],
        // 'ts_origin' => 8,

        $line = sprintf("%s|%s|%s|%s|%s|%s|%d|%d|%.2f|%s|%.4f|%.2f|%s|%s|%.1f|%d\n",
                        $order['ts_txn_id'], '', $order['ts_uid'], $order['ts_portfolio_id'], $order['ts_pay_method'], '',
                        $order['ts_trade_type'], $order['ts_trade_status'], $order['ts_placed_amount'], '', $order['ts_placed_percent'], $order['ts_placed_fee'],
                        $order['ts_accepted_at'], $order['ts_scheduled_at'], $order['ts_risk'], $order['ts_origin']);
        File::append($path, $line);
    }

    public static function writeTsOrderFundToFile($order, $path = null)
    {
        if (!$path) {
            $path = storage_path().sprintf('/app/ts_order-%s.txt', date('Ymd'));
        }

        // 'ts_txn_id' => $chargeTxnId,
        // 'ts_portfolio_txn_id' => $txnId,
        // 'ts_uid' => $uid,
        // 'ts_portfolio_id' => $portfolioId,
        // 'ts_pay_method' => $paymethod,
        // 'ts_fund_code' => $buy_fund['yw_fund_code'],
        // 'ts_trade_type' => 10,
        // 'ts_trade_status' => 0,
        // 'ts_placed_amount' => $plan['ts_amount'],
        // 'ts_placed_share' => 0,
        // ''
        // 'ts_placed_fee' => 0,
        // 'ts_accepted_at' => $dt,
        // 'ts_scheduled_at' => $dt,
        // ''
        // 'ts_origin' => 8,


        $line = sprintf("%s|%s|%s|%s|%s|%s|%d|%d|%.2f|%.4f|%s|%.2f|%s|%s|%s|%d\n",
                        $order['ts_txn_id'], $order['ts_portfolio_txn_id'], $order['ts_uid'], $order['ts_portfolio_id'], $order['ts_pay_method'], $order['ts_fund_code'],
                        $order['ts_trade_type'], $order['ts_trade_status'], $order['ts_placed_amount'], $order['ts_placed_share'], '', $order['ts_placed_fee'],
                        $order['ts_accepted_at'], $order['ts_scheduled_at'], '', $order['ts_origin']);
        File::append($path, $line);
    }

    public static function getLastHolding($uid){
        $adjust_plan = TsPlan::where('ts_uid',$uid)->where('ts_type',3)->where('ts_status',1)->orderBy('id','DESC')->first();
	    if($adjust_plan){
            $plan = TsPlan::where('ts_uid',$uid)->whereIn('ts_status',[1,5])->orderBy('id','DESC')->first();
	    }
        if($adjust_plan){
            $holding = [
                    'holding'=>[],
                    'buying'=>[],
                    'bonusing'=>[],
                    'redeeming'=>[],
                    'yingmi'=>[],
                    ];
            $fundPlan = TsPlanFund::where('ts_txn_id',$plan['ts_txn_id'])->whereIn('ts_status',[1,5])->get();
            foreach($fundPlan as $row){
                if($row['ts_amount_dst'] <= 0.001)continue;
                $holding['holding'][] = ['code'=>$row['ts_fund_code'],'share'=>1,'amount'=>$row['ts_amount_dst'],'date'=>date('Y-m-d'),'redeemable_date'=>date('Y-m-d'),'pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id']];
            }
        }else{
            $holding = UserHelper::getHolding($uid);
        }
        return $holding;
    }

    public static function getRedeemStatus($uid){
        $plan = TsPlan::where('ts_uid',$uid)->where('ts_type','!=',2)->where('ts_status',1)->orderBy('id','DESC')->first();
        if($plan){
            return false;
        }
        $plan = TsPlan::where('ts_uid',$uid)->orderBy('id','DESC')->first();
        if($plan && $plan['ts_type'] == 2 && $plan['ts_percent'] == 1){
            return false;
        }
        $holding = UserHelper::getHolding($uid);
        if(empty($holding['holding']) &&
           empty($holding['buying']) &&
           empty($holding['redeeming']) &&
           empty($holding['bonusing']) &&
           empty($holding['yingmi'])){
            return false;
        }
        return true;
    }

    /** holding = [
                    'holding'=>[['code'=>'','share'=>'','amount'=>'','date'=>'','redeemable_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'buying'=>[['id'=>'','code'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'redeeming'=>[['id'=>,'code'=>'','share'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'bonusing'=>[['code'=>'','share'=>'','amount'=>'','record_date'=>'','dividend_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'yingmi'=>[['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>''],['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>'']],
                ]
    **/
    public static function getOldHolding($uid,$date){
        $holding = [
                    'holding'=>[],
                    'buying'=>[],
                    'bonusing'=>[],
                    'redeeming'=>[],
                    'yingmi'=>[],
                    ];
        $ts = TsHoldingFund::where('ts_uid',$uid)->where('ts_date',$date)->get();
        $yingmi = [];
        foreach($ts as $row){
            if(strpos($row['ts_portfolio_id'],'ZH')!==false){
                $key = $row['ts_portfolio_id'].'|'.$row['ts_pay_method'];
                if(isset($yingmi[$key])){
                    $yingmi[$key][] = ['code'=>$row['ts_fund_code'],'share'=>$row['ts_share'],'amount'=>$row['ts_amount']];
                }else{
                    $yingmi[$key] = [];
                    $yingmi[$key][] = ['code'=>$row['ts_fund_code'],'share'=>$row['ts_share'],'amount'=>$row['ts_amount']];
                }
            }else{
                $holding['holding'][] = ['code'=>$row['ts_fund_code'],'share'=>$row['ts_share'],'amount'=>$row['ts_amount'],'date'=>'2017-05-02','redeemable_date'=>'2017-05-02','pay_method'=>$row['ts_pay_method'],'portfolio_id'=>$row['ts_portfolio_id']];
            }
        }
        if($yingmi){
            foreach($yingmi as $key=>$list){
                $tmp = explode('|',$key);
                $holding['yingmi'][] = ['list'=>$list,'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>$tmp[1],'portfolio_id'=>$tmp[0]];
            }
        }
        return $holding;
    }

    public static function AdjustGetBenchmarking($holding,$position,$fund_list){
        $hold = UserHelper::mergeHolding($holding,$fund_list);
        $tags = UserHelper::setTag($fund_list,$hold);
        $asset_strategy = new AssetStrategyHelper($hold);
        $asset_strategy->setPosition($position);
        $ratio = $asset_strategy->reallocationAsset();
        $fund_strategy = new FundStrategyHelper();
        $fund_strategy->setTag($tags);
        $fund_strategy->setHolding($holding);
        $fund_strategy->setHold($hold);
        $fund_strategy->setFundList($fund_list);
        $strategy = $fund_strategy->strategy($ratio);
        $new_add_strategy = [];
        foreach($strategy['add'] as $row){
            $row['portfolio_id'] = 'test';
            $new_add_strategy[] = $row;
        }
        $strategy['add'] = $new_add_strategy;
        $new_holding = UserHelper::doing($holding,$strategy);
        $new_hold = UserHelper::mergeHolding($new_holding,$fund_list);
        $cost = 0;
        $del_time = 0;
        $add_time = 0;
        foreach($strategy['del'] as $row){
            $cost += $row['cost'];
            $del_fund = FundInfos::where('fi_code',$row['code'])->first();
            if($del_fund && $del_fund['fi_yingmi_to_account_time'] != null){
                if($del_time < $del_fund['fi_yingmi_to_account_time']){
                    $del_time = $del_fund['fi_yingmi_to_account_time'];
                }
            }
        }
        foreach($strategy['add'] as $row){
            $cost += $row['cost'];
            $add_fund = FundInfos::where('fi_code',$row['code'])->first();
            if($add_fund && $add_fund['fi_yingmi_confirm_time'] != null){
                if($add_time < $add_fund['fi_yingmi_confirm_time']){
                    $add_time = $add_fund['fi_yingmi_confirm_time'];
                }
            }
        }
        $fundPlan = self::mergeSrc2Dst($hold['pay_code'],$new_hold['pay_code']);
        $change_list = [];
        foreach($fundPlan as $row){
            if(isset($change_list[$row['code']])){
                $change_list[$row['code']]['src'] += $row['src'];
                $change_list[$row['code']]['dst'] += $row['dst'];
            }else{
                $change_list[$row['code']] = ['src'=>$row['src'],'dst'=>$row['dst']];
            }
        }
        $list = [];
        $all_ratio = 0;
        foreach($change_list as $code=>$row){
            if($row['src'] ==0 && $row['dst'] == 0)continue;
            $all_ratio += round($row['dst']/$hold['amount'],4);
            $list[] = ['code'=>$code,'amount_src'=>round($row['src'],2),'amount_dst'=>round($row['dst'],2),'ratio'=>round($row['dst']/$hold['amount'],4)];
        }

        if($list && $all_ratio!=1){
            $list[0]['ratio'] += (1-$all_ratio);
        }
        $data = [
            'fee'=>$cost,
            'time'=>($del_time+$add_time),
            'list'=>$list
        ];

        return [20000, 'Succeed', $data];
    }

    public static function BuyGetBenchmarking($holding,$amount,$position,$fund_list)
    {
        // todo add holding=false logic
        //$holding = UserHelper::getHolding($uid,$portfolioId);
        $hold = UserHelper::mergeHolding($holding,$fund_list,$amount);
        $tags = UserHelper::setTag($fund_list,$hold);
        $asset_strategy = new AssetStrategyHelper($hold);
        $asset_strategy->setPosition($position);
        $ratio = $asset_strategy->buyAsset($amount);
        $fund_strategy = new FundStrategyHelper();
        $fund_strategy->setTag($tags);
        $fund_strategy->setHolding($holding);
        $fund_strategy->setHold($hold);
        $fund_strategy->setFundList($fund_list);
        $fund_strategy->setPayMethod(['test'=>$amount]);
        $strategy = $fund_strategy->strategy($ratio);
        $new_add_strategy = [];
        foreach($strategy['add'] as $row){
            $row['portfolio_id'] = 'test';
            $new_add_strategy[] = $row;
        }
        $strategy['add'] = $new_add_strategy;
        //$score_src_re  = AssetStrategyHelper::checkDeviation($holding,$fund_list,$position);
        //$score_src = $score_src_re['percent'];
        $new_holding = UserHelper::doing($holding,$strategy);
        $new_hold = UserHelper::mergeHolding($new_holding,$fund_list);
        $cost = 0;
        $list = [];
        $all_ratio = 0;
        foreach($strategy['add'] as $row){
            $cost += $row['cost'];
            $type = -1;
            if(isset($fund_list['fund_type'][$row['code']])){
                $type = $fund_list['fund_type'][$row['code']];
                $type = $fund_list['pool_type'][$type];
            }
            $list[] = ['code'=>$row['code'],'amount'=>$row['amount'],'ratio'=>round($row['amount']/$amount,4), 'type'=>$type]; //todo zxb add type
            $all_ratio += round($row['amount']/$amount,4);
        }
        if($list && $all_ratio!=1){
            $list[0]['ratio'] += (1-$all_ratio);
        }
        $data = [
            'fee'=>$cost,
            'list'=>$list
        ];

        return [20000, 'Succeed', $data];
    }

    // 盈米宝充值
    public static function placeRechargeOrder($uid, $paymethod, $poId, $risk, $amount, $invest_plan=null, $fr=1)
    {
        $dt = Carbon::now();
        $txnId = TsTxnId::makePoTxnId($dt, 1, 1);

        if (!$txnId) {
            return [30000, '生成订单失败', ''];
        }

        DB::connection('mysql')->beginTransaction();
        try{
            $order = [
                'ts_txn_id' => $txnId,
                'ts_uid' => $uid,
                'ts_portfolio_id' => $poId,
                'ts_pay_method' => $paymethod,
                'ts_trade_type' => 1,
                'ts_trade_status' => 0,
                'ts_placed_amount' => $amount,
                'ts_placed_percent' => 0,
                'ts_accepted_at' => $dt,
                'ts_scheduled_at' => $dt,
                'ts_pos_term' => $fr,
                'ts_origin' => 8,
                'ts_risk' => $risk,
                'ts_placed_fee' => 0,
            ];
            if(!empty($invest_plan)){
                $order['ts_trade_type'] = 5;
                $order['ts_invest_plan_id'] = $invest_plan;
            }

            // 先将订单数据写入文件
            static::writeTsOrderToFile($order);

            // 然后再写入数据库
            $m = new TsOrder($order);
            $m->save();

            $buy_fund = YingmiWalletFund::where('id','>',0)->first();
            $chargeTxnId = TsTxnId::makeFundTxnId($txnId);
            $chargeSubOrder = [
                'ts_txn_id' => $chargeTxnId,
                'ts_uid' => $uid,
                'ts_portfolio_id' => $poId,
                'ts_portfolio_txn_id' => $txnId,
                'ts_fund_code' => $buy_fund['yw_fund_code'],
                'ts_fund_name' => $buy_fund['yw_fund_name'],
                'ts_trade_type' => 12,
                'ts_trade_status' => 0,
                'ts_placed_amount' => $amount,
                'ts_placed_share' => 0,
                'ts_pay_method' => $paymethod,
                'ts_accepted_at' => $dt,
                'ts_scheduled_at' => $dt,
                'ts_origin' => 8,
                'ts_placed_fee' =>0,
            ];
            if(!empty($invest_plan)){
                $chargeSubOrder['ts_invest_plan_id'] = $invest_plan;
            }
            $suborders = [];
            array_unshift($suborders, $chargeSubOrder);
            foreach ($suborders as $sub) {
                //
                // 先将订单数据写入文件
                //
                static::writeTsOrderFundToFile($sub);
                //
                // 然后再写入数据库
                //
                $m = new TsOrderFund($sub);
                $m->save();
            }

            DB::connection('mysql')->commit();

        } catch(\Exception $e) {
            Log::error('TsHelperZxb is error:'.'placeRechargeOrder('.$uid.','.$poId.','.$paymethod.','.$amount.','.$risk.','.$invest_plan.',)'.$e->getMessage().$e->getTraceAsString());

            DB::connection('mysql')->rollBack();

            return [30000, '订单错误', ''];
        }

        return [20000, 'Succeed', $txnId];
    }

    public static function placeWithdrawOrder($uid, $paymethod, $poId, $risk, $amount, $redeemType, $fr=1)
    {
        $dt = Carbon::now();
        $txnId = TsTxnId::makePoTxnId($dt, 2, 1);

        if (!$txnId) {
            return [30000, '生成订单失败', ''];
        }

        DB::connection('mysql')->beginTransaction();
        try{
            $order = [
                'ts_txn_id' => $txnId,
                'ts_uid' => $uid,
                'ts_portfolio_id' => $poId,
                'ts_pay_method' => $paymethod,
                'ts_trade_type' => 2,
                'ts_trade_status' => 0,
                'ts_placed_amount' => $amount,
                'ts_placed_percent' => 0,
                'ts_accepted_at' => $dt,
                'ts_scheduled_at' => $dt,
                'ts_pos_term' => $fr,
                'ts_origin' => 8,
                'ts_risk' => $risk,
                'ts_placed_fee' => 0,
            ];
            static::writeTsOrderToFile($order);
            $m = new TsOrder($order);
            $m->save();

            if ($redeemType == 1) {
                $withdrawType = 21;
            } else {
                $withdrawType = 22;
            }

            $fund = YingmiWalletFund::where('id','>',0)->first();
            $withdrawTxnId = TsTxnId::makeFundTxnId($txnId);
            $withdrawSubOrder = [
                'ts_txn_id' => $withdrawTxnId,
                'ts_uid' => $uid,
                'ts_portfolio_id' => $poId,
                'ts_portfolio_txn_id' => $txnId,
                'ts_fund_code' => $fund['yw_fund_code'],
                'ts_fund_name' => $fund['yw_fund_name'],
                'ts_trade_type' => $withdrawType,
                'ts_trade_status' => 0,
                'ts_placed_amount' => 0,
                'ts_placed_share' => $amount,
                'ts_pay_method' => $paymethod,
                'ts_accepted_at' => $dt,
                'ts_scheduled_at' => $dt,
                'ts_origin' => 8,
                'ts_placed_fee' =>0,
            ];

            static::writeTsOrderFundToFile($withdrawSubOrder);
            $m = new TsOrderFund($withdrawSubOrder);
            $m->save();

            DB::connection('mysql')->commit();
        } catch(\Exception $e) {

            Log::error('TsHelperZxb is error:'.'placeWithdrawOrder('.$uid.', '.$poId.', '.$paymethod.','.$amount.')'.$e->getMessage().$e->getTraceAsString());

            DB::connection('mysql')->rollBack();
        }

        return [20000, 'Succeed', $txnId];
    }
}
