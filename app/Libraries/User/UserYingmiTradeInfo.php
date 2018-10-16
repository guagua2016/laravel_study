<?php

namespace App\Libraries\User;

use App\BnFundValue;
use App\YingmiTradeStatus;
use App\YingmiPortfolioTradeStatus;

use function App\Libraries\trade_date;

/**
 *
 * 用户在盈米组合的信息
 *
 */

class UserYingmiTradeInfo implements UserTradeInfo
{
    protected $uid;

    public function __construct($uid)
    {
        $this->uid = $uid;
    }

    public function userTradeFlows()
    {
        $orders = YingmiTradeStatus::where('yt_uid', $this->uid)
                ->where('yt_portfolio_txn_id', '!=', '')
                ->whereIn('yt_trade_type', ['W04', 'W05', '024', '022'])
                ->whereIn('yt_trade_status', [0, 2, 3, 4])
                ->get();

        // 合并同一个交易日同一类型的订单
        // 一个支付方式的进行合并, 如果订单表中需要记录可直接使用
        $orders = $orders->groupBy(function ($item, $key) {
            return $item->yt_fund_id . '-' . $item->yt_trade_date . '-' . $item->yt_trade_type . '-' . $item->yt_pay_method;
        });

        $datas = [];
        foreach ($orders as $order) {

            // @todo 处理订单关系
            $first = $order->first();
            $txnId = $first->yt_yingmi_order_id;

            if (!$txnId) {
                dd($order);
            }

            $fundId = $first->yt_fund_id;
            if ($first->yt_trade_date == '0000-00-00') {
                $tradeDate = trade_date($first->yt_placed_date);
            } else {
                $tradeDate = $first->yt_trade_date;
            }

            if ($first->yt_trade_nav > 0) {
                $nav = $first->yt_trade_nav;
            } else {
                $value = BnFundValue::where('fv_fund_id', $fundId)
                    ->where('fv_date', $tradeDate)
                    ->first();

                if ($value) {
                    $nav = $value->fv_nav;
                } else {
                    $nav = 0;
                }
            }

            $data = [
                'ts_txn_id' => $txnId,
                'ts_uid' => $first->yt_uid,
                'ts_portfolio_id' => 0,
                'ts_fund_id' => $first->yt_fund_id,
                'ts_type' => $first->yt_mofang_trade_type,
                'ts_placed_date' => $first->yt_placed_date,
                'ts_placed_time' => $first->yt_placed_time,
                'ts_placed_amount' => 0,
                'ts_placed_share' => 0,
                'ts_fee' => 0,
                'ts_nav' => $nav,
                'ts_trade_date' => $tradeDate,
                'ts_acked_date' => $first->yt_acked_date,
                'ts_acked_share' => 0,
                'ts_acked_amount' => 0,
            ];

            foreach ($order as $r) {
                $data['ts_placed_amount'] += $r->yt_placed_amount;
                $data['ts_placed_share']  += $r->yt_placed_share;
                $data['ts_acked_amount']  += $r->yt_acked_amount;
                $data['ts_acked_share']   += $r->yt_acked_share;
                $data['ts_fee']           += $r->yt_acked_fee;
            }

            $datas[] = $data;
        }

        return $datas;
    }

    //public function userTradeFlows()
    //{
    //    $pOrders = YingmiPortfolioTradeStatus::with('subOrders')
    //             ->where('yp_uid', $this->uid)
    //             ->normal()
    //             ->orderBy('yp_placed_date', 'ASC')
    //             ->orderBy('yp_placed_time', 'ASC')
    //             ->get();

    //    $datas = [];
    //    $matrixs = [];
    //    foreach ($pOrders as $pOrder) {
    //        if (!isset($pOrder->subOrders)) {
    //            continue;
    //        }

    //        if ($pOrder->subOrders->isEmpty()) {
    //            continue;
    //        }

