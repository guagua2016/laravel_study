<?php

namespace App\Libraries;

use App\Jobs\Job;
use Illuminate\Contracts\Bus\SelfHandling;

use Carbon\Carbon;

use Log;

use App\RaFund;
use App\BaseRaFundNav;
use App\FundSplit;
use App\TsFundBonus;

use App\Libraries\Timing;
use function App\Libraries\basename_class;
use function App\Libraries\date_range;
use function App\Libraries\floorp_format;
use function App\Libraries\floorp;

class FundShareCalculator
{
    protected $logtag = '';
    protected $fund;
    protected $orders = [];
    protected $dividends = [];

    //
    // 以下是计算结果
    //
    public $ts_holding_fund = [];  // 当日持仓
    public $ts_statment = [];      // 对账单

    //
    // 以下是计算上下文
    //
    protected $yield = 0;                // 当日收益
    protected $lastHoldAmount = 0;       // 昨日持仓(自然日)
    public    $nav = 1;                  // 当前净值
    public    $navDate = '0000-00-00';   // 净值日期
    public    $shareNav = 1;             // 份额净值
    public    $shareDate = '0000-00-00'; // 份额日期(持仓是等于$navDate, 空仓时保留最后一次净值日期);
    protected $divMode = 1;              // 当前分红方式
    protected $share = null;             // 当前份额
    protected $details = null;           // 当前份额的FIFO明细
    protected $buying = null;            // 购买待确认份额
    protected $redeeming = null;         // 当前赎回中的份额
    protected $bonusing = null;          // 当前分红上下文
    //
    // 流水类型(1:申购(+);2:赎回(-);3:申购费(-);4:赎回费(-)5:日收益(+);6:未结转收益(+);7:分红支出(-);8:分红收入(+);9:收益结转(+);10:强制结转(+)
    //
    const ST_SUB = 1;
    const ST_REDEEM = 2;
    const ST_SUB_FEE = 3;
    const ST_REDEEM_FEE = 4;
    const ST_YIELD = 5;
    const ST_UNCARRY = 6;
    const ST_BONUS_OUT = 7;
    const ST_BONUS_IN = 8;
    const ST_CARRY = 9;
    const ST_CARRY_FORCE = 10;
    const ST_ADD_FORCE = 11;
    const ST_DEL_FORCE = 12;
    const ST_SPLIT = 13;
    //
    // 对账单上下
    //
    protected $stat = [
        self::ST_SUB => 0,
        self::ST_REDEEM => 0,
        self::ST_SUB_FEE => 0,
        self::ST_REDEEM_FEE => 0,
        self::ST_YIELD => 0,
        self::ST_UNCARRY => 0,
        self::ST_BONUS_OUT => 0,
        self::ST_BONUS_IN => 0,
        self::ST_CARRY => 0,
        self::ST_CARRY_FORCE => 0,
        self::ST_ADD_FORCE => 0,
        self::ST_DEL_FORCE => 0,
        self::ST_SPLIT => 0,
    ];

    public function ts_share()
    {
        //
        // 计算shareBuying
        //
        $shareBuying = \array_sum(array_column($this->buying, 'ts_share'));

        //
        // 计算shareRedeeming
        //
        @list($shareRedeeming, $amountRedeeming) = [0, 0];
        foreach ($this->redeeming as $r) {
            $shareRedeeming += $r['ts_share'];
            if (number_format($r['ts_trade_nav'], 4, '.', '') != '0.0000') {
                $amountRedeeming += $r['ts_share'] * $r['ts_trade_nav'];
            } else {
                $amountRedeeming += $r['ts_share'] * $r['ts_latest_nav'];
            }
        }

        $profitAcc = 0;
        foreach ($this->ts_statment as $st) {
            if (in_array($st['ts_stat_type'], [3, 4, 5, 6, 9, 10])) {
                $profitAcc += ($st['ts_stat_amount'] + $st['ts_stat_uncarried']);
            }
        }

        $shares = [];
        $shares[] = array_merge($this->share, [
            'ts_nav' => $this->shareNav,
            'ts_date' => $this->shareDate,
            'ts_amount' => $this->share['ts_share'] * $this->nav,
            'ts_share_buying' => $shareBuying,
            'ts_amount_buying' => $shareBuying * $this->nav,
            'ts_share_redeeming' => $shareRedeeming,
            'ts_amount_redeeming' => $amountRedeeming,
            'ts_profit' => $this->yield,
            'ts_profit_acc' => $profitAcc,

        ]);
        return $shares;
    }

    public function ts_share_detail()
    {
        $details = [];
        foreach ($this->details as $detail) {
            $detail['ts_share_id'] = $this->share['ts_share_id'];
            $detail['ts_nav'] = $this->nav;
            $detail['ts_nav_date'] = $this->navDate;
            $details[] = $detail;
        }
        return $details;
    }

    public function ts_share_fund_buying()
    {
        return $this->buying;
    }

    public function ts_share_fund_redeeming()
    {
        return $this->redeeming;
    }

