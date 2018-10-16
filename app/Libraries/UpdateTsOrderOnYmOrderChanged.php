<?php namespace App\Libraries;

use App\Http\Controllers\YingmiPortfolioController;

use Carbon\Carbon;

use DB;
use Log;
use App\TsOrder;
use App\TsOrderFund;
use App\TsTxnId;
use App\YingmiPortfolioTradeStatus;
use App\YingmiTradeStatus;
use App\TsPlanFund;

use App\Events\TsOrderChanged;
use App\Jobs\JobCalcTsShareTxn;
use App\Jobs\JobCalcTsWalletShareTxn;

use App\Libraries\TradeDate;
use App\Libraries\SmsService;
use App\Libraries\DirtyDumper;
use App\Libraries\TsHelper;
use App\Libraries\TradeSdk\TsHelperZxb;

use function App\Libraries\basename_class;
use function App\Libraries\model_array_cud;


trait UpdateTsOrderOnYmOrderChanged
{
    use TradeDate;

    public function onYingmiOrderChanged($uid, $txnId)
    {
        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $changed = false;

        //
        // [XXX] 这个地方有个业务逻辑的坑，事情本来是本来是分两步：
        //
        // 1. 拷贝yingmi的基金订单状态到ts_order_fund表
        // 2. 根据更新后的ts_order_fund重新计算ts_order的信息
        //
        // 这两个本来一个功能，紧耦合完成即可。 但因为晓彬那边需要更新plan的信
        // 息，而plan的信息是所有ts_order整体更新的。所以事情就被拆成了三个独
        // 立的步骤。
        //
        // a. 拷贝yingmi的基金订单状态到ts_order_fund表
        // b. 根据ts_order_fund更新plan
        // c. 根据ts_order_fund重新计算ts_order的信息
        //
        // 其中的c步骤需要注意的是，如果是盈米组合的订单，更新ts_order的方式有
        // 两个：(1)订单状态直接拷贝盈米组合即可;(2)跟新组合一样自己算。这里我
        // 们采用第二种，因为第一种如何盈米的组合和自己的订单状态不一致时会有
        // 问题，比如下面case，更新盈米组合和基金订单，拷贝基金订单，别的程序
        // 有更新了盈米组合和基金订单，更新plan，采用拷贝的方式更新盈米组合订
        // 单。
        //

        // 根据TsOrder获取处理元组
        $rows = TsOrder::where('ts_txn_id', $txnId)
            ->orderBy('ts_portfolio_id', 'ASC')
            ->get();
        if ($rows->isEmpty()) {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            Log::error($this->logtag.'ts_order not exists', ['ts_txn_id'=> $txnId, 'backtrace' => $bt]);
            return;
        }

        $primary = $rows->first();

        //
        // step 1: ying_trade_status => ts_order_fund
        //
        foreach ($rows as $row) {
            //
            // 根据不同的组合订单类型处理
            //
            if (substr($row->ts_portfolio_id, 0, 2) == 'ZH') {
                //
                // 盈米老组合，组合订单是我们下单，基金订单是盈米下单
                //
                if ($this->handleOldOrderChanged($uid, $row)) {
                    $changed = true;
                }

            } else {
                //
                // 千人千面组合
                //
                if ($this->handleNewOrderChanged($uid, $row)) {
                    $changed = true;
                }
            }
        }

        if ($primary->ts_trade_type != 7) {
            //
            // step 2: 更新计划 并 checkOrder
            //
            @list($rc, $s) = TsHelperZxb::continueOrder($txnId);
            Log::info($this->logtag.'calling TsHelperZxb::continueOrder:', ['in' => $txnId, 'out' => [$rc, $s]]);
            // if ($rc != 20000) {
            //
            //    晓彬说continueOrder理论上不会失败，这里只做log不做进一步审查
            //
            // }
            @list($rc, $s) = TsHelperZxb::checkOrder($txnId);
            Log::info($this->logtag.'calling TsHelperZxb::checkOrder:', ['in' => $txnId, 'out' => [$rc, $s]]);
            if ($rc == 20000) {
                $isPlanFinished = 1;
            } elseif ($rc == 40001) {
                $isPlanFinished = -1;
            } elseif ($rc == 20004) {
                // $isPlanFinished = 2; // 调仓被打断，部分资金转出
                $isPlanFinished = 1; // 调仓被打断，部分资金转出
            } else {
                $isPlanFinished = 0;
            }
        } else {
            $isPlanFinished = 1;
        }

        //
        // step3: 重新计算TsOrder信息
        //
        $count = 0;
        foreach ($rows as $row) {
            if ($this->recalcAndUpdateTsOrder($row, $isPlanFinished)) {
                $count += 1;
            }
        }

        //
        // 发送事件，通知损益系统重算持仓
        //
        if ($changed) {
            Log::info($this->logtag."fire(TsOrderChanged) and JobCalcTsShareTxn", ['uid' => $uid, 'txn' => $txnId]);
            //  event(new TsOrderChanged($uid, $txnId, false));

            //
            // 重新计算持仓(同步计算)
            //
            (new JobCalcTsWalletShareTxn($uid, $txnId))->handle();
            if (!in_array($primary->ts_trade_type, [1, 2])) {
                (new JobCalcTsShareTxn($uid, $txnId))->handle();
            }
        } else {
            Log::info($this->logtag.'no ts_order_fund  change, skip fire(TsOrderChanged) and  JobCalcTsShareTxn', ['txn' => $txnId]);
        }

        return $changed;
    }

