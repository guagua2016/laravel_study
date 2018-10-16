<?php namespace App\Libraries;

use App\MfPortfolioInfo;
use App\YingmiAccount;
use Carbon\Carbon;

use DB;
use Log;
use Artisan;

use App\BnFundInfo;
use App\BnFundValue;
use App\MfDeviationPool;
use App\MfFundTradeStatus;
use App\MfPortfolioTradeStatus;
use App\RaFundNav;
use App\YingmiPortfolioTradeStatus;

use App\Libraries\MfHelper;
use App\Libraries\DirtyDumper;

use function App\Libraries\next_trade_date;

trait MfOrderUpdater
{
    /**
     *更新mf_fund_trade_statuses中的单条记录
     *@oder mf_fund_trade_statuses中的一条
     */
    public function updateOrderDatabase($uid, $order, $row = false)
    {
        $fundCode = $order['fundCode'];
        $fi = BnFundInfo::findByCode($fundCode, 'dummy');

        $xtab = [
            'mf_txn_id'          => 'brokerOrderNo',
            'mf_yingmi_order_id' => 'orderId',
            'mf_trade_type'      => 'fundOrderCode',
            'mf_trade_status'    => 'confirmStatus',
            'mf_placed_amount'   => 'tradeAmount',
            'mf_placed_share'    => 'tradeShare',
            'mf_acked_amount'    => 'successAmount',
            'mf_acked_share'     => 'successShare',
            'mf_acked_date'      => 'orderConfirmDate',
            'mf_acked_fee'       => 'fee',
            'mf_trade_date'      => 'orderTradeDate',
            'mf_extra'           => 'tradeExta',
            'mf_yingmi_ta_id'    => 'taId',
            'mf_redeem_pay_date' => 'transferIntoDate',
            'mf_error_code'      => 'errorCode',
            'mf_error_msg'       => 'errorMessage',
            'mf_trade_nav'       => 'tradeNav',
        ];

        $orderInfo = [
            'mf_uid'       => $uid,
            'mf_fund_id'   => $fi->fi_globalid,
            'mf_fund_code' => $fi->fi_code,
            'mf_fund_name' => $fi->fi_name,
        ];

        foreach ($xtab as $k => $v) {
            if (isset($order[$v]) && !is_null($order[$v])) {
                $orderInfo[$k] = $order[$v];
            }
        }

        if (isset($order['orderCreatedOn']) && !is_null($order['orderCreatedOn']))
        {
            $placed = Carbon::parse($order['orderCreatedOn']);
            $orderInfo['mf_placed_date'] = $placed->toDateString();
            $orderInfo['mf_placed_time'] = $placed->toTimeString();
        }

        if (isset($order['orderTradeDate']) && !is_null($order['orderTradeDate']))
        {
            if (!isset($orderInfo['mf_trade_nav'])
                || $orderInfo['mf_trade_nav'] == 0
            ) {
                $val = RaFundNav::where('ra_fund_id', $orderInfo['mf_fund_id'])
                    ->where('ra_date', $orderInfo['mf_trade_date'])
                    ->first();

                $orderInfo['mf_trade_nav'] = $val ? $val->ra_nav : '0.0000';
            }
        }

        $row = $this->updateRow($orderInfo, $row);

        if ($row) {
            $row->save();
        }

        // 对订单进行处理
        // 1. 申购失败
        // 2. 调仓赎回确认
        // ...
        //if ($row) {
        //    $this->dealOrder($row);
        //}

        return true;
    }

