<?php
namespace App\Libraries;

use DB;
use Log;

use Carbon\Carbon;

use App\BnFundInfo;
use App\BnFundValue;
use App\FundBonus;
use App\FundSplit;
use App\MfFundHoldingDetail1;
use App\MfFundShare;
use App\MfFundShareDetail;
use App\MfFundTradeStatus;
use App\MfPortfolioTradeStatus;
use App\MfPortfolioShare;
use App\RaFundNav;
use App\YingmiPortfolioTradeStatus;
use App\MfPortfolioInfo;
use App\Libraries\MfHelper;

use App\Libraries\DirtyDumper;

use function App\Libraries\date_range;

trait MfShareUpdater {
    public function updateShare($uid, $fundId)
    {
        $columns = [
            'mf_uid',
            'mf_portfolio_id',
            'mf_fund_id',
            'mf_fund_percentage',
            DB::raw('SUM(mf_share_total) AS mf_share_total'),
            DB::raw('SUM(mf_share_avail) AS mf_share_avail'),
            DB::raw('SUM(mf_share_frozen) AS mf_share_frozen'),
            DB::raw('SUM(mf_asset_total) AS mf_asset_total'),
            DB::raw('SUM(mf_principal) AS mf_principal'),
            'mf_nav',
            'mf_nav_date',
            DB::raw('SUM(mf_yield) AS mf_yield'),
            'mf_yield_date',
            DB::raw('SUM(mf_yield_estimated) AS mf_yield_estimated'),
            'mf_yield_estimated_date',
            DB::raw('SUM(mf_yield_accumulated) AS mf_yield_accumulated'),
            DB::raw('SUM(mf_yield_uncarried) AS mf_yield_uncarried'),
            'mf_div_mode',
        ];

        $row = MfFundShareDetail::where('mf_uid', $uid)
             ->where('mf_fund_id', $fundId)
             ->first($columns);
        if (!$row) {
            MfFundShare::where('mf_uid', $uid)->where('mf_fund_id', $fundId)->delete();
        }

        $fi = BnFundInfo::findOrDefault($fundId, 'dummy');

        $data = [
            'mf_uid'                  => $uid,
            'mf_portfolio_id'         => $row->mf_portfolio_id,
            'mf_fund_id'              => $fundId,
            'mf_fund_code'            => $fi->fi_code,
            'mf_fund_name'            => $fi->fi_name,
            'mf_fund_type'            => $fi->fi_type,
            'mf_fund_percentage'      => $row->mf_fund_percentage,
            'mf_share_total'          => $row->mf_share_total,
            'mf_share_avail'          => $row->mf_share_avail,
            'mf_share_frozen'         => $row->mf_share_frozen,
            'mf_asset_total'          => $row->mf_asset_total,
            'mf_principal'            => $row->mf_principal,
            'mf_nav'                  => $row->mf_nav,
            'mf_nav_date'             => $row->mf_nav_date,
            'mf_yield'                => $row->mf_yield,
            'mf_yield_date'           => $row->mf_yield_date,
            'mf_yield_estimated'      => $row->mf_yield_estimated,
            'mf_yield_estimated_date' => $row->mf_yield_estimated_date,
            'mf_yield_accumulated'    => $row->mf_yield_accumulated,
            'mf_yield_uncarried'      => $row->mf_yield_uncarried,
            'mf_div_mode'             => $row->mf_div_mode,
        ];

        $share = MfFundShare::firstOrNew(['mf_uid' => $uid, 'mf_fund_id' => $fundId]);
        $share->fill($data)->save();
    }

    public function updateDivMode($uid, $fundId)
    {
        $orders = MfFundTradeStatus::where('mf_uid', $uid)
                ->where('mf_fund_id', $fundId)
                ->where('mf_trade_type', '029')
                ->where('mf_trade_status', '!=', 1)
                ->get();

        if ($orders->isEmpty()) {
            MfHelper::setDividendMethod($uid, $fundId);
        }
    }

