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
use App\TsWalletHolding;
use App\TsWalletShare;
use App\TsWalletShareAcking;
use App\TsWalletStatement;

use App\Events\TsShareFundChanged;

use App\Libraries\Timing;
use App\Libraries\BatchUpdater;
use App\Libraries\MonetaryShareCalculator;
use App\Libraries\TsOrderDumper;

use function App\Libraries\basename_class;
use function App\Libraries\model_array_cud;
use function App\Libraries\intcmp;

trait TsWalletShareCalculator
{
    public function calculate($uid, $code, $paymethod, $console = null)
    {
        $verbose = 1;
        if ($console) {
            $verbose = $console->getOutput()->getVerbosity();
        }

        if ($verbose > 1) {
            $timing = new Timing(basename_class(__CLASS__) . '@' . __FUNCTION__. "($uid, $code, $paymethod)");
        }

        $changed = false;
        $fund = RaFund::where('ra_code', $code)->first();

        //
        // 对于购买确认的情况, -2 是需要计入的，因为后面有个退款充值订单，如果不计入，无法平账。
        //
        //
        $orders = TsOrderFund::where('ts_uid', $uid)
            ->where('ts_pay_method', $paymethod)
            ->whereRaw('((ts_trade_type IN (10, 11, 12, 13, 14, 19, 20, 21, 22, 26, 29, 71) AND ts_trade_status IN (0, 1, 5, 6)) OR (ts_trade_type IN (31, 51, 63) AND ts_trade_status IN (-2, 0, 1, 5, 6)))')
            ->orderBy('ts_placed_date', 'ASC')
            ->orderBy('ts_placed_time', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        $dividends = TsDividendFund::where('ts_uid', $uid)
            ->where('ts_fund_code', $code)
            ->where('ts_pay_method', $paymethod)
            ->get();

        if ($console) {
            TsOrderDumper::dumpOrders($fund, $orders, $dividends, $console);
        }

        $calculator = new WalletShareCalculator($this->logtag, $fund, $orders, $dividends, $verbose);

        try {
            $calculator->handle();

            $tmp = [
                'ts_uid' => $uid,
                'ts_fund_code' => $code,
                'ts_pay_method' => $paymethod,
            ];

            //
            // 保存份额
            //
            $shares = collect();
            foreach ($calculator->ts_share() as $s) {
                // if (!$s['ts_share_id']) continue;

                $share = array_merge($tmp, $s, [
                    'ts_share' => numfmt($s['ts_share']),
                    'ts_share_charging1' => numfmt($s['ts_share_charging1']),
                    'ts_share_charging21' => numfmt($s['ts_share_charging21']),
                    'ts_share_charging22' => numfmt($s['ts_share_charging22']),
                    'ts_share_charging3' => numfmt($s['ts_share_charging3']),
                    'ts_share_transfering' => numfmt($s['ts_share_transfering']),
                    'ts_share_transfering3' => numfmt($s['ts_share_transfering3']),
                    'ts_share_withdrawing' => numfmt($s['ts_share_withdrawing']),
                    'ts_share_withdrawing3' => numfmt($s['ts_share_withdrawing3']),
                    'ts_share_redeeming' => numfmt($s['ts_share_redeeming']),
                    'ts_share_redeeming3' => numfmt($s['ts_share_redeeming3']),
                    'ts_amount_avail' => numfmt($s['ts_amount_avail']),
                    'ts_amount_buying' => numfmt($s['ts_amount_buying']),
                    'ts_amount_adjusting' => numfmt($s['ts_amount_adjusting']),
                    'ts_amount_withdrawing' => numfmt($s['ts_amount_withdrawing']),
                    'ts_amount_paying' => numfmt($s['ts_amount_paying']),
                    'ts_amount_refund' => numfmt($s['ts_amount_refund']),
                    'ts_amount_redeemable' => numfmt($s['ts_amount_redeemable']),
                    'ts_amount_withdrawable' => numfmt($s['ts_amount_withdrawable']),
                    'ts_profit' => numfmt($s['ts_profit']),
                    'ts_profit_acc' => numfmt($s['ts_profit_acc']),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                $key = sprintf("%s|%s", $share['ts_fund_code'], $share['ts_pay_method']);

                $shares->put($key, $share);
            }

            $olds = TsWalletShare::where('ts_uid', $uid)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get()
                ->keyBy(function ($e) {
                    return sprintf("%s|%s", $e['ts_fund_code'], $e['ts_pay_method']);
                });

            $keys = $shares->keys()->merge($olds->keys())->unique();
            foreach ($keys as $key) {
                $new = $shares->get($key);
                if ($new) {
                    unset($new['ts_share_id']);
                    unset($new['ts_trade_date']);
                    unset($new['ts_yield_uncarried']);
                }
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
                        $row = new TsWalletShare($new);
                        Log::info('ts_share_fund insert', [
                            'ts_uid' => $row['ts_uid'],
                            'ts_fund_code' => $row['ts_fund_code'],
                            'ts_pay_method' => $row['ts_pay_method'],
                        ]);
                        $row->save();
                    } else {
                        Log::info('ts_share_fund delete', [
                            'ts_uid' => $old['ts_uid'],
                            'ts_fund_code' => $old['ts_fund_code'],
                            'ts_pay_method' => $old['ts_pay_method'],
                        ]);
                        $old->delete();
                    }
                }
            }

            //
            // 保存对账单
            //
            $rows = [];
            foreach ($calculator->ts_statment as $s) {
                $rows[] = array_merge($tmp, $s, [
                    "ts_stat_amount" => numfmt($s['ts_stat_amount'], 2),
                    "ts_stat_share" => numfmt($s['ts_stat_share'], 2),
                    "ts_stat_uncarried" => numfmt($s['ts_stat_uncarried'], 2),
                ]);
            }

            $olds = TsWalletStatement::where('ts_uid', $uid)
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

            BatchUpdater::batchWithInTransation('\App\TsWalletStatement', $inserted, $updated, $deleted, true);
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
                    'ts_share' => numfmt($h['ts_share']),
                    'ts_share_charging1' => numfmt($h['ts_share_charging1']),
                    'ts_share_charging21' => numfmt($h['ts_share_charging21']),
                    'ts_share_charging22' => numfmt($h['ts_share_charging22']),
                    'ts_share_charging3' => numfmt($h['ts_share_charging3']),
                    'ts_share_transfering' => numfmt($h['ts_share_transfering']),
                    'ts_share_transfering3' => numfmt($h['ts_share_transfering3']),
                    'ts_share_redeeming' => numfmt($h['ts_share_redeeming']),
                    'ts_share_redeeming3' => numfmt($h['ts_share_redeeming3']),
                    'ts_share_withdrawing' => numfmt($h['ts_share_withdrawing']),
                    'ts_share_withdrawing3' => numfmt($h['ts_share_withdrawing3']),
                    'ts_amount_avail' => numfmt($h['ts_amount_avail']),
                    'ts_amount_buying' => numfmt($h['ts_amount_buying']),
                    'ts_amount_adjusting' => numfmt($h['ts_amount_adjusting']),
                    'ts_amount_withdrawing' => numfmt($h['ts_amount_withdrawing']),
                    'ts_amount_paying' => numfmt($h['ts_amount_paying']),
                    'ts_amount_refund' => numfmt($h['ts_amount_refund']),
                    'ts_amount_redeemable' => numfmt($h['ts_amount_redeemable']),
                    'ts_amount_withdrawable' => numfmt($h['ts_amount_withdrawable']),
                    'ts_uncarried' => numfmt($h['ts_uncarried']),
                ]);
            }
            // dd($rows);

