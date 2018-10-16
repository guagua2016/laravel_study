<?php
namespace App\Libraries;

use DB;
use Log;
use App\MessageGlobalIds;
use App\SmsContents;
use App\SmsTemplates;
use App\SmsSents;

use App\Libraries\JobStatus;
use App\Libraries\Rpc;
use App\Libraries\TradeDate;

use Carbon\Carbon;

use App\TsOrderFund;
use App\TsWalletShare;

use App\Jobs\JobCalcTsWalletShare;

class TsWalletService
{
    use TradeDate;
    /**
     * 对 19订单进行下单操作
     */
    public static function placeWalletOrder19($txnId)
    {
        $logtag = "[SQBXD:KUN:$txnId] ";
        $orders = TsOrderFund::where('ts_txn_id', $txnId)->get();
        if ($orders->isEmpty()) {
            Log::error($logtag.'ts_order_fund not found', ['ts_txn_id' => $txnId]);
            return [false, 'ts_order_fund not found', $txnId];
        }

        if ($orders->count() > 1) {
            Log::error($logtag.'ts_order_fund found multiple ', ['ts_txn_id' => $txnId]);
            return [false, 'ts_order_fund found multiple', $txnId];
        }

        $order = $orders->first();
        $uid = $order->ts_uid;
        $paymethod = $order->ts_pay_method;

        $logtag = sprintf("[SQBXD:KUN:%s] ", $uid);

        if ($order->ts_trade_type != 19) {
            Log::error($logtag.'invalid ts_trade_type for placing ', [
                'ts_txn_id' => $txnId, 'ts_trade_type' => $order->ts_trade_type]);
            return [false, 'invalid ts_trade_status for placing', $txnId];
        }

        if ($order->ts_trade_status != 0) {
            Log::error($logtag.'invalid ts_trade_status for placing ', [
                'ts_txn_id' => $txnId, 'ts_trade_status' => $order->ts_trade_status]);
            return [false, 'invalid ts_trade_status for placing', $txnId];
        }

        //
        // 检查魔方宝是否存在
        //
        $share = TsWalletShare::where('ts_uid', $uid)
            ->where('ts_fund_code', '001826')
            ->where('ts_pay_method', $paymethod)
            ->first();
        if (!$share) {
            Log::error($logtag.'ts_wallet_share not found ', [
                'ts_uid' => $uid, 'ts_txn_id' => $txnId, 'ts_pay_method' => $order->ts_pay_method]);
            return [false, 'ts_wallet_share not foundg', [$uid, $paymethod, $txnId]];
        }

        //
        // 检查魔方宝的可用余额
        //
        if ($share->ts_amount_avail - $order->ts_placed_amount < -0.00001) {
            Log::error($logtag.'ts_amount_avail not enough ', [
                'ts_uid' => $uid, 'ts_txn_id' => $txnId,
                'ts_pay_method' => $order->ts_pay_method,
                'ts_amount_avail' => $share->ts_amount_avail,
                'ts_placed_amount' => $order->ts_placed_amount]);
            return [false, 'ts_amount_avail not enough', [$uid, $paymethod, $txnId]];
        }

        //
        // 下单操作
        //
        $now = Carbon::now();
        $tradeDate = static::tradeDatewithTime($now->toDateString(), $now->toTimeString());

        $order->ts_placed_date = $now->toDateString();
        $order->ts_placed_time = $now->toTimeString();
        $order->ts_trade_date = $tradeDate;
        $order->ts_trade_nav = 1.0000;
        $order->ts_acked_amount = $order->ts_placed_amount;
        $order->ts_acked_share = $order->ts_placed_amount;
        $order->ts_acked_date = $tradeDate;
        $order->ts_trade_status = 6;
        $order->ts_pay_status = 1;
        $order->save();

        //
        // 更新盈米宝余额
        //
        $job = new JobCalcTsWalletShare($uid, $paymethod);
        $job->handle();

        return [true, 'Succeed', $txnId];
    }

    /**
     * 对29订单进行下单操作
     */
    public static function placeWalletOrder29($txnId)
    {
        $logtag = "[SQBXD:KUN:$txnId] ";
        $orders = TsOrderFund::where('ts_txn_id', $txnId)->get();
        if ($orders->isEmpty()) {
            Log::error($logtag.'ts_order_fund not found', ['ts_txn_id' => $txnId]);
            return [false, 'ts_order_fund not found', $txnId];
        }

        if ($orders->count() > 1) {
            Log::error($logtag.'ts_order_fund found multiple ', ['ts_txn_id' => $txnId]);
            return [false, 'ts_order_fund found multiple', $txnId];
        }

        $order = $orders->first();
        $uid = $order->ts_uid;
        $paymethod = $order->ts_pay_method;

        $logtag = sprintf("[SQBXD:KUN:%s] ", $uid);

        if ($order->ts_trade_type != 29) {
            Log::error($logtag.'invalid ts_trade_type for placing ', [
                'ts_txn_id' => $txnId, 'ts_trade_type' => $order->ts_trade_type]);
            return [false, 'invalid ts_trade_status for placing', $txnId];
        }

        if ($order->ts_trade_status != 0) {
            Log::error($logtag.'invalid ts_trade_status for placing ', [
                'ts_txn_id' => $txnId, 'ts_trade_status' => $order->ts_trade_status]);
            return [false, 'invalid ts_trade_status for placing', $txnId];
        }

        //
        // 检查魔方宝是否存在
        //
        $share = TsWalletShare::where('ts_uid', $uid)
            ->where('ts_fund_code', '001826')
            ->where('ts_pay_method', $paymethod)
            ->first();
        if (!$share) {
            Log::error($logtag.'ts_wallet_share not found ', [
                'ts_uid' => $uid, 'ts_txn_id' => $txnId, 'ts_pay_method' => $order->ts_pay_method]);
            return [false, 'ts_wallet_share not foundg', [$uid, $paymethod, $txnId]];
        }

        //
        // @TODO 检查魔方宝的可用余额
        //
        // [XXX] 这个地方之所以不检查盈米宝的可用余额是因为，这个时候很可能没
        // 有重新计算盈米宝的份额，解冻判断不过去。
        //

        // if ($share->ts_amount_avail - $order->ts_placed_amount > -0.00001) {
        //     Log::error($logtag.'ts_amount_avail not enough ', [
        //         'ts_uid' => $uid, 'ts_txn_id' => $txnId,
        //         'ts_pay_method' => $order->ts_pay_method,
        //         'ts_amount_avail' => $share->ts_amount_avail,
        //         'ts_placed_amount' => $order->ts_placed_amount]);
        //     return [false, 'ts_wallet_share not foundg', [$uid, $paymethod, $txnId]];
        // }

        //
        // 下单操作
        //
        $now = Carbon::now();
        $tradeDate = static::tradeDatewithTime($now->toDateString(), $now->toTimeString());

        $order->ts_placed_date = $now->toDateString();
        $order->ts_placed_time = $now->toTimeString();
        $order->ts_trade_date = $tradeDate;
        $order->ts_trade_nav = 1.0000;
        $order->ts_acked_amount = $order->ts_placed_share;
        $order->ts_acked_share = $order->ts_placed_share;
        $order->ts_acked_date = $tradeDate;
        $order->ts_trade_status = 6;
        $order->ts_pay_status = 1;
        $order->save();

        //
        // 更新盈米宝余额
        //
        $job = new JobCalcTsWalletShare($uid, $paymethod);
        $job->handle();

        return [true, 'Succeed', $txnId];
    }


}
