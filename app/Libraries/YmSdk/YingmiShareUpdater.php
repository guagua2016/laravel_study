<?php namespace App\Libraries\YmSdk;

use Carbon\Carbon;

use DB;
use Log;
use App\BnFundInfo;
use App\BnFundValue;
use App\YingmiShare;
use App\YingmiShareDetail;

use App\MfPortfolioInfo;
use App\YingmiPortfolioShare;
use App\YingmiPortfolioShareDetail;
use App\YingmiPortfolioTradeStatus;
use function App\Libraries\array_dict;
use App\Jobs\UpdateYingmiHoldingOnShareChanged;
use App\Jobs\UpdateYingmiHoldingDetailOnShareDetailChanged;
use App\YingmiTradeStatus;

use App\Libraries\DirtyDumper;

use function App\Libraries\latest_trade_date;

trait YingmiShareUpdater
{
    public function updateShare($uid, $fundId, $poId = 0)
    {

        $columns = [
            'ys_uid',
            'ys_portfolio_id',
            'ys_fund_id',
            DB::raw('SUM(ys_share_total) as ys_share_total'),
            DB::raw('SUM(ys_share_avail) AS ys_share_avail'),
            DB::raw('SUM(ys_share_frozen) AS ys_share_frozen'),
            DB::raw('SUM(ys_asset_total) AS ys_asset_total'),
            DB::raw('SUM(ys_principal) AS ys_principal'),
            'ys_nav',
            'ys_nav_date',
            DB::raw('SUM(ys_yield) AS ys_yield'),
            'ys_yield_date',
            DB::raw('SUM(ys_yield_estimated) AS ys_yield_estimated'),
            'ys_yield_estimated_date',
            DB::raw('SUM(ys_yield_uncarried) AS ys_yield_uncarried'),
            DB::raw('SUM(ys_yield_accumulated) AS ys_yield_accumulated'),
            'ys_div_mode',
        ];

        //
        // 根据detail统计Share信息
        //
        $row = YingmiShareDetail::where('ys_uid', $uid)
             ->where('ys_portfolio_id', $poId)
             ->where('ys_fund_id', $fundId)
             ->first($columns);
        if ($row->ys_uid === null) {
            return;
        }

        //
        // 获取基金信息
        //
        $fi = BnFundInfo::findOrDefault($fundId, 'dummy');

        $data = [
            'ys_uid' => $uid,
            'ys_portfolio_id' => $poId,
            'ys_fund_id' => $fundId,
            'ys_fund_code' => $fi->fi_code,
            'ys_fund_name' => $fi->fi_name,
            'ys_fund_type' => $fi->fi_type,
            'ys_share_total' => $row->ys_share_total,
            'ys_share_avail' => $row->ys_share_avail,
            'ys_share_frozen' => $row->ys_share_frozen,
            'ys_asset_total' => $row->ys_asset_total,
            'ys_principal' => $row->ys_principal,
            'ys_nav' => $row->ys_nav,
            'ys_nav_date' => $row->ys_nav_date,
            'ys_yield' => $row->ys_yield,
            'ys_yield_date' => $row->ys_yield_date,
            'ys_yield_estimated' => $row->ys_yield_estimated,
            'ys_yield_estimated_date' => $row->ys_yield_estimated_date,
            'ys_yield_uncarried' => $row->ys_yield_uncarried,
            'ys_yield_accumulated' => $row->ys_yield_accumulated,
            'ys_div_mode' => $row->ys_div_mode,
        ];

        $share = YingmiShare::where('ys_uid', $uid)
            ->where('ys_portfolio_id', $poId)
            ->where('ys_fund_id', $fundId)->first();

        if ($share) {
            $share->fill($data);
            //
            // 防止覆盖最新的收益和累计收益
            //
            if ($share->ys_nav_date < $share->getOriginal('ys_nav_date')) {
                $share->ys_nav = $share->ys_nav;
                $share->ys_nav_date = $share->getOriginal('ys_nav_date');
            }
            if ($share->ys_yield_date < $share->getOriginal('ys_yield_date')) {
                $share->ys_yield = $share->ys_yield;
                $share->ys_yield_date = $share->getOriginal('ys_yield_date');
            }
            if ($share->ys_yield_estimated_date < $share->getOriginal('ys_yield_estimated_date')) {
                $share->ys_yield_estimated = $share->ys_yield_estimated;
                $share->ys_yield_estimated_date = $share->getOriginal('ys_yield_estimated_date');
            }
            if ($share->isDirty()) {
                DirtyDumper::xlogDirty($share, 'share update', ['uid' => $uid, 'ys_portfolio_id' => $poId, 'ys_fund_id' => $fundId]);
                $share->save();
            }
        } else {
            Log::info('share insert', [
                'uid' => $uid,
                'ys_portfolio_id' => $poId,
                'ys_fund_id' => $fundId,
                'ys_fund_code' => $data['ys_fund_code'],
                'ys_share_total' => $data['ys_share_total'],
            ]);

            $share = new YingmiShare($data);
            $share->save();
        }
    }

