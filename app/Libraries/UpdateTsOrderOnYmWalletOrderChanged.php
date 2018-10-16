<?php

namespace App\Libraries;

use Log;

use App\MfPortfolioTradeStatus;
use App\TsOrder;
use App\TsTxnId;
use App\TsTxnTls;
use App\TsOrderFund;
use App\YingmiPortfolioTradeStatus;
use App\YingmiTradeStatus;

use App\Libraries\DirtyDumper;

use function App\Libraries\basename_class;

trait UpdateTsOrderOnYmWalletOrderChanged
{
    public function updateTsOrderFund($uid, $txnId)
    {
        $base = basename_class(__CLASS__) . '@' . __FUNCTION__ . ' ';

        // txnId 可能为MfPortfolioTradeStatus 或 YingmiPortfolioTradeStatus 中txn id
        // 需要转换为TsPoId
        $poId = $txnId;
        $origin = 8;
        $MfPoOrder = MfPortfolioTradeStatus::where('mp_txn_id', $poId)->first();
        $YingmiPoOrder = YingmiPortfolioTradeStatus::where('yp_txn_id', $poId)->first();

        if ($MfPoOrder) {
            $poId = $MfPoOrder->mp_ts_txn_id;

            $origin = 5;
        } elseif ($YingmiPoOrder) {
            $poId = $YingmiPoOrder->yp_ts_txn_id;

            // yp_txn_id 如果是 20170809B000006A:1:71n2qp1s6el0:ZH005579 类型的
            // 则是trade system 下的订单，所以origin是8
            // 如果yp_txn_id 不是上面类型的，而yp_mf_txn_id有值，
            // 则此订单是mf_portfolio_trade 中的 origin为5
            // 否则origin为3
            if (strpos($txnId, ':') === false){
                $origin = $YingmiPoOrder->yp_mf_txn_id ? 5 : 3;
            }
        }

        $orders = TsOrder::where('ts_txn_id', $poId)
            ->get(['ts_txn_id', 'ts_portfolio_id', 'ts_trade_type', 'ts_trade_status']);
        $order = $orders->first(function ($key, $val) {
            return is_numeric($val->ts_portfolio_id);
        });
        if (!$order) {
            $order = $order->first();
        }

        // 调仓赎回到组合产生的充值单->14
        // 赎回赎回到组合产生的充值单->13
        $tradeType = 14;
        if ($order) {
            if (in_array($order->ts_trade_type, [4])) {
                $tradeType = 13;
            }
        }

        $subOrders = TsOrderFund::where('ts_uid', $uid)
            ->where('ts_portfolio_txn_id', $poId)
            ->get();
        $subOrders = $subOrders->keyBy('ts_txn_id');

        $ymSubOrders = YingmiTradeStatus::where('yt_uid', $uid)
            ->where('yt_portfolio_txn_id', $txnId)
            ->where('yt_origin_order_id', '!=', '')
            ->get();

        $toUpdates = [];

        foreach ($ymSubOrders as $ymSubOrder) {
            $subTxnId = $ymSubOrder->yt_ts_txn_id;
            $originId = $ymSubOrder->yt_origin_order_id;

            // 此订单没有txnid 不做更新
            if (!$subTxnId) {
                continue;
            }

            // 此订单没有原始赎回对应订单，
            // 则此订单应该是我们主动下的订单，不通过此函数更新
            if (!$originId) {
                continue;
            }

            //
            // 如果TsOrderFund已有此订单信息，且此订单在TsOrderFund类型
            // 非因赎回而生成充值的订单(14)， 不更新此订单
            //
            if ($subOrders->has($subTxnId)
                && !in_array($subOrders->get($subTxnId)->ts_trade_type, [13, 14]))
            {
                continue;
            }

            if ($subOrders->has($subTxnId)) {
                $toUpdate = $subOrders->get($subTxnId);
            } else {
                $toUpdate = new TsOrderFund;
            }

            $datas = array_replace($ymSubOrder->getTsAllArray($tradeType), [
                'ts_portfolio_txn_id' => $poId,
                'ts_origin' => $origin,
            ]);
            $toUpdate->fill($datas);

            $toUpdates[] = $toUpdate;
        }

        try {
            foreach ($toUpdates as $toUpdate) {
                if ($toUpdate->isDirty()) {
                    DirtyDumper::xlogDirty($toUpdate, 'ts_order_fund update', $toUpdate->logkeys());
                    $toUpdate->save();
                }
            }
        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
        }
    }

