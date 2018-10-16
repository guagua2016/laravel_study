<?php

namespace App\Libraries;

use App\Jobs\Job;
use Illuminate\Contracts\Bus\SelfHandling;

use Carbon\Carbon;

use Log;

use App\RaFund;
use App\RaFundBonus;
use App\RaFundNav;
use App\FundSplit;

use App\Libraries\Timing;
use App\Libraries\TdateLookupTable;

use function App\Libraries\basename_class;
use function App\Libraries\date_range;
use function App\Libraries\floorp_format;

class MonetaryShareCalculator
{
    protected $logtag = '';
    protected $fund = null;
    protected $orders = [];
    protected $dividends = [];
    protected $finfo = null;

    //
    // 以下是计算结果
    //
    public $ts_holding_fund = [];  // 当日持仓
    public $ts_statment = [];      // 对账单


    //
    // 以下是计算上下文
    //
    // 货币基金不同之处在于，由于没有赎回购买费率的影响，所以不需
    // 要先进先出， 只需要维护一个统一的份额即可。
    //
    // 但购买有是否可以赎回为问题，所以需要通过一个数组单独记录
    //
    //
    protected $yield = 0;                      // 当日收益
    public    $returnDaily = 0;                // 当前万份收益
    public    $returnDailyDate = '0000-00-00'; // 净值日期
    public    $shareDate = '0000-00-00';       // 份额日期(持仓是等于净值日期，清仓时等于最后一次清仓日期)
    protected $share = null;                   // 当前份额
    protected $details = null;                 // 当前份额的明细
    protected $buying = null;                  // 购买待确认份额
    protected $redeeming = null;               // 当前赎回中的份额
    protected $bonusing = null;                // 当前分红上下文


    //
    // 流水类型(1:申购(+);2:赎回(-);3:申购费(-);4:赎回费(-)5:日收益(+);6:未结转收益(+);7:分红支出(-);8:分红收入(+);9:收益结转(+);10:强制结转(+);11:强增(+);12:强减(-)
    //
    // [XXX] 这里需要解释一下强制结转，强制结转是我们为了对账的统一性而规定的
    // 流水类型，并非来自基金公司，而是来自我们记账系统。主要用户处理清仓时，
    // 收支不平衡的问题, 也即：
    //
    //    确认购买金额 + 确认分红份额 - 确认赎回金额 != 0
    //
    // 问题是这样的，我们查询了广发基金的官网的累积收益和分红记录，在货币基金
    // 购买到清仓的过程中，所有分红的份额之和不等于累计收益。两者之差正好是最
    // 后一天日收益。 咨询了广发基金的客服，告知因为轻仓时，最后一天的收益是不
    // 做红利再投处理的，而是随着赎回直接打回了用户的银行卡。而货币基金的分红
    // 记录只记录红利再投的分红(因为货币基金只有红利再投一种分红方式)。问题就
    // 这样产生了， 而且根据我们的经验，这个可能不是个例，很可能是基金会计里面
    // 通用的法则。
    //
    // 为了解决这个问题，我们设计了一个比较trivial的解决方案，即假定：
    //
    //    如果对于赎回订单的确认金额 != 确认份额时，其差值就是赎回日随同赎回一
    //    起结转的分红收益(现金红利),并记为 “强制结转”收益, 以入账(+)计。
    //
    //
    // [XXX] A类货币和B类货币有时候会发生转换，在交易记录里面以强增和强减来记
    // 录。为了流水上的一致性，这里增加了强增和强减两种流水类型。因为是货币基
    // 金，金额和份额应该是相等的。强增的以收入计，强减以支出计。
    //
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
        $shareRedeeming = \array_sum(array_column($this->redeeming, 'ts_share'));

        $profitAcc = 0;
        foreach ($this->ts_statment as $st) {
            if (in_array($st['ts_stat_type'], [3, 4, 5, 6, 9, 10])) {
                $profitAcc += ($st['ts_stat_amount'] + $st['ts_stat_uncarried']);
            }
        }

