<?php namespace App\Libraries;

use App\Http\Controllers\YingmiAccountController;
use App\TsOrder;
use App\TsOrderFund;
use App\TsPayMethod;
use App\TsTxnId;
use App\Jobs\RetryYingmibaoRechargeDealy;
use App\Libraries\TradeSdk\TradeDoubleCheck;
use App\Libraries\TradeSdk\TradeStrategyHelper;
use App\MfVipUser;
use App\WechatUser;
use App\YingmiPortfolioInfo;
use App\YingmiPortfolioShareDetail;
use App\YingmiPortfolioTradeStatus;
use App\YingmiShareDetail;
use App\YingmiTradeStatus;
use Carbon\Carbon;
use App\Libraries\YmSdk\YmHelper;
use Illuminate\Support\Facades\Bus;
use Log;
use Hash;
use Uuid;
use Storage;
use Artisan;
use App\BnFundInfo;
use App\BnFundValue;
use App\FundBonus;
use App\FundSplit;
use App\YingmiAccount;
use App\GlobalId;
use App\MfFundTradeStatus;
use App\MfFundShare;
use App\MfPortfolioTradeStatus;
use App\FundInfos;
use App\YingmiWalletFund;
use App\YingmiWalletShareDetail;
use App\YingmiPaymentMethod;
use App\UserRiskAnalyzeResult;
use App\MfPortfolioShare;
use App\MfPortfolioComposition;
use App\MfPortfolioInfo;
use App\MfFundShareDetail;
use App\MfFundHoldingDetail1;
use App\MfPortfolioInvestPlan;
use App\MfDeviationPool;
use Illuminate\Foundation\Bus\DispatchesJobs;
use App\Jobs\TestQueue;
use DB;
use Illuminate\Database\QueryException;
use App\Libraries\MfOrderUpdater;
use App\Libraries\DirtyDumper;
use App\Libraries\TsYmOrderUpdater;
use App\Libraries\basename_class;
use App\Libraries\Batch;
use App\Jobs\TsDeal1129;
use Illuminate\Bus\Queueable;
use App\YingmiPaymentProviders;
use App\TsTerminalInfo;

class TsHelper
{
    use DispatchesJobs;
    use MfOrderUpdater;
    use TsYmOrderUpdater;
    use Batch;
    use Queueable;

    /**
     * @param $uid
     * @param $payment
     * @return array
     */
    public static function getDailyAndLefyAmount($uid, $payment)
    {
        $ym_pay = $payment->ympay;
        $left_amount = 0;
        $day_quota = $ym_pay->bank->yp_max_rapid_pay_amount_per_day;
        if ($day_quota <= 0) { // 如果没有限额，暂定限额为1亿
            $left_amount = $day_quota = 100000000;
        } else {
            $today = date("Y-m-d");
            $buyed = TsOrder::where('ts_uid', $uid)
                ->whereIn('ts_trade_type', [3, 5])// 购买定投
                ->where('ts_trade_status', '>=', 0)
                ->where('ts_placed_date', $today)
                ->sum('ts_placed_amount');
            $left_amount = $day_quota - $buyed;
            $left_amount = round($left_amount, 2);
            if ($left_amount < 0) {
                $left_amount = 0;
            }
        }
        return array($ym_pay, $left_amount);
    }

    public function updateMfFundOrderByTxn($txn)
    {
        $result = [];

        $row = MfFundTradeStatus::where('mf_txn_id', $txn)->first();
        if (!$row) {
            return false;
        }
        $uid = $row->mf_uid;
        $ym_id =  YmHelper::getYmId($uid);
        $params = [
            'accountId'     => "$ym_id",
            'brokerUserId'  => "$uid",
            'brokerOrderNo' => $row->mf_txn_id,
        ];

        $tmp = YmHelper::rest_rpc("/trade/getFundOrder", $params, "get");

        if ($tmp['code'] == '20000' && is_array($tmp['result'])) {
            $this->updateOrderDatabase($uid, $tmp['result']);
            $result = ['code'=>20000, 'message'=>'success', 'result'=>$tmp];
        } else {
            $result = ['code'=>20001, 'message'=>'failed', 'result'=>$tmp];

            $valid_type = ['W04', 'W05']; //getFundOrder只返回盈米宝购买和赎回到盈米宝的订单，盈米宝充值的订单不返回
            if (isset($tmp['code']) && $tmp['code']== 1110 && in_array($row->mf_trade_type, $valid_type)) { //订单不存在
                if (isset($tmp['msg'])) {
                    $error_msg = $tmp['msg'];
                } else {
                    $error_msg = '';
                }
                $row->update(
                    [
                        'mf_trade_status' => 8, // 下单时盈米直接返回失败，导致订单不存在
                        'mf_error_code' => $tmp['code'],
                        'mf_error_msg' => $error_msg,
                    ]
                );
            }

            Log::error(sprintf("%s:yingmi rpc query share error[%s]", __CLASS__, $txn));
        }

        return $result;
    }


    public function updateYmFundOrderByYingmiOrderId($yingmi_order_id)
    {
        $result = [];

        $row = YingmiTradeStatus::where('yt_yingmi_order_id', $yingmi_order_id)->first();
        if (!$row) {
            return false;
        }
        $uid = $row->yt_uid;
        $ym_id =  YmHelper::getYmId($uid);
        $params = [
            'accountId'     => "$ym_id",
            'brokerUserId'  => "$uid",
            'orderId' => $yingmi_order_id,
        ];

        $tmp = YmHelper::rest_rpc("/trade/getFundOrder", $params, "get");

        if ($tmp['code'] == '20000' && is_array($tmp['result'])) {
            $this->updateOrderDatabase($uid, $tmp['result']);
            $result = ['code'=>20000, 'message'=>'success', 'result'=>$tmp];
        } else {
            $result = ['code'=>20001, 'message'=>'failed', 'result'=>$tmp];

            $valid_type = ['W04', 'W05'];
            if (isset($tmp['code']) && $tmp['code']== 1110 && in_array($order->yt_trade_type, $valid_type)) { //订单不存在
                if (isset($tmp['msg'])) {
                    $error_msg = $tmp['msg'];
                } else {
                    $error_msg = '';
                }
                $row->update(
                    [
                        'yt_trade_status' => 8, // 下单时盈米直接返回失败，导致订单不存在
                        'yt_error_code' => $tmp['code'],
                        'yt_error_msg' => $error_msg,
                    ]
                );
            }

            Log::error(sprintf("%s:yingmi rpc query share error[%s]", __CLASS__, $yingmi_order_id));
        }

        return $result;
    }


    public function updateYmWalletOrderByYingmiOrderId($yingmi_order_id)
    {
        $result = [];

        $row = YingmiTradeStatus::where('yt_yingmi_order_id', $yingmi_order_id)->first();
        if (!$row) {
            return false;
        }
        $uid = $row->yt_uid;
        $ym_id =  YmHelper::getYmId($uid);
        $params = [
            'accountId'     => "$ym_id",
            'brokerUserId'  => "$uid",
            'orderId' => $yingmi_order_id,
        ];

        $tmp = YmHelper::rest_rpc("/trade/getWalletOrder", $params, "get");
        if ($tmp['code'] == '20000' && is_array($tmp['result'])) {
            $this->updateOrderDatabase($uid, $tmp['result']);
            $result = ['code'=>20000, 'message'=>'success', 'result'=>$tmp];
        } else {
            $result = ['code'=>20001, 'message'=>'failed', 'result'=>$tmp];

            if (isset($tmp['code']) && $tmp['code']== 1110) { //订单不存在
                if (isset($tmp['msg'])) {
                    $error_msg = $tmp['msg'];
                } else {
                    $error_msg = '';
                }

                $row->update(
                    [
                        'yt_trade_status' => 8, // 下单时盈米直接返回失败，导致订单不存在
                        'yt_error_code' => $tmp['code'],
                        'yt_error_msg' => $error_msg,
                    ]
                );
            }

            Log::error(sprintf("%s:yingmi rpc query share error[%s]", __CLASS__, $yingmi_order_id));
        }

        return $result;
    }

    public function updateYmPoOrderAndSubFundOrder($txn)
    {
        $order = YingmiPortfolioTradeStatus::where('yp_txn_id', $txn)->first();
        if (!$order) {
            return false;
        }

        $uid = $order->yp_uid;
        $ymId = YmHelper::getYmId($uid);
        if (!$ymId) {
            Log::error('missing Yingmi account', ['uid' => $uid]);
            return false;
        }

        $params = [
            'brokerUserId' => $uid,
            'accountId' => $ymId,
            'brokerOrderNo' => $txn,
        ];
        $tmp = YmHelper::rest_rpc("/trade/getPoOrder", $params, "get");
        if($tmp['code'] == '20000' && is_array($tmp['result'])) {
            $this->updatePoOrderDatabaseTs($uid, $tmp['result'], $order);
        }else{
            if (isset($tmp['code']) && $tmp['code']== 1110) { //订单不存在
                if (isset($tmp['msg'])) {
                    $error_msg = $tmp['msg'];
                } else {
                    $error_msg = '';
                }

                $order->update(
                    [
                        'yp_trade_status' => 'P10', // 下单时盈米直接返回失败，导致订单不存在
                        'yp_error_code' => $tmp['code'],
                        'yp_error_msg' => $error_msg,
                    ]
                );
            }

            Log::warning('yingmi update portfolio order error:'.__CLASS__.' line:'.__LINE__);
        }
    }

    /**
     * 将盈米分批次文件中的文件创建时间戳转化为东八区的时间戳
     */
    public static function formatYingmiTimeStamps($timestamps)
    {
        $dt = date("Y-m-d H:i:s", strtotime($timestamps));

        return $dt;
    }

    public static function getTimingMsg($start, $end ,$count, $base='', $extra='')
    {
        $total = round(($end-$start)*1000, 2);
        if ($count > 0) {
            $avg = round($total / $count, 2);
        } else {
            $avg = 0;
        }

        $msg = "avg=$avg total=$total count=$count";
        $result = $base . ',' . $extra . ','.$msg;

        return $result;
    }

    public static function getYmAccountId($uid) {
        $ymid = YingmiAccount::where('ya_uid',$uid)->first();

        if($ymid){
            return $ymid->ya_account_id;
        }

        return false;
     }