    //        $poId = $pOrder->yp_portfolio_id;
    //        foreach ($pOrder->subOrders as $subOrder) {
    //            $fundId = $subOrder->yt_fund_id;
    //            if ($subOrder->yt_trade_date == '0000-00-00') {
    //                $tradeDate = trade_date($subOrder->yt_placed_date);
    //            } else {
    //                $tradeDate = $subOrder->yt_trade_date;
    //            }

    //            if ($subOrder->yt_trade_nav > 0) {
    //                $nav = $subOrder->yt_trade_nav;
    //            } else {
    //                $value = BnFundValue::where('fv_fund_id', $pOrder->yt_fund_id)
    //                       ->where('fv_date', $tradeDate)
    //                       ->first();

    //                if ($value) {
    //                    $nav = $value->fv_nav;
    //                } else {
    //                    $nav = 0;
    //                }
    //            }

    //            //$datas[] = [
    //            //    'mu_uid' => $subOrder->yt_uid,
    //            //    'mu_portfolio_id' => $poId,
    //            //    'mu_product_id' => $subOrder->yt_fund_id,
    //            //    'mu_type' => $subOrder->yt_mofang_trade_type,
    //            //    'mu_placed_date' => $subOrder->yt_placed_date,
    //            //    'mu_placed_time' => $subOrder->yt_placed_time,
    //            //    'mu_amount' => $subOrder->yt_placed_amount,
    //            //    'mu_share' => $subOrder->yt_placed_share,
    //            //    'mu_fee' => $subOrder->yt_acked_fee,
    //            //    'mu_nav' => $nav,
    //            //    'mu_nav_date' => $tradeDate,
    //            //    'mu_acked_date' => $subOrder->yt_acked_date,
    //            //    'mu_acked_share' => $subOrder->yt_acked_share,
    //            //    'mu_acked_amount' => $subOrder->yt_acked_amount,
    //            //    'mu_origin_id' => 1,
    //            //];

    //            if (isset($matrixs[$fundId][$tradeDate])) {
    //                $matrixs[$fundId][$tradeDate]['mu_placed_amount'] += $subOrder->yt_placed_amount;
    //                $matrixs[$fundId][$tradeDate]['mu_placed_share'] += $subOrder->yt_placed_share;
    //                $matrixs[$fundId][$tradeDate]['mu_fee'] += $subOrder->yt_acked_fee;
    //                $matrixs[$fundId][$tradeDate]['mu_acked_amount'] += $subOrder->yt_acked_amount;
    //                $matrixs[$fundId][$tradeDate]['mu_acked_share'] += $subOrder->yt_acked_share;
    //            } else {
    //                $matrixs[$fundId][$tradeDate] = [
    //                    'mu_txn_id' => $subOrder->yt_txn_id,
    //                    'mu_uid' => $subOrder->yt_uid,
    //                    'mu_portfolio_id' => $poId,
    //                    'mu_fund_id' => $subOrder->yt_fund_id,
    //                    'mu_type' => $subOrder->yt_mofang_trade_type,
    //                    'mu_placed_date' => $subOrder->yt_placed_date,
    //                    'mu_placed_time' => $subOrder->yt_placed_time,
    //                    'mu_placed_amount' => $subOrder->yt_placed_amount,
    //                    'mu_placed_share' => $subOrder->yt_placed_share,
    //                    'mu_fee' => $subOrder->yt_acked_fee,
    //                    'mu_nav' => $nav,
    //                    'mu_trade_date' => $tradeDate,
    //                    'mu_acked_date' => $subOrder->yt_acked_date,
    //                    'mu_acked_share' => $subOrder->yt_acked_share,
    //                    'mu_acked_amount' => $subOrder->yt_acked_amount,
    //                    //'mu_origin_id' => 1,
    //                ];
    //            }
    //        }
    //    }

    //    foreach ($matrixs as $matrix) {
    //        foreach ($matrix as $m) {
    //            $datas[] = $m;
    //        }
    //    }

    //    return $datas;
    //}
}