    public function recalcAndUpdateTsOrder($order, $isPlanFinished)
    {
        $data = [];
        //
        // 获取TsOrderFund
        //
        $suborders = TsOrderFund::where('ts_uid', $order->ts_uid)
            ->where('ts_portfolio_id', $order->ts_portfolio_id)
            ->where('ts_pay_method', $order->ts_pay_method)
            ->where('ts_portfolio_txn_id', $order->ts_txn_id)
            ->whereNotIn('ts_trade_type', [13, 14, 97, 98])
            ->get();

        //
        // 分两种情况：
        //
        // 1. 如果有子订单，则根据子订单计算
        // 2. 如果无子订单，且是盈米老组合单，则直接同步老组合状态，否则还是走计算逻辑；
        //
        if ($suborders->isEmpty() && substr($order->ts_portfolio_id, 0, 2) == 'ZH') {
            $ypTxnId = sprintf("%s:%s:%s", $order->ts_txn_id, $order->ts_pay_method, $order->ts_portfolio_id);
            $ymPoOrder = YingmiPortfolioTradeStatus::where('yp_txn_id', $ypTxnId)->first();
            if ($ymPoOrder) {
                $data = $ymPoOrder->getTsOrderFillArray();
            }
        }

        if (!$data) {
            //
            // 根据新的ts基金订单，更新组合订单
            //
            $count = 0;
            $stat = (object)['accepted' => 0, 'placed' => 0, 'part' => 0, 'acked' => 0, 'failed' => 0, 'canceled' => 0];
            $data = [
                'ts_trade_date' => '9999-99-99',
                'ts_placed_date' => '9999-99-99',
                'ts_placed_time' => '23:59:59',
                'ts_acked_date' => '0000-00-00',
                'ts_redeem_pay_date' => '0000-00-00',
                'ts_pay_status' => 0,
            ];
            @list($ackedAmount, $ackedFee, $chargeStatus, $withdrawStatus) = [0, 0, 0, 0];
            foreach ($suborders as $so) {
                if ($so->ts_trade_date != '0000-00-00' && $data['ts_trade_date'] > $so->ts_trade_date) {
                    $data['ts_trade_date'] = $so->ts_trade_date;
                }

                if ($so->ts_placed_date != '0000-00-00' && $so->ts_placed_time != '00:00:00'
                    && $data['ts_placed_date'].$data['ts_placed_time'] > $so->ts_placed_date.$so->ts_placed_time) {
                    $data['ts_placed_date'] = $so->ts_placed_date;
                    $data['ts_placed_time'] = $so->ts_placed_time;
                }

                if ($so->ts_acked_date != '0000-00-00' && $data['ts_acked_date'] < $so->ts_acked_date) {
                    $data['ts_acked_date'] = $so->ts_acked_date;
                }

                if ($so->ts_redeem_pay_date && $data['ts_redeem_pay_date'] < $so->ts_redeem_pay_date) {
                    //
                    // [XXX] 调仓时这个有问题
                    //
                    $data['ts_redeem_pay_date'] = $so->ts_redeem_pay_date;
                }

                if (in_array($so->ts_trade_type, [10, 12, 19])) {
                    //
                    // 如果是充值订单， 计算充值状态
                    //
                    // -2: 扣款失败，确认失败
                    // -1: 扣款失败，确认中
                    // 0:  受理
                    // 1:  扣款中, 确认中
                    // 3:  扣款成功，确认中
                    // 5:  部分成功
                    // 6:  成功
                    //
                    $data['ts_pay_status'] = $so->ts_pay_status;

                    if ($so->ts_trade_status < 0) {
                        $chargeStatus = -2;
                    } elseif ($so->ts_trade_status == 0) {
                        $chargeStatus = 0;
                    } elseif ($so->ts_trade_status == 1) {
                        if ($so->ts_pay_status == 0) {
                            $chargeStatus = 1;
                        } elseif ($so->ts_pay_status < 0) {
                            $chargeStatus = -1;
                        } else {
                            $chargeStatus = 3;
                        }
                    } else {
                        $chargeStatus = $so->ts_trade_status == 6 ? 6 : 5;
                    }

                    if ($chargeStatus == 5 || $chargeStatus == 6) {
                        $data['ts_pay_status'] = 1;

                        if ($order->ts_trade_type == 1) {
                            $ackedAmount += $so->ts_acked_amount;
                            $ackedFee += $so->ts_acked_fee;
                        }
                    }

                } elseif (in_array($so->ts_trade_type, [20, 21, 22, 29])) {
                    //
                    // 如果是提现订单, 计算提现状态
                    //
                    // -1: 失败
                    // 0：受理
                    // 1: 划款中, 确认中
                    // 5: 部分成功
                    // 6: 全部成功
                    //
                    $data['ts_pay_status'] = $so->ts_pay_status;

                    if ($so->ts_trade_status < 0) {
                        $withdrawStatus = -1;
                    } elseif ($so->ts_trade_status == 0) {
                        $withdrawStatus = 0;
                    } elseif ($so->ts_trade_status == 1) {
                        $withdrawStatus = 1;
                    } else {
                        $withdrawStatus = $so->ts_trade_status == 6 ? 6 : 5;
                    }

                    if ($withdrawStatus == 5 || $withdrawStatus == 6) {
                        $data['ts_pay_status'] = 2;

                        if ($order->ts_trade_type == 2) {
                            $ackedAmount += $so->ts_acked_amount;
                            $ackedFee += $so->ts_acked_fee;
                        }
                    }
                } else {
                    //
                    // 普通订单
                    //
                    $count += 1;
                    if ($so->ts_trade_status < 0) {
                        $stat->failed += 1;
                    } else {
                        switch($so->ts_trade_status) {
                            case 0:  $stat->accepted += 1; break;
                            case 1:  $stat->placed += 1;   break;
                            case 5:  $stat->part += 1;     break;
                            case 6:  $stat->acked += 1;    break;
                            case 7:  $stat->placed += 1;   break;
                            case 9:  $stat->canceled += 1; break;
                            default:
                                Log::error($this->logtag."unknow ts_order_fund.ts_trade_status detected", [$so->toArray()]);
                        }
                    }

                    //
                    // 只有当订单成功或部分成功时才计入acked，此外需要注意的是充值订单不计入
                    //
                    if (in_array($so->ts_trade_status, [5, 6])) {
                        $ackedAmount += $so->ts_acked_amount;
                        $ackedFee += $so->ts_acked_fee;
                    }
                }
            }

            //
            // reset 9999-99-99 => 0000-00-00
            //
            if ($data['ts_trade_date'] == '9999-99-99') {
                $data['ts_trade_date'] = '0000-00-00';
            }
            if ($data['ts_placed_date'] == '9999-99-99') {
                $data['ts_placed_date'] = '0000-00-00';
                $data['ts_placed_time'] = '00:00:00';
            }

            // dd($count, $stat, $chargeStatus, $withdrawStatus);

            //
            // 计算ts组合订单状态
            //
            if ($order->ts_trade_type == 1) {
                if (in_array($chargeStatus, [-2, -1])) {
                    $status = -1;
                } elseif (in_array($chargeStatus, [5, 6])) {
                    $status = $chargeStatus;
                } else {
                    $status = 1;
                }
            } elseif ($order->ts_trade_type == 2) {
                $status = $withdrawStatus;
            } else {
                if ($count == 0) {
                    if ($chargeStatus < 0 || $withdrawStatus < 0) {
                        $status = -1;
                    } else {
                        //
                        // 没有任何子订单, 这个情况可能发生在赎回只有老组合，购买新组合的
                        // 情况。也就是，本次交易只下了老组合的赎回单，新组合的购买单没法
                        // 下。
                        //
                        switch ($withdrawStatus) {
                            case 6: $status = 6; break; // 只有一个成功的提现订单
                            case 1: $status = 1; break; // 只有一个确认中提现订单
                            default:
                                $status = 0;
                        }
                    }

                } elseif ($stat->placed > 0) {
                    //
                    // 有确认中订单
                    //
                    $status = 1; // 确认中
                } elseif ($stat->accepted > 0) {
                    //
                    // 有非最终状态订单
                    //
                    if ($stat->accepted == $count) {
                        $status = 0; // 已授理
                    } else {
                        $status = 1; // 确认中
                    }

                } else {
                    //
                    // 全部是最终状态订单
                    //
                    if ($stat->acked > 0 || $stat->part > 0) {
                        if ($isPlanFinished == 1) {
                            $status = 6; // 成功
                        } else {
                            $status = 5; // 部分成功
                        }
                    } else {
                        if ($stat->canceled > 0 && ($stat->canceled == $count || $order->ts_trade_type == 4 || $chargeStatus > 0 && $withdrawStatus > 0)) {
                            $status = 9; // 已撤单
                        } else {
                            $status = -1; // 失败
                        }
                    }
                }
            }

            //
            // 如果是最终状态订单，调用晓彬确定是否真的是最终状态
            //
            $forceStatus = $this->getForceStatus($order->ts_txn_id);
            if ($forceStatus !== null) {
                $status = $forceStatus;
            } elseif (in_array($status, [-1, 5, 6, 9]) && !$isPlanFinished && !in_array($order->ts_txn_id, ['20170824A000038A', '20170824A000055A']) && !in_array($order->ts_trade_type, [2])) {
                $status = 1;
            } elseif ($status == 0 && $suborders->count() == 0) {
                //
                // 如果没有任何子订单，以计划为准
                //
                if ($isPlanFinished == -1) {
                    $status = -1;
                } elseif ($isPlanFinished == 1) {
                    $status = 6;
                } elseif ($isPlanFinished == 2) {
                    $status = 5;
                } else {
                    $status = 0;
                }
            }

            $data = array_merge($data, [
                'ts_acked_amount' => number_format($ackedAmount, 2, '.', ''),
                'ts_acked_fee' => number_format($ackedFee, 2, '.', ''),
                'ts_trade_status' => $status,
            ]);

            //
            // 调仓订单的确认日期比较复杂，涉及到几种情况，我们取其中的最大值
            //
            if ($order->ts_trade_type == 6 && !in_array($status, [-1, 5, 6, 9])) {
                //
                // 我们通过计划来计算确认日期
                //
                if ($data['ts_trade_date'] != '0000-00-00') {
                    $n = TsPlanFund::calcAckDate($order->ts_txn_id);
                    $planAckDate = static::tradeDate($data['ts_trade_date'], $n);
                    if ($planAckDate >= $data['ts_acked_date']) {
                        $data['ts_acked_date'] = $planAckDate;
                    }
                }
            }

            //
            // 计算错误代码和错误消息
            //
            if ($order->ts_trade_type == 1) {
                $chargeOrders = $suborders->filter(function ($e) {
                    return in_array($e->ts_trade_type, [10, 12]);
                });
                if (!$chargeOrders->isEmpty()) {
                    $chargeOrder = $chargeOrders->last();
                    $data['ts_error_code'] = $chargeOrder->ts_error_code;
                    $data['ts_error_msg'] = $chargeOrder->ts_error_msg;
                }
            } elseif ($order->ts_trade_type == 2) {
                $withdrawOrders = $suborders->filter(function ($e) {
                    return in_array($e->ts_trade_type, [20, 21, 22]);
                });
                if (!$withdrawOrders->isEmpty()) {
                    $withdrawOrder = $withdrawOrders->last();
                    $data['ts_error_code'] = $withdrawOrder->ts_error_code;
                    $data['ts_error_msg'] = $withdrawOrder->ts_error_msg;
                }
            } elseif ($order->ts_trade_type == 3) {
                $chargeOrders = $suborders->filter(function ($e) {
                    return in_array($e->ts_trade_type, [10, 12, 19]);
                });
                if (!$chargeOrders->isEmpty()) {
                    $chargeOrder = $chargeOrders->last();
                    $data['ts_error_code'] = $chargeOrder->ts_error_code;
                    $data['ts_error_msg'] = $chargeOrder->ts_error_msg;
                }
            }
        }

        if ($data['ts_placed_date'] == '0000-00-00' && $data['ts_trade_status'] != 0) {
            $data['ts_placed_date'] = substr($order->ts_scheduled_at, 0, 10);
        }

        //
        // 更新组合订单
        //
        $order->fill($data);

        //
        // 更新ts订单
        //
        @list($ok, $changed) = [true, false];
        try {
            // 保存组合订单
            if ($order->isDirty()) {
                DirtyDumper::xlogDirty($order, $this->logtag.'ts_order update', $order->logkeys());
                $order->save();
                //
                // 设置$changed标志
                //
                $changed = true;
            }

        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
            $ok = false;
        }

        return $changed;
    }

