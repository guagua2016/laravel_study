<?php namespace App\Libraries\YmSdk;

use App\Http\Controllers\YingmiPortfolioController;
use App\Libraries\SmsService;
use App\YingmiWalletShareDetail;
use Carbon\Carbon;

use DB;
use Log;
use App\BaseRaFund;
use App\BaseRaFundNav;
use App\MfPortfolioTradeStatus;
use App\MfFundTradeStatus;
use App\TsTxnId;
use App\YingmiTradeStatus;

use App\YingmiAccount;
use App\YingmiPortfolioComposition;
use App\YingmiPortfolioInfo;
use App\YingmiPortfolioTradeStatus;
use App\YingmiFundProfit;

use App\Libraries\DirtyDumper;

use function App\Libraries\array_dict;

trait YingmiOrderUpdater
{
    public function updateOrderDatabase($uid, $order, $row = false, $map = null)
    {
        $fundCode = $order['fundCode'];
        $tradeType = $order['fundOrderCode'];

        if ($row) {
            $fi = (object)[
                'globalid' => $row->yt_fund_id,
                'ra_name' => $row->yt_fund_name,
            ];
        } else {
            $fi = BaseRaFund::findByCode($fundCode, 'dummy'); // 根据基金code加载基金ID和名称
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
            'yt_error_code' => 'errorCode',
            'yt_error_msg' => 'errorMessage',
            'yt_trade_nav' => 'tradeNav',
        ];

        $orderInfo = [
            'yt_uid' => $uid,
            'yt_fund_id' => $fi->globalid,
            'yt_fund_code' => $fundCode,
            'yt_fund_name' => $fi->ra_name,
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

            if (!isset($orderInfo['yt_trade_nav'])
                || $orderInfo['yt_trade_nav'] == 0
            ) {
                $nav = '0.0000';
                if (isset($order['nav']) && $order['nav'] != 0) {
                    $nav = $order['nav'];
                } else {
                    $val = BaseRaFundNav::where('ra_fund_id', $orderInfo['yt_fund_id'])
                         ->where('ra_date', $orderInfo['yt_trade_date'])
                         ->first(['ra_nav']);
                    if ($val) {
                        $nav = $val->ra_nav;
                    }
                }

                $orderInfo['yt_trade_nav'] = $nav;
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

        // 更新W10d的originId
        if ($tradeType == 'W10') {

            $originInfo = $this->getOriginInfoW10($uid, $order);

            if (!empty($originInfo)) {
                $orderInfo['yt_origin_order_id'] = $originInfo['yt_origin_order_id'];
                $orderInfo['yt_portfolio_txn_id'] = $originInfo['yt_portfolio_txn_id'];
                $orderInfo['yt_portfolio_id'] = $originInfo['yt_portfolio_id'];
                $orderInfo['yt_ts_txn_id'] = $originInfo['yt_ts_txn_id'];
            }
        }

        // 又origin订单id找到 对应组合订单号
        // 为了不改变订单组合订单，如果订单有组合订单直接返回，
        // 如果订单组合id，与origin订单组合id不一致，人工审核处理
        if (array_key_exists('originFundOrderNo', $order)) {

            // 因为盈米上线originFundOrderNo字段前的订单， 本应该有originFundOrderNo的全为null
            if ($order['originFundOrderNo'] ||  $order['orderTradeDate'] < '2017-10-31') {

                $originInfo = $this->getOriginTxnId($uid, $order);

                if (!empty($originInfo)) {
                    $orderInfo['yt_origin_order_id'] = $originInfo['yt_origin_order_id'];
                    $orderInfo['yt_portfolio_txn_id'] = $originInfo['yt_portfolio_txn_id'];
                    $orderInfo['yt_portfolio_id'] = $originInfo['yt_portfolio_id'];
                    $orderInfo['yt_ts_txn_id'] = $originInfo['yt_ts_txn_id'];
                }
            }
        }

        if (isset($orderInfo['yt_txn_id']) || isset($orderInfo['yt_yingmi_order_id'])) {
            if (!isset($orderInfo['yt_portfolio_txn_id']) || !$orderInfo['yt_portfolio_txn_id']) {

                $mfOrder = null;
                if (isset($orderInfo['yt_txn_id'])) {
                    $mfOrder = MfFundTradeStatus::where('mf_txn_id', $orderInfo['yt_txn_id'])->where('mf_portfolio_txn_id', '!=', '')->first();
                } else if (isset($orderInfo['yt_yingmi_order_id'])) {
                    $mfOrder = MfFundTradeStatus::where('mf_yingmi_order_id', $orderInfo['yt_yingmi_order_id'])->where('mf_portfolio_txn_id', '!=', '')->first();
                }

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
            $fi2 = BaseRaFund::findByCode($convertFundCode, 'dummy');

            $xtab2 = [
                'yt_share_type' => 'destShareType',
                'yt_acked_amount' => 'convertSuccessAmount',
                'yt_acked_share' => 'convertSuccessShare',
            ];

            $info2 = [
                'yt_fund_id' => $fi2->globalid,
                'yt_fund_code' => $convertFundCode,
                'yt_fund_name' => $fi2->ra_name,
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

        if ($r1 && !$r1->yt_pay_method) {
            $walletId = '';
            if ($r1->yt_wallet_id) {
                $walletId = $r1->yt_wallet_id;
            } elseif (isset($order['walletId'])) {
                $walletId = $order['walletId'];
            }

            if ($walletId) {
                $paymethodId = YingmiWalletShareDetail::getPayMethodByWalletId($uid, $walletId);
                $r1->yt_pay_method = $paymethodId;
            }
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

    protected function dbUpdateRow($orderInfo, $row, $shouldTradeTypeMatch = false)
    {
        $checkCols = [
            'yt_uid',
        ];

        $txn = false;
        $yingmiId = false;
        if (!$row) {
            if (isset($orderInfo['yt_txn_id']) && isset($orderInfo['yt_yingmi_order_id'])) {
                $model = YingmiTradeStatus::where('yt_yingmi_order_id', $orderInfo['yt_yingmi_order_id'])
                    ->orWhere('yt_txn_id', $orderInfo['yt_txn_id'])
                    ->orderBy('yt_txn_id', 'DESC');
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
                // 2017-10-18 重复下修改分红方式的订单，可以直接过滤
                $filters = [
                    2017101300478736,
                    2017101300478737,
                    2017101300478738,
                    2017101600428769,
                    2017101600428775,
                    2017101600428776,
                    2017101600428777,
                    2017101600428778,
                    2017101600428780,
                    2017101600428783,
                    2017101600428784,
                    2017101600428785,
                    2017101600428786,
                    2017102700402514,
                    2017102700402523,
                    2017102700402529,
                    2017102700402532,
                    2017102700402534,
                    2017102700402535,
                    2017102700402537,
                    2017102700402539,
                    2017102700402552,
                    2017102700402555,
                    // 029 duplicated start
                    2017101300478736,
                    2017101300478737,
                    2017101300478738,
                    2017101600428769,
                    2017101600428775,
                    2017101600428776,
                    2017101600428777,
                    2017101600428778,
                    2017101600428780,
                    2017101600428783,
                    2017101600428784,
                    2017101600428785,
                    2017101600428786,
                    2017102700389140,
                    2017102700389153,
                    2017102700389155,
                    2017102700389156,
                    2017102700390327,
                    2017102700390330,
                    2017102700392384,
                    2017102700392386,
                    2017102700392387,
                    2017102700392388,
                    2017102700392393,
                    2017102700392397,
                    2017102700392400,
                    2017102700392407,
                    2017102700392411,
                    2017102700392413,
                    2017102700392419,
                    2017102700392420,
                    2017102700392421,
                    2017102700392929,
                    2017102700392932,
                    2017102700392935,
                    2017102700392938,
                    2017102700392940,
                    2017102700392943,
                    2017102700392953,
                    2017102700392955,
                    2017102700392958,
                    2017102700392960,
                    2017102700392975,
                    2017102700401773,
                    2017102700401775,
                    2017102700401782,
                    2017102700401784,
                    2017102700401785,
                    2017102700401788,
                    2017102700401789,
                    2017102700401792,
                    2017102700401824,
                    2017102700401828,
                    2017102700401854,
                    2017102700402000,
                    2017102700402408,
                    2017102700402514,
                    2017102700402523,
                    2017102700402529,
                    2017102700402532,
                    2017102700402534,
                    2017102700402535,
                    2017102700402537,
                    2017102700402539,
                    2017102700402552,
                    2017102700402555,
                    // 029 duplicated end
                ];

                if (!in_array($orderInfo['yt_yingmi_order_id'], $filters) &&
                    isset($orderInfo['yt_trade_type']) &&
                    $orderInfo['yt_trade_type'] !== '029'
                ) {
                    Log::error('duplicated yingmi_trade_status dectected', [
                        'yt_txn_id'=> isset($orderInfo['yt_txn_id']) ? $orderInfo['yt_txn_id'] : '',
                            'yt_yingmi_order_id' => $orderInfo['yt_yingmi_order_id'],
                        ]);
                    $alert = sprintf("检测到重复订单:[%s,%d]",
                        isset($orderInfo['yt_txn_id']) ? $orderInfo['yt_txn_id'] : '',
                        isset($orderInfo['yt_yingmi_order_id']) ? $orderInfo['yt_yingmi_order_id'] : '');
                    SmsService::smsAlert($alert, 'kun,zhangzhe,pp');
                }
            }
            $row = $rows->first();
        }

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

    public function updatePoOrderDatabase($uid, $order, $row = false)
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
            'yp_pay_method' => 'paymentMethodId',
            'yp_pay_wallet' => 'walletId',
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

         //if (isset($order['paymentMethodId']) && isset($order['walletId'])) {
         //    $orderInfo['yp_pay_method'] = $order['paymentMethodId'];
         //} elseif (isset($order['paymentMethodId'])) {
         //    //$orderInfo['yp_pay_type'] = 1;
         //    $orderInfo['yp_pay_method'] = $order['paymentMethodId'];
         //} else {
         //    if (isset($order['walletId'])) {
         //        $orderInfo['yp_pay_type'] = 2;
         //        $orderInfo['yp_pay_method'] = $order['walletId'];
         //    }
         //}

        $r = $this->dbUpdatePoRow($orderInfo, $row);

        //
        // 组合各个成分的订单
        //
        $compositeOrderInfo = $this->compositeOrderInfo($uid, $order, $r);
        $rs = [];
        foreach ($compositeOrderInfo as $composite) {
            $tmpRow = $this->dbUpdateRow($composite, false);

            if ($tmpRow) {
                $rs[] = $tmpRow;
            }
        }
        //dd($r, $rs);;
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

    protected function compositeOrderInfo($uid, $order, $row = false)
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
        $funds = BaseRaFund::whereIn('ra_code', $codes)
            ->get();
        $funds = array_dict($funds, 'ra_code');

        $compositeOrderInfo = [];
        foreach ($order['compositionOrders'] as $composite) {
            $orderInfo = [
                'yt_uid' => $uid,
                'yt_account_id' => $account->ya_account_id,
                'yt_portfolio_id' => $order['poCode'],
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
                $orderInfo['yt_fund_name'] = $funds[$composite['prodCode']]->ra_name;
                $orderInfo['yt_fund_id'] = $funds[$composite['prodCode']]->globalid;
            }

            $compositeOrderInfo[] = $orderInfo;
        }

        return $compositeOrderInfo;
    }

    protected function dbUpdatePoRow($orderInfo, $row, $shouldTradeTypeMatch = false)
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

    public function getOriginTxnId($uid, $orderData)
    {
        $orderId = $orderData['orderId'];
        $originOrderId = $orderData['originFundOrderNo'];
        $txnId = $orderData['brokerOrderNo'];

        $columns = [
            'yt_portfolio_txn_id',
            'yt_yingmi_order_id',
            'yt_portfolio_id',
            'yt_ts_txn_id',
            'yt_origin_order_id',
        ];
        $orders = YingmiTradeStatus::whereIn('yt_yingmi_order_id', [$orderId, $originOrderId])
            ->get($columns);

        $orders = $orders->keyBy('yt_yingmi_order_id');

        // 1. 订单已经更新了对应字段，无须从originFund中更新
        if (isset($orders[$orderId]) && $orders[$orderId]->yt_portfolio_txn_id) {
            return  $orders[$orderId]->toArray();
        }

        $originOrder = null;

        // 2. 从originFund中更新
        if (isset($orders[$originOrderId]) && $orders[$originOrderId]->yt_portfolio_txn_id) {
            $originOrder = $orders[$originOrderId];
        }

        // 3. 对于originFund为null的情况，理论上只会出现在17-10-31 之前的订单
        // 用下单日期，与下单金额去反查W05订单
        if (isset($orderData['orderCreatedOn']) && isset($orderData['tradeAmount'])) {
            $placedDate = Carbon::parse($orderData['orderCreatedOn'])->toDateString();
            $placedAmount = $orderData['tradeAmount'];

            $originOrder = YingmiTradeStatus::where('yt_uid', $uid)
                ->where('yt_trade_type', 'W05')
                ->where('yt_trade_status', 2)
                ->where('yt_acked_date', $placedDate)
                ->where('yt_acked_amount', $placedAmount)
                ->first($columns);
        }

        if ($originOrder) {
            $poId = $originOrder->yt_portfolio_txn_id;
            $MfPoOrder = MfPortfolioTradeStatus::where('mp_txn_id', $poId)->first();
            $YingmiPoOrder = YingmiPortfolioTradeStatus::where('yp_txn_id', $poId)->first();

            if ($MfPoOrder) {
                $poId = $MfPoOrder->mp_ts_txn_id;
            } elseif ($YingmiPoOrder) {
                $poId = $YingmiPoOrder->yp_ts_txn_id;
            }

            $newTxnId = TsTxnId::getOrMakeFundTxnId(
                $poId,
                $txnId,
                $orderId
            );

            return [
                'yt_origin_order_id' => $originOrder->yt_yingmi_order_id,
                'yt_portfolio_txn_id' => $originOrder->yt_portfolio_txn_id,
                'yt_portfolio_id' => $originOrder->yt_portfolio_id,
                'yt_ts_txn_id' => $newTxnId,
            ];
        }

        return [];
    }

    public function getOriginInfoW10($uid, $orderData)
    {
        // 如果存在originFundOrderNo， 则使用getOriginTxnId处理
        if (isset($orderData['originFundOrderNo']) && $orderData['originFundOrderNo']) {
            return;
        }

        // file_api 传来的order 没有brokerOrderNo 字段
        if (!isset($orderData['brokerOrderNo'])) {
            return;
        }

        $orderId = $orderData['orderId'];
        $txnId = $orderData['brokerOrderNo'];

        $columns = [
            'yt_portfolio_txn_id',
            'yt_yingmi_order_id',
            'yt_portfolio_id',
            'yt_ts_txn_id',
            'yt_origin_order_id',
        ];
        $order = YingmiTradeStatus::where('yt_yingmi_order_id', $orderId)
            ->first($columns);

        // 1. 订单已经更新了对应字段 不会再自动更新，
        if ($order && $order->yt_origin_order_id) {
            return  $order->toArray();
        }

        if (isset($orderData['orderCreatedOn']) && isset($orderData['tradeAmount'])) {
            $placedDate = Carbon::parse($orderData['orderCreatedOn'])->toDateString();
            $placedAmount = $orderData['tradeAmount'];
        } else {
            return;
        }

        // 已经作为origin的w01订单，不在作为origin订单
        $filters = YingmiTradeStatus::where('yt_uid', $uid)
            ->where('yt_trade_type', 'W10')
            ->where('yt_origin_order_id', '!=', '')
            ->lists('yt_origin_order_id');

        $originOrders = YingmiTradeStatus::where('yt_uid', $uid)
            ->where('yt_trade_type', 'W04')
            ->where('yt_trade_status', 1)
            ->where('yt_acked_date', $placedDate)
            ->where('yt_placed_amount', $placedAmount)
            ->whereNotIn('yt_yingmi_order_id', $filters->all())
            ->get();

        if ($originOrders->isEmpty()) {
            return;
        }

        $originOrder = $originOrders->first(function ($key, $val) {
            return $val->yt_error_code == '0316';
        });

        if (!$originOrder) {
            $originOrder = $originOrders->first();
        }

        if ($order->yt_ts_txn_id) {
            $newTxnId = $order->yt_ts_txn_id;
        } else {
            $newTxnId = TsTxnId::getOrMakeFundTxnId(
                $originOrder->yt_portfolio_txn_id,
                $txnId,
                $orderId
            );
        }

        return [
            'yt_origin_order_id' => $originOrder->yt_yingmi_order_id,
            'yt_portfolio_txn_id' => $originOrder->yt_portfolio_txn_id,
            'yt_portfolio_id' => $originOrder->yt_portfolio_id,
            'yt_ts_txn_id' => $newTxnId,
        ];
    }

    public function getUserWalletIdPaymentMethodMap($uid)
    {
        $result = YingmiWalletShareDetail::where('yw_uid', $uid)
            ->select('yw_wallet_id', 'yw_pay_method')
            ->lists('yw_pay_method', 'yw_wallet_id');

        if ($result) {
            $result = $result->toArray();
        }

        return $result;
    }

}