    protected function updateRow($orderInfo, $row)
    {
        $checks = [
            'mf_uid',
        ];

        $txn = false;
        if (!$row) {
            if (isset($orderInfo['mf_txn_id']) && !is_null($orderInfo['mf_txn_id']))
            {
                $txn = $orderInfo['mf_txn_id'];
                $model = MfFundTradeStatus::where('mf_txn_id', $txn);
            } else
            {
                $yingmiId = $orderInfo['mf_yingmi_order_id'];
                $model = MfFundTradeStatus::where('mf_yingmi_order_id', $yingmiId);
            }

            $row = $model->first();
        }

        if ($row) {
            $failed = false;
            foreach($checks as $c) {
                if ($row->{$c} != $orderInfo[$c]) {
                    Log::error('column value mismatch: ', [
                        "order.$c" => $row->{$c},
                        "info.$c"  => $orderInfo[$c],
                        'yt_id'    => $row->id,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return false;
            }

            $row->fill($orderInfo);
            if ($row->isDirty()) {
                DirtyDumper::xlogDirty($row, 'trade status update', ['mf_txn_id' => $txn]);
            }
        } else {
            $row = new MfFundTradeStatus($orderInfo);
        }

        return $row;
    }

    public function updatePoOrderDatabase($uid, $orderId)
    {
        Log::info("update portfolio order", [$uid, $orderId]);

        $poOrder = MfPortfolioTradeStatus::with('subOrders')
                 ->where('mp_uid', $uid)
                 ->where('mp_trade_status', '!=', 'P10')
                 ->where('mp_txn_id', $orderId)
                 ->first();

        if (!$poOrder) {
            Log::error("update mofang portfolio order failed:[$orderId]");
            return false;
        }

        $yingmi = YingmiPortfolioTradeStatus::with('composite')
                ->where('yp_trade_type', 'P03')
                ->whereIn('yp_trade_status', ['P0', 'P1', 'P2', 'P4'])
                ->where('yp_mf_txn_id', $orderId)
                ->get();

        if (!isset($poOrder->subOrders) && $poOrder->subOrders->isEmpty() && $yingmi->isEmpty()) {
            Log::error("update mofang portfolio subOrders is empty:[$orderId]");
            return false;
        }

        $poType = $poOrder->mp_trade_type;

        if ($poType == 'P04') {
            // 处理盈米订单
            foreach ($yingmi as $y) {
                foreach ($y->composite as $c) {
                    if ($c->yt_dealed == 0 && $c->yt_trade_status == 2) {

                        $value = $c->yt_acked_amount;
                        $poTxnId = $y->yp_mf_txn_id;
                        $txnId = $c->id;

                        //MfHelper::buyingPortfolioAdjust($uid, $value, $poTxnId, $txnId);
                    }
                }
            }
        }

        if (isset($poOrder->subOrders) && !$poOrder->subOrders->isEmpty() && !$yingmi->isEmpty()) {
            $subOrders = $poOrder->subOrders;
            $row['mp_acked_amount'] = $subOrders->sum('mf_acked_amount') + $yingmi->sum('yp_acked_amount');
            $row['mp_acked_fee'] = $subOrders->sum('mf_acked_fee') + $yingmi->sum('yp_acked_fee');
            $row['mp_acked_date'] = max($subOrders->max('mf_acked_date'), $yingmi->max('yp_acked_date'));
            $row['mp_trade_date'] = $subOrders->min('mf_trade_date');

            $status = $subOrders->pluck('mf_trade_status')->toArray();
            $status = array_diff($status, [7, 8, -3, -2, -1]);

            $ystatus = true;
            foreach ($yingmi as $y) {
                if (!$y->dealed) {
                    $ystatus = false;
                    continue;
                }
            }

        } else if (isset($poOrder->subOrders) && !$poOrder->subOrders->isEmpty()) {
            $subOrders = $poOrder->subOrders;
            $row['mp_acked_amount'] = $subOrders->sum('mf_acked_amount');
            $row['mp_acked_fee'] = $subOrders->sum('mf_acked_fee');
            $row['mp_acked_date'] = $subOrders->max('mf_acked_date');
            $row['mp_trade_date'] = $subOrders->min('mf_trade_date');

            $status = $subOrders->pluck('mf_trade_status')->toArray();
            $status = array_diff($status, [7, 8, -3, -2, -1]);

            $ystatus = true;
        } else if (!$yingmi->isEmpty()) {
            $row['mp_acked_amount'] = $yingmi->sum('yp_acked_amount');
            $row['mp_acked_fee'] = $yingmi->sum('yp_acked_fee');
            $row['mp_acked_date'] = $yingmi->max('yp_acked_date');
            $row['mp_trade_date'] = $yingmi->min('mf_trade_date');

            $status = [2];

            $ystatus = true;
            foreach ($yingmi as $y) {
                if (!$y->dealed) {
                    $ystatus = false;
                    continue;
                }
            }
        }

        if (!isset($status)) {
            return;
        }

        $row['mp_acked_amount'] = number_format($row['mp_acked_amount'], 2, '.', '');
        $row['mp_acked_fee'] = number_format($row['mp_acked_fee'], 2, '.', '');

        // 组合订单当前状态
        //$status = $subOrders->pluck('mf_trade_status')->toArray();
        //$status = array_diff($status, [7, 8]);

        $row['mp_trade_status'] = $poOrder->mp_trade_status;
        if (in_array(0, $status)) {

            // 存在未确认订单，组合订单状态为未确认
            $row['mp_trade_status'] = 'P1';
        } else if (in_array(1, $status)) {
            if (in_array(2, $status) || in_array(3, $status)) {

                // 部分订单成功部分失败
                $row['mp_trade_status'] = 'P4';
            } else {

                //所有订单全部失败
                $row['mp_trade_status'] = 'P3';
            }
        } else if (in_array(3, $status)) {

            //部分订单成功
            $row['mp_trade_status'] = 'P4';
        } else {

            if (!empty($status)) {
                $status = array_diff($status, [9]);

                if (empty($status)) {
                    $row['mp_trade_status'] =  'P9';
                } else {
                    //所有订单成功
                    if ($ystatus) {
                        $row['mp_trade_status'] = 'P2';
                    } else {
                        $row['mp_trade_status'] = 'P1';
                    }
                }
            }
        }

        // 对于调仓订单，存在当前所有子订单全部确认
        // 但是赎回订单的钱还没有返回到盈米宝, 因此
        // 没有用于购买新的基金，此时组合订单状态为未确认
        if ($poType == 'P04' && isset($poOrder->subOrders)) {
            $unDealed = $poOrder->subOrders->filter(function ($order) {
               return  ($order->mf_trade_type == 'W05' && $order->mf_trade_status == 2 && $order->mf_dealed == 0);
            });

            // 存在赎回未处理订单，组合订单状态为未确认
            if (!$unDealed->isEmpty()) {
                $row['mp_trade_status'] = 'P1';
            }
        }

        $delayed = $poOrder->subOrders->filter(function ($order) {
            return $order->mf_trade_status == 11;
        });
        if (!$delayed->isEmpty()) {
            $row['mp_trade_status'] = 'P1';
        }

        if ($poType == 'P04') {
                //dd($row, $uid, $orderId);

                if (isset($row['mp_trade_status']) && ($row['mp_trade_status'] == 'P0' || $row['mp_trade_status'] == 'P1')) {
                $placedTime = $poOrder->mp_placed_time;
                $placedDate = $poOrder->mp_placed_date;
                if ($placedTime > '15:00:00') {
                    $ackDate = next_trade_date($placedDate, 9);
                } else {
                    $ackDate = next_trade_date($placedDate, 8);
                }

                if ($row['mp_acked_date'] < $ackDate) {
                    $row['mp_acked_date'] = $ackDate;
                }
            }
        }

        $poOrder->fill($row);
        if ($poOrder->isDirty()) {
            DirtyDumper::xlogDirty($poOrder, 'mofang portfolio trade status update', ['mp_txn_id' => $orderId]);
        }

        $statusUpdated = false;
        if ($poOrder->getOriginal('mp_trade_status') != $poOrder->mp_trade_status) {
            $statusUpdated = true;
        }
        $poOrder->save();

        //if (isset($row['mp_trade_status'])
        //    && $row['mp_trade_status'] == 'P2'
        //    && $statusUpdated
        //) {
        //    Artisan::call('mf:up_ev', ['uid' => $uid]);
        //}

        // 调仓完成后将调仓信号状态更新为执行完成
        if ($poOrder->mp_trade_type == 'P04' &&
            in_array($poOrder->mp_trade_status, ['P2', 'P3', 'P4'])) {
                $sign = MfDeviationPool::where('mf_uid', $uid)
                      ->where('mf_transfer_id', $poOrder->mp_txn_id)
                      ->orderBy('mf_trade_date', 'DESC')
                      ->first();

                if ($sign) {
                    $sign->mf_status = 2;
                    $sign->save();
                }
        }
    }

    public function dealOrder($order)
    {
        $poOrder = MfPortfolioTradeStatus::where('mp_txn_id', $order->mf_portfolio_txn_id)
                 ->first();

        if (!$poOrder) {
            Log::error("not find portfolio order:[$order->mf_portfolio_txn_id]");
            return false;
        }
        $uid = $poOrder->mp_uid;

        // 基金订单失败的处理
        if (in_array($order->mf_trade_status, [1, 3]) &&
            $order->mf_trade_type == 'W04') {
                $this->dealFaildOrder($uid, $order);
        }

        // 调仓订单
        //if ($poOrder->mp_trade_type == 'P04') {
        //    if (in_array($order->mf_trade_type, ['024', 'W05']) &&
        //        $order->mf_dealed == 0) {
        //            $this->dealAdjustOrder($uid, $order);
        //    }
        //}
    }

    public function dealFaildOrder($uid, $order)
    {
        if (!is_null($order->mf_parent_txn_id)) {
            return;
        }

        Artisan::queue('mf:op_po', [
            '--op' => 6,
            '--fund_order_id' => $order->mf_txn_id,
        ]);
    }

    public function dealAdjustOrder($uid, $order)
    {
        if (!is_null($order->mf_parent_txn_id)) {
            return;
        }

        $errorMsg = 'portfolio adjust error:';

        $signs = MfDeviationPool::where('mf_uid', $uid)
               ->orderBy('mf_trade_date', 'DESC')
               ->take(2)
               ->get();

        if ($signs->isEmpty()) {
            Log::error(sprintf("%s mf_devitaion_pool is null [%s]",
            $errorMsg, $uid));
        }

        $first = $signs->first();

        $value = $order->mf_acked_amount;
        $poTxnId = $order->mf_portfolio_txn_id;
        $txnId = $order->mf_txn_id;

        if ($order->mf_trade_status == 2 || $order->mf_trade_status == 4) {
            if (in_array($first->mf_autorun, [0, 2])) {
                // 正在执行调仓, 且没有新的调仓信号
                //MfHelper::buyingPortfolioAdjust($uid, $value, $poTxnId, $txnId);

                if ($first->mf_status != 1) {
                    Log::error(sprintf("%s mf_devitaion_pool.mf_status=%d [%s]",
                   $errorMsg, $first->mf_status, $uid));
                }
            } else if ($first->mf_autorun == 1) {
                 /*
                  * 调仓中遇到新的调仓信号
                  * 首先执行新的赎回调整，然后执行此次购买
                  * stage one:
                  *   1. cancel mf fund buying order in mf_fund_trade_status
                  *   2. get the sum of placed_amount of all the canceled orders above, as Amt1
                  *   3. cancel the mf fund redeeming order in mf_fund_trade_status
                  * stage two:
                  *   if Amt1 > 0
                  *       call zxb (op=5, amount=Amt1), get returns (op=1, op=1)
                  *       call zxb (op=5, amount=0),    get returns (op=2, op=6)
                  *   if Amt1 <=0:
                  *       call zxb (op=5, amount=0),    get returns (op=2, op=6)
                  */
                $vip = [
                    // 1000000001,
                    // 1000000002,
                    // 1000000006,
                    // 1000000011,
                    // 1000000074,
                    // 1000000087,
                    // 1000000091,
                    // 1000000154,
                    // 1000001054,
                    // 1000001138,
                    // 1000001141,
                    // 1000078305,
                ];
                MfHelper::sendSms("Adjust during Adjust happened, Please Deal. uid=$uid, mf_txn_id=$poTxnId, txn_id=$txnId");
                if(!in_array($uid, $vip)){
                    //MfHelper::sendSms("Adjust during Adjust (not vip return directly)happened, Please Deal. uid=$uid, mf_txn_id=$poTxnId, txn_id=$txnId");
                    return;
                }

                //stage one
                $cancel_amount = 0;
                $cancel_parent_txn_ids = [];
                $buying_rows = MfHelper::getAdjustBuyingOrder($uid, $poTxnId);
                foreach ($buying_rows as $row){
                    $res = MfHelper::cancelFundOrder($row->mf_txn_id);
                    if(isset($res['code']) && $res['code']==20000 && isset($res['result']['amount']) && isset($res['result']['parent_id'])){
                        $cancel_amount += $res['result']['amount'];
                        $cancel_parent_txn_ids[] = $res['result']['parent_id'];
                    }
                }

                $redeeming_rows = MfHelper::getAdjustRedeemingOrder($uid, $poTxnId);
                foreach ($redeeming_rows as $row){
                    $res = MfHelper::cancelFundOrder($row->mf_txn_id);
                }

                //stage 2
                if ($cancel_amount > 0){
                        //MfHelper::buyingPortfolioAdjust($uid, $cancel_amount, $poTxnId, implode(',',$cancel_parent_txn_ids));
                }

                //to do next call zxb (op=5, amount=0)
                $po_info = MfPortfolioInfo::where('mf_uid', $uid)->first();
                $ym_account = YingmiAccount::where('ya_Uid',$uid)->first();
                $selected = MfHelper::getPaymentInfo($uid);
                $sub_orders = [];
                $selected_status = [];
                $po_ori_comp = MfHelper::getUserPoAdjustTradeList($uid);
                if(isset($po_ori_comp['result']['op_list'])){
                    $po_comps = $po_ori_comp['result']['op_list'];
                    foreach ($po_comps as $list){
                        if($list['op'] == 6){
                            $selected_status[] = MfHelper::redeemingYingmiPortfolio($uid, $list['id'], $list['redemption'], $ym_account->ya_account_id, $selected->yp_payment_method_id, $poTxnId, 1);
                        }else{
                            $sub_order_id = MfHelper::getOrderId();
                            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id'=>$sub_order_id]);
                            $model->mf_uid = $uid;
                            $model->mf_portfolio_txn_id = $poTxnId;
                            $model->mf_portfolio_id = $po_info->id;
                            $fund_info = MfHelper::getFundInfoByCode($list['fund_code']);
                            $model->mf_fund_id = $fund_info->fi_globalid;
                            $model->mf_fund_code = $list['fund_code'];
                            $model->mf_fund_name = $fund_info->fi_name;
                            $model->mf_trade_type = 'W05';//调仓时赎回到盈米宝
                            $model->mf_trade_status = 8; //未正式向盈米下单
                            $model->mf_placed_share = $list['amount'];
                            $model->save();
                            $sub_orders[] = $sub_order_id;
                        }
                    }

                    //执行赎回指令 redeem_to=1到盈米宝 =0到银行卡 , --pool_id用于在赎回订单提交后，更新mf_deviation_pool的状态
                    if(!empty($sub_orders)){
                        $adjust = MfDeviationPool::where('mf_transfer_id', $poTxnId)->first();
                        $mf_status = Artisan::call('mf:redeem_po', ['--po_order_id'=>$poTxnId, '--redeem_to'=>'1', '--pool_id'=>$adjust->id, '--transfer_id'=>$poTxnId]);
                    }
                }

                // 修改当前调仓信号正在执行中，且把上一个调仓信息调整为执行中止
                if ($first->mf_status != 0) {
                    Log::error(sprintf("%s mf_devitaion_pool.mf_status = %d [%s]",
                        $errorMsg, $first->mf_status, $uid));
                }
                $first->mf_autorun = 2;
                $first->mf_status = 1;

                if ($signs->count() == 2) {
                    $signs->last()->mf_status = 3;
                } else {
                    Log::error(sprintf("%s not find last diviation [%s]", $uid));
                }
//                MfHelper::buyingPortfolioAdjust($uid, 0, $poTxnId);
//                MfHelper::buyingPortfolioAdjust($uid, $value, $poTxnId, $txnId);
//                // 修改当前调仓信号正在执行中，且把上一个调仓信息调整为执行中止
//                if ($first->mf_status != 0) {
//                    Log::error(sprintf("%s mf_devitaion_pool.mf_status = %d [%s]",
//                    $errorMsg, $first->mf_status, $uid));
//                }
//                $first->mf_autorun = 2;
//                $first->mf_status = 1;
//
//                if ($signs->count() == 2) {
//                    $signs->last()->mf_status = 3;
//                } else {
//                    Log::error(sprintf("%s not find last diviation [%s]", $uid));
//                }
            }
        }

    }
}