    public function handleOldOrderChanged($uid, $row)
    {
        $ymPaymethod = substr($row->ts_pay_method, 2);
        //
        // 获取对应的盈米组合订单
        //
        $ymPoOrder = YingmiPortfolioTradeStatus::where('yp_ts_txn_id', $row->ts_txn_id)
            ->where('yp_portfolio_id', $row->ts_portfolio_id)
            ->where('yp_pay_method', $ymPaymethod)
            ->first();
        if (!$ymPoOrder) {
            Log::warning($this->logtag."yingimi_portfolio_trade_statuses not found, not placed ?", $row->logkeys());
            return false;
        }

        //
        // 获取对应的盈米组合的基金订单
        //
        $ymSubOrders = YingmiTradeStatus::where('yt_portfolio_txn_id', $ymPoOrder->yp_txn_id)->get();
        //
        // 尝试填充基金订单的yt_ts_txn_id
        //
        $okSubOrders = collect();
        foreach ($ymSubOrders as $so) {
            if (!$so->yt_ts_txn_id) {
                $subTxnId = TsTxnId::makeFundTxnId($row->ts_txn_id);
                if (!$subTxnId) {
                    Log::error($this->logtag."make fund txn id failed", ['order' => $row->logkeys(), 'suborder' => $so->logkeys()]);
                    continue;
                }
                $so->yt_ts_txn_id = $subTxnId;
                DirtyDumper::xlogDirty($so, $this->logtag.'yingmi_trade_statuses update', $so->logkeys());
                $so->save();
            }
            $okSubOrders->push($so);
        }

        //
        // 更新ts订单
        //
        @list($ok, $changed) = [true, false];
        try {
            //
            // 更新基金的ts订单
            //
            foreach ($okSubOrders as $so) {
                $inAdjust = ($row->ts_trade_type == 6) || ($row->ts_trade_type == 8);
                $data = $so->toTsOrderFundArray($so->yt_ts_txn_id, $row->ts_txn_id, $inAdjust, null, 8);
                //
                // 尝试预估下单费用
                //
                $tsSubOrder = TsOrderFund::where('ts_txn_id', $so->yt_ts_txn_id)
                    ->where('ts_uid', $uid)
                    ->where('ts_portfolio_id', $so->yt_portfolio_id)
                    ->where('ts_fund_code', $so->yt_fund_code)
                    ->where('ts_pay_method', $row->ts_pay_method)
                    ->first();

                if ($tsSubOrder) {
                    //
                    // 更新
                    //
                    $tsSubOrder->fill($data);

                    if ($tsSubOrder->isDirty()) {
                        DirtyDumper::xlogDirty($tsSubOrder, $this->logtag.'ts_order_fund update', $tsSubOrder->logkeys());
                        $tsSubOrder->save();
                        $changed = true;
                    }
                } else {
                    //
                    // 新建
                    //
                    $tsSubOrder = new TsOrderFund($data);
                    Log::info($this->logtag.'ts_order_fund insert', $tsSubOrder->logkeys());
                    $tsSubOrder->save();
                    $changed = true;
                }
            }


        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
            $ok = false;
        }

        return $changed;
    }