    /**
     * 盈米宝充值封装
     * @param $txn 订单ID
     * @return array 成功是code=20000， 失败时code=2222x，其中code=22222时为盈米返回1129时的情况
     */
    public static function rechargeYmWallet($model)
    {
        $txn = $model->yt_txn_id;
        $timing = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        $timeout = env('YM_WALLET_TIMEOUT', 20);
        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        if ($model->yt_trade_type != 'W01') {
            return ['txn'=>$txn, 'status'=>-1]; // 魔方的错误
        }

        $wallet_row = self::makeSureWalletDetailExists($model->yt_uid, $model->yt_pay_method);
        if ($model->yt_uid == 1000000006 || $model->yt_uid == 1000000002) {
            if (\App::environment() === 'local' || \App::environment() === 'dev') {
                $model->yt_fund_code = '000509';
                $model->save();
                $params = [
                    'brokerUserId' => $model->yt_uid,
                    'accountId' => $model->yt_account_id,
                    'paymentMethodId' => $model->yt_pay_method,
                    'tradeAmount' => $model->yt_placed_amount,
                    'fundCode' => $model->yt_fund_code,
                    'brokerOrderNo' => $model->yt_txn_id,
                    'isIdempotent' => 1,
                    'payTimeoutSec' => $timeout, //用户充值盈米宝时返回1129，处理超时信息
                ];
            } else {
                $params = [
                    'brokerUserId' => $model->yt_uid,
                    'accountId' => $model->yt_account_id,
                    'paymentMethodId' => $model->yt_pay_method,
                    'tradeAmount' => $model->yt_placed_amount,
                    'fundCode' => $model->yt_fund_code,
                    'brokerOrderNo' => $model->yt_txn_id,
                    'isIdempotent' => 1,
                    'payTimeoutSec' => $timeout, //用户充值盈米宝时返回1129，处理超时信息
                ];
            }

        } else {
            $params = [
                'brokerUserId' => $model->yt_uid,
                'accountId' => $model->yt_account_id,
                'paymentMethodId' => $model->yt_pay_method,
                'tradeAmount' => $model->yt_placed_amount,
                'fundCode' => $model->yt_fund_code,
                'brokerOrderNo' => $model->yt_txn_id,
                'isIdempotent' => 1,
                'payTimeoutSec' => $timeout, //用户充值盈米宝时返回1129，处理超时信息
            ];
        }
        $tmp = YmHelper::rest_rpc('/trade/rechargeWallet', $params, 'post');

        if (!(is_array($tmp) && isset($tmp['code']) && $tmp['code'] == 20000 && isset($tmp['result']))) {
            if (isset($tmp['code']) && isset($tmp['msg'])) {
                if ($tmp['code'] == '1129') {// 1129对应稍后查询支付结果，目前暂时通知运营人员手工处理改情况
                    $model->yt_trade_status = 0; //状态位为0
                    $model->yt_pay_status = 0;
                    $model->yt_yingmi_order_id = $tmp['detail']['orderId'];
                    $model->yt_error_code = $tmp['code'];
                    $model->yt_error_msg = $tmp['msg'];

                    $uid = $model->yt_uid;
                    $txn = $model->yt_txn_id;
                    $logtag = "[YMBCZ:PYY:$uid] ";

                    $result = ['txn' => $txn, 'status' =>1129]; // 特殊的标志，对应盈米的1129
                } else {
                    $model->yt_trade_status = 0; //状态位为0
                    $model->yt_pay_status = 1;
                    $model->yt_error_code = $tmp['code'];
                    $model->yt_error_msg = $tmp['msg'];

                    // set sub w04 order yt_trade_status=-3
                    $tmp_po_txn = $model->yt_portfolio_txn_id;
                    $w04_updated = YingmiTradeStatus::where('yt_portfolio_txn_id', $tmp_po_txn)
                                 ->where('yt_uid', $model->yt_uid)
                                 ->where('yt_trade_type', 'W04')
                                 ->update(['yt_trade_status'=>-3]);
                    Log::info($base .'update W04 status=-3', ['updated'=>$w04_updated]);
                    Artisan::call("yingmi:update_one_wallet_order", ["--order_id"=>$model->yt_txn_id]);
                    $result = ['txn' => $txn, 'status' =>1];
                }
            } else {
                $model->yt_trade_status = 0; //状态位为0
                $model->yt_pay_status = 1;
                $model->yt_error_code = -1;
                $model->yt_error_msg = '系统错误';

                $result = ['txn' => $txn, 'status' =>1];

            }
        } elseif (array_key_exists('needVerifyCode', $tmp['result'])) {
            // 网联处理逻辑
            $needVerifyCode = $tmp['result']['needVerifyCode'];

            if ($needVerifyCode) {
                $model->yt_trade_status = 0;
                $model->yt_pay_status = 0;
                $model->yt_pay_verify_code = 1;
            } else {
                $model->yt_trade_status = 0;
                $model->yt_pay_status = 1;
                $model->yt_pay_verify_code = 1;
            }

            $model->yt_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_trade_date = $tmp['result']['orderTradeDate'];
            $model->yt_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->yt_yingmi_order_id = $tmp['result']['orderId'];

            $wallet_row->yw_wallet_id = $tmp['result']['walletId'];

            $result = ['txn'=>$txn, 'status'=>1130];

        } else {
            $model->yt_trade_status = 0;
            $model->yt_pay_status = 2;
            $model->yt_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_trade_date = $tmp['result']['orderTradeDate'];
            $model->yt_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->yt_yingmi_order_id = $tmp['result']['orderId'];

            $wallet_row->yw_wallet_id = $tmp['result']['walletId'];

            $result = ['txn'=>$txn, 'status'=>2];
        }


        $model->save();
        $wallet_row->save();

//        Log::info('11111111');
//        if ($model->yt_uid == 1000000006) {
//            Log::info('11111122');
//            if (\App::environment() === 'local' || \App::environment() === 'dev') {
//                Log::info('11111133');
//                $model->yt_trade_status = 0;
//                $model->yt_pay_status = 2;
//                $model->save();
//                $result = ['txn'=>$txn, 'status'=>2];
//            }
//        }

        return $result;
    }

    /**
     * 赎回盈米宝封装
     */
    public static function redeemYmWallet($model)
    {
        $txn = $model->yt_txn_id;
        $result =  ['txn'=>$txn, 'status'=>-1];

        if (!in_array($model->yt_trade_type, ['W02', 'W03'])) {
            return $result;
        }

        $uid = $model->yt_uid;
        $account_id = $model->yt_account_id;
        $wallet_id = $model->yt_wallet_id;
        $sum = $model->yt_placed_share;
        $wallet_order_id = $model->yt_txn_id;

        $redeem_mode = 0;
        if ($model->yt_trade_type == 'W02') {
            $redeem_mode = 1;
        }

        $params = [
            'brokerUserId' => $uid,
            'accountId' => $account_id,
            'walletId' => $wallet_id,
            'tradeShare' => $sum,
            'redeemMode' => $redeem_mode,
            'brokerOrderNo' => $wallet_order_id,
            'isIdempotent' => 1,
        ];
        $tmp = YmHelper::rest_rpc('/trade/redeemWallet', $params, 'post');

        if (!(is_array($tmp) && isset($tmp['code']) && $tmp['code'] == 20000 && isset($tmp['result']))) {
            if (isset($tmp['code']) && isset($tmp['msg'])) {
                $model->yt_trade_status = 1;
                $model->yt_pay_status = 1;
                $model->yt_error_code = $tmp['code'];
                $model->yt_error_msg = $tmp['msg'];
            } else {
                $model->yt_trade_status = 0;
                $model->yt_pay_status = 0;
                $model->yt_error_code = -1;
                $model->yt_error_msg = '系统错误';
            }

            $result = ['txn'=>$txn, 'status'=>7];
        } else {
            $model->yt_trade_status = 0;
            $model->yt_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_trade_date = $tmp['result']['orderTradeDate'];
            $model->yt_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->yt_pay_status = 0;
            $model->yt_yingmi_order_id = $tmp['result']['orderId'];

            if (isset($tmp['result']['transferIntoDate'])) {
                $model->yt_redeem_pay_date = $tmp['result']['transferIntoDate'];
            }

            $result = ['txn'=>$txn, 'status'=>0];
        }

        $model->save();

        return $result;
    }


    public static function makeSureWalletDetailExists($uid, $payment_method)
    {
        $locked_row = YingmiWalletShareDetail::where('yw_uid', $uid)
                    ->where('yw_pay_method', $payment_method)
                    ->first();
        if (!$locked_row) {
            $locked_row = new YingmiWalletShareDetail();
            $locked_row->yw_uid = $uid;
            $locked_row->yw_account_id = '';
            $locked_row->yw_wallet_id = '';
            $locked_row->yw_fund_id = '';
            $locked_row->yw_fund_code = '';
            $locked_row->yw_fund_name = '';
            $locked_row->yw_pay_method = $payment_method;
            $locked_row->yw_share_avail_total = 0;
            $locked_row->yw_withdraw_share_avail = 0;
            $locked_row->yw_output_share_avail = 0;
            $locked_row->yw_buying_share_avail = 0;
            $locked_row->save();
        }

        return $locked_row;
    }


    /**
     * 发送短信封装
     * @param $msg 要发送的消息
     * @param array 手机号组成的数组，为空时表示给运营人员和开发人员发短信
     * @return bool
     */
    public static function sendSms($msg, $mobiles = [])
    {
        if (!is_array($mobiles)) {
            return false;
        }

        if (empty($mobiles)) {
            $mobiles = [
                13811710773,
                18610562049,
            ];
        }
        $attrs = [
            'channel' => 3,
            'stype' => 5,

        ];
        try {
            $sms = SmsService::postMobileSms(13, $mobiles, $msg, $attrs);
            // Log::info('10000:mf_portfolio send msg ' . $msg . ' to', $mobiles);
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }

        return true;
    }

    /**
     * 获取 TsOrder 到 YingmiPortfolioTradeStatuses 的字段对应关系
     */
    public static function getTsOrderMap()
    {
        return [
            'ts_txn_id' => 'yp_txn_id',
            'ts_uid' => 'yp_uid',
            'ts_portfolio_id' => 'yp_portfolio_id',
            'ts_placed_date' => 'yp_placed_date',
            'ts_placed_time' => 'yp_placed_time',
            'ts_placed_amount' => 'yp_placed_amount',
            'ts_placed_percent' => 'yp_placed_percentage',
        ];
    }

    /**
     * 获取 TsOrderFund 到 YingmiTradeStatuses 的字段对应关系
     */
    public static function getTsFundOrderMap()
    {
        return [
            'ts_txn_id' => 'yt_txn_id',
            'ts_uid' => 'yt_uid',
            'ts_portfolio_id' => 'yt_portfolio_id',
            'ts_portfolio_txn_id' => 'yt_portfolio_txn_id',
            'ts_fund_code' => 'yt_fund_code',
            'ts_fund_name' => 'yt_fund_name',
            //'ts_trade_type' => 'yt_trade_type', // 需要转换
            //'ts_trade_status' => 'yt_trade_status', // 需要转换
            'ts_placed_date' => 'yt_placed_date',
            'ts_placed_time' => 'yt_placed_time',
            'ts_placed_amount' => 'yt_placed_amount',
            'ts_placed_share' => 'yt_placed_share',
            'ts_invest_plan_id' => 'yt_invest_plan_id',
            //'ts_trade_date' => 'yt_trade_date',
            //'ts_trade_nav' => 'yt_trade_nav',
            //'ts_acked_date' => 'yt_acked_date',
            //'ts_acked_amount' => 'yt_acked_amount',
            //'ts_acked_share' => 'yt_acked_share',
            //'ts_acked_fee' => 'yt_acked_fee',
            //'ts_redeem_pay_date' => 'yt_redeem_pay_date',
            //'ts_pay_method' => 'yt_pay_method', // 需要转换
            //'ts_pay_status' => 'yt_pay_status',
            //'ts_error_code' => 'yt_error_code',
            //'ts_error_msg' => 'yt_error_msg',
        ];
    }

