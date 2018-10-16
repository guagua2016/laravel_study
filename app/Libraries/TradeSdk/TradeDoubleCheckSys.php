<?php namespace App\Libraries\TradeSdk;

use App\Libraries\TradeSdk\TradeStrategyHelper;
use App\Libraries\MfHelper;
use App\BnRaPoolFund;
use App\BnRaPool;
use App\FundInfos;
use App\BaseFundStatus;
use App\FundFee;
use App\BnMarkowitz;
use App\BaseDailyLimit;
use App\MfDeviationPool;
use App\TsPlanFundTmp;
use Log;

class TradeDoubleCheckSys
{
    /**
     * 获取用户组合购买、追加、赎回、调仓等信息 todo zhuxiaobin
     * $op 购买-buy-1，赎回-redeem-2，追加-append-3，定投-invest-4，调仓-adjust-5, 赎回盈米的组合（只用于op_list)-6, 延迟购买-11
     */
    // true:线上基金池 local:测试基金池
    private static $poolEnv = array(
        true  => [111010, 111020, 112020, 112050, 121010, 121020, 131010, 140010],
        'local'  => [111010, 111020, 112020, 112050, 121010, 121020, 131010, 140010],
        'dev'  => [111010, 111020, 112020, 112050, 121010, 121020, 131010, 140010],
    );
    // 风险对应global id
    public static $risk_list = ['1'=>'800001','2'=>'800002','3'=>'800003','4'=>'800004','5'=>'800005','6'=>'800006','7'=>'800007','8'=>'800008','9'=>'800009','10'=>'800010',];
    // 方便测试用的uid列表
    public static $testUids = ['1000001141', '1000000002', '1000000006', '1000134759'];
    // 测试入口
    public static function testMethod()
    {
        $uid = '1000000002';
        $plan_id = 90;
        $op = 1;
        $plan_tmp = TsPlanFundTmp::where('ts_uid', $uid)
            ->where('ts_plan_id', $plan_id)
            ->get();
        $holdings = array();
        $plans = array();
        foreach ($plan_tmp as $plan) {
            $fcode = $plan->ts_fund_code;
            $hamount = $plan->ts_amount_src;
            $pamount = $plan->ts_amount_dst;
            if(isset($holdings[$fcode])) {
                $holdings[$fcode] += $hamount;
            } else {
                $holdings[$fcode] = $hamount;
            }

            if(isset($plans[$fcode])) {
                $plans[$fcode] += $pamount;
            } else {
                $plans[$fcode] = $pamount;
            }
        }

        self::doubleCheck($uid, $holdings, $plans, $plan_id, $op, 10);

        //$risk = 10; //MfHelper::getUserRiskValue($uid);
        //$holdings = MfHelper::getUserHoldingInfo($uid);
        ////$holding['holdings'] = array();
        //$amount = 200000;
        //$redemption = 0;
        //$op = 1;
        //if ($op == 5) {
        //    $matches = TradeStrategyHelper::simulateTrade($uid, $risk, $holdings, $amount, $redemption, $op);
        //} else {
        //    $matches = TradeStrategyHelper::matchTrade($uid, $risk, $holdings, $amount, $redemption, $op);
        //}
        ////Log::info($matches);
        ////var_dump($matches);
        ////$result = self::checkDeviation($uid, $risk, $holdings, $amount, $redemtion, $op);
        //$result = self::doubleCheck($uid, $holdings, $matches, $op, $risk);
        //return $result;
    }
    /*
    // risk为操作的风险，主要针对买的风险，如果买的风险跟持仓风险不同则用当前买的风险

    */
    public static function doubleCheck($uid, $holding, $plan, $plan_id, $op, $risk)
    {
        Log::info("Trade double check, uid=$uid,[holding, plan, op, planid]=", [$holding, $plan, [$op], [$plan_id]]);
        //// 调仓后预估持仓check
        if ($op == 5) {
            $is_match = self::adjustCheckSys($uid, $plan);
            if ($is_match == false) {
                //Log::info("Trade double check, deviation check fail, uid=$uid,[holding, plan, op, planid]=", [$holding, $plan, [$op], [$plan_id]]);
                MfHelper::sendSms("调仓后偏离度检查double check不通过，请尽快处理.uid=$uid, planid=$plan_id", [18800067859, 18610562049, 13031127095]);
                return [];
            }
        //// op=1,2,3,4后持仓check
        } else {
            $is_match = self::deviaCheck($uid, $holding, $plan, $op, $risk);
            if ($is_match == false) {
                //Log::info("Trade double check, deviation check fail, uid=$uid,[holdings, matches, op, planid]=", [$holdings, $matches, [$op], [$plan_id]);
                MfHelper::sendSms("非调仓操作偏离度检查double check不通过，请尽快处理.uid=$uid, planid=$plan_id", [18800067859, 18610562049, 13031127095]);
                return [];
            }

        }

    }

