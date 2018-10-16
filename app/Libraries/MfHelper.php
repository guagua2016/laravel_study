<?php namespace App\Libraries;

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
use App\UserQuestionnaireAnswer;
use App\UserQuestionnaireSummarie;
use App\TsOrder;

class MfHelper
{
    use DispatchesJobs;

    /**
     * @param $result
     * @return mixed|string
     */
    public static function formatZxbRedeemResult($result)
    {
        $result = json_encode($result);

        $result = str_replace('total_share', 'total_tmp', $result);
        $result = str_replace('share', 'amount', $result);
        $result = str_replace('fundCode', 'fund_code', $result);
        $result = str_replace('total_tmp', 'total_share', $result);
        $result = json_decode($result, true);

        return $result;
    }

    /**
     * @param $result
     * @return mixed|string
     */
    public static function formatZxbBuyResult($result)
    {
        $result = json_encode($result);

        $result = str_replace('fundCode', 'fund_code', $result);
        $result = json_decode($result, true);

        return $result;
    }

    /**
     * @param $result
     * @return mixed|string
     */
    public static function formatZxbAdjustRedeemResult($result)
    {
        $result = json_encode($result);

        $result = str_replace('total_share', 'total_tmp', $result);
        $result = str_replace('share', 'amount', $result);
        $result = str_replace('fundCode', 'fund_code', $result);
        $result = str_replace('total_tmp', 'total_share', $result);
        $result = json_decode($result, true);

        return $result;
    }

    /**
     * @param $uid
     * @param $value
     * @param $order_id
     * @param $user_risk_value
     * @param $payment
     * @param $po_info
     * @param $invest_plan_id
     * @return mixed
     */
    public static function genDefaultBuyOrder($uid, $value, $order_id, $user_risk_value, $payment_method, $po_id, $invest_plan_id)
    {
        $risk = self::getDecimalRiskValue($user_risk_value);

        $model = MfPortfolioTradeStatus::firstOrNew(['mp_txn_id' => $order_id]);

        $model->mp_uid = $uid;
        $model->mp_portfolio_id = $po_id;
        $model->mp_risk = $risk;
        $model->mp_adjustment_id = null;
        $model->mp_trade_type = 'P02';
        $model->mp_trade_status = 'P99';
        $model->mp_placed_amount = $value;
        $model->mp_placed_date = date('Y-m-d');
        $model->mp_placed_time = date('H:i:s');
        $model->mp_pay_method = $payment_method;
        $model->mp_pay_status = 0;
        $model->mp_invest_plan_id = $invest_plan_id;
        $model->mp_extra = '';
        $model->save();

        return $model;
    }

    /**
     * @param $uid
     * @param $payment_method
     */
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
     * @param $uid
     * @return mixed
     */
    public static function getDefaultPoInfo($uid)
    {
        $po_info = MfPortfolioInfo::firstOrNew(['mf_uid' => $uid]);//首先创建用户唯一的组合id，便于后续处理

        if (!$po_info->id) {
            $po_info->mf_established_date = date('Y-m-d');;
            $po_info->mf_established_time = date('H:i:s');;
            $po_info->save();
        }

        return $po_info;
    }

    /**
     * @param $uid
     * @param $order_id
     * @param $po_id
     * @param $po_comps
     * @param $invest_plan_id
     * @return array
     */
    public static function genBuyingSubOrders($uid, $order_id, $po_id, $po_comps, $invest_plan_id)
    {
        $sub_orders = [];
        $payment = self::getPaymentInfo($uid);
        if ($payment) {
            $pay_method = $payment->yp_payment_method_id;
        } else {
            $pay_method = '';
        }
        foreach ($po_comps as $order) {
            $sub_order_id = self::getOrderId();

            $buy_type = 8; //means 未正式向盈米下单
            if($order['op'] == 11){
                $buy_type = 11; //延迟下单 对应op=11
            }

            $row = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $sub_order_id]);
            $row->mf_direct_buy = 1;
            $row->mf_uid = $uid;
            $row->mf_portfolio_txn_id = $order_id;
            $row->mf_portfolio_id = $po_id;
            $fund_info = self::getFundInfoByCode($order['fund_code']);
            $row->mf_fund_id = $fund_info->fi_globalid;
            $row->mf_fund_code = $order['fund_code'];
            $row->mf_fund_name = $fund_info->fi_name;
            $row->mf_trade_type = 'W04';
            $row->mf_trade_status = $buy_type;
            $row->mf_placed_amount = $order['amount'];
            $row->mf_invest_plan_id = $invest_plan_id;
            $row->mf_pay_method = $pay_method;
            $row->save();