    /**
     * 获取 TsOrderFund->YingmiTradeStatuses 交易类型的对应关系
     */
    public static function getTsOrderFundTradeTypeMap()
    {
        // ts_order_fund  交易类型
        // 10:银行卡充值买基金
        // 11:退款充值
        // 12:银行卡给钱包充值
        // 20:撤单提现
        // 21:快速提现
        // 22:普通赎回（提现）
        // 30:银行卡购买
        // 31:钱包购买
        // 40:赎回银行卡
        // 41:赎回到钱包
        // 43:强制赎回
        // 50:现金定投
        // 51:钱包定投
        // 61:调仓购买(老)
        // 62:调仓赎回(老)
        // 63:调仓购买
        // 64:调仓赎回
        // 70:分红到银行卡
        // 71:分红到钱包
        // 72:修改分红方式
        // 91:转出
        // 92:转入
        // 93:强增
        // 94:强减
        // 95:非交易过户转出
        // 96:非交易过户转入
        // 97:配平转出
        // 98:配平转入

        // yingmi_trade_status 交易类型
        // 022:申购
        // 020:认购
        // 024:赎回
        // 036:转换(转出)
        // 039:定投申购
        // 029:修改分红方式
        // 134:非交易过户转入确认
        // 135:非交易过户转出确认
        // 142:强制赎回
        // 144:强行调增
        // 145:强行调减
        // W01:盈米宝充值
        // W02:盈米宝快速提现
        // W03:盈米宝普通赎回
        // W04:盈米宝购买
        // W05:赎回到盈米宝
        // W06:转换到盈米宝
        // W07:盈米宝支付
        // W08:盈米宝退款
        // W09:分红自动充值
        // W10:退款自动充值
        // W11:盈米宝支付的定投申购
        // X36:转换(转入)
        // X29:分红

        return [
            '10' => 'W01',
            '11' => 'W08',
            '12' => 'W01',
            '20' => 'W03',
            '21' => 'W02',
            '22' => 'W03',
            '30' => '022',
            '31' => 'W04',
            '40' => '024',
            '41' => 'W05',
            '43' => '142',
            '50' => '039',
            '51' => 'W04',
            '61' => 'W04',
            '62' => 'W05',
            '63' => 'W04',
            '64' => 'W05',
            '70' => 'X29',
            '71' => 'W09',
            '72' => '029',
            '91' => '036',
            '92' => '036',
            '93' => '144',
            '94' => '145',
            '95' => '135',
            '96' => '136',
        ];
    }

    /**
     * 获取 TsOrderFund->YingmiTradeStatuses 交易状态的对应关系
     */
    public static function getTsOrderFundTradeStatusMap()
    {
        // ts_order_fund
        // 交易类型(-1:失败;0:已授理;1:已下单;3:部分确认成功;5:确认成功;9:已撤单; 7:to cancel)

        // yingmi_trade_status
        // 交易状态(0:未确认;1:确认失败;2:确认成功;3:部分确认成功;4:认购成功;9:已撤单; 7: to cancel)

        return [
            '-2' => '1',
            '0' => '8',
            '1' => '0',
            '5' => '3',
            '6' => '2',
            '7' => '7',
            '9' => '9',
        ];
    }

    /**
     * 获取 TsOrder->YingmiPortfolioTradeStatuses 交易类型的对应关系
     */
    public static function getTsOrderTradeTypeMap()
    {
        // ts_order  交易类型
        // 1:充值
        // 2:提现
        // 3:购买
        // 4:赎回
        // 5:定投
        // 6:调仓
        // 7:分红
        // 8:调仓使赎回盈米老组合到盈米宝
        // 9:其他

        // yingmi_trade_status 交易类型
        // P01:购买组合
        // P02:盈米宝购买组合
        // P03:赎回组合
        // P04:组合跟踪调仓

       return [
           '3' => 'P02',
           '4' => 'P03',
           '6' => 'P04',
           '8' => 'P03', // redeem_to_wallet=1
           //'5' => '' //5是定投，此处应该不用考虑
       ];
    }

    /**
     * 获取 TsOrder->YingmiPortfolioTradeStatuses 交易状态的对应关系
     */
    public static function getTsOrderTradeStatusMap()
    {
        // ts_order 交易状态
        // -1:失败;
        // 0:已授理;
        // 1:部分下单成功;
        // 2:全部下单成功;
        // 5:部分确认成功;
        // 6:全部确认成功过;
        // 9:已撤单;)
        // 7: to cancel

        // yingmi_portfolio_trade_status 组合交易状态
        // P0:未确认,
        // P1:确认处理中,
        // P2:确认成功,
        // P3:确认失败,
        // P4:部分成功,
        // P9:撤单,
        // P8:订单已经受理 魔方自定义
        // P99:购买失败-魔方自定义
        // P7: to cancel
        return [
            '-1' => 'P3',
            '0' => 'P8',
            '2' => 'P0',
            '5' => 'P4',
            '6' => 'P2',
            '7' => 'P7',
            '9' => 'P9',
        ];
    }


    /**
     * 根据TsPayMethod的globalid获取支付信息
     *
     */
    public static function getGatewayInfo($id)
    {
        $result = [null, null];

        $row = TsPayMethod::where('globalid', $id)
             ->first();

        if ($row) {
            $result = [$row->ts_gateway_id, $row->ts_gateway_pay_id];
        }

        return $result;
    }

    public static function log($msg, $mobiles=[])
    {
        Log::info($msg);

        self::sendSms($msg, $mobiles);
    }

    public static function getFundIdByCode($code)
    {
        $result = -1;

        $row = FundInfos::where('fi_code', $code)
             ->select('fi_globalid')
             ->first();
        if ($row) {
            $result = $row->fi_globalid;
        }

        return $result;
    }

    public static function getWalletIdByPayMethodId($pay_method)
    {
        $id = null;

        $row = YingmiWalletShareDetail::where('yw_pay_method', $pay_method)
             ->select('yw_wallet_id')
             ->first();

        if ($row) {
            $id = $row->yw_wallet_id;
        }

        return $id;
    }

    public static function getTerminalInfo($po_txn, $type)
    {
        $i = TsTerminalInfo::where('ts_txn_id', $po_txn)
           ->where('ts_trade_type', $type)
           ->orderBy('id', 'desc')
           ->first();

        if ($i) {
            return [$i->ts_ip, $i->ts_info, $i->ts_type];
        } else {
            return [null, null, null];
        }
    }

