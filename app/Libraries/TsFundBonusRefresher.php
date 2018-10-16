<?php namespace App\Libraries;

use Artisan;
use DB;
use Log;
use App\TsFundBonus;
use App\TsJournalFundBonus;

use App\Events\TsFundBonusChanged;

use App\Libraries\Timing;
use App\Libraries\BatchUpdater;
use App\Libraries\FundShareCalculator;
use App\Libraries\MonetaryShareCalculator;
use App\Libraries\TsOrderDumper;

use function App\Libraries\basename_class;
use function App\Libraries\model_array_cud;
use function App\Libraries\intcmp;

trait TsFundBonusRefresher
{
    public function refresh($code)
    {
        //
        // 我们需要使用$date日最新的一条记录，我们的取法是按照基金代码和ym_ts
        // 升序排序，然后通过keyBy()获取同一key的最后一条.
        //

        $rows = TsJournalFundBonus::where('ts_fund_code', $code)
            ->orderBy('ts_record_date', 'ASC')
            ->orderBy('ts_status', 'ASC')
            ->orderBy('ts_ts', 'ASC')
            ->get();

        $latest = $rows->keyBy('ts_record_date');

        $news = [];
        foreach ($latest as $r) {
            if ($r->ts_op == '-') {
                continue;
            }
            $nav = $r->ts_nav;
            $news[] = [
                'ts_fund_code' => $r->ts_fund_code,
                'ts_record_date' => $r->ts_record_date,
                'ts_dividend_date' => $r->ts_dividend_date,
                'ts_payment_date' => $r->ts_payment_date,
                'ts_bonus' => $r->ts_bonus,
                'ts_bonus_nav' => $r->ts_bonus_nav,
                'ts_bonus_nav_date' => $r->ts_bonus_nav_date,
                'ts_origin' => $r->ts_origin,
            ];
        }

        $model = TsFundBonus::where('ts_fund_code', $code);
        $olds = $model->get();
        list($inserted, $updated, $deleted) = model_array_cud(
            $olds->all(), $news, function ($a, $b) {
                return strcmp($a['ts_record_date'], $b['ts_record_date']);
            }
        );

        BatchUpdater::batchWithInTransation('\App\TsFundBonus', $inserted, $updated, $deleted, true);
        //
        // 触发净值更新事件
        //
        if (!empty($inserted) || !empty($updated) || !empty($deleted)) {
            Log::info($this->logtag."will fireTsFundBonusChangedEvent($code)");
            // $this->fireTsFundBonusChangedEvent($e['ts_fund_code']);
            return true;
        } else {
            return false;
        }

    }

    public function fireTsFundBonusChangedEvent($code)
    {
        $updateAt = TsFundBonus::where('ts_fund_code', $code)->max('updated_at');

        // $verbose = $this->getOutput()->getVerbosity();
        $verbose = 2;

        event(new TsFundBonusChanged($code, $updateAt, $verbose));
    }

}