            $olds = TsWalletHolding::where('ts_uid', $uid)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    return strcmp($a['ts_date'], $b['ts_date']);
                }
            );

            BatchUpdater::batchWithInTransation('\App\TsWalletHolding', $inserted, $updated, $deleted, true);

            //
            // 保存购买上下文
            //
            $rows = [];
            foreach ($calculator->ts_wallet_share_acking() as $r) {
                $rows[] = array_merge($tmp, [
                    'ts_order_id' => $r['ts_order_id'],
                    'ts_trade_type' => $r['ts_trade_type'],
                    'ts_amount' => numfmt($r['ts_amount']),
                    'ts_share' => numfmt($r['ts_share']),
                    'ts_trade_date' => $r['ts_trade_date'],
                    'ts_acked_date' => $r['ts_acked_date'],
                    'ts_redeemable_date' => $r['ts_redeemable_date'],
                    'ts_share_id' => $r['ts_share_id'],
                ]);
            }

            $olds = TsWalletShareAcking::where('ts_uid', $uid)
                ->where('ts_fund_code', $code)
                ->where('ts_pay_method', $paymethod)
                ->get();
            list($inserted, $updated, $deleted) = model_array_cud(
                $olds->all(), $rows, function ($a, $b) {
                    return strcmp($a['ts_order_id'], $b['ts_order_id']);
                }
            );

            BatchUpdater::batchWithInTransation('\App\TsWalletShareAcking', $inserted, $updated, $deleted, true);
            if ($inserted || $updated || $deleted) {
                $changed = true;
            }

        } catch(\Exception $e) {
            Log::error(sprintf($this->logtag."Caught exception: %s\n%s",  $e->getMessage(), $e->getTraceAsString()));
        }

        return $changed;
    }

}