    public function buyWithYmWallet($model)
    {
        $success_txns = [];
        $txn = $model->yt_txn_id;
        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $result = ['txn'=>$txn, 'status'=>-1];

        if (!in_array($model->yt_trade_status, [7, 8])) {
            return $result;
        }

        $uid = $model->yt_uid;
        $paymethod = $model->yt_pay_method;

        Artisan::call("yingmi:update_wallet_share", ['uid'=>$uid]);

        $wallet = YingmiWalletShareDetail::where('yw_uid', $uid)
                ->where('yw_pay_method', $paymethod)
                ->first();
        if (!$wallet) {
            $msg = "$uid $paymethod $txn do not have wallet share detail row";
            //Log::info("$base $msg");
            static::sendSms($msg);
            return $result;
        }
        if ($wallet->yw_share_avail_total < $model->yt_placed_amount) {
            $msg = "$uid $paymethod $txn do not have enough money to buy:wallet=" . $wallet->yw_share_avail_total;
            Log::error("$base $msg");
            //static::sendSms($msg);

            return $result;
        }

        // 添加二次确认信息 start
        $a1 = false;
        $terminal_ip = null;
        $terminal_info = null;
        $terminal_type = null;

        $tag = "[YMHGX:PYY:$uid] $txn ";
        //$fake_code = '002295';
        $fake_code = $model->yt_fund_code;
        // Log::info($tag, [$fake_code]);
        $finfo = FundInfos::where('fi_code', $fake_code)->first();
        // Log::info($tag, [$finfo]);
        $frisk = $finfo->fi_amac_risk;
        // Log::info($tag, [$frisk]);
        $account = YingmiAccount::where('ya_uid', $uid)->first();
        if ($account) {
            $po_txn = $model->yt_portfolio_txn_id;
            $ts_order = TsOrder::where('ts_txn_id', $po_txn)
                      ->first();
            if ($ts_order) {
                $utype = $account->ya_origin + 0;
                $ts_trade_type = $ts_order->ts_trade_type + 0;
                $adequacy = $account->ya_adequacy + 0;
                $risk_grade_5 = $account->ya_risk_grade_5 + 0;
                Log::info($tag, ['frisk'=>$frisk, 'grade_5'=>$risk_grade_5, 'adequacy'=>$adequacy, 'user_type'=>$utype, 'trade_type'=>$ts_trade_type, 'po_tnx'=>$po_txn, 'txn'=>$txn]);
                if ($utype == 1) { // 老用户
                    if ($ts_trade_type == 3) { // 购买
                        if ($adequacy == 1) { // 录入合规性信息
                            if ($risk_grade_5>0 && $risk_grade_5<6) {
                                if ($frisk > $risk_grade_5) {
                                    $a1 = true;
                                    list($terminal_ip, $terminal_info, $terminal_type) = static::getTerminalInfo($po_txn, 1);
                                    Log::info($tag, ['po_tnx'=>$po_txn, 'txn'=>$txn,'frisk'=>$frisk, 'grade_5'=>$risk_grade_5, 'adequacy'=>$adequacy, 'user_type'=>$utype, 'trade_type'=>$ts_trade_type, 'a1'=>$a1, 'ip'=>$terminal_info, 'type'=>$terminal_type, 'info'=>$terminal_info]);
                                    if (is_null($terminal_ip) || is_null($terminal_info) || is_null($terminal_type)) { // 留痕信息有误
                                        $msg = $tag.'老用户，购买，合规性信息ok，风险留痕信息有误';
                                        //Log::info($msg);
                                        static::sendSms($msg);
                                    }
                                }
                            } else { // 不应该发生 报警
                                $msg = $tag.'老用户，购买，合规性信息ok，风险信息有误';
                                // Log::info($msg);
                                static::sendSms($msg);
                            }
                        } else if ($adequacy == 0) {  // 未录入合规性信息
                            $msg = $tag.'老用户，购买，合规性信息未录入';
                            // Log::info($msg);
                            static::sendSms($msg);
                        } else { // 其他情况，不应该发生
                            $msg = $tag.'老用户，购买，合规性信息有误';
                            // Log::info($msg);
                            static::sendSms($msg);
                        }
                    } else if ($ts_trade_type == 5) { // 定投
                        if ($adequacy == 1) { // 录入合规性信息
                            if ($risk_grade_5>0 && $risk_grade_5<6) {
                                if ($frisk > $risk_grade_5) {
                                    $a1 = true;
                                    list($terminal_ip, $terminal_info, $terminal_type) = static::getTerminalInfo($uid, 3);
                                    Log::info($tag, [
                                        'po_tnx'=>$po_txn,
                                        'txn'=>$txn,
                                        'frisk'=>$frisk,
                                        'grade_5'=>$risk_grade_5,
                                        'adequacy'=>$adequacy,
                                        'user_type'=>$utype,
                                        'trade_type'=>$ts_trade_type,
                                        'a1'=>$a1,
                                        'ip'=>$terminal_info,
                                        'type'=>$terminal_type,
                                        'info'=>$terminal_info
                                    ]
                                    );
                                    if (is_null($terminal_ip) || is_null($terminal_info) || is_null($terminal_type)) { // 留痕信息有误
                                        $msg = $tag.'老用户，定投，合规性信息ok，风险留痕信息有误';
                                        //Log::info($msg);
                                        static::sendSms($msg);
                                    }
                                }
                            } else { // 不应该发生 报警
                                $msg = $tag.'老用户，定投，合规性信息ok，风险信息有误';
                                // Log::info($msg);
                                static::sendSms($msg);
                            }
                        } else if ($adequacy == 0) {  // 未录入合规性信息
                            $msg = $tag.'老用户，定投，合规性信息为录入';
                            // Log::info($msg);
                            static::sendSms($msg);
                        } else { // 其他情况，不应该发生
                            $msg = $tag.'老用户，定投，合规性信息有误';
                            Log::info($msg);
                            static::sendSms($msg);
                        }

                        // 老用户定投直接放行
                    } else if ($ts_trade_type == 6) { // 调仓
                        if ($adequacy == 1) { // 录入合规性信息
                            if ($risk_grade_5>0 && $risk_grade_5<6) {
                                if ($frisk > $risk_grade_5) {
                                    $a1 = true;
                                    list($terminal_ip, $terminal_info, $terminal_type) = static::getTerminalInfo($po_txn, 2);
                                    Log::info($tag, ['po_tnx'=>$po_txn, 'txn'=>$txn,'frisk'=>$frisk, 'grade_5'=>$risk_grade_5, 'adequacy'=>$adequacy, 'user_type'=>$utype, 'trade_type'=>$ts_trade_type, 'a1'=>$a1, 'ip'=>$terminal_info, 'type'=>$terminal_type, 'info'=>$terminal_info]);
                                    if (is_null($terminal_ip) || is_null($terminal_info) || is_null($terminal_type)) { // 留痕信息有误
                                        $msg = $tag.'老用户，调仓，合规性信息ok，风险留痕信息有误';
                                        // Log::info($msg);
                                        static::sendSms($msg);
                                    }
                                }
                            } else { // 不应该发生 报警
                                $msg = $tag.'老用户，调仓，合规性信息ok，风险信息有误';
                                // Log::info($msg);
                                static::sendSms($msg);
                            }
                        } else if ($adequacy == 0) {  // 未录入合规性信息
                            $msg = $tag.'老用户，调仓，合规性信息未录入';
                            // Log::info($msg);
                            static::sendSms($msg);
                        } else { // 其他情况，不应该发生
                            $msg = $tag.'老用户，调仓，合规性信息有误';
                            // Log::info($msg);
                            static::sendSms($msg);
                        }
                    } else { // 其他情况 不用处理

                    }
                } else if ($utype == 3){ // 新用户
                    if ($ts_trade_type == 3) { // 购买
                        if ($adequacy == 1) { // 录入合规性信息
                            if ($risk_grade_5>0 && $risk_grade_5<6) {
                                if ($frisk > $risk_grade_5) {
                                    $a1 = true;
                                    list($terminal_ip, $terminal_info, $terminal_type) = static::getTerminalInfo($po_txn, 1);
                                    Log::info($tag, ['po_txn'=>$po_txn, 'txn'=>$txn,'frisk'=>$frisk, 'grade_5'=>$risk_grade_5, 'adequacy'=>$adequacy, 'user_type'=>$utype, 'trade_type'=>$ts_trade_type, 'a1'=>$a1, 'ip'=>$terminal_info, 'type'=>$terminal_type, 'info'=>$terminal_info]);
                                    if (is_null($terminal_ip) || is_null($terminal_info) || is_null($terminal_type)) { // 留痕信息有误
                                        $msg = $tag.'新用户，购买，合规性信息ok，风险留痕信息有误';
                                        // Log::info($msg);
                                        static::sendSms($msg);
                                    }
                                }
                            } else { // 不应该发生 报警
                                $msg = $tag.'新用户，购买，合规性信息ok，风险信息有误';
                                // Log::info($msg);
                                static::sendSms($msg);
                            }
                        } else if ($adequacy == 0) {  // 未录入合规性信息
                            $msg = $tag.'新用户，购买，未录入合规性信息';
                            // Log::info($msg);
                            static::sendSms($msg);
                        } else { // 其他情况，不应该发生
                            $msg = $tag.'新用户，购买，合规性信息有误';
                            // Log::info($msg);
                            static::sendSms($msg);
                        }
                    } else if ($ts_trade_type == 5) { // 定投
                        if ($adequacy == 1) { // 录入合规性信息
                            if ($risk_grade_5>0 && $risk_grade_5<6) {
                                if ($frisk > $risk_grade_5) {
                                    $a1 = true;
                                    list($terminal_ip, $terminal_info, $terminal_type) = static::getTerminalInfo($uid, 3);
                                    Log::info($tag, ['po_txn'=>$po_txn, 'txn'=>$txn,'frisk'=>$frisk, 'grade_5'=>$risk_grade_5, 'adequacy'=>$adequacy, 'user_type'=>$utype, 'trade_type'=>$ts_trade_type, 'a1'=>$a1, 'ip'=>$terminal_info, 'type'=>$terminal_type, 'info'=>$terminal_info]);
                                    if (is_null($terminal_ip) || is_null($terminal_info) || is_null($terminal_type)) { // 留痕信息有误
                                        $msg = $tag.'新用户，购买，合规性信息ok，风险留痕信息有误';
                                        // Log::info($msg);
                                        static::sendSms($msg);
                                    }
                                }
                            } else { // 不应该发生 报警
                                $msg = $tag.'新用户，购买，合规性信息ok，风险信息有误';
                                // Log::info($msg);
                                static::sendSms($msg);
                            }
                        } else if ($adequacy == 0) {  // 未录入合规性信息
                            $msg = $tag.'新用户，定投，未录入合规性信息';
                            // Log::info($msg);
                            static::sendSms($msg);
                        } else { // 其他情况，不应该发生
                            $msg = $tag.'新用户，定投，合规性信息有误';
                            // Log::info($msg);
                            static::sendSms($msg);
                        }
                    } else if ($ts_trade_type == 6) { // 调仓
                        if ($adequacy == 1) { // 录入合规性信息
                            if ($risk_grade_5>0 && $risk_grade_5<6) {
                                if ($frisk > $risk_grade_5) {
                                    $a1 = true;
                                    list($terminal_ip, $terminal_info, $terminal_type) = static::getTerminalInfo($po_txn, 2);
                                    Log::info($tag, ['po_txn'=>$po_txn, 'txn'=>$txn,'frisk'=>$frisk, 'grade_5'=>$risk_grade_5, 'adequacy'=>$adequacy, 'user_type'=>$utype, 'trade_type'=>$ts_trade_type, 'a1'=>$a1, 'ip'=>$terminal_info, 'type'=>$terminal_type, 'info'=>$terminal_info]);
                                    if (is_null($terminal_ip) || is_null($terminal_info) || is_null($terminal_type)) { // 留痕信息有误
                                        $msg = $tag.'新用户，调仓，合规性信息ok，风险留痕信息有误';
                                        // Log::info($msg);
                                        static::sendSms($msg);
                                    }
                                }
                            } else { // 不应该发生 报警
                                $msg = $tag.'新用户，调仓，合规性信息ok，风险信息有误';
                                // Log::info($msg);
                                static::sendSms($msg);
                            }
                        } else if ($adequacy == 0) {  // 未录入合规性信息
                            $msg = $tag.'新用户，调仓，未录入合规性信息';
                            // Log::info($msg);
                            static::sendSms($msg);
                        } else { // 其他情况，不应该发生
                            $msg = $tag.'新用户，调仓，合规性信息有误';
                            // Log::info($msg);
                            static::sendSms($msg);
                        }
                    } else { // 其他情况 不用处理

                    }
                } else { // 不应该发生
                    $msg = $tag.'用户既不是新用户也不是老用户';
                    // Log::info($msg);
                    static::sendSms($msg);
                }
            } else { // 没有父订单，不应该发生
                $msg = $tag.'用户没有父订单';
                // Log::info($msg);
                static::sendSms($msg);
            }
        } else { // 没有开户，不应该发生
            $msg = $tag.'用户没有开户';
            // Log::info($msg);
            static::sendSms($msg);
        }
        // 添加二次确认信息 end

        if ($model->yt_uid == 1000000006 || $model->yt_uid == 1000000002) {
            if (\App::environment() === 'local' || \App::environment() === 'dev') {
                $model->yt_fund_code = '270001';
                $model->save();
                $params = [
                    'brokerUserId' => $model->yt_uid,
                    'accountId' => $model->yt_account_id,
                    'brokerOrderNo' => $model->yt_txn_id,
                    'fundCode' => $model->yt_fund_code,
                    'tradeAmount' => $model->yt_placed_amount,
                    'walletId' => $wallet->yw_wallet_id,
                    'ignoreRiskGrade' => 1,
                    'isIdempotent' => 1,
                ];
            } else {
                $params = [
                    'brokerUserId' => $model->yt_uid,
                    'accountId' => $model->yt_account_id,
                    'brokerOrderNo' => $model->yt_txn_id,
                    'fundCode' => $model->yt_fund_code,
                    'tradeAmount' => $model->yt_placed_amount,
                    'walletId' => $wallet->yw_wallet_id,
                    'ignoreRiskGrade' => 1,
                    'isIdempotent' => 1,
                ];
            }
        } else {
            $params = [
                'brokerUserId' => $model->yt_uid,
                'accountId' => $model->yt_account_id,
                'brokerOrderNo' => $model->yt_txn_id,
                'fundCode' => $model->yt_fund_code,
                'tradeAmount' => $model->yt_placed_amount,
                'walletId' => $wallet->yw_wallet_id,
                'ignoreRiskGrade' => 1,
                'isIdempotent' => 1,
            ];
        }

        if ($a1) {
            $params['isRiskConfirmAgain'] = $a1;
            $params['terminalIP'] = $terminal_ip;
            $params['terminalInfo'] = $terminal_info;
            $params['terminalType'] = $terminal_type;
        }

        $tmp = YmHelper::rest_rpc("/trade/buyFund", $params, "post");
        if (isset($tmp['code']) && $tmp['code'] == '20000' && is_array($tmp['result'])) {
            $model->yt_trade_status = 0;
            $model->yt_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_trade_date = $tmp['result']['orderTradeDate'];
            $model->yt_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->yt_yingmi_order_id = $tmp['result']['orderId'];
            $model->save();

            $result = ['txn'=>$txn, 'status'=>0];
        } elseif ($tmp['code'] == -1) {
            $model->yt_trade_status = 0;
            $model->yt_error_code = -1;
            $model->yt_error_msg = '系统错误';
            $model->save();

            $result = ['txn'=>$txn, 'status'=>7];

            Artisan::call("yingmi:update_order", ['--order'=>$model->yt_txn_id]);
        } else {
            $model->yt_trade_status = -2;
            $model->yt_error_code = $tmp['code'];
            $model->yt_error_msg = $tmp['msg'];
            $model->save();

            $result = ['txn'=>$txn, 'status'=>8];
        }

        Artisan::call("yingmi:update_wallet_share", ['uid'=>$uid]);

//        Log::info('22222222');
//        if ($model->yt_uid == 1000000006) {
//            Log::info('22222223');
//            if (\App::environment() === 'local' || \App::environment() === 'dev') {
//                Log::info('22222223');
//                $model->yt_error_code = -1;
//                $model->save();
//                $result = ['txn'=>$txn, 'status'=>7];
//            }
//        }

        return $result;
    }