    public function updateShareDetail($uid, $row)
    {
        $checks = ['mf_uid'];

        $shareId  = $row['shareId'];
        $fundCode = $row['fundCode'];

        $xtab = [
            'mf_pay_method'           => 'paymentMethodId',
            'mf_share_total'          => ['key' => 'totalShare', 'fmt' => 2],
            'mf_share_avail'          => ['key' => 'avaiShare', 'fmt' => 2],
            'mf_share_frozen'         => ['key' => '', 'fmt' => 2],
            'mf_asset_total'          => ['key' => 'totalShareAsset', 'fmt' => 2],
            'mf_yield_uncarried'      => ['key' => 'yieldUncarried', 'fmt' => 2],
            'mf_nav'                  => ['key' => 'nav', 'fmt' => 4],
            'mf_nav_date'             => 'navDate',
            'mf_div_mode'             => 'dividendMethod',
            'mf_yield'                => ['key' => 'previousProfit', 'fmt' => 2],
            'mf_yield_date'           => 'previousProfitTradeDate',
            'mf_yield_estimated'      => ['key' => 'previousEstimatedProfit', 'fmt' => 4],
            'mf_yield_estimated_date' => 'previousEstimatedProfitTradeDate',
            'mf_yield_accumulated'    => ['key' => 'accumulatedProfit', 'fmt' => 2],
        ];

        $fi = BnFundInfo::findByCode($fundCode, 'dummy');

        $poInfo = MfPortfolioInfo::where('mf_uid', $uid)->first();

        if (!$poInfo) {
            Log::error("not find portfolio order [$uid]");
        }

        $poId = $poInfo ? $poInfo->id : -1;

        $shareData = [
            'mf_uid'          => $uid,
            'mf_portfolio_id' => $poId,
            'mf_share_id'     => $shareId,
            'mf_fund_id'      => $fi->fi_globalid,
            'mf_fund_code'    => $fundCode,
            'mf_fund_name'    => $fi->fi_name,
            'mf_fund_type'    => $fi->fi_type,
        ];

        foreach ($xtab as $k => $vv) {
            if (is_array($vv)) {
                $v = $vv['key'];
                $fmt = $vv['fmt'];
            } else {
                $v = $vv;
                $fmt = 0;
            }

            if (isset($row[$v]) && !is_null($row[$v])) {
                if (!$fmt) {
                    $shareData[$k] = $row[$v];
                } else {
                    $shareData[$k] = number_format((double)$row[$v], $fmt, '.', '');
                }
            }
        }

        $share = MfFundShareDetail::where('mf_share_id', $shareId)->first();

        if ($share) {
            $failed = false;

            foreach ($checks as $c) {
                if ($share->{$c} != $shareData[$c]) {
                    Log::error('column value mismatch: ', [
                        "share.$c"  => $share->{$c},
                        "info.$c" => $shareData[$c],
                        'mf_id' => $share->id,
                    ]);

                    $failed = true;
                    break;
                }
            }

            if ($failed) {
                return [$uid, $fi->fi_globalid, $shareId];
            }

            $share->fill($shareData);
            //
            // 防止覆盖最新的收益和累计收益
            //
            if ($share->mf_nav_date < $share->getOriginal('mf_nav_date')) {
                $share->mf_nav = $share->mf_nav;
                $share->mf_nav_date = $share->getOriginal('mf_nav_date');
            }
            if ($share->mf_share_total != 0) {
                if ($share->mf_yield_date < $share->getOriginal('mf_yield_date')) {
                    $share->mf_yield = $share->mf_yield;
                    $share->mf_yield_date = $share->getOriginal('mf_yield_date');
                }
                if ($share->mf_yield_estimated_date < $share->getOriginal('mf_yield_estimated_date')) {
                    $share->mf_yield_estimated = $share->mf_yield_estimated;
                    $share->mf_yield_estimated_date = $share->getOriginal('mf_yield_estimated_date');
                }
            }

            if ($share->isDirty()) {
                DirtyDumper::xlogDirty($share, 'mf_fund_share_detail update', ['uid' => $uid, 'mf_share_id' => $shareId]);
                $share->save();
                $modified = true;
            }

        } else {
            Log::info('mf_fund_share_detail insert', [
                'uid' => $uid,
                'mf_share_id' => $shareId,
                'fundCode' => $shareData['mf_fund_code'],
                'pay_method' => $shareData['mf_pay_method'],
                'mf_portfolio_id' => isset($shareData['mf_portfolio_id']) ? $shareData['mf_portfolio_id'] : '0',
            ]);

            $share = new MfFundShareDetail($shareData);
            $share->save();
            $modified = true;
        }

        if ($row['dividendMethod'] == 1) {
            self::updateDivMode($uid, $fi->fi_globalid);
        }

        return [$uid, $fi->fi_globalid, $shareId];
    }