    private static function deviaCheck($uid, $holdings, $plans, $op, $cur_risk)
    {
        $future_holdings = $plans;
        $user_holdings = $holdings;
        try {
            //有可能用户连续买好几个不同风险组合，以当前买的风险为基准检查偏离度
            Log::info("Trade double check: buy risk=$cur_risk, uid=$uid");
            $alloc_id = self::$risk_list[$cur_risk];
            $newestDate = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->max('ra_date');
            $newsRatio = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->where('ra_date', $newestDate)
                ->get(['ra_asset_id', 'ra_ratio']);
            // 最新配置比例
            $cateRatio = $newsRatio->pluck('ra_ratio', 'ra_asset_id');

            $hold_fund_codes = array_keys($future_holdings);
            $user_fund_codes = array_keys($user_holdings);
            $fundCate = BnRaPoolFund::whereIn('ra_fund_code', $hold_fund_codes)
                ->distinct('ra_fund_code')
                ->get(['ra_fund_code', 'ra_pool_id']);
            $fundCate = $fundCate->pluck('ra_pool_id', 'ra_fund_code');
            $cateAmount = array();
            $cateAmount[-1] = 0.0;
            $totalAmount = 0.0;
            foreach ($future_holdings as $key => $amount) {
                if (isset($fundCate[$key])) {
                    if (isset($cateAmount[$fundCate[$key]])) {
                        $cateAmount[$fundCate[$key]] += $amount;
                        $totalAmount += $amount;
                    } else {
                        $cateAmount[$fundCate[$key]] = $amount;
                        $totalAmount += $amount;
                    }
                } else {
                    $cateAmount[-1] += $amount;
                    $totalAmount += $amount;
                }
            }
            $user_fundCate = BnRaPoolFund::whereIn('ra_fund_code', $user_fund_codes)
                ->distinct('ra_fund_code')
                ->get(['ra_fund_code', 'ra_pool_id']);
            $user_fundCate = $user_fundCate->pluck('ra_pool_id', 'ra_fund_code');
            $user_cateAmount = array();
            $user_cateAmount[-1] = 0.0;
            $user_totalAmount = 0.0;
            foreach ($user_holdings as $key => $amount) {
                if (isset($user_fundCate[$key])) {
                    if (isset($user_cateAmount[$user_fundCate[$key]])) {
                        $user_cateAmount[$user_fundCate[$key]] += $amount;
                        $user_totalAmount += $amount;
                    } else {
                        $user_cateAmount[$fundCate[$key]] = $amount;
                        $user_totalAmount += $amount;
                    }
                } else {
                    $user_cateAmount[-1] += $amount;
                    $user_totalAmount += $amount;
                }
            }

            if($totalAmount < 10.0){
                Log::info("Trade double check:uid=$uid,left holding amount=$totalAmount");
                return true;
            }
            foreach ($cateAmount as $key => $cateAmt) {
                $cateAmount[$key] /= $totalAmount;
            }
            if ($user_totalAmount != 0.0){
                foreach ($user_cateAmount as $key => $cateAmt) {
                    $user_cateAmount[$key] /= $user_totalAmount;
                }
            }
            $bigCateRatio = $cateRatio;
            // foreach ($cateRatio as $key => $ratio) {
            //     if (isset($bigCateRatio[self::cateTrans($key)])) {
            //         $bigCateRatio[self::cateTrans($key)] += floatval($ratio);
            //     } else {
            //         $bigCateRatio[self::cateTrans($key)] = floatval($ratio);
            //     }
            // }
            $deviations = 0.0;
            $user_deviations = 0.0;
            if (isset($bigCateRatio[0]) or isset($cateAmount[0])) {
                Log::error("Trade double check:有其它大类不在数据库中");
                return false;
            }
            // 计算操作后未来持仓偏离度
            foreach ($cateAmount as $key => $ratio) {
                if (isset($bigCateRatio[$key])) {
                    $tmpVar = $ratio - $bigCateRatio[$key];
                    $deviations += (($tmpVar > 0.0) ? $tmpVar : 0.0);
                } else {
                    $deviations += $ratio;
                }
            }
            // 计算用户当前持仓
            if ($user_totalAmount != 0.0){
                foreach ($user_cateAmount as $key => $ratio) {
                    if (isset($bigCateRatio[$key])) {
                        $tmpVar = $ratio - $bigCateRatio[$key];
                        $user_deviations += (($tmpVar > 0.0) ? $tmpVar : 0.0);
                    } else {
                        $user_deviations += $ratio;
                    }
                }
            } else {
                $user_deviations = 1.0;
            }
            if ($op == 1 || $op == 2 || $op == 3 || $op == 4) {
                Log::info("Trade double check result, uid=$uid, deviations=$deviations, user_deviations=$user_deviations, op=$op");
                // 没有老组合概念了，统一处理
                //if ($op == 2 && !empty($ym_op)) {
                //    Log::info("Trade double check, 赎回有盈米不做检查,uid=$uid");
                //    Log::info("Trade double check:操作后,deviation:" . $deviations . ", op=" . $op . ", uid:" . $uid);
                //    Log::info("Trade double check,[future_ratio,alloc_ratio,user_ratio", [$cateAmount, $bigCateRatio, $user_cateAmount]);
                //    return True;
                //}
                if ($deviations >= ($user_deviations  + 0.1)) {
                    //$latest_deviation * 0.95 + 0.05
                    Log::info("Trade double check:操作后偏离度不符合常规,deviation=$deviations, op=$op, uid=$uid");
                    Log::info("Trade double check,[future_ratio,alloc_ratio,user_ratio", [$cateAmount, $bigCateRatio, $user_cateAmount]);
                    return false;
                }
            }
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception Trade double check, in
                matchCheck: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }
        return true;

    }
    private static function adjustCheckSys($uid, $plans)
    {
        $preHoldings = $plans;
        try {
            // 用户当前风险
            $risk = MfHelper::getUserRiskValue($uid);
            // 风险对应global id
            $alloc_id = self::$risk_list[$risk];
            // 最新一期配置日期
            $newestDate = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->max('ra_date');
            // 最新一期配置各大类比例
            $newsRatio = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->where('ra_date', $newestDate)
                ->get(['ra_asset_id', 'ra_ratio']);
            $cateRatio = $newsRatio->pluck('ra_ratio', 'ra_asset_id');
            // 未来可能持仓所有基金code
            $hold_fund_codes = array_keys($preHoldings);
            // 基金所属大类基金池id
            $fundCate = BnRaPoolFund::whereIn('ra_fund_code', $hold_fund_codes)
                ->distinct('ra_fund_code')
                ->get(['ra_fund_code', 'ra_pool_id']);
            $fundCate = $fundCate->pluck('ra_pool_id', 'ra_fund_code');
            // 可能持仓大类比例
            $cateAmount = array();
            // 未分类大类比例
            $cateAmount[-1] = 0.0;
            // 未来可能持仓总金额
            $totalAmount = 0.0;
            foreach ($preHoldings as $key => $amount) {
                if (isset($fundCate[$key])) {
                    if (isset($cateAmount[$fundCate[$key]])) {
                        $cateAmount[$fundCate[$key]] += $amount;
                        $totalAmount += $amount;
                    } else {
                        $cateAmount[$fundCate[$key]] = $amount;
                        $totalAmount += $amount;
                    }
                } else {
                    $cateAmount[-1] += $amount;
                    $totalAmount += $amount;
                }
            }
            // 未来持仓金额小于10元不做match检查
            if($totalAmount < 10){
                Log::info("Trade double check:调仓后持仓金额小于10元,uid=$uid");
                return true;
            }
            // 把大类未来持仓金额转成占比
            foreach ($cateAmount as $key => $cateAmt) {
                $cateAmount[$key] /= $totalAmount;
            }
            // 最新配置大类所占比例
            $bigCateRatio = $cateRatio;
            // 调仓后偏离度
            $deviations = 0.0;
            if (isset($bigCateRatio[0]) or isset($cateAmount[0])) {
                Log::info("Trade double check:有其它大类不在数据库中");
                return false;
            }
            // 与线上最新配置比例对比算偏离度
            foreach ($cateAmount as $key => $ratio) {
                if (isset($bigCateRatio[$key])) {
                    $tmpVar = $ratio - floatval($bigCateRatio[$key]);
                    $deviations += (($tmpVar > 0)? $tmpVar : 0.0);
                } else {
                    $deviations += $ratio;
                }
            }
            Log::info("Trade double check,uid=$uid, deviations=$deviations, op=5");
            // 调仓后偏离度大于0.1则不通过
            if ($deviations > 0.1) {
                Log::info("Trade double check:调仓后基金偏离度大于0.1, uid=$uid");
		Log::info("Trade double check,uid=$uid", [$cateAmount, $bigCateRatio]);
                return false;
            }
        } catch(\Exception $e) {
            Log::error(sprintf("Caught exception Trade double check, in
                adjustMatchCheck: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }
        return true;
    }

    /**
     * 义涛的双盲校验函数
     * @param $uid uid
     * @param $holdings 用户持仓 张哲生成
     * @param $matches 交易列表 晓彬生成
     * @param $op
     * @return array 为[]时表示目前没有可以进行的操作
     */
    public static function doubleCheckOld($uid, $holdings, $matches, $op, $risk=false)
    {
        if (in_array($uid, self::$testUids)) {
            Log::info("Trade double check uid=".$uid." risk=$risk");
        } else {
            Log::info("Trade double check start uid=$uid, op=$op"." risk=$risk");
            //return $matches;
        }
        // 晓彬返回空
        if (empty($matches)) {
            Log::info("Trade double check, return empty of zxb  uid=$uid, [holdings, matches, op]=",
                 [$holdings, $matches, [$op]]);
            MfHelper::sendSms('晓彬交易策略返回的交易列表为空，请尽快处理'.
                " uid=$uid", [18800067859, 13031127095, 18610562049]);
            return [];
        }
        // op=1
        $buyes = array();
        // op=2
        $selles = array();
        // 盈米组合操作
        $ym_op = array();
        // 大类没有可购买基金，延迟购买,op=7
        $big_asset_buy_delay = array();
        if ($op != 5) {
            foreach ($matches as $match) {
                $opx = $match['op'];
                if ($opx == 1) {
                    array_push($buyes, $match);
                } elseif ($opx == 2) {
                    array_push($selles, $match);
                } elseif ($opx == 6) {
                    $ym_op[$match['id']] = $match['redemption'];
                } elseif ($opx == 11) {
                    $big_asset_buy_delay[$match['pool']] = $match['amount'];
                }
            }
        }
        // buy check(没有检查用户单支基金购买上限)
        if (!empty($buyes)) {
            $can_buy = self::buyCheck($buyes, $uid);
            if (!$can_buy) {
                Log::info("Trade double check, buy check fail, uid=$uid,
                    [holdings, matches, op]", [$holdings, $matches, [$op]]);
                MfHelper::sendSms('购买基金池的double check不通过，请尽快处理'." uid=$uid", [18800067859, 18610562049, 13031127095]);
                return [];
            }
        }

        // sell check (没有检查是否把不在基金池中的基金优化卖出)
        if (!empty($selles)) {
            $can_sell = self::sellCheck($selles, $uid);
            if (!$can_sell) {
                Log::info("Trade double check, sell check fail, uid=$uid,
                    [holdings, matches, op]=", [$holdings, $matches, [$op]]);
                MfHelper::sendSms('赎回基金池的double check不通过，请尽快处理'." uid=$uid", [18800067859, 18610562049, 13031127095]);
                return [];
            }
        }

        // match check
        //// 调仓后预估持仓check
        if ($op == 5) {
            $is_match = self::adjustMatchCheck($holdings, $matches, $uid);
            if ($is_match == false) {
                Log::info("Trade double check, deviation check fail, uid=$uid,[holdings, matches, op]=", [$holdings, $matches, [$op]]);
                MfHelper::sendSms('调仓后偏离度检查double check不通过，请尽快处理'." uid=$uid", [18800067859, 18610562049, 13031127095]);
                return [];
            }
        //// op=1,2,3,4后持仓check
        } else {
            $is_match = self::matchCheck($buyes, $selles, $holdings, $uid, $op,$ym_op, $big_asset_buy_delay, $risk);
            if ($is_match == false) {
                Log::info("Trade double check, deviation check fail, uid=$uid,[holdings, matches, op]=", [$holdings, $matches, [$op]]);
                MfHelper::sendSms('非调仓操作偏离度检查double check不通过，请尽快处理'." uid=$uid", [18800067859, 18610562049, 13031127095]);
                return [];
            }

        }
        return $matches;
    }

    public static function buyCheck($buyes, $uid)
    {
        $funds = array();
        foreach ($buyes as $buy) {
            array_push($funds, $buy['fundCode']);
        }
        //是否在基金池
        $exist = self::fundsExist($funds, $uid);
        if (!$exist) {
            Log::error("Trade double check, funds not exists in fund poll " . $uid);
            return $exist;
        }
        //是否可购
        $buy_avai = self::fundsBuyAvai($buyes, $uid);
        if (!$buy_avai) {
            Log::error("Trade double check, funds can not buy " . $uid);
            return $buy_avai;
        }
        return true;
    }

    public static function sellCheck($selles, $uid)
    {
        $sell_avai = self::fundsSellAvai($selles, $uid);
        if (!$sell_avai) {
            Log::error("Trade double check, some funds can not sell " . $uid);
            return $sell_avai;
        }
        return true;
    }

    public static function adjustMatchCheck($holdings, $futureHoldings, $uid)
    {

        Log::info("Trade double check op = 5:holdings=",[$holdings]);
        Log::info("Trade double check op = 5:future_holdings=",[$futureHoldings]);
        // 新组合持仓
        $futureMfHolding = $futureHoldings['holding']['holding'];
        // 盈米组合持仓
        $futureYmHolding = $futureHoldings['holding']['yingmi'];

        // 未来可能持仓
        $preHoldings = array();
        // 未来可能持仓中加入盈米组合
        foreach ($futureYmHolding as $key => $values) {
            $ym_holdings = $values['list'];
            foreach ($ym_holdings as $ym_holding) {
                if (isset($preHoldings[$ym_holding['code']])) {
                    $preHoldings[$ym_holding['code']] += floatval($ym_holding['amount']);
                } else {
                    $preHoldings[$ym_holding['code']] = floatval($ym_holding['amount']);
                }
            }
        }
        // 未来可能持仓中加入新组合
        foreach ($futureMfHolding as $mfHolding) {
            if (isset($preHoldings[$mfHolding['code']])) {
                    $preHoldings[$mfHolding['code']] += floatval($mfHolding['amount']);
                } else {
                    $preHoldings[$mfHolding['code']] = floatval($mfHolding['amount']);
            }
        }
        try {
            // 用户当前风险
            $risk = MfHelper::getUserRiskValue($uid);
            // 风险对应global id
            $alloc_id = self::$risk_list[$risk];
            // 最新一期配置日期
            $newestDate = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->max('ra_date');
            // 最新一期配置各大类比例
            $newsRatio = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->where('ra_date', $newestDate)
                ->get(['ra_asset_id', 'ra_ratio']);
            $cateRatio = $newsRatio->pluck('ra_ratio', 'ra_asset_id');
            // 未来可能持仓所有基金code
            $hold_fund_codes = array_keys($preHoldings);
            // 基金所属大类基金池id
            $fundCate = BnRaPoolFund::whereIn('ra_fund_code', $hold_fund_codes)
                ->distinct('ra_fund_code')
                ->get(['ra_fund_code', 'ra_pool_id']);
            $fundCate = $fundCate->pluck('ra_pool_id', 'ra_fund_code');
            // 可能持仓大类比例
            $cateAmount = array();
            // 未分类大类比例
            $cateAmount[-1] = 0.0;
            // 未来可能持仓总金额
            $totalAmount = 0.0;
            foreach ($preHoldings as $key => $amount) {
                if (isset($fundCate[$key])) {
                    if (isset($cateAmount[$fundCate[$key]])) {
                        $cateAmount[$fundCate[$key]] += $amount;
                        $totalAmount += $amount;
                    } else {
                        $cateAmount[$fundCate[$key]] = $amount;
                        $totalAmount += $amount;
                    }
                } else {
                    $cateAmount[-1] += $amount;
                    $totalAmount += $amount;
                }
            }
            // 未来持仓金额小于10元不做match检查
            if($totalAmount < 10){
                Log::info("Trade double check:调仓后持仓金额小于10元,uid=$uid");
                return true;
            }
            // 把大类未来持仓金额转成占比
            foreach ($cateAmount as $key => $cateAmt) {
                $cateAmount[$key] /= $totalAmount;
            }
            // 最新配置大类所占比例
            $bigCateRatio = $cateRatio;
            // 调仓后偏离度
            $deviations = 0.0;
            if (isset($bigCateRatio[0]) or isset($cateAmount[0])) {
                Log::info("Trade double check:有其它大类不在数据库中");
                return false;
            }
            // 与线上最新配置比例对比算偏离度
            foreach ($cateAmount as $key => $ratio) {
                if (isset($bigCateRatio[$key])) {
                    $tmpVar = $ratio - floatval($bigCateRatio[$key]);
                    $deviations += (($tmpVar > 0)? $tmpVar : 0.0);
                } else {
                    $deviations += $ratio;
                }
            }
            Log::info("Trade double check,uid: ".$uid."'s deviations=".$deviations.",op=5");
            // 调仓后偏离度大于0.1则不通过
            if ($deviations > 0.1) {
                Log::info("Trade double check:调仓后基金偏离度大于0.1, uid=" . $uid);
		Log::info("Trade double check,uid=".$uid, [$cateAmount, $bigCateRatio]);
                return false;
            }
        } catch(\Exception $e) {
            Log::error(sprintf("Caught exception Trade double check, in
                adjustMatchCheck: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;

        }
        return true;
    }
    public static function matchCheck($buyes, $selles, $holdings, $uid, $op, $ym_op, $big_asset_buy_delay, $cur_risk)
    {
        Log::info("Trade double check:buyes=",[$buyes]);
        Log::info("Trade double check:selles=",[$selles]);
        Log::info("Trade double check:holdings=",[$holdings]);
        Log::info("Trade double check:ym_op=",[$ym_op]);
        Log::info("Trade double check:big_asset_buy_delay=",[$big_asset_buy_delay]);
        $curEnv = self::$poolEnv[\App::environment()];
        // 未来可能持仓
        $future_holdings = array();
        // 当前已经确认的持仓
        $cur_holdings = $holdings['holding'];
        // 当前正在确认中买的基金
        $cur_buyings = $holdings['buying'];
        // 当前正在确认中赎回的基金
        $cur_redeemings = $holdings['redeeming'];
        // 取消购买的基金
        $cur_cancel_buyings = $holdings['cancel_buying'];
        // 取消赎回的基金
        $cur_cancel_redeemings = $holdings['cancel_redeeming'];
        // 盈米持仓
        $yingmi = $holdings['yingmi'];
        foreach ($cur_holdings as $cur_holding) {
            $future_holdings[$cur_holding['code']] = floatval($cur_holding['amount']);
        }
        foreach ($cur_buyings as $cur_buy) {
            if (isset($future_holdings[$cur_buy['code']])) {
                $future_holdings[$cur_buy['code']] += floatval($cur_buy['amount']);
            } else {
                $future_holdings[$cur_buy['code']] = floatval($cur_buy['amount']);
            }
        }
        foreach ($cur_cancel_redeemings as $cur_cancel_redeeming) {
            if (isset($future_holdings[$cur_cancel_redeeming['code']])) {
                $future_holdings[$cur_cancel_redeeming['code']] += floatval($cur_cancel_redeeming['amount']);
            } else {
                $future_holdings[$cur_cancel_redeeming['code']] = floatval($cur_cancel_redeeming['amount']);
            }
        }
        $user_holdings = $future_holdings;
        foreach ($yingmi as $key => $values) {
            $ym_holdings = $values['list'];
            foreach ($ym_holdings as $ym_holding) {
                if (isset($user_holdings[$ym_holding['code']])) {
                    $user_holdings[$ym_holding['code']] += floatval($ym_holding['amount']);
                } else {
                    $user_holdings[$ym_holding['code']] = floatval($ym_holding['amount']);
                }
            }
        }
        foreach ($buyes as $buy) {
            if (isset($future_holdings[$buy['fundCode']])) {
                $future_holdings[$buy['fundCode']] += $buy['amount'];
            } else {
                $future_holdings[$buy['fundCode']] = $buy['amount'];
            }
        }

        foreach ($selles as $sell) {
            if (isset($future_holdings[$sell['fundCode']])) {
                $future_holdings[$sell['fundCode']] -= (($sell['total_asset'] / $sell['total_share']) * $sell['share']);
                //Log::info([$sell['total_asset'] , $sell['total_share'] , $sell['share'], $future_holdings[$sell['fundCode']]]);
            } else {
                $future_holdings[$sell['fundCode']] = $sell['total_asset'] / $sell['total_share'] * $sell['share'];
            }
        }

        foreach ($yingmi as $key => $values) {
            $ym_holdings = $values['list'];
            if (!isset($ym_op[$key])) {
                foreach ($ym_holdings as $ym_holding) {
                    if (isset($future_holdings[$ym_holding['code']])) {
                        $future_holdings[$ym_holding['code']] += floatval($ym_holding['amount']);
                    } else {
                        $future_holdings[$ym_holding['code']] = floatval($ym_holding['amount']);
                    }
                }
            } else {
                foreach ($ym_holdings as $ym_holding) {
                    if (isset($future_holdings[$ym_holding['code']])) {
                        $future_holdings[$ym_holding['code']] +=
                            floatval($ym_holding['amount']) *
                            (1.0 - $ym_op[$key]);
                    } else {
                        $future_holdings[$ym_holding['code']] =
                            floatval($ym_holding['amount']) *
                            (1.0 - $ym_op[$key]);
                    }
                }

            }
        }
        try {
            Log::info("Trade double check: risk=$cur_risk, uid=$uid");
            $alloc_id = self::$risk_list[$cur_risk];
            $newestDate = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->max('ra_date');
            $newsRatio = BnMarkowitz::where('ra_alloc_id', $alloc_id)
                ->where('ra_date', $newestDate)
                ->get(['ra_asset_id', 'ra_ratio']);
            // 最新配置比例
            $cateRatio = $newsRatio->pluck('ra_ratio', 'ra_asset_id');

            $hold_fund_codes = array_keys($future_holdings);
            $user_fund_codes = array_keys($user_holdings);
            $fundCate = BnRaPoolFund::whereIn('ra_fund_code', $hold_fund_codes)
                ->distinct('ra_fund_code')
                ->get(['ra_fund_code', 'ra_pool_id']);
            $fundCate = $fundCate->pluck('ra_pool_id', 'ra_fund_code');
            $cateAmount = array();
            $cateAmount[-1] = 0.0;
            $totalAmount = 0.0;
            foreach ($future_holdings as $key => $amount) {
                if (isset($fundCate[$key])) {
                    if (isset($cateAmount[$fundCate[$key]])) {
                        $cateAmount[$fundCate[$key]] += $amount;
                        $totalAmount += $amount;
                    } else {
                        $cateAmount[$fundCate[$key]] = $amount;
                        $totalAmount += $amount;
                    }
                } else {
                    $cateAmount[-1] += $amount;
                    $totalAmount += $amount;
                }
            }
            $user_fundCate = BnRaPoolFund::whereIn('ra_fund_code', $user_fund_codes)
                ->distinct('ra_fund_code')
                ->get(['ra_fund_code', 'ra_pool_id']);
            $user_fundCate = $user_fundCate->pluck('ra_pool_id', 'ra_fund_code');
            $user_cateAmount = array();
            $user_cateAmount[-1] = 0.0;
            $user_totalAmount = 0.0;
            foreach ($user_holdings as $key => $amount) {
                if (isset($user_fundCate[$key])) {
                    if (isset($user_cateAmount[$user_fundCate[$key]])) {
                        $user_cateAmount[$user_fundCate[$key]] += $amount;
                        $user_totalAmount += $amount;
                    } else {
                        $user_cateAmount[$fundCate[$key]] = $amount;
                        $user_totalAmount += $amount;
                    }
                } else {
                    $user_cateAmount[-1] += $amount;
                    $user_totalAmount += $amount;
                }
            }

            // 延迟购买
            if (!empty($big_asset_buy_delay)) {
                foreach ($big_asset_buy_delay as $key => $amount) {
                    if (isset($cateAmount[$key])) {
                        $cateAmount[$key] += $amount;
                        $totalAmount += $amount;
                    } else {
                        $cateAmount[$key] = $amount;
                        $totalAmount += $amount;
                    }
                }
            }
            if($totalAmount < 10.0){
		Log::info("Trade double check:uid=$uid,left holding amount=$totalAmount");
                return true;
            }
            foreach ($cateAmount as $key => $cateAmt) {
                $cateAmount[$key] /= $totalAmount;
            }
            if ($user_totalAmount != 0.0){
                foreach ($user_cateAmount as $key => $cateAmt) {
                    $user_cateAmount[$key] /= $user_totalAmount;
                }
            }
            $bigCateRatio = $cateRatio;
            // foreach ($cateRatio as $key => $ratio) {
            //     if (isset($bigCateRatio[self::cateTrans($key)])) {
            //         $bigCateRatio[self::cateTrans($key)] += floatval($ratio);
            //     } else {
            //         $bigCateRatio[self::cateTrans($key)] = floatval($ratio);
            //     }
            // }
            $deviations = 0.0;
            $user_deviations = 0.0;
            if (isset($bigCateRatio[0]) or isset($cateAmount[0])) {
                Log::error("Trade double check:有其它大类不在数据库中");
                return false;
            }
            // 计算操作后未来持仓偏离度
            foreach ($cateAmount as $key => $ratio) {
                if (isset($bigCateRatio[$key])) {
                    $tmpVar = $ratio - $bigCateRatio[$key];
                    $deviations += (($tmpVar > 0.0) ? $tmpVar : 0.0);
                } else {
                    $deviations += $ratio;
                }
            }
            // 计算用户当前持仓
            if ($user_totalAmount != 0.0){
                foreach ($user_cateAmount as $key => $ratio) {
                    if (isset($bigCateRatio[$key])) {
                        $tmpVar = $ratio - $bigCateRatio[$key];
                        $user_deviations += (($tmpVar > 0.0) ? $tmpVar : 0.0);
                    } else {
                        $user_deviations += $ratio;
                    }
                }
            } else {
                $user_deviations = 1.0;
            }
            if ($op == 1 || $op == 2 || $op == 3 || $op == 4) {
                Log::info("Trade double check result, uid=".$uid."'s deviations=".$deviations.",user_deviations=".$user_deviations.", op=".$op);
                if ($op == 2 && !empty($ym_op)) {
                    Log::info("Trade double check, 赎回有盈米不做检查,uid=$uid");
                    Log::info("Trade double check:操作后,deviation:" . $deviations . ", op=" . $op . ", uid:" . $uid);
                    Log::info("Trade double check,[future_ratio,alloc_ratio,user_ratio", [$cateAmount, $bigCateRatio, $user_cateAmount]);
                    return True;
                }
                if ($deviations >= ($user_deviations  + 0.1)) {
                    //$latest_deviation * 0.95 + 0.05
                    Log::info("Trade double check:操作后偏离度不符合常规,deviation:" . $deviations . ", op=" . $op . ", uid:" . $uid);
                    Log::info("Trade double check,[future_ratio,alloc_ratio,user_ratio", [$cateAmount, $bigCateRatio, $user_cateAmount]);
                    return false;
                }
            }
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception Trade double check, in
                matchCheck: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }
        return true;
    }

    public static function cateTrans($sCate)
    {
        // 0：不知分类，1：股票，2：债券，3：货币，4：标普，5：黄金，6：恒生
        $categories = array([0, 1, 2, 3, 4, 5, 6]);
        if ($sCate < 11) {
            return 0;
        } elseif ($sCate < 20) {
            return 1;
        } elseif ($sCate < 30) {
            return 2;
        } elseif ($sCate < 40) {
            return 3;
        } elseif ($sCate == 41) {
            return 4;
        } elseif ($sCate == 42) {
            return 5;
        } elseif ($sCate == 43) {
            return 6;
        } else {
            return 0;
        }
    }

    public static function fundsExist($funds, $uid)
                          {
        if (empty($funds)) {
            Log::info("Trade double checkout:blank funds pass here" . $uid);
            return true;
        }
        // 基金池环境
        $pool_ids = self::$poolEnv[\App::environment()];
        try {
            $all_funds = array();
            foreach ($pool_ids as $poid) {
                $newsest = BnRaPoolFund::where('ra_pool_id', $poid)
                    ->max('ra_date');
                $rows = BnRaPoolFund::where('ra_pool_id', $poid)
                    ->where('ra_date', $newsest)
                    ->distinct()
                    ->lists('ra_fund_code')
                    ->toArray();
                $all_funds = array_merge($all_funds, $rows);
            }

            $fund_not_exits = false;
            foreach ($funds as $ele) {
                $fund_code = $ele;
                if (!in_array($fund_code, $all_funds)) {
                    Log::error("Trade double check:fund not in fund pool
                        " . $fund_code . " " . $uid);
                    $fund_not_exits = true;
                }
            }
            if ($fund_not_exits == true) {
                return false;
            }
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception Trade double check, in
                fundsExists: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }

        return true;
    }

    public static function fundsBuyAvai($buyes, $uid)
    {
        $funds = array();
        foreach ($buyes as $buy) {
            array_push($funds, $buy['fundCode']);
        }
        if (empty($funds)) {
            Log::info("Trade double check:blank funds not pass" . $uid);
            return true;
        }
        // $fund_codes = array();
        try {
            $rows = FundInfos::whereIn('fi_code', $funds)
                //->whereNotIn('fi_yingmi_subscribe_status', [1])
                ->distinct('fi_code')
                ->get(['fi_yingmi_amount', 'fi_code', 'fi_yingmi_subscribe_status']);
            //->toArray();
            $subscis = $rows->pluck('fi_yingmi_subscribe_status', 'fi_code');
            $ymamounts = $rows->pluck('fi_yingmi_amount', 'fi_code');
        } catch (\Exception $e) {
            Log::error(sprintf("Trade double check:caught exception trade
                double check: %s\n%s", $e->getMessage(),
                $e->getTraceAsString()));
            return false;
        }
        //返回的基金有不可购买基金
        $is_buy_avai = true;
        foreach ($subscis as $key => $subsci) {
            if (!in_array($subsci, [0, 6])) {
                Log::error("Trade double check fund can not buy " . $key . " " . $uid);
                $is_buy_avai = false;
            }
        }
        if (!$is_buy_avai) {
            return $is_buy_avai;
        }
        //购买金额小于最低可购买金额
        //$is_amount_avai = true;
        //foreach ($buyes as $buy) {
        //    if ($buy['amount'] < $ymamounts[intval($buy['fundCode'])]) {
        //        Log::error("trade double check fund buy amount less than yingmiamount " . $buy['fundCode'] . " " . $uid);
        //        $is_amount_avai = false;
        //    }
        //}
        //if (!$is_amount_avai) {
        //    return $is_amount_avai;
        //}
        return true;
    }

    public static function fundsSellAvai($selles, $uid)
    {
        $funds = array();
        foreach ($selles as $sell) {
            array_push($funds, $sell['fundCode']);
        }
        if (empty($funds)) {
            Log::info("Trade double check:blank funds not pass" . $uid);
            return true;
        }
        // 是否可赎回
        try {
            $rows = FundInfos::whereIn('fi_code', $funds)
                ->whereNotIn('fi_yingmi_subscribe_status', [0, 5])
                ->get();
        } catch (\Exception $e) {
            Log::error(sprintf("Trade double check:caught exception trade
                double check sellAvi: %s\n%s",
                $e->getMessage(), $e->getTraceAsString()));
            return false;
        }
        //返回的基金有不可赎回基金
        if (!$rows->isEmpty()) {
            log::error("Trade double check fund can not sell " . $uid . " " . json_encode($rows));
            return false;
        }

        // 赎回份额是否大于最小可赎回金额
        $is_share_avai = true;
        try {
            $rows = FundFee::whereIn('ff_code', $funds)
                ->where('ff_type', 16)
                ->where('ff_fee_type', 3)
                ->distinct('ff_code')
                ->get(['ff_code', 'ff_fee']);
            $fffee = $rows->pluck('ff_fee', 'ff_code');
            foreach ($selles as $sell) {
                if ($sell['share'] < $fffee[($sell['fundCode'])]) {
                    // 全部赎回
                    if ($sell['share'] != $sell['total_share']) {
                        Log::error("Trade double check fund sell share less than yingmishare ". $sell['fundCode'] . " " . $uid);
                        $is_share_avai = false;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error(sprintf("Trade double check:caught exception trade
                double check sellAvi: %s\n%s",
                $e->getMessage(), $e->getTraceAsString()));
            return false;
        }

        if (!$is_share_avai) {
            return $is_share_avai;
        }

        return true;

    }
}