        $shares = [];
        $shares[] = array_merge($this->share, [
            'ts_nav' => 1,
            'ts_date' => $this->shareDate,
            'ts_amount' => $this->share['ts_share'] * 1,
            'ts_share_buying' => $shareBuying,
            'ts_amount_buying' => $shareBuying * 1,
            'ts_share_redeeming' => $shareRedeeming,
            'ts_amount_redeeming' => $shareRedeeming * 1,
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
            $detail['ts_nav'] = 1;
            $detail['ts_nav_date'] = $this->returnDailyDate;
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
        return $this->bonusing;
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
        $this->dividends  = $dividends;

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

        $this->finfo = [
            'ra_fund_id' => $fund->globalid,
            'ra_fund_cod' => $fund->ra_code,
        ];

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

        // 事件类型:0:日内第一个事件;1:申购;2:赎回;3:分红;4:净值更新;5:强制赎回;8:强增; 9强减;11:申购确认;12:赎回到账;13:修改分红方式;14:收益结转;15:分红登记;16:分红除息;17:分红派息;18:基金分拆;19:记录当日持仓;24:强制收益结转;
        //
        // 事件队列，元组来实现
        //
        //     (dt, op, order_id, fund_id, argv)
        //
        // 其中，
        //
        //     dt： 日内事件处理时间，主要用于事件在队列中的排序，不一
        //          定等同于下单时间。比如：3:00pm前下的购买订单的处理
        //          时间可能是16:00，因为15:00净值才出来，而购买赎回都
        //          需要使用当天的净值。
        //
        //          各个事件的处理时间如下：
        //
        //          00:00:01 生成持仓相关的当日例行事件（更新净值，分拆，分红，记录当日持仓）
        //          01:00:00 分拆
        //          01:30:00 强增
        //          02:00:00 购买确认
        //          03:00:00 赎回确认
        //          05:00:00 强减
        //          15:00:00 更新净值/万份收益
        //          15:30:00 强制收益结转
        //          16:00:00 分红权益登记
        //          16:30:00 分红除息
        //          16:40:00 分红红利再投
        //          16:45:00 分红派息
        //          17:00:00 调仓处理
        //          18:00:00 购买
        //          18:30:00 赎回
        //          19:00:00 收益结转
        //          23:59:59 记录当日持仓
        //
        //          时间的处理顺序之所以这样排序，原因如下：
        //
        //          购买的确认和赎回确认也需要在更新净值在前进行，因为T
        //          日确认的购买份额在T日是可以赎回的，所以应该在开盘之
        //          前完成结算。T日确认的赎回份额，在T日不再计算收益，
        //          所以需要在在更新净值之前完成
        //
        //          盈米关于收益结转的处理是T日对T-1日的之前的未付收益进行结转,所
        //          以结转实际上是不含T日的未付收益的，但我们在处理中，为了记
        //          账，将结转日改为前一个净值日期，所以需要在前一个净值日最后
        //          进行。
        //
        //          申购，赎回依赖当日净值，所以需要在净值更新之
        //          后进行。具体的，申购和赎回的是按T日的净值计算金额的。
        //
        //          强制收益结转理论网上赎回当日的未结转收益，所以需要在更新净
        //          值之后处理。强制收益结转需啊哟在赎回之前完成，因为货币基金
        //          的赎回可能会赎回强制结转的收益。
        //
        //          收益结转在基金公司的业务层面是不包含当日的未结转收益的。
        //
        //          强增需要在赎回确认之前，理论上这样可以满足强增之后份额可以
        //          立即赎回。但实际的交易记录目前未观察到该类case。
        //
        //          强减需要在购买确认之后，这样刚刚确认的份额可以进行强减，这
        //          个是可以确定的，因为我们已经在交易记录上看到了此类的case。


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
                30 => [1, '18:00:00'],        // 30:银行卡购买;
                31 => [1, '18:00:00'],        // 31:钱包购买;
                40 => [2, '18:30:00'],        // 40:赎回银行卡;
                41 => [2, '18:30:00'],        // 41:赎回到钱包;
                43 => [5, '18:30:00'],        // 43:强制赎回;
                50 => [1, '18:00:00'],        // 50:现金定投;
                51 => [1, '18:00:00'],        // 51:钱包定投;
                61 => [1, '18:00:00'],        // 61:调仓购买(老)；
                62 => [2, '18:30:00'],        // 62:调仓赎回(老);
                63 => [1, '18:00:00'],        // 63:调仓购买；
                64 => [2, '18:30:00'],        // 64:调仓赎回;
                70 => [3, '16:00:00'],        // 70:分红到银行卡;
                71 => [3, '16:00:00'],        // 71:分红到钱包;
                72 => [14, '16:00:00'],       // 72:修改分红方式;
                                              // 91:转出;
                                              // 92:转入;
                93 => [8, '01:30:00'],        // 93:强增;
                94 => [9, '05:00:00'] ,      // 94:强减;
                                // 95:非交易过户转出;非交易过户转入)
            ];

            if (array_key_exists($order->ts_trade_type, $xtab)) {
                @list($type, $time) = $xtab[$order->ts_trade_type];
            } else {
                // dd("unknown trade type", $order);
                Log::error($this->logtag."unknown trade type", [$order]);
                $alert = sprintf($this->logtag."SNH:检测到未知交易类型:[%s, %d]", $order->ts_txn_id, $order->ts_trade_type);
                SmsService::smsAlert($alert, 'kun');
                continue;
            }

            //
            // 生成订单事件
            //
            if (in_array($order->ts_trade_status, [0, 1])) {
                // 如果是未确认的赎回订单，需要使用下单份额
                $it_share = $order->ts_placed_share;
                $it_amount = $order->ts_placed_share;
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
                'it_placed_at' => sprintf("%s %s", $order->ts_placed_date, $order->ts_placed_time),
            ];

            $events[] = $e1;

            //
            // 如果是购买和赎回，需要生产确认订单
            //
            if (in_array($type, [1, 2]) && in_array($order->ts_trade_status, [5, 6])) {
                if ($type == 1) {
                    list($type2, $time) = [11, '00:02:00'];
                } else {
                    list($type2, $time) = [12, '00:03:00'];
                }

                $e2 = array_replace($e1, [
                    'it_type' => $type2,
                    'it_date' => $order->ts_acked_date,
                    'it_time' => $time,
                ]);

                $events[] = $e2;
            }

            //
            // 如果是赎回，且确认金额 != 确认份额，则需要生成强制结转订单.
            //
            if (in_array($type, [2, 5]) && $order->ts_acked_amount != $order->ts_acked_share) {
                $e3 = array_replace($e1, [
                    'it_type' => 24,
                    'it_date' => $order->ts_trade_date,
                    'it_time' => '15:30:00',
                ]);

                $events[] = $e3;
            }
        }

