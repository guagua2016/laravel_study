<?php
namespace App\Libraries;

use Carbon\Carbon;

use DB;
use Log;

class TdateLookupTable
{
    public $beginDate;
    public $endDate;

    protected $dates = [];

    public static function build($dates)
    {
        $inst = new static;

        $beginDate = null;
        $endDate = null;
        foreach ($dates as $day) {
            $inst->dates[$day] = 1;

            if ($beginDate === null || $beginDate > $day) {
                $beginDate = $day;
            }
            if ($endDate === null || $endDate < $day) {
                $endDate = $day;
            }
        }
        $inst->beginDate = Carbon::parse($beginDate);
        $inst->endDate = Carbon::parse($endDate);

        return $inst;
    }

    /**
     * 获取当前自然日的前一个净值日期
     * @param $day 当前自然日
     */
    public function prevTo($day)
    {
        $carbon = Carbon::parse($day);
        do {
            $carbon->subDay();
            $temp = $carbon->toDateString();
            if (array_key_exists($temp, $this->dates)) {
                return $temp;
            }
        } while ($carbon->gte($this->beginDate));

        return null;
    }
}