    public static function redeemYmFund($model, $redeem_to_wallet='1')
    {
        $txn = $model->yt_txn_id;
        $result = ['txn'=>$txn, 'status'=>-1];

        $uid = $model->yt_uid;
        $order_id = $model->yt_txn_id;
        $account_id = $model->yt_account_id;
        $share = $model->yt_placed_share;
        if ($redeem_to_wallet != '0') {//0:to card 1:to wallet
            $redeem_to_wallet = '1';
        }

        $params = [
            'brokerUserId' => "$uid",
            'accountId' => $account_id,
            'shareId' => $model->yt_share_id,
            'brokerOrderNo' => $order_id,
            'tradeShare' => number_format($share, 2, '.', ''),
            'redeemToWallet' => $redeem_to_wallet,//0:赎回到银行卡,1:赎回到盈米宝
            'isIdempotent' => '1'
        ];

        if ($redeem_to_wallet == '1') {//赎回到盈米宝，需要提供额外的参数
            $wallet_fund_row = self::getYmWalletFund();
            $params['walletFundCode'] = $wallet_fund_row->yw_fund_code;
        }

        $tmp = YmHelper::rest_rpc("/trade/redeemFund", $params, "post");
        if ($tmp['code'] == 20000 && is_array($tmp['result'])) {
            $model->yt_trade_status = 0; //trade_status 全部为0，是为了方便张哲的同步脚本，检查该订单的状态
            $model->yt_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_trade_date = $tmp['result']['orderTradeDate'];
            $model->yt_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->yt_redeem_pay_date = $tmp['result']['transferIntoDate'];
            $model->yt_yingmi_order_id = $tmp['result']['orderId'];
            $model->yt_redeem_to_wallet = $params['redeemToWallet'];

            $result = ['txn' => $txn, 'status' => 0];
        } elseif ($tmp['code'] == -1) {
            $model->yt_trade_status = 0;
            $model->yt_placed_share = $params['tradeShare'];
            $model->yt_redeem_to_wallet = $params['redeemToWallet'];
            $model->yt_error_code = -1;
            $model->yt_error_msg = '系统错误';

            $result = ['txn' => $txn, 'status' => 7];
        } else {
            $model->yt_trade_status = 1;
            $model->yt_error_code = $tmp['code'];
            $model->yt_error_msg = $tmp['msg'];
            $model->yt_redeem_to_wallet = $params['redeemToWallet'];

            // try {
            //     $mobiles = self::getEmergencyMobiles();
            //     $tmp_msg = "遇到赎回失败，请及时联系张哲进行处理:" . implode(',', [
            //             $tmp['code'],
            //             $tmp['msg'],
            //             $model->uid,
            //             $order_id,
            //             $fund_id,
            //             $fund['fi_code'],
            //             $fund['fi_name']
            //         ]);
            //     self::sendSms($tmp_msg, $mobiles);
            // } catch (\Exception $e) {
            //     Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            // }
            $result = ['txn' => $txn, 'status' => 8];
        }

        $model->save();

        return $result;
    }

    public static function getOrderId()
    {
        return Uuid::generate()->string;
    }

    public static function getYmPoValidStatus()
    {
        return [0, 7];
    }

    public static function getMfPoValidStatus()
    {
        return [0, 1, 7];
    }

    public static function getMfFundValidStatus()
    {
        return [0, 7];
    }



    /**
     * TsOrder中，如果用户同时持有盈米老组合和千人千面组合，或者有多张银行卡，那么用户赎回和调仓时
     * 一个ts_txn_id只对应千人千面的一个组合操作订单
     * 但是一个ts_txn_id可以对应多个盈米老组合的操作订单,
     * 拷贝的过程中，第一个子订单的订单号为ts_txn_id, 后面的依次加1
     * @param $id primary key (id) of ts_order
     * @return array ['gw'=>gwateay_id, 'txn'=>"yp_txn_id of yingmi_portfolio_trade_statuses"]
     */
    public function copyOneTsOrderYmPoRow($id)
    {
        $result = ['gw' => -1, 'txn' => $id];

        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $xtab = static::getTsOrderMap();
        $type_map = static::getTsOrderTradeTypeMap();
        $status_map = static::getTsOrderTradeStatusMap();
        $ym_po_valid_status = static::getYmPoValidStatus();

        $order = TsOrder::where('id', $id)
                ->first();
        if (!$order) {
            return $result;
        }

        $uid = $order['ts_uid'];
        $pay_method = $order->ts_pay_method;
        $trade_status = $order->ts_trade_status;
        $po_id = $order->ts_portfolio_id;
        $txn_id = $order->ts_txn_id;
        $final_order_id = "$txn_id:$pay_method:$po_id";
        $account_id = static::getYmAccountId($uid);

        list($gw, $gw_pay) = static::getGatewayInfo($pay_method);

        if (!in_array($trade_status, $ym_po_valid_status)) { // 只有刚下单的订单才可以拷贝再执行
            static::log($base . "$id $trade_status not valid");
            return $result;
        }

        if (is_null($gw)) {
            static::log($base . "$id $uid $pay_method do not have gateway info");
            // return $result;
        }

        //'ts_txn_id' => 'yp_txn_id',
        //'ts_uid' => 'yp_uid',
        //'ts_portfolio_id' => 'yp_portfolio_id',
        //'ts_placed_date' => 'yp_placed_date',
        //'ts_placed_time' => 'yp_placed_time',
        //'ts_placed_amount' => 'yp_placed_amount',
        //'ts_placed_percent' => 'yp_placed_percentage',

        $new = [];
        foreach ($xtab as $k=>$v) {
            $new[$v] = $order[$k];
        }
        $new['yp_txn_id'] = $final_order_id;
        $new['yp_account_id'] = $account_id;
        $new['yp_mf_txn_id'] = $txn_id; // ugly logic
        $new['yp_trade_type'] = $type_map[$order['ts_trade_type']];
        $new['yp_trade_status'] = $status_map[$order['ts_trade_status']];
        $new['yp_pay_method'] = $gw_pay;
        $new['yp_pay_wallet'] = static::getWalletIdByPayMethodId($gw_pay);
        $new['yp_ts_txn_id'] = $txn_id; //ugly

        if ($order['ts_trade_type'] == 8) { // 表示调仓时，赎回到盈米宝
            $new['yp_redeem_to_wallet'] = 1;
        } else {
            $new['yp_redeem_to_wallet'] = 0;
        }

        $news = [$new];
        $olds = YingmiPortfolioTradeStatus::where('yp_uid', $uid)
              ->where('yp_txn_id', $final_order_id)
              ->get();
        list($inserted, $updated, $deleted) = model_array_cud(
            $olds->all(), $news, function ($a, $b) {
                return strcmp($a['yp_txn_id'], $b['yp_txn_id']);
            }
        );
        //dd($news, $olds);
        $this->batch('\App\YingmiPortfolioTradeStatus', $inserted, $updated, $deleted, true);

        $result = ['gw' => 1, 'txn' => $final_order_id];

        return $result;
    }

    public function copyOneTsOrderFundRow($txn_id)
    {
        $result = ['gw'=>-1,'txn' => 'no ts_order_fund_row', 'yts' => null];

        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $xtab = static::getTsFundOrderMap();
        $type_map = static::getTsOrderFundTradeTypeMap();
        $status_map = static::getTsOrderFundTradeStatusMap();
        $valid_status = self::getMfFundValidStatus();
        $order = TsOrderFund::where('ts_txn_id', $txn_id)->first();
        if (!$order) {
            return $result;
        }
        $uid = $order['ts_uid'];
        $pay_method = $order->ts_pay_method;
        $trade_status = $order->ts_trade_status;
        list($gw, $gw_pay) = static::getGatewayInfo($pay_method);

        if (!in_array($trade_status, $valid_status)) { // 只有刚下单的订单才可以拷贝再执行
            static::log($base . "$pay_method trade status not [0, 7]");
            return $result;
        }
        if (is_null($gw)) {
            static::log($base . "$uid $pay_method do not have gateway info");
            //return $result;
        }

        switch ($gw) {
        case 1 : // ying mi
            $new = [];
            foreach ($xtab as $k=>$v) {
                $new[$v] = $order[$k];
            }

            $new['yt_trade_type'] = $type_map[$order['ts_trade_type']];
            $new['yt_trade_status'] = $status_map[$order['ts_trade_status']];
            $new['yt_pay_method'] = $gw_pay;
            $new['yt_wallet_id'] = static::getWalletIdByPayMethodId($gw_pay);
            $new['yt_fund_id'] = static::getFundIdByCode($order['ts_fund_code']);
            $new['yt_account_id'] = static::getYmAccountId($uid);
            $new['yt_ts_txn_id'] = $txn_id;
            $new['yt_placed_share'] = number_format($order['ts_placed_share'], 2, '.', '');

            // 如果是赎回基金的订单，需要存储share id
            if (in_array($new['yt_trade_type'], ['W05', '024'])) {
                $new['yt_share_id'] = static::getFundShareIdByCodeAndPay($order['ts_fund_code'], $gw_pay);
            }

            $row = YingmiTradeStatus::where('yt_uid', $uid)
                  ->where('yt_txn_id', $txn_id)
                  ->first();
            if ($row) {
                //
                // 更新
                //
                $new['yt_trade_status'] = $row->yt_trade_status;

                $row->fill($new);

                if ($row->isDirty()) {
                    DirtyDumper::xlogDirty($row, $base.'yingmi_trade_status update', [
                        'yt_uid' => $row['yt_uid'],
                        'yt_txn_id' => $row['yt_txn_id'],
                    ]);
                    $row->save();
                }
            } else {
                //
                // 新建
                //
                $row = new YingmiTradeStatus($new);
                Log::info($base.'yingmi_trade_status insert', [
                    'yt_uid' => $row['yt_uid'],
                    'yt_txn_id' => $row['yt_txn_id'],
                ]);
                $row->save();
            }

            $result = ['gw' => 1, 'txn' => $txn_id, 'yts' => $row];
            break;
        default:
            static::log($base . "$pay_method unknown gateway");
            $result = ['gw'=>-1, 'txn' => 'no gateway', 'yts' => null ];
            break;
        }

        return $result;
    }


    /**
     * 如果是盈米宝宝的充值订单，遇到1129的情况，将任务重新放回队列，并延迟一定的时间执行
     */
    public function executeYmOrders($txns)
    {
        $result = [];

        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';
        foreach ($txns as $k=>$txn) {
            $order = YingmiTradeStatus::where('yt_txn_id', $txn)->first();
            if (!$order) {
                continue;
            }

            $tmp = $this->executeOneYmOrder($order);
            Log::info($base, [$txn=>$tmp]);
            if ($tmp['status'] == 1129) {
                // @todo 只可能在第一笔订单是W01的时候，才会有1129发生
                //处理1129 整个订单延迟执行, 在新的job里面完成这个操作，简化这个job的逻辑
                // 存储已经执行o的订单状态
                // 发起一次订单查询请求
                // 如果返回成功 继续往下执行
                // 如果返回失败 终止后续订单执行
                // 如果返回未确认状态 将listener放入队列 延迟5分钟执行
                Log::info($base . " $txn 1129 returned");
                if ($k == 0) {
                    // fire new job to deal 1129
                    $job = (new TsDeal1129($order->yt_portfolio_txn_id, 5))->onQueue('queue1129');
                    $this->dispatch($job);

                    return [
                        ['status'=>1129, 'txn'=>$txn],
                    ];
                } else {
                    static::log($base . "$txn 1129 not first order");
                }
            }

            $result[] = $tmp;
        }

        return $result;
    }

    public static function executeOneYmOrder($order)
    {
        $result = ['txn'=>$order->yt_txn_id, 'status' => -1];

        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $status = $order->yt_trade_status;
        $type = $order->yt_trade_type;

        switch ($type) {
        case 'W01': // 盈米宝充值
            $result = static::rechargeYmWallet($order);
            break;
        case 'W02': // 盈米宝快速提现
            break;
        case 'W03': // 盈米宝普通提现
            $result = static::redeemYmWallet($order);
            break;
        case 'W04': // 盈米宝购买基金 这种订单可以撤销
            if ($status == 7) {
                $result = static::cancelYmFundOrder($order);
            } else {
                $result = static::buyWithYmWallet($order);
            }
            break;
        case 'W05': // 赎回基金到盈米宝
            if ($status == 7) {
                $result = static::cancelYmFundOrder($order);
            } else {
                $result = static::redeemYmFund($order, '1');
            }
            break;
        case '024': // 赎回基金到银行卡 这种订单可以撤销
            if ($status == 7) {
                $result = static::cancelYmFundOrder($order);
            } else {
                $result = static::redeemYmFund($order, '0');
            }
            break;
        case '029': // 设置分红方式
            $result = static::setFundDividend();
            break;
        default:
            break;
        }

        return $result;
    }

