<?php namespace App\Libraries\YmSdk;

use Carbon\Carbon;

use DB;
use Log;
use App\BnFundInfo;
use App\YingmiWalletShare;
use App\YingmiWalletShareDetail;
use App\Libraries\YmSdk\YmHelper;

trait YingmiWalletShareUpdater
{
    public function updateWalletShare($uid, $fundId)
    {
        $columns = [
            'yw_uid',
            'yw_account_id',
            'yw_fund_id',
            'yw_fund_code',
            'yw_fund_name',
            DB::raw('SUM(yw_share_avail_total) as yw_share_avail_total'),
            DB::raw('SUM(yw_withdraw_share_avail) AS yw_withdraw_share_avail'),
            DB::raw('SUM(yw_output_share_avail) AS yw_output_share_avail'),
        ];

        //
        // 根据detail统计Share信息
        //
        $row = YingmiWalletShareDetail::where('yw_uid', $uid)
             ->where('yw_fund_id', $fundId)
             ->first($columns);
        if (!$row) {
            YingmiWalletShare::where('yw_uid', $uid)->where('yw_fund_id', $fundId)->delete();
            return;
        }

        //
        // 获取基金信息
        //
        //$fi = BnFundInfo::findOrDefault($fundId, 'dummy');

        $data = [
            'yw_uid' => $uid,
            'yw_account_id' => $row->yw_account_id,
            'yw_fund_id' => $row->yw_fund_id,
            'yw_fund_code' => $row->yw_fund_code,
            'yw_fund_name' => $row->yw_fund_name,
            'yw_share_avail_total' => $row->yw_share_avail_total,
            'yw_withdraw_share_avail' => $row->yw_withdraw_share_avail,
            'yw_output_share_avail' => $row->yw_output_share_avail,
        ];

        $share = YingmiWalletShare::firstOrNew(['yw_uid' => $uid, 'yw_fund_id' => $fundId]);
        $share->fill($data);
        $share->save();
    }

    public function updateWalletShareDetail($uid, $row)
    {
        $checkCols = [
            'ys_uid', 'ys_account_id'
        ];

        $walletId = $row['walletId'];
        $fundCode = $row['fundCode'];
        $paymentMethod = $row['paymentMethodId'];

        $xtab = [
            'yw_fund_code' => 'fundCode',
            'yw_fund_name' => 'fundName',
            'yw_pay_method' => 'paymentMethodId',
            'yw_share_avail_total' => ['key' => 'totalAvailShare', 'fmt' => 2],
            'yw_withdraw_share_avail' => ['key' => 'withdrawAvailShare', 'fmt' => 2],
            'yw_output_share_avail' => ['key' => 'outputAvailShare', 'fmt' => 2],
        ];

        //
        // 根据基金code加载基金ID和名称
        //
        $fi = BnFundInfo::findByCode($fundCode, 'dummy');
        $ymId = YmHelper::getYmId($uid);

        $shareData = [
            'yw_uid' => $uid,
            'yw_account_id' => $ymId,
            'yw_wallet_id' => $walletId,
            'yw_fund_id' => $fi->fi_globalid,
            'yw_fund_code' => $fundCode,
        ];

        foreach ($xtab as $k => $vv) {
            if (is_array($vv)) {
                $v = $vv['key'];
                $fmt = $vv['fmt'];
            } else {
                $v = $vv;
                $fmt = 0;
            }

            if (isset($row[$v]) && $row[$v]) {
                if (!$fmt) {
                    $shareData[$k] = $row[$v];
                } else {
                    $shareData[$k] = number_format((double)$row[$v], $fmt, '.', '');
                }
            }
        }

        //$share = YingmiWalletShareDetail::where('yw_wallet_id', $walletId)->first();
        $share = YingmiWalletShareDetail::where('yw_uid', $uid)
            ->where('yw_pay_method', $paymentMethod)
            ->first();
        if ($share) {
            //
            // 进行必要的安全性检查
            //
            $failed = false;
            foreach ($checkCols as $c) {
                if ($share->{$c} != $shareData[$c]) {
                    Log::error('column value mismatch: ', [
                        "share.$c"  => $share->{$c},
                        "info.$c" => $shareData[$c],
                        'yw_id' => $share->id,
                        'line' => $line,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return [$uid, $fi->fi_globalid, $walletId];
            }

            //
            // 更新订单信息
            //
            $share->fill($shareData);
        } else {
            $share = new YingmiWalletShareDetail($shareData);
        }

        $share->save();

        return [$uid, $fi->fi_globalid, $walletId];
    }
}