        // $beginDate = $this->orders->min('ts_placed_date');
        $endDate = Carbon::today()->toDateString();

        //
        // 加载基金净值
        //
        $rows = RaFundNav::where('ra_code', $this->fund->ra_code)
            ->whereBetween('ra_nav_date', [$beginDate, $endDate])
            ->where('ra_mask', 0)
            ->selectRaw('DISTINCT ra_nav_date, ra_return_daily')
            ->get(['ra_nav_date', 'ra_return_daily']);
        foreach ($rows as $row) {
            if ($row->ra_return_daily) {
                $e1 = [
                    'it_type' => 4,
                    'it_date' => $row->ra_nav_date,
                    'it_time' => '15:00:00',
                    'it_return_daily'  => $row->ra_return_daily,
                    'it_placed_at' => '0000-00-00 00:00:00',
                ];

                // if (isset($this->bonus[$row->ra_nav_date])) {
                //     $e1['it_return'] = $this->bonus[$row->ra_nav_date];
                // }

                array_push($events, $e1);
            } else {
                Log::warning($this->logtag.'zero ra_nav detected', [
                    'ra_fund_id' => $this->fundId, 'ra_date' => $row->ra_nav
                ]);
            }
        }

        //
        // 加载收益结转信息
        //
        // 盈米的收益结转（货币分红） 实际上是红利发放日前一个净值日的未结转收
        // 益进行结转，所以我们需要计算前一个净值日。
        //
        $xtab = TdateLookupTable::build($rows->lists('ra_nav_date'));
        foreach ($this->dividends as $div) {
            $carryDate = $xtab->prevTo($div->ts_dividend_date);
            if (!$carryDate) {
                Log::error('carry date not found, use ts_dividend_date instead', ['ts_dividend_fund' => $div]);
                $carryDate = $div->ts_dividend_date;
            }
            $e1 = [
                'it_type' => 14, // 收益结转
                'it_date' => $carryDate,
                'it_time' => '19:00:00',
                'it_amount'  => $div->ts_dividend_share, // 货币基金的结转在分红表里是以share记录的
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            array_push($events, $e1);
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
                //
                // 重置日内信息
                //
                // $this->yield = 0; // 当日收益
                $this->stat = array_fill_keys(array_keys($this->stat), 0); // 当日流水
                break;

            case 1:
                if ($this->returnDailyDate != '0000-00-00' && $e['it_trade_date'] != $this->returnDailyDate) {
                    // dd("order trade_date/nav and context trade_date/nav mismatch", [
                    //    $this->returnDaily, $this->returnDailyDate, $e
                    // ]);
                }

                if (array_key_exists($e['it_order_id'], $this->buying)) {
                    // dd("buying order exists", [$this->buying, $e]);
                    Log::error($this->logtag."buying order exists", [$this->buying, $e]);
                    $alert = sprintf($this->logtag."SNH:检测到重复购买订单:[%s]", $e['it_order_id']);
                    SmsService::smsAlert($alert, 'kun');
                }

                $buying = [
                    'ts_order_id' => $e['it_order_id'],
                    'ts_trade_date' => $e['it_trade_date'],
                    'ts_acked_date' => $e['it_acked_date'],
                    'ts_redeemable_date' => $e['it_acked_date'],
                    'ts_nav' => 1,
                    'ts_share' => $e['it_share'],
                ];
                $this->buying[$e['it_order_id']] = $buying;
                #
                # 记录购买对账单: 购买时记账手续费要计入购买金额(it_amount是包含手续费)
                #
                // $this->stat[self::ST_SUB] += $e['it_amount'];
                $this->adjustStat(self::ST_SUB, $e['it_amount'], $e['it_share'], 0);
                // $this->stat[self::ST_SUB_FEE] -= $e['it_fee']; // 货币基金目前没有手续费
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
                if ($this->returnDailyDate != '0000-00-00'
                    && $e['it_trade_date'] != $this->returnDailyDate
                    && $e['it_trade_date'] != $this->today) {
                    // dd("order trade_date/nav and context trade_date/nav mismatch", [
                    //    $this->returnDaily, $this->returnDailyDate, $e
                    // ]);
                }
                $redeemings = [];

                // 因为我们使用了强制结转逻辑， 赎回的时候，我们就得使用
                // it_amount，而不是it_share, 否则，强制结转的那部分收益就可能
                // 不被赎回。
                if ($this->share['ts_share'] - $e['it_amount'] < -0.00001) {
                    // dd('SNH: insuffient share for redeem', $this->share, $e);
                    Log::error($this->logtag.'SNH: insuffient share for redeem', [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:货币赎回份额不足:[%s]", $e['it_order_id']);
                    SmsService::smsAlert($alert, 'kun');
                }

                $this->share['ts_share'] -= $e['it_amount'];

                $this->redeeming[$e['it_order_id']] = [
                    'ts_order_id' => $e['it_order_id'],
                    'ts_share_id' => $this->share['ts_share_id'],
                    'ts_share' => $e['it_amount'],
                    'ts_trade_date' => $e['it_trade_date'],
                    'ts_trade_nav' => $e['it_nav'],
                    'ts_acked_date' => $e['it_acked_date'],
                    'ts_latest_nav' => 1,
                ];

                // if ($e['it_date'] == '2017-08-04') {
                //     dd($this->share, $e, $this->redeeming);
                // }

                #
                # 记录赎回对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                #
                // $this->stat[self::ST_REDEEM] -= $e['it_amount'];
                $this->adjustStat(self::ST_REDEEM, -$e['it_amount'], -$e['it_amount'], 0);
                // $this->stat[self::ST_REDEEM_FEE] -= $e['it_fee'];

                //
                // 从detail上扣减
                //
                $left = $e['it_amount'];
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
                @list($this->returnDaily, $this->returnDailyDate, $this->yield) = [$e['it_return_daily'], $e['it_date'], 0];
                //
                // 因为货币基金的日收益通过万份收益预估是算不准的，我们的处理方式是：
                //
                // 1. 如果有基金公司返回的明确的未结转收益数据，则直接使用该数据。
                // 2. 否则，通过万份收益去预估，但预估会有一分钱的差异
                //
                $yield = 0;
                if (isset($e['it_return'])) {
                    $yield = $e['it_return'];
                } else {
                    //
                    // [XXX] 通过万份收益对日收益进行预估，按照下面公式：
                    //
                    //     floorp(持有份额 * 万份收益)
                    //
                    // 这里采用去尾法，因为大部分货币基金都是用的去尾法。
                    //
                    $tmp = $this->share['ts_share'] * $e['it_return_daily'] / 10000;
                    if (abs($tmp) < 0.00001) {
                        $yield = 0;
                    } else {
                        $yield = floorp($tmp, 2);
                    }
                }

                //
                // [XXX] 在产品使用中发现一个问题，理论上用户只要有持仓，当日
                // 有万份收益，就应该给用户展示收益，哪怕收益为0。
                //
                if ($this->share['ts_share'] > 0.00001 || abs($yield) > 0.00001) {
                    // 调增未结转收益或份额
                    $this->share['ts_yield_uncarried'] += $yield;
                    # 记录日收益对账单
                    // $this->stat[self::ST_UNCARRY] = [0, $yield];
                    $this->adjustStat(self::ST_UNCARRY, 0, 0, $yield);
                    $this->yield = $yield;
                }

                //
                // 如果是持仓，更新份额日期
                //
                if ($this->share['ts_share'] > 0.00001) {
                    $this->shareDate = $this->returnDailyDate;
                }

                break;

            case 5:
                if ($this->returnDailyDate != '0000-00-00' && $e['it_trade_date'] != $this->returnDailyDate) {
                    // dd("order trade_date/nav and context trade_date/nav mismatch", [
                    //    $this->nav, $this->returnDailyDate, $e
                    // ]);
                }

                // 因为我们使用了强制结转逻辑， 赎回的时候，我们就得使用
                // it_amount，而不是it_share, 否则，强制结转的那部分收益就可能
                // 不被赎回。
                if ($this->share['ts_share'] - $e['it_amount'] < -0.00001) {
                    // dd('SNH: insuffient share for redeem', $this->share, $e);
                    Log::error($this->logtag.'SNH: insuffient share for redeem', [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:货币强制赎回份额不足:[%s]", $e['it_order_id']);
                    SmsService::smsAlert($alert, 'kun');
                }

                $this->share['ts_share'] -= $e['it_amount'];
                //
                // 与普通赎回不同，强制赎回不需要保存赎回上下文
                //
                //
                // 记录赎回对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                //
                // $this->stat[self::ST_REDEEM] -= $e['it_amount'];
                $this->adjustStat(self::ST_REDEEM, -$e['it_amount'], -$e['it_amount'], 0);
                // $this->stat[self::ST_REDEEM_FEE] -= $e['it_fee'];

                //
                // 从detail上扣减
                //
                $left = $e['it_amount'];
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

            case 14:
                // 收益结转
                //
                // 因为货币基金的日收益通过万份收益预估是算不准的，收益结转时，我们的处理方式是：
                //
                // 1. 如果有基金公司返回的明确的结转数据，则直接使用该数据。
                // 2. 否则，直接将我们估算的未结转收益结转
                if ($e['it_amount']) {
                    $carried = $e['it_amount'];
                } else {
                    $carried = $this->share['ts_yield_uncarried'];
                }
                // 先记流水，因为 ts_yield_uncarried 要清零
                // $this->stat[self::ST_CARRY] = [$carried, -$this->share['ts_yield_uncarried']];
                $this->adjustStat(self::ST_CARRY, $carried, $carried, -$this->share['ts_yield_uncarried']);

                // 结转处理
                $this->share['ts_share'] += $carried;
                $this->share['ts_yield_uncarried'] = 0;
                //
                // 结转的数据单独记录份额，份额的交易日期和可赎回日期均按照0000-00-00记录
                //
                if (!array_key_exists('0000-00-00', $this->details)) {
                    $detail = [
                        'ts_trade_date' => '0000-00-00',
                        'ts_acked_date' => '0000-00-00',
                        'ts_redeemable_date' => '0000-00-00',
                        'ts_share' => $carried,
                    ];
                    $this->details['0000-00-00'] = $detail;
                } else {
                    $this->details['0000-00-00']['ts_share'] += $carried;
                }

                break;

            case 24:
                //
                // 强制结转
                //
                // 计算强制结转的金额
                $carried = $e['it_amount'] - $e['it_share'];
                if (abs($carried - $this->share['ts_yield_uncarried']) > 0.02) {
                    Log::warning($this->logtag.'force carried mismatch, please check!', ['fund' => $this->finfo, 'carried' => $carried, 'share' => $this->share, 'event' => $e]);
                }
                // 先记流水，因为 ts_yield_uncarried 要清零
                // $this->stat[self::ST_CARRY_FORCE] = [$carried, -$this->share['ts_yield_uncarried']];
                $this->adjustStat(self::ST_CARRY_FORCE, $carried, $carried, -$this->share['ts_yield_uncarried']);
                // 结转处理
                $this->share['ts_share'] += $carried;
                $this->share['ts_yield_uncarried'] = 0;
                //
                // 结转的数据单独记录份额，份额的交易日期和可赎回日期均按照0000-00-00记录
                //
                if (!array_key_exists('0000-00-00', $this->details)) {
                    $detail = [
                        'ts_trade_date' => '0000-00-00',
                        'ts_acked_date' => '0000-00-00',
                        'ts_redeemable_date' => '0000-00-00',
                        'ts_share' => $carried,
                    ];
                    $this->details['0000-00-00'] = $detail;
                } else {
                    $this->details['0000-00-00']['ts_share'] += $carried;
                }
                break;

            case 8:
                //
                // 强增的逻辑比较简单，直接加到份额上即可
                //
                if ($this->share['ts_share_id'] == 0) {
                    $this->share['ts_share_id'] = $e['it_order_id'];
                    $this->share['ts_trade_date'] = $e['it_trade_date'];
                }
                $this->share['ts_share'] += $e['it_share'];
                #
                # 记录强增对账单:货币基金份额就是金额
                #
                // $this->stat[self::ST_ADD_FORCE] += $e['it_share'];
                $this->adjustStat(self::ST_ADD_FORCE, $e['it_share'], $e['it_share'], 0);
                //
                // 强增份额
                //
                if (!array_key_exists($e['it_date'], $this->details)) {
                    $detail = [
                        'ts_trade_date' => $e['it_trade_date'],
                        'ts_acked_date' => $e['it_trade_date'],
                        'ts_redeemable_date' => $e['it_date'],
                        'ts_share' => $e['it_share'],
                    ];
                    $this->details[$e['it_date']] = $detail;
                } else {
                    $this->details[$e['it_date']]['ts_share'] += $carried;
                }
                break;

            case 9:
                //
                // 强减
                //
                if ($this->share['ts_share'] - $e['it_share'] < -0.00001) {
                    // dd('SNH: insuffient share for force del', $this->share, $e);
                    Log::error($this->logtag.'SNH: insuffient share for force del', [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:货币强减份额不足:[%s]", $e['it_order_id']);
                    SmsService::smsAlert($alert, 'kun');
                }

                $this->share['ts_share'] -= $e['it_share'];
                #
                # 记录强减对账单：货币基金份额即金额
                #
                // $this->stat[self::ST_DEL_FORCE] -= $e['it_share'];
                $this->adjustStat(self::ST_DEL_FORCE, -$e['it_share'], -$e['it_share'], 0);
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

            case 19:
                //
                // 记录当日持仓
                //
                $totalShare = \array_sum(array_column($this->buying, 'ts_share'));
                $totalShare += $this->share['ts_share'];

                $redeemingShare = \array_sum(array_column($this->redeeming, 'ts_share'));

                if ($totalShare > 0.00001 || $redeemingShare > 0.00001) {
                    $this->ts_holding_fund[] = [
                        'ts_date' => $e['it_date'],
                        'ts_share' => $totalShare,
                        'ts_amount' => $totalShare,
                        'ts_share_redeeming' => $redeemingShare,
                        'ts_amount_redeeming' => $redeemingShare,
                    ];
                }

                //
                // 记录当日对账
                //
                foreach ($this->stat as $k => $v) {
                    if (!$v) {
                        continue;
                    }

                    list($amount, $share, $uncarried, $status) = $v;

                    $this->ts_statment[] = [
                        'ts_date' => $e['it_date'],
                        'ts_stat_type' => $k,
                        'ts_stat_status' => $status,
                        'ts_stat_amount' => $amount,
                        'ts_stat_share' => $share,
                        'ts_stat_uncarried' => $uncarried,
                    ];
                }
                // if ($e['it_date'] == '2016-11-10') {
                //     dd($this->stat, $this->ts_statment);
                // }

                break;

            default:
                Log::error($this->logtag.'unknown event type', $e);
                // dd('unknown event type', $e);
                $alert = sprintf($this->logtag."SNH:货币检测到未知事件类型:[%s]", $e['it_type']);
                SmsService::smsAlert($alert, 'kun');
        }
    }

    protected function adjustStat($type, $amount, $share, $uncarried, $status = 1)
    {
        if ($this->stat[$type]) {
            $this->stat[$type][0] += $amount;
            $this->stat[$type][1] += $share;
            $this->stat[$type][2] += $uncarried;
            if ($this->stat[$type][3] != 0) {
                $this->stat[$type][3] = $status;
            }
        } else {
            $this->stat[$type] = [$amount, $share, $uncarried, $status];
        }
    }

}
