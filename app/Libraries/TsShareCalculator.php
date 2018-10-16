<?php namespace App\Libraries;

use Artisan;
use DB;
use Log;
use App\RaFund;
use App\TsDividendFund;
use App\TsHoldingFund;
use App\TsOrder;
use App\TsOrderFund;
use App\TsPayMethod;
use App\TsShareFund;
use App\TsShareFundBuying;
use App\TsShareFundDetail;
use App\TsShareFundRedeeming;
use App\TsShareFundBonusing;
use App\TsStatementFund;

use App\Events\TsShareFundChanged;

use App\Libraries\Timing;
use App\Libraries\BatchUpdater;
use App\Libraries\FundShareCalculator;
use App\Libraries\MonetaryShareCalculator;
use App\Libraries\TsOrderDumper;

use function App\Libraries\basename_class;
use function App\Libraries\model_array_cud;
use function App\Libraries\intcmp;

trait TsShareCalculator
{
    public function calculate($uid, $poId, $code, $paymethod, $console = null)
    {
        $verbose = 3;
        if ($console) {
            $verbose = $console->getOutput()->getVerbosity();
        }

        if ($verbose > 1) {
            $timing = new Timing(basename_class(__CLASS__) . '@' . __FUNCTION__. "($uid, $poId, $code, $paymethod)");
        }

        $changed = false;
        $fund = RaFund::where('ra_code', $code)->first();

        $orders = TsOrderFund::where('ts_uid', $uid)
            ->where('ts_portfolio_id', $poId)
            ->where('ts_fund_code', $code)
            ->where('ts_pay_method', $paymethod)
            ->whereNotIn('ts_trade_type', [10, 11, 20, 21, 70, 71, 72, 97, 98])
            ->whereNotIn('ts_trade_status', [-3, -2, -1, 7, 9])
            ->orderBy('ts_placed_date', 'ASC')
            ->orderBy('ts_placed_time', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $dividends = TsDividendFund::where('ts_uid', $uid)
            ->where('ts_portfolio_id', $poId)
            ->where('ts_fund_code', $code)
            ->where('ts_pay_method', $paymethod)
            ->get();

        if ($console) {
            TsOrderDumper::dumpOrders($fund, $orders, $dividends, $console);
        }

        if ($fund->ra_type_calc == 3) {
            $calculator = new MonetaryShareCalculator($this->logtag, $fund, $orders, $dividends);
        } else {
            $calculator = new FundShareCalculator($this->logtag, $fund, $orders, $dividends);
        }

        try {
            $calculator->handle();

            $tmp = [
                'ts_uid' => $uid,
                'ts_portfolio_id' => $poId,
                'ts_fund_code' => $code,
                'ts_pay_method' => $paymethod,
            ];

            //
            // 保存份额
            //
            $shares = collect();
            foreach ($calculator->ts_share() as $s) {
                if (!$s['ts_share_id']) continue;

                $share = array_merge($tmp, $s, [
                    'ts_nav' => numfmt($s['ts_nav'], 4),
                    'ts_share' => numfmt($s['ts_share'], 4),
                    'ts_amount' => numfmt($s['ts_amount'], 2),
                    'ts_yield_uncarried' => numfmt($s['ts_yield_uncarried'], 2),
                    'ts_share_buying' => numfmt($s['ts_share_buying'], 4),
                    'ts_amount_buying' => numfmt($s['ts_amount_buying'], 2),
                    'ts_share_redeeming' => numfmt($s['ts_share_redeeming'], 4),
                    'ts_amount_redeeming' => numfmt($s['ts_amount_redeeming'], 2),
                    'ts_profit' => numfmt($s['ts_profit'], 2),
                    'ts_profit_acc' => numfmt($s['ts_profit_acc'], 2),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                $key = sprintf("%s|%s|%s", $share['ts_portfolio_id'], $share['ts_fund_code'], $share['ts_pay_method']);

                $shares->put($key, $share);
            }

            $olds = TsShareFund::where('ts_uid', $uid)
                ->where('ts_portfolio_id', $poId)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get()
                ->keyBy(function ($e) {
                    return sprintf("%s|%s|%s", $e['ts_portfolio_id'], $e['ts_fund_code'], $e['ts_pay_method']);
                });

            $keys = $shares->keys()->merge($olds->keys())->unique();
            foreach ($keys as $key) {
                $new = $shares->get($key);
                $old = $olds->get($key);

                if ($old && $new) {
                    //
                    // 更新
                    //
                    $old->fill($new);

                    if ($old->isDirty()) {
                        $change = true;
                        DirtyDumper::xlogDirty($old, 'ts_share_fund update', [
                            'ts_uid' => $old['ts_uid'],
                            'ts_portfolio_id' => $old['ts_portfolio_id'],
                            'ts_fund_code' => $old['ts_fund_code'],
                            'ts_pay_method' => $old['ts_pay_method'],
                        ]);
                        $old->save();
                    } else {
                        $old->touch();
                    }

                } else {
                    if ($new) {
                        //
                        // 新建
                        //
                        $row = new TsShareFund($new);
                        Log::info('ts_share_fund insert', [
                            'ts_uid' => $row['ts_uid'],
                            'ts_portfolio_id' => $row['ts_portfolio_id'],
                            'ts_fund_code' => $row['ts_fund_code'],
                            'ts_pay_method' => $row['ts_pay_method'],
                        ]);
                        $row->save();
                    } else {
                        Log::info('ts_share_fund delete', [
                            'ts_uid' => $old['ts_uid'],
                            'ts_portfolio_id' => $old['ts_portfolio_id'],
                            'ts_fund_code' => $old['ts_fund_code'],
                            'ts_pay_method' => $old['ts_pay_method'],
                        ]);
                        $old->delete();
                    }
                }
            }

            //
            // 保存份额明细
            //
            $rows = [];
            foreach ($calculator->ts_share_detail() as $d) {
                $rows[] = array_merge($tmp, $d, [
                    'ts_nav' => numfmt($d['ts_nav'], 4),
                    'ts_share' => numfmt($d['ts_share'], 4),
                ]);
            }

            $olds = TsShareFundDetail::where('ts_uid', $uid)
                ->where('ts_portfolio_id', $poId)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    if ($a['ts_share_id'] != $b['ts_share_id']) {
                        return strcmp($a['ts_share_id'], $b['ts_share_id']);
                    }
                    return strcmp($a['ts_trade_date'], $b['ts_trade_date']);
                }
            );
            BatchUpdater::batchWithInTransation('\App\TsShareFundDetail', $inserted, $updated, $deleted, true);
            if ($inserted || $updated || $deleted) {
                $changed = true;
            }

            //
            // 保存对账单
            //
            $rows = [];
            foreach ($calculator->ts_statment as $s) {
                $rows[] = array_merge($tmp, $s, [
                    "ts_stat_amount" => numfmt($s['ts_stat_amount'], 2),
                    "ts_stat_share" => numfmt($s['ts_stat_share'], 4),
                    "ts_stat_uncarried" => numfmt($s['ts_stat_uncarried'], 2),
                ]);
            }

            $olds = TsStatementFund::where('ts_uid', $uid)
                ->where('ts_portfolio_id', $poId)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    if ($a['ts_date'] != $b['ts_date']) {
                        return strcmp($a['ts_date'], $b['ts_date']);
                    }
                    return intcmp($a['ts_stat_type'], $b['ts_stat_type']);
                }
            );

            BatchUpdater::batchWithInTransation('\App\TsStatementFund', $inserted, $updated, $deleted, true);
            if ($inserted || $updated || $deleted) {
                $changed = true;
            }

            //
            // 保存持仓
            //
            $rows = [];
            foreach ($calculator->ts_holding_fund as $h) {
                $rows[] = array_merge($tmp, [
                    'ts_date' => $h['ts_date'],
                    'ts_share' => numfmt($h['ts_share'], 4),
                    'ts_amount' => numfmt($h['ts_amount'], 2),
                    'ts_share_redeeming' => numfmt($h['ts_share_redeeming'], 4),
                    'ts_amount_redeeming' => numfmt($h['ts_amount_redeeming'], 2),
                ]);
            }

            $olds = TsHoldingFund::where('ts_uid', $uid)
                ->where('ts_portfolio_id', $poId)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    return strcmp($a['ts_date'], $b['ts_date']);
                }
            );

            BatchUpdater::batchWithInTransation('\App\TsHoldingFund', $inserted, $updated, $deleted, true);

            //
            // 保存购买上下文
            //
            $rows = [];
            foreach ($calculator->ts_share_fund_buying() as $r) {
                $rows[] = array_merge($tmp, $r, [
                    'ts_nav' => numfmt($r['ts_nav'], 4),
                    'ts_share' => numfmt($r['ts_share'], 4),
                    'ts_amount' => numfmt($r['ts_amount'], 2),
                ]);
            }

            $olds = TsShareFundBuying::where('ts_uid', $uid)
                ->where('ts_portfolio_id', $poId)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    return strcmp($a['ts_order_id'], $b['ts_order_id']);
                }
            );

            BatchUpdater::batchWithInTransation('\App\TsShareFundBuying', $inserted, $updated, $deleted, true);
            if ($inserted || $updated || $deleted) {
                $changed = true;
            }

            //
            // 保存赎回上下文
            //
            $rows = [];
            foreach ($calculator->ts_share_fund_redeeming() as $r) {
                $rows[] = array_merge($tmp, $r, [
                    'ts_trade_nav' => numfmt($r['ts_trade_nav'], 4),
                    'ts_latest_nav' => numfmt($r['ts_latest_nav'], 4),
                    'ts_share' => numfmt($r['ts_share'], 4),
                ]);
            }
            $olds = TsShareFundRedeeming::where('ts_uid', $uid)
                ->where('ts_portfolio_id', $poId)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    return strcmp($a['ts_order_id'], $b['ts_order_id']);
                }
            );

            BatchUpdater::batchWithInTransation('\App\TsShareFundRedeeming', $inserted, $updated, $deleted, true);
            if ($inserted || $updated || $deleted) {
                $changed = true;
            }

            //
            // 保存分红上下文
            //
            $rows = [];
            foreach ($calculator->ts_share_fund_bonusing() as $r) {
                $rows[] = array_merge($tmp, $r, [
                    'ts_share' => numfmt($r['ts_share'], 4),
                    'ts_bonus_amount' => numfmt($r['ts_bonus_amount'], 2),
                    'ts_bonus_share' => numfmt($r['ts_bonus_share'], 4),
                    'ts_bonus_ratio' => numfmt($r['ts_bonus_ratio'], 6),
                ]);
            }

            $olds = TsShareFundBonusing::where('ts_uid', $uid)
                ->where('ts_portfolio_id', $poId)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    return strcmp($a['ts_share_id'], $b['ts_share_id']);
                }
            );

            BatchUpdater::batchWithInTransation('\App\TsShareFundBonusing', $inserted, $updated, $deleted, true);
            if ($inserted || $updated || $deleted) {
                $changed = true;
            }

        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
        }

        return $changed;
    }

}