    public function handleNewOrderChanged($uid, $order)
    {
        $ymPaymethod = substr($order->ts_pay_method, 2);

        //
        // 获取TsOrderFund
        //
        $suborders = TsOrderFund::where('ts_uid', $uid)
            ->where('ts_portfolio_id', $order->ts_portfolio_id)
            ->where('ts_pay_method', $order->ts_pay_method)
            ->where('ts_portfolio_txn_id', $order->ts_txn_id)
            ->get();

        $tsSubTxnIds = $suborders->lists('ts_txn_id');
        if (in_array($order->ts_txn_id,['20170911A000158A', '20171027B000345D'])) {
            $tsSubTxnIds = $tsSubTxnIds->map(function ($stxnid) {
                return $this->tlsYtTxnId($stxnid);
            });
        }

        //
        // 获取对应的盈米基金订单
        //
        $ymSubOrders = YingmiTradeStatus::whereIn('yt_txn_id', $tsSubTxnIds)
            ->get()
            ->keyBy('yt_txn_id');
        //
        // 更新ts基金订单
        //
        foreach ($suborders as $so) {
            $stxnid = $so->ts_txn_id;
            if (in_array($order->ts_txn_id, ['20170911A000158A', '20171027B000345D'])) {
                $stxnid = $this->tlsYtTxnId($stxnid);
            }
            $tmp = $ymSubOrders->get($stxnid);
            if ($tmp) {
                $so->fill($tmp->getTsFillArray());
            }
            //
            // 这个地方做点复杂的逻辑，这样前端处理的时候会省好多事！
            //
            // 如果是盈米宝扣款失败和未向盈米下单（-3）的情况，自动填充一下
            // ts_place_date
            //
            if (in_array($so->ts_trade_type, [10, 12, 19])) {
                if ($so->ts_trade_status < -1) {
                    if ($so->ts_placed_date == '0000-00-00') {
                        $so->ts_placed_date = substr($so->ts_scheduled_at, 0, 10);
                    }
                } else {
                    if ($so->ts_trade_nav == 0 && ($so->ts_trade_status == 5 || $so->ts_trade_status == 6)) {
                        $so->ts_trade_nav = "1.0000";
                    }
                }
            } elseif ($so->ts_trade_status == -3) {
                $so->ts_placed_date = substr($so->ts_scheduled_at, 0, 10);
                $so->ts_error_msg = '扣款失败,终止下单';
            } elseif ($so->ts_placed_date == '0000-00-00') {
                if ($so->ts_error_code) {
                    // 有错误码，说明向盈米下过单，失败了
                    $so->ts_placed_date = substr($so->ts_scheduled_at, 0, 10);
                }
            }
            //
            // 检测订单跳变
            //
            $originStatus = $so->getOriginal('ts_trade_status');
            if ($so->ts_trade_status != $originStatus) {
                $flip = false;
                if (in_array($originStatus, [-3, -2, -1, 9])
                    && in_array($so->ts_trade_status, [0, 1, 5, 6])) {
                    $flip = true;
                } elseif (in_array($originStatus, [5, 6])
                          && in_array($so->ts_trade_status, [-3, -2, -1, 9])) {
                    $flip = true;
                }

                if ($flip) {
                    Log::error($this->logtag.'SNH: final ts_trade_status changed', [
                        'ts_txn_id' => $so->ts_txn_id,
                        'src' => $originStatus,
                        'dst' => $so->ts_trade_status,
                    ]);
                    //
                    // 发送报警短信
                    //
                    $alert = sprintf($this->logtag."报警:订单终态跳变[%s, %d => %d]", $so->ts_txn_id, $originStatus, $so->ts_trade_status);
                    SmsService::smsAlert($alert, 'kun,xiaobin,pp');
                }
            }
        }

        //
        // 更新ts订单
        //
        @list($ok, $changed) = [true, false];
        try {
            //
            // 更新基金的ts订单
            //
            foreach ($suborders as $so) {
                if ($so->isDirty()) {
                    DirtyDumper::xlogDirty($so, $this->logtag.'ts_order_fund update', $so->logkeys());
                    $so->save();
                    $changed = true;
                }
            }
        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
            $ok = false;
        }

        return $changed;
    }