    public static function cancelYmFundOrder($order)
    {
        $txn_id = $order->yt_txn_id;
        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        //
        // 尚未向盈米下单，则直接标记撤单成功
        //
        if (in_array($order->yt_trade_status, [8])) {
            $order->yt_trade_status = 9;
            $order->yt_canceled_at = date('Y-m-d H:i:s');
            $order->save();

            return ['txn' => $txn_id, 'status' => 0];
        }

        //
        // 需要向盈米撤单
        //
        $account_id = static::getYmAccountId($order->yt_uid);
        if (!$account_id) {
            return ['status'=>-1, 'txn'=>$txn_id];
        }
        $params = [
            'brokerUserId' => $order->yt_uid,
            'accountId' => $account_id,
            'brokerOrderNo' => $order->yt_txn_id,
            'isIdempotent' => 1,
        ];

        $tmp = YmHelper::rest_rpc("/trade/cancelFundOrder", $params, "post");
        if ($tmp['code'] == 20000 && is_array($tmp['result'])) {
            $order->yt_trade_status = 9;
            $order->yt_canceled_at = $tmp['result']['orderCancelledOn'];

            $result = ['txn' => $txn_id, 'status' => 0];
        } elseif ($tmp['code'] == -1) {
            $order->yt_trade_status = 0;
            static::sendSms($base . ' cancel fund order failed ' . $txn_id);

            $result = ['txn' => $txn_id, 'status' => 1];
        } else {
            $order->yt_trade_status = -2;
            static::sendSms($base . ' cancel fund order failed ' . $txn_id);

            $result = ['txn' => $txn_id, 'status' => 1];
        }

        $order->save();

        return $result;
    }

    public static function cancelYmPoOrder($txn)
    {
        $result = ['txn' => $txn, 'status' => 1];

        $row = YingmiPortfolioTradeStatus::where('yp_txn_id', $txn)->first();
        if(!$row){
            return $result;
        }

        $params = [
            'accountId' => "$row->yp_account_id",
            'brokerUserId' => "$row->yp_uid",
            'orderId' => "$row->yp_yingmi_order_id",
        ];

        $tmp = YmHelper::rest_rpc("/trade/cancelPoOrder", $params, "post");
        if(isset($tmp['code']) && $tmp['code']==20000){
            $row->yp_trade_status = 'P9';
            $result = ['txn' => $txn, 'status' => 0];
        }else{
            $result = ['txn' => $txn, 'status' => 1];
        }

        $row->save();

        Artisan::call("yingmi:update_po_order",['uid'=>$row->yp_uid]);

        return $result;
    }

    /**
     * @param $txns array yingmi_portfolio_trade_statuses中的yp_txn_id
     */
    public function executeYmPoOrders($txns)
    {
        $result = [];

        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';
        foreach ($txns as $k=>$txn) {
            $tmp = $this->executeOneYmPoOrder($txn);

            $result[] = $tmp;
        }

        return $result;
    }

    public static function executeOneYmPoOrder($txn_id)
    {
        $result = ['txn'=>$txn_id, 'status' => -1];

        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $order = YingmiPortfolioTradeStatus::where('yp_txn_id', $txn_id)->first();

        if (!$order) {
            return $result;
        }

        $status = $order->yp_trade_status;
        $type = $order->yp_trade_type;

        switch ($type) {
        case 'P01':
            break;
        case 'P02':
            break;
        case 'P03':
            if ($order->yp_trade_status == 'P7'){
                $result = static::cancelYmPoOrder($txn_id);
            } else {
                $result = static::redeemYmPo($txn_id);
            }
            break;
        case 'P04':
            if ($order->yp_trade_status == 'P7'){
                $result = static::cancelYmPoOrder($txn_id);
            } else {
                $result = static::adjustYmPo($txn_id);
            }
            break;
        default:
            break;
        }

        return $result;
    }