            $sub_orders[] = $sub_order_id;
        }

        return $sub_orders;
    }

    /**
     * @param $uid
     * @param $risk_specified
     * @param $po_id
     * @return float
     */
    public static function updateRiskAfterBuy($uid, $po_id, $risk_specified)
    {
        /*if (!$risk_specified) {
            $risk_stored = round(self::getUserRiskValue($uid) / 10, 1);
        } else {
            $risk_stored = round($risk_specified / 10, 1);
        }*/
        $risk_stored = self::getDecimalRiskValue($risk_specified);

        $po_share = MfPortfolioShare::firstOrNew(['mp_uid' => $uid, 'mp_portfolio_id' => $po_id]);
        $old_risk = $po_share->mp_risk;
        $po_share->mp_risk = $risk_stored;

        $po_share->save();
        Log::info("MfHelper@updateRiskAfterBuy", [func_get_args(), 'old'=>$old_risk, 'new'=>$risk_stored]);

        return round($risk_specified);
    }

    /**
     * @param $uid
     * @param $order_id
     * @param $model
     * @param $invest_plan_id
     */
    public static function sendMsgBuy1129($uid, $order_id, $model, $invest_plan_id)
    {
        if (is_null($invest_plan_id)) {//正常购买
            $alert_msg = "uid=$uid,po_id=$model->portfolio_id,sum=$model->mf_placed_amount,order_id=$order_id " . "正常购买时,盈米宝充值阶段返回1129，请及时联系潘友谊进行处理13811710773";
        } else {//定投购买
            $alert_msg = "uid=$uid,po_id=$model->portfolio_id,sum=$model->mf_placed_amount,order_id=$order_id " . "定投扣款时,盈米宝充值阶段返回1129，请及时联系潘友谊进行处理13811710773";
        }
        self::sendSms($alert_msg);
    }

    /**
     * @param $uid
     * @param $value
     * @param $dealed_time
     * @param $po_info
     */
    public static function sendMsgBuySuccess($uid, $value, $dealed_time, $buying_risk)
    {
        $po_name = self::getPoNameByRisk($buying_risk);
        $place_time = date('Y年m月d日 H:i:s', strtotime($dealed_time));
        $tel = config('koudai.customer_tel');
        $msg = "尊敬的用户您好，您于" . $place_time . "申购的" . $value . "元" . $po_name . "已受理成功，请登陆理财魔方APP，在我的资产中查看交易进度。如有疑问请咨询" . $tel . "。";
        self::sendSmsUid($msg, [$uid]);

        $jmsg = '感谢您投资理财魔方智能组合，为了更好的为您服务，请您登陆理财魔方官方公众号，领取您的投资分析报告，谢谢！';
        self::sendJpush($jmsg, [$uid]);

        MessageService::message([$uid], '请领取投资分析报告', $jmsg);
    }

    public static function getNewerMsg($uid)
    {
        // 新手信逻辑

        $show_newer_msg = false;
        $newer_msg = 'https://static.licaimofang.com/wp-content/uploads/2017/07/xinyonghu.png';

        //$cnt = MfPortfolioTradeStatus::where('mp_uid', $uid)->count();
        $cnt = TsOrder::where('ts_uid', $uid)->count();
        if ($cnt > 1) { // old
            $show_newer_msg = false;
            $newer_msg = '';
        } else {
            $q_sum = UserQuestionnaireSummarie::where('uq_uid', $uid)
                   ->orderBy('id', 'desc')
                   ->first();
            if (!$q_sum) {
                $show_newer_msg = true;
                $newer_msg = 'https://static.licaimofang.com/wp-content/uploads/2017/07/xinyonghu.png';
            } else {
                //         1、投资经验不满3年；
                $tag1 = false;
                $opt = ['A', 'B'];
                $q_id = 6;
                $ans = UserQuestionnaireAnswer::where('uq_uid', $uid)
                     ->where('uq_question_id', $q_id)
                     ->orderBy('id', 'desc')
                     ->first();
                if ($ans && in_array($ans->uq_answer, $opt)) {
                    $tag1 = true;
                }

                //         2、预期收益5%左右；
                $tag2 = false;
                $opt = ['A'];
                $q_id = 8;
                $ans = UserQuestionnaireAnswer::where('uq_uid', $uid)
                     ->where('uq_question_id', $q_id)
                     ->orderBy('id', 'desc')
                     ->first();
                if ($ans && in_array($ans->uq_answer, $opt)) {
                    $tag2 = true;
                }


                if ($tag1 || $tag2) {
                    $show_newer_msg = true;
                    $newer_msg = 'https://static.licaimofang.com/wp-content/uploads/2017/07/xinyonghu.png';
                } else {
                    $show_newer_msg = true;
                    $newer_msg = 'https://static.licaimofang.com/wp-content/uploads/2017/07/laoyonghu.png';
                }
            }
        }

        return ['show_newer_msg'=>$show_newer_msg, 'newer_msg'=>$newer_msg];
    }
    /**
     * @param $uid
     * @param $value
     * @param $order_id
     * @param $dealed_time
     * @return array
     */
    public static function getBuySuccessResult($uid, $value, $order_id, $dealed_time)
    {
        $binded = self::getWechatBindStatus($uid);

        $newer = self::getNewerMsg($uid);

        $order = TsOrder::where('ts_txn_id', $order_id)->first();
        if ($order) {
            $val1 = '智能组合 等级' . number_format($order->ts_risk * 10);
        } else {
            $val1 = '智能组合';
        }

        $result = [
            'success_pic' => 'http://static.licaimofang.com/wp-content/uploads/2016/07/success.png',
            'success_text' => '您的购买申请已受理',
            'items' => [
                [
                    'key' => '申购组合',
                    'val' => $val1,
                ],
                [
                    'key' => '申购金额',
                    'val' => sprintf('%s元', number_format($value, 2)),
                ],
                [
                    'key' => '受理时间',
                    'val' => $dealed_time,
                ],
                [
                    'key' => '预计确认份额日期',
                    'val' => '请稍后进入交易记录详情页查看',
                ],
            ],
            'order_id' => '订单号:' . $order_id,
            'wechat_msg' => '微信公众账号已经复制成功,请您前去微信搜索栏粘贴,并添加关注我们的公众号,会有您的专属投资顾问为您服务并且按时发送您的投资报告及调仓提醒',
            'wechat_id' => 'licaimf',
            'show_confirm' => !$binded,
            //'show_newer_msg' => $show_newer_msg,
            //'newer_msg' => $newer_msg,
        ];

        $result = array_merge($result, $newer);

        return $result;
    }

    /**
     * @param $uid
     * @param $risk_specified 1 2 3 - 10
     * @return array
     */
    public static function getUserRiskValueForBuying($uid, $risk_specified=null)
    {
        if ($risk_specified) {
            $user_risk_value = $risk_specified;
        } else {
            $user_risk_value = self::getUserRiskValue($uid);
        }

        //$user_risk_value = round($user_risk_value / 10, 1);
        if($user_risk_value > 10){
            $user_risk_value = 10;
        }

        if($user_risk_value < 1){
            $user_risk_value = 1;
        }

        return  $user_risk_value;
    }

    /**
     * @param $model
     * @param $wait_flag
     */
    public static function updatePoOrderStatus($model, $wait_flag=false)
    {
        if ($wait_flag) {
            $model->mp_trade_status = 'P11';
            $model->mp_pay_status = 0;
        } else {
            $model->mp_trade_status = 'P10';
            $model->mp_pay_status = 2;
        }
        $model->save();

        return $model;
    }

    /**
     * @param $uid
     * @param $value
     * @param $user_risk_value
     * @param $invest_plan_id
     * @return array
     */
    public static function getUserPoTradeComps($uid, $value, $user_risk_value, $invest_plan_id)
    {
        if (is_null($invest_plan_id)) {
            $po_ori_comp = self::getUserPoTradeList($uid, $value, 1, $user_risk_value); //普通购买
        } else {
            $po_ori_comp = self::getUserPoTradeList($uid, $value, 4, $user_risk_value); //定投购买
        }
        if (!isset($po_ori_comp['code'])) {
            return ['code' => 20001, 'message' => '购买遇到问题，请稍后重试或请联系客服'];
        }
        if ($po_ori_comp['code'] != 20000) {
            return ['code' => 20002, 'message' => '购买遇到问题，请稍后重试或请联系客服'];
            //return $po_ori_comp;
        }
        if (!isset($po_ori_comp['result']['op_list'])) {
            return ['code' => 20003, 'message' => '购买遇到问题，请稍后重试或请联系客服'];
        }

        return ['code' => 20000, 'message' =>'success', 'result'=>$po_ori_comp['result']['op_list']];//最终的操作列表
    }

    /**
     * @param $user_risk_value
     */
    public static function getDecimalRiskValue($user_risk_value)
    {
        $risk = round($user_risk_value / 10, 1);

        if ($risk > 1) {
            $risk = 1;
        }
        if ($risk <= 0) {
            $risk = 0.1;
        }

        return $risk;
    }

    /**
     * @param $uid
     * @param $portfolio_txn_id
     * @param $po_ori_comp
     * @param $parent_txn_id
     * @return array
     */
    public static function genAdjustBuyingSubOrder($uid, $portfolio_txn_id, $po_ori_comp, $parent_txn_id)
    {
        $po_info = MfPortfolioInfo::where('mf_uid', $uid)->first();

        $sub_orders = [];

        foreach ($po_ori_comp as $order) {
            $sub_order_id = self::getOrderId();
            $fund_info = self::getFundInfoByCode($order['fund_code']);

            $buy_type = 8; //means 未正式向盈米下单
            if($order['op'] == 11){
                $buy_type = 11; //延迟下单 对应op=11
            }

            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $sub_order_id]);
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_txn_id;
            $model->mf_parent_txn_id = $parent_txn_id; //tod change mf_parent_txn_id column type
            $model->mf_portfolio_id = $po_info->id;
            $model->mf_fund_id = $fund_info->fi_globalid;
            $model->mf_fund_code = $order['fund_code'];
            $model->mf_fund_name = $fund_info->fi_name;
            $model->mf_trade_type = 'W04';
            $model->mf_trade_status = $buy_type;
            $model->mf_placed_amount = $order['amount'];
            Log::info(__FUNCTION__." raw result=", $model->toArray());
            $model->save();

            $sub_orders[] = $sub_order_id;
        }
        return $sub_orders;
    }

    /**
     *获取魔方组合的交易流水号
     */
    public static function getOrderId()
    {
        return Uuid::generate()->string;
    }

    public static function getPaymentInfo($uid)
    {
        $row = YingmiPaymentMethod::where('yp_uid', $uid)
            ->where('yp_enabled', 1)
            ->first();

        return $row;
    }

    public static function getPortfolioInfo($uid, $id)
    {
        $info = MfPortfolioInfo::where('id', $id)->where('mf_uid', $uid)->first();

        return $info;
    }

    /**
     *$uid
     *$id 组合id
     *also return portfolio_info yingmi_account and payment model
     */
    public static function checkUserStatus($uid, $id=false)
    {
        //$info = self::getPortfolioInfo($uid, $id);
        // if (!$info) {
        //     return ['code' => 20001, 'message' => '请提供正确的组合ID'];
        // }

        $info = MfPortfolioInfo::firstOrNew(['mf_uid' => $uid]);
        $info->mf_name = '智能组合';
        $info->save();

        $ym_account = self::getYingmiAccount($uid);
        if (!$ym_account) {
            return ['code' => 20001, 'message' => '要购买基金，请先开户并绑卡'];
        }

        $payment = self::getPaymentInfo($uid);
        if (!$payment) {
            return ['code' => 20001, 'message' => '开户未绑卡，或未选定一张银行卡作为主卡'];
        }


        $result = [
            'info' => $info, //组合信息
            'account' => $ym_account, //盈米账户信心
            'payment' => $payment,//支付信息
        ];

        return ['code' => 20000, 'message' => 'success', 'result' => $result];
    }

    /**
     * $key = 0 以paymentId 为key
     *      = 1 以walletId  为key
     */
    public static function getPaymentWalletByPeer($uid, $key)
    {
        $builder = YingmiWalletShareDetail::where('yw_uid', $uid);

        if ($key == 0) {
            $rows = $builder->lists('yw_wallet_id', 'yw_pay_method');
        } else {
            $rows = $builder->lists('yw_pay_method', 'yw_wallet_id');
        }

        return $rows;
    }

    public static function getPaymentIdByWallet($uid, $wallet)
    {
        $rows = self::getPaymentWalletByPeer($uid, 1);

        return isset($rows[$wallet]) ? $rows[$wallet] : null;
    }

    public static function getWalletIdByPayment($uid, $payment)
    {
        $rows = self::getPaymentWalletByPeer($uid, 0);

        return isset($rows[$payment]) ? $rows[$payment] : null;
    }

    public static function getWalletShareDetail($uid, $payment_method)
    {
        Log::info("[MFPLAN:PYY:$uid]", func_get_args());

        $row = YingmiWalletShareDetail::where('yw_uid', $uid)
            ->where('yw_pay_method', $payment_method)
            ->orderBy('id', 'desc')
            ->first();

        return $row;
    }

    /**
     * @param $uid
     * @param $ymId
     * @param $row
     */
    public static function saveWalletShareDetail($uid, $ymId, $row)
    {
        $fi = BnFundInfo::findByCode($row['fundCode'], 'dummy');
        $share = YingmiWalletShareDetail::where('yw_uid', $uid)
            ->where('yw_pay_method', $row['paymentMethodId'])
            ->first();
        if (!$share) {
            $share = new YingmiWalletShareDetail();
        }
        if (isset($row['fundName'])) {
            $fund_name = $row['fundName'];
        } else {
            $wallet_fund = YingmiWalletFund::where('yw_fund_code', $row['fundCode'])->first();
            if ($wallet_fund) {
                $fund_name = $wallet_fund->yw_fund_name;
            } else {
                $fund_name = '';
            }
        }
        $share->yw_uid = $uid;
        $share->yw_account_id = $ymId;
        $share->yw_wallet_id = $row['walletId'];
        $share->yw_fund_id = $fi->fi_globalid;
        $share->yw_fund_code = $row['fundCode'];
        $share->yw_fund_name = $fund_name;
        $share->yw_pay_method = $row['paymentMethodId'];
        $share->yw_share_avail_total = $row['totalAvailShare'];
        $share->yw_withdraw_share_avail = $row['withdrawAvailShare'];
        $share->yw_output_share_avail = $row['outputAvailShare'];

        $share->save();
    }

    /**
     * @return array
     */
    public static function getUserBuyLimit($uid)
    {
        $vip = MfVipUser::where('mf_uid', $uid)
            ->where('mf_type', 1)
            ->first();
        if ($vip) {
            $buy_limit = [
                'lowest' => round($vip->mf_amount),
                'highest' => 10000000,
            ];
        } else {
            $buy_limit = [
                'lowest' => 2000,
                'highest' => 10000000,
            ];
        }

        return $buy_limit;
    }

    /**
     * @param $parent_txn_id
     */
    private static function updateDealedTagAfterAdjustBuy($parent_txn_id)
    {
        $update_mf_dealed = MfFundTradeStatus::whereIn('mf_txn_id', $parent_txn_id)->update(['mf_dealed' => 1]);
        $update_ym_dealed = YingmiTradeStatus::whereIn('id', $parent_txn_id)->update(['yt_dealed' => 1]);
    }

    public static function getFundShareDetail($uid, $fund_id)
    {
        $share = MfFundShareDetail::where('mf_uid', $uid)
            ->where('mf_fund_id', $fund_id)
            ->where('mf_asset_total', '>', 0)
            ->first();

        return $share;
    }

    public static function getWalletFund()
    {
        $wallet_fund = YingmiWalletFund::orderBy('id', 'asc')->first();

        return $wallet_fund;
    }

    public static function getFundInfo($fund_id)
    {
        $fund = FundInfos::where('fi_globalid', $fund_id)
            //->where('fi_yingmi_subscribe_status', 0)
            ->first();

        return $fund;
    }

    public static function getFundInfoByCode($fund_code)
    {
        $fund = FundInfos::where('fi_code', $fund_code)
            //->where('fi_yingmi_subscribe_status', 0)
            ->first();

        return $fund;
    }


    public static function getYingmiAccount($uid)
    {
        $ym_account = YingmiAccount::where('ya_uid', $uid)->first();

        return $ym_account;
    }

    public static function formatBankLimit($limit)
    {
        if ($limit >= 10000) {
            $limit = round((float)($limit) / 10000.00, 2) . '万元';
        } else if ($limit === NULL) {
            $limit = '--元';
        } else  {
            $limit = round($limit) . '元';
        }

        return $limit;
    }

    public static function formatFundCode($code)
    {
        return sprintf('%06d', $code);
    }

    public static function getUserPaymentDetail($uid)
    {
        $payments = [];
        $payment = Self::getPaymentInfo($uid);
        if (is_null($payment)) {
            return $payments;
        }

        $payments[] = [
            'id' => $payment->yp_payment_method_id,
            'name' => $payment->bank->yp_name
                . "(尾号"
                . substr($payment->yp_payment_no, -4)
                . ")",
            'val' => '限额单笔'
                . Self::formatBankLimit($payment->bank->yp_max_rapid_pay_amount_per_txn)
                . '/单日'
                . Self::formatBankLimit($payment->bank->yp_max_rapid_pay_amount_per_day),
            'icon' => $payment->bank->yp_icon,
            'amount' => 0,
            'single_amount' => round($payment->bank->yp_max_rapid_pay_amount_per_txn),
        ];

        return $payments;
    }

    /**
     * 0保守型 1稳健型 2积极型
     * @param $risk_grade
     * @return int
     */
    public static function getRiskTypeByGrade($uid)
    {
        //保守型：0、1、2
        //稳健型：3、4、5
        //积极型：6、7、8、9、10

        $risk_row = Self::getUserRiskInfo($uid);
        if (!$risk_row) {
            return 0;
        }

        $risk_grade = $risk_row->ur_risk * 10;
        if ($risk_grade <= 2) {
            return 1;
        } elseif ($risk_grade >= 6) {
            return 3;
        } else {
            return 2;
        }
    }

    public static function getUserRiskChar($risk_type)
    {
        if ($risk_type == 1) {
            return '保守型';
        } elseif ($risk_type == 2) {
            return '稳健型';
        } elseif ($risk_type == 3) {
            return '积极型';
        } else {
            return '保守型';
        }
    }

    public static function getUserRiskInfo($uid)
    {
        $row = UserRiskAnalyzeResult::where("ur_uid", $uid)
            ->orderBy('ur_date', 'DESC')
            ->first();

        return $row;
    }

    /**
     * 如果没有做评测，返回风险值为5
     * @param $uid
     * @param $risk_from_web 1 2 3  - 10
     * @return int
     *
     */
    public static function getUserRiskValue($uid, $risk_from_web = null)
    {
        if ($risk_from_web) {
            return $risk_from_web;
        }

        $final_risk = MfPortfolioShare::userPortfolioRisk($uid);
        if (!$final_risk) {
            $m = UserRiskAnalyzeResult::where('ur_uid', $uid)
                ->where('ur_risk', '>', 0)
                ->orderBy('updated_at', 'DESC')
                ->first();
            if ($m) {
                $final_risk = $m->ur_risk;
            } else {
                $m = UserRiskAnalyzeResult::where('ur_uid', $uid)
                    ->orderBy('updated_at', 'DESC')
                    ->first();
                if ($m) {
                    $final_risk = $m->ur_assign_risk;
                } else {
                    $final_risk = 0.5;
                }
            }
        }

        $tmp = ceil($final_risk * 10);
        if($tmp>10){
            $tmp = 10;
        }else if($tmp < 1){
            $tmp = 1;
        }

        return $tmp;

//        $row = Self::getUserRiskInfo($uid);
//        if (!$row) {
//            return 5;
//        }
//
//        return $row->user_real_risk * 10;
    }

    //may do not need this function any more
    public static function getUserRiskPromptInfo($uid, $po_risk)
    {
        $risk_type = Self::getRiskTypeByGrade($uid);
        $risk_text = '';
        $show_risk = 0;
        $risk_title = '风险提示';
        if ($risk_type == 0) {
            $show_risk = 0; // 0 未做过风险评测，需要做风险评测 1 显示风险提示信息
            $risk_text = '您尚未进行风险测评，购买基金前请先完成风险测评';
            $risk_title = '风险测评';
        } else {
            $show_risk = 2;
        }

        return [
            'show_risk' => $show_risk,
            'risk_text' => $risk_text,
            'risk_title' => $risk_title,
        ];
    }

    /**
     * 获取用户持仓信息，与晓彬交互使用
     *
     */
    public static function getUserHoldingInfo($uid, $type = 0)
    {
        $base_msg = __CLASS__ . '@' . __FUNCTION__ . $uid ;

        $timing = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        Log::info($base_msg, [11]);
        $poOrders = MfPortfolioTradeStatus::with('subOrders')
            ->where('mp_uid', $uid)
            ->whereIn('mp_trade_status', ['P0', 'P1'])
            ->orderBy('mp_placed_date', 'ASC')
            ->orderBy('mp_placed_time', 'ASC')
            ->get();
        Log::info($base_msg, [12]);
        $today = Carbon::today()->toDateString();

        //$details = MfFundHoldingDetail1::where('mf_uid', $uid)
        //         ->where('mf_date', $today)
        //         ->where('mf_share', '>', 0)
        //         ->get();
        //$ids = $details->keyBy('mf_fund_id')->keys()->all();
        //$ids = BnFundInfo::whereIn('fi_globalid', $ids)
        //     ->get(['fi_globalid', 'fi_code']);
        //$ids = $ids->keyBy('fi_globalid');

        //$holdings = [];
        //foreach ($details as $detail) {
        //    $code = '';
        //    if ($ids->has($detail->mf_fund_id)) {
        //        $code = sprintf('%06d', $ids[$detail->mf_fund_id]->fi_code);
        //    }
        //    $holdings[] = [
        //        'code'   => $code,
        //        'share'  => $detail->mf_share,
        //        'amount' => $detail->mf_asset, // 市值
        //        'date'   => $detail->mf_trade_date,
        //    ];
        //}
        $holdings = [];
        $others = MfFundShare::where('mf_uid', $uid)
            ->where('mf_share_total', '>', 0)
            //->whereNotIn('mf_fund_id', $ids->keys()->toArray())
            ->get();
        foreach ($others as $other) {
            // 如果此基金正在赎回中且可用份额为0，不在holdings显示此基金
            if ($other->mf_share_avail == 0) {
                $re = MfFundTradeStatus::where('mf_uid', $uid)
                    ->where('mf_fund_id', $other->mf_fund_id)
                    ->whereIn('mf_trade_type', ['W05', '024'])
                    ->where('mf_trade_status', 0)
                    ->first();

                if ($re) {
                    continue;
                }
            }

            $holdings[] = [
                'code' => $other->mf_fund_code,
                'share' => $other->mf_share_avail,
                'amount' => $other->mf_asset_total, // 市值
                'date' => $other->mf_nav_date,
            ];
        }

        $shares = MfFundShare::where('mf_uid', $uid)
            ->get(['mf_fund_id', 'mf_nav_date', 'mf_nav']);
        $shares = $shares->keyBy('mf_fund_id');
        $fundIds = MfFundTradeStatus::where('mf_uid', $uid)->distinct()->lists('mf_fund_id');
        //$values = BnFundInfo::with('latestValue')
        //        ->whereIn('fi_globalid', $fundIds)
        //        ->get();
        //$navs = $values->keyBy('fi_globalid');

        $buyings = [];
        $adjusts = [];
        $cancelBuying = [];
        $cancelRedeeming = [];
        foreach ($poOrders as $poOrder) {
            if (!isset($poOrder->subOrders) || $poOrder->subOrders->isEmpty()) {
                Log::error('portfolio suborders is null');
                continue;
            }

            $poType = $poOrder->mp_trade_type;
            $subOrders = $poOrder->subOrders;
            foreach ($subOrders as $subOrder) {
                $fundId = $subOrder->mf_fund_id;
                $type = $subOrder->mf_trade_type;
                $status = $subOrder->mf_trade_status;

                // 忽略 下单失败,确认失败,已撤单 的订单
                if (in_array($status, [1, 7, 9])) {
                    continue;
                }

                // 已确认的订单放在holdings中，未确认的放在订单中
                if (in_array($type, ['022', '020', 'W04'])) {
                    if ($status == 0) {
                        $orign = ($poType == 'P04') ? 4 : 0;
                        array_push($buyings, [
                            'id' => $subOrder->mf_txn_id,
                            'code' => sprintf('%06d', $subOrder->mf_fund_code),
                            'amount' => $subOrder->mf_placed_amount, //市值
                            'date' => $subOrder->mf_trade_date,
                            'source' => $orign,  //来源
                        ]);
                    }
                }

                if (isset($shares[$fundId])) {
                    $nav = $shares[$fundId]->mf_nav;
                }
                //else if (isset($navs[$fundId]) && isset($navs[$fundId]->latestValue)){
                //    $nav = $navs[$fundId]->latestValue->fv_nav;
                //} else {
                //    Log::error(sprintf("not find fund latest nav [%d]", $fundId));
                //}

                if (in_array($type, ['024', 'W05'])) {
                    if ($poType == 'P04' && in_array($status, [0])) {
                        $asset = $nav * $subOrder->mf_placed_share;

                        array_push($adjusts, [
                            'id' => $subOrder->mf_txn_id,
                            'code' => sprintf('%06d', $subOrder->mf_fund_code),
                            'share' => $subOrder->mf_placed_share,
                            'amount' => $asset, // 市值
                            'date' => $subOrder->mf_trade_date,
                        ]);
                    }
                }
            }
        }
        $base_msg = __CLASS__ . '@' . __FUNCTION__ . $uid ;
        $mem_use = memory_get_usage()/1024/1024;
        Log::info($base_msg, [14, $mem_use]);

        $yingmi = self::getYingmiPortfolioHolding($uid);
        $mem_use = memory_get_usage()/1024/1024;
        Log::info($base_msg, [15, $mem_use]);

        $yingmiOrder = self::getYingmiRedemmingInfo($uid);
        $mem_use = memory_get_usage()/1024/1024;
        Log::info($base_msg, [16, $mem_use]);

        Log::info($base_msg, [17]);
        $result = [
            'holding' => $holdings,
            'buying' => $buyings,
            'redeeming' => $adjusts,
            'cancel_buying' => $cancelBuying,
            'cancel_redeeming' => $cancelRedeeming,
            'yingmi'           => $yingmi,
            'yingmi_redeeming' => ['amount' => $yingmiOrder],
        ];
        return $result;
    }

    public static function getUserEmptyHoldingInfo()
    {
        $result = [
            'holding' => [],
            'buying' => [],
            'redeeming' => [],
            'cancel_buying' => [],
            'cancel_redeeming' => [],
            'yingmi'           => [],
            'yingmi_redeeming' => ['amount' => 0],
        ];
        return $result;
    }

    public static function getYingmiPortfolioHolding($uid)
    {
        $holding = [];
        $payment = YingmiPaymentMethod::where('yp_uid', $uid)
                 ->where('yp_enabled', 1)
                 ->first(['yp_payment_method_id']);

        if (!$payment) {
            return $holding;
        }

        $shares = YingmiPortfolioShareDetail::with('funds')
                ->where('yp_uid', $uid)
                ->where('yp_payment_method', $payment->yp_payment_method_id)
                ->where('yp_share_asset', '>', 0)
                ->get();

        foreach ($shares as $share) {
            $items = [];
            foreach ($share->funds as $fund) {
                $items[] = [
                    'code' => $fund->ys_fund_code,
                    'share' => $fund->ys_share_avail,
                    'amount' => $fund->ys_asset_total,
                ];
            }
            $holding[$share->yp_portfolio_id] = [
                'list' => $items,
                'lower' => round($share->yp_lower_redeem_ratio, 4),
                'upper' => round($share->yp_higher_redeem_ratio, 4),
                'tag' => $share->yp_can_redeem,
            ];
        }

        return $holding;
    }

    public static function getYingmiRedemmingInfo($uid)
    {
        $order = MfPortfolioTradeStatus::where('mp_uid', $uid)
               ->where('mp_trade_type', 'P04')
               ->whereIn('mp_trade_status', ['P0', 'P1'])
               ->get(['mp_txn_id']);
        if ($order->isEmpty()) {
            return 0;
        }

        $orderIds = $order->keyBy('mp_txn_id')->keys()->toArray();
        $base_msg = __CLASS__ . '@' . __FUNCTION__ . $uid ;
        $mem_use = memory_get_usage()/1024/1024;
        Log::info($base_msg, [14, $mem_use, count($orderIds)]);

        $yorder = YingmiPortfolioTradeStatus::where('yp_uid', $uid)
                ->whereNotNull('yp_mf_txn_id')
                ->whereIn('yp_mf_txn_id', $orderIds)
                ->get(['yp_txn_id']);
        if ($yorder->isEmpty()) {
            return 0;
        }
        $yorderIds = $yorder->keyBy('yp_txn_id')->keys()->toArray();
        $datas = YingmiTradeStatus::where('yt_uid', $uid)
               ->whereIn('yt_portfolio_txn_id', $yorderIds)
               ->where('yt_dealed', 0)
               ->get();
        if ($datas->isEmpty()) {
            return 0;
        }
        $fundIds = $datas->keyBy('yt_fund_id')->keys()->toArray();
        $mem_use = memory_get_usage()/1024/1024;
        Log::info($base_msg, [15, $mem_use, count($orderIds), count($fundIds)]);

        //$values = BnFundInfo::with('latestValue')
        //        ->whereIn('fi_globalid', $fundIds)
        //        ->get();

        //$mem_use = memory_get_usage()/1024/1024;
        //Log::info($base_msg, [16, $mem_use, count($orderIds), count($fundIds)]);

        //$navs = $values->keyBy('fi_globalid');

        $amount = 0;
        foreach ($datas as $data) {
            if ($data->yt_trade_status == 2) {
                $amount += $data->yt_acked_amount;
            } else {
                $value = BnFundValue::where('fv_fund_id', $data->yt_fund_id)
                       ->orderBy('fv_date', 'DESC')
                       ->first();
                if ($value) {
                    $amount += $data->yt_placed_share * $value->fv_nav;
                }
            }
        }

        return $amount;
    }

    /**
     *组合内单只基金购买失败时，调用该接口
     *
     */
    public static function getUserPoTradeCompositionAlter($uid, $risk, $value, $holding, $fund_code)
    {
        $result = TradeStrategyHelper::Alternative($uid, $risk, $holding, $fund_code, $value); // result from zxb

        $result = TradeDoubleCheck::doubleCheck($uid, $holding, $result, 1, $risk); // double check result from syt

        $result = json_encode($result);
        $result = str_replace('fundCode', 'fund_code', $result);
        $result = json_decode($result, true);

        $result = [
            'code' => 20000,
            'message' => 'success',
            'result' => [
                'op_list' => $result
            ],
        ];

        return $result;
    }

    /**
     * 获取用户组合购买、追加、赎回、调仓等信息
     * $op 购买-buy-1，赎回-redeem-2，追加-append-3，定投-invest-4，调仓-adjust-5, 赎回盈米的组合（只用于op_list)-6
     * 赎回盈米的组合有两种情况：1、直接赎回时，赎回到用户的银行卡 2、调仓时，赎回到用户的盈米宝，用这部分钱购买新组合
     * $type 主要用户赎回时，1-用户请求赎回时的展示份额用 2-用户请求赎回时，获取操作列表用
     * $adjust_txn_id:调仓过程中，发起的赎回确认后，用户赎回的钱买组合时，需要传入调仓记录的mf_txn_id.
     */
    public static function getUserPoTradeComposition($uid, $risk, $value = 0, $holding = [], $op = 1, $type=1, $adjust_txn_id=null)
    {
        Log::info("10000:mf_portfolio_fuse", func_get_args());
        $env = env('APP_DEBUG', false);

        if ($op == 1 || $op == 3 || $op == 4) {
            $buy_limit = self::getUserBuyLimit($uid);
            $result = TradeStrategyHelper::matchTrade($uid, $risk, $holding, $value, 0, $op); //result from zxb
            Log::info('10000:mf_portfolio_fuse buy zxb_result=' . json_encode($result));
            $result = TradeDoubleCheck::doubleCheck($uid, $holding, $result, 1, $risk);  // double check result from syt
            Log::info('10000:mf_portfolio_fuse buy syt_result=' . json_encode($result));
            $result = self::formatZxbBuyResult($result);
            // if($env){//only in test env
            //     $mocked_data = [
            //         [
            //             "op"=>1,
            //             "fundCode"=>"000509",
            //             "amount"=>$value*0.5,
            //             "cost"=>0,
            //             "type"=>11101,
            //             "pool"=>111010
            //         ],
            //         [
            //             "op"=>1,
            //             "fundCode"=>"270050",
            //             "amount"=>$value*0.5,
            //             "cost"=>$value*0.5*0.003,
            //             "type"=>11101,
            //             "pool"=>111010
            //         ],

            //     ];
            //     $mocked_data = self::formatZxbBuyResult($mocked_data);
            //     $result = [
            //         'op_list' => $mocked_data,
            //         'limit' => $buy_limit,
            //     ];
            // }else{
            //     $result = [
            //         'op_list' => $result,
            //         'limit' => $buy_limit,
            //     ];
            // }
            $result = [
                'op_list' => $result,
                'limit' => $buy_limit,
            ];

            Log::info('10000:mf_portfolio_fuse buy result= ', $result);
        } elseif ($op == 2) { //获取用户选定银行卡的持有的盈米组合和魔方组合的可赎回比例
            $selected_limit = TradeStrategyHelper::redemptionShare($uid, $risk, $holding);
            Log::info("10000:mf_portfolio_fuse selected_limit=", $selected_limit);
            $discarded_limit = self::getPriorityRedeemRatio($uid);
            Log::info("10000:mf_portfolio_fuse discarded_limit=", $discarded_limit);
            $bak_limit = $discarded_limit;

            if(empty($discarded_limit)){//废弃组合为空时
                $discarded_ratio = 0;
                if($selected_limit['amount'] == 0){//用户新老组合均未购买，理论上不会到此
                    return ['code'=>20001, 'message'=>'portfolio can not redeem'];
                    //$selected_ratio = 0;
                }else{
                    if($value < $selected_limit['lowest']){
                        $selected_ratio = $selected_limit['lowest'];
                    }else if($value > $selected_limit['highest']){
                        $selected_ratio = 1;
                    }else{
                        $selected_ratio = $value;
                    }
                }
                $limit = $selected_limit;
                Log::info("10000:mf_portfolio_fuse selected_ratio=$selected_ratio and limit", $limit);
            }else{
                if($selected_limit['amount'] == 0){
                    //return ['code'=>20001, 'message'=>'portfolio can not redeem'];
                    $selected_ratio = 0;
                     if($value < $discarded_limit['lowest']){
                        $discarded_ratio = $discarded_limit['lowest'];
                    }else if($value > $discarded_limit['highest']){
                        $discarded_ratio = 1;
                    }else{
                         $discarded_ratio = $value;
                     }
                     $limit = $discarded_limit;
                }else{
                    $limit = self::getFinalRedeemLimit($selected_limit, $discarded_limit);
                    $selected_ratio = 0;
                    if ($value <= $limit['lowest']){
                        $discarded_ratio = $discarded_limit['lowest'];
                    }else if($value>$limit['lowest'] && $value<=$limit['lowest_middle']){
                        $discarded_ratio = $value * $limit['amount'] / $discarded_limit['amount'];
                    }else if($value>$limit['lowest_middle'] && $value<=$limit['lower_middle']){
                        $discarded_ratio = 1;
                    }else if ($value>$limit['lower_middle'] && $value<=$limit['higher_middle']){
                        $discarded_ratio = 1;
                        $selected_ratio = $selected_limit['lowest'];
                    }else if ($value>$limit['higher_middle'] && $value<=$limit['highest']){
                        $discarded_ratio = 1;
                        $selected_ratio = ($value * $limit['amount'] - $discarded_limit['amount']) / $selected_limit['amount'];
                    }else{
                        $discarded_ratio = 1;
                        $selected_ratio = 1;
                    }

                }
            }

            $discarded_ratio = round($discarded_ratio, 4);
            $selected_ratio = round($selected_ratio, 4);

            //dd($discarded_ratio, $selected_ratio);
            $discarded_op_list = [];
            $selected_op_list = [];
            $selected_op_list = [];
            $mf_op_list = [];
            if($discarded_ratio > 0){ //获取理财魔方老组合的赎回信息
                foreach ($discarded_limit['details'] as $portfolio_share_id => $ratio_detail){
                    if($type == 1){
                        $ops = self::getYingmiPorfotlioRedeemingInfo($uid, $ratio_detail['portfolio_id'], $ratio_detail['payment_method'], $discarded_ratio);
                        if(isset($ops['code']) && $ops['code'] == 20000 && isset($ops['result'])){
                            $discarded_op_list[$portfolio_share_id] = $ops['result'];
                        }
                    }else{
                        $discarded_op_list[$portfolio_share_id] = ['op'=>6, 'id'=>$ratio_detail['portfolio_id'], 'redemption'=>$discarded_ratio];
                    }
                }
            }

            $c = self::getPaymentInfo($uid);
            if($c){
                $payment_method = $c->yp_payment_method_id;
            }else{
                $payment_method = '';
            }

            if($selected_ratio > 0){ //盈米组合和魔方组合为等比赎回
                $result = TradeStrategyHelper::matchTrade($uid, $risk, $holding, 0, $selected_ratio, $op); // result from zxb
                Log::info('10000:mf_portfolio_fuse redeem zxb_result=' . json_encode($result));
                $result = TradeDoubleCheck::doubleCheck($uid, $holding, $result, 2, $risk); //double check result from syt
                Log::info('10000:mf_portfolio_fuse redeem syt_result=' . json_encode($result));
                $result = self::formatZxbRedeemResult($result);
                foreach ($result as $v){
                    if($v['op'] == 6){
                        if($type == 1){
                            $ops = self::getYingmiPorfotlioRedeemingInfo($uid, $v['id'], $payment_method, $v['redemption']);
                            if(isset($ops['code']) && $ops['code'] == 20000 && isset($ops['result'])){
                                $selected_op_list[$v['id'].':'.$payment_method] = $ops['result'];
                            }
                        }else{
                            $selected_op_list[$v['id'].':'.$payment_method] = ['op'=>6, 'id'=>$v['id'],'redemption'=> $v['redemption']];
                        }
                    }else{
                        $mf_op_list['mf_portfolio'][] = $v;
                    }
                }
            }else{ // 如果赎回没有波及到选定的盈米组合和魔方组合，并且是获取赎回的展示信息
                if($type == 1){
                    $result = TradeStrategyHelper::matchTrade($uid, $risk, $holding, 0, 1, $op); // result from zxb
                    Log::info('10000:mf_portfolio_fuse redeem zxb_result=' . json_encode($result));
                    $result = TradeDoubleCheck::doubleCheck($uid, $holding, $result, 2, $risk);
                    Log::info('10000:mf_portfolio_fuse redeem syt_result=' . json_encode($result));
                    $result = self::formatZxbRedeemResult($result);
                    foreach ($result as $v){
                        if($v['op'] == 6){
                            $ops = self::getYingmiPorfotlioRedeemingInfo($uid, $v['id'], $payment_method, $v['redemption'], 2);
                            if(isset($ops['code']) && $ops['code'] == 20000 && isset($ops['result'])){
                                $tmp1 = $ops['result'];
                                $selected_op_list[$v['id'].':'.$payment_method] = $tmp1;
                            }
                        }else{
                            $v['amount'] = 0;
                            $mf_op_list['mf_portfolio'][] = $v;
                        }
                    }
                }
            }
            Log::info('10000:mf_portfolio_fuse', ['discarded'=>[$discarded_ratio, $discarded_op_list]]);
            $tmp_dol = collect(collect($discarded_op_list)->first())->sum('total_asset');
            Log::info('10000:mf_portfolio_fuse', ['selected' => [$selected_ratio, $selected_op_list]]);
            Log::info('10000:mf_portfolio_fuse', ['mf_op_list'=>$mf_op_list]);
            $tmp_mf = (collect(collect($mf_op_list)->first())->sum('total_asset'));
            $tmp_hol = collect(($holding['holding']))->sum('amount');
            $tmp_sum = $tmp_mf + $tmp_dol;
            Log::info("10000:mf_portfolio_fuse discarded_sum=$tmp_dol, mf_sum=$tmp_mf, discarded_and_mf_sum=$tmp_sum, zz_holding_mf_sum=$tmp_hol");

            Log::info('10000:mf_portfolio_fuse', ['user_percent'=>$value]);
            if($type == 1) {
                $op_lists = [];
                foreach ($discarded_op_list as $vs){
                    foreach ($vs as $v){
                        $op_lists[] = $v;
                    }

                }
                foreach ($selected_op_list as $vs){
                    foreach ($vs as $v){
                        $op_lists[] = $v;
                    }
                }
                foreach ($mf_op_list as $vs){
                    foreach ($vs as $v){
                        $op_lists[] = $v;
                    }
                }

                $co_final = [];
                $cos = collect($op_lists)->groupBy('fund_code');
                foreach ($cos as $fund_code=>$co){
                    $co_final[] = [
                        'op' => 2,
                        'fund_code' => $fund_code,
                        'amount' => $co->sum('amount'),
                        'total_share' => $co->sum('total_share'),
                        'total_asset' => $co->sum('total_asset'),
                        'cost' => $co->sum('cost'),
                    ];
                }
                //dd($co_final);
                $result = [
                    'op_list' => $co_final,
                    'limit' => $limit,
                ];
            }else{
                $result = [
                    'op_list' => array_merge($discarded_op_list, $selected_op_list, $mf_op_list),
                    'limit' => $limit,
                ];
            }

            Log::info('10000:mf_portfolio_fuse redeem result= ', $result);
        } else {//op=5的情况
            if ($value === 0) {//初次调用调仓的时候
                $result = TradeStrategyHelper::matchTrade($uid, $risk, $holding, 0, 0, $op); //result from zxb
                Log::info('10000:mf_portfolio_fuse redeem zxb_result=' . json_encode($result));
                //$result = TradeDoubleCheck::doubleCheck($uid, $holding, $result); // double check result from syt
                //Log::info('10000:mf_portfolio_fuse redeem syt_result=' . json_encode($result));
                $result = self::formatZxbAdjustRedeemResult($result);
                $result = [
                    'op_list' => $result,
                    'limit' => [],
                ];
                Log::info('10000:mf_portfolio_fuse adjust result step 1 = ', $result);
            } else {
                if ($value > 0) {//调仓有基金确认时，张哲的同步脚本发现赎回订单确认时，调用此接口，获取下一步的购买指令。
                    // 注意，需要修改赎回订单对应的mf_dealed，mf_parent_txn_id，方便后续追踪订单状态

                    $result = TradeStrategyHelper::matchTrade($adjust_txn_id, $risk, $holding, $value, 0, $op); //result from zxb
                    Log::info('10000:mf_portfolio_fuse buy_after_redeem_confirm', ['txn'=>$adjust_txn_id, 'risk'=>$risk, 'holding'=>$holding, 'amount'=>$value, 0, 'op'=>$op, 'zxb_result' => $result]);
                    //$result = TradeDoubleCheck::doubleCheck($adjust_txn_id, $holding, $result); // double check result from syt

                    $result = json_encode($result);
                    $result = str_replace('fundCode', 'fund_code', $result);
                    $result = json_decode($result, true);

                    // if($env){//only in test env
                    //     $mocked_data = [
                    //         [
                    //             "op"=>1,
                    //             "fundCode"=>"000509",
                    //             "amount"=>$value*0.5,
                    //             "cost"=>0,
                    //             "type"=>11101,
                    //             "pool"=>111010
                    //         ],
                    //         [
                    //             "op"=>1,
                    //             "fundCode"=>"270050",
                    //             "amount"=>$value*0.5,
                    //             "cost"=>$value*0.5*0.003,
                    //             "type"=>11101,
                    //             "pool"=>111010
                    //         ],

                    //     ];
                    //     $mocked_data = self::formatZxbBuyResult($mocked_data);
                    //     $result = [
                    //         'op_list' => $mocked_data,
                    //         'limit' => [],
                    //     ];
                    // }else{
                    //     $result = [
                    //         'op_list' => $result,
                    //         'limit' => [],
                    //     ];
                    // }
                    $result = [
                        'op_list' => $result,
                        'limit' => [],
                    ];

                    Log::info("10000:mf_portfolio_fuse adjust result step 2 uid=$uid value=$value result=", $result);
                } else { //调仓中的调仓出现时，value<0,此种情况特殊处理, this should never happen
                    return [];
                }
            }
        }
        //dd($result);
        return ['code' => 20000, 'message' => 'success', 'result' => $result];
    }

    /**
     *  获取组合的购买信息展示
     * @param $uid
     * @param $value
     * @param int $type 1:用户点击一键购买按钮时，返回的展示信息 2：用户在购买页面输入金额后，返回的信息
     * @return array
     */
    public static function getPoBuyInfo($uid, $value, $type = 1, $risk_from_web = null)
    {
        $po_info = MfPortfolioInfo::firstOrNew(['mf_uid' => $uid]);
        $po_info->mf_name = '智能组合';// change name when needeed
        $po_info->save();

        $po_comp = self::getUserPoTradeList($uid, $value, 1, $risk_from_web);
        if (!isset($po_comp['code'])) {
            return ['code' => 20001, 'message' => '不可购买，请联系客服'];
        }
        if ($po_comp['code'] != 20000) {
            return $po_comp;
        }
        if (!isset($po_comp['result'])) {
            return ['code' => 20001, 'message' => '无法获取组合购买信息，请联系客服'];
        }

        $limit = $po_comp['result']['limit'];
        if (isset($limit['lowest'])) {
            $start_amount = $limit['lowest'];
        } else {
            $start_amount = 2000;
        }
        $po_comp = $po_comp['result']['op_list'];
        $estimated_fee = round(collect($po_comp)->sum('cost'), 2);

        $payments = self::getUserPaymentDetail($uid);
        if (empty($payments)) {
            $msg = "MfHelper@getPoBuyInfo getUserPaymentDetail return null, $uid";
            Log::error($msg);
            self::sendSms($msg);
            return ['code' => 20001, 'message' => '无法获取购买所需信息，请联系客服'];
        }

        $risk_info = self::getUserRiskPromptInfo($uid, 10);
        $part3_value = self::getUserPoBuyingPoComposition($po_comp, $type);

        // 获取组合限制购买信息
        $portfolio_start_text = $start_amount . "元起购";
        $sub_text = "申购费率约为" . round($estimated_fee * 100 / $value, 2) . "%";
        $sub_text .= "，根据您持有的基金与实际比例进行差额购买";
        $title = self::getPoNameByRisk(self::getUserRiskValue($uid, $risk_from_web));

        if ($type == 1) {
            $result = [
                'risk' => $risk_from_web,
                'title' => '购买配置',
                'id' => $po_info->id,
                'text' => '您现在买入',
                'subtext' => $title,
                'part1' => [
                    'key' => '付款账户',
                    'val' => $payments[0],
                    'opt' => [
                        'caption' => '选择付款账户',
                        'items' => [
                            [
                                'head' => '',
                                'item' => [],
                            ],

                            [
                                'head' => '银行卡',
                                'item' => $payments,
                            ],
                        ],
                    ],
                ],
                'part2' => [
                    [
                        'text' => '买入金额',
                        'hidden_text' => $portfolio_start_text,
                        'sub_text' => $sub_text,
                        'start_amount' => $start_amount
                    ],
                ],
                'part3' => [
                    'title' => ['percent' => '配置比例', 'amount' => '金额(元)'],
                    'value' => $part3_value,
                ],
                'note' => [
                    //'说明: 15:00后完成支付,将按下一个交易日净值确认份额',
                ],
                'button' => '确认购买',
                'bottom' => [
                    '基金交易服务由盈米财富提供',
                    '您的资金将转入盈米宝，并通过盈米宝完成支付',
                    '基金销售资格证号：000000378',
                ],
                'pop_msg' => '盈米宝是一款宝类产品，对应货币基金(国寿安保增金宝货币市场基金，基金代码001826)。用户可以将现金充值到盈米宝中获得国寿安保增金宝货币市场基金份额。盈米宝可以用于每日转接收益，购买其他基金或金融理财产品。',
            ];

            $result = array_merge($result, $risk_info); //    // 'show_risk','risk_text', // 'risk_title'
        } else {
            $delay_msg = '';
            $collect = collect($part3_value);
            $collect = $collect->groupBy('op');
            $delay = $collect->get(11);
            if($delay){
                $names = implode('、', $delay->pluck('name')->toArray());
                $delay_msg .= "；".$names."因为基金本身暂停申购，将为您延迟购买";
            }

            $result = [];
            $result['part1']['title'] = ['percent' => '配置比例', 'amount' => '金额(元)'];
            $result['part1']['value'] = $part3_value;
            $result['part2'] = "预估申购费用" . $estimated_fee . "元".$delay_msg; // 原来此处显示的购买手续费用的估算
            $result['risk'] = $risk_from_web;
        }

        return ['code' => 20000, 'message' => '获取信息成功', 'result' => $result];
    }

    /**
     * @param $comp 组合成分信息
     * @param int $type 1:最终购买前获取购买信息 2:输入购买金额后，实时展示信息
     * @return array
     */
    public static function getUserPoBuyingPoComposition($comp, $type = 1)
    {
        //Log::info('10000:mf_portfolio getUserPoBuyingPoComposition,input', $comp);
        $part3_value = [];
        $comp = collect($comp);
        $total_amount = $comp->sum('amount');
        $cnt = $comp->count();
        //$total_amount = 0;
        $sum_percent = 0;
        foreach ($comp as $k => $value) {
            $fund_info = self::getFundInfoByCode($value['fund_code']);
            if ($type == 1) {
                $amount = '--';
            } else {
                $amount = round($value['amount'], 2);
            }
            $tmp_percent = round($value['amount'] / $total_amount * 100, 2);
            if ($k == ($cnt - 1)) {
                $tmp_percent = round(100 - $sum_percent, 2);
            } else {
                $sum_percent += $tmp_percent;
            }

            $part3_value[] = [
                'name' => $fund_info->fi_name,
                //'percent' => round($value['amount']/$total_amount * 100, 2) . "%",
                'percent' => $tmp_percent . "%",
                'amount' => $amount,
                'code' => $value['fund_code'],
                'id' => $fund_info->fi_globalid,

                'type' => $value['type'],
                'op' => $value['op'],
            ];
        }

        return $part3_value;
    }

    /**
     * 用户赎回组合时，获取赎回信息
     * @param $uid
     * @param int $percent
     * @param int $type 1:获取组合赎回展示信息 2:获取具体的赎回操作列表
     * @param int $first_redeem 1:第一次点击赎回按钮 0:不是第一次点击赎回按钮
     * @return array
     */
    public static function getUserPoRedeemComposition($uid, $percent = 0, $type = 1, $first_redeem = 0)
    {
        $part3_value = [];
        $expected_amount = 0;

        $result = self::getUserPoTradeList($uid, $percent, 2, null, $type); //2表示赎回
        //dd($result);
        if (!isset($result['code'])) {
            return ['code' => 20001, 'message' => '不可赎回，请联系客服'];
        }
        if ($result['code'] != 20000) {
            return $result;
        }

        if (!isset($result['result'])) {
            return ['code' => 20001, 'message' => '无法获取组合赎回信息，请联系客服'];
        }

        $tmp_total_asset = 0;
        $shares = $result['result']['op_list'];
        //dd(collect($shares)->sum('total_asset'));
        if ($type == 1) {
            foreach ($shares as $k => $value) {
                $fund_info = self::getFundInfoByCode($value['fund_code']);
                if ($first_redeem == 1) {
                    $amount = '--';
                } else {
                    //$amount = round($value['amount'] * $percent, 2);
                    $amount = $value['amount'];
                }

                $part3_value[] = [
                    'name' => $fund_info->fi_name,
                    'shares' => round($value['total_share'], 2),
                    'redeem_shares' => round($amount, 2),
                ];
                if ($first_redeem != 1) {
                    $tmp_percent = round($amount, 2) / round($value['total_share'], 2);
                    $tmp_total_asset += $value['total_asset'];
                    $expected_amount = $expected_amount + $value['total_asset'] * $tmp_percent - $value['cost'];
                    Log::info("10000:mf_portfolio_fuse redeem_info percent=$tmp_percent,asset=$tmp_total_asset,expected_amout=$expected_amount");
                }
            }

            $expected_amount = round($expected_amount, 2);
            if (!is_null($percent)) {
                $part3_value['expected_amount'] = $expected_amount;
            }

            $part3_value['limit'] = $result['result']['limit'];
        } else {
            $part3_value = $shares;
        }

        return $part3_value;
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
                13671318228,
            ];
        }
        $attrs = [
            'channel' => 3,
            'stype' => 5,
        ];
        try {
            $sms = SmsService::postMobileSms(13, $mobiles, $msg, $attrs);
            Log::info('10000:mf_portfolio send msg ' . $msg . ' to', $mobiles);
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }

        return true;
    }

    /**
     * 发送短信封装
     * @param $msg 要发送的消息
     * @param array 手机号组成的数组，为空时表示给运营人员和开发人员发短信
     * @return bool
     */
    public static function sendSmsUid($msg, $uids = [])
    {
        if (!is_array($uids)) {
            return false;
        }

        if (empty($uids)) {
            return false;
        }

        $attrs = [
            'channel' => 3,
            'stype' => 5,
        ];
        try {
            $sms = SmsService::postUserSms(13, $uids, $msg, $attrs);
            Log::info('10000:mf_portfolio send msg ' . $msg . ' to', $uids);
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }

        return true;
    }


    /**
     * 发送JPush
     * @param $msg 消息体
     * @param array uid组成的数组，为空表示给张哲发JPush
     * @return bool
     */
    public static function sendJpush($msg, $uids = [])
    {
        if (!is_array($uids)) {
            return false;
        }

        if (empty($uids)) {
            $uids = [
                '1000083515'
            ];
        }

        try {
            $sms = JPushService::postUsers($msg, $uids);
        } catch (\Exception $e) {
            Log::info('10000:mf_portfolio send JPush ' . $msg . ' to', $uids);
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            return false;
        }

        return true;
    }

    /**
     *成功返回  ['code' => 20000, 'message' => 'success'];
     *失败返回  ['code' => 2222x, 'message' => '失败原因'];
     */

    /**
     * 盈米宝充值封装
     * @param $uid
     * @param $sum
     * @param $portfolio_order_id 组合的订单id
     * @param $portfolio_id  组合id
     * @param string $invest_plan_id 定投计划id 为空表示普通购买时的充值 不为空表示定投时的充值
     * @return array 成功是code=20000， 失败时code=2222x，其中code=22222时为盈米返回1129时的情况
     */
    public static function rechargeWallet($uid, $sum, $portfolio_order_id, $portfolio_id, $invest_plan_id = null)
    {
        $timing = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));

        $wallet_fund = self::getWalletFund();
        if (!$wallet_fund) {
            return ['code' => 22221, 'message' => '盈米宝基金不存在'];
        }

        $payment = self::getPaymentInfo($uid);
        if (!$payment) {
            return ['code' => 22223, 'message' => '支付信息不存在'];
        }

        $wallet_order_id = Self::getOrderId();
        $params = [
            'brokerUserId' => $uid,
            'accountId' => $payment->yp_account_id,
            'paymentMethodId' => $payment->yp_payment_method_id,
            'tradeAmount' => $sum,
            'fundCode' => $wallet_fund->yw_fund_code,
            'brokerOrderNo' => $wallet_order_id,
            'isIdempotent' => 1,
            'payTimeoutSec' => 15, //用户充值盈米宝时返回1129，处理超时信息
        ];
        $tmp = YmHelper::rest_rpc('/trade/rechargeWallet', $params, 'post');

        $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $wallet_order_id]);
        if (!(is_array($tmp) && isset($tmp['code']) && $tmp['code'] == 20000 && isset($tmp['result']))) {
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $wallet_fund->yw_fund_id;
            $model->mf_fund_code = sprintf('%06d', $wallet_fund->yw_fund_code);
            $model->mf_fund_name = $wallet_fund->yw_fund_name;
            $model->mf_trade_type = 'W01';
            $model->mf_trade_status = 1;
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_invest_plan_id = $invest_plan_id;
            $model->mf_pay_method = $payment->yp_payment_method_id;

            if (isset($tmp['code']) && isset($tmp['msg'])) {
                if ($tmp['code'] == '1129') { // 1129对应稍后查询支付结果，目前暂时通知运营人员手工处理改情况
                    $model->mf_trade_status = 0; //状态位为0
                    $model->mf_yingmi_order_id = $tmp['detail']['orderId'];
                    $model->mf_error_code = $tmp['code'];
                    $model->mf_error_msg = $tmp['msg'];
                    $model->save();

                    self::updateWalletBuyingAvail($uid, $payment->yp_payment_method_id, $sum, 1);

                    return ['code' => 22222, 'message' => '购买请求已受理，请稍后查询结果。如有疑问，请联系客服',  'result' => ['wallet_order_id' => $wallet_order_id]];
                } else {
                    $model->mf_error_code = $tmp['code'];
                    $model->mf_error_msg = $tmp['msg'];
                    $model->save();

                    return ['code' => $tmp['code'], 'message' => $tmp['msg']];
                }
            } else {
                $model->mf_error_code = -1;
                $model->mf_error_msg = '系统错误';
                $model->save();

                return ['code' => -1, 'message' => '盈米宝充值请求失败'];
            }
        } else {
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $wallet_fund->yw_fund_id;
            $model->mf_fund_code = sprintf('%06d', $wallet_fund->yw_fund_code);
            $model->mf_fund_name = $wallet_fund->yw_fund_name;
            $model->mf_trade_type = 'W01';
            $model->mf_trade_status = 2;
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_trade_date = $tmp['result']['orderTradeDate'];
            $model->mf_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->mf_acked_amount = $sum;
            $model->mf_invest_plan_id = $invest_plan_id;
            $model->mf_yingmi_order_id = $tmp['result']['orderId'];
            $model->mf_pay_method = $payment->yp_payment_method_id;

            $model->save();

            self::updateWalletBuyingAvail($uid, $payment->yp_payment_method_id, $sum, 0);

            return ['code' => 20000, 'message' => '充值成功', 'result' => ['wallet_order_id' => $wallet_order_id]];
        }
    }

    public static function buying(
        $uid,
        $product_id,
        $sum,
        $order_id,
        $portfolio_id,
        $portfolio_order_id,
        $parent_order_id = null,
        $adjust = false
    ) {
        Log::info("10000:mf_porfolio buying params=", func_get_args());
        $payment = self::getPaymentInfo($uid);
        if (!$payment) {
            return ['code' => 20002, 'message' => '支付信息不存在'];
        }

        $wallet_info = self::getWalletShareDetail($uid, $payment->yp_payment_method_id);
        if (!$wallet_info) {
            $alert_msg = "can not get wallet info when buying: $uid, $sum, $order_id";
            Log::info($alert_msg);
            self::sendSms($alert_msg);
        }else{
            self::updateUserWalletShareDetails($uid);
            $wallet_info = self::getWalletShareDetail($uid, $payment->yp_payment_method_id);
        }

        $fund = self::getFundInfo($product_id);

        $params = [
            'brokerUserId' => $uid,
            'accountId' => $payment->yp_account_id,
            'brokerOrderNo' => $order_id,
            'fundCode' => sprintf('%06d', $fund['fi_code']),
            'tradeAmount' => $sum,
            'walletId' => $wallet_info->yw_wallet_id,
            'ignoreRiskGrade' => 1,
            'isIdempotent' => 1,
        ];
        $timing0 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        $tmp = YmHelper::rest_rpc("/trade/buyFund", $params, "post");
        unset($timing0);
        $timing1 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        if (isset($tmp['code']) && $tmp['code'] == '20000' && is_array($tmp['result'])) {
            $timing2 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
            Log::info(__CLASS__ . '@' . __FUNCTION__ ." $order_id  $uid firstOrNew");
            unset($timing2);
            $timing2 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
            $model->mf_parent_txn_id = $parent_order_id;
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $product_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            if (isset($tmp['result']['buyMode']) && $tmp['result']['buyMode'] == 'allot') {
                $model->mf_trade_type = 'W04';
            } else {
                $model->mf_trade_type = 'W04';
            }
            $model->mf_trade_status = 0;
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_trade_date = $tmp['result']['orderTradeDate'];
            $model->mf_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->mf_yingmi_order_id = $tmp['result']['orderId'];
            $model->save();

            //从yw_buying_share_avail中减去此次的购买金额
            if(!$adjust){
                $tmp_wallet_info = YingmiWalletShareDetail::where('yw_uid', $uid)
                    ->where('yw_pay_method', $payment->yp_payment_method_id)
                    ->first();
                if($tmp_wallet_info && isset($tmp['result']['isDuplicated']) && $tmp['result']['isDuplicated']==false) {
                    Log::info(__CLASS__.'@'.__FUNCTION__."wallet detail before", $tmp_wallet_info->toArray());
                    YingmiWalletShareDetail::decBuyAvail($tmp_wallet_info, $sum);
                }else{
                    self::sendSms("yw_buying_share_avail update fail: $uid, $sum, $order_id");
                }
            }else{
                //Artisan::call("yingmi:update_wallet_share", ['uid' => $uid]);
                self::updateUserWalletShareDetails($uid);
            }

            Log::info("10000:mf_porfolio buying after model save=", $model->toArray());
            Log::info(__CLASS__ . '@' . __FUNCTION__ . " $order_id $uid save");
            unset($timing2);
            return ['code' => 20000, 'message' => 'success'];
        } elseif ($tmp['code'] == -1) {
            $timing3 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
            $model->mf_parent_txn_id = $parent_order_id;
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $product_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            $model->mf_trade_status = 7;
            $model->mf_trade_type = 'W04';
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_error_code = -1;
            $model->mf_error_msg = '系统错误';
            $model->save();
            unset($timing3);
            return ['code' => 20008, 'message' => '系统错误'];
        } else {
            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
            $model->mf_parent_txn_id = $parent_order_id;
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $product_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            $model->mf_trade_status = 7;
            $model->mf_trade_type = 'W04';
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_error_code = $tmp['code'];
            $model->mf_error_msg = $tmp['msg'];
            $model->save();

            return ['code' => 20009, 'message' => $tmp['msg']];
        }
    }

    /**
     * 这个函数暂时不用了， 使用ts:set_fund_dividend_method命令来统一于每天5点设置分红方式
     */
    public static function setDividendMethod($uid, $fund_id)
    {
        return true;

        $ymid = self::getYingmiAccount($uid);
        if (!$ymid) {
            return ['code' => 20001, 'message' => '请开户'];
        }

        $fund = self::getFundInfo($fund_id);
        if (!$fund) {
            return ['code' => 20001, 'message' => '无法通过fund_id获取基金信息'];
        }

        $share = self::getFundShareDetail($uid, $fund_id);
        if (!$share) {
            return ['code' => 20001, 'message' => '无法通过fund_id和uid获取基金的share信息'];
        }

        if ($share->mf_div_mode == 0) {
            return ['code' => 20000, 'message' => '已经设置过分红方式-' . $share->mf_div_mode];
        }

        $order_id = self::getOrderId();
        $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
        $model->mf_uid = $uid;
        $model->mf_fund_id = $fund_id;
        $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
        $model->mf_fund_name = $fund['fi_name'];
        $model->mf_trade_type = '029';
        $model->mf_trade_status = 8;
        //$model->mf_account_id = $ymid->yw_account_id;
        $model->mf_div_mod = 0;
        $model->save();

        // call set div mode async
        Artisan::call("mf:set_fund_div_mode", ['--order_id' => $order_id]);
    }

    public static function redeeming(
        $uid,
        $fund_id,
        $share,
        $order_id,
        $portfolio_id,
        $portfolio_order_id,
        $redeem_to_wallet = '0'
    ) {
        $payment = self::getPaymentInfo($uid);
        if (!$payment) {
            return ['code' => 20002, 'message' => '支付信息不存在'];
        }

        // $wallet_info = self::getWalletShareDetail($uid, $payment->yp_payment_method_id);
        // if (!$wallet_info) {
        //         return ['code' => 20006, 'message' => '请更新盈米宝份额信息'];
        // }

        //赎回时不做是否可赎回检查，直接赎回
        $fund = self::getFundInfo($fund_id);
        //if(!$fund){
        // return ['code' => 20007, 'message' => '该基金暂时不可赎回'];
        //}

        // $mf_po_share = MfFundShareDetail::where("mf_uid", $uid)
        //              ->where('mf_fund_id', $fund_id)
        //              ->where("mf_portfolio_id", $portfolio_id)
        //              ->first();
        $mf_po_share = self::getFundShareDetail($uid, $fund_id);
        if (!$mf_po_share) {
            return ['code' => 20007, 'message' => '该基金暂无可赎回份额'];
        }

        if ($redeem_to_wallet != '0') {//0:to card 1:to wallet
            $redeem_to_wallet = '1';
        }


        $params = [
            'brokerUserId' => "$uid",
            'accountId' => $payment->yp_account_id,
            'shareId' => $mf_po_share->mf_share_id,
            'brokerOrderNo' => $order_id,
            'tradeShare' => number_format($share, 2, '.', ''),
            'redeemToWallet' => $redeem_to_wallet,//0:赎回到银行卡,1:赎回到盈米宝
            'isIdempotent' => '1'
        ];
        if ($redeem_to_wallet == '1') {//赎回到盈米宝，需要提供额外的参数
            $params['walletFundCode'] = '001826';
            $trade_type = 'W05';
        } else {
            $trade_type = '024';
        }
        $tmp = YmHelper::rest_rpc("/trade/redeemFund", $params, "post");
        $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
        if ($tmp['code'] == 20000 && is_array($tmp['result'])) {
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $fund_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            $model->mf_trade_type = $trade_type;
            $model->mf_trade_status = 0; //trade_status 全部为0，是为了方便张哲的同步脚本，检查该订单的状态
            $model->mf_placed_share = $params['tradeShare'];
            $model->mf_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_trade_date = $tmp['result']['orderTradeDate'];
            $model->mf_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            //$model->mf_pay_method = $payment->yp_payment_method_id;
            $model->mf_share_id = $params['shareId'];
            $model->mf_redeem_pay_date = $tmp['result']['transferIntoDate'];
            $model->mf_yingmi_order_id = $tmp['result']['orderId'];
            $model->mf_redeem_to_wallet = $params['redeemToWallet'];
            $model->save();
            return ['code' => 20000, 'message' => '赎回成功'];
        } elseif ($tmp['code'] == -1) {
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $fund_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            $model->mf_trade_type = $trade_type;
            $model->mf_trade_status = 0;
            $model->mf_placed_share = $params['tradeShare'];
            //$model->mf_pay_method = $payment->yp_payment_method_id;
            $model->mf_share_id = $params['shareId'];
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_redeem_to_wallet = $params['redeemToWallet'];
            $model->mf_error_code = -1;
            $model->mf_error_msg = '系统错误';
            $model->save();
            return ['code' => 20001, 'message' => '系统错误'];
        } else {
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $fund_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            $model->mf_trade_type = $trade_type;
            $model->mf_trade_status = 1;
            $model->mf_placed_share = $params['tradeShare'];
            $model->mf_share_id = $params['shareId'];
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_share_id = $params['shareId'];
            $model->mf_redeem_to_wallet = $params['redeemToWallet'];
            $model->mf_error_code = $tmp['code'];
            $model->mf_error_msg = $tmp['msg'];
            $model->save();

            try {
                $mobiles = self::getEmergencyMobiles();
                $tmp_msg = "遇到赎回失败，请及时联系张哲进行处理:" . implode(',', [
                        $tmp['code'],
                        $tmp['msg'],
                        $model->uid,
                        $order_id,
                        $fund_id,
                        $fund['fi_code'],
                        $fund['fi_name']
                    ]);
                self::sendSms($tmp_msg, $mobiles);
            } catch (\Exception $e) {
                Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            }

            return ['code' => 20001, 'message' => $tmp['msg']];
        }
    }

    public static function getEmergencyMobiles()
    {
        return [
            13811710773,
            18610562049,
            13671318228,
        ];
    }

    // 用户组合内购买基金失败后，重新购买时，却认购买金额的函数
    public static function getPoAlterAmount($old_record)
    {
        $value = $old_record->mf_placed_amount - $old_record->mf_acked_amount;
        //处理value，应对php极小数

        return $value;
    }

    /**
     * 调用张哲写好的函数
     * 获取用户在mf_deviation_pool中的最新调仓记录，仅仅用在通过前端发起的请求
     * @param $uid
     * @return bool
     */
    public static function getUserPoAdjustStatus($uid)
    {
        $row = MfDeviationPool::where('mf_uid', $uid)
            ->orderBy('id', 'desc')
            ->first();
        if ($row->mf_type == 0) {
            return false;
        } else {
            if ($row->mf_status > 0) {
                return false;
            } else {
                if ($row->mf_autorun == 1) {
                    return false;
                } else {
                    return $row;
                }
            }
        }
    }

    /**
     * 获取用户初次点击调仓按钮时的购买操作列表
     * @param $uid
     * @param int $value 0:第一次点击调仓按钮
     * @param $adjust_txn_id 调仓赎回确认后，用赎回的钱购买组合时传入。为原始的调仓订单txn_id
     * @return array
     */
    public static function getUserPoAdjustTradeList($uid, $value = 0, $adjust_txn_id=null)
    {
        $base_msg = __CLASS__.'@'.__FUNCTION__;
        $risk = self::getUserRiskValue($uid);
        $holding = self::getUserHoldingInfo($uid);
        $po_ori_comp = self::getUserPoTradeComposition($uid, $risk, $value, $holding, 5, 1, $adjust_txn_id);
        $status1 = self::getUserTradeCompositionStatus($po_ori_comp);
        if (!$status1) {
            Log::info('10000:mf_portfolio get null po comp, quiting');
            $result = ['code' => 20001, 'message' => '无法获取组合操作列表', 'result' => []];
        } else {
            $result = ['code' => 20000, 'message' => 'success', 'result' => $po_ori_comp['result']];
        }

        return $result;
    }

    /**
     * 除基金确认失败或部分确认成功的情况之外，获取交易列表
     * op=1,2,3,4 op=5调仓时需要单独处理
     * op=1时，会根据holding的状态，确定最后op=1新购 还是 op=3追加
     */
    public static function getUserPoTradeList($uid, $value, $op, $risk_from_web = null, $type=1)
    {

        //if ($op == 1 || $op == 3 || $op == 4) {
        $risk = self::getUserRiskValue($uid, $risk_from_web);
        Log::info("--------------- uid=$uid, risk=$risk, risk_from_web=$risk_from_web");
        //} else {
        //  $risk = self::getUserRiskValue($uid, $risk_from_web);
        //}

        $holding = self::getUserHoldingInfo($uid);
        $status = self::getUserClearBuyStatus($holding);
        if ($status) {//首次购买或清仓购买 op值不变
        } else {    //非首次购买，追加 op=3
            if ($op == 1) {
                $op = 3;
            }
        }

        $po_ori_comp = self::getUserPoTradeComposition($uid, $risk, $value, $holding, $op, $type);
        $status1 = self::getUserTradeCompositionStatus($po_ori_comp);
        if (!$status1) {
            Log::info('10000:mf_portfolio get null po comp, quiting');
            $result = ['code' => 20001, 'message' => '无法获取组合操作列表', 'result' => []];
        } else {
            $result = ['code' => 20000, 'message' => 'success', 'result' => $po_ori_comp['result']];
        }

        return $result;
    }

    public static function getUserTradeCompositionStatus(&$po_ori_comp)
    {
        if (!(isset($po_ori_comp['code']) && $po_ori_comp['code'] == 20000 && isset($po_ori_comp['result']) && (!empty($po_ori_comp['result'])) && (!is_null($po_ori_comp['result'])))) {
            Log::info('10000:mf_portfolio get null po comp, quiting');
            return false;
        } else {
            return true;
        }
    }

    /**
     *获取用户的购买状态
     *用户首次购买组合   return true
     *清仓后重新购买组合 return true
     *其他             return false
     */
    public static function getUserClearBuyStatus(&$holding)
    {
        if (empty($holding['holding']) && empty($holding['buying']) && empty($holding['redeeming'])) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 用于正常购买或是定投购买组合
     * @param $uid
     * @param $value
     * @param null $invest_plan_id 定投计划的ID
     * @param null $risk_specified 用户指定的risk 1,2,3-10
     * @return array
     */
    public static function buyingPortfolio($uid, $value, $invest_plan_id = null, $risk_specified = null)
    {
        $final = [];
        $wait_flag = false;
        $exception = false;
        $is_debug = env('APP_DEBUG', false);
        $result = [];

        $user_risk_value = self::getUserRiskValueForBuying($uid, $risk_specified); //1,2,3-10

        $po_comps = self::getUserPoTradeComps($uid, $value, $user_risk_value, $invest_plan_id);
        if (!(isset($po_comps['code']) && $po_comps['code'] == 20000)) {
            return $po_comps;
        }
        $po_comps = $po_comps['result'];

        $order_id = self::getOrderId();
        $payment = self::getPaymentInfo($uid);
        $po_info = self::getDefaultPoInfo($uid);

        $default_wallet_detail = self::makeSureWalletDetailExists($uid, $payment->yp_payment_method_id);

        DB::beginTransaction();

        try {
            //step 1: write po order
            $locked_row = YingmiWalletShareDetail::where('yw_uid', $uid)
                        ->where('yw_pay_method', $payment->yp_payment_method_id)
                        ->lockForUpdate()
                        ->first();
            if(!$locked_row){
                Log::info("user buy portfolio lock for update failed ". json_encode(func_get_args()));
                $final = ['code' => 20001, 'message' => '请稍后重试', 'result' => []];
            }else{
                $model = self::genDefaultBuyOrder($uid, $value, $order_id, $user_risk_value, $payment->yp_payment_method_id, $po_info->id, $invest_plan_id);

                //step 2 recharge wallet and wirte order
                $ymb_res = self::rechargeWallet($uid, $value, $order_id, $po_info->id, $invest_plan_id);
                if (!(isset($ymb_res['code']) && ($ymb_res['code'] == 20000 || $ymb_res['code'] == 22222) && isset($ymb_res['result']['wallet_order_id']))) {
                    //盈米宝充值直接返回失败的时候，插入一条状态为P99的组合购买订单,尽量确保组合交易订单的完整性
                    $model->mp_extra = $ymb_res['message'];
                    $model->save();

                    $final = $ymb_res;

                } else {
                    if ($ymb_res['code'] == 22222) {
                        $wait_flag = true;
                    }
                    self::updatePoOrderStatus($model, $wait_flag);

                    $dealed_time = $model->mp_placed_date . " " . $model->mp_placed_time;

                    //step 3 write sub orders to db
                    $sub_orders = self::genBuyingSubOrders($uid, $order_id, $po_info->id, $po_comps, $invest_plan_id);

                    //step 4 execute sub orders
                    if ($wait_flag) {
                        // deal 1129: first send alert message to pp and zz, then mf:deal_1129 auto deal every 5 minutes;
                        // self::sendMsgBuy1129($uid, $order_id, $model, $invest_plan_id);
                    } else {
                        Artisan::call('mf:buy_po', ['--po_order_id' => $order_id, '--wait' => $wait_flag]);
                    }

                    //用户在user_analyze_results中的风险值改变后，mf_portfolio_share中的风险值不变
                    //直到用户购买或定投后，才更新mf_portfolio_share中mp_risk的值为user_analyze_results中的最新风险值
                    $buying_risk = self::updateRiskAfterBuy($uid, $po_info->id, $user_risk_value);

                    if (is_null($invest_plan_id)) {//正常购买受理成功时的后续逻辑
                        self::sendMsgBuySuccess($uid, $value, $dealed_time, $user_risk_value);
                    }

                    $result = self::getBuySuccessResult($uid, $value, $order_id, $dealed_time);

                    Artisan::queue("portfolio:update_order", ['uid'=>$uid]);

                    if (!is_null($invest_plan_id) && $wait_flag) { //定投且盈米宝充值时返回1129,用于后续处理定投逻辑
                        $final = ['code' => 22222, 'message' => '受理成功', 'result' => $result];
                    } else {
                        $final = ['code' => 20000, 'message' => '受理成功', 'result' => $result];
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            self::sendSms("MfHelper@buyingPortfolio failed after add transaction, params=" . json_encode(func_get_args()));
            $final = ['code' => 20001, 'message' => '请稍后重试或联系客服', 'result' => $result];
        }

        DB::commit();

        return $final;

    }

    /**
     * 用于赎回确认后购买新的成分基金,后台执行的任务。不给前端提供服务
     * @param $uid
     * @param $value
     * @param null $invest_plan_id 定投计划的ID
     * @param null $portfolio_txn_id 调仓赎回成功确认后，再次申购基金。mf_portfolio_trade_status中的调仓记录的mp_txn_id
     * @param null $parent_txn_id mf_fund_trade_status中调仓赎回记录对应的mf_txn_id
     * @return array
     */
    public static function buyingPortfolioAdjust($uid, $value, $portfolio_txn_id, $parent_txn_id=null)
    {
        $result = [];

        $base_msg = "10000:mf_portfolio_fuse" . __CLASS__ . "@" . __FUNCTION__. " ". json_encode(func_get_args()).' ';
        Log::info($base_msg);

        $payment = MfHelper::getPaymentInfo($uid);
        if(!$payment){
            Log::info($base_msg . " no master card");
            self::sendSms($base_msg.' no master card.');
            return ['code' => 20001, 'message' => 'no master card'];
        }

        //Artisan::call("yingmi:update_wallet_share", ['uid' => $uid]); // 先更新盈米宝的份额信息
        self::updateUserWalletShareDetails($uid);

        DB::beginTransaction();
        try {
            $wallet_shares = YingmiWalletShareDetail::where('yw_uid', $uid)
                ->where('yw_pay_method', $payment->yp_payment_method_id)
                ->lockForUpdate()
                ->first();
            if(!$wallet_shares){
                Log::error($base_msg."can not get lock for update");
                self::sendSms($base_msg.' exception caught');
                $result = ['code' => 20022, 'message' => $base_msg. ' can not get lock for update'];
            }else{
                $result = self::buyingPortfolioAdjustWithinTxn($uid, $value, $portfolio_txn_id, $parent_txn_id, $wallet_shares, $base_msg);
            }
        } catch (\Exception $e) {
            Log::error(sprintf($base_msg. " Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            self::sendSms($base_msg.' exception caught');
            $result = ['code' => 20002, 'message' => 'Exception caught '.$e->getLine()];
        }

        DB::commit();

        return $result;
    }


    private static function buyingPortfolioAdjustWithinTxn($uid, $value, $portfolio_txn_id, $parent_txn_id, $wallet_shares, $base_msg)
    {
        $share_avail = $wallet_shares->yw_share_avail_total-$wallet_shares->yw_buying_share_avail;

        if ($share_avail < $value) {
            Log::info( $base_msg. "share_avail-buying_avail not enough", $wallet_shares->toArray());
            return ['code' => 20001, 'message' => '盈米宝余额不足'];
        }

        $po_ori_comp = self::getUserPoAdjustTradeList($uid, $value, $portfolio_txn_id); //调仓确认后购买
        if (!isset($po_ori_comp['code'])) {
            self::sendSms($base_msg.' can not get op list.');
            return ['code' => 20001, 'message' => 'can not get op list'];
        }
        if ($po_ori_comp['code'] != 20000) {
            self::sendSms($base_msg.' can get op list failed.');
            return ['code' => 20001, 'message' => 'can not get op list'];
            //return $po_ori_comp;
        }

        if(!isset($po_ori_comp['result']['op_list'])){
            self::sendSms($base_msg.' can op list structure error.');
            return ['code' => 20001, 'message' => 'can op list structure error'];
        }

        $po_ori_comp = $po_ori_comp['result']['op_list'];
        Log::info($base_msg . "buy after redeem confirm op_list=, ", $po_ori_comp);
        //$parent_txn_id = explode(',', $parent_txn_id);
        if(empty($po_ori_comp)){
            if ($value < 1) {
                Log::info($base_msg . "buy after redeem confirm value<1");
                $tmp_parent_txn_id = explode(',', $parent_txn_id);
                self::updateDealedTagAfterAdjustBuy($tmp_parent_txn_id);
            } else {
                self::sendSms($base_msg.' can op list is empty.');
            }

            return ['code' => 20002, 'message' => '调仓时，再次购买op_list为空'];
        }

        $sub_orders = self::genAdjustBuyingSubOrder($uid, $portfolio_txn_id, $po_ori_comp, $parent_txn_id);

        Log::info($base_msg . 'buy po_order=' . $portfolio_txn_id . ' and sub_orders=', $sub_orders);

        $sub_orders = implode(',', $sub_orders);
        Artisan::call('mf:buy_po', ['--po_order_id' => $portfolio_txn_id, '--sub_orders' => $sub_orders, '--adjust_order'=>'yes']);

        //更新订单号为$parent_txn_id的mf_dealed=1，表示已经处理 根据返回状态来决定dealed
        $parent_txn_id = explode(',', $parent_txn_id);
        self::updateDealedTagAfterAdjustBuy($parent_txn_id);

        //Artisan::call("yingmi:update_wallet_share", ['uid' => $uid]);//update yingmibao again
        self::updateUserWalletShareDetails($uid);

        return  ['code' => 20000, 'message' => '受理成功'];
    }

    /**
     * 仅用于组合直接赎回到银行卡
     * @param $uid
     * @param $portfolio_id
     * @param $value
     * @return array
     */
    public static function redeemingPortfolio($uid, $portfolio_id, $value, $account_id)
    {
        $po_info = MfPortfolioInfo::where('mf_uid', $uid)
            ->first();
        if ($po_info) {
            Log::info('10000:mf_portfolio portfolio info  is not empty, can redeem args = ', func_get_args());
        }else{
            Log::info('10000:mf_portfolio portfolio info  is empty, can not redeem creating po info args = ', func_get_args());
            $po_info = new MfPortfolioInfo();
            $po_info->mf_uid = $uid;
            $po_info->save();
        }

        $po_ori_comp = self::getUserPoRedeemComposition($uid, $value, 2, 0);//2表示直接赎回

        //$po_comps = collect($po_ori_comp);
        $order_id = self::getOrderId(); //此次赎回的订单id
        $user_risk_value = round(self::getUserRiskValue($uid)/10, 1);

        $payment = self::getPaymentInfo($uid);
        $model = MfPortfolioTradeStatus::firstOrNew(['mp_txn_id' => $order_id]);
        $model->mp_uid = $uid;
        $model->mp_portfolio_id = $po_info->id;
        $model->mp_risk = $user_risk_value;
        $model->mp_adjustment_id = null;
        $model->mp_trade_type = 'P03';
        $model->mp_trade_status = 'P10';
        $model->mp_placed_percentage = $value;
        $model->mp_placed_date = date('Y-m-d');
        $model->mp_placed_time = date('H:i:s');
        $model->mp_pay_method = $payment->yp_payment_method_id;
        $model->save();
        $place_time = $model->mp_placed_date . ' ' . $model->mp_placed_time;

        //step 4 insert mf_fund_trade_statuses
        $sub_orders = [];
        $sub_status = [];
        //dd($po_ori_comp);
        foreach ($po_ori_comp as $k=>$orders) {
            if($k == 'mf_portfolio'){
                foreach($orders as $order){
                    $sub_order_id = MfHelper::getOrderId();
                    $model1 = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $sub_order_id]);
                    $model1->mf_uid = $uid;
                    $model1->mf_portfolio_txn_id = $order_id;
                    $model1->mf_portfolio_id = $po_info->id;
                    $fund_info = MfHelper::getFundInfoByCode($order['fund_code']);
                    $model1->mf_fund_id = $fund_info->fi_globalid;
                    $model1->mf_fund_code = $order['fund_code'];
                    $model1->mf_fund_name = $fund_info->fi_name;
                    $model1->mf_trade_type = '024'; //赎回到银行卡
                    $model1->mf_trade_status = 8; //未正式向盈米下单
                    $model1->mf_placed_share = $order['amount'];
                    $model1->mf_pay_method = $payment->yp_payment_method_id;
                    $model1->save();
                    $sub_orders[] = $sub_order_id;
                }
            }else{
                //abc
                $r = explode(':', $k);
                $sub_status[] = self::redeemingYingmiPortfolio($uid, $r[0], $value, $account_id, $r[1], $order_id);
            }
        }

        Log::info('10000:mf_portfolio redeem po_order=' . $order_id . ' and sub_orders=', $sub_orders);
        if(!empty($sub_orders)){
            Artisan::call('mf:redeem_po', ['--po_order_id' => $order_id]);
        }

        if(!empty($sub_orders) || !empty($sub_status)){
            $model->mp_trade_status = 'P0';
            $model->save();
        }
        $tel = config('koudai.customer_tel');
        $msg = "尊敬的用户您好，您于" . $place_time . "发起对组合" . $po_info->mf_name
            . "赎回" . ($value * 100)
            . "%的操作已受理，各成分基金赎回款将逐笔到账，您可以登陆APP,进入我的资产->交易记录中查看交易进度。如有疑问请咨询"
            . $tel . "。";
        self::sendSmsUid($msg, [$uid]);

        $remind_text = [];
        $result = [
            'success_pic' => 'http://static.licaimofang.com/wp-content/uploads/2016/07/success.png',
            'success_text' => '您的赎回申请已受理',
            'expected_date' => '请稍后登录APP查看',
            //'text' => '组合交易遵循同卡进出原则，赎回金额将转入您的' . $card_text,
            'text' => '组合交易遵循同卡进出原则，赎回金额将转入您的绑定的银行卡',
            'remind_text' => $remind_text,
        ];

        Artisan::queue("portfolio:update_order", ['uid'=>$uid]);

        return ['code' => 20000, 'message' => 'success', 'result' => $result];

    }

    /**
     * 获取组合定投发起日的中文名称
     * @param $invest_plan model of mf_portfolio_invest_plans
     * @return string 每日 每两周周x 每周周x 每月x日
     */
    public static function getInvestPlanSendDayName($invest_plan)
    {
        if (!$invest_plan) {
            return '--';
        }
        if ($invest_plan->mf_interval == 2) {
            if ($invest_plan->mf_interval_time_unit == 0) {
                return '每日';
            } else {
                if ($invest_plan->mf_interval_time_unit == 1) {
                    if ($invest_plan->mf_send_day == 1) {
                        return '每两周周一';
                    } else {
                        if ($invest_plan->mf_send_day == 2) {
                            return '每两周周二';
                        } else {
                            if ($invest_plan->mf_send_day == 3) {
                                return '每两周周三';
                            } else {
                                if ($invest_plan->mf_send_day == 4) {
                                    return '每两周周四';
                                } else {
                                    if ($invest_plan->mf_send_day == 5) {
                                        return '每两周周五';
                                    } else {
                                        return '--';
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if ($invest_plan->mf_interval_time_unit == 2) {
                        return "每月" . $invest_plan->mf_send_day . "日";
                    } else {
                        return '--';
                    }
                }
            }
        } else {
            if ($invest_plan->mf_interval_time_unit == 0) {
                return '每日';
            } else {
                if ($invest_plan->mf_interval_time_unit == 1) {
                    if ($invest_plan->mf_send_day == 1) {
                        return '每周周一';
                    } else {
                        if ($invest_plan->mf_send_day == 2) {
                            return '每周周二';
                        } else {
                            if ($invest_plan->mf_send_day == 3) {
                                return '每周周三';
                            } else {
                                if ($invest_plan->mf_send_day == 4) {
                                    return '每周周四';
                                } else {
                                    if ($invest_plan->mf_send_day == 5) {
                                        return '每周周五';
                                    } else {
                                        return '--';
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if ($invest_plan->mf_interval_time_unit == 2) {
                        return "每月" . $invest_plan->mf_send_day . "日";
                    } else {
                        return '--';
                    }
                }
            }
        }

    }

    /**
     * 获取用户定投计划相关信息
     * @param $plan model of mf_portfolio_invest_plans
     * @return array
     */
    public static function getInvestPlanInfo($plan)
    {
        $uid = $plan->mf_uid;
        $logtag = "PLAN|PYY|$uid ";
        Log::info($logtag, [$plan]);
        $send_day_name = self::getInvestPlanSendDayName($plan);
        $status = $plan->mf_status;
        if ($status == 'T') {
            $icon = "http://static.licaimofang.com/wp-content/uploads/2016/09/icon.png";
        } else {
            $icon = 'http://static.licaimofang.com/wp-content/uploads/2016/09/portfolio-icon.png';
        }

        $pay_method = $plan->mf_pay_method;
        $pay = YingmiPaymentMethod::withTrashed()
             ->where('yp_payment_method_id', $pay_method)
             ->first();

        if ($plan->mf_pay_type == 1) {
            $bank_info = '魔方宝';
        } elseif ($pay->bank) {
            $bank_info = $pay->bank->yp_name
                . "(尾号"
                . substr($pay->yp_payment_no, -4)
                . ")";
        } else {
            $bank_info =  "(尾号" . substr($pay->yp_payment_no, -4) . ")";
        }

        $item = [
            'status' => $status,
            'id' => $plan->mf_portfolio_id,
            'invest_plan_id' => $plan->mf_invest_plan_id,
            'part1' => [
                'icon' => $icon,
                'portfolio_name' => $plan->info->mf_name,
                'bank_info' => $bank_info,
            ],
            'part2' => [
                ['key' => '每期定投', 'value' => $plan->mf_amount . '元',],
                ['key' => '扣款日期', 'value' => $send_day_name,],
            ],
        ];

        return $item;
    }

    /**
     * 获取用户的组合定投计划可否修改或终止，防止用户操作过于频繁
     * @param $uid
     * @param $id 组合id
     * @return array
     */
    public static function getMoidfyStopStatus($uid, $id)
    {
        if ($uid == 1000000002 || $uid == 1000000006 || $uid == 1000000091) {
            return [
                'can_stop' => true,
                'can_stop_msg' => '',
                'can_modify' => true,
                'can_modify_msg' => '',
            ];
        }

        $can_stop = true;
        $can_stop_msg = '';
        $terminated_start = date("Y-m-d");
        $terminated_end = date('Y-m-d', strtotime("+1 day"));
        $terminated_plans = MfPortfolioInvestPlan::where('mf_uid', $uid)
            ->where('mf_portfolio_id', $id)
            ->where('mf_status', "T")
            ->where('mf_terminate_time', '>=', $terminated_start)
            ->where('mf_terminate_time', '<', $terminated_end)
            ->count();
        if ($terminated_plans >= 3) {
            $can_stop_msg = '您今天的操作过于频繁，请明天再试';
            $can_stop = false;
        }

        $can_modify = true;
        $can_modify_msg = '';
        $modified_plans = MfPortfolioInvestPlan::where('mf_uid', $uid)
            ->where('mf_portfolio_id', $id)
            ->sum('mf_modify_counts');
        if ($modified_plans >= 3) {
            $can_modify_msg = '您今天的操作过于频繁，请明天再试';
            $can_modify = false;
        }

        return [
            'can_stop' => $can_stop,
            'can_stop_msg' => $can_stop_msg,
            'can_modify' => $can_modify,
            'can_modify_msg' => $can_modify_msg
        ];
    }

    /**
     * 调用盈米接口获交易日信息
     * @param $date_time 时间计算基点
     * @param int $offset 时间偏离
     * @return null
     */
    public static function getTradeDate($date_time, $offset = 0, $fund_code = 0)
    {
        if ($offset == 0) {
            if ($fund_code == 0) {
                $params = [
                    'datetime' => $date_time,
                ];
            } else {
                $params = [
                    'datetime' => $date_time,
                    'fundCode' => $fund_code,
                ];
            }

        } else {
            if ($fund_code == 0) {
                $params = [
                    'datetime' => $date_time,
                    'offset' => $offset,
                ];
            } else {
                $params = [
                    'datetime' => $date_time,
                    'fundCode' => $fund_code,
                    'offset' => $offset,
                ];
            }

        }

        $tmp = YmHelper::rest_rpc('/utils/getTradeDate', $params, 'GET');

        $trade_date = null;
        if (isset($tmp['code']) && $tmp['code'] == 20000 && isset($tmp['result']['tradeDate'])) {
            $trade_date = $tmp['result']['tradeDate'];
        }

        return $trade_date;
    }

    /**
     * @param $interval_unit 定投周期
     * @param $interval      每几个周期定投
     * @param $send_day      定投发起日
     * @param $date_time     发起定投的时间点
     * @return $next_trigger_date
     */
    public static function getPoInvestPlanNextTriggerDate($interval_unit, $interval, $send_day, $date_time)
    {
        $time_tag = " 10:10:10";
        $cur_time_carbon = Carbon::createFromFormat("Y-m-d H:i:s", date("Y-m-d", strtotime($date_time)) . $time_tag);
        $cur_time_string = $cur_time_carbon->format('Y-m-d H:i:s');
        $cur_next_time_string = $cur_time_carbon->copy()->addDay()->format('Y-m-d H:i:s');//获取当前日期的下一天

        if ($interval_unit == 0) {//按日定投
            $next_trigger_date = self::getTradeDate($cur_next_time_string);//获取当前日期下一天所属的交易日
        } else {
            if ($interval_unit == 2) {//按月定投
                $next_trade_date = self::getTradeDate($cur_next_time_string);//获取当前日期下一天所属的交易日
                $send_day = sprintf("%02d", $send_day);
                $ori_trigger_date_string = $cur_time_carbon->format("Y-m") . "-$send_day" . $time_tag;
                $ori_trigger_trade_date = self::getTradeDate($ori_trigger_date_string);
                $t1 = Carbon::createFromFormat("Y-m-d", $next_trade_date);
                $t2 = Carbon::createFromFormat("Y-m-d", $ori_trigger_trade_date);
                $tmp = $t1->diffInDays($t2, false); //means t2-t1
                if ($tmp >= 0) {
                    $next_trigger_date = $ori_trigger_trade_date;
                } else {
                    $tmp = Carbon::createFromFormat('Y-m-d H:i:s',
                        $ori_trigger_date_string)->addMonth()->format("Y-m-d H:i:s");
                    $next_trigger_date = self::getTradeDate($tmp);
                }
                Log::info("10000:mf_portfolio $cur_time_string and  next_trigger_date = $next_trigger_date");
            } else {//按周定投
                $next_trade_date = self::getTradeDate($cur_next_time_string);//获取当前日期下一天所属的交易日
                $ori_trigger_date_string = $cur_time_carbon->copy()->startOfWeek()->addDays($send_day - 1)->toDateString() . $time_tag;
                $ori_trigger_trade_date = self::getTradeDate($ori_trigger_date_string);
                $t1 = Carbon::createFromFormat("Y-m-d", $next_trade_date);
                $t2 = Carbon::createFromFormat("Y-m-d", $ori_trigger_trade_date);
                $tmp = $t1->diffInDays($t2, false); //means t2-t1
                if ($tmp >= 0) {
                    $next_trigger_date = $ori_trigger_trade_date;
                } else {
                    //不管是每周定投还是每两周定投，若当前日期下一天所属交易日大于等于当周定投日所属交易日，均在下一个周的定投日所属交易扣款
                    $tmp = Carbon::createFromFormat('Y-m-d H:i:s',
                        $ori_trigger_date_string)->addWeek()->format("Y-m-d H:i:s");
                    $next_trigger_date = self::getTradeDate($tmp);
                }

                Log::info("10000:mf_portfolio $cur_time_string and  next_trigger_date = $next_trigger_date");
            }
        }

        return $next_trigger_date;
    }

    /**
     * @param $interval_unit
     * @param $interval
     * @param $send_day
     * @param $date_time 定投执行的时间
     * @return null
     */
    public static function getPoInvestPlanNextTriggerDateAfterInvest($interval_unit, $interval, $send_day, $date_time)
    {
        $time_tag = " 10:10:10";
        $cur_time_carbon = Carbon::createFromFormat("Y-m-d H:i:s", date("Y-m-d", strtotime($date_time)) . $time_tag);
        $cur_next_time_string = $cur_time_carbon->copy()->addDay()->format('Y-m-d H:i:s');//获取当前日期的下一天

        if ($interval_unit == 0) {//按日定投
            $next_trigger_date = self::getTradeDate($cur_next_time_string);//获取当前日期下一天所属的交易日
        } else {
            if ($interval_unit == 2) {//按月定投
                $send_day = sprintf("%02d", $send_day);
                $ori_trigger_date_string = $cur_time_carbon->format("Y-m") . "-$send_day" . $time_tag;
                $tmp = Carbon::createFromFormat('Y-m-d H:i:s',
                    $ori_trigger_date_string)->addMonth()->format("Y-m-d H:i:s");
                $next_trigger_date = self::getTradeDate($tmp);
            } else {//按周定投
                $tmp = $cur_time_carbon->copy()->startOfWeek()->addDays($send_day - 1)->addWeeks($interval)->format("Y-m-d") . $time_tag;
                $next_trigger_date = self::getTradeDate($tmp);
            }
        }

        return $next_trigger_date;
    }

    //
    // 获取预估调仓接口
    //
    public static function getUserPoAdjustEstimateTradeList($uid, $value = 0)
    {
        $risk = self::getUserRiskValue($uid);
        $holding = self::getUserHoldingInfo($uid);

        $po_ori_comp = TradeStrategyHelper::simulateTrade($uid, $risk, $holding, $value, 0, 5);
        $po_ori_comp =  TradeDoubleCheck::doubleCheck($uid, $holding, $po_ori_comp, 5, $risk);

        return $po_ori_comp;
    }

    public static function testQueue()
    {
        $job = (new TestQueue())->delay(2)->onQueue('high');
        Bus::dispatch($job);
    }

    public static function formatRisk($risk)
    {
        $risk = round($risk);

        if ($risk > 10) {
            $risk = 10;
        } elseif ($risk < 1) {
            $risk = 1;
        }

        return round($risk / 10, 1);
    }

    public static function getPoNameByRisk($risk)
    {
        $risk = round($risk);

        return sprintf("智能组合 等级%s", $risk);
    }


    public static function getWechatBindStatus($uid)
    {
        $result = false;

        $row = WechatUser::where('uid', $uid)->first();
        if($row){
            $result = true;
        }

        return $result;

    }

    /**
     * 获取用户的可选银行卡列表
     * @param $uid
     * @return array
     */
    public static function getPaymentMethodList($uid)
    {
        $payments = [];
        $message = '千人千面版本目前只支持<b style="color:#e98229">单张银行卡</b>进行交易,请选择一张您的银行卡作为主卡。以后新的交易将在主卡上进行,基金赎回将优先赎回未选中卡上持有的基金份额。';
        $enabled = YingmiPaymentMethod::where('yp_uid', $uid)
                ->where('yp_enabled', 1)
                ->first();
        if($enabled){
            $payments[] = [
                'id' => $enabled->yp_payment_method_id,
                'name' => $enabled->bank->yp_name
                . "(尾号"
                . substr($enabled->yp_payment_no, -4)
                . ")",
                'val' => self::getPoHoldingInfo($enabled->yp_payment_method_id),
                'icon' => $enabled->bank->yp_icon,
            ];
        }else{
            $payment_rows = YingmiPaymentMethod::where('yp_uid', $uid)->get();
            foreach ($payment_rows as $payment) {
                $payments[] = [
                    'id' => $payment->yp_payment_method_id,
                    'name' => $payment->bank->yp_name
                    . "(尾号"
                    . substr($payment->yp_payment_no, -4)
                    . ")",
                    'val' => self::getPoHoldingInfo($payment->yp_payment_method_id),
                    'icon' => $payment->bank->yp_icon,
                ];
            }
        }

        return ['payments' =>$payments, 'message'=>$message];
    }

    public static function getPoRiskByPoId($id)
    {
        return substr($id, -1) + 1;
    }

    /**
     * 获取用户某张银行卡的持仓文字信息
     * @param $payment_method
     * @return string
     */
    public static function getPoHoldingInfo($payment_method)
    {
        $result = "该卡未持有资产";

        $rows = YingmiPortfolioShareDetail::where('yp_payment_method', $payment_method)
            ->where('yp_total_asset', '>', 0)
            ->orderBy('yp_total_asset', 'desc')
            ->get();
        foreach ($rows as $k => $row) {
            $risk = self::getPoRiskByPoId($row->yp_portfolio_id);
            $asset = round($row->yp_total_asset, 2);
            $name = self::getPoNameByRisk($risk);


            if($k == 0){
                $result = "该卡持有<br/>".$name." (".$asset."元)";
            }else{
                $result .= "<br/>".$name." (".$asset."元)";
            }
        }

        return $result;
    }

    /**
     * 是否给用户展示 选择银行的页面
     * 目前只基于一个状态进行判断
     * @param $uid
     * @return int 1:显示 0:不显示
     */
    public static function getCardEnableStatus($uid)
    {
        $result = 1;

        $row = YingmiPaymentMethod::where('yp_uid', $uid)
            ->where('yp_enabled', 1)
            ->first();
        if ($row) {
            $result = 0;
        }

        return $result;
    }

    /**
     * 获取用户未启用的银行卡
     * @param $uid
     * @return mixed
     */
    public static function getDisabledCards($uid)
    {
        $rows = YingmiPaymentMethod::where('yp_uid', $uid)
            ->where('yp_enabled', 0)
            ->get();

        return $rows;
    }

    /**
     * 老组合用户选定一张卡后，计算用户的加权风险值
     * @param $uid
     * @return int
     */
    public static function getUserWeightedRisk($uid, $payment_method=null)
    {
        if(is_null($payment_method)){
            $card = self::getPaymentInfo($uid);
            if (!$card) {
                return null;
            }

            $payment_method = $card->yp_payment_method_id;
        }

        $weighted_risk = null;
        $rows = YingmiPortfolioShareDetail::where('yp_payment_method', $payment_method)
            ->where('yp_uid', $uid)
            ->groupBy('yp_portfolio_id')
            ->selectRaw("yp_portfolio_id as id, sum(yp_total_asset) as asset")
            ->get();
        $asset = $rows->sum("asset");
        if ($asset <= 0) { //如果选定的卡没有持仓，按照原来的风险逻辑返回
            //return null;
            return self::getUserRiskValue($uid);
        }

        foreach ($rows as $row) {
            $weighted_risk += $row->asset / $asset * self::getPoRiskByPoId($row->id);
        }

        return round($weighted_risk);
    }

    /**
     * 获取用户废弃银行卡持有的盈米组合的赎回比例
     *
     * @param $uid
     * @return array
     */
    public static function getPriorityRedeemRatio($uid)
    {
        $result = [];
        //$result['lowest'] = null;
        //$result['highest'] = null;

        $enabled = self::getPaymentInfo($uid);
        if (!$enabled) {
            return $result;
        }

        $disabled = self::getDisabledCards($uid);
        if ($disabled->isEmpty()) {
            return $result;
        }

        $disabled_pays = $disabled->pluck('yp_payment_method_id')->toArray();
        $share_details = YingmiPortfolioShareDetail::whereIn('yp_payment_method', $disabled_pays)
            ->where('yp_can_redeem', 1)
            ->get();
        if ($share_details->isEmpty()) {
            return $result;
        }

        foreach ($share_details as $share) {
            $tmp_id = $share->yp_portfolio_id.':'.$share->yp_payment_method;
            $ratios[$tmp_id] = self::getYingmiRedeemRatio($uid, $share->yp_portfolio_id,
                $share->yp_payment_method);
            $ratios[$tmp_id]['amount'] = $share->yp_total_asset;
            $ratios[$tmp_id]['portfolio_id'] = $share->yp_portfolio_id;
            $ratios[$tmp_id]['payment_method'] = $share->yp_payment_method;
        }

        $result = self::getRedeemRatioRange($ratios);
        $result['details'] = $ratios;

        return $result;
    }

    public static function getRedeemRatioRange($ratios)
    {
        $co = collect($ratios);

        $lower_ratio = $co->max('lowest');

        $co_tmp = $co->filter(function ($item) use ($lower_ratio) {
            return $item['highest'] >= $lower_ratio;
        });
        $higher_ratio = $co_tmp->min('highest');

        return [
            'lowest' => $lower_ratio,
            'highest' => $higher_ratio,
            'amount' => $co->sum('amount'),
        ];
    }

    public static function getDefaultYingmiRedeemRatio()
    {
        $result = [];
        $result['lowest'] = null;
        $result['highest'] = null;
        $result['lower_amount'] = null;
        $result['higher_amount'] = null;
        $result['max_ratio'] = null;
        $result['max_amount'] = null;
        $result['payment_method'] = null;

        return $result;
    }

    public static function getYingmiRedeemRatio($uid, $id, $payment_method)
    {
        $result = [
            'lowest' => 1,
            'highest' => 1,
        ];
        $row = YingmiPortfolioShareDetail::where('yp_uid', $uid)
             ->where('yp_portfolio_id', $id)
             ->where('yp_payment_method', $payment_method)
             ->first();
        if($row){
            $result = [
                'lowest' => $row->yp_lower_redeem_ratio,
                'highest' => $row->yp_higher_redeem_ratio,
            ];
        }

        return $result;
    }

    /**
     * 获取总的赎回比例
     * @param $selected
     * @param $discarded
     * @return array
     */
    public static function getFinalRedeemLimit($selected, $discarded)
    {
        $limit = [];

        $total_amount = ($discarded['amount'] + $selected['amount']);
        $lowest = $discarded['lowest'] * $discarded['amount'] / $total_amount;
        $lowest_middle = $discarded['highest'] * $discarded['amount'] / $total_amount;
        $lower_middle = $discarded['amount']/$total_amount;
        $higher_middle = ($discarded['amount'] + $selected['lowest'] * $selected['amount']) / $total_amount;
        $highest = ($discarded['amount'] + $selected['highest'] * $selected['amount']) / $total_amount;

        $limit['lowest'] = round($lowest, 4); // 废弃卡的赎回下限
        $limit['lowest_middle'] = round($lowest_middle,4); // 废弃卡的赎回上限
        $limit['lower_middle'] = round($lower_middle,4); // 废弃卡的全部赎回比例
        $limit['higher_middle'] = round($higher_middle,4); //（废弃卡全部赎回+选定卡的最低赎回）的赎回下限
        $limit['highest'] = round($highest, 4); //（废弃卡全部赎回+选定卡的最低赎回）的赎回上限
        $limit['amount'] = $total_amount;
        $limit['selected'] = $selected;
        $limit['discarded'] = $discarded;
        Log::info(['descarded'=>$discarded, 'selected'=>$selected, 'final'=>$limit]);
        //dd($selected, $limit);
        return $limit;
    }

    /**
     *@to 1:to bank card 2:to ying mi bao for adjust
     */
    public static function redeemingYingmiPortfolio($uid, $portfolio_id, $percent, $account_id, $payment_method, $mf_portfolio_txn_id, $to=0)
    {
        $order_id = YmHelper::getTradeId();
        $info = YingmiPortfolioInfo::where("id", $portfolio_id)->first();
        if(!$info){
            return ['code'=>20001, 'message'=>'组合id不正确'];
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
        $model = YingmiPortfolioTradeStatus::firstOrNew(['yp_txn_id' => $order_id]);
        if (!(isset($tmp_result['code']) && $tmp_result['code'] == 20000)) {
            $model->yp_uid = $uid;
            $model->yp_mf_txn_id = $mf_portfolio_txn_id;
            $model->yp_portfolio_id = $portfolio_id;
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            $model->yp_trade_type = 'P03';
            $model->yp_placed_percentage = $percent;
            $model->yp_placed_date = date('Y-m-d');
            $model->yp_placed_time = date('H:i:s');
            $model->yp_trade_date = date('Y-m-d');
            $model->yp_account_id = $account_id;
            $model->yp_redeem_to_wallet = $to;
            $model->yp_pay_method = $payment_method;
            $model->yp_pay_type = 2;
            $model->yp_pay_status = 1;
            $model->yp_flag = 1;
            if (isset($tmp_result['code']) && isset($tmp_result['msg'])) {
                $model->yp_error_code = $tmp_result['code'];
                $model->yp_error_msg = $tmp_result['msg'];
                $model->yp_trade_status = 'P99';
            } else {
                $model->yp_error_code = -1;
                $model->yp_error_msg = '系统错误';
                $model->yp_trade_status = 'P0';
            }
            $model->save();

            return ['code'=>20002, 'message'=>$model->yp_error_msg];
        } else {
            $model->yp_uid = $uid;
            $model->yp_mf_txn_id = $mf_portfolio_txn_id;
            $model->yp_portfolio_id = $portfolio_id;
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            $model->yp_trade_type = 'P03';
            $model->yp_trade_status = 'P0';
            $model->yp_placed_percentage = $percent;
            $model->yp_placed_date = date('Y-m-d', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_placed_time = date('H:i:s', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_trade_date = $tmp_result['result']['orderTradeDate'];
            $model->yp_acked_date = $tmp_result['result']['orderExpectedConfirmDate'];
            $model->yp_redeem_pay_date = $tmp_result['result']['transferIntoDate'];
            $model->yp_account_id = $account_id;
            $model->yp_redeem_to_wallet = $to;
            $model->yp_pay_type = 1;
            $model->yp_pay_method = $payment_method;
            $model->yp_pay_status = 0;
            $model->yp_flag = 1;
            $model->yp_yingmi_order_id = $tmp_result['result']['orderId'];
            $model->save();

            return ['code'=>20000, 'message'=>'success'];
        }
    }

    /**
     * @type 1:赎回波及到用户选定的盈米组合和魔方组合时
     */
    public static function getYingmiPorfotlioRedeemingInfo($uid, $portfolio_id, $payment_method, $percent, $type=1)
    {
        //$timing = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        $payment_rows = YingmiPaymentMethod::where('yp_uid', $uid)
            ->where('yp_payment_method_id', $payment_method)
            ->orderBy('created_at', 'desc')
            ->get();
        if ($payment_rows->isEmpty()) {
            return ['code' => 20001, 'message' => '开户未绑卡,请绑卡后重试'];
        }

        $shares = YingmiShareDetail::where('ys_uid', $uid)
            ->where('ys_portfolio_id', $portfolio_id)
            ->where('ys_pay_method', $payment_method)
            ->get();
        if ($shares->isEmpty()) {
            return ['code' => 20002, 'message' => '该组合尚未确认'];
        }
        $part3_vale = [];
        foreach ($shares as $k => $value) {
            if($value->ys_share_avail>0){
                $tmp1 = $value->ys_share_avail;
                if($type == 1){
                    $tmp2 = round($value->ys_share_avail * $percent, 2);
                }else{
                    $tmp2 = 0;
                }

                $asset_avail = $value->ys_share_avail*$value->ys_nav;
                $part3_vale[] = [
                    'op' => 2,
                    'fund_code' => $value->ys_fund_code,
                    'amount' => $tmp2,
                    'total_share' => $tmp1, //为了统一
                    'total_asset' => round($asset_avail, 2),
                    //'cost' => round($asset_avail*0.005),
                    'cost' => 0,
                ];
            }
        }

        return ['code' => 20000, 'message' => '查询成功', 'result' => $part3_vale];
    }

    public static function adjustYingmiPortfolio($uid, $id, $account_id, $payment_method, $mf_portfolio_txn_id)
    {
        $final_order_id = YmHelper::getTradeId();

        $wallet_id = 0;
        $wallet_id_row = YingmiWalletShareDetail::where('yw_uid', $uid)
                       ->where('yw_pay_method', $payment_method)
                       ->first();
        if ($wallet_id_row) {
            $wallet_id = $wallet_id_row->yw_wallet_id;
        }

        $info = YingmiPortfolioInfo::where('id', $id)->first();

        $detail = YingmiPortfolioShareDetail::where("yp_uid", $uid)
            ->where('yp_portfolio_id', $id)
            ->where('yp_payment_method', $payment_method)
            ->first();

        $params = [
            'accountId' => $account_id,
            'brokerUserId' => $uid,
            'poCode' => $id,
            'paymentMethodId' => $payment_method,
            'brokerOrderNo' => $final_order_id,
        ];
        $tmp_result = YmHelper::rest_rpc('/trade/adjustPoShare', $params, 'POST');
        $model = YingmiPortfolioTradeStatus::firstOrNew(['yp_txn_id' => $final_order_id]);
        if (!(isset($tmp_result['code']) && $tmp_result['code'] == 20000)) {
            $flag = false;
            $model->yp_uid = $uid;
            $model->yp_mf_txn_id = $mf_portfolio_txn_id;
            $model->yp_portfolio_id = $id;
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            $model->yp_trade_type = 'P04';
            $model->yp_placed_date = date('Y-m-d');
            $model->yp_placed_time = date('H:i:s');
            $model->yp_trade_date = date('Y-m-d');
            $model->yp_account_id = $account_id;
            $model->yp_pay_method = $payment_method;
            $model->yp_pay_wallet = $wallet_id;
            $model->yp_share_id = $detail->yp_portfolio_share_id;
            $model->yp_flag = 1;
            if (isset($tmp_result['code']) && isset($tmp_result['msg'])) {
                $model->yp_error_code = $tmp_result['code'];
                $model->yp_error_msg = $tmp_result['msg'];
                $model->yp_trade_status = 'P1';
            } else {
                $model->yp_error_code = -1;
                $model->yp_error_msg = '系统错误';
                $model->yp_trade_status = 'P0';
            }
            $model->save();
            return ['code'=>20001, 'message'=>$model->yp_error_msg];
        } else {
            $model->yp_uid = $uid;
            $model->yp_mf_txn_id = $mf_portfolio_txn_id;
            $model->yp_portfolio_id = $id;
            $model->yp_adjustment_id = $info->yp_adjustment_id;
            $model->yp_trade_type = 'P04';
            $model->yp_trade_status = 'P0';
            $model->yp_placed_date = date('Y-m-d', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_placed_time = date('H:i:s', strtotime($tmp_result['result']['orderCreatedOn']));
            $model->yp_trade_date = $tmp_result['result']['orderTradeDate'];
            $model->yp_account_id = $account_id;
            $model->yp_pay_method = $payment_method;
            $model->yp_pay_wallet = $wallet_id;
            $model->yp_share_id = $detail->yp_portfolio_share_id;
            $model->yp_flag = 1;
            $model->yp_yingmi_order_id = $tmp_result['result']['orderId'];
            $model->yp_pay_method = $payment_method;
            $model->save();

            $detail->yp_can_adjust = 0;
            $detail->save();

            return ['code'=>20001, 'message'=>'success'];
        }
    }

    public static function redeemWallet($uid, $account_id, $wallet_id, $sum, $wallet_order_id, $txn_id = '')
    {
        $timing = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        Log::info("10000:mf_portfolio_fuse ".__CLASS__." ". __FUNCTION__ ." args=", func_get_args());
        $params = [
            'brokerUserId' => $uid,
            'accountId' => $account_id,
            'walletId' => $wallet_id,
            'tradeShare' => $sum,
            'brokerOrderNo' => $wallet_order_id,
            'isIdempotent' => 1,
        ];
        $tmp = YmHelper::rest_rpc('/trade/redeemWallet', $params, 'post');
        $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $wallet_order_id]);
        if (!(is_array($tmp) && isset($tmp['code']) && $tmp['code'] == 20000 && isset($tmp['result']))) {
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $txn_id;
            $model->mf_fund_id = 0;
            $model->mf_fund_code = sprintf('%06d', 0);
            $model->mf_fund_name = 0;
            $model->mf_trade_type = 'W03';
            $model->mf_trade_status = 1;
            $model->mf_placed_amount = $sum;
            $model->mf_placed_share = $sum;
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_trade_date = date('Y-m-d');
            //$model->mf_pay_method = $wallet_id;
            //$model->mf_pay_currency = 156;
            $model->mf_pay_status = 1;
            //$model->mf_flag = 1;
            //$model->mf_share_type = 'A';
            //$model->mf_account_id = $account_id;
            if (isset($tmp['code']) && isset($tmp['msg'])) {
                $model->mf_error_code = $tmp['code'];
                $model->mf_error_msg = $tmp['msg'];
            } else {
                $model->mf_error_code = -1;
                $model->mf_error_msg = '系统错误';
            }
            $model->save();

            return ['code' => 20001, 'message' => '盈米宝提现失败', 'result' => $tmp];
        } else {
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $txn_id;
            $model->mf_fund_id = 0;
            $model->mf_fund_code = sprintf('%06d', 0);
            $model->mf_fund_name = 0;
            $model->mf_trade_type = 'W03';
            $model->mf_trade_status = 0;
            $model->mf_placed_amount = $sum;
            $model->mf_placed_share = $sum;
            $model->mf_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_trade_date = $tmp['result']['orderTradeDate'];
            $model->mf_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->mf_redeem_pay_date = $tmp['result']['transferIntoDate'];
            $model->mf_acked_amount = 0;
            //$model->mf_pay_method = $wallet_id;
            //$model->mf_pay_currency = 156;
            $model->mf_pay_status = 2;
            //$model->mf_flag = 0;
            //$model->mf_share_type = 'A';
            //$model->mf_account_id = $account_id;
            $model->mf_yingmi_order_id = $tmp['result']['orderId'];
            $model->save();

            return $tmp;
        }
    }

    /**
     * @param $uid
     * @param $mp_txn_id
     */
    public static function getAdjustBuyingOrder($uid, $mp_txn_id)
    {
        $rows = MfFundTradeStatus::where('mf_uid', $uid)
            ->where('mf_portfolio_txn_id', $mp_txn_id)
            ->where('mf_trade_type', 'W04')
            ->where('mf_trade_status', 0)
            ->get();

        return $rows;

    }

    /**
     * @param $uid
     * @param $mp_txn_id
     * @return mixed
     */
    public static function getAdjustRedeemingOrder($uid, $mp_txn_id)
    {
        $rows = MfFundTradeStatus::where('mf_uid', $uid)
            ->where('mf_portfolio_txn_id', $mp_txn_id)
            ->where('mf_trade_type', 'W05')
            ->where('mf_trade_status', 0)
            ->get();

        return $rows;
    }

    /**
     * @param $txn_id
     * @return mixed
     */
    public static function cancelFundOrder($txn_id)
    {
        $base = __CLASS__."@".__FUNCTION__." ";
        $fail_result = ['code'=>20001, 'message'=>'can not cancel', 'result'=>['id'=>$txn_id,'status'=>1, 'amount'=>0]];

        $order = MfFundTradeStatus::where('mf_txn_id', $txn_id)
            ->where('mf_trade_status', 0)
            ->first();
        if(!$order){
            Log::info("$base $txn_id is not right or can not cancel");
            return $fail_result;
        }

        $account = self::getYingmiAccount($order->mf_uid);
        if(!$account){
            Log::info("$base $txn_id do not have yingmi account");
            return $fail_result;
        }

        $cancel_amount = 0;
        $params = [
            'brokerUserId' => $order->mf_uid,
            'accountId' => $account->ya_account_id,
            'brokerOrderNo' => $order->mf_txn_id,
            'isIdempotent' => 1,
        ];
        $result = YmHelper::rest_rpc("/trade/cancelFundOrder", $params, "post");
        Log::info("$base cancel result", $result);
        if (isset($result['code']) && $result['code']==20000) {// cancel success
            if($order->mf_trade_type == 'W04'){
                $cancel_amount = $order->mf_placed_amount;
            }
            $order->mf_trade_status = 9;
            $order->save();

            return ['code'=>20000, 'message'=>'success', 'result'=>['id'=>$txn_id, 'parent_id'=>$order->mf_parent_txn_id, 'status'=>9, 'amount'=>$cancel_amount]];
        }else{

            return $fail_result;
        }
    }

    public static function updateWalletBuyingAvail($uid, $payment_method, $sum)
    {
        $result = false;

        $wallet_info = YingmiWalletShareDetail::where('yw_uid', $uid)
            ->where('yw_pay_method', $payment_method)
            ->first();
        if($wallet_info){
            YingmiWalletShareDetail::incBuyAvail($wallet_info, $sum);
            $result = true;
        }else{
            self::sendSms("yw_buying_share_avail recharge update fail: $uid $sum $payment_method");
        }

        return $result;
    }

    public static function updateUserWalletShareDetails($uid)
    {
        $ymId = YmHelper::getYmId($uid);
        if (!$ymId) {
            Log::error('missing Yingmi account', ['uid' => $uid]);
            return false;
        }

        $params = [
            'brokerUserId' => $uid,
            'accountId' => $ymId,
        ];
        $tmp = YmHelper::rest_rpc("/trade/getWalletShares", $params, "get");
        if($tmp['code'] == '20000' && is_array($tmp['result'])){
            foreach($tmp['result'] as $row){
                self::saveWalletShareDetail($uid, $ymId, $row);
            }
        }else{
            Log::warning('yingmi update wallet share error:'.__CLASS__.' line:'.__LINE__);
        }
    }

    /**
     * @param $txn_id
     * @param $base_msg
     * @return bool
     */
    public static function buyPoFund($txn_id)
    {
        $base_msg = "10000:mf_portfolio_fuse " . __CLASS__ . "@" . __FUNCTION__ . " args=".json_encode(func_get_args()). ' ';
        $valid_status = [7, 8, 11];
        $row = MfFundTradeStatus::where('mf_txn_id', $txn_id)
            ->where('mf_trade_type', 'W04')
            ->whereIn('mf_trade_status', $valid_status)
            ->first();
        if (!$row) {
            Log::error($base_msg . "Please provide correct mf_txn_id type=W04 status=".json_encode($valid_status));
            return false;
        }

        $po_order = MfPortfolioTradeStatus::where('mp_txn_id', $row->mf_portfolio_txn_id)->first();
        if ($po_order->mp_trade_type == 'P04') {
            $ajust_tag = true;
        } else {
            $ajust_tag = false;
        }

        MfHelper::updateUserWalletShareDetails($row->mf_uid);

        $res = MfHelper::buyingWithoutDcreaseWalletBuyingAavail($row->mf_uid, $row->mf_fund_id, $row->mf_placed_amount, $row->mf_txn_id, $row->mf_portfolio_id, $row->mf_portfolio_txn_id, $row->mf_parent_txn_id, $ajust_tag);
        if (isset($res['code']) && $res['code'] == 20000 && !$ajust_tag){
            //如果是直接购买的订单， 延迟购买成功后修改mf_direct_buy
            $row->mf_direct_buy = 0;
            $row->save();
        }
        MfHelper::updateUserWalletShareDetails($row->mf_uid);

        Log::info($base_msg, $res);

        return true;
    }

    public static function buyingPortfolioDirectlyWithYingmibao($uid, $value, $invest_plan_id = null, $risk_specified = null)
    {
        $final = [];
        $exception = false;
        $is_debug = env('APP_DEBUG', false);
        $result = [];

        $user_risk_value = self::getUserRiskValueForBuying($uid, $risk_specified); //1,2,3-10

        $po_comps = self::getUserPoTradeComps($uid, $value, $user_risk_value, $invest_plan_id);
        if (!(isset($po_comps['code']) && $po_comps['code'] == 20000)) {
            return $po_comps;
        }
        $po_comps = $po_comps['result'];

        $order_id = self::getOrderId();
        $payment = self::getPaymentInfo($uid);
        $po_info = self::getDefaultPoInfo($uid);

        $default_wallet_detail = self::makeSureWalletDetailExists($uid, $payment->yp_payment_method_id);

        DB::beginTransaction();

        try {
            //step 1: write po order
            $locked_row = YingmiWalletShareDetail::where('yw_uid', $uid)
                ->where('yw_pay_method', $payment->yp_payment_method_id)
                ->lockForUpdate()
                ->first();
            if(!$locked_row){
                Log::info("user buy portfolio lock for update failed ". json_encode(func_get_args()));
                $final = ['code' => 20001, 'message' => '请稍后重试', 'result' => []];
            }else{
                $model = self::genDefaultBuyOrder($uid, $value, $order_id, $user_risk_value, $payment->yp_payment_method_id, $po_info->id, $invest_plan_id);
                self::updatePoOrderStatus($model);
                $dealed_time = $model->mp_placed_date . " " . $model->mp_placed_time;

                //step 3 write sub orders to db
                $sub_orders = self::genBuyingSubOrders($uid, $order_id, $po_info->id, $po_comps, $invest_plan_id);

                //step 4 execute sub orders
                Artisan::call('mf:buy_po', ['--po_order_id' => $order_id]);

                //用户在user_analyze_results中的风险值改变后，mf_portfolio_share中的风险值不变
                //直到用户购买或定投后，才更新mf_portfolio_share中mp_risk的值为user_analyze_results中的最新风险值
                $buying_risk = self::updateRiskAfterBuy($uid, $po_info->id, $user_risk_value);

                if (is_null($invest_plan_id)) {//正常购买受理成功时的后续逻辑
                    //self::sendMsgBuySuccess($uid, $value, $dealed_time, $user_risk_value);
                }

                $result = self::getBuySuccessResult($uid, $value, $order_id, $dealed_time);

                if (!is_null($invest_plan_id)) { //定投且盈米宝充值时返回1129,用于后续处理定投逻辑
                    $final = ['code' => 22222, 'message' => '受理成功', 'result' => $result];
                } else {
                    $final = ['code' => 20000, 'message' => '受理成功', 'result' => $result];
                }
            }
        } catch (\Exception $e) {
            Log::error(sprintf("Caught exception: %s\n%s", $e->getMessage(), $e->getTraceAsString()));
            self::sendSms("MfHelper@buyingPortfolioDirectlyWithYingmibao failed after add transaction, params=" . json_encode(func_get_args()));
            $final = ['code' => 20001, 'message' => '请稍后重试或联系客服', 'result' => $result];
        }

        DB::commit();

        return $final;
    }

    /**
     *  该函数只能用于响应Koudai-web的RPC请求
     * @param $uid
     * @param $value
     * @param int $type
     * @param null $risk_from_web
     * @return array
     */
    public static function getPoBuyInfoForKoudaiWeb($uid, $value, $type = 1, $risk_from_web = null)
    {
        $po_info = MfPortfolioInfo::firstOrNew(['mf_uid' => $uid]);
        $po_info->mf_name = '智能组合';// hange name when needeed
        $po_info->save();

        $po_comp = self::getUserPoTradeListForKoudaiWeb($uid, $value, 1, $risk_from_web);
        if (!isset($po_comp['code'])) {
            return ['code' => 20001, 'message' => '不可购买，请联系客服'];
        }
        if ($po_comp['code'] != 20000) {
            return $po_comp;
        }
        if (!isset($po_comp['result'])) {
            return ['code' => 20001, 'message' => '无法获取组合购买信息，请联系客服'];
        }

        $limit = $po_comp['result']['limit'];
        if (isset($limit['lowest'])) {
            $start_amount = $limit['lowest'];
        } else {
            $start_amount = 2000;
        }
        $po_comp = $po_comp['result']['op_list'];
        $estimated_fee = round(collect($po_comp)->sum('cost'), 2);

        $payments = self::getUserPaymentDetail($uid);
        $risk_info = self::getUserRiskPromptInfo($uid, 10);
        $part3_value = self::getUserPoBuyingPoComposition($po_comp, $type);

        // 获取组合限制购买信息
        $portfolio_start_text = $start_amount . "元起购";
        $sub_text = "申购费率约为" . round($estimated_fee * 100 / $value, 2) . "%";

        $title = self::getPoNameByRisk(self::getUserRiskValue($uid, $risk_from_web));

        if ($type == 1) {
            $result = [
                'risk' => $risk_from_web,
                'title' => '购买配置',
                'id' => $po_info->id,
                'text' => '您现在买入',
                'subtext' => $title,
                'part1' => [
                    'key' => '付款账户',
                    'val' => $payments[0],
                    'opt' => [
                        'caption' => '选择付款账户',
                        'items' => [
                            [
                                'head' => '',
                                'item' => [],
                            ],

                            [
                                'head' => '银行卡',
                                'item' => $payments,
                            ],
                        ],
                    ],
                ],
                'part2' => [
                    [
                        'text' => '买入金额',
                        'hidden_text' => $portfolio_start_text,
                        'sub_text' => $sub_text,
                        'start_amount' => $start_amount
                    ],
                ],
                'part3' => [
                    'title' => ['percent' => '配置比例', 'amount' => '金额(元)'],
                    'value' => $part3_value,
                ],
                'note' => [
                    //'说明: 15:00后完成支付,将按下一个交易日净值确认份额',
                ],
                'button' => '确认购买',
                'bottom' => [
                    '基金交易服务由盈米财富提供',
                    '您的资金将转入盈米宝，并通过盈米宝完成支付',
                    '基金销售资格证号：000000378',
                ],
                'pop_msg' => '盈米宝是一款宝类产品，对应货币基金(国寿安保增金宝货币市场基金，基金代码001826)。用户可以将现金充值到盈米宝中获得国寿安保增金宝货币市场基金份额。盈米宝可以用于每日转接收益，购买其他基金或金融理财产品。',
            ];

            $result = array_merge($result, $risk_info); //    // 'show_risk','risk_text', // 'risk_title'
        } else {
            $delay_msg = '';
            $collect = collect($part3_value);
            $collect = $collect->groupBy('op');
            $delay = $collect->get(11);
            if($delay){
                $names = implode('、', $delay->pluck('name')->toArray());
                $delay_msg .= "；".$names."因为基金本身暂停申购，将为您延迟购买";
            }

            $result = [];
            $result['part1']['title'] = ['percent' => '配置比例', 'amount' => '金额(元)'];
            $result['part1']['value'] = $part3_value;
            $result['part2'] = "预估申购费用" . $estimated_fee . "元".$delay_msg; // 原来此处显示的购买手续费用的估算
            $result['risk'] = $risk_from_web;
        }

        return ['code' => 20000, 'message' => '获取信息成功', 'result' => $result];
    }

    /**
     *  该函数只能用于响应Koudai-web的RPC请求
     * @param $uid
     * @param $value
     * @param $op
     * @param null $risk_from_web
     * @param int $type
     * @return array
     */
    public static function getUserPoTradeListForKoudaiWeb($uid, $value, $op, $risk_from_web = null, $type=1)
    {

        //if ($op == 1 || $op == 3 || $op == 4) {
        $risk = self::getUserRiskValue($uid, $risk_from_web);
        Log::info("--------------- uid=$uid, risk=$risk, risk_from_web=$risk_from_web");
        //} else {
        //  $risk = self::getUserRiskValue($uid, $risk_from_web);
        //}

        //$holding = self::getUserHoldingInfo($uid);
        $holding = self::getUserEmptyHoldingInfo();
        $status = self::getUserClearBuyStatus($holding);
        if ($status) {//首次购买或清仓购买 op值不变
        } else {    //非首次购买，追加 op=3
            if ($op == 1) {
                $op = 3;
            }
        }

        $po_ori_comp = self::getUserPoTradeCompositionForKoudaiWeb($uid, $risk, $value, $holding, $op, $type);
        $status1 = self::getUserTradeCompositionStatus($po_ori_comp);
        if (!$status1) {
            Log::info('10000:mf_portfolio get null po comp, quiting');
            $result = ['code' => 20001, 'message' => '无法获取组合操作列表', 'result' => []];
        } else {
            $result = ['code' => 20000, 'message' => 'success', 'result' => $po_ori_comp['result']];
        }

        return $result;
    }

    /**
     * 该函数只能用于响应Koudai-web的RPC请求
     * @param $uid
     * @param $risk
     * @param int $value
     * @param array $holding
     * @param int $op
     * @param int $type
     * @param null $adjust_txn_id
     * @return array
     */
    public static function getUserPoTradeCompositionForKoudaiWeb($uid, $risk, $value = 0, $holding = [], $op = 1, $type=1, $adjust_txn_id=null)
    {
        $base_msg = "10000:mf_portfolio_fuse_from_web ";
        Log::info($base_msg, func_get_args());
        $env = env('APP_DEBUG', false);
        $result = [];
        if ($op == 1 || $op == 3 || $op == 4) {
            $buy_limit = self::getUserBuyLimit($uid);
            $result = TradeStrategyHelper::matchTrade($uid, $risk, $holding, $value, 0, $op); //result from zxb
            Log::info($base_msg.'zxb_result=' . json_encode($result));
            $result = TradeDoubleCheck::doubleCheck($uid, $holding, $result, 1, $risk);  // double check result from syt
            Log::info($base_msg.'syt_result=' . json_encode($result));
            $result = self::formatZxbBuyResult($result);

            // if ($env) {//only in test env
            //     $mocked_data = self::getPoBuyMockDataForDev($value);
            //     $mocked_data = self::formatZxbBuyResult($mocked_data);
            //     $result = [
            //         'op_list' => $mocked_data,
            //         'limit' => $buy_limit,
            //     ];
            // }else{
            //     $result = [
            //         'op_list' => $result,
            //         'limit' => $buy_limit,
            //     ];
            // }
            $result = [
                'op_list' => $result,
                'limit' => $buy_limit,
            ];
        } else {
            $result = [];
        }

        Log::info($base_msg. ' return result= ', $result);
        return ['code' => 20000, 'message' => 'success', 'result' => $result];
    }

    public static function getPoBuyMockDataForDev($value)
    {
        return [
            [
                "op"=>1,
                "fundCode"=>"000509",
                "amount"=>$value*0.5,
                "cost"=>0,
                "type"=>11101,
                "pool"=>111010
            ],
            [
                "op"=>1,
                "fundCode"=>"270050",
                "amount"=>$value*0.5,
                "cost"=>$value*0.5*0.003,
                "type"=>11101,
                "pool"=>111010
            ],
        ];
    }

    public static function genSingleFundBuyingOrder($uid, $fund_code, $portfolio_txn_id, $po_id, $amount, $parent_txn_id=null)
    {
        $sub_order_id = self::getOrderId();
        $fund_info = self::getFundInfoByCode($fund_code);
        $buy_type = 8; //means 未正式向盈米下单

        $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $sub_order_id]);
        $model->mf_uid = $uid;
        $model->mf_portfolio_txn_id = $portfolio_txn_id;
        $model->mf_parent_txn_id = $parent_txn_id; //tod change mf_parent_txn_id column type
        $model->mf_portfolio_id = $po_id;
        $model->mf_fund_id = $fund_info->fi_globalid;
        $model->mf_fund_code = $fund_code;
        $model->mf_fund_name = $fund_info->fi_name;
        $model->mf_trade_type = 'W04';
        $model->mf_trade_status = $buy_type;
        $model->mf_placed_amount = $amount;
        Log::info(__FUNCTION__." generated fund buying order result=", ['model'=>$model->toArray(), 'input'=>func_get_args()]);

        $model->save();

        return $sub_order_id;
    }

    public static function buyingWithoutDcreaseWalletBuyingAavail(
        $uid,
        $product_id,
        $sum,
        $order_id,
        $portfolio_id,
        $portfolio_order_id,
        $parent_order_id = null,
        $adjust = false
    ) {
        Log::info("10000:mf_porfolio buying params=", func_get_args());
        $payment = self::getPaymentInfo($uid);
        if (!$payment) {
            return ['code' => 20002, 'message' => '支付信息不存在'];
        }

        $wallet_info = self::getWalletShareDetail($uid, $payment->yp_payment_method_id);
        if (!$wallet_info) {
            $alert_msg = "can not get wallet info when buying: $uid, $sum, $order_id";
            Log::info($alert_msg);
            self::sendSms($alert_msg);
        }else{
            self::updateUserWalletShareDetails($uid);
            $wallet_info = self::getWalletShareDetail($uid, $payment->yp_payment_method_id);
        }

        $fund = self::getFundInfo($product_id);

        $params = [
            'brokerUserId' => $uid,
            'accountId' => $payment->yp_account_id,
            'brokerOrderNo' => $order_id,
            'fundCode' => sprintf('%06d', $fund['fi_code']),
            'tradeAmount' => $sum,
            'walletId' => $wallet_info->yw_wallet_id,
            'ignoreRiskGrade' => 1,
            'isIdempotent' => 1,
        ];
        $timing0 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        $tmp = YmHelper::rest_rpc("/trade/buyFund", $params, "post");
        unset($timing0);
        $timing1 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
        if (isset($tmp['code']) && $tmp['code'] == '20000' && is_array($tmp['result'])) {
            $timing2 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
            Log::info(__CLASS__ . '@' . __FUNCTION__ ." $order_id  $uid firstOrNew");
            unset($timing2);
            $timing2 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
            $model->mf_parent_txn_id = $parent_order_id;
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $product_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            if (isset($tmp['result']['buyMode']) && $tmp['result']['buyMode'] == 'allot') {
                $model->mf_trade_type = 'W04';
            } else {
                $model->mf_trade_type = 'W04';
            }
            $model->mf_trade_status = 0;
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_placed_time = date('H:i:s', strtotime($tmp['result']['orderCreatedOn']));
            $model->mf_trade_date = $tmp['result']['orderTradeDate'];
            $model->mf_acked_date = $tmp['result']['orderExpectedConfirmDate'];
            $model->mf_yingmi_order_id = $tmp['result']['orderId'];
            $model->save();

            //从yw_buying_share_avail中减去此次的购买金额
            if(!$adjust){
                $tmp_wallet_info = YingmiWalletShareDetail::where('yw_uid', $uid)
                    ->where('yw_pay_method', $payment->yp_payment_method_id)
                    ->first();
                if($tmp_wallet_info && isset($tmp['result']['isDuplicated']) && $tmp['result']['isDuplicated']==false) {
                    Log::info(__CLASS__.'@'.__FUNCTION__."wallet detail before", $tmp_wallet_info->toArray());
                    //YingmiWalletShareDetail::decBuyAvail($tmp_wallet_info, $sum);
                }else{
                    self::sendSms("yw_buying_share_avail update fail: $uid, $sum, $order_id");
                }
            }else{
                //Artisan::call("yingmi:update_wallet_share", ['uid' => $uid]);
                self::updateUserWalletShareDetails($uid);
            }

            Log::info("10000:mf_porfolio buying after model save=", $model->toArray());
            Log::info(__CLASS__ . '@' . __FUNCTION__ . " $order_id $uid save");
            unset($timing2);
            return ['code' => 20000, 'message' => 'success'];
        } elseif ($tmp['code'] == -1) {
            $timing3 = new Timing(sprintf('%s@%s', basename_class(__CLASS__), __FUNCTION__));
            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
            $model->mf_parent_txn_id = $parent_order_id;
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $product_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            $model->mf_trade_status = 7;
            $model->mf_trade_type = 'W04';
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_error_code = -1;
            $model->mf_error_msg = '系统错误';
            $model->save();
            unset($timing3);
            return ['code' => 20008, 'message' => '系统错误'];
        } else {
            $model = MfFundTradeStatus::firstOrNew(['mf_txn_id' => $order_id]);
            $model->mf_parent_txn_id = $parent_order_id;
            $model->mf_uid = $uid;
            $model->mf_portfolio_txn_id = $portfolio_order_id;
            $model->mf_portfolio_id = $portfolio_id;
            $model->mf_fund_id = $product_id;
            $model->mf_fund_code = sprintf('%06d', $fund['fi_code']);
            $model->mf_fund_name = $fund['fi_name'];
            $model->mf_trade_status = 7;
            $model->mf_trade_type = 'W04';
            $model->mf_placed_amount = $sum;
            $model->mf_placed_date = date('Y-m-d');
            $model->mf_placed_time = date('H:i:s');
            $model->mf_error_code = $tmp['code'];
            $model->mf_error_msg = $tmp['msg'];
            $model->save();

            return ['code' => 20009, 'message' => $tmp['msg']];
        }
    }
}