    public function tlsYtTxnId($ts_txn_id)
    {
        $xtab = collect([
            '20170804B001104S023' => 'c8c70f70-984f-11e7-9ff6-1fe0c7dc89fe',
            '20170804B001104S024' => 'c8c84a00-984f-11e7-a424-414c34ca38df',
            '20170804B001104S025' => 'c8c91000-984f-11e7-8e4e-959d0649f797',
            '20170804B001104S026' => 'c8c9d4a0-984f-11e7-8f77-d35f6ddbbaca',
            '20170804B001104S027' => 'c8caab50-984f-11e7-af47-a5424186759c',
            '20170804B001104S028' => 'c8cc3bd0-984f-11e7-8af9-7f27c9e113c0',
            '20171027B000345D001' => '20171027B000344D001',
       ]);

        return $xtab->get($ts_txn_id, $ts_txn_id);
    }

    public function getForceStatus($ts_txn_id)
    {
        $today = Carbon::today()->toDateString();

        $ht = [
            '20180504B000197A' => ['2018-05-11', 1],
            '20180504B000204A' => ['2018-05-11', 1],
            '20180504B000212A' => ['2018-05-11', 1],
            '20180504B000226A' => ['2018-05-11', 1],
            '20180504B000227A' => ['2018-05-11', 1],
            '20180504B000230A' => ['2018-05-11', 1],
            '20180504B000233A' => ['2018-05-11', 1],
            '20180504B000235A' => ['2018-05-11', 1],
            '20180504B000239A' => ['2018-05-11', 1],
            '20180504B000245A' => ['2018-05-11', 1],
            '20180504B000246A' => ['2018-05-11', 1],
            '20180504B000258A' => ['2018-05-11', 1],
            '20180504B000263A' => ['2018-05-11', 1],
            '20180504B000271A' => ['2018-05-11', 1],
            '20180504B000280A' => ['2018-05-11', 1],
            '20180504B000284A' => ['2018-05-11', 1],
            '20180504B000295A' => ['2018-05-11', 1],
            '20180504B000317A' => ['2018-05-11', 1],
            '20180504B000388A' => ['2018-05-11', 1],
            '20180504B000396A' => ['2018-05-11', 1],
            '20180504B000403A' => ['2018-05-11', 1],
            '20180504B000429A' => ['2018-05-11', 1],
            '20180504B000435A' => ['2018-05-11', 1],
            '20180504B000441A' => ['2018-05-11', 1],
            '20180504B000447A' => ['2018-05-11', 1],
        ];

        if (array_key_exists($ts_txn_id, $ht)) {
            list($edate, $status) = $ht[$ts_txn_id];
            if ($today < $edate) {
                return $status;
            }
        }

        return null;
    }

}