    public function updateShareDetail($uid, $row)
    {
        $checkCols = [
            'ys_uid', 'ys_account_id'
        ];

        $shareId = $row['shareId'];
        $fundCode = $row['fundCode'];

        $xtab = [
            'ys_account_id' => 'accountId',
            'ys_portfolio_id' => 'poCode',
            'ys_pay_method' => 'paymentMethodId',
            'ys_portfolio_id' => 'poCode',
            'ys_share_type' => 'shareType',
            'ys_share_total' => ['key' => 'totalShare', 'fmt' => 2],
            'ys_share_avail' => ['key' => 'avaiShare', 'fmt'=> 2],
            'ys_share_frozen' => ['key' => 'frozenShare', 'fmt' => 2],
            'ys_asset_total' => ['key' => 'totalShareAsset', 'fmt' => 2],
            'ys_yield_uncarried' => ['key' => 'yieldUncarried', 'fmt' => 2],
            'ys_nav' => ['key' => 'nav', 'fmt' => 4],
            'ys_nav_date' => 'navDate',
            'ys_div_mode' => 'dividendMethod',
            'ys_yield_accumulated' => ['key' => 'accumulatedProfit', 'fmt' => 2],
            'ys_yield' => ['key' => 'previousProfit', 'fmt' => 2],
            'ys_yield_date' => 'previousProfitTradeDate',
            'ys_yield_estimated' => ['key' => 'previousEstimatedProfit', 'fmt' => 2],
            'ys_yield_estimated_date' => 'previousEstimatedProfitTradeDate',
        ];

        //
        // 根据基金code加载基金ID和名称
        //
        $fi = BnFundInfo::findByCode($fundCode, 'dummy');

        $shareData = [
            'ys_uid' => $uid,
            'ys_share_id' => $shareId,
            'ys_fund_id' => $fi->fi_globalid,
            'ys_fund_code' => $fundCode,
            'ys_fund_type' => $fi->fi_type,
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
                    $shareData[$k] = number_format((double)$row[$v] + 0.000000001, $fmt, '.', '');
                }
            }
        }

        if (isset($row['dividendMethod'])) {
            $shareData['ys_div_mode'] = $row['dividendMethod'];
        }

        if (!isset($shareData['ys_portfolio_id'])) {
            $poId = MfPortfolioInfo::where('mf_uid', $uid)->first();
            $shareData['ys_portfolio_id'] = $poId->id;
        }

        $modified = false;
        //
        // 分两种情况处理：
        //
        // 1) 如果$shareId对应的记录存在，则直接更新该记录。
        //
        // 2) 如果$shareId对应的记录不存在，但设置了对应的组合代码poCode, 则还
        //    需要根据组合代码，基金代码和pay_method来确定对应的份额是否存在。
        //

        $share = YingmiShareDetail::where('ys_share_id', $shareId)->first();
        if (!$share && isset($shareData['ys_portfolio_id']) && isset($shareData['ys_pay_method'])) {
            $shares = YingmiShareDetail::where('ys_portfolio_id', $shareData['ys_portfolio_id'])
                ->where('ys_fund_id', $shareData['ys_fund_id'])
                ->where('ys_pay_method', $shareData['ys_pay_method'])
                ->get();
            if ($shares->count() > 1) {
                Log::error('multiplle share detail matched, use first!', [
                    $shareData, $shares->toArray(),
                ]);
            }
            $share = $shares->first();
        }

        if ($share) {
            //
            // 进行必要的安全性检查
            //
            $failed = false;
            foreach ($checkCols as $c) {
                if ($share->{$c} && $shareData[$c] && $share->{$c} != $shareData[$c]) {
                    Log::error('column value mismatch: ', [
                        "share.$c"  => $share->{$c},
                        "info.$c" => $shareData[$c],
                        'ys_id' => $share->id,
                        'row' => $row,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return [$uid, $fi->fi_globalid, $shareId];
            }

            //
            // 更新订单信息
            //
            $share->fill($shareData);
            //
            // 防止覆盖最新的收益和累计收益
            //
            if ($share->ys_nav_date < $share->getOriginal('ys_nav_date')) {
                dd("restore nav", $share);
                $share->ys_nav = $share->ys_nav;
                $share->ys_nav_date = $share->getOriginal('ys_nav_date');
            }
            if ($share->ys_share_total != 0) {
                if ($share->ys_yield_date < $share->getOriginal('ys_yield_date')) {
                    $share->ys_yield = $share->ys_yield;
                    $share->ys_yield_date = $share->getOriginal('ys_yield_date');
                }
                if ($share->ys_yield_estimated_date < $share->getOriginal('ys_yield_estimated_date')) {
                    $share->ys_yield_estimated = $share->ys_yield_estimated;
                    $share->ys_yield_estimated_date = $share->getOriginal('ys_yield_estimated_date');
                }
            }

            if ($share->isDirty()) {
                DirtyDumper::xlogDirty($share, 'share detail update', ['uid' => $uid, 'ys_share_id' => $shareId]);
                $share->save();
                $modified = true;
            }

        } else {
            Log::info('share detail insert', [
                'uid' => $uid,
                'ys_share_id' => $shareId,
                'fundCode' => $shareData['ys_fund_code'],
                'pay_method' => $shareData['ys_pay_method'],
                'ys_portfolio_id' => isset($shareData['ys_portfolio_id']) ? $shareData['ys_portfolio_id'] : '0',
            ]);

            $share = new YingmiShareDetail($shareData);
            $share->save();
            $modified = true;
        }
        // if ($shareId == '2017010600022877') {
        //     dd($share, $row);
        // }

        if ($modified) {
            return [$uid, $fi->fi_globalid, $share->ys_portfolio_id];
        } else {
            return [$uid, $fi->fi_globalid, false];
        }

    }

    public function updatePoShare($uid, $poId)
    {
        $columns = [
            'yp_uid',
            'yp_account_id',
            'yp_portfolio_id',
            'yp_yield_date',
            DB::raw('SUM(yp_total_asset) AS yp_total_asset'),
            DB::raw('SUM(yp_share_asset) AS yp_share_asset'),
            DB::raw('SUM(yp_processing_asset) AS yp_processing_asset'),
            DB::raw('SUM(yp_previous_profit) AS yp_previous_profit'),
            DB::raw('SUM(yp_accumulated_profit) AS yp_accumulated_profit'),
            DB::raw('SUM(yp_avail_share_asset) AS yp_avail_share_asset'),
            DB::raw('MIN(yp_portfolio_adjustment_id) AS yp_portfolio_adjustment_id'),
        ];

        $row = YingmiPortfolioShareDetail::where('yp_uid', $uid)
             ->where('yp_portfolio_id', $poId)
             ->first($columns);
        if (!$row) {
            YingmiShare::where('yp_uid', $uid)->where('yp_fund_id', $poId)->delete();
            return;
        }

        $data = [
            'yp_uid' => $uid,
            'yp_portfolio_id' => $poId,
            'yp_account_id' => $row->yp_account_id,
            'yp_yield_date' => $row->yp_yield_date,
            'yp_total_asset' => $row->yp_total_asset,
            'yp_share_asset' => $row->yp_share_asset,
            'yp_processing_asset' => $row->yp_processing_asset,
            'yp_previous_profit' => $row->yp_previous_profit,
            'yp_accumulated_profit' =>  $row->yp_accumulated_profit,
            'yp_avail_share_asset' =>   $row->yp_avail_share_asset,
            'yp_portfolio_adjustment_id' => $row->yp_portfolio_adjustment_id,
        ];

        $share = YingmiPortfolioShare::firstOrNew(['yp_uid' => $uid, 'yp_portfolio_id' => $poId]);

        $share->fill($data);
        $share->save();

        //
        //更新组合基金的share
        //
        $fundIds = YingmiShareDetail::where('ys_uid', $uid)
                 ->where('ys_portfolio_id', $poId)
                 ->selectRaw('DISTINCT ys_fund_id')
                 ->lists('ys_fund_id');

        $fundIds = $fundIds->toArray();

        $funds = $this->updateFundShare($uid, $fundIds, $poId);
        if ($funds) {
            foreach ($funds as $fund) {
                $job = new UpdateYingmiHoldingOnShareChanged($fund);
                $job->handle();
            }
        }
    }

    public function updateFundShare($uid, $fundIds, $poId)
    {
        $columns = [
            'ys_uid',
            'ys_fund_id',
            'ys_portfolio_id',
            DB::raw('SUM(ys_share_total) as ys_share_total'),
            DB::raw('SUM(ys_share_avail) AS ys_share_avail'),
            DB::raw('SUM(ys_share_frozen) AS ys_share_frozen'),
            DB::raw('SUM(ys_asset_total) AS ys_asset_total'),
            DB::raw('SUM(ys_principal) AS ys_principal'),
            'ys_nav',
            'ys_nav_date',
            'ys_fund_percentage',
            //DB::raw('SUM(ys_yield) AS ys_yield'),
            //'ys_yield_date',
            //DB::raw('SUM(ys_yield_estimated) AS ys_yield_estimated'),
            //'ys_yield_estimated_date',
            //DB::raw('SUM(ys_yield_uncarried) AS ys_yield_uncarried'),
            //DB::raw('SUM(ys_yield_accumulated) AS ys_yield_accumulated'),
            //'ys_div_mode',
        ];

        //
        // 根据detail统计Share信息
        //
        $rows = YingmiShareDetail::where('ys_uid', $uid)
              ->where('ys_portfolio_id', $poId)
              ->whereIn('ys_fund_id', $fundIds)
              ->groupBy('ys_fund_id')
              ->get($columns);
        if ($rows->isEmpty()) {
            // YingmiShare::where('ys_uid', $uid)->whereIn('ys_fund_id', $fundIds)->delete();
            return;
        }
        $codes = array_keys(array_dict($rows, 'ys_fund_id'));

        $totalAsset = 0;
        foreach ($rows as $row) {
            $totalAsset += $row->ys_asset_total;
        }
        $rows = array_dict($rows, 'ys_fund_id');

        $fis = BnFundInfo::whereIn('fi_globalid', $codes)
            ->get();
        $fis = array_dict($fis, 'fi_globalid');

        $shares = YingmiShare::where('ys_uid', $uid)
                ->where('ys_portfolio_id', $poId)
                ->whereIn('ys_fund_id', $fundIds)
                ->get();
        $shares = array_dict($shares, 'ys_fund_id');

        $shareData = [];
        foreach ($rows as $id => $row) {
            if (isset($shares[$id])) {
                $share = $shares[$id];
            } else {
                $share = new YingmiShare;
                $shares[$id] = $share;
            }

            if (isset($fis[$id])) {
                $fi = $fis[$id];
            } else {
                continue;
            }

            //if ($totalAsset != 0) {
            //    $percent = $row->ys_asset_total / $totalAsset;
            //}

            $data = [
                'ys_uid' => $uid,
                'ys_fund_id' => $id,
                'ys_fund_code' => $fi->fi_code,
                'ys_fund_name' => $fi->fi_name,
                'ys_fund_type' => $fi->fi_type,
                'ys_portfolio_id' => $row->ys_portfolio_id,
                'ys_share_total' => $row->ys_share_total,
                'ys_share_avail' => $row->ys_share_avail,
                'ys_share_frozen' => $row->ys_share_frozen,
                'ys_asset_total' => $row->ys_asset_total,
                'ys_principal' => $row->ys_principal,
                'ys_nav' => $row->ys_nav,
                'ys_nav_date' => $row->ys_nav_date,
                'ys_fund_percentage' => $row->ys_fund_percentage,
                //'ys_yield' => $row->ys_yield,
                //'ys_yield_date' => $row->ys_yield_date,
                //'ys_yield_estimated' => $row->ys_yield_estimated,
                //'ys_yield_estimated_date' => $row->ys_yield_estimated_date,
                //'ys_yield_uncarried' => $row->ys_yield_uncarried,
                //'ys_yield_accumulated' => $row->ys_yield_accumulated,
                //'ys_div_mode' => $row->ys_div_mode,
                //'ys_fund_percentage' => $percent,
            ];

            $share->fill($data);
        }

        DB::transaction(function () use ($shares) {
            foreach ($shares as $share) {
                $share->save();
            }
        });

        return $shares;
    }

    public function updatePoShareDetail($uid, $ymId, $row)
    {
        $checkCols = [
            'yp_uid',
            'yp_account_id'
        ];

        $shareId = $row['poShareId'];
        $poCode = $row['poCode'];

        $xtab = [
            'yp_payment_method' => 'paymentMethodId',
            'yp_total_asset' => ['key' => 'totalAsset', 'fmt' => 2],
            'yp_share_asset' => ['key' => 'shareAsset', 'fmt' => 2],
            'yp_processing_asset' => ['key' => 'processingAsset', 'fmt' => 2],
            'yp_previous_profit' => ['key' => 'previousProfit', 'fmt' => 2],
            'yp_accumulated_profit' => ['key' => 'accumulatedProfit', 'fmt' => 2],
            'yp_avail_share_asset' => ['key' => 'avaiShareAsset', 'fmt' => 2],
            'yp_deviation_rate' => ['key' => 'deviationRate', 'fmt' => 4],
            'yp_portfolio_asset_status' => 'poAssetStatus',
            'yp_expected_confirm_date' => 'expectedConfirmDate',
            'yp_can_buy' => 'canBuy',
            'yp_can_redeem' => 'canRedeem',
            'yp_can_adjust' => 'canAdjust',
        ];

        $adjustId = YingmiPortfolioTradeStatus::latestAdjustId($uid, $poCode, $row['paymentMethodId']);

        $yieldDate = max(array_map(function ($r) {
            if ($r['fundType'] != 4) {
		    return $r['navDate'];
            }
        }, $row['compositionShares']));
        if (is_null($yieldDate)) {
            $yieldDate = max(array_map(function ($r) {
	        return $r['navDate'];
            }, $row['compositionShares']));
        }

        $shareData = [
            'yp_uid' => $uid,
            'yp_portfolio_share_id' => $shareId,
            'yp_account_id' => $ymId,
            'yp_portfolio_id' => $poCode,
            'yp_yield_date' => $yieldDate,
        ];

        if ($adjustId != 0) {
            $shareData['yp_portfolio_adjustment_id'] = $adjustId;
        }

        foreach ($xtab as $k => $vv) {
            if (is_array($vv)) {
                $v = $vv['key'];
                $fmt = $vv['fmt'];
            } else {
                $v = $vv;
                $fmt = 0;
            }

            if (isset($row[$v]) && $row[$v] !== null) {
                if (!$fmt) {
                    $shareData[$k] = $row[$v];
                } else {
                    $shareData[$k] = number_format((double)$row[$v], $fmt, '.', '');
                }
            }
        }

        $share = YingmiPortfolioShareDetail::where('yp_portfolio_share_id', $shareId)->first();

        if ($share) {
            //
            // 进行必要的安全性检查
            //
            $failed = false;
            foreach ($checkCols as $c) {
                if ($share->{$c} && $shareData[$c] && $share->{$c} != $shareData[$c]) {
                    Log::error('column value mismatch: ', [
                        "share.$c"  => $share->{$c},
                        "info.$c" => $shareData[$c],
                        'yp_id' => $share->id,
                        'row' => $row,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return [$uid, $fi->fi_globalid, $shareId];
            }

            //
            // 更新订单信息
            //
            $share->fill($shareData);
        } else {
            $share = new YingmiPortfolioShareDetail($shareData);
        }

        $share->save();

        $funds = $this->updatePoFundShareDetail($uid, $row);
        foreach ($funds as $fund) {
            $job = new UpdateYingmiHoldingDetailOnShareDetailChanged($fund);
            $job->handle();
        }

        return [$uid, $poCode, $shareId];
    }

    public function updatePoFundShareDetail($uid, $row)
    {
        $checkCols = [
            'ys_uid', 'ys_portfolio_id',
        ];

        $shareId = $row['poShareId'];
        $poId = $row['poCode'];
        $funds = $row['compositionShares'];
        $payment = $row['paymentMethodId'];

        $xtab = [
            'ys_share_type' => 'shareType',
            'ys_share_total' => ['key' => 'totalShare', 'fmt' => 2],
            'ys_share_avail' => ['key' => 'avaiShare', 'fmt' => 2],
            'ys_nav' => ['key' => 'nav', 'fmt' => 4],
            'ys_nav_date' => 'navDate',
            'ys_asset_total' => ['key' => 'shareAsset', 'fmt' => 2],
            'ys_fund_percentage' => 'percent',
        ];

        $codes = array_map(function ($f) {
            return $f['prodCode'];
        }, $funds);

        $fis = BnFundInfo::whereIn('fi_code', $codes)
            ->get();
        $fis = array_dict($fis, 'fi_code');

        $shareDatas = [];
        foreach ($funds as $fund) {
            $data = [
                'ys_uid' => $uid,
                // 'ys_share_id' => $shareId,
                'ys_portfolio_share_id' => $shareId,
                'ys_portfolio_id' => $poId,
                'ys_pay_method' => $payment,
            ];

            if (isset($fis[$fund['prodCode']])) {
                $fi = $fis[$fund['prodCode']];
                $data['ys_fund_id'] = $fi->fi_globalid;
                $data['ys_fund_code'] = $fi->fi_code;
                $data['ys_fund_type'] = $fi->fi_type;
            }

            $data['ys_share_frozen'] = '0.00';
            $data['ys_principal'] = '0.00';
            $data['ys_yield_uncarried'] = '0.00';
            $data['ys_yield_accumulated'] = '0.00';

            foreach ($xtab as $k => $vv) {
                if (is_array($vv)) {
                    $v = $vv['key'];
                    $fmt = $vv['fmt'];
                } else {
                    $v = $vv;
                    $fmt = 0;
                }

                if (isset($fund[$v]) && $fund[$v] != null) {
                    if (!$fmt) {
                        $data[$k] = $fund[$v];
                    } else {
                        $data[$k] = number_format((double)$fund[$v], $fmt, '.', '');
                    }
                }
            }

            $shareDatas[$fund['prodCode']] = $data;
        }

        $shares = YingmiShareDetail::where('ys_portfolio_share_id', $shareId)->get();
        $shares = array_dict($shares, 'ys_fund_code');

        $toUpdate = [];
        foreach ($shareDatas as $code => $shareData) {
            $fee = 0;
            if (isset($fees[$code])) {
                $fee = $fees[$code];
            }

            if (isset($shares[$code])) {
                $share = $shares[$code];

                $failed = false;
                foreach ($checkCols as $c) {
                    if ($share->{$c} != $shareData[$c]) {
                        Log::error('column value mismatch: ', [
                            "share.$c"  => $share->{$c},
                            "info.$c" => $shareData[$c],
                            'ys_id' => $share->id,
                            'line' => $line,
                        ]);

                        $failed = true;
                        break;
                    }
                }

                if ($failed) {
                    continue;
                }

                //$hour = date('H');
                //if ($hour < 14 || $hour > 18) {
                //    unset($shareData['ys_asset_total']);
                //}
                //$week = date('w');
                //if ($week == 0 || $week == 6) {
                //    unset($shareData['ys_asset_total']);
                //}


                $share->fill($shareData);
            } else {
                $share = new YingmiShareDetail($shareData);
            }

            $toUpdate[] = $share;
        }

        DB::transaction(function () use ($toUpdate) {
            foreach ($toUpdate as $u)  {
                $u->save();
            }
        });

        return $toUpdate;
    }

    public function estimateYield($fundId, $fund, &$data)
    {
        if (is_null($fund['navDate']) || $fund['navDate'] == '0000-00-00') {
            return ;
        }

        $lastTradeDate = latest_trade_date(date('Y-m-d', strtotime($fund['navDate']) - 86400));
        $value = BnFundValue::where('fv_fund_id', $fundId)
               ->where('fv_calc_type', 0)
               ->where('fv_date', $lastTradeDate)
               ->first(['fv_nav']);

        if (!$value) {
            return;
        }

        $yield = round(($fund['nav'] - $value->fv_nav) * $fund['totalShare'], 2);
        $data['ys_yield_estimated_date'] = $fund['navDate'];
        $data['ys_yield_estimated'] = $yield;
    }
}