    public function updatePercentage($uid)
    {
        $details = MfFundShareDetail::where('mf_uid', $uid)
                 //->where('mf_share_total', '>', 0)
                 ->get();

        $total = $details->sum('mf_asset_total');
        //if (!empty($orders)) {
        //    $total += array_sum($orders);
        //}

        foreach ($details as &$d) {
            if ($total > 0) {
                $d->mf_fund_percentage = round((float)$d->mf_asset_total / $total, 4);
            } else {
                $d->mf_fund_percentage = 0;
            }
        }

        DB::transaction(function () use ($details) {
            foreach ($details as $d) {
                $d->save();
            }
        });
    }

    public function updatePrincipal($uid, $shareId)
    {
        $share = MfFundShareDetail::where('mf_uid', $uid)
               ->where('mf_share_id', $shareId)
               ->first();

        if (!$share) {
            Log::warning("not find share $shareId");
            return;
        }

        $fundId = $share->mf_fund_id;

        $infos = [
            'uid'      => $uid,
            'poId'     => $share->mf_portfolio_id,
            'fundId'   => $fundId,
            'fundType' => $share->mf_fund_type,
        ];

        $walletId = MfHelper::getWalletIdByPayment($uid, $share->mf_pay_method);

        $orders = MfFundTradeStatus::where('mf_uid', $uid)
                ->where('mf_fund_id', $fundId)
                ->whereIn('mf_trade_type', ['020', '022', '024', 'W04', 'W05'])
                ->whereIn('mf_trade_status', [2, 3, 4])
                ->orderBy('mf_placed_date', 'ASC')
                ->orderBy('mf_placed_time', 'ASC')
                ->get();

        $events = [];
        $e = [
            'it_type'      => 0,
            'it_date'      => '0000-00-00',
            'it_time'      => '00:00:00',
            'it_amount'    => 0,
            'it_share'     => 0,
            'it_fee'       => 0,
            'it_nav'       => 0,
            'it_placed_at' => 0,
        ];

        foreach ($orders as $order) {
            $placedAt = $order->ip_placed_date . ' ' . $order->ip_placed_time;

            if (in_array($order->mf_trade_type, ['020', '022', 'W04'])) {
                $type = 1;
                $amount = $order->mf_acked_amount;
            } else if (in_array($order->mf_trade_type, ['024', 'W05'])) {
                $type = 2;
                $amount = $order->mf_acked_amount + $order->mf_acked_fee;
            }
            $e1 = [
                'it_type'      => $type,
                'it_date'      => $order->mf_trade_date,
                'it_time'      => '16:00:00',
                'it_amount'    => $amount,
                'it_share'     => $order->mf_acked_share,
                'it_fee'       => $order->mf_acked_fee,
                'it_nav'       => $order->mf_trade_nav,
                'it_placed_at' => $placedAt,
            ];

            array_push($events, $e1);

            if ($type == 1) {
                $type = 11;
                $time = '00:02:00';
            } else {
                $type = 12;
                $time = '00:03:00';
            }

            $e2 = array_replace($e1, [
                'it_type' => $type,
                'it_date' => $order->mf_acked_date,
                'it_time' => $time,
                'it_trade_date' => $order->mf_trade_date,
            ]);

            array_push($events, $e2);
        }

        $beginDate = $orders->min('mf_placed_date');
        $endDate = Carbon::today()->toDateString();

        // 分红信息
        $bonus = FundBonus::where('fb_fund_id', $fundId)
               ->whereBetween('fb_record_date', [$beginDate, $endDate])
               ->get();
        $bonusContext = [];
        foreach ($bonus as $b) {
            $bonusContext[$b->fb_globalid] = [
                'fb_record_date' => $b->fb_record_date,
                'fb_bonus' => $b->fb_bonus,
                'fb_share' => 0,
                'fb_bonus_amount' => 0,
                'fb_bonus_share' => 0,
            ];

            $e1 = [
                'it_type'      => 15,
                'it_date'      => $b->fb_record_date,
                'it_time'      => '23:57:00',
                'it_extra'     => $b->fb_globalid,
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            $e2 = [
                'it_type'      => 16,
                'it_date'      => $b->fb_ex_dividend_date,
                'it_time'      => '23:58:00',
                'it_extra'     => $b->fb_globalid,
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            $e3 = [
                'it_type'      => 17,
                'it_date'      => $b->fb_dividend_payment_date,
                'it_time'      => '23:59:00',
                'it_extra'     => $b->fb_globalid,
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            array_push($events, $e1, $e2, $e3);
        }

        // 拆分信息
        $split = FundSplit::where('fs_fund_id', $fundId)
               ->whereBetween('fs_split_date', [$beginDate, $endDate])
               ->get();

        foreach ($split as $s) {
            $e = [
                'it_type'      => 18,
                'it_date'      => $s->fb_dividend_payment_date,
                'it_time'      => '01:00:00',
                'it_split'     => $s->fs_split_proportion,
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            array_push($events, $e);
        }

        $navs = RaFundNav::where('ra_fund_id', $fundId)
              ->whereBetween('ra_date', [$beginDate, $endDate])
              ->get(['ra_date', 'ra_nav']);
        foreach ($navs as $nav) {
            if ($nav->ra_nav) {
                $e = [
                    'it_type'      => 0,
                    'it_date'      => $nav->ra_date,
                    'it_time'      => '15:30:00',
                    'it_nav'       => $nav->ra_nav,
                    'it_placed_at' => '0000-00-00 00:00:00',
                ];

                array_push($events, $e);
            } else {
                Log::warning('ra nav is 0');
            }
        }

        $dates = date_range($beginDate, $endDate);
        foreach ($dates as $day) {
            $e = [
                'it_type' => 19,
                'it_date' => $day,
                'it_time' => '23:59:59',
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            array_push($events, $e);
        }

        usort($events, function ($a, $b) {
            if ($a['it_date'] != $b['it_date']) {
                return strcmp($a['it_date'], $b['it_date']);
            }

            if ($a['it_time'] != $b['it_time']) {
                return strcmp($a['it_time'], $b['it_time']);
            }

            return strcmp($a['it_placed_at'], $b['it_placed_at']);
        });

        $shares = [];
        $holdings = [];
        foreach ($events as $event) {
            self::processEvent($infos, $event, $shares, $holdings, $bonusContext);
        }

        MfFundHoldingDetail1::where('mf_uid', $uid)
            ->where('mf_fund_id', $fundId)
            ->delete();

        MfFundHoldingDetail1::insert($holdings);
    }

    public function processEvent($infos, $event, &$shares, &$holdings, &$bonusContext)
    {
        $type = $event['it_type'];

        $share = [
            'ip_share' => 0,
            'ip_share_buying' => 0,
            'ip_share_redeeming' => 0,
            'ip_principal' => 0,
            'ip_asset' => 0,
            'ip_nav' => 0,
            'ip_nav_date' => 0,
            'ip_yield' => 0,
            'ip_div_mode' => 0,
        ];

        if ($type == 1) {
            // 同一个交易日的购买订单可以计算在一起
            if (isset($shares[$event['it_date']])) {
                $share = $shares[$event['it_date']];
            }

            $share['ip_share_buying'] += $event['it_share'];
            $share['ip_principal'] += $event['it_amount'];

            $shares[$event['it_date']] = $share;

        } else if ($type == 11) {
            if (isset($shares[$event['it_trade_date']])) {
                $share = $shares[$event['it_trade_date']];
                $share['ip_share'] += $event['it_share'];
                $share['ip_share_buying'] -= $event['it_share'];

                $shares[$event['it_trade_date']] = $share;
            } else {
                Log::error('not find share but acked');
            }
        } else if ($type == 2) {
            // 赎回从份额中依次减去要赎回的份额
            foreach ($shares as &$share) {
                if ($event['it_share'] <= 0) {
                    break;
                }

                if ($share['ip_share'] <= $event['ip_share']) {
                    $event['it_share'] -= $share['ip_share'];
                    $share['ip_share_redeeming'] = $share['ip_share'];
                    $share['ip_share'] = 0;
                } else {
                    $share['ip_share'] -= $event['it_share'];
                    $share['ip_share_redeeming'] = $event['it_share'];
                    $event['it_share'] = 0;
                }
            }
        } else if ($type == 12) {
            foreach ($shares as $key => &$share) {
                if ($event['it_share'] <= 0) {
                    break;
                }

                if ($share['ip_share_redeeming'] <= $event['ip_share']) {
                    $event['it_share'] -= $share['ip_share_redeeming'];
                    $share['ip_share_redeeming'] = 0;

                    unset($shares[$key]);
                } else {
                    $share['ip_share_redeeming'] -= $event['it_share'];
                    $event['it_share'] = 0;
                }
            }
        }

        foreach ($shares as $key => &$share) {
            switch ($event['it_type']) {
                case 0:
                    $lastNav = $share['ip_nav'];
                    $total = $share['ip_share'];

                    $share['ip_nav'] = $event['it_nav'];
                    $share['ip_nav_date'] = $event['it_date'];
                    $share['ip_yield'] = $total * ($share['ip_nav'] - $lastNav);
                    break;
                case 15:
                    $bonusContext[$event['it_extra']]['fb_share'] = $share['ip_share'];
                    break;
                case 16:
                    $ctx = &$bonusContext[$event['it_extra']];
                    $ctx['fb_amount'] = $ctx['fb_share'] * $ctx['fb_bonus'];
                    $bonusShare = $ctx['fb_amount'] / $share['ip_nav'];
                    $share['ip_share'] += $bonusShare;
                    break;
                case 17:
                    break;
                case 18:
                    if ($event['it_split'] != 0) {
                        $share['ip_share'] *= $event['it_split'];
                        $share['ip_share_buying'] *= $event['it_split'];
                        $share['ip_share_redeeming'] *= $event['it_split'];

                        $share['ip_nav'] /= $event['it_split'];
                    }
                    break;
                case 19:
                    $total = $share['ip_share']
                           + $share['ip_share_buying']
                           + $share['ip_share_redeeming'];
                    if ($total > 0.000099) {
                        if (!$share['ip_nav']) {
                            $tmp = RaFundNav::where('ra_fund_id', $infos['fundId'])
                                ->where('ra_date', '<=', $event['it_date'])
                                ->orderBy('ra_date', 'DESC')
                                ->first(['ra_date', 'ra_nav']);
                            if ($tmp) {
                                $share['ip_nav'] = $tmp->ra_nav;
                                $share['ip_nav_date'] = $tmp->ra_date;
                            }
                        }

                        $asset = $share['ip_nav'] * $total;
                        $holdings[] = [
                            'mf_uid' => $infos['uid'],
                            'mf_portfolio_id' => $infos['poId'],
                            'mf_fund_id' => $infos['fundId'],
                            'mf_fund_type' => $infos['fundType'],
                            'mf_date' => $event['it_date'],
                            'mf_trade_date' => $key,
                            'mf_share' => round($share['ip_share'], 4),
                            'mf_share_buying' => round($share['ip_share_buying'], 4),
                            'mf_share_redeeming' => round($share['ip_share_redeeming'], 4),
                            'mf_principal' => round($share['ip_principal'], 4),
                            'mf_asset' => round($asset, 4),
                            'mf_nav' => round($share['ip_nav'], 4),
                            'mf_nav_date' => $share['ip_nav_date'],
                            'mf_yield' => round($share['ip_yield'], 4),
                        ];
                    }

                    break;
            }
        }
    }

    public function updatePoShare($uid)
    {
        Log::info('update portfolio share', [$uid]);

        //$columns = [
        //    'mf_uid',
        //    'mf_portfolio_id',
        //    DB::raw('SUM(mf_share_total) AS mf_share_total'),
        //    DB::raw('SUM(mf_share_avail) AS mf_share_avail'),
        //    DB::raw('SUM(mf_asset_total) AS mf_asset_total'),
        //    DB::raw('SUM(mf_principal) AS mf_principal'),
        //    DB::raw('SUM(mf_yield) AS mf_yield'),
        //    DB::raw('MAX(mf_yield_date) AS mf_yield_date'),
        //    DB::raw('SUM(mf_yield_accumulated) AS mf_yield_accumulated'),
        //];


        $row1 = (object) [
            'mf_uid' => $uid,
            'mf_portfolio_id' => 0,
            'mf_share_total' => 0,
            'mf_share_avail' => 0,
            'mf_asset_total' => 0,
            'mf_principal' => 0,
            'mf_yield' => 0,
            'mf_yield_date' => '0000-00-00',
            'mf_yield_accumulated' => 0,
        ];
        $rows = MfFundShare::where('mf_uid', $uid)
              //->where('mf_share_total', '>', 0)
              ->get();

        // 组合的收益中包含了最新的预估收益
        $yieldDate = $rows->max('mf_yield_date_latest');
        $row1->mf_yield_date = $yieldDate ? $yieldDate : '0000-00-00';
        foreach ($rows as $row) {
            $row1->mf_yield_accumulated += $row->mf_yield_accumulated;
            $row1->mf_portfolio_id = $row->mf_portfolio_id;

            if ($row->mf_share_total == 0) {
                continue;
            }
            $row1->mf_share_total += $row->mf_share_total;
            $row1->mf_share_avail += $row->mf_share_avail;
            $row1->mf_asset_total += $row->mf_asset_total;
            $row1->mf_principal += $row->mf_principal;
            if ($row->mf_yield_date_latest == $yieldDate) {
                $row1->mf_yield += $row->mf_yield_latest;
            }
        }

        $po_info = MfPortfolioInfo::where('mf_uid', $uid)->first();
        if ($po_info) {
            $tmp_po_id = $po_info->id;
        } else {
            $po_info = new MfPortfolioInfo();
            $po_info->mf_uid = $uid;
            $po_info->save();
            $tmp_po_id = $po_info->id;
        }
        if ($row1->mf_portfolio_id == 0) {
            $row1->mf_portfolio_id = $tmp_po_id;
        }

        $row2 = MfPortfolioTradeStatus::with('subOrders')
              ->where('mp_uid', $uid)
              ->whereIn('mp_trade_type', ['P02', 'P04'])
              ->whereIn('mp_trade_status', ['P0', 'P1'])
              ->get();

        // 在途资产
        $process1 = $process2 = 0;
        foreach ($row2 as $r) {
            if (!isset($r->subOrders)) {
                continue;
            }

            $subOrders = $r->subOrders;
            if ($r->mp_trade_type == 'P02') {
                $process1 += $subOrders->where('mf_trade_status', 0)
                          ->sum('mf_placed_amount');
            } else {
                $process2 += $subOrders->where('mf_trade_type', 'W05')
                     ->where('mf_trade_status', 2)
                     ->sum('mf_acked_amount');

                $process2 -= $subOrders->where('mf_trade_type', 'W04')
                     ->where('mf_trade_status', 2)
                     ->sum('mf_placed_amount');

                if ($r->mp_trade_type == 'P04') {
                    $yorders = YingmiPortfolioTradeStatus::with('composite')
                             ->where('yp_mf_txn_id', $r->mp_txn_id)
                             ->where('yp_trade_type', 'P03')
                             ->whereIn('yp_trade_status', ['P0', 'P1', 'P2'])
                             ->get();

                    foreach ($yorders as $yorder) {
                        if (isset($yorder->composite) && !$yorder->composite->isEmpty()) {
                            $process2 += $yorder->composite->sum('yt_acked_amount');
                        }
                    }
                }
            }
        }

        if ((!$row1 || !$row1->mf_uid) && $process1 == 0) {
            return;
        }

        $data = [
            'mp_uid'               =>$uid,
            //'mp_account_id',
            //'mp_portfolio_id'    => $poId,
            'mp_yield_date'        => '0000-00-00',
            'mp_total_asset'       => 0,
            'mp_avail_asset'       => 0,
            'mp_processing_asset'  => 0,
            'mp_principal'         => 0,
            'mp_yield'             => 0,
            'mp_accumulated_yield' => 0,
        ];

        // 根据基金份额信息计算组合份额信息
        if ($row1 && $row1->mf_uid) {
            $data['mp_yield_date']        = $row1->mf_yield_date;
            $data['mp_portfolio_id']      = $row1->mf_portfolio_id;
            $data['mp_total_asset']       = $row1->mf_asset_total;
            $data['mp_avail_asset']       = $row1->mf_asset_total;
            $data['mp_processing_asset']  = 0;
            $data['mp_principal']         = $row1->mf_principal;
            $data['mp_yield']             = $row1->mf_yield;
            $data['mp_accumulated_yield'] = $row1->mf_yield_accumulated;
        }

        // 根据订单信息计算组合在途资产信息
        if (!$row2->isEmpty()) {
            $data['mp_portfolio_id'] = $row2->first()->mp_portfolio_id;
            $data['mp_total_asset'] += $process1;
            $data['mp_total_asset'] += $process2;
            $data['mp_processing_asset'] = $process1 + $process2;
        }

        //$poShare = MfPortfolioShare::firstOrNew(['mp_uid' => $uid]);
        $poShare = MfPortfolioShare::firstOrNew(['mp_uid' => $uid, 'mp_portfolio_id' => $tmp_po_id]);
        $poShare->fill($data)->save();
    }
}
