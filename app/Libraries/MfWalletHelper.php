<?php

namespace App\Libraries;

use App\Libraries\YmSdk\YmHelper;
use App\MfFundTradeStatus;
use App\YingmiAccount;
use Log;

class MfWalletHelper
{
    public static function updateWalletOrder($wallet_txn_id = null)
    {
        $fail = ['code' => 20001, 'message' => 'failed', 'result' => []];

        $row = MfFundTradeStatus::where('mf_txn_id', $wallet_txn_id)
            ->first();
        if ($row) {
            $old = $row->mf_pay_status;
            $ym_account = YingmiAccount::where('ya_uid', $row->mf_uid)->first();
            if (!$ym_account) {
                return $fail;
            }

            $params = [
                'brokerUserId' => $row->mf_uid,
                'accountId' => $ym_account->ya_account_id,
                'brokerOrderNo' => $row->mf_txn_id,
            ];
            $result = YmHelper::rest_rpc('/trade/getWalletOrder', $params, 'GET');
            if (isset($result['code']) && $result['code'] == 20000) {
                if (isset($result['result']['payStatus'])) {
                    $row->mf_pay_status = $result['result']['payStatus'];
                }

                if (isset($result['result']['confirmStatus'])) {
                    $row->mf_trade_status = $result['result']['confirmStatus'];
                }

                if (isset($result['result']['orderTradeDate'])) {
                    $row->mf_trade_date = $result['result']['orderTradeDate'];
                }

                if (isset($result['result']['orderConfirmDate'])) {
                    $row->mf_acked_date = $result['result']['orderConfirmDate'];
                }

                if (isset($result['result']['fundName'])) {
                    $row->mf_fund_name = $result['result']['fundName'];
                }

                if (isset($result['result']['errorMessage'])) {
                    $row->mf_error_msg = $result['result']['errorMessage'];
                }
                $row->save();

                return ['code' => 20000, 'message' => 'success', 'result' => ['old'=>$old, 'new'=>$row->mf_pay_status]];
            } else {
                return $fail;
            }
        } else {
            return $fail;
        }
    }
}