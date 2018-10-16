<?php namespace App\Libraries;

use App\Http\Controllers\YingmiPortfolioController;
use App\Libraries\SmsService;
use App\YingmiWalletShareDetail;
use Carbon\Carbon;

use DB;
use Log;
use App\BnFundInfo;
use App\BnFundValue;
use App\MfFundTradeStatus;
use App\YingmiTradeStatus;

use App\YingmiAccount;
use App\YingmiPortfolioComposition;
use App\YingmiPortfolioInfo;
use App\YingmiPortfolioTradeStatus;
use App\YingmiFundProfit;
use App\YingmiJournalConfirm;
use App\YingmiJournalShare;
use App\YingmiShareDetail;
use App\FundInfos;

use App\Libraries\DirtyDumper;

use function App\Libraries\array_dict;

trait TsYmOrderUpdater
{
    /**
     * 使用ym_share_journal更新用户的份额信息
     */
    public function updateShareDetailTs($uid, $row, $real_time=false)
    {
        $base = sprintf('%s@%s(%s)', basename_class(__CLASS__), __FUNCTION__, $uid." ");
        $s0 = microtime(true);
        $checkCols = [
            'ys_uid', 'ys_account_id'
        ];

        if ($real_time) {
            $shareId = $row['shareId'];
            $fundCode = $row['fundCode'];

            $xtab = [
                'ys_account_id' => 'accountId',
                'ys_portfolio_id' => 'poCode',
                'ys_pay_method' => 'paymentMethodId',
                'ys_share_type' => 'shareType',
                'ys_share_total' => ['key' => 'totalShare', 'fmt' => 2],
                'ys_share_avail' => ['key' => 'avaiShare', 'fmt'=> 2],
                'ys_share_frozen' => ['key' => 'frozenShare', 'fmt' => 2],
                'ys_asset_total' => ['key' => 'totalShareAsset', 'fmt' => 2],
                'ys_yield_uncarried' => ['key' => 'yieldUncarried', 'fmt' => 2],
                'ys_nav' => ['key' => 'nav', 'fmt' => 4],
                'ys_nav_date' => 'navDate',
                'ys_div_mode' => 'dividendMethod',
                'ys_yield_accumulated' => ['key' => 'accumulatedProfit', 'fmt' => 2],
                'ys_yield' => ['key' => 'previousProfit', 'fmt' => 2],
                'ys_yield_date' => 'previousProfitTradeDate',
                'ys_yield_estimated' => ['key' => 'previousEstimatedProfit', 'fmt' => 2],
                'ys_yield_estimated_date' => 'previousEstimatedProfitTradeDate',
            ];
        } else {
            $shareId = $row['ym_share_id'];
            $fundCode = $row['ym_fund_code'];

            $xtab = [
                'ys_account_id' => 'ym_account_id',
                'ys_portfolio_id' => 'ym_portfolio_id',
                'ys_pay_method' => 'ym_pay_method',
                'ys_share_type' => 'ym_share_type',
                'ys_share_total' => ['key' => 'ym_total_share', 'fmt' => 2],
                // 'ys_share_avail' => ['key' => 'avaiShare', 'fmt'=> 2],
                'ys_share_frozen' => ['key' => 'ym_frozen_share', 'fmt' => 2],
                // 'ys_asset_total' => ['key' => 'totalShareAsset', 'fmt' => 2],
                'ys_yield_uncarried' => ['key' => 'ym_yield_uncarried', 'fmt' => 2],
                'ys_nav' => ['key' => 'ym_nav', 'fmt' => 4],
                'ys_nav_date' => 'ym_nav_Date',
                'ys_div_mode' => 'ym_div_mode',
                'ys_yield_accumulated' => ['key' => 'ym_accumulated_yield', 'fmt' => 2],
                'ys_yield' => ['key' => 'ym_yield', 'fmt' => 2],
                'ys_yield_date' => 'ym_nav_date',
                // 'ys_yield_estimated' => ['key' => 'previousEstimatedProfit', 'fmt' => 2],
                // 'ys_yield_estimated_date' => 'previousEstimatedProfitTradeDate',
            ];
        }


        //
        // 根据基金code加载基金ID和名称
        //
        $s1 = microtime(true);
        $fi = BnFundInfo::findByCode($fundCode, 'dummy');
        $e1 = microtime(true);
        $msg_1 = TsHelper::getTimingMsg($s1, $e1, 1, $base, 'get bn fund info');

        $shareData = [
            'ys_uid' => $uid,
            'ys_share_id' => $shareId,
            'ys_fund_id' => $fi->fi_globalid,
            'ys_fund_code' => $fundCode,
            'ys_fund_type' => $fi->fi_type,
        ];

        foreach ($xtab as $k => $vv) {
            if (is_array($vv)) {
                $v = $vv['key'];
                $fmt = $vv['fmt'];
            } else {
                $v = $vv;
                $fmt = 0;
            }

            if (isset($row[$v]) && $row[$v]) {

                if (!$fmt) {
                    $shareData[$k] = $row[$v];
                } else {
                    $shareData[$k] = number_format((double)$row[$v] + 0.000000001, $fmt, '.', '');
                }
            }
        }

        $modified = false;
        //
        // 分两种情况处理：
        //
        // 1) 如果$shareId对应的记录存在，则直接更新该记录。
        //
        // 2) 如果$shareId对应的记录不存在，但设置了对应的组合代码poCode, 则还
        //    需要根据组合代码，基金代码和pay_method来确定对应的份额是否存在。
        //

        $msg_11 = $msg_22 = '';
        $s11 = microtime(true);
        $share = YingmiShareDetail::where('ys_share_id', $shareId)->first();
        $e11 = microtime(true);
        $msg_11 = TsHelper::getTimingMsg($s11, $e11, 1, $base, 'get share detail cost');
        if (!$share && isset($shareData['ys_portfolio_id']) && isset($shareData['ys_pay_method'])) {
            $s22 = microtime(true);
            $shares = YingmiShareDetail::where('ys_portfolio_id', $shareData['ys_portfolio_id'])
                ->where('ys_fund_id', $shareData['ys_fund_id'])
                ->where('ys_pay_method', $shareData['ys_pay_method'])
                ->get();
            if ($shares->count() > 1) {
                Log::error('multiplle share detail matched, use first!', [
                    $shareData, $shares->toArray(),
                ]);
            }
            $e22 = microtime(true);
            $msg_22 = TsHelper::getTimingMsg($s22, $e22, $shares->count(), $base, 'get share detail cost');
            $share = $shares->first();
        }
        Log::info($base. 'step 1 ' .$msg_11);
        if ($msg_22 != '') {
            Log:;info($base. 'step 2 '. $msg_22);
        }

        if ($share) {
            $s33 = microtime(true);
            //
            // 进行必要的安全性检查
            //
            $failed = false;
            foreach ($checkCols as $c) {
                if ($share->{$c} && $shareData[$c] && $share->{$c} != $shareData[$c]) {
                    Log::error('column value mismatch: ', [
                        "share.$c"  => $share->{$c},
                        "info.$c" => $shareData[$c],
                        'ys_id' => $share->id,
                        'row' => $row,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return [$uid, $fi->fi_globalid, $shareId];
            }

            //
            // 更新订单信息
            //
            $share->fill($shareData);
            //
            // 防止覆盖最新的收益和累计收益
            //
            if ($share->ys_nav_date < $share->getOriginal('ys_nav_date')) {
                $share->ys_nav = $share->ys_nav;
                $share->ys_nav_date = $share->getOriginal('ys_nav_date');
            }
            if ($share->ys_share_total != 0) {
                if ($share->ys_yield_date < $share->getOriginal('ys_yield_date')) {
                    $share->ys_yield = $share->ys_yield;
                    $share->ys_yield_date = $share->getOriginal('ys_yield_date');
                }
                if ($share->ys_yield_estimated_date < $share->getOriginal('ys_yield_estimated_date')) {
                    $share->ys_yield_estimated = $share->ys_yield_estimated;
                    $share->ys_yield_estimated_date = $share->getOriginal('ys_yield_estimated_date');
                }
            }

            if ($share->isDirty()) {
                DirtyDumper::xlogDirty($share, 'share detail update', ['uid' => $uid, 'ys_share_id' => $shareId]);
                $share->save();
                $modified = true;
            }
            $e33 = microtime(true);
            $msg33 = TsHelper::getTimingMsg($s33, $e33, 1, $base, 'update one share cost');
        } else {
            Log::info('share detail insert', [
                'uid' => $uid,
                'ys_share_id' => $shareId,
                'fundCode' => $shareData['ys_fund_code'],
                'pay_method' => $shareData['ys_pay_method'],
                'ys_portfolio_id' => isset($shareData['ys_portfolio_id']) ? $shareData['ys_portfolio_id'] : '0',
            ]);

            $share = new YingmiShareDetail($shareData);
            $share->save();
            $modified = true;
        }

        $e0 = microtime(true);
        $msg0 = TsHelper::getTimingMsg($s0, $e0, 1, $base, 'update one share cost');

        if ($modified) {
            return [$uid, $fi->fi_globalid, $share->ys_portfolio_id];
        } else {
            return [$uid, $fi->fi_globalid, false];
        }

    }

    // public function updateOrderDatabaseYingmiJournalConfirm($confirm_rows)
    // {
    //     //dd($confirm_rows);
    //     $cols = collect($confirm_rows);
    //     $md5s = $cols->pluck('ym_md5')->toArray();

    //     $olds = YingmiJournalConfirm::whereIn('ym_md5', $md5s)
    //           ->select('ym_md5')
    //           ->get()
    //           ->pluck('ym_md5')
    //           ->toArray();
    //     $olds = array_flip($olds);

    //     $cols = $cols->reject(function($row) use ($olds) {
    //         return array_key_exists($row['ym_md5'], $olds);
    //     });
    //     $news = $cols->toArray();

    //     YingmiJournalConfirm::insert($news);

    //     Log::info(__FUNCTION__." row to update ".count($news));

    //     return true;
    // }

    // public function updateOrderDatabaseYingmiJournalShare($share_rows)
    // {
    //     $cols = collect($share_rows);
    //     $md5s = $cols->pluck('ym_md5')->toArray();

    //     $olds = YingmiJournalShare::whereIn('ym_md5', $md5s)
    //           ->select('ym_md5')
    //           ->get()
    //           ->pluck('ym_md5')
    //           ->toArray();
    //     $olds = array_flip($olds);

    //     $cols = $cols->reject(function($row) use ($olds) {
    //             return array_key_exists($row['ym_md5'], $olds);
    //     });
    //     $news = $cols->toArray();

    //     YingmiJournalShare::insert($news);

    //     Log::info(__FUNCTION__." row to update ".count($news));

    //     return true;
    // }


    public function updateOrderDatabaseTs($uid, $order, $row = false, $map = null)
    {
        $fundCode = $order['fundCode'];
        $tradeType = $order['fundOrderCode'];

        if ($row) {
            $fi = (object)[
                'fi_globalid' => $row->yt_fund_id,
                'fi_name' => $row->yt_fund_name,
            ];
        } else {
            $fi = BnFundInfo::findByCode($fundCode, 'dummy'); // 根据基金code加载基金ID和名称
        }

        $xtab = [
            'yt_txn_id' => 'brokerOrderNo',
            'yt_portfolio_id' => 'poCode',
            'yt_account_id' => 'accountId',
            'yt_yingmi_order_id' => 'orderId',
            'yt_share_type' => 'shareType',
            'yt_trade_status' => 'confirmStatus',
            'yt_placed_amount' => 'tradeAmount',
            'yt_placed_share' => 'tradeShare',
            'yt_acked_amount' => 'successAmount',
            'yt_acked_share' => 'successShare',
            'yt_acked_fee' => 'fee',
            'yt_pay_status' => 'payStatus',
            'yt_pay_currency' => 'tradeCurrency',
            'yt_pay_method' => 'paymentMethodId',
            'yt_wallet_id' => 'walletId',
            'yt_extra' => 'tradeExta',
            'yt_yingmi_ta_id' => 'taId',
            'yt_redeem_pay_date' => 'transferIntoDate',
        ];

        $orderInfo = [
            'yt_uid' => $uid,
            'yt_fund_id' => $fi->fi_globalid,
            'yt_fund_code' => $fundCode,
            'yt_fund_name' => $fi->fi_name,
            'yt_trade_type' => $tradeType,
        ];

        foreach ($xtab as $k => $v) {
            if (isset($order[$v]) && !is_null($order[$v]) && $order[$v] != '') {
                $orderInfo[$k] = $order[$v];
            }
        }

        if (isset($order['orderCreatedOn']) && $order['orderCreatedOn'] !== null) {
            $carbonPlaced = Carbon::parse($order['orderCreatedOn']);
            $orderInfo['yt_placed_date'] = $carbonPlaced->toDateString();
            $orderInfo['yt_placed_time'] = $carbonPlaced->toTimeString();
        }

        if (isset($order['orderTradeDate']) && $order['orderTradeDate'] !== null) {
            $carbonTrade = Carbon::parse($order['orderTradeDate']);
            $orderInfo['yt_trade_date'] = $carbonTrade->toDateString();

            $val = BnFundValue::where('fv_fund_id', $orderInfo['yt_fund_id'])
                ->where('fv_date', $orderInfo['yt_trade_date'])
                ->first();

            if ($val) {
                $orderInfo['yt_trade_nav'] = $val->fv_nav;
            } else {
                $orderInfo['yt_trade_nav'] = '0.0000';
            }
            //
            // [XXX] 傻逼盈米有几个特殊的订单，3点后提交的，交易日是却是当天，
            // 这个只好打个丑陋的补丁。也即：以回传的交易日为准，如果交易日是
            // 2016-08-23，但订单是2016-08-23 15:00:00之后提交，则忽略这个订单
            // 的提交时间。
            //
            if ($orderInfo['yt_placed_date'] == $orderInfo['yt_trade_date']
                && $orderInfo['yt_placed_time'] >= '15:00:00') {
                Log::error("yt_trade_date & yt_placed_time confilict, ignore yt_placed_time", [
                    'yt_yingmi_order_id' => $orderInfo['yt_yingmi_order_id']
                ]);
                unset($orderInfo['yt_placed_time']);
            }
        }

        if (isset($order['orderConfirmDate']) && $order['orderConfirmDate'] !== null) {
            $carbonAcked = Carbon::parse($order['orderConfirmDate']);
            $orderInfo['yt_acked_date'] = $carbonAcked->toDateString();
        }

        if (isset($order['poOrder']) && $order['poOrder'] !== null) {
            $orderInfo['yt_portfolio_txn_id'] = $order['poOrder'];
        }

        if (isset($orderInfo['yt_txn_id'])) {
            if (!isset($orderInfo['yt_portfolio_txn_id']) || !$orderInfo['yt_portfolio_txn_id']) {
                $mfOrder = MfFundTradeStatus::where('mf_txn_id', $orderInfo['yt_txn_id'])->first();
                if ($mfOrder) {
                    $orderInfo['yt_portfolio_txn_id'] = $mfOrder->mf_portfolio_txn_id;
                    if (!isset($orderInfo['yt_portfolio_id']) || !$orderInfo['yt_portfolio_id']) {
                        $orderInfo['yt_portfolio_id'] = $mfOrder->mf_portfolio_id;
                    }
                }
            }
        }

        $r1 = $r2 = false;
        if ($tradeType != "036") {
            //盈米宝的订单，需要保存payment_method
            if (!is_null($map) && is_array($map)) {
                if (substr($tradeType, 0,1) == 'W') {
                    $payment_method = null;
                    $wallet_id = null;
                    if (isset($order['paymentMethodId'])) {
                        $payment_method = $order['paymentMethodId'];
                    }

                    if (isset($order['walletId'])) {
                        $wallet_id = $order['walletId'];
                    }

                    if (!is_null($wallet_id) && is_null($payment_method)) {
                        if (isset($map[$wallet_id])) {
                            $payment_method = $map[$wallet_id];
                        }
                    } else if (is_null($wallet_id) && !is_null($payment_method)) {
                        $map_revert = array_flip($map);
                        if (isset($map_revert[$payment_method])){
                            $wallet_id = $map_revert[$payment_method];
                        }
                    }

                    $orderInfo['yt_pay_method']= $payment_method;
                    $orderInfo['yt_wallet_id']= $wallet_id;
                }
            }


            $r1 = $this->dbUpdateRow($orderInfo, $row, false);
        } else {
            $r1 = $this->dbUpdateRow($orderInfo, $row, true);

            $convertFundCode = $order['destFundCode'];
            $fi2 = BnFundInfo::findByCode($convertFundCode, 'dummy');

            $xtab2 = [
                'yt_share_type' => 'destShareType',
                'yt_acked_amount' => 'convertSuccessAmount',
                'yt_acked_share' => 'convertSuccessShare',
            ];

            $info2 = [
                'yt_fund_id' => $fi2->fi_globalid,
                'yt_fund_code' => $convertFundCode,
                'yt_fund_name' => $fi2->fi_name,
                'yt_trade_type' => 'X36',
                'yt_placed_amount' => '0.00',
                'yt_placed_share' => '0.00',
                'yt_acked_fee' => '0.00',
            ];

            foreach ($xtab2 as $k => $v) {
                if (isset($order[$v]) && $order[$v] !== null && $order[$v] != '') {
                    $info2[$k] = $order[$v];
                }
            }

            $orderInfo2 = array_replace($orderInfo, $info2);

            $r2 = $this->dbUpdateRow($orderInfo2, false, true);
        }

        DB::transaction(function () use ($r1, $r2) {
            if ($r1) {
                $r1->save();
            }
            if ($r2) {
                $r2->save();
            }
        });

        return true;
    }

    protected function dbUpdateRowTs($orderInfo, $row, $shouldTradeTypeMatch = false)
    {
        $checkCols = [
            'yt_uid',
            'yt_account_id'
        ];

        $txn = false;
        $yingmiId = false;

        if (!$row) {
            if (isset($orderInfo["yt_txn_id"]) && $orderInfo["yt_trade_type"] == 'W04') {
                $yingmiId = $orderInfo['yt_yingmi_order_id'];
                $model = YingmiTradeStatus::where('yt_yingmi_order_id', $yingmiId);
            } elseif (isset($orderInfo["yt_txn_id"]) && !empty($orderInfo["yt_txn_id"])) {
                $txn = $orderInfo["yt_txn_id"];
                $model = YingmiTradeStatus::where('yt_txn_id', $txn);
            } else {
                $yingmiId = $orderInfo['yt_yingmi_order_id'];
                $model = YingmiTradeStatus::where('yt_yingmi_order_id', $yingmiId)->orderBy('id', 'DESC');
            }
            if ($shouldTradeTypeMatch) {
                $model = $model->where('yt_trade_type', $orderInfo['yt_trade_type']);
            }

            $rows = $model->get();
            if ($rows->count() > 1) {
                Log::error('duplicated yingmi_trade_status dectected', [
                    'yt_txn_id', $orderInfo['yt_txn_id'],
                    'yt_yingmi_order_id' => $orderInfo['yt_yingmi_order_id'],
                ]);
            }
            $row = $rows->first();
        }

        // if ($orderInfo['yt_yingmi_order_id'] == '2016082200050368') {
        //     dd($row, $orderInfo);
        // }


        if ($row) {
            // 进行必要的安全性检查
            //
            //
            $failed = false;
            foreach ($checkCols as $c) {
                if ($row->{$c} != $orderInfo[$c]) {
                    Log::error('column value mismatch: ', [
                        "order.$c" => $row->{$c},
                        "info.$c" => $orderInfo[$c],
                        'yt_id' => $row->id,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return false;
            }

            //
            // 更新订单信息
            //
            $row->fill($orderInfo);

            if ($row->isDirty()) {
                DirtyDumper::xlogDirty($row, 'trade status update', ['yt_txn_id' => $row->yt_txn_id, 'yt_yingmi_order_id' => $row->yt_yingmi_order_id]);
            } else {
                $row = false;
            }
        } else {
            Log::info('trade status insert', [
                'uid' => $orderInfo['yt_uid'],
                'yt_txn_id' => $txn,
                'yt_yingmi_order_id' => isset($orderInfo['yt_yingmi_order_id']) ? $orderInfo['yt_yingmi_order_id'] : '',
                'yt_portfolio_id' => isset($orderInfo['yt_portfolio_id']) ? $orderInfo['yt_portfolio_id'] : '',
                'yt_fund_id' => $orderInfo['yt_fund_id'],
                'yt_fund_code' => $orderInfo['yt_fund_code'],

            ]);

            $row = new YingmiTradeStatus($orderInfo);
        }

        // if ($orderInfo['yt_yingmi_order_id'] == '2016072500021254') {
        //     dd($row, $orderInfo);
        // } else {
        //     echo $orderInfo['yt_yingmi_order_id'], ",", $yingmiId, "\n";
        // }
        return $row;
    }

    public function updatePoOrderDatabaseTs($uid, $order, $row = false)
    {
        $poId = $order['poCode'];
        $tradeType = $order['orderCode'];

        $account = YingmiAccount::where('ya_uid', $uid)->first();
        $info = YingmiPortfolioInfo::find($poId);
        if ($info) {
            $adjustId = $info->yp_adjustment_id;
        } else {
            $adjustId = 0;
        }

        $orderInfo = [
            'yp_uid' => $uid,
            'yp_portfolio_id' => $poId,
            'yp_trade_type' => $tradeType,
            'yp_account_id' => $account->ya_account_id,
            //'yp_adjustment_id' => $adjustId,
        ];

        $xtab = [
            'yp_txn_id' => 'brokerOrderNo',
            'yp_trade_status' => 'confirmStatus',
            'yp_placed_amount' => 'tradeAmount',
            'yp_placed_percentage' => 'redeemRatio',
            'yp_acked_date' => 'orderConfirmDate',
            'yp_pay_status' => 'payStatus',
            'yp_yingmi_order_id' => 'orderId',
            'yp_invest_plan_id' => 'investPlanId',
            'yp_trade_date' => 'orderTradeDate',
        ];

        $contents = [
            'yp_acked_amount' => 'successAmount',
            'yp_acked_fee' => 'fee',
        ];

        foreach ($xtab as $k => $v) {
            if (isset($order[$v]) && !is_null($order[$v])) {
                $orderInfo[$k] = $order[$v];
            }
        }

        foreach ($order['compositionOrders'] as $composite) {
            foreach ($contents as $k => $v) {
                if (isset($composite[$v]) && !is_null($composite[$v])) {
                    if (isset($orderInfo[$k])) {
                        $orderInfo[$k] += round($composite[$v], 2);
                    } else {
                        $orderInfo[$k] = round($composite[$v], 2);
                    }
                }
            }
        }

        if (isset($order['orderCreatedOn']) && $order['orderCreatedOn'] !== null) {
            $carbonPlaced = Carbon::parse($order['orderCreatedOn']);
            $orderInfo['yp_placed_date'] = $carbonPlaced->toDateString();
            $orderInfo['yp_placed_time'] = $carbonPlaced->toTimeString();
        }

        // if (isset($order['paymentMethodId']) && isset($order['walletId'])) {
        //     $orderInfo['yp_pay_method'] = $order['paymentMethodId'];
        // } elseif (isset($order['paymentMethodId'])) {
        //     $orderInfo['yp_pay_type'] = 1;
        //     $orderInfo['yp_pay_method'] = $order['paymentMethodId'];
        // } else {
        //     if (isset($order['walletId'])) {
        //         $orderInfo['yp_pay_type'] = 2;
        //         $orderInfo['yp_pay_method'] = $order['walletId'];
        //     }
        // }

        $r = $this->dbUpdatePoRowTs($orderInfo, $row);

        //
        // 组合各个成分的订单
        //
        $compositeOrderInfo = $this->compositeOrderInfoTs($uid, $order, $r);
        $rs = [];
        foreach ($compositeOrderInfo as $composite) {
            $tmpRow = $this->dbUpdateRowTs($composite, false);

            if ($tmpRow) {
                $rs[] = $tmpRow;
            }
        }

        DB::transaction(function () use ($r, $rs) {
            if ($r) {
                $r->save();
            }

            foreach ($rs as $r) {
                $r->save();
            }
        });

        return true;
    }

    protected function compositeOrderInfoTs($uid, $order, $row = false)
    {
        $xtab = [
            'yt_yingmi_order_id' => 'orderId',
            'yt_trade_type' => 'orderCode',
            'yt_fund_code' => 'prodCode',
            'yt_trade_status' => 'confirmStatus',
            //'yt_fund_name' => 'prodName',
            'yt_share_type' => 'shareType',
            'yt_acked_date' => 'orderConfirmDate',
            'yt_trade_date' => 'orderTradeDate',
            'yt_placed_amount' => 'tradeAmount',
            'yt_placed_share' => 'tradeShare',
            'yt_acked_amount' => 'successAmount',
            'yt_acked_share' => 'successShare',
            'yt_acked_fee' => 'fee',
            'yt_pay_status' => 'payStatus',
        ];

        $account = YingmiAccount::where('ya_uid', $uid)->first();

        $composites = $order['compositionOrders'];
        $codes = array_keys(array_dict($composites, function ($v) {
            return $v['prodCode'];
        }));
        $funds = BnFundInfo::whereIn('fi_code', $codes)
            ->get();
        $funds = array_dict($funds, 'fi_code');

        $compositeOrderInfo = [];
        foreach ($order['compositionOrders'] as $composite) {
            $orderInfo = [
                'yt_uid' => $uid,
                'yt_account_id' => $account->ya_account_id,
                'yt_portfolio_txn_id' => $order['brokerOrderNo'],
                'yt_pay_method' => $order['paymentMethodId'],
            ];

           if ($row && $row->yp_trade_type == 'P02' && $row->yp_trade_date) {
               $orderInfo['yt_trade_date'] = $row->yp_trade_date;
           }

            foreach ($xtab as $k => $v) {
                if (isset($composite[$v]) && !is_null($composite[$v])) {
                    $orderInfo[$k] = $composite[$v];
                }
            }

            if (isset($composite['orderCreatedOn']) && $composite['orderCreatedOn'] !== null) {
                $carbonPlaced = Carbon::parse($composite['orderCreatedOn']);
                $orderInfo['yt_placed_date'] = $carbonPlaced->toDateString();
                $orderInfo['yt_placed_time'] = $carbonPlaced->toTimeString();
            }

            if (isset($funds[$composite['prodCode']])) {
                $orderInfo['yt_fund_name'] = $funds[$composite['prodCode']]->fi_name;
                $orderInfo['yt_fund_id'] = $funds[$composite['prodCode']]->fi_globalid;
            }

            $compositeOrderInfo[] = $orderInfo;
        }

        return $compositeOrderInfo;
    }

    protected function dbUpdatePoRowTs($orderInfo, $row, $shouldTradeTypeMatch = false)
    {
        $checkCols = [
            'yp_uid',
            'yp_account_id'
        ];

        $txn = false;
        $yingmiId = false;

        if (!$row) {
            if (isset($orderInfo["yp_txn_id"])) {
                $txn = $orderInfo["yp_txn_id"];
                $model = YingmiPortfolioTradeStatus::where('yp_txn_id', $txn);
            } else {
                $yingmiId = $orderInfo['yp_yingmi_order_id'];
                $model = YingmiPortfolioTradeStatus::where('yp_yingmi_order_id', $yingmiId);
            }
            if ($shouldTradeTypeMatch) {
                $model = $model->where('yp_trade_type', $orderInfo['yp_trade_type']);
            }

            $row = $model->first();
        }

        $time = $orderInfo['yp_placed_date'] . ' ' . $orderInfo['yp_placed_time'];
        $adjust = YingmiPortfolioComposition::where('yp_portfolio_id', $orderInfo['yp_portfolio_id'])
                ->where('created_at', '<', $time)
                ->orderBy('created_at', 'DESC')
                ->first();

        if ($row) {
            //
            // 进行必要的安全性检查
            //
            $failed = false;
            foreach ($checkCols as $c) {
                if ($row->{$c} != $orderInfo[$c]) {
                    Log::error('column value mismatch: ', [
                        "order.$c" => $row->{$c},
                        "info.$c" => $orderInfo[$c],
                        'yp_id' => $row->id,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return false;
            }

            //组合的调仓状态由变P2 or P4时，发送短信给用户
            try{
                if ($row->yp_trade_type == 'P04' && ($row->yp_trade_status == 'P0' || $row->yp_trade_status == 'P1') && ($orderInfo['yp_trade_status'] == 'P2' || $orderInfo['yp_trade_status'] == 'P4')) {
                    //disable this after qian ren qian mian on line
                    /*$place_time = $row->yp_placed_date." ".$row->yp_placed_time;
                    $place_time = date('Y年m月d日 H:i:s', strtotime($place_time));
                    $portfolio_name = YingmiPortfolioInfo::where('id', $row->yp_portfolio_id)->first();
                    if($portfolio_name){
                        $portfolio_name = $portfolio_name->yp_lcmf_name;
                    }
                    if($orderInfo['yp_trade_status'] == 'P2'){
                        $tmp = '全部确认成功';
                    }else{
                        $tmp = '部分确认成功';
                    }
                    $tel = config('koudai.customer_tel');
                    $msg = "尊敬的用户您好，您于" . $place_time . "进行了" .$portfolio_name
                        . "的跟调，该调仓交易已".$tmp."。您可于15点后登陆我的资产->智能组合->交易记录查看。如有疑问请咨询" . $tel . "。";
                    $attrs = [
                        'channel' => 1,
                    ];
                    */
                }
            }catch(\Exception $e){
                Log::error(sprintf("Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
            }
            //
            // 更新订单信息
            //
            $row->fill($orderInfo);

            if ($row->isDirty()) {
                DirtyDumper::xlogDirty($row, 'trade status update', ['yp_txn_id' => $txn]);
            } else {
                $row = false;
            }
        } else {
            $row = new YingmiPortfolioTradeStatus($orderInfo);

            if ($row->yp_adjustment_id == 0  && $adjust) {
                $row->yp_adjustment_id = $adjust->yp_portfolio_adjustment_id;
            }
        }

        return $row;
    }

    public function updateFundProfitDatabaseTs($uid, $fundCode, $profit_list)
    {
        $result = false;
        $inserted = [];

        $fi = BnFundInfo::findByCode($fundCode, 'dummy'); // 根据基金code加载基金ID和名称

        $xtab = [
            'yw_trade_date' => 'tradeDate',
            'yw_fund_code' => 'fundCode',
            'yw_share_id' => 'shareId',
            'yw_total_share' => 'totalShare',
            'yw_total_value' => 'value',
            'yw_profit' => 'profit',
            'yw_estimated' => 'isEstimated',
            'yw_nav' => 'nav',
            'yw_payment_method' => 'paymentMethodId',
        ];


        $updated_at = date('Y-m-d H:i:s');
        foreach ($profit_list as $profits)
        {
            if (!isset($profits['shareId'])) {
                continue;
            }


            $orderInfo = [
                'yw_uid' => $uid,
                'yw_fund_id' => $fi->fi_globalid,
                'created_at' => $updated_at,
                'updated_at' => $updated_at,
            ];

            foreach ($xtab as $k => $v) {
                if (isset($profits[$v]) && !is_null($profits[$v])) {
                    $orderInfo[$k] = $profits[$v];
                }
            }

            $inserted[] = $orderInfo;
        }

        if (!empty($inserted)) {
            $deleted = YingmiFundProfit::where('yw_uid', $uid)
                ->where('yw_fund_id', $fi->fi_globalid)
                ->delete();

            $result = YingmiFundProfit::insert($inserted);
        }

        return $result;
    }

    public function updatePoOrderDatabaseForTs($uid, $order, $row = false)
    {
        $poId = $order['poCode'];
        $tradeType = $order['orderCode'];

        $account = YingmiAccount::where('ya_uid', $uid)->first();
        $info = YingmiPortfolioInfo::find($poId);
        if ($info) {
            $adjustId = $info->yp_adjustment_id;
        } else {
            $adjustId = 0;
        }

        $orderInfo = [
            'yp_uid' => $uid,
            'yp_portfolio_id' => $poId,
            'yp_trade_type' => $tradeType,
            'yp_account_id' => $account->ya_account_id,
            //'yp_adjustment_id' => $adjustId,
        ];

        $xtab = [
            'yp_txn_id' => 'brokerOrderNo',
            'yp_trade_status' => 'confirmStatus',
            'yp_placed_amount' => 'tradeAmount',
            'yp_placed_percentage' => 'redeemRatio',
            'yp_acked_date' => 'orderConfirmDate',
            'yp_pay_status' => 'payStatus',
            'yp_yingmi_order_id' => 'orderId',
            'yp_invest_plan_id' => 'investPlanId',
            'yp_trade_date' => 'orderTradeDate',
        ];

        $contents = [
            'yp_acked_amount' => 'successAmount',
            'yp_acked_fee' => 'fee',
        ];

        foreach ($xtab as $k => $v) {
            if (isset($order[$v]) && !is_null($order[$v])) {
                $orderInfo[$k] = $order[$v];
            }
        }

        foreach ($order['compositionOrders'] as $composite) {
            foreach ($contents as $k => $v) {
                if (isset($composite[$v]) && !is_null($composite[$v])) {
                    if (isset($orderInfo[$k])) {
                        $orderInfo[$k] += round($composite[$v], 2);
                    } else {
                        $orderInfo[$k] = round($composite[$v], 2);
                    }
                }
            }
        }

        if (isset($order['orderCreatedOn']) && $order['orderCreatedOn'] !== null) {
            $carbonPlaced = Carbon::parse($order['orderCreatedOn']);
            $orderInfo['yp_placed_date'] = $carbonPlaced->toDateString();
            $orderInfo['yp_placed_time'] = $carbonPlaced->toTimeString();
        }

        // if (isset($order['paymentMethodId']) && isset($order['walletId'])) {
        //     $orderInfo['yp_pay_method'] = $order['paymentMethodId'];
        // } elseif (isset($order['paymentMethodId'])) {
        //     $orderInfo['yp_pay_type'] = 1;
        //     $orderInfo['yp_pay_method'] = $order['paymentMethodId'];
        // } else {
        //     if (isset($order['walletId'])) {
        //         $orderInfo['yp_pay_type'] = 2;
        //         $orderInfo['yp_pay_method'] = $order['walletId'];
        //     }
        // }

        $r = $this->dbUpdatePoRowTs($orderInfo, $row);

        //
        // 组合各个成分的订单
        //
        $compositeOrderInfo = $this->compositeOrderInfoTs($uid, $order, $r);
        $rs = [];
        foreach ($compositeOrderInfo as $composite) {
            $tmpRow = $this->dbUpdateRowTs($composite, false);

            if ($tmpRow) {
                $rs[] = $tmpRow;
            }
        }

        DB::transaction(function () use ($r, $rs) {
            if ($r) {
                $r->save();
            }

            foreach ($rs as $r) {
                $r->save();
            }
        });

        return true;
    }


}