    public function updateTsOrderFundW09($uid, $order)
    {
        if ($order->yt_trade_type != 'W09') {
            return;
        }

        if ($order->yt_ts_txn_id) {
            $subTxnId = $order->yt_ts_txn_id;
        } else {
            $subTxnId = TsTxnTls::getTxnId($order->yt_txn_id, $order->yt_yingmi_order_id);

            if (!$subTxnId) {
                $dt = sprintf("%s %s", $order->yt_placed_date, $order->yt_placed_time);
                $type = (int) ($order->tlsTsTradeType(false) / 10);

                $txnId = TsTxnId::makePoTxnId($dt, $type, 0);

                $subTxnId = TsTxnId::getOrMakeFundTxnId($txnId, $order->yt_txn_id, $order->yt_yingmi_order_id);
            }

            $order->yt_ts_txn_id = $subTxnId;
            DirtyDumper::xlogDirty($order, 'yingmi_portfolio_trade_statuses update', [
                'yt_txn_id' => $order->yt_txn_id, 'yt_yingmi_order_id' => $order->yt_yingmi_order_id
            ]);
            $order->save();
        }

        $subOrder = TsOrderFund::where('ts_uid', $uid)
            ->where('ts_txn_id', $subTxnId)
            ->first();

        if (!$subOrder) {
            $subOrder = new TsOrderFund;
        }

        $subOrder->fill($order->toTsOrderFundArray($subTxnId, 0, false, null, 1));

        try {
            if ($subOrder->isDirty()) {
                DirtyDumper::xlogDirty($subOrder, 'ts_order_fund update', $subOrder->logkeys());
                $subOrder->save();
            }
        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
        }
    }

    /*
     * 将退款自动充值的订单更新到ts_order_fud
     * 通过placed_date 与 placed_amount 查找acked_date 与placed_amount
     * 相同且为购买失败的订单，ts_order_fund = -2
     *
     * filters 中存放着已经被关联的购买失败订单
     *
     */
    public function updateTsOrderFundW10($uid, $order)
    {
        if ($order->yt_trade_type != 'W10') {
            return;
        }

        if (!$order->yt_ts_txn_id) {
            return;
        }

        $subOrder = TsOrderFund::where('ts_uid', $uid)
            ->where('ts_txn_id', $order->yt_ts_txn_id)
            ->first();

        if (!$subOrder) {
            $subOrder = new TsOrderFund;
        }

        $txnId = $order->yt_ts_txn_id;
        $poTxnId = $order->yt_portfolio_txn_id;
        $portfolioId = $order->yt_portfolio_id;

        $origin = 8;
        $tsOrder = TsOrder::where('ts_txn_id', $poTxnId)->first();
        if (!$tsOrder) {
            $mfOrder = MfPortfolioTradeStatus::where('mp_txn_id', $poTxnId)->first();
            $ymOrder = YingmiPortfolioTradeStatus::where('yp_txn_id', $poTxnId)->first();

            if ($mfOrder) {
                $origin = 5;
                $poTxnId = $mfOrder->mp_ts_txn_id;
            } elseif ($ymOrder) {
                $origin = 3;
                $poTxnId = $ymOrder->yp_ts_txn_id;
            } else {
                $origin = 0;
                $poTxnId = 0;
            }
        }

        $subOrder->fill($order->toTsOrderFundArray($txnId, $poTxnId, false, $portfolioId, $origin));

        try {
            if ($subOrder->isDirty()) {
                DirtyDumper::xlogDirty($subOrder, 'ts_order_fund update', $subOrder->logkeys());
                $subOrder->save();
            }
        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
        }
    }
}
