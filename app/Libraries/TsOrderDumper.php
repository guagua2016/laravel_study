<?php namespace App\Libraries;

use Log;

class TsOrderDumper
{
    public static function dumpOrders($fund, $orders, $dividends, $console)
    {
        $verbose = $console->getOutput()->getVerbosity();
        if ($verbose < 4) {
            return;
        }

        $data = [];
        foreach ($orders as $o) {
            array_push($data, [
                $o->id,
                $o->ts_txn_id,
                $o->ts_uid,
                $o->ts_portfolio_id,
                $o->ts_fund_code,
                $o->ts_fund_name,
                $o->ts_pay_method,
                $o->ts_trade_type,
                $o->ts_trade_status,
                $o->ts_trade_date,
                $o->ts_trade_nav,
                $o->ts_acked_date,
                $o->ts_acked_amount,
                $o->ts_acked_share,
                $o->ts_acked_fee,
                $o->ts_placed_date,
                $o->ts_placed_time,
                $o->ts_placed_amount,
                $o->ts_placed_share,
                $o->ts_portfolio_txn_id,
            ]);
        }
        if ($fund->ra_type_calc != 3) {
            foreach ($dividends as $d) {
                array_push($data, [
                    $d->id,
                    '',
                    $d->ts_uid,
                    $d->ts_portfolio_id,
                    $d->ts_fund_code,
                    '',
                    $d->ts_pay_method,
                    '73',
                    '',
                    $d->ts_dividend_date,
                    '',
                    $d->ts_dividend_date,
                    $d->ts_dividend_amount,
                    $d->ts_dividend_share,
                    '',
                    $d->ts_record_date,
                    '',
                    $d->ts_dividend_amount,
                    $d->ts_dividend_share,
                    '',
                ]);
            }
        }

        usort($data, function ($a, $b) {
            if ($a[9] != $b[9]) {
                return strcmp($a[9], $b[9]);
            }

            if ($a[15] != $b[15]) {
                return strcmp($a[15], $b[15]);
            }

            return strcmp($a[16], $b[16]);
        });

        $console->table([
            "id",
            "txn_id",
            "uid",
            "po_id",
            "fcode",
            "fname",
            'pay',
            "ty",
            "st",
            "trade_date",
            "tnav",
            "acked_date",
            "acked_amount",
            "acked_share",
            "acked_fee",
            "placed_date",
            "placed_time",
            "placed_amount",
            "placed_share",
            "po_txn_id",
        ], $data);
    }
}