    public function ts_share_fund_bonusing()
    {
        if (!empty($this->bonusing)) {
            return [$this->bonusing];
        } else {
            return [];
        }
    }
    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($logtag, $fund, $orders, $dividends)
    {
        $this->logtag = $logtag;
        $this->fund   = $fund;
        $this->orders = $orders;
        $this->dividends  = $dividends->keyBy('ts_record_date');

        $this->ts_holding_fund = [];  // 当日持仓
        $this->ts_statment = [];      // 对账单
        $this->buying = [];           // 当前购买中的份额
        $this->redeeming = [];        // 当前赎回中的份额
        $this->bonusing = [];         // 当前分红上下文
        $this->share = [              // 当前份额
            'ts_share_id'        => 0,
            'ts_trade_date'      => '0000-00-00',
            'ts_share'           => 0,
            'ts_yield_uncarried' => 0,
        ];
        $this->details = [];

        $this->today = date('Y-m-d');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // $timing = new Timing(sprintf($this->logtag.'%s@%s [%s]', basename_class(__CLASS__), __FUNCTION__, $this->fund->ra_code));

        $events = [];

        # 事件类型:0:日内第一个事件;1:申购;2:赎回;3:分红;4:净值更新;5:强制赎回;8:强增; 9强减;11:申购确认;12:赎回到账;13:修改分红方式;14:收益结转;15:分红登记;16:分红除息;17:分红派息;18:基金分拆;19:记录当日持仓;
        #         100:例行事件生成;101:记录当前持仓;
        #
        # 事件队列，基于heapq实现，元组来实现
        #
        #     (dt, op, order_id, fund_id, argv)
        #
        # 其中，
        #
        #     dt： 日内事件处理时间，主要用于事件在队列中的排序，不一
        #          定等同于下单时间。比如：3:00pm前下的购买订单的处理
        #          时间可能是16:00，因为15:00净值才出来，而购买赎回都
        #          需要使用当天的净值。
        #
        #          各个事件的处理时间如下：
        #
        #          00:00:01 生成持仓相关的当日例行事件（更新净值，分拆，分红，记录当日持仓）
        #          01:00:00 分拆
        #          01:30:00 强增
        #          02:00:00 购买确认
        #          03:00:00 赎回确认
        #          14:50:00 分红权益登记
        #          15:00:00 更新净值
        #          16:30:00 分红除息
        #          16:40:00 分红红利再投
        #          16:45:00 分红派息
        #          17:00:00 调仓处理
        #          18:00:00 购买
        #          18:30:00 赎回
        #          23:59:59 记录当日持仓
        #
        #          时间的处理顺序之所以这样排序，原因如下：
        #
        #          由于分拆日的净值是日末净值，也就是分拆之后的，所以
        #          分拆需要在更新净值之前进行。
        #
        #          分析交易记录得知，强增其实就是分拆
        #
        #          分红的权益登记应该在更新净值之前进行，因为计算除息日
        #          的日收益需要用分红比例调整除息日净值。
        #
        #          购买的确认和赎回确认也需要在更新净值在前进行，因为T
        #          日确认的购买份额在T日是可以赎回的，所以应该在开盘之
        #          前完成结算。T日确认的赎回份额，在T日不再计算收益，
        #          所以需要在在更新净值之前完成
        #
        #          理论上，分拆和购买/赎回确认的顺序可以互换，甚至先处
        #          理确认，再处理分拆可能更合理，但代码写出先分拆了，
        #          因为不影响最后结果，也就不改了。
        #
        #          分红出息和派息，申购，赎回依赖当日净值，所以需要在净值更新之
        #          后进行。具体的，红利在投资的份额是依据除息日的净值
        #          的。申购和赎回的是按T日的净值计算金额的。
        #
        #          分红需要在购买和赎回之前进行，因为权益登记日购买的
        #          份额是不享受分红的，权益登记赎回的份额是享受分红的。
        #
        #          分红本身的权益登记、除息、派息需要按顺序进行，因为
        #          三个日子可能是一天。
        #
        $beginDate = Carbon::today()->toDateString();
        foreach ($this->orders as $order) {
            if ($order->ts_trade_type == 72) {
                continue;
            }

            //
            // 如果不是完结订单，我们只处理赎回。
            //
            if (in_array($order->ts_trade_status, [0, 1])) {
                if (!in_array($order->ts_trade_type, [40, 41, 62, 64])) {
                    continue;
                }
            }

            if ($order->ts_placed_date != '0000-00-00'
                && $beginDate > $order->ts_placed_date) {
                $beginDate = $order->ts_placed_date;
            }

            $xtab = [
                30 => 1,        // 30:银行卡购买;
                31 => 1,        // 31:钱包购买;
                40 => 2,        // 40:赎回银行卡;
                41 => 2,        // 41:赎回到钱包;
                43 => 5,        // 43:强制赎回;
                50 => 1,        // 50:现金定投;
                51 => 1,        // 51:钱包定投;
                61 => 1,        // 61:调仓购买（老）
                62 => 2,        // 62:调仓赎回（老）
                63 => 1,        // 63:调仓购买；
                64 => 2,        // 64:调仓赎回;
                70 => 3,        // 70:分红到银行卡;
                71 => 3,        // 71:分红到钱包;
                72 => 13,       // 72:修改分红方式;
                // 91:转出;
                // 92:转入;
                93 => 8,        // 93:强增;
                94 => 9,        // 94:强减;
                // 95:非交易过户转出;非交易过户转入)
            ];

            if (array_key_exists($order->ts_trade_type, $xtab)) {
                $type = $xtab[$order->ts_trade_type];
            } else {
                // dd("unknown trade type", $order);
                Log::error($this->logtag."unknown trade type", [$order]);
                $alert = sprintf($this->logtag."订单交易类型未知:[%s,%d]", $order->ts_txn_id, $order->ts_trade_type);
                SmsService::smsAlert($alert, 'kun');
                continue;
            }
            $placedAt = sprintf("%s %s", $order->ts_placed_date, $order->ts_placed_time);

            //
            // 首先看是不是强增,基金的分拆是通过强增的方式记录的（例如160127），
            // 如果是强增事件，因为订单返回的强增日期与基金分拆并不是一天，所
            // 以需要将日期换成对应的分拆那天。
            //
            // 如果不换成分拆日，则分拆那天会有大幅亏损。观察盈米数据可知，盈
            // 米也是这么处理的。
            //
            if ($type == 8) {
                $sdate = Carbon::parse($order->ts_trade_date)->subDays(10)->toDateString();
                $fs = FundSplit::where('fs_fund_id', $this->fund->globalid)
                    ->whereBetween('fs_split_date', [$sdate, $order->ts_trade_date])
                    ->orderBy('fs_split_date', 'DESC')
                    ->first();
                if ($fs && $order->ts_trade_date != $fs->fs_split_date) {
                    Log::info('replace ts_trade_date with fs_split_date', ['order' => $order->toArray(), 'split' => $fs->toArray()]);
                    $order->ts_trade_date = $fs->fs_split_date;
                }
                $e1 = [
                    'it_type' => $type,
                    'it_date' => $order->ts_trade_date,
                    'it_time' => '01:30:00',
                    'it_amount' => $order->ts_acked_amount,
                    'it_share' => $order->ts_acked_share,
                    'it_acked_date' => $order->ts_acked_date,
                    'it_trade_date' => $order->ts_trade_date,
                    'it_nav' => $order->ts_trade_nav,
                    'it_order_id' => $order->ts_txn_id,
                    'it_placed_at' => $placedAt,
                ];
                if ($fs) {
                    $e1['it_split'] = $fs->fs_split_proportion;
                }
            } else {
                if ($type == 1) {
                    $time = '18:00:00';
                } elseif ($type == 2) {
                    $time = '18:30:00';
                } else {
                    $time = '16:00:00';
                }

                //
                // 生成订单事件
                //
                if (in_array($order->ts_trade_status, [0, 1])) {
                    // 如果是未确认的赎回订单，需要使用下单份额
                    $it_share = $order->ts_placed_share;
                    if ($type == 2) {
                        //
                        // 赎回的情况，我们不能使用 ts_placed_amount, 因为是预估的
                        //
                        $it_amount = 0;
                    } else {
                        $it_amount = $order->ts_placed_amount;
                    }
                    if ($order->ts_trade_date == '0000-00-00') {
                        //
                        // 如果是刚刚授理的订单,没有交易日期,则我们只是简单的
                        // 预估注意：预估需要用ts_acceptd_at,不能用
                        // ts_scheduled_at,也就是一旦受理，我们就认为这笔赎回
                        // 已经锁定了，否则在延迟赎回时会带来问题，导致延迟赎
                        // 回的被多次赎回。
                        //
                        $dt = $order->ts_accepted_at;
                        $order->ts_trade_date = TradeDate::tradeDatewithTime(
                            substr($dt, 0, 10), substr($dt, 11), 0);
                    }
                    if ($order->ts_trade_date <= $this->today) {
                        $it_date = $order->ts_trade_date;
                    } else {
                        if ($order->ts_placed_date != '0000-00-00') {
                            $it_date = $order->ts_placed_date;
                        } else {
                            $it_date = substr($order->ts_accepted_at, 0, 10);
                        }
                    }
                } else {
                    $it_share = $order->ts_acked_share;
                    $it_amount = $order->ts_acked_amount;
                    $it_date = $order->ts_trade_date;
                }
                $e1 = [
                    'it_type' => $type,
                    'it_date' => $it_date,
                    'it_time' => $time,
                    'it_amount' => $it_amount,
                    'it_share' => $it_share,
                    'it_fee' => $order->ts_acked_fee,
                    'it_nav' => $order->ts_trade_nav,
                    'it_acked_date' => $order->ts_acked_date,
                    'it_order_id' => $order->ts_txn_id,
                    'it_trade_date' => $order->ts_trade_date,
                    'it_placed_at' => $placedAt,
                ];
            }

            $events[] = $e1;

            //
            // 如果是购买和赎回，需要生产确认订单
            //
            if (in_array($type, [1, 2]) && in_array($order->ts_trade_status, [5, 6])) {
                if ($type == 1) {
                    list($type, $time) = [11, '00:02:00'];
                } else {
                    list($type, $time) = [12, '00:03:00'];
                }

                $e2 = array_replace($e1, [
                    'it_type' => $type,
                    'it_date' => $order->ts_acked_date,
                    'it_time' => $time,
                ]);

                $events[] = $e2;
            }
        }

        // $beginDate = $this->orders->min('ts_placed_date');
        $endDate = Carbon::today()->toDateString();

        //
        // 加载分红信息
        //
        // 基金的分红信息必须使用盈米的分红信息，因为只有该信息是基金公司官方
        // 给出的，是准确的。经过比较发现，分红记录中的权益登记日和分红表里的权益登记日是一样的。
        //
        // [XXX] 这里做一个非常trivial的设计，因为我们发现有两个用户有现金分红
        // 的记录，这是因为最老的单只基金购买留下的记录。之后理论上不会再出现。
        // 处理方法是这样的：如果发现用户有现金分红的金额，则这次分红不生成红
        // 利再投事件，通过在除息事件中增加一个it_dividend_amount的域。
        //
        $rows = TsFundBonus::where('ts_fund_code', $this->fund->ra_code)
            ->whereBetween('ts_record_date', [$beginDate, $endDate])
            ->get();
        foreach ($rows as $row) {
            //
            // 如果（除息日>今天 || （除息日==今天，但今天净值未出）) 则跳过该记录
            //
            if ($row->ts_dividend_date > $this->today
                || ($row->ts_dividend_date == $this->today && BaseRaFundNav::getNav($this->fund->ra_code, $row->ts_dividend_date, true) === null)) {
                continue;
            }
            if ($row->ts_bonus_nav == '0.0000') {
                //
                // 获取红利再投日净值
                //
                $tmp = BaseRaFundNav::where('ra_code', $this->fund->ra_code)
                    ->where('ra_date', '>=', $row->ts_bonus_nav_date)
                    ->where('ra_mask', 0)
                    ->orderBy('ra_date', 'ASC')
                    ->first();
                if (!$tmp) {
                    Log::warning($this->logtag."skip bonus due zero nav", $row->toArray());
                    continue;
                } else {
                    $row->ts_bonus_nav = $tmp->ra_nav;
                }
            }


            $recordDate = min($row->ts_record_date, $row->ts_dividend_date);

            $e1 = [
                'it_type' => 15,
                'it_date' => $recordDate,
                'it_time' => '14:50:00',
                'it_bonus' => $row->ts_bonus,
                'it_record_date' => $recordDate,
                'it_dividend_date' => $row->ts_dividend_date,
                'it_payment_date' => $row->ts_payment_date,
            ];

            $e2 = [
                'it_type' => 16,
                'it_date' => $row->ts_dividend_date,
                'it_time' => '16:30:00',
                'it_bonus' => $row->ts_bonus,
                'it_placed_at' => '0000-00-00 00:00:00',
                'it_record_date' => $row->ts_record_date,
            ];

            $dividend = null;
            if ($this->dividends->has($row->ts_record_date)) {
                $dividend = $this->dividends->get($row->ts_record_date);
            } elseif ($this->dividends->has($row->ts_dividend_date)) {
                $dividend = $this->dividends->get($row->ts_dividend_date);
            }

            if ($dividend && $dividend->ts_dividend_amount > 0) {
                $e2['it_dividend_amount'] = $dividend->ts_dividend_amount;
                array_push($events, $e1, $e2);
            } else {
                $e3 = [
                    'it_type' => 17,
                    'it_date' => $row->ts_bonus_nav_date,
                    'it_time' => '16:40:00',
                    'it_bonus_nav' => $row->ts_bonus_nav,
                    'it_placed_at' => '0000-00-00 00:00:00',
                    'it_record_date' => $row->ts_record_date,
                ];

                if ($dividend) {
                    $e2['it_dividend_share'] = $dividend->ts_dividend_share;
                    $e3['it_dividend_share'] = $dividend->ts_dividend_share;
                }
                // dd($this->dividends, $row->toArray(), $e3);
                array_push($events, $e1, $e2, $e3);
            }
        }

        //
        // 加载分拆信息
        //
        // 加载数据库中的操作列表。停用！！！[XXX] 分析交易记录发现，分拆操作
        // 在交易记录里面记录为强增，因此，通过强增的逻辑来处理分拆，这里不再
        // 单独加载。
        //
        if ($this->fund->ra_code == '164705') {

            $xxdates = $this->orders->filter(function ($e) { return $e->ts_trade_type == 93; })->lists('ts_trade_date');

            $rows = FundSplit::where('fs_fund_id', $this->fund->globalid)
                  ->whereBetween('fs_split_date', [$beginDate, $endDate])
                  ->get();
            foreach ($rows as $row) {
                $tmpsdate = $row->fs_split_date;
                $tmpedate = Carbon::parse()->addDays(10)->toDateString();

                $forceAdd = $this->orders->filter(function ($e) use ($tmpsdate, $tmpedate) {
                    return ($e->ts_trade_type == 93) && ($e->ts_trade_date >= $tmpsdate) && ($e->ts_trade_date <= $tmpedate);
                });

                if (!$forceAdd->isEmpty()) {
                    Log::info('skip split due to force add exists', ['split' => $row->toArray()]);
                    continue;
                }

                $e1 = [
                    'it_type' => 18,
                    'it_date' => $row->fs_split_date,
                    'it_time' => '01:00:00',
                    'it_split' => $row->fs_split_proportion,
                    'it_placed_at' => '0000-00-00 00:00:00',
                ];

                array_push($events, $e1);
            }

        }


        //
        // 加载基金净值
        //
        $rows = BaseRaFundNav::where('ra_code', $this->fund->ra_code)
            ->whereBetween('ra_nav_date', [$beginDate, $endDate])
            ->selectRaw('DISTINCT ra_nav_date, ra_nav')
            ->get(['ra_nav_date', 'ra_nav']);
        foreach ($rows as $row) {
            if ($row->ra_nav) {
                $e1 = [
                    'it_type' => 4,
                    'it_date' => $row->ra_nav_date,
                    'it_time' => '15:00:00',
                    'it_nav'  => $row->ra_nav,
                    'it_placed_at' => '0000-00-00 00:00:00',
                ];

                array_push($events, $e1);
            } else {
                Log::warning($this->logtag.'zero ra_nav detected', [
                    'ra_fund_code' => $row->fund->ra_code, 'ra_date' => $row->ra_nav
                ]);
            }
        }

        //
        // 记录持仓事件
        //
        $dates = date_range($beginDate, $endDate);
        foreach ($dates as $day) {
            $e1 = [
                'it_type' => 0,
                'it_date' => $day,
                'it_time' => '00:00:01',
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            $e2 = [
                'it_type' => 19,
                'it_date' => $day,
                'it_time' => '23:59:59',
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            array_push($events, $e1, $e2);
        }

        //
        // 对所有事件进行排序
        //
        usort($events, function ($a, $b) {
            if ($a['it_date'] != $b['it_date']) {
                return strcmp($a['it_date'], $b['it_date']);
            }

            if ($a['it_time'] != $b['it_time']) {
                return strcmp($a['it_time'], $b['it_time']);
            }

            return strcmp($a['it_placed_at'], $b['it_placed_at']);
        });

        foreach ($events as $event) {
            if ($event['it_date'] <= $this->today) {
                $this->process($event);
            }
        }

    }

    protected function process($e)
    {
        //$timing = new Timing(sprintf('%s@%s [%s:%s]', basename_class(__CLASS__), __FUNCTION__, $this->uid, $this->fundId));

        $type = $e['it_type'];

        switch ($type) {
            case 0:
                // $this->yield = 0; // 当日收益清零
                $this->yield_deprecated = 0; // 当日收益
                $this->stat = array_fill_keys(array_keys($this->stat), 0); // 当日流水
                break;

            case 1:
                // if ($this->navDate != '0000-00-00' && ($e['it_nav'] != $this->nav || $e['it_trade_date'] != $this->navDate)) {
                //    dd("order trade_date/nav and context trade_date/nav mismatch", [
                //        $this->nav, $this->navDate, $e
                //    ]);
                // }

                if (array_key_exists($e['it_order_id'], $this->buying)) {
                    // dd("buying order exists", [$this->buying, $e]);
                    Log::error($this->logtag.'SNH: buying order exists', [$this->buying, $e]);
                    $alert = sprintf($this->logtag."基金份额计算检测到重复购买订单:[%s]", $e['it_order_id']);
                    SmsService::smsAlert($alert, 'kun');
                }

                $buying = [
                    'ts_order_id' => $e['it_order_id'],
                    'ts_trade_date' => $e['it_trade_date'],
                    'ts_acked_date' => $e['it_acked_date'],
                    'ts_redeemable_date' => $e['it_acked_date'],
                    'ts_nav' => $e['it_nav'],
                    'ts_share' => $e['it_share'],
                    'ts_amount' => $e['it_amount'],
                ];
                $this->buying[$e['it_order_id']] = $buying;
                #
                # 记录购买对账单: 购买时记账手续费要计入购买金额(it_amount是包含手续费)
                #
                // $this->stat[self::ST_SUB] += $e['it_amount'];
                $this->adjustStat(self::ST_SUB, $e['it_amount'], $e['it_share']);
                // $this->stat[self::ST_SUB_FEE] -= $e['it_fee'];
                $this->adjustStat(self::ST_SUB_FEE, -$e['it_fee'], 0);
                break;

            case 11:
                if (array_key_exists($e['it_order_id'], $this->buying)) {
                    $buying = $this->buying[$e['it_order_id']];

                    if ($this->share['ts_share'] < 0.00001) {
                        //
                        // 如果用户以前清仓后又重新购买，清除份额日期,设置份额净值为交易净值
                        //
                        @list($this->shareNav, $this->shareDate) = [$e['it_nav'], '0000-00-00'];
                        $this->share['ts_trade_date'] = $e['it_trade_date'];
                    }
                    //
                    // 加到share上
                    //
                    if ($this->share['ts_share_id'] == 0) {
                        $this->share['ts_share_id'] = $e['it_order_id'];
                        $this->share['ts_trade_date'] = $e['it_trade_date'];
                    }
                    $this->share['ts_share'] += $e['it_share'];
                    //
                    // 同时增加到detail上
                    //
                    if (!array_key_exists($e['it_trade_date'], $this->details)) {
                        $detail = [
                            'ts_trade_date' => $buying['ts_trade_date'],
                            'ts_acked_date' => $buying['ts_acked_date'],
                            'ts_redeemable_date' => $buying['ts_redeemable_date'],
                            'ts_share' => $buying['ts_share'],
                        ];
                        $this->details[$e['it_trade_date']] = $detail;
                    } else {
                        $this->details[$e['it_trade_date']]['ts_share'] += $buying['ts_share'];
                    }

                    unset($this->buying[$e['it_order_id']]);
                } else {
                    Log::error($this->logtag.'buying share not find but acked', $e);
                }
                break;

            case 2:
                if ($this->navDate != '0000-00-00'
                    && ($e['it_nav'] != $this->nav || $e['it_trade_date'] != $this->navDate)
                    && $e['it_trade_date'] != $this->today) {
                    // dd("order trade_date/nav and context trade_date/nav mismatch", [
                    //    $this->nav, $this->navDate, $e
                    // ]);
                }

                if ($this->share['ts_share'] - $e['it_share'] < -0.00001) {
                    // dd('SNH: insuffient share for redeem', $this->share, $e);
                    Log::error($this->logtag.'SNH: insuffient share for redeem', [$this->share, $e, $this->orders->toArray(), $this->dividends->toArray()]);
                    $alert = sprintf($this->logtag."SNH:赎回份额不足:[%s, %s]", $e['it_order_id'], $this->fund->ra_code);
                    SmsService::smsAlert($alert, 'kun');
                }

                //
                // 从share上扣减并生成赎回记录
                //
                $this->share['ts_share'] -= $e['it_share'];

                $this->redeeming[$e['it_order_id']] = [
                    'ts_order_id' => $e['it_order_id'],
                    'ts_share_id' => $this->share['ts_share_id'],
                    'ts_share' => $e['it_share'],
                    'ts_trade_date' => $e['it_trade_date'],
                    'ts_trade_nav' => $e['it_nav'],
                    'ts_acked_date' => $e['it_acked_date'],
                    'ts_latest_nav' => $this->nav,
                ];
                #
                # 记录赎回对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                #
                $amount = $e['it_amount'];
                if ($e['it_amount'] < 0.00001 && $e['it_share'] > 0.00001) {
                    $amount = round($e['it_share'] * $this->nav, 2);
                }
                // $this->stat[self::ST_REDEEM] -= $e['it_amount'];
                $this->adjustStat(self::ST_REDEEM, -$amount, -$e['it_share']);
                // $this->stat[self::ST_REDEEM_FEE] -= $e['it_fee'];
                if (abs($e['it_fee']) > 0.00001) {
                    $this->adjustStat(self::ST_REDEEM_FEE, -$e['it_fee'], 0);
                }

                //
                // 从detail上扣减
                //
                $left = $e['it_share'];
                while ($left > 0.00001 && !empty($this->details)) {
                    $detail = reset($this->details);
                    if ($detail['ts_share'] - $left > 0.00001) {
                        $key = key($this->details);
                        $this->details[$key]['ts_share'] -= $left;
                        $left = 0;
                    } else {
                        $left -= $detail['ts_share'];
                        array_shift($this->details);
                    }
                }
                if ($left > 0.00001) {
                    // dd('SNH: share & details mismatched', [$this->share, $this->details, $e, $left]);
                    Log::error($this->logtag.'SNH: share & details mismatched', [$this->share, $this->details, $e, $left]);
                }

                break;

            case 12:
                if (isset($this->redeeming[$e['it_order_id']])) {
                    unset($this->redeeming[$e['it_order_id']]);
                }
                break;

            case 4:
                //
                // 净值更新
                //
                $lastNav = $this->nav;
                @list($this->nav, $this->navDate, $this->yield) = [$e['it_nav'], $e['it_date'], 0];
                //
                // 如果是除息日，需要对净值进行调整，否则会大比例亏损
                //
                // dump('04', $this->navDate,$this->yield, "xx");
                $nav = $this->nav;
                if (!empty($this->bonusing) && $this->bonusing['ts_dividend_date'] == $e['it_date']) {
                    $nav += $this->bonusing['ts_bonus_ratio'];
                }
                //
                // 购买中，尚未确认的是有收益的，因为当天购买是在净值更新之后
                // 处理的，所以这里直接将已经在购买中的记录计算收益即可。
                //
                // 赎回中，尚未确认的是没有收益的，因为当天赎回也是在净值更新
                // 之后处理的，所以这种情况下下，赎回当天是有收益的。
                //
                $totalShare = \array_sum(array_column($this->buying, 'ts_share'));
                $totalShare += $this->share['ts_share'];
                //
                // [XXX] 日收益的两种算法理论上是一样：
                //  1. 份额 * (今日净值 - 昨日净值)
                //  2. 今日市值 - 昨日市值
                //
                // 但当中间存在四舍五入的时，第一种算法会有累计误差，特别是计
                // 算累计收益的时候。这时，只能采用第二种算法，而且要四舍五入
                // 之后计算，才能维持资产和日收益、累计收益的一致性
                //
                // 后来发现，上面两种算法在一天有多笔购买是，还是会引入累计误
                // 差。所以采用第三种算法来实际记录日收益，这两种算法的结果仅
                // 用于校验。
                //
                // 第三种算法如下：
                //
                //    日收益 = 日末持仓 + 当日资金流出 - 当日资金流入 - 昨日持仓
                //
                $this->yield_deprecated = round($totalShare * $nav, 2) - round($totalShare * $lastNav, 2);
                // if ($e['it_date'] == '2017-02-14') {
                //     dd($yield, round($totalShare * $this->nav, 2),  round($totalShare * $lastNav, 2), $totalShare * $this->nav,$totalShare * $lastNav);
                // }
                //
                // 如果有持仓，则调整响应的份额净值和份额日期
                //
                if ($totalShare > 0.00001) {
                    @list($this->shareNav, $this->shareDate) = [$this->nav, $this->navDate];
                }
                //
                // 这里不再记录日收益对账单，而是要到记录日末持仓时再记录对账单
                //
                // $this->stat[self::ST_YIELD] = $yield;
                // $this->adjustStat(self::ST_YIELD, $yield, 0);
                break;

            case 5:
                //
                // 强制赎回
                //
                if ($this->navDate != '0000-00-00' && ($e['it_nav'] != $this->nav || $e['it_trade_date'] != $this->navDate)) {
                    // dd("order trade_date/nav and context trade_date/nav mismatch", [
                    //     $this->nav, $this->navDate, $e
                    // ]);
                }

                if ($this->share['ts_share'] - $e['it_share'] < -0.00001) {
                    // dd('SNH: insuffient share for redeem', $this->share, $e);
                    Log::error($this->logtag.'SNH: insuffient share for redeem', [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:强制赎回份额不足:[%s, %s]", $e['it_order_id'], $this->fund->ra_code);
                    SmsService::smsAlert($alert, 'kun');
                }

                //
                // 从share上扣减,但无需生成赎回记录
                //
                $this->share['ts_share'] -= $e['it_share'];

                #
                # 记录赎回对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                #
                // $this->stat[self::ST_REDEEM] -= $e['it_amount'];
                $this->adjustStat(self::ST_REDEEM, -$e['it_amount'], -$e['it_share']);
                // $this->stat[self::ST_REDEEM_FEE] -= $e['it_fee'];
                if (abs($e['it_fee']) > 0.00001) {
                    $this->adjustStat(self::ST_REDEEM_FEE, -$e['it_fee'], 0);
                }

                //
                // 从detail上扣减
                //
                $left = $e['it_share'];
                while ($left > 0.00001 && !empty($this->details)) {
                    $detail = reset($this->details);
                    if ($detail['ts_share'] - $left > 0.00001) {
                        $key = key($this->details);
                        $this->details[$key]['ts_share'] -= $left;
                        $left = 0;
                    } else {
                        $left -= $detail['ts_share'];
                        array_shift($this->details);
                    }
                }
                if ($left > 0.00001) {
                    // dd('SNH: share & details mismatched', [$this->share, $this->details, $e, $left]);
                    Log::error($this->logtag.'SNH: share & details mismatched', [$this->share, $this->details, $e, $left]);
                }

                break;

            case 8:
                //
                // 强增
                //
                // [XXX] 如果是单存的强增，也可能出现没有这是份额首次建立的情况
                //
                if ($this->share['ts_share'] < 0.00001) {
                    //
                    // 如果用户以前清仓后又重新购买，清除份额日期,设置份额净值为交易净值
                    //
                    @list($this->shareNav, $this->shareDate) = [$e['it_nav'], '0000-00-00'];
                    $this->share['ts_trade_date'] = $e['it_trade_date'];
                }
                //
                // 加到share上
                //
                if ($this->share['ts_share_id'] == 0) {
                    $this->share['ts_share_id'] = $e['it_order_id'];
                    $this->share['ts_trade_date'] = $e['it_trade_date'];
                }
                $this->share['ts_share'] += $e['it_share'];
                #
                # 强增操作，无需记录对账单；因为强增本质上是拆分操作.
                #
                // $this->stat[self::ST_SUB] += $e['it_share'] * $e['it_nav'];
                // $this->stat[self::ST_SUB_FEE] -= $e['it_fee'];
                $this->adjustStat(self::ST_ADD_FORCE, 0, $e['it_share']);

                if (!array_key_exists($e['it_date'], $this->details)) {
                    $detail = [
                        'ts_trade_date' => $e['it_trade_date'],
                        'ts_acked_date' => $e['it_acked_date'],
                        'ts_redeemable_date' => $e['it_acked_date'],
                        'ts_share' => $e['it_share'],
                    ];
                    $this->details[$e['it_date']] = $detail;
                } else {
                    $this->details[$e['it_date']]['ts_share'] += $e['it_share'];
                }

                //
                // 强增操作因为本质是拆分，所以也要进行净值折算，否则会出错。
                //
                if (isset($e['it_split']) && $e['it_split'] != 0) {
                    $this->nav /= $e['it_split'];
                } else {
                    $this->nav = $e['it_nav'];
                }
                // dd($this->share, $e);

                break;

            case 9:
                // dd("fore reduce occured, not implement!", $e);
                Log::error($this->logtag."fore reduce occured, not implement!", $e);
                $alert = sprintf($this->logtag."SNH:发现强减订单,计算逻辑未实现:[%s, %s]", $e['it_order_id'], $this->fund->ra_code);
                SmsService::smsAlert($alert, 'kun');

                break;

            case 13:
                $this->divMode = 1;

                break;

            case 15:
                //
                // 分红权益登记
                //
                $totalShare = \array_sum(array_column($this->buying, 'ts_share'));
                $totalShare += $this->share['ts_share'];
                $this->bonusing = [
                    'ts_share_id' => $this->share['ts_share_id'],
                    'ts_share' => $totalShare,
                    'ts_bonus_ratio' => $e['it_bonus'],
                    'ts_bonus_amount' => 0,
                    'ts_bonus_share' => 0,
                    'ts_record_date' => $e['it_record_date'],
                    'ts_dividend_date' => $e['it_dividend_date'],
                    'ts_payment_date' => $e['it_payment_date'],
                ];
                break;

            case 16:
                //
                // 分红除息
                //
                if (empty($this->bonusing)) {
                    Log::error($this->logtag."bonusing: dividend but no bonus context", $e);
                } else {
                    //
                    //  如果设置 it_dividend_amount, 说明碰到了特殊的现金分红的case
                    //
                    if (isset($e['it_dividend_amount']) && $e['it_dividend_amount'] > 0.00001) {
                        $bonus_status = 1;
                        $this->bonusing['ts_bonus_amount'] = $e['it_dividend_amount'];
                    } else {
                        $bonus_status = isset($e['it_dividend_share']) ? 1 : 0;
                        $tmpAmount = $this->bonusing['ts_share'] * $e['it_bonus'];
                        if (in_array($this->fund->ra_calc_round, [11, 12, 13])) {
                            $this->bonusing['ts_bonus_amount'] = floorp($tmpAmount, 2);
                        } else {
                            $this->bonusing['ts_bonus_amount'] = round($tmpAmount, 2);
                        }
                    }
                    //
                    // 记录分红支出对账单
                    //
                    if ($this->bonusing['ts_bonus_amount'] > 0.00001) {
                        // $this->stat[self::ST_BONUS_OUT] = -$this->bonusing['ts_bonus_amount'];
                        $this->adjustStat(self::ST_BONUS_OUT, -$this->bonusing['ts_bonus_amount'], 0, $bonus_status);
                    }

                    #
                    # 如果设置 it_dividend_amount, 说明碰到了特殊的现金分红的case，直接可以处理了。
                    #
                    if (isset($e['it_dividend_amount']) && $e['it_dividend_amount'] > 0.00001) {
                        $this->bonusing = [];
                    }
                }
                break;

            case 17:
                //
                // 分红派息
                //
                if (empty($this->bonusing)) {
                    Log::error($this->logtag."bonusing: dividend resub but no bonus context", $e);
                } else {
                    $bonus_status = 0;
                    $tmp = $this->bonusing['ts_bonus_amount'] / $e['it_bonus_nav'];
                    if (in_array($this->fund->ra_calc_round, [1, 11, 21, 31])) {
                        $this->bonusing['ts_bonus_share'] = floorp($tmp, 2);
                    } else {
                        $this->bonusing['ts_bonus_share'] = round($tmp, 2);
                    }
                    if (isset($e['it_dividend_share'])) {
                        if ($this->bonusing['ts_bonus_share'] != $e['it_dividend_share']) {
                            Log::warning($this->logtag.'calc bonus share and yingmi mismatch', [
                                'bonusing' => $this->bonusing, 'event' => $e
                            ]);
                        }
                        $bonus_status = 1;
                        $this->bonusing['ts_bonus_share'] = $e['it_dividend_share'];
                    }
                    if ($this->bonusing['ts_bonus_share'] > 0.00001) {
                        #
                        # 记录分红支出对账单
                        #
                        // $this->stat[self::ST_BONUS_IN] = [
                        //     $this->bonusing['ts_bonus_amount'],
                        //     $this->bonusing['ts_bonus_share']
                        // ];
                        $this->adjustStat(
                            self::ST_BONUS_IN,
                            $this->bonusing['ts_bonus_amount'],
                            $this->bonusing['ts_bonus_share'],
                            $bonus_status);
                        #
                        # 将红利再投份额加入份额信息上
                        #
                        $this->share['ts_share'] += $this->bonusing['ts_bonus_share'];
                        //
                        // 将红利再投份额加入details上
                        //
                        $dividentDate = $this->bonusing['ts_dividend_date'];
                        if (!array_key_exists($dividentDate, $this->details)) {
                            $detail = [
                                'ts_trade_date' => $dividentDate,
                                'ts_acked_date' => $this->bonusing['ts_payment_date'],
                                'ts_redeemable_date' => $this->bonusing['ts_payment_date'],
                                'ts_share' => $this->bonusing['ts_bonus_share'],
                            ];
                            $this->details[$dividentDate] = $detail;
                        } else {
                            $this->details[$dividentDate]['ts_share'] += $this->bonusing['ts_bonus_share'];
                        }
                    }
                    #
                    # 清除分红上下文
                    #
                    $this->bonusing = [];
                }
                break;

            case 18:
                //
                // 处理基金分拆
                //
                if ($e['it_split'] != 0) {
                    $oldshare = $this->share['ts_share'];
                    //
                    // 处理分拆并折算净值
                    //
                    $this->share['ts_share'] = round($this->share['ts_share'] * $e['it_split'], 2);
                    $this->nav /= $e['it_split'];
                    // 计算分拆得到的份额
                    $splitshare = $this->share['ts_share'] - $oldshare;
                    //
                    // 记录流水
                    //
                    $this->adjustStat(self::ST_SPLIT, 0, $splitshare);
                    //
                    // 添加detail
                    //
                    if (!array_key_exists($e['it_date'], $this->details)) {
                        $detail = [
                            'ts_trade_date' => $e['it_date'],
                            'ts_acked_date' => $e['it_date'],
                            'ts_redeemable_date' => $e['it_date'],
                            'ts_share' => $splitshare,
                        ];
                        $this->details[$e['it_date']] = $detail;
                    } else {
                        $this->details[$e['it_date']]['ts_share'] += $splitshare;
                    }
                }
                break;

            case 19:
                //
                // 记录当日持仓
                //
                $totalShare = \array_sum(array_column($this->buying, 'ts_share'));
                $totalShare += $this->share['ts_share'];
                $totalAmount = round($totalShare * $this->nav, 2);

                $redeemingShare = \array_sum(array_column($this->redeeming, 'ts_share'));
                $redeemingAmount = round($redeemingShare * $this->nav, 2);

                if ($totalShare > 0.00001 || $redeemingShare > 0.00001) {
                    $this->ts_holding_fund[] = [
                        'ts_date' => $e['it_date'],
                        'ts_share' => $totalShare,
                        'ts_amount' => $totalAmount,
                        'ts_share_redeeming' => $redeemingShare,
                        'ts_amount_redeeming' => $redeemingAmount,
                    ];
                }
                // if ($e['it_date'] == '2017-03-13') {
                //     dd($this->shares,  "bb", $e, '2017-03-13');
                // }

                //
                // 记录当日对账
                //
                $balance = 0; // 当日资金进出结余
                foreach ($this->stat as $k => $v) {
                    if (!$v) {
                        continue;
                    }

                    list($amount, $share, $status) = $v;

                    $this->ts_statment[] = [
                        'ts_date' => $e['it_date'],
                        'ts_stat_type' => $k,
                        'ts_stat_status' => $status,
                        'ts_stat_amount' => $amount,
                        'ts_stat_share' => $share,
                        'ts_stat_uncarried' => 0,
                    ];

                    $balance += $amount;
                }

                //
                // 计算日收益：
                //
                //    日末持仓 + (资金流出 - 资金流入) - 昨日日末持仓
                //
                // 也即：
                //
                //    日末持仓 - 资金进出结余 - 昨日日末持仓
                //
                $yield = $totalAmount - $balance - $this->lastHoldAmount;
                if (abs($yield - $this->yield_deprecated) >= 0.0150) {
                    // dd("daily yield mismatch, check algo!", [
                    //     'it_date' => $e['it_date'],
                    //     'yield1' => $yield,
                    //     'yield2' => $this->yield_deprecated,
                    //     'diff' => $yield - $this->yield_deprecated,
                    //     'stat' => $this->stat,
                    // ]);
                }
                //
                // 记录日收益对账单，而是要到记录日末持仓时再记录对账单
                //
                // [XXX] 从产品的角度，只要当日有持仓，且基金有净值，就应该有
                // 日收益，哪怕是为0也要记录
                //
                $this->adjustStat(self::ST_YIELD, $yield, 0);
                if (($totalShare > 0.00001 || abs($yield) > 0.00001) && $e['it_date'] == $this->navDate) {
                    $this->yield = $yield;
                    $this->ts_statment[] = [
                        'ts_date' => $e['it_date'],
                        'ts_stat_type' => self::ST_YIELD,
                        'ts_stat_status' => 1,
                        'ts_stat_amount' => $yield,
                        'ts_stat_share' => 0,
                        'ts_stat_uncarried' => 0,
                    ];
                }
                // if ($e['it_date'] == '2017-08-17') {
                //     dd($this->ts_statment, $this->stat, $yield, $totalAmount, $balance, $this->lastHoldAmount);
                // }

                //
                // 更新昨日持仓
                //
                $this->lastHoldAmount = $totalAmount;
                break;

            default:
                Log::error($this->logtag.'unknown event type', $e);
                // dd('unknown event type', $e);
                $alert = sprintf($this->logtag."SNH:检测到未知类型事件:[%s]", $e['it_type']);
                SmsService::smsAlert($alert, 'kun');
        }
    }

    protected function adjustStat($type, $amount, $share, $status = 1)
    {
        if ($this->stat[$type]) {
            $this->stat[$type][0] += $amount;
            $this->stat[$type][1] += $share;
            if ($this->stat[$type][2] != 0) {
                $this->stat[$type][2] = $status;
            }
        } else {
            $this->stat[$type] = [$amount, $share, $status];
        }
    }

}