    public static function adjustYmPo($txn_id)
    {
        $result = ['txn'=>$txn_id, 'status' => -1];
        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $model = YingmiPortfolioTradeStatus::where('yp_ts_txn_id', $txn_id)
               ->first();
        $final_order_id = $txn_id;
        $wallet_id = $model->yp_pay_wallet;
        $payment_method = $model->yp_pay_method;

        $id = $model->yp_portfolio_id;
        $info = YingmiPortfolioInfo::where('id', $id)->first();

        $params = [
            'accountId' => $account_id,
            'brokerUserId' => $uid,
            'poCode' => $id,
            'paymentMethodId' => $payment_method,
            'brokerOrderNo' => $final_order_id,
        ];
        $tmp_result = YmHelper::rest_rpc('/trade/adjustPoShare', $params, 'POST');

        if (!(isset($tmp_result['code']) && $tmp_result['code'] == 20000)) {
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            $model->yp_placed_date = date('Y-m-d');
            $model->yp_placed_time = date('H:i:s');
            $model->yp_trade_date = date('Y-m-d');
            if (isset($tmp_result['code']) && isset($tmp_result['msg'])) {
                $model->yp_error_code = $tmp_result['code'];
                $model->yp_error_msg = $tmp_result['msg'];
                $model->yp_trade_status = 'P1';
            } else {
                $model->yp_error_code = -1;
                $model->yp_error_msg = '系统错误';
                $model->yp_trade_status = 'P0';
            }

            $result = ['txn'=>$txn_id, 'status' => -1];
        } else {
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            $model->yp_trade_status = 'P0';
            $model->yp_placed_date = date('Y-m-d', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_placed_time = date('H:i:s', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_trade_date = $tmp_result['result']['orderTradeDate'];
            $model->yp_yingmi_order_id = $tmp_result['result']['orderId'];

            $result = ['txn'=>$txn_id, 'status' => 0];
        }

        // udpate yingmi_trade_statuses中的基金子订单信息
        Artisan::call("yingmi:update_po_order", ['--order'=>$txn_id]);

        $model->save();

        return $result;
    }

    /**
     *@to 1:to bank card 2:to ying mi bao for adjust
     */
    public static function redeemYmPo($txn_id)
    {
        $result = ['txn'=>$txn_id, 'status' => -1];
        $base = basename_class(__CLASS__) . '@' . __FUNCTION__. ' ';

        $model = YingmiPortfolioTradeStatus::where('yp_txn_id', $txn_id)->first();
        if (!$model) {
            return $result;
        }

        $uid = $model->yp_uid;
        $portfolio_id = $model->yp_portfolio_id;
        $percent = $model->yp_placed_percentage;
        $account_id = $model->yp_account_id;
        $payment_method = $model->yp_pay_method;
        $to = $model->yp_redeem_to_wallet;

        $order_id = $txn_id;
        $info = YingmiPortfolioInfo::where("id", $portfolio_id)->first();
        if(!$info){
            return $result;
        }

        $params = [
            'accountId' => $account_id,
            'brokerUserId' => $uid,
            'poCode' => $portfolio_id,
            'paymentMethodId' => $payment_method,
            'redeemRatio' => $percent,
            'brokerOrderNo' => $order_id,
        ];
        if($to != 0){
            $params['redeemToWallet'] = 1;
        }

        $tmp_result = YmHelper::rest_rpc('/trade/redeemPo', $params, 'POST');
        if (!(isset($tmp_result['code']) && $tmp_result['code'] == 20000)) {
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            if (isset($tmp_result['code']) && isset($tmp_result['msg'])) {
                $model->yp_error_code = $tmp_result['code'];
                $model->yp_error_msg = $tmp_result['msg'];
                $model->yp_trade_status = 'P3';
            } else {
                $model->yp_error_code = -1;
                $model->yp_error_msg = '系统错误';
                $model->yp_trade_status = 'P0';
                static::log($base. "$txn redeem ym po to wallet -1 occured");
            }

            $result = ['txn'=>$txn_id, 'status' => 1];
        } else {
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            $model->yp_trade_status = 'P0';
            $model->yp_placed_date = date('Y-m-d', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_placed_time = date('H:i:s', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_trade_date = $tmp_result['result']['orderTradeDate'];
            $model->yp_acked_date = $tmp_result['result']['orderExpectedConfirmDate'];
            $model->yp_redeem_pay_date = $tmp_result['result']['transferIntoDate'];
            $model->yp_yingmi_order_id = $tmp_result['result']['orderId'];

            $result = ['txn'=>$txn_id, 'status' => 0];

        }

        // udpate yingmi_trade_statuses中的基金子订单信息
        Artisan::call("yingmi:update_po_order", ['--order'=>$txn_id]);

        $model->save();

        return $result;
    }

    public static function getYmWalletFund()
    {
        return YingmiWalletFund::first();
    }

    public static function genMockBuyData($uid, $amount)
    {
        $wf = self::getYmWalletFund();
        $pay = TsPayMethod::where('ts_uid', $uid)
             ->where('ts_bind', 1)
             ->first();

        $r_txn = TsTxnId::make(date("iY-m-d H:i:s"), 1, 1);
        $b_txn = TsTxnId::make(date("Y-m-d H:i:s"), 3, 1);
        $b_txn1 = TsTxnId::make(date("Y-m-d H:i:s"), 3, 1);
        //$p_txn = TsTxnId::make(date("Y-m-d H:i:s"), 1, 0);

        $p_txn = self::genTsOrder($uid,3, $amount);

        $r = [
            'ts_txn_id' => $r_txn,
            'ts_uid' => $uid,
            'ts_portfolio_id' => 1,
            'ts_portfolio_txn_id' => $p_txn,
            'ts_fund_code' => $wf->yw_fund_code,
            'ts_fund_name' => $wf->yw_fund_name,
            'ts_trade_type' => 10,
            'ts_trade_status' => 0,
            //'ts_placed_date' => date("Y-m-d"),
            //'ts_placed_time' => date("H:i:s")
            'ts_placed_amount' => $amount,
            // 'ts_placed_share' => 'yt_placed_share',
            // 'ts_trade_date' => 'yt_trade_date',
            // 'ts_trade_nav' => 'yt_trade_nav',
            // 'ts_acked_date' => 'yt_acked_date',
            // 'ts_acked_amount' => 'yt_acked_amount',
            // 'ts_acked_share' => 'yt_acked_share',
            // 'ts_acked_fee' => 'yt_acked_fee',
            // 'ts_redeem_pay_date' => 'yt_redeem_pay_date',
            'ts_pay_method' => $pay->globalid,
            // 'ts_pay_status' => 'yt_pay_status',
            // 'ts_error_code' => 'yt_error_code',
            // 'ts_error_msg' => 'yt_error_msg',
        ];

        $m = new TsOrderFund();
        $m->fill($r);
        $m->save();

        $b  = [
            'ts_txn_id' => $b_txn,
            'ts_uid' => $uid,
            'ts_portfolio_id' => 1,
            'ts_portfolio_txn_id' => $p_txn,
            'ts_fund_code' => '270004',
            'ts_fund_name' => '广发货币',
            'ts_trade_type' => 31,
            'ts_trade_status' => 0,
            //'ts_placed_date' => date("Y-m-d"),
            //'ts_placed_time' => date("H:i:s")
            'ts_placed_amount' => round($amount/3, 2),
            // 'ts_placed_share' => 'yt_placed_share',
            // 'ts_trade_date' => 'yt_trade_date',
            // 'ts_trade_nav' => 'yt_trade_nav',
            // 'ts_acked_date' => 'yt_acked_date',
            // 'ts_acked_amount' => 'yt_acked_amount',
            // 'ts_acked_share' => 'yt_acked_share',
            // 'ts_acked_fee' => 'yt_acked_fee',
            // 'ts_redeem_pay_date' => 'yt_redeem_pay_date',
            'ts_pay_method' => $pay->globalid,
            // 'ts_pay_status' => 'yt_pay_status',
            // 'ts_error_code' => 'yt_error_code',
            // 'ts_error_msg' => 'yt_error_msg',
        ];
        $m = new TsOrderFund();
        $m->fill($b);
        $m->save();


        $b  = [
            'ts_txn_id' => $b_txn1,
            'ts_uid' => $uid,
            'ts_portfolio_id' => 1,
            'ts_portfolio_txn_id' => $p_txn,
            'ts_fund_code' => '270050',
            'ts_fund_name' => '广发新经济混合',
            'ts_trade_type' => 31,
            'ts_trade_status' => 0,
            //'ts_placed_date' => date("Y-m-d"),
            //'ts_placed_time' => date("H:i:s")
            'ts_placed_amount' => round($amount/3, 2),
            // 'ts_placed_share' => 'yt_placed_share',
            // 'ts_trade_date' => 'yt_trade_date',
            // 'ts_trade_nav' => 'yt_trade_nav',
            // 'ts_acked_date' => 'yt_acked_date',
            // 'ts_acked_amount' => 'yt_acked_amount',
            // 'ts_acked_share' => 'yt_acked_share',
            // 'ts_acked_fee' => 'yt_acked_fee',
            // 'ts_redeem_pay_date' => 'yt_redeem_pay_date',
            'ts_pay_method' => $pay->globalid,
            // 'ts_pay_status' => 'yt_pay_status',
            // 'ts_error_code' => 'yt_error_code',
            // 'ts_error_msg' => 'yt_error_msg',
        ];

        $m = new TsOrderFund();
        $m->fill($b);
        $m->save();


        $x = self::genW03Order($uid, floor($amount/10), $p_txn); // 赎回盈米宝

        $xx = self::genW05Order($uid, '270050', 1, $p_txn, '1');  // 赎回基金到盈米宝
        $xxx = self::genW05Order($uid, '270004', 1, $p_txn, '0'); // 赎回基金到银行卡

        return $p_txn;
    }

    public static function genW03Order($uid, $amount, $p_txn)
    {
        $wf = self::getYmWalletFund();
        $pay = TsPayMethod::where('ts_uid', $uid)
             ->where('ts_bind', 1)
             ->first();

        $r_txn = TsTxnId::make(date("Y-m-d H:i:s"), 2, 1);

        $r = [
            'ts_txn_id' => $r_txn,
            'ts_uid' => $uid,
            'ts_portfolio_id' => 1,
            'ts_portfolio_txn_id' => $p_txn,
            'ts_fund_code' => $wf->yw_fund_code,
            'ts_fund_name' => $wf->yw_fund_name,
            'ts_trade_type' => 20,
            'ts_trade_status' => 0,
            'ts_placed_share' => $amount,
            'ts_pay_method' => $pay->globalid,
        ];

        $m = new TsOrderFund();
        $m->fill($r);
        $m->save();

        return $r_txn;
    }


    public static function genW05Order($uid, $code, $amount, $p_txn, $type)
    {
        $fund = FundInfos::where('fi_code', $code)->first();
        $pay = TsPayMethod::where('ts_uid', $uid)
             ->where('ts_bind', 1)
             ->first();

        $r_txn = TsTxnId::make(date("Y-m-d H:i:s"), 4, 1);
        if ($type == '1') {
            $trade_type = '41';
        } else {
            $trade_type = '40';
        }

        $r = [
            'ts_txn_id' => $r_txn,
            'ts_uid' => $uid,
            'ts_portfolio_id' => 1,
            'ts_portfolio_txn_id' => $p_txn,
            'ts_fund_code' => $code,
            'ts_fund_name' => $fund->fi_name,
            'ts_trade_type' => $trade_type,
            'ts_trade_status' => 0,
            'ts_placed_share' => $amount,
            'ts_pay_method' => $pay->globalid,
        ];

        $m = new TsOrderFund();
        $m->fill($r);
        $m->save();

        return $r_txn;
    }

    /**
     * @param $type 3-购买 4-赎回 5-定投 6-调仓
     */
    public static function genTsOrder($uid, $type, $amount)
    {
        $order = new TsOrder();
        $pay = TsPayMethod::where('ts_uid', $uid)
             ->where('ts_bind', 1)
             ->first();

        $txn = TsTxnId::make(date("Y-m-d H:i:s"), 4, 0);

        $data = [
            'ts_txn_id' => $txn,
            'ts_uid' => $uid,
            'ts_portfolio_id' => 1,
            'ts_trade_type' => $type,
            'ts_trade_status' => 0,
            'ts_pay_method' => $pay->globalid,
        ];

        if ($type == 3) {
            $data['ts_placed_amount'] = $amount;
        } else if ($type == 4) {
            $data['ts_placed_share'] = $amount;
        } else {

        }

        $order->fill($data);
        $order->save();

        return $txn;
    }

    public static function genTsYmBuyParam($uid, $amount)
    {
        $account_id = TsHelper::getYmAccountId($uid);
        $paymethod = YingmiPaymentMethod::where('yp_uid', $uid)->first();
        $wallet_id = TsHelper::getWalletIdByPayMethodId($paymethod->yp_payment_method_id);

        $txn = TsTxnId::make(date("Y-m-d H:i:s"), 4, 0);

        return [
                'brokerUserId' => $uid,
                'accountId' => $account_id,
                'poCode' => 'ZH000518',
                'walletId' => $wallet_id,
                'tradeAmount' => $amount,
                'brokerOrderNo' => $txn,
                'ignoreRiskGrade' => 1,
                'isIdempotent' => 1
            ];
    }

    public static function getFundShareIdByCodeAndPay($code, $pay_method)
    {
        $id = null;

        $row = YingmiShareDetail::where('ys_fund_code', $code)
             ->where('ys_pay_method', $pay_method)
             ->select('ys_share_id')
             ->first();
        if ($row) {
            $id = $row->ys_share_id;
        }

        return $id;
    }

    public static function getMfPoNameByRisk($risk)
    {
        $risk = round($risk * 10);

        return "智能组合-等级".$risk;
    }

    public static function getUserPaymentRow($uid)
    {
        $row = TsPayMethod::where('ts_uid', $uid)
             ->where('ts_master', 1)
             ->where('ts_bind', 1)
             ->first();

        return $row;
    }

    public static function getUserPaymentDetail($uid)
    {
        $payments = [];

        $payment = Self::getUserPaymentRow($uid);
        if (is_null($payment)) {
            return $payments;
        }

        // 解决渤海银行和民生银行不能购买的问题 start
        $tmp_pay = $payment->globalid;
        list($gw, $tmp_pay_id) = explode(':', $tmp_pay);

        $tpr = YingmiPaymentMethod::where('yp_payment_method_id', $tmp_pay_id)
             ->first();
        $tmp_bank = $tpr->yp_payment_type;

        $provider = YingmiPaymentProviders::where('yp_payment_type', $tmp_bank)
                  ->first();

        $xtab = [
            'bank:014' => '民生银行',
            'bank:034' => '渤海银行',
        ];
        $xtab1 = [
            'bank:014' => 'http://static.licaimofang.com/wp-content/uploads/2016/04/minsheng.png', // 民生
            'bank:034' => 'https://static.licaimofang.com/wp-content/uploads/2017/07/bohai.png', // 渤海银行
        ];
        $keys = array_keys($xtab);

        if (in_array($tmp_bank, $keys) && !$provider) {
            return [
                'paymethod' => $payment->globalid,
                'name' => $xtab[$tmp_bank]
                . "(尾号"
                . substr($tpr->yp_payment_no, -4)
                . ")",
                'val' => '限额单笔0元'
                . '/单日0元',

                'icon' => $xtab1[$tmp_bank],
                'amount' => 1000000,
                'single_amount' =>1000000,
                'left_amount' => 1000000,
            ];
        }
        // 解决渤海银行和民生银行不能购买的问题 end

        list($ym_pay, $left_amount) = self::getDailyAndLefyAmount($uid, $payment);

        $payments = [
            'paymethod' => $payment->globalid,
            'name' => $ym_pay->bank->yp_name
            . "(尾号"
            . substr($ym_pay->yp_payment_no, -4)
            . ")",
            'val' => '限额单笔'
            . Self::formatBankLimit($ym_pay->bank->yp_max_rapid_pay_amount_per_txn)
            . '/单日'
            . Self::formatBankLimit($ym_pay->bank->yp_max_rapid_pay_amount_per_day),
            'icon' => $ym_pay->bank->yp_icon,
            'amount' => 0,
            'single_amount' => round($ym_pay->bank->yp_max_rapid_pay_amount_per_txn),
            'left_amount' => $left_amount,
        ];

        return $payments;
    }

    public static function formatBankLimit($limit)
    {
        if ($limit >= 10000) {
            $limit = round((float)($limit) / 10000.00, 2) . '万元';
        } else if ($limit === NULL) {
            $limit = '无限额';
        } else  {
            $limit = round($limit) . '元';
        }

        return $limit;
    }


    /**
     * @param $list
     * @param int $type 1:result after user click buy button 2:result after user input buying amount
     * @return array
     */
    public static function formatBuyFundList($list, $type=1)
    {
        $result = [];

        foreach ($list as $row) {
            $fund = FundInfos::where('fi_code', $row['code'])->first();
            if ($type == 1) {
                $amount = '--';
            } else {
                $amount = round($row['amount'], 2);
            }
            $result[] = [
                'code' => $row['code'],
                'amount' => $amount,
                'percent' => round($row['ratio'] * 100, 2). "%",
                'name' => $fund->fi_name,
                'id' => $fund->fi_globalid,
                'type' => $row['type'],
            ];
        }

        return $result;
    }

    /**
     * @param $list
     * @param int $type 1:result after user click redeem button 2:result after user input redeem percentage
     * @return array
     */
    public static function formatRedeemFundList($list, $type=1)
    {
        $result = [];

        foreach ($list as $row) {
            $fund = FundInfos::where('fi_code', $row['code'])->first();
            if ($type == 1) {
                $redeem = 0;
            } else {
                $redeem = round($row['amount'], 2);
            }
            $result[] = [
                'id' => $fund->fi_globalid,
                'code' => $row['code'],
                'name' => $fund->fi_name,
                'shares' => round($row['amount_total'], 2),
                'redeem_shares' => $redeem,
            ];
        }

        return $result;
    }

    public static function getMigrateUids()
    {
        $uids = [
            1000000001,
            1000134759, //易明智
            1000106528, //马永谙
            1000078305, //焦德花
            1000105609, //宋百丰
            1000000074, //雷蕾
            1000120790, //辛飞
            1000000006, //潘友谊
            1000000002, //盛义涛
            1000000091, //张亮
            1000000087, //高蓬
            1000001141, //陈君
            1000083515, //张哲
            1000001054, //朱晓彬
            1000000011, //袁雨来
            1000000009, //周维
            1000001138, //孙娇龙
            1000095126, //王秋菊
            1000107874, //张高齐
            1000105792, //尹锐冲
            1000127064, //胡志珍
            1000134808, //姚家辉
            1000126623, //胡杨
            1000164688, //刘成利
            1000126869, //陈利
        ];

        return $uids;
    }

    public static function migrated($uid)
    {
        return true;

        $xtab = [
            1000000001,
            1000134759, //易明智
            1000106528, //马永谙
            1000078305, //焦德花
            1000105609, //宋百丰
            1000000074, //雷蕾
            1000120790, //辛飞
            1000000006, //潘友谊
            1000000002, //盛义涛
            1000000091, //张亮
            1000000087, //高蓬
            1000001141, //陈君
            1000083515, //张哲
            1000001054, //朱晓彬
            1000000011, //袁雨来
            1000000009, //周维
            1000001138, //孙娇龙
            1000095126, //王秋菊
            1000107874, //张高齐
            1000105792, //尹锐冲
            1000127064, //胡志珍
            1000134808, //姚家辉
            1000126623, //胡杨
            1000164688, //刘成利
            1000126869, //陈利
            1000176378, //胡戒
        ];

        $now = date('Y-m-d H:i:s');
        if ($now > '2017-08-19 05:00:00') {
            if ($uid < 1000140000 || $uid > 1000195000) {
                return true;
            }
        }

        return in_array($uid, $xtab);
    }

    /**
     *  老系统的TsSetFundDividend命令，设置分红方式用
     */
    public static function setDivByUid($uid)
    {
        $account_id = TsHelper::getYmAccountId($uid);
        $rows = YingmiShareDetail::where('ys_uid', $uid)
            ->where('ys_share_total', '>', 0)
            ->where('ys_div_mode', 1)
            ->get();
        // 先查询是有设置过分红方式的订单，如果有不用重新设置，如果没有，设置分红方式，并写库。
        foreach ($rows as $k=>$row)
        {
            $fund_code = $row->ys_fund_code;
            $pay_method =  $row->ys_pay_method;

            static::setOneFundShareDiv($uid, $account_id, $fund_code, $pay_method);
        }

        return true;
    }

    /**
     *  新的ts系统，在下单时设置分红方式
     * @param $uid
     * @param $account_id
     * @param $share_id
     * @param $fund_code
     * @param $pay_method
     */
    public static function setOneFundShareDiv($uid, $account_id, $fund_code, $pay_method)
    {
        $result = false;

        $pre_order = YingmiTradeStatus::where('yt_uid', $uid)
            ->where('yt_fund_code', $fund_code)
            ->where('yt_pay_method',$pay_method)
            ->where('yt_trade_type', '029')
            ->orderBy('yt_placed_date', 'desc')
            ->orderBy('yt_placed_time', 'desc')
            ->first();
        if ($pre_order) {
            Log::info("skipping $uid");
            return $result;
        }

        $order_id = MfHelper::getOrderId();
        $params = [
            'brokerUserId' => $uid,
            'accountId' => $account_id,
            'paymentMethodId' => $pay_method,
            'fundCode' => $fund_code,
            'brokerOrderNo' => $order_id,
            'dividendMethod' => '0',
        ];

        $tmp = YmHelper::rest_rpc("/trade/setDividendMethodByFundCode", $params, "post");
        if ($tmp['code'] == '20000' && is_array($tmp['result'])) { // 写订单
            $fund_row = FundInfos::where('fi_code', $fund_code)->first();
            $fund_id = $fund_row->fi_globalid;
            $fund_name = $fund_row->fi_name;

            $po = MfPortfolioInfo::where('mf_uid', $uid)->first();
            if ($po) {
                $po_id = $po->id;
            } else {
                $new = new MfPortfolioInfo();
                $new->mf_uid = $uid;
                $new->save();
                $po_id = $new->id;
            }
            $model = new YingmiTradeStatus();
            $model->yt_txn_id = $order_id;
            $model->yt_uid = $uid;
            $model->yt_portfolio_id = $po_id;
            $model->yt_fund_id = $fund_id;
            $model->yt_fund_code = $fund_code;
            $model->yt_fund_name = $fund_name;
            $model->yt_trade_type = '029';
            $model->yt_trade_status = 0;
            $model->yt_pay_method = $pay_method;
            $model->yt_share_id = '';
            $model->yt_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->yt_trade_date = $tmp['result']['orderTradeDate'];
            $model->yt_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->yt_div_mod = 0;
            $model->yt_yingmi_order_id = $tmp['result']['orderId'];
            $model->save();
            $result = true;
        } else {
            $result = false;
        }

        return $result;
    }


    /**
     * 设置基金的分红方式 单写一个cron执行该操作
     */
    public static function setFundDividend($yts)
    {
        $result = ['txn'=>$yts->yt_txn_id, 'status'=>-1];

        $uid = $yts->yt_uid;
        $account_id = $yts->yt_account_id;
        if (is_null($account_id) || $account_id == '') {
            $account_id = static::getYmAccountId($uid);
        }

        $fund_code = $yts->yt_fund_code;
        $pay_method = $yts->yt_pay_method;
        $order_id = $yts->yt_txn_id;

        $params = [
            'brokerUserId' => $uid,
            'accountId' => $account_id,
            'paymentMethodId' => $pay_method,
            'fundCode' => $fund_code,
            'brokerOrderNo' => $order_id,
            'dividendMethod' => '0',
        ];

        $tmp = YmHelper::rest_rpc("/trade/setDividendMethodByFundCode", $params, "post");
        if ($tmp['code'] == '20000' && is_array($tmp['result'])) { // 写订单
            $yts->yt_trade_status = 0;
            $yts->yt_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $yts->yt_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $yts->yt_trade_date = $tmp['result']['orderTradeDate'];
            $yts->yt_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $yts->yt_div_mod = 0;
            $yts->yt_yingmi_order_id = $tmp['result']['orderId'];

            $result = ['txn'=>$order_id, 'status'=>0];
        } elseif ($tmp['code'] == -1) {
            $yts->yt_trade_status = 0;
            $yts->yt_error_code = -1;
            $yts->yt_error_msg = '系统错误';

            $result = ['txn' => $order_id, 'status' => 7];
        } else {
            $yts->yt_trade_status = 1;
            $yts->yt_error_code = $tmp['code'];
            $yts->yt_error_msg = $tmp['msg'];

            $result = ['txn' => $order_id, 'status' => 8];
        }

        $yts->save();

        return $result;
    }

    /**
     * @param $disable_lock
     * @param $uid
     */
    public static function updateOneUserWalletShare($uid, $disable_lock=false)
    {
        DB::beginTransaction();
        try {
            if ($disable_lock) {
                MfHelper::updateUserWalletShareDetails($uid);
            } else {
                $locked_row = YingmiWalletShareDetail::where('yw_uid', $uid)
                    ->lockForUpdate()
                    ->get();
                if ($locked_row->isEmpty()) {
                    $msg = __CLASS__ . '@' . __FUNCTION__ . " artisan update wallet share detail lock for update failed uid=" . $uid;
                    Log::info($msg);
                    // static::sendSms($msg);
                }
                MfHelper::updateUserWalletShareDetails($uid);
            }
        } catch (\Exception $e) {
            $msg = __CLASS__ . '@' . __FUNCTION__ . " artisan update wallet share detail exception caught uid=" . $uid;
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            static::sendSms($msg);
        }

        DB::commit();
    }

    public static function checkPayMethod($uid, $paymethod)
    {
        //解决渤海银行和民生银行不能购买的问题 start
        @list($tmp_gw, $tmp_pay_id) = explode(':', $paymethod);
        if (!$tmp_pay_id) {
            Log::error('bad paymethod, may missing gateway info', ['uid' => $uid, 'paymethod' => $paymethod]);
            return [
                'code' => 20001,
                'message' => '支付方式不存在,请联系客服',
            ];
        }
        $tpr = YingmiPaymentMethod::where('yp_payment_method_id', $tmp_pay_id)
             ->first();
        $tmp_bank = $tpr->yp_payment_type;
        $provider = YingmiPaymentProviders::where('yp_payment_type', $tmp_bank)
                  ->first();
        $keys = [
            'bank:014',
            'bank:034'
        ];
        if (in_array($tmp_bank, $keys) && !$provider) {
            return [
                'code' => 20004,
                'message' => '因民生银行、渤海银行总部进行年底风控审查，所有第三方支付的接口都暂时关闭。在此期间，使用民生银行的用户暂时不能用民生银行卡购买智能产品组合。已用上述两家银行卡购买的基金可以正常赎回，不受影响。具体恢复时间待定，请等候通知',
            ];
        }

        return ['code' => 20000, 'message' => 'Success'];
    }

    public static function getWalletBankFormat($availAmount)
    {
        $limit = sprintf('可用额度%s元', number_format($availAmount, 2));
        $item = [
            'bank_id' => 'wallet',
            'bank_icon' => 'https://static.licaimofang.com/wp-content/uploads/2018/05/mfb.png',
            'bank_name' => '魔方宝',
            'limit' => $limit,
            'card_no' => '',
            'type' => 1,
            'status' => null,
            'left_amount' => $availAmount,
            'single_amount' => $availAmount,
            'pay_type' => 1,
        ];

        return $item;
    }

    public static function resendRechargeVerifyCode($uid, $orderId)
    {
        $timing = new Timing(sprintf('%s@%s[%s:%s]', basename_class(__CLASS__), __FUNCTION__, $uid, $orderId));

        $accountId = static::getYmAccountId($uid);

        $params = [
            'accountId' => $accountId,
            'brokerUserId' => $uid,
            'orderId' => $orderId,
        ];

        $tmp = YmHelper::rest_rpc('/trade/rechargeResendVerifyCode', $params, 'post');

        if ($tmp['code'] == '20000' && is_array($tmp['result'])) {
            return ['status' => 1, 'msg' => '已重新发送', 'resendable' => true, 'code' => $tmp['code']];
        } elseif (in_array($tmp['code'], ['3043', '3041', '1583', '1584'])) {
            if ($tmp['code'] == '3043') {
                $resendable = true;
            } else {
                $resendable = false;
            }

            return ['status' => -1, 'msg' => $tmp['msg'], 'resendable' => $resendable, 'code' => $tmp['code']];
        } else {
            return ['status' => -1, 'msg' => '未知错误', 'resendable' => false, 'code' => $tmp['code']];
        }
    }

    public static function confirmRechargeVerifyCode($uid, $orderId, $verifyCode)
    {
        $timing = new Timing(sprintf('%s@%s[%s:%s]', basename_class(__CLASS__), __FUNCTION__, $uid, $orderId));

        $accountId = static::getYmAccountId($uid);

        $params = [
            'accountId' => $accountId,
            'brokerUserId' => $uid,
            'orderId' => $orderId,
            'verifyCode' => $verifyCode,
        ];

        $tmp = YmHelper::rest_rpc('/trade/rechargeWalletVerifyCode', $params, 'post');

        $code = $tmp['code'];
        if ($code == '20000' && is_array($tmp['result'])) {
            return ['status' => 1, 'msg' => $tmp['result']['msg'], 'code' => $code];
        } elseif (in_array($code, ['1129', '1561'])) {
            if ($code == '1129') {
                $status = 3;
            } else {
                $status = 2;
            }

            return ['status' => $status, 'msg' => $tmp['msg'], 'code' => $code];
        } else {
            return ['status' => 0, 'msg' => $tmp['msg'], 'code' => $code];
        }
    }
}
