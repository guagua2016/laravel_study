<?php

namespace App\Libraries\User;

use App\BnFundValue;
use App\MfFundTradeStatus;
use App\MfPortfolioTradeStatus;

use function App\Libraries\trade_date;

/**
 *
 * 千人千面用户的持仓信息
 *
 */

class UserMofangTradeInfo implements UserTradeInfo
{
    protected $uid;

    public function __construct($uid)
    {
        $this->uid = $uid;
    }

    public function userTradeFlows()
    {
        $orders = MfFundTradeStatus::where('mf_uid', $this->uid)
                ->whereIn('mf_trade_type', ['W04', 'W05', '024', '022'])
                ->whereIn('mf_trade_status', [0, 1, 2, 3, 4])
                ->whereNotNull('mf_yingmi_order_id')
                ->get();

        $orders = $orders->groupBy(function ($item, $key) {
            return $item->mf_fund_id . '-' . $item->mf_trade_date . '-' . $item->mf_trade_type;
        });

        $datas = [];
        foreach ($orders as $order) {

            $first = $order->first();

            $txnId = $first->mf_yingmi_order_id;

            if (!$txnId) {
                dd($order);
            }

            $fundId = $first->mf_fund_id;
            if ($first->mf_trade_date == '0000-00-00') {
                $tradeDate = trade_date($first->mf_placed_date);
            } else {
                $tradeDate = $first->mf_trade_date;
            }

            if ($first->mf_trade_nav > 0) {
                $nav = $first->mf_trade_nav;
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
                'ts_uid' => $first->mf_uid,
                'ts_portfolio_id' => 0,
                'ts_fund_id' => $first->mf_fund_id,
                'ts_type' => $first->mf_mofang_trade_type,
                'ts_placed_date' => $first->mf_placed_date,
                'ts_placed_time' => $first->mf_placed_time,
                'ts_placed_amount' => 0,
                'ts_placed_share' => 0,
                'ts_fee' => 0,
                'ts_nav' => $nav,
                'ts_trade_date' => $tradeDate,
                'ts_acked_date' => $first->mf_acked_date,
                'ts_acked_share' => 0,
                'ts_acked_amount' => 0,
            ];

            foreach ($order as $r) {
                $data['ts_placed_amount'] += $r->mf_placed_amount;
                $data['ts_placed_share']  += $r->mf_placed_share;
                $data['ts_acked_amount']  += $r->mf_acked_amount;
                $data['ts_acked_share']   += $r->mf_acked_share;
                $data['ts_fee']           += $r->mf_acked_fee;
            }

            $datas[] = $data;
        }

        return $datas;
    }

    //public function userTradeFlows()
    //{
    //    $pOrders = MfPortfolioTradeStatus::with('subOrders')
    //             ->where('mp_uid', $this->uid)
    //             ->normal()
    //             ->orderBy('mp_placed_date', 'ASC')
    //             ->orderBy('mp_placed_time', 'ASC')
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

    //        foreach ($pOrder->subOrders as $subOrder) {
    //            $fundId = $subOrder->mf_fund_id;

    //            if ($subOrder->mf_trade_date == '0000-00-00') {
    //                $tradeDate = trade_date($subOrder->mf_placed_date);
    //            } else {
    //                $tradeDate = $subOrder->mf_trade_date;
    //            }

    //            if ($subOrder->mf_trade_nav > 0) {
    //                $nav = $subOrder->mf_trade_nav;
    //            } else {
    //                $value = BnFundValue::where('fv_fund_id', $pOrder->mf_fund_id)
    //                       ->where('fv_date', $tradeDate)
    //                       ->first();

    //                if ($value) {
    //                    $nav = $value->fv_nav;
    //                } else {
    //                    $nav = 0;
    //                }
    //            }

    //            //$datas[] = [
    //            //    'mu_uid' => $subOrder->mf_uid,
    //            //    'mu_portfolio_id' => $subOrder->mf_portfolio_id,
    //            //    'mu_product_id' => $subOrder->mf_fund_id,
    //            //    'mu_type' => $subOrder->mf_mofang_trade_type,
    //            //    'mu_placed_date' => $subOrder->mf_placed_date,
    //            //    'mu_placed_time' => $subOrder->mf_placed_time,
    //            //    'mu_amount' => $subOrder->mf_placed_amount,
    //            //    'mu_share' => $subOrder->mf_placed_share,
    //            //    'mu_fee' => $subOrder->mf_acked_fee,
    //            //    'mu_nav' => $nav,
    //            //    'mu_nav_date' => $tradeDate,
    //            //    'mu_acked_date' => $subOrder->mf_acked_date,
    //            //    'mu_acked_share' => $subOrder->mf_acked_share,
    //            //    'mu_acked_amount' => $subOrder->mf_acked_amount,
    //            //    'mu_origin_id' => 2,
    //            //];

    //            if (isset($matrixs[$fundId][$tradeDate])) {
    //                $matrixs[$fundId][$tradeDate]['mu_placed_amount'] += $subOrder->mf_placed_amount;
    //                $matrixs[$fundId][$tradeDate]['mu_placed_share'] += $subOrder->mf_placed_share;
    //                $matrixs[$fundId][$tradeDate]['mu_fee'] += $subOrder->mf_acked_fee;
    //                $matrixs[$fundId][$tradeDate]['mu_acked_amount'] += $subOrder->mf_acked_amount;
    //                $matrixs[$fundId][$tradeDate]['mu_acked_share'] += $subOrder->mf_acked_share;
    //            } else {
    //                $matrixs[$fundId][$tradeDate] = [
    //                    'mu_txn_id' => $subOrder->mf_txn_id,
    //                    'mu_uid' => $subOrder->mf_uid,
    //                    'mu_portfolio_id' => $subOrder->mf_portfolio_id,
    //                    'mu_fund_id' => $subOrder->mf_fund_id,
    //                    'mu_type' => $subOrder->mf_mofang_trade_type,
    //                    'mu_placed_date' => $subOrder->mf_placed_date,
    //                    'mu_placed_time' => $subOrder->mf_placed_time,
    //                    'mu_placed_amount' => $subOrder->mf_placed_amount,
    //                    'mu_placed_share' => $subOrder->mf_placed_share,
    //                    'mu_fee' => $subOrder->mf_acked_fee,
    //                    'mu_nav' => $nav,
    //                    'mu_trade_date' => $tradeDate,
    //                    'mu_acked_date' => $subOrder->mf_acked_date,
    //                    'mu_acked_share' => $subOrder->mf_acked_share,
    //                    'mu_acked_amount' => $subOrder->mf_acked_amount,
    //                    //'mu_origin_id' => 2,
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
