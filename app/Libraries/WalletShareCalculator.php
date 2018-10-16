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
use App\TradeDate as BaseTradeDate;

use App\Libraries\Timing;
use App\Libraries\TdateLookupTable;

use function App\Libraries\basename_class;
use function App\Libraries\date_range;
use function App\Libraries\floorp_format;

/*
 * [XXX] 盈米宝的份额计算采用实时TA逻辑，也即购买的当天就对份额进行实时确认，
 * 赎转申也是当天就进行份额的确认。这会导致订单状态和持仓状态的不一致。因此，
 * 对于确认中的订单，请以ts_wallet_share_acking为准，不要以订单为准。 这个地方
 * 有别于以往的计算逻辑。
 */

class WalletShareCalculator
{
    use TradeDate;

    protected $logtag = '';
    protected $fund = null;
    protected $orders = [];
    protected $dividends = [];
    protected $finfo = null;
    protected $dumpCount = 0;

    //
    // 以下是计算结果
    //
    public $ts_holding_fund = [];  // 当日持仓
    public $ts_statment = [];      // 对账单
    public $ts_journal = [];       // 应用日志


    //
    // 以下是计算上下文
    //
    // 货币基金不同之处在于，由于没有赎回购买费率的影响，所以不需
    // 要先进先出， 只需要维护一个统一的份额即可。
    //
    // 但购买有是否可以赎回为问题，所以需要通过一个数组单独记录
    //
    //
    protected $profit = 0;                     // 当日收益
    public    $curTdate = '0000-00-00';        // 当前交易日
    public    $returnDaily = 0;                // 当前万份收益
    public    $returnDailyDate = '0000-00-00'; // 净值日期
    public    $shareDate = '0000-00-00';       // 份额日期(持仓是等于净值日期，清仓时等于最后一次清仓日期)
    protected $share = null;                   // 当前份额
    protected $amount = null;                  // 当前余额分账
    protected $details = null;                 // 当前份额的明细
    protected $buying = null;                  // 购买待确认份额
    protected $redeeming = null;               // 当前赎回中的份额
    protected $bonusing = null;                // 当前分红上下文
    protected $lastHolding = null;             // 昨日持仓


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
    const ST_WITHDRAW = 14;
    const ST_WITHDRAW_FAST = 15;

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
        self::ST_WITHDRAW => 0,
        self::ST_WITHDRAW_FAST => 0,
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

        list($redeemable, $withdrawable) = $this->fastWithdrawable($this->share, $this->amount);

        $shares = [];
        $shares[] = array_merge($this->share, [
            'ts_date' => $this->shareDate,
            'ts_amount_avail' => $this->amount['ts_amount_avail'],
            'ts_amount_buying' => $this->amount['ts_amount_buying'],
            'ts_amount_adjusting' => $this->amount['ts_amount_adjusting'],
            'ts_amount_withdrawing' => $this->amount['ts_amount_withdrawing'],
            'ts_amount_paying' => $this->amount['ts_amount_paying'],
            'ts_amount_refund' => $this->amount['ts_amount_refund'],
            'ts_amount_redeemable' => $redeemable,
            'ts_amount_withdrawable' => $withdrawable,
            'ts_profit' => $this->profit,
            'ts_profit_acc' => $profitAcc,
        ]);

        return $shares;
    }

    // public function ts_share_detail()
    // {
    //     $details = [];
    //     foreach ($this->details as $detail) {
    //         $detail['ts_share_id'] = $this->share['ts_share_id'];
    //         $detail['ts_nav'] = 1;
    //         $detail['ts_nav_date'] = $this->returnDailyDate;
    //         $details[] = $detail;
    //     }

    //     return $details;
    // }

    public function ts_wallet_share_acking()
    {
        $acking = [];
        foreach ($this->buying as $r) {
            $acking[] = [
                'ts_order_id' => $r['ts_order_id'],
                'ts_trade_type' => $r['ts_trade_type'],
                'ts_amount' => numfmt($r['ts_amount']),
                'ts_share' => numfmt($r['ts_share']),
                'ts_trade_date' => $r['ts_trade_date'],
                'ts_acked_date' => $r['ts_acked_date'],
                'ts_redeemable_date' => $r['ts_redeemable_date'],
                'ts_redeem_pay_date' => '0000-00-00',
                'ts_share_id' => '0',
            ];
        }

        foreach ($this->redeeming as $r) {
            $acking[] = [
                'ts_order_id' => $r['ts_order_id'],
                'ts_trade_type' => $r['ts_trade_type'],
                'ts_amount' => numfmt($r['ts_amount']),
                'ts_share' => numfmt($r['ts_share']),
                'ts_trade_date' => $r['ts_trade_date'],
                'ts_acked_date' => $r['ts_acked_date'],
                'ts_redeemable_date' => '0000-00-00',
                'ts_redeem_pay_date' => $r['ts_redeem_pay_date'],
                'ts_share_id' => $r['ts_share_id'],
            ];
        }

        return $acking;
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
    public function __construct($logtag, $fund, $orders, $dividends, $verbose = 1)
    {
        $this->logtag = $logtag;
        $this->fund   = $fund;
        $this->orders = $orders;
        $this->dividends  = $dividends;
        $this->verbose = $verbose;

        $this->ts_holding_fund = [];  // 当日持仓
        $this->ts_statment = [];      // 对账单
        $this->buying = [];           // 当前购买中的份额
        $this->redeeming = [];        // 当前赎回中的份额
        $this->bonusing = [];         // 当前分红上下文
        $this->share = [              // 当前份额
            'ts_share_id'        => 0,
            'ts_trade_date'      => '0000-00-00',
            'ts_share'           => 0,
            'ts_share_charging1'   => 0,
            'ts_share_charging21'   => 0,
            'ts_share_charging22'   => 0,
            'ts_share_charging3' => 0,
            'ts_share_transfering' => 0,
            'ts_share_transfering3' => 0,
            'ts_share_redeeming' => 0,
            'ts_share_redeeming3' => 0,
            'ts_share_withdrawing' => 0,
            'ts_share_withdrawing3' => 0,
            'ts_yield_uncarried' => 0,
        ];
        $this->amount = [
            'ts_amount_avail' => 0,
            'ts_amount_buying' => 0,
            'ts_amount_adjusting' => 0,
            'ts_amount_withdrawing' => 0,
            'ts_amount_paying' => 0,
            'ts_amount_refund' => 0,
        ];

        $this->details = [];

        $this->finfo = [
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

        $journals = collect();

        //
        // 在模型中，我们区分两类时间，现实事件：事件有现实的时间点。 虚拟时间：
        // 事件最终也发生了，但没有具体的时间点，比如订单的确认。
        //

        //
        // 生成需要应用的journal
        //
        foreach ($this->orders as $order) {
            if ($order->ts_trade_type == 21) {
                //
                // 快速提现的订单需要特殊处理，因为快速提现的订单的确认和到账逻辑跟普通订单完全不一样。
                //
                if ($order->ts_trade_status == -2) {
                    Log::error($this->logtag."fast withdraw acked failed, skip but may be problem", [$order->ts_txn_id]);
                    continue;   // 快速提现确认失败，直接跳过
                }
                if (in_array($order->ts_trade_status, [5, 6])) {
                    // 仅生成下单订单，所有的其他在下单时直接处理
                    $journals->push($this->buildPlaceJournal($order));
                } elseif ($order->ts_trade_status == 1) {
                    $journals->push($this->buildForeJournal($order));
                    $journals->push($this->buildPlaceJournal($order));
                }
                $journals->push($this->buildAcceptJournal($order));

            } else {
                switch ($order->ts_trade_status) {
                    case -2:
                        //
                        // [XXX] 该状态是订单最后确认失败，但在订单处理现场，需要
                        // 按照成功计算，因为失败之后的钱是通过退款充值W10退回来的。
                        // 换句话说，钱本质上已经在-2的时候花掉了，只是后来给退回
                        // 来了。
                        //
                        // fallthough
                    case 5:
                    case 6:
                        $journals->push($this->buildAckJournal($order));
                        // fallthough

                    case 1:
                        if ($order->ts_trade_status == 1 && in_array($order->ts_trade_type, [10, 12])) {
                            if ($order->ts_pay_status == 0 && $order->ts_trade_date == '0000-00-00') {
                                //
                                // 发生了1129, 跳过该订单
                                //
                                Log::error($this->logtag."1129 dectect, skip due to unknown pay status", [$order->ts_txn_id]);
                                break;
                            }
                            if ($order->ts_pay_status == 0 && $order->created_at > '2018-05-15 00:00:00') {
                                //
                                // 发生了1129 或者 没有输入网联验证码
                                //
                                Log::error($this->logtag."skip due to unknown pay status, maybe verify code required", [$order->ts_txn_id]);
                                break;
                            }
                        }

                        $journals->push($this->buildForeJournal($order));
                        $journals->push($this->buildPlaceJournal($order));
                        // fallthough

                    case 0:
                        $journals->push($this->buildAcceptJournal($order));

                        // if ($order->ts_txn_id == '20170606B000325S007') {
                        //     dd($order, $journals->last());
                        // }
                        break;

                    default:
                        Log::error($this->logtag."unknown trade status", [$order]);
                        $alert = sprintf($this->logtag."SNH:检测到未知交易状态:[%s, %d]", $order->ts_txn_id, $order->ts_trade_status);
                        // SmsService::smsAlert($alert, 'kun');
                        continue;
                }

                //
                // 如果是赎回，且确认金额 != 确认份额，则需要生成强制结转订单.
                //
                if (in_array($order->ts_trade_type, [20, 21, 22, 26, 41, 64]) && $order->ts_acked_amount != $order->ts_acked_share) {
                    $tmp = $order->ts_acked_amount - $order->ts_acked_share;
                    $journals->push([
                        'ts_txn_id' => $order->ts_txn_id,
                        'ts_type' => 'FCY',
                        'ts_date' => $order->ts_trade_date,
                        'ts_time' => $order->ts_trade_time,
                        'ts_amount' => $order->ts_acked_amount,
                        'ts_share' => $order->ts_acked_share,
                        'ts_fee' => 0,
                        'ts_order' => $order,
                    ]);
                    if ($journals->last()['ts_date'] == '0000-00-00') {
                        // dd("0000-00-00 decteced5", $order);
                        Log::error($this->logtag."0000-00-00 detected-5", [$order->ts_txn_id]);

                    }
                }
            }
        }

        $beginDate = $journals->min('ts_date');
        $endDate = Carbon::today()->toDateString();
        if ($beginDate < '2016-07-21') {
            //
            // [XXX] 这个是为了安全起见，经常有某个用户的的交易记录里面出现
            // 0000-00-00，这种记录会导致内存溢出，所以这个地方强制不能小于
            // 2016-07-21，也就是我们最小的一笔订单的日期。
            //
            $beginDate = '2016-07-21';
        }

        //
        // 将journal转化为events
        //
        $buyingAckEvents = collect();
        $buyingForeEvents = collect();
        $redeemAckEvents = collect();
        $redeemForeEvents = collect();
        $divEvents = collect();
        $forceDivEvents = collect();

        foreach ($journals as $journal) {
            $e1 = [
                'it_type' => $journal['ts_type'],
                'it_date' => $journal['ts_date'],
                'it_time' => $journal['ts_time'],
                'it_amount' => $journal['ts_amount'],
                'it_share' => $journal['ts_share'],
                'it_fee' => $journal['ts_fee'],
                'it_order_id' => $journal['ts_txn_id'],
                'it_order' => $journal['ts_order'],
            ];
            if ($journal['ts_type'] == 'FCY') {
                unset($e1['it_time']);
                $forceDivEvents->push($e1);
            } elseif (!starts_with($journal['ts_type'], 'K') && !starts_with($journal['ts_type'], 'F')) {
                $events[] = $e1;
            } else {
                switch($journal['ts_type']) {
                    case 'K10':
                    case 'K11':
                    case 'K12':
                    case 'K13':
                    case 'K14':
                    case 'K71':
                        $buyingAckEvents->push($e1);
                        break;

                    case 'K20':
                    case 'K21':
                    case 'K22':
                    case 'K26':
                    case 'K31':
                    case 'K51':
                    case 'K63':
                        $redeemAckEvents->push($e1);
                        break;

                    case 'F10':
                    case 'F11':
                    case 'F12':
                    case 'F13':
                    case 'F14':
                    case 'F71':
                        $buyingForeEvents->push($e1);
                        break;

                    case 'F20':
                    case 'F21':
                    case 'F22':
                    case 'F26':
                    case 'F31':
                    case 'F51':
                    case 'F63':
                        $redeemForeEvents->push($e1);
                        break;

                    case 'F19';
                    case 'K19':
                    case 'F29':
                    case 'K29':
                        // nothing to do
                        break;

                    default:
                        // dd("unknown ack type", $journal);
                        Log::error($this->logtag."unknown ack type", [$journal]);

                }
            }
        }
        //
        // 加载基金净值
        //
        $this->navEvents = collect();
        $rows = RaFundNav::where('ra_code', $this->fund->ra_code)
            ->whereBetween('ra_nav_date', [$beginDate, $endDate])
            ->where('ra_mask', 0)
            ->selectRaw('DISTINCT ra_nav_date, ra_return_daily')
            ->get(['ra_nav_date', 'ra_return_daily']);
        foreach ($rows as $row) {
            if ($row->ra_return_daily) {
                $e1 = [
                    'it_type' => 'NAV',
                    'it_date' => $row->ra_nav_date,
                    'it_time' => '15:00:00',
                    'it_return_daily'  => $row->ra_return_daily,
                    'it_placed_at' => '0000-00-00 00:00:00',
                ];

                // array_push($events, $e1);
                $this->navEvents->put($row->ra_nav_date, $e1);
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
                'it_type' => 'CRY', // 收益结转
                'it_date' => $carryDate,
                'it_time' => '19:00:00',
                'it_amount'  => $div->ts_dividend_share, // 货币基金的结转在分红表里是以share记录的
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            $divEvents->push($e1);
        }
        // dd($divEvents);

        //
        // 记录持仓事件
        //
        $dates = date_range($beginDate, $endDate);
        foreach ($dates as $day) {
            $e1 = [
                'it_type' => 'CLR',
                'it_date' => $day,
                'it_time' => '00:00:01',
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            $e3 = [
                'it_type' => 'PM3',
                'it_date' => $day,
                'it_time' => '15:00:00',
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            $e2 = [
                'it_type' => 'LST',
                'it_date' => $day,
                'it_time' => '23:59:59',
                'it_placed_at' => '0000-00-00 00:00:00',
            ];

            array_push($events, $e1, $e2, $e3);
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

            if (isset($a['it_order_id']) && isset($b['it_order_id']) && $a['it_order_id'] != $b['it_order_id']) {
                return strcmp($a['it_order_id'], $b['it_order_id']);
            }

            return strcmp($a['it_type'], $b['it_type']);
        });

        $this->buyingAckEvents = $buyingAckEvents->groupBy('it_date');
        $this->buyingForeEvents = $buyingForeEvents->groupBy('it_date');
        $this->redeemAckEvents = $redeemAckEvents->groupBy('it_date');
        $this->redeemForeEvents = $redeemForeEvents->groupBy('it_date');
        $this->divEvents = $divEvents->groupBy('it_date');
        $this->forceDivEvents = $forceDivEvents->groupBy('it_date');

        //
        // 充值份额登记事件，仅用于处理各种充值操作
        //
        // [XXX] 盈米的的充值订单会产生充值的份额，共有三类充值份额：
        //
        // 1. 1类充值份额：不可用于购买基金，不能进行普通提现，不能进行快速提
        // 现。这类份额往往是赎回到盈米宝的QDII基金产生。QDII基金赎回时，会在
        // 赎回订单的确认日产生一个对应的盈米宝充值订单，该订单不遵循3点前后的
        // 交易规则，其交易日为该QDII基金赎回到账日期。确认日为该充值订单的确
        // 认日。在订单下单之后，未到订单交易日之前，这部分份额即为1类份额。一
        // 类份额会在交易日当天根据基金的不同转化为对应的2类（QDII）或者3类份
        // 额（非QDII）。
        //
        // 2. 2.1类充值份额：可用于购买基金，但不能进行普通提现，不能进行快速提
        // 现。该类份额是QDII赎回到盈米宝产生的充值订单在交易日当天份额登记时
        // 转化而来。
        //
        // 3. 2.2类充值份额：可用于购买基金，可以进行普通提现，不能进行快速提现。
        // 该类份额是非QDII基金赎回到盈米宝产生的充值订单在交易日当天份额登记
        // 时转化而来。
        //
        // 3. 3类充值份额：可用于购买基金，可以进行普通提现，不能进行快速提现。
        // 该类份额是2.1类份额或者2.2类份额在交易日的3点之后转化而来
        //
        // 份额登记的逻辑是，一笔充值订单下单后，首先产生的1类充值份额，根据订
        // 单的交易日和当前的交易日是否一样，会有两种不同的处理：（1）如果订单
        // 的交易日和当前交易日一样，则根据基金不同转化为2.1类份额和2.2类份额。
        // （2）如果订单交易日和当前交易日不一样，则生成充值份额登记事件，该事
        // 件会在交易日当天处理，将1类份额转化为2.1或者2.2类份额。2.1或者2.2类
        // 份额会在交易日结束时，转化3类份额。
        //
        //
        $this->chargeRegEvents = collect();

        //
        // 初始化交易日
        //
        if (!empty($events)) {
            $this->curTdate = static::tradeDate($events[0]['it_date']);
        }

        // $this->dumpEvents('AAA', $events);
        foreach ($events as $event) {
            if ($event['it_date'] <= $this->today) {
                $this->process($event);
            }
        }

    }

    protected function process($e)
    {
        $defaut = collect();
        //$timing = new Timing(sprintf('%s@%s [%s:%s]', basename_class(__CLASS__), __FUNCTION__, $this->uid, $this->fundId));

        $type = $e['it_type'];
        // if ($type == 'A20') {
        //     dd($e);
        // }


        switch ($type) {
            case 'CLR':
                //
                // 重置日内信息
                //
                // $this->profit = 0; // 当日收益
                $this->stat = array_fill_keys(array_keys($this->stat), 0); // 当日流水

                // @TODO 01:30:00 强增

                // 02:00:00 购买确认
                foreach ($this->buyingAckEvents->get($e['it_date'], $defaut) as $ack) {
                    if (array_key_exists($ack['it_order_id'], $this->buying)) {
                        $buying = $this->buying[$ack['it_order_id']];
                        $order = $ack['it_order'];

                        if ($order->ts_txn_id == '20160823B000009C001') {
                            // dump($e, "fxxx", $this->share, $this->amount);
                        }

                        if ($this->share['ts_share'] < 0.00001) {
                            //
                            // 如果用户以前清仓后又重新购买，清除份额日期,设置份额净值为交易净值
                            //
                            @list($this->shareNav, $this->shareDate) = [$ack['it_nav'], '0000-00-00'];
                            $this->share['ts_trade_date'] = $buying['ts_trade_date'];
                        }

                        //
                        // 加到share上
                        //
                        if ($this->share['ts_share_id'] == 0) {
                            $this->share['ts_share_id'] = $buying['ts_order_id'];
                            $this->share['ts_trade_date'] = $buying['ts_trade_date'];
                        }
                        $this->share['ts_share'] += $ack['it_share'];
                        if (in_array($ack['it_type'], ['K10', 'K11', 'K12', 'K13', 'K14', 'K71'])) {
                            $this->share['ts_share_charging3'] -= $ack['it_share'];
                        } else {
                            // dd('unknown buy ack type', $ack);
                            Log::error($this->logtag."unknown buy ack type", [$ack]);

                        }
                        $this->verboseChange($ack, 'm', 'it_share', 'share');

                        if ($order->ts_txn_id == '20160823B000009C001') {
                            // dd($e, "fyyy", $this->share, $this->amount);
                        }

                        //
                        // 清除购买上下文
                        //
                        unset($this->buying[$ack['it_order_id']]);
                    } else {
                        Log::error($this->logtag.'buying share not find but acked', $ack);
                    }
                }

                // 03:00:00 赎回确认
                foreach ($this->redeemAckEvents->get($e['it_date'], $defaut) as $ack) {
                    if (array_key_exists($ack['it_order_id'], $this->redeeming)) {
                        $redeeming = $this->redeeming[$ack['it_order_id']];
                        $order = $ack['it_order'];

                        // if ($order->ts_txn_id == '20160822B000009S007') {
                        //     dump($ack, "xxxx", $this->share, $this->amount);
                        // }

                        //
                        // 从share上扣减
                        //
                        if ($this->share['ts_share'] - $ack['it_amount'] < -0.00001) {
                            Log::error($this->logtag.'SNH: insuffient share for redeem', [$this->share, $ack]);
                            $alert = sprintf($this->logtag."SNH:赎回份额不足:[%s]", $ack['it_order_id']);
                            // SmsService::smsAlert($alert, 'kun');
                        }

                        $this->share['ts_share'] -= $ack['it_amount'];
                        if (in_array($ack['it_type'], ['K31', 'K51', 'K63'])) {
                            $this->share['ts_share_transfering3'] -= $ack['it_amount'];
                            $this->verboseChange($ack, 'r', 'it_amount', 'share');
                        } elseif (in_array($ack['it_type'], ['K20', 'K22', 'K26'])) {
                            $this->share['ts_share_redeeming3'] -= $ack['it_share'];
                            $toForceRedeem = $order->ts_acked_amount - $order->ts_acked_share;
                            if ($toForceRedeem > -0.00001) {
                                // 需要处理强制结转逻辑
                                $this->amount['ts_amount_refund'] -= $toForceRedeem;
                                if ($this->amount['ts_amount_refund'] < -0.00001) {
                                    Log::error($this->logtag.'SNH: insuffient amount for K20/K22/K26', [$this->share, $e]);
                                    $alert = sprintf($this->logtag."SNH:提现余额不足:[%s]", $ack['it_order_id']);
                                    // SmsService::smsAlert($alert, 'kun');
                                }
                            }
                            $this->verboseChange($ack, 'r', 'it_share', 'share');
                        } elseif (in_array($ack['it_type'], ['K21'])) {
                            $this->share['ts_share_withdrawing3'] -= $ack['it_share'];
                            $toForceRedeem = $order->ts_acked_amount - $order->ts_acked_share;
                            if ($toForceRedeem > -0.00001) {
                                // 需要处理强制结转逻辑
                                $this->amount['ts_amount_refund'] -= $toForceRedeem;
                                if ($this->amount['ts_amount_refund'] < -0.00001) {
                                    Log::error($this->logtag.'SNH: insuffient amount for K21', [$this->share, $e]);
                                    $alert = sprintf($this->logtag."SNH:快速提现余额不足:[%s]", $ack['it_order_id']);
                                    // SmsService::smsAlert($alert, 'kun');
                                }
                            }
                            $this->verboseChange($ack, 'r', 'it_share', 'share');
                        } else {
                            // dd("unknown redeem ack type", $ack);
                            Log::error($this->logtag."unknown redeem ack type", [$ack]);
                        }

                        if ($e['it_date'] == '2018-02-09') {
                            // dump("DDDDxxxx", $ack['it_type'], $ack['it_amount'], $this->share, $this->amount);
                        }
                        // if ($order->ts_txn_id == '20160822B000009S007') {
                        //     dump($e, "xxxx", $this->share, $this->amount);
                        // }


                        unset($this->redeeming[$ack['it_order_id']]);
                    } else {
                        Log::error($this->logtag.'redeem record not find but acked', $ack);
                    }
                }

                // @TODO 05:00:00 强减

                break;

            case 'A10': // 盈米宝充值买基金受理
            case 'A11': // 退款自动充值受理
            case 'A12': // 银行卡充值盈米宝
            case 'A13': // 赎回到盈米宝到账充值
            case 'A14': // 调仓赎回到盈米宝退款充值
            case 'A31': // 盈米宝购买基金受理
            case 'A51': // 盈米宝定投买基金受理
            case 'A63': // 调仓盈米宝购买基金
            case 'A71': // 分红自动充值（这种情况发生在用户全部赎回但货币基金未强制结转时）
            case 'A19': // 购买冻结订单受理

            case 'A29': // 购买解冻订单受理
                break;

            case 'P19': // 购买冻结
                $order = $e['it_order'];

                // if ($e['it_date'] == '2016-08-24') {
                //     // dump("aa", $this->share, $e, $this->redeeming);
                // }

                //
                // 处理盈米宝记账部分
                //
                list($left, $this->amount['ts_amount_avail'])
                    = $this->ledger($e['it_amount'], [$this->amount['ts_amount_avail']]);
                $this->verboseChange($e, 'F', 'it_amount', 'amount');
                if ($left > 0.0001) {
                    Log::error($this->logtag.'SNH: insuffient amount for A19', [$this->amount, $e]);
                    $alert = sprintf($this->logtag."SNH:购买冻结余额不足:[%s]", $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');
                    $this->amount['ts_amount_refund'] -= $left;
                    $this->verboseChange($e, 'x', 'it_amount', 'amount', $left);
                }
                $this->amount['ts_amount_buying'] += $e['it_amount'];
                $this->verboseChange($e, '+', 'it_amount', 'amount');
                //
                // 记录冻结对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                //
                // $this->adjustStat(self::ST_REDEEM, -$e['it_share'], -$e['it_share'], 0);

                // if ($e['it_date'] == '2018-01-17') {
                //     dd("bb", $this->share, $e, $this->redeeming);
                // }

                break;
            case 'A20': // 撤单提现受理
            case 'A21': // 快速提现受理
            case 'A22': // 普通提现受理
            case 'A26': // 调仓提现
                $order = $e['it_order'];

                if ($e['it_date'] == '2016-08-24') {
                    // dump("aa", $this->share, $e, $this->redeeming);
                }

                //
                // 处理盈米宝记账部分
                // 撤单提现只能从购买中的份额扣减, 提现订单是按照份额算的.
                //
                // [XXX] 部分订单发生过充值购买，失败后退款充值再提现，因此这
                // 里我们扣款的是先从ts_amount_buying里面扣，如果不够，再从
                // ts_amount_refund里面扣。
                //
                if ($e['it_date'] <= '2018-05-15') {
                    if ($type == 'A20') {
                        list($left, $this->amount['ts_amount_buying'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_adjusting'], $this->amount['ts_amount_avail'])
                            = $this->ledger($e['it_share'], [$this->amount['ts_amount_buying'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_adjusting'], $this->amount['ts_amount_avail']]);
                    } elseif ($type == 'A22') {
                        list($left, $this->amount['ts_amount_avail'])
                            = $this->ledger($e['it_share'], [$this->amount['ts_amount_avail']]);
                    } else { // A26
                        list($left, $this->amount['ts_amount_adjusting'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_buying'], $this->amount['ts_amount_avail'])
                            = $this->ledger($e['it_share'], [$this->amount['ts_amount_adjusting'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_buying'], $this->amount['ts_amount_avail']]);
                    }
                } else {
                    if ($type == 'A20') {
                        list($left, $this->amount['ts_amount_buying'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_avail'])
                            = $this->ledger($e['it_share'], [$this->amount['ts_amount_buying'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_avail']]);
                    } elseif ($type == 'A22') {
                        list($left, $this->amount['ts_amount_avail'])
                            = $this->ledger($e['it_share'], [$this->amount['ts_amount_avail']]);
                    } elseif ($type == 'A21') {
                        list($left, $this->amount['ts_amount_avail'])
                            = $this->ledger($e['it_share'], [$this->amount['ts_amount_avail']]);
                    } else { // A26
                        list($left, $this->amount['ts_amount_adjusting'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_avail'])
                            = $this->ledger($e['it_share'], [$this->amount['ts_amount_adjusting'], $this->amount['ts_amount_refund'], $this->amount['ts_amount_avail']]);
                    }
                }
                $this->verboseChange($e, '-', 'it_share', 'amount');
                if ($left > 0.0001) {
                    Log::error($this->logtag."SNH: insuffient amount for $type", [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:提现%s余额不足:[%s]", $type, $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');
                    $this->amount['ts_amount_refund'] -= $left;
                    $this->verboseChange($e, 'x', 'it_share', 'amount', $left);
                }
                $this->amount['ts_amount_withdrawing'] += $e['it_share'];
                $this->verboseChange($e, '+', 'it_share', 'amount');
                break;

            case 'P29': // 购买解冻订单受理
                $order = $e['it_order'];

                // if ($e['it_date'] == '2016-08-24') {
                //     // dump("aa", $this->share, $e, $this->redeeming);
                // }

                //
                // 处理盈米宝记账部分
                //
                list($left, $this->amount['ts_amount_buying'])
                    = $this->ledger($e['it_share'], [$this->amount['ts_amount_buying']]);
                $this->verboseChange($e, 'F', 'it_share', 'amount');
                if ($left > 0.0001) {
                    Log::error($this->logtag.'SNH: insuffient amount for A29', [$this->amount, $e]);
                    $alert = sprintf($this->logtag."SNH:购买冻结余额不足:[%s]", $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');
                    $this->amount['ts_amount_refund'] -= $left;
                    $this->verboseChange($e, 'x', 'it_share', 'amount', $left);
                }
                $this->amount['ts_amount_avail'] += $e['it_share'];
                $this->verboseChange($e, '+', 'it_share', 'amount');
                //
                // 记录冻结对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                //
                // $this->adjustStat(self::ST_REDEEM, -$e['it_share'], -$e['it_share'], 0);

                // if ($e['it_date'] == '2018-01-17') {
                //     dd("bb", $this->share, $e, $this->redeeming);
                // }

                break;
            case 'P10': // 购买下充值单
            case 'P11': // 退款自动充值
            case 'P12': // 银行卡充值下单
            case 'P13': // 赎回到盈米宝到账充值
            case 'P14': // 调仓赎回到盈米宝
            case 'P71': // 分红自动充值
                $order = $e['it_order'];
                if (array_key_exists($e['it_order_id'], $this->buying)) {
                    // dd("buying order exists", [$this->buying, $e]);
                    Log::error($this->logtag."buying order exists", [$this->buying, $e]);
                    $alert = sprintf($this->logtag."SNH:检测到重复购买订单:[%s]", $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');
                }

                if ($order->ts_txn_id == '20160823B000009C001') {
                    // dump($e, "xxxx", $this->share, $this->amount);
                }

                $buying = [
                    'ts_order_id' => $e['it_order_id'],
                    'ts_trade_type' => $order->ts_trade_type,
                    'ts_trade_nav' => 0,
                    'ts_trade_date' => $order->ts_trade_date,
                    'ts_acked_date' => $order->ts_acked_date,
                    'ts_redeemable_date' => $order->ts_acked_date,
                    'ts_share' => $e['it_amount'],
                    'ts_amount' => $e['it_amount'],
                ];
                $this->buying[$e['it_order_id']] = $buying;
                if ($e['it_order_id'] == '20170228B000017S014') {
                    // dump($e);
                }
                #
                # 记录购买对账单: 购买时记账手续费要计入购买金额(it_amount是包含手续费)
                #
                $this->adjustStat(self::ST_SUB, $e['it_amount'], $e['it_amount'], 0);
                // $this->stat[self::ST_SUB_FEE] -= $e['it_fee']; // 货币基金目前没有手续费

                $this->share['ts_share_charging1'] += $e['it_amount'];
                $this->verboseChange($e, '+', 'it_amount', 'share');

                //
                // 处理份额注册
                //
                $reg = [
                    'it_type' => 'R'.$order->ts_trade_type,
                    'it_date' => $e['it_date'],
                    'it_time' => $e['it_time'],
                    'it_amount' => $order->ts_placed_amount,
                    'it_share' => $order->ts_placed_share,
                    'it_order_id' => $order->ts_txn_id,
                    'it_order' => $order,
                ];
                if ($order->ts_trade_date == $this->curTdate) {
                    // 下单时订单的交易日，当前所属交易日一致
                    if ($order->ts_trade_date < '2018-05-15' || $order->ts_pay_status == 1) {
                        $this->share['ts_share_charging1'] -= $reg['it_amount'];
                        if (in_array($reg['it_type'], ['R13', 'R14'])) {
                            $this->share['ts_share_charging21'] += $reg['it_amount'];
                        } else {
                            $this->share['ts_share_charging22'] += $reg['it_amount'];
                        }
                    }
                    $this->verboseChange($reg, 'g', 'it_amount', 'share');
                } else {
                    if ($this->chargeRegEvents->has($order->ts_trade_date)) {
                        $this->chargeRegEvents->get($order->ts_trade_date)->push($reg);
                    } else {
                        $tmp = collect([$reg]);
                        $this->chargeRegEvents->put($order->ts_trade_date, $tmp);
                    }
                }

                //
                // 处理盈米宝记账部分
                //
                if ($type == 'P10') {
                    $this->amount['ts_amount_buying'] += $e['it_amount'];
                    $this->verboseChange($e, '+', 'it_amount', 'amount');
                } elseif ($type == 'P11') {
                    $this->amount['ts_amount_refund'] += $e['it_amount'];
                    $this->verboseChange($e, '+', 'it_amount', 'amount');
                } elseif ($type == 'P13') {
                    $this->amount['ts_amount_avail'] += $e['it_amount'];
                    $this->verboseChange($e, '+', 'it_amount', 'amount');
                } elseif ($type == 'P14') {
                    $this->amount['ts_amount_adjusting'] += $e['it_amount'];
                    $this->verboseChange($e, '+', 'it_amount', 'amount');
                } else { // $type == P71 && $type == P12
                    $this->amount['ts_amount_avail'] += $e['it_amount'];
                    $this->verboseChange($e, '+', 'it_amount', 'amount');
                }
                if ($order->ts_txn_id == '20160823B000009C001') {
                    // dump($e, "yyyy", $this->share, $this->amount);
                }
                break;

            case 'P20': // 撤单提现
            case 'P21': // 快速提现
            case 'P22': // 普通提现
            case 'P26': // 调仓提现

                $redeemings = [];
                $order = $e['it_order'];

                if ($e['it_date'] == '2016-08-24') {
                    // dump("aa", $this->share, $e, $this->redeeming);
                }

                $avail = $this->share['ts_share'];
                if (!in_array($type, ['P21'])) {
                    $avail += ($this->share['ts_share_charging22'] + $this->share['ts_share_charging3']);
                }
                $avail = $avail - $this->share['ts_share_redeeming']
                    - $this->share['ts_share_redeeming3']
                    - $this->share['ts_share_transfering']
                    - $this->share['ts_share_transfering3']
                    - $this->share['ts_share_withdrawing']
                    - $this->share['ts_share_withdrawing3'];

                if ($avail - $e['it_share'] < -0.00001) {
                    // dd('SNH: insuffient share for redeem', $this->share, $e);
                    Log::error($this->logtag."SNH: insuffient share for $type", [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:盈米宝撤单提现份额不足:[%s]", $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');
                }

                if (in_array($type, ['P20', 'P22', 'P26'])) {
                    $this->share['ts_share_redeeming'] += $e['it_share'];
                } else {
                    $this->share['ts_share_withdrawing'] += $e['it_share'];
                }
                $this->verboseChange($e, '+', 'it_share', 'share');

                $this->redeeming[$e['it_order_id']] = [
                    'ts_order_id' => $e['it_order_id'],
                    'ts_share_id' => $this->share['ts_share_id'],
                    'ts_trade_type' => $order->ts_trade_type,
                    'ts_trade_date' => $order->ts_trade_date,
                    'ts_share' => $e['it_share'],
                    'ts_amount' => $e['it_share'],
                    'ts_acked_date' => $order->ts_acked_date,
                    'ts_redeem_pay_date' => $order->ts_redeem_pay_date ? : '0000-00-00',
                ];

                //
                // 记录赎回对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                //
                $this->adjustStat(self::ST_REDEEM, -$e['it_share'], -$e['it_share'], 0);

                // if ($e['it_date'] == '2018-01-17') {
                //     dd("bb", $this->share, $e, $this->redeeming);
                // }
                //
                // 处理盈米宝记账部分
                // 撤单提现只能从购买中的份额扣减, 提现订单是按照份额算的.
                //
                // [XXX] 部分订单发生过充值购买，失败后退款充值再提现，因此这
                // 里我们扣款的是先从ts_amount_buying里面扣，如果不够，再从
                // ts_amount_refund里面扣。
                //

                if ($e['it_date'] <= '2018-05-15') {
                    list($left, $this->amount['ts_amount_withdrawing'], $this->amount['ts_amount_refund'])
                        = $this->ledger($e['it_share'], [$this->amount['ts_amount_withdrawing'], $this->amount['ts_amount_refund']]);
                } else {
                    list($left, $this->amount['ts_amount_withdrawing'], $this->amount['ts_amount_refund'])
                        = $this->ledger($e['it_share'], [$this->amount['ts_amount_withdrawing'], $this->amount['ts_amount_refund']]);
                }
                $this->verboseChange($e, '-', 'it_share', 'amount');
                if ($left > 0.0001) {
                    Log::error($this->logtag.'SNH: insuffient amount for P26', [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:撤单提现余额不足:[%s]", $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');
                    $this->amount['ts_amount_refund'] -= $left;
                    $this->verboseChange($e, 'x', 'it_share', 'amount', $left);
                }

                //
                // P21类型需要特殊处理
                //
                if ($type == 'P21') {
                    if (in_array($order->ts_trade_status, [5, 6])) {
                        // 处理份额登记
                        $fore = [
                            'it_type' => 'F21',
                            'it_date' => $e['it_date'],
                            'it_time' => $e['it_time'],
                            'it_amount' => $order->ts_acked_amount,
                            'it_share' => $order->ts_acked_share,
                            'it_fee' => $order->ts_acked_fee,
                            'it_order_id' => $order->ts_txn_id,
                        ];
                        $this->share['ts_share_withdrawing'] -= $fore['it_share'];
                        $this->share['ts_share_withdrawing3'] += $fore['it_share'];
                        $this->verboseChange($fore, 'g', 'it_share', 'share');

                        // 处理份额确认
                        $ack = array_merge($fore, ['it_type' => 'K21']);
                        $redeeming = $this->redeeming[$ack['it_order_id']];

                        //
                        // 从share上扣减
                        //
                        if ($this->share['ts_share'] - $ack['it_amount'] < -0.00001) {
                            Log::error($this->logtag.'SNH: insuffient share for redeem', [$this->share, $ack]);
                            $alert = sprintf($this->logtag."SNH:赎回份额不足:[%s]", $ack['it_order_id']);
                            // SmsService::smsAlert($alert, 'kun');
                        }

                        $this->share['ts_share'] -= $ack['it_amount'];
                        $this->share['ts_share_withdrawing3'] -= $ack['it_share'];
                        // 理论上21订单无需处理强制结转逻辑，但这里进行了保留
                        $toForceRedeem = $order->ts_acked_amount - $order->ts_acked_share;
                        if ($toForceRedeem > -0.00001) {
                            $this->amount['ts_amount_refund'] -= $toForceRedeem;
                            if ($this->amount['ts_amount_refund'] < -0.00001) {
                                Log::error($this->logtag.'SNH: insuffient amount for K21', [$this->share, $e]);
                                $alert = sprintf($this->logtag."SNH:快速提现余额不足:[%s]", $ack['it_order_id']);
                                // SmsService::smsAlert($alert, 'kun');
                            }
                        }
                        $this->verboseChange($ack, 'r', 'it_share', 'share');

                        unset($this->redeeming[$ack['it_order_id']]);
                    }
                }

                break;

            case 'P31': // 盈米宝买基金下单
            case 'P51': // 盈米宝定投买基金下单
            case 'P63': // 调仓盈米宝买基金下单
                //
                // 盈米宝下单本质上是个赎回转申购的过程，这里当做盈米宝赎回处理
                //
                $redeemings = [];
                $order = $e['it_order'];

                // if ($order->ts_txn_id == '20160822B000009S007') {
                //     dump($e, "xxxx", $this->share, $this->amount);
                // }

                // 购买基金是，ts_share和ts_share_buying 都可以用
                $avail = $this->share['ts_share']
                    + $this->share['ts_share_charging21']
                    + $this->share['ts_share_charging22']
                    + $this->share['ts_share_charging3']
                    - $this->share['ts_share_redeeming']
                    - $this->share['ts_share_redeeming3']
                    - $this->share['ts_share_transfering']
                    - $this->share['ts_share_transfering3']
                    - $this->share['ts_share_withdrawing']
                    - $this->share['ts_share_withdrawing3'];

                // 因为我们使用了强制结转逻辑， 赎回的时候，我们就得使用
                // it_amount，而不是it_share, 否则，强制结转的那部分收益就可能
                // 不被赎回。
                if ($avail - $e['it_amount'] < -0.00001) {
                    // dd('SNH: insuffient share for redeem', $this->share, $e);
                    Log::error($this->logtag.'SNH: insuffient share for redeem', [$this->share, $e]);
                    $alert = sprintf($this->logtag."SNH:货币赎回份额不足:[%s]", $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');
                }

                // $this->share['ts_share'] -= $e['it_amount'];
                $this->share['ts_share_transfering'] += $e['it_amount'];
                $this->verboseChange($e, '+', 'it_amount', 'share');

                $this->redeeming[$e['it_order_id']] = [
                    'ts_order_id' => $e['it_order_id'],
                    'ts_share_id' => $this->share['ts_share_id'],
                    'ts_trade_type' => $order->ts_trade_type,
                    'ts_trade_date' => $order->ts_trade_date,
                    'ts_share' => $e['it_amount'],
                    'ts_amount' => $e['it_amount'],
                    'ts_acked_date' => $order->ts_acked_date,
                    'ts_redeem_pay_date' => $order->ts_redeem_pay_date ? : '0000-00-00',
                ];

                // if ($e['it_date'] == '2018-04-11') {
                //     dd($this->share, $e, $this->redeeming);
                // }

                //
                // 记录赎回对账单：赎回时手续费单独记录，赎回金额实际是到账金额
                //
                $this->adjustStat(self::ST_REDEEM, -$e['it_amount'], -$e['it_amount'], 0);

                //
                // 处理盈米宝记账部分
                //
                if (in_array($type, ['P31', 'P51'])) {
                    $column = 'ts_amount_buying';
                } else {
                    $column = 'ts_amount_adjusting';
                }
                //
                // [XXX] 金额扣减时，由于我们不知道退款充值是来自调仓
                // 还是来自购买，所以这里的处理方式：如果先扣调仓和购
                // 买，不够的话从refund里补。
                //
                if ($e['it_date'] <= '2018-05-15') {
                    list($left, $this->amount[$column], $this->amount['ts_amount_refund'], $this->amount['ts_amount_avail'])
                        = $this->ledger($e['it_amount'], [$this->amount[$column], $this->amount['ts_amount_refund'], $this->amount['ts_amount_avail']]);
                } else {
                    list($left, $this->amount[$column], $this->amount['ts_amount_refund'])
                        = $this->ledger($e['it_amount'], [$this->amount[$column], $this->amount['ts_amount_refund']]);
                }
                $this->verboseChange($e, '-', 'it_amount', 'amount');
                if ($left > 0.00001) {
                    Log::error($this->logtag.'SNH: insuffient amount for redemption transfer', [$this->share, $this->amount, $e]);
                    $alert = sprintf($this->logtag."SNH:购买赎转申余额不足:[%s]", $e['it_order_id']);
                    // SmsService::smsAlert($alert, 'kun');

                    $this->amount['ts_amount_refund'] -= $left; // 强制记在退款上
                    $this->verboseChange($e, 'x', 'it_amount', 'amount', $left);
                }

                // if ($order->ts_txn_id == '20160822B000009S007') {
                //     dd($e, "YYYY", $this->share, $this->amount);
                // }

                break;


            case 'PM3':
                //
                // 交易日结束
                //

                if (static::isTradeDate($e['it_date'])) {
                    // 15:00:00 充值预确认
                    foreach ($this->buyingForeEvents->get($e['it_date'], $defaut) as $fore) {
                        if (array_key_exists($fore['it_order_id'], $this->buying)) {
                            $buying = $this->buying[$fore['it_order_id']];
                            $order = $fore['it_order'];

                            if ($order->ts_txn_id == '20160823B000009C001') {
                                // dump($e, "fxxx", $this->share, $this->amount);
                            }

                            $this->share['ts_share_charging3'] += $fore['it_share'];
                            if (in_array($fore['it_type'], ['F10', 'F11', 'F12', 'F71'])) {
                                $this->share['ts_share_charging22'] -= $fore['it_share'];
                            } elseif (in_array($fore['it_type'], ['F13', 'F14'])) {
                                $this->share['ts_share_charging21'] -= $fore['it_share'];
                            } else {
                                // dd('unknown buy fore type', $fore);
                                Log::error($this->logtag."unknown buy fore type", [$fore]);

                            }
                            $this->verboseChange($fore, 'g', 'it_share', 'share');

                            if ($order->ts_txn_id == '20160823B000009C001') {
                                // dd($e, "fyyy", $this->share, $this->amount);
                            }
                            if ($e['it_date'] == '2018-02-09') {
                                // dump("CCCCCxxxx", $fore['it_type'], $fore['it_amount'], $this->share, $this->amount);
                            }

                        } else {
                            Log::error($this->logtag.'buying share not find but acked', $fore);
                        }
                    }

                    // if ($e['it_date'] == '2017-08-22') {
                    //     dd($this->redeeming);
                    // }
                    // 15:00:00 赎回/提现预确认
                    foreach ($this->redeemForeEvents->get($e['it_date'], $defaut) as $fore) {
                        if (array_key_exists($fore['it_order_id'], $this->redeeming)) {
                            $redeeming = $this->redeeming[$fore['it_order_id']];
                            $order = $fore['it_order'];

                            if (in_array($fore['it_type'], ['F31', 'F51', 'F63'])) {
                                $this->share['ts_share_transfering'] -= $fore['it_amount'];
                                $this->share['ts_share_transfering3'] += $fore['it_amount'];
                                $this->verboseChange($fore, 'g', 'it_amount', 'share');
                            } elseif (in_array($fore['it_type'], ['F20', 'F22', 'F26'])) {
                                $this->share['ts_share_redeeming'] -= $fore['it_share'];
                                $this->share['ts_share_redeeming3'] += $fore['it_share'];
                                $this->verboseChange($fore, 'g', 'it_share', 'share');
                            }  elseif (in_array($fore['it_type'], ['F21'])) {
                                $this->share['ts_share_withdrawing'] -= $fore['it_share'];
                                $this->share['ts_share_withdrawing3'] += $fore['it_share'];
                                $this->verboseChange($fore, 'g', 'it_share', 'share');
                            }else {
                                // dd("unknown redeem fore type", $fore);
                                Log::error($this->logtag."unknown redeem fore type", [$fore]);
                            }

                            if ($e['it_date'] == '2018-02-09') {
                                // dump("DDDDxxxx", $fore['it_type'], $fore['it_amount'], $this->share, $this->amount);
                            }
                        } else {
                            Log::error($this->logtag.'redeem record not find but acked', $fore);
                        }
                    }
                }

                if ($this->navEvents->has($e['it_date'])) {
                    $ev_nav = $this->navEvents->get($e['it_date']);
                    @list($this->returnDaily, $this->returnDailyDate, $this->profit) = [$ev_nav['it_return_daily'], $ev_nav['it_date'], 0];
                    // if ($ev_nav['it_date'] == '2018-04-11') {
                    //     dd($ev_nav, $this->share);
                    // }

                    //
                    // 有效计息份额
                    //
                    $effectiveShare = $this->share['ts_share'];

                    //
                    // 因为货币基金的日收益通过万份收益预估是算不准的，我们的处理方式是：
                    //
                    // 1. 如果有基金公司返回的明确的未结转收益数据，则直接使用该数据。
                    // 2. 否则，通过万份收益去预估，但预估会有一分钱的差异
                    //
                    $profit = 0;
                    if (isset($ev_nav['it_return'])) {
                        $profit = $ev_nav['it_return'];
                    } else {
                        //
                        // [XXX] 通过万份收益对日收益进行预估，按照下面公式：
                        //
                        //     floorp(持有份额 * 万份收益)
                        //
                        // 这里采用去尾法，因为大部分货币基金都是用的去尾法。
                        //
                        $tmp = $effectiveShare * $ev_nav['it_return_daily'] / 10000;
                        if (abs($tmp) < 0.00001) {
                            $profit = 0;
                        } else {
                            $profit = floorp($tmp, 2);
                        }
                    }

                    //
                    // [XXX] 在产品使用中发现一个问题，理论上用户只要有持仓，当日
                    // 有万份收益，就应该给用户展示收益，哪怕收益为0。
                    //
                    if ($effectiveShare > 0.00001 || abs($profit) > 0.00001) {
                        // 调增未结转收益或份额
                        $this->share['ts_yield_uncarried'] += $profit;
                        # 记录日收益对账单
                        // $this->stat[self::ST_UNCARRY] = [0, $profit];
                        $this->adjustStat(self::ST_UNCARRY, 0, 0, $profit);
                        $this->profit = $profit;
                    }

                    //
                    // 如果是持仓，更新份额日期
                    //
                    if ($this->share['ts_share'] > 0.00001) {
                        $this->shareDate = $this->returnDailyDate;
                    }
                }

                //
                // 15:00:00 强制结转
                //
                // 不管是不是交易日，只要当天有结转信息就进行结转
                //
                foreach ($this->forceDivEvents->get($e['it_date'], $defaut) as $carry) {
                    //
                    // 强制结转
                    //
                    // 计算强制结转的金额
                    $carried = $carry['it_amount'] - $carry['it_share'];
                    if (abs($carried - $this->share['ts_yield_uncarried']) > 0.02) {
                        Log::warning($this->logtag.'force carried mismatch, please check!', ['fund' => '001826', 'carried' => $carried, 'share' => $this->share, 'event' => $e]);
                    }
                    // 先记流水，因为 ts_yield_uncarried 要清零
                    // $this->stat[self::ST_CARRY_FORCE] = [$carried, -$this->share['ts_yield_uncarried']];
                    $this->adjustStat(self::ST_CARRY_FORCE, $carried, $carried, -$this->share['ts_yield_uncarried']);
                    // 结转处理
                    $this->share['ts_share'] += $carried;
                    $this->share['ts_yield_uncarried'] = 0;
                    $this->verboseChange($carry, 'f', 'it_amount', 'share', $carried);

                    //
                    // 处理盈米宝记账部分, 强制结转计入refund，防止用户提走
                    //
                    $this->amount['ts_amount_refund'] += $carried;
                    $this->verboseChange($carry, 'f', 'it_amount', 'amount', $carried);
                }
                //
                // 15:00:00 收益结转
                //
                // 不管是不是交易日，只要当天有结转信息就进行结转
                //
                foreach ($this->divEvents->get($e['it_date'], $defaut) as $carry) {
                    //
                    // 因为货币基金的日收益通过万份收益预估是算不准的，收益结转时，我们的处理方式是：
                    //
                    // 1. 如果有基金公司返回的明确的结转数据，则直接使用该数据。
                    // 2. 否则，直接将我们估算的未结转收益结转
                    if ($carry['it_amount']) {
                        $carried = $carry['it_amount'];
                    } else {
                        $carried = $this->share['ts_yield_uncarried'];
                    }
                    // 先记流水，因为 ts_yield_uncarried 要清零
                    // $this->stat[self::ST_CARRY] = [$carried, -$this->share['ts_yield_uncarried']];
                    $this->adjustStat(self::ST_CARRY, $carried, $carried, -$this->share['ts_yield_uncarried']);

                    // 结转处理
                    $this->share['ts_share'] += $carried;
                    $this->share['ts_yield_uncarried'] = 0;
                    $this->verboseChange($carry, 'c', 'it_amount', 'share', $carried);

                    //
                    // 处理盈米宝记账部分
                    //
                    $this->amount['ts_amount_avail'] += $carried;
                    $this->verboseChange($carry, 'c', 'it_amount', 'amount', $carried);
                }

                //
                // 处理完成，切换交易日
                //
                if ($e['it_date'] == $this->curTdate) {
                    $this->curTdate = static::nextTradeDate($this->curTdate, 1);

                    //
                    // 如果有份额注册事件，对份额进行注册
                    //
                    foreach ($this->chargeRegEvents->get($this->curTdate, $defaut) as $reg) {
                        // 下单时订单的交易日，当前所属交易日一致
                        $this->share['ts_share_charging1'] -= $reg['it_amount'];
                        $this->share['ts_share_charging21'] += $reg['it_amount'];
                        $this->verboseChange($reg, 'g', 'it_amount', 'share');
                    }
                    $this->chargeRegEvents->forget($this->curTdate);
                }

                break;

            case 'LST':
                //
                // 记录当日持仓
                //
                list($redeemable, $withdrawable) = $this->fastWithdrawable($this->share, $this->amount);
                $h = [
                    'ts_share' => $this->share['ts_share'],
                    'ts_share_charging1' =>  $this->share['ts_share_charging1'],
                    'ts_share_charging21' =>  $this->share['ts_share_charging21'],
                    'ts_share_charging22' =>  $this->share['ts_share_charging22'],
                    'ts_share_charging3' =>  $this->share['ts_share_charging3'],
                    'ts_share_transfering' =>  $this->share['ts_share_transfering'],
                    'ts_share_transfering3' =>  $this->share['ts_share_transfering3'],
                    'ts_share_redeeming' => $this->share['ts_share_redeeming'],
                    'ts_share_redeeming3' => $this->share['ts_share_redeeming3'],
                    'ts_share_withdrawing' =>  $this->share['ts_share_withdrawing'],
                    'ts_share_withdrawing3' =>  $this->share['ts_share_withdrawing3'],
                    'ts_amount_avail' => $this->amount['ts_amount_avail'],
                    'ts_amount_buying' => $this->amount['ts_amount_buying'],
                    'ts_amount_adjusting' => $this->amount['ts_amount_adjusting'],
                    'ts_amount_withdrawing' => $this->amount['ts_amount_withdrawing'],
                    'ts_amount_paying' => $this->amount['ts_amount_paying'],
                    'ts_amount_refund' => $this->amount['ts_amount_refund'],
                    'ts_amount_redeemable' => $redeemable,
                    'ts_amount_withdrawable' => $withdrawable,
                    'ts_uncarried' => $this->share['ts_yield_uncarried'],
                ];

                // if ($e['it_date'] == '2016-08-23') {
                //     dd($h);
                // }

                $saveHolding = false;
                if ($this->lastHolding) {
                    $diff = array_udiff_assoc($h, $this->lastHolding,function ($a, $b) {
                        return bccomp($a, $b, 3);
                    });

                    if (!empty($diff)) {
                        $saveHolding = true;
                    }
                } else {
                    $saveHolding = true;
                }

                if ($saveHolding) {
                    $this->lastHolding = $h;
                    $this->ts_holding_fund[] = array_merge($h, [ 'ts_date' => $e['it_date']]);
                    // dump($h, $this->lastHolding, isset($diff) ? $diff : []);
                }
                // dump($e['it_date'], $this->share);

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
                // SmsService::smsAlert($alert, 'kun');
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

    //
    // 赎回分账，将需要赎回的$amountToLedger, 依次分摊到$array的所有元素里面，返回[$left, array[0], $array[1] ...];
    //
    protected function ledger($amountToLedger, $array)
    {
        $result = [0.0];
        $left = $amountToLedger;
        foreach ($array as $e) {
            if ($left > 0.00001 && $e > 0.00001) {
                if ($e - $left > 0.00001) {
                    $result[] = $e - $left;
                    $left = 0;
                } else {
                    $result[] = 0;
                    $left -=  $e;
                }
            } else {
                $result[] = $e;
            }
        }

        $result[0] = $left;

        return $result;
    }

    protected function verboseChange($event, $op, $eventColumn, $effectedColumn, $left = null)
    {
        if ($this->verbose == 1) {
            return true;
        }

        $txnId = '';
        if (isset($event['it_order'])) {
            $txnId = $event['it_order']->ts_txn_id;
        }
        if ($effectedColumn == 'share') {
            if ($this->verbose == 3){
                if ($this->dumpCount++ % 30 == 0) {
                    printf("ev |%19s|%10s|o|%8s|%6s|%10s|%10s|%10s|%10s|%10s|%11s|%12s|%9s|%9s|%11s|%12s\n",
                           'ts_txn_id', 'it_date', 'share', $effectedColumn, 'share', 'charging1', 'charging21', 'charging22', 'charging3', 'transfering', 'transfering3', 'redeeming', 'redeeming3', 'withdrawing', 'withdrawing3');
                }
                printf("%s|%19s|%s|%s|%8.2f|%6s|%10.2f|%10.2f|%10.2f|%10.2f|%10.2f|%11.2f|%12.2f|%9.2f|%9.2f|%11.2f|%12.2f\n",
                       $event['it_type'], $txnId, $event['it_date'],
                       $op,
                       $left ? : $event[$eventColumn],
                       $effectedColumn,
                       $this->share['ts_share'],
                       $this->share['ts_share_charging1'],
                       $this->share['ts_share_charging21'],
                       $this->share['ts_share_charging22'],
                       $this->share['ts_share_charging3'],
                       round($this->share['ts_share_transfering']),
                       round($this->share['ts_share_transfering3']),
                       $this->share['ts_share_redeeming'],
                       $this->share['ts_share_redeeming3'],
                       $this->share['ts_share_withdrawing'],
                       $this->share['ts_share_withdrawing3']);
            }
        } else {
            if ($this->verbose == 2) {
                if ($this->dumpCount++ % 30 == 0) {
                    printf("ev |%19s|%10s|o|%8s|%6s|%10s|%10s|%10s|%11s|%10s|%10s|%10s|%12s\n",
                           'ts_txn_id', 'it_date', 'amount', $effectedColumn, 'avail', 'buying','adjusting', 'withdrawing', 'paying', 'refund', 'redeemable', 'withdrawable');
                }
                list($redeemable, $withdrawable) = $this->fastWithdrawable($this->share, $this->amount);
                printf("%s|%19s|%s|%s|%8.2f|%6s|%10.2f|%10.2f|%10.2f|%11.2f|%10.2f|%10.2f|%10.2f|%12.2f\n",
                       $event['it_type'], $txnId, $event['it_date'],
                       $op,
                       $left ? : $event[$eventColumn],
                       $effectedColumn,
                       $this->amount['ts_amount_avail'],
                       $this->amount['ts_amount_buying'],
                       $this->amount['ts_amount_adjusting'],
                       $this->amount['ts_amount_withdrawing'],
                       $this->amount['ts_amount_paying'],
                       $this->amount['ts_amount_refund'],
                       round($redeemable, 2),
                       round($withdrawable, 2));
            }
        }
    }

    protected function dumpEvents($prefix, $events)
    {
        foreach ($events as $e) {
            printf("%s|%s|%19s|%s|%s\n", $prefix, $e['it_type'], isset($e['it_order_id']) ? $e['it_order_id']: "", $e['it_date'], $e['it_time']);
        }
    }

    protected function getAcceptDateAndTime($order)
    {
        if ($order->ts_accepted_at) {
            if ($order->ts_accepted_at != '0000-00-00 00:00:00') {
                $acceptAt = Carbon::parse($order->ts_accepted_at);
                return [$acceptAt->toDateString(), $acceptAt->toTimeString()];
            }
        } else {
            if ($order->ts_placed_date != '0000-00-00' && $order->ts_placed_time != '0000-00-00') {
                return [$order->ts_placed_date, $order->ts_placed_time];
            }

            if ($order->ts_scheduled_at) {
                $scheduleAt = Carbon::parse($order->ts_scheduled_at);
                return [$scheduleAt->toDateString(), $scheduleAt->toTimeString()];

            }
        }

        return ['0000-00-00', '00:00:00'];
    }

    protected function fastWithdrawable($share, $amount)
    {
        $s = $share;

        // 快速提现份额只能从静态份额支出
        $s['ts_share']  -= ($s['ts_share_withdrawing'] + $s['ts_share_withdrawing3']);

        // 处理3类普通赎回份额，可以从3类充值份额中支出，也可以从静态份额中支出
        list($left, $s['ts_share_charging3'], $s['ts_share']) =
            $this->ledger($s['ts_share_redeeming3'], [$s['ts_share_charging3'], $s['ts_share']]);
        if ($left > 0.00001) {
            Log::error($this->logtag.'SNH: insuffient amount for redemption3', [$left, $share, $amount]);
            $s['ts_share'] -= $left;
        }

        // 处理3类赎转申分额，可以从3类份额中支出，也可以从静态份额中支出
        list($left, $s['ts_share_charging3'], $s['ts_share']) =
            $this->ledger($s['ts_share_transfering3'], [$s['ts_share_charging3'], $s['ts_share']]);
        if ($left > 0.00001) {
            Log::error($this->logtag.'SNH: insuffient amount for redemption3', [$left, $share, $amount]);
            $s['ts_share'] -= $left;
        }

        // 处理1类普通赎回份额
        list($left, $s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']) =
            $this->ledger($s['ts_share_redeeming'], [$s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']]);
        if ($left > 0.00001) {
            Log::error($this->logtag.'SNH: insuffient amount for redemption1', [$left, $share, $amount]);
            $s['ts_share'] -= $left;
        }

        // 处理1类赎转申份额
        list($left, $s['ts_share_charging21'], $s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']) =
            $this->ledger($s['ts_share_transfering'], [$s['ts_share_charging21'], $s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']]);
        if ($left > 0.00001) {
            Log::error($this->logtag.'SNH: insuffient amount for transfering1', [$left, $share, $amount]);
            $s['ts_share'] -= $left;
        }

        // 处理购买提现冻结份额
        list($left, $s['ts_share_charging21'], $s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']) =
            $this->ledger($amount['ts_amount_buying'] + $amount['ts_amount_withdrawing'], [$s['ts_share_charging21'], $s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']]);
        if ($left > 0.00001) {
            Log::error($this->logtag.'SNH: insuffient amount for frozen withdraw/buying', [$left, $share, $amount]);
            $s['ts_share'] -= $left;
        }

        //
        // 处理调仓冻结份额
        //
        list($left, $s['ts_share_charging1'], $s['ts_share_charging21'], $s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']) =
            $this->ledger($amount['ts_amount_adjusting'], [$s['ts_share_charging1'], $s['ts_share_charging21'], $s['ts_share_charging22'], $s['ts_share_charging3'], $s['ts_share']]);
        if ($left > 0.00001) {
            Log::error($this->logtag.'SNH: insuffient amount for frozen adjusting', [$left, $share, $amount]);
            $s['ts_share'] -= $left;
        }

        $withdrawable = $s['ts_share'];
        $redeemable = $s['ts_share'] + $s['ts_share_charging22'] + $s['ts_share_charging3'];
        return [$redeemable, $withdrawable];
    }

    protected function buildAcceptJournal($order)
    {
        list($tsDate, $tsTime) = $this->getAcceptDateAndTime($order);
        $journal = [
            'ts_txn_id' => $order->ts_txn_id,
            'ts_type' => 'A'.$order->ts_trade_type,
            'ts_date' => $tsDate,
            'ts_time' => $tsTime,
            'ts_amount' => $order->ts_placed_amount,
            'ts_share' => $order->ts_placed_share,
            'ts_fee' => $order->ts_placed_fee,
            'ts_order' => $order,
        ];

        if ($journal['ts_date'] == '0000-00-00') {
            // dd("0000-00-00 decteced4", $order);
            Log::error($this->logtag."0000-00-00 detected-4", [$order->ts_txn_id]);
        }

        return $journal;
    }

    protected function buildPlaceJournal($order)
    {
        $journal = [
            'ts_txn_id' => $order->ts_txn_id,
            'ts_type' => 'P'.$order->ts_trade_type,
            'ts_date' => $order->ts_placed_date,
            'ts_time' => $order->ts_placed_time,
            'ts_amount' => $order->ts_placed_amount,
            'ts_share' => $order->ts_placed_share,
            'ts_fee' => $order->ts_placed_fee,
            'ts_order' => $order,
        ];

        if ($journal['ts_date'] == '0000-00-00') {
            // dd("0000-00-00 decteced3", $order);
            Log::error($this->logtag."0000-00-00 detected-3", [$order->ts_txn_id]);

        }

        return $journal;
    }

    protected function buildForeJournal($order)
    {
        if ($order->ts_trade_status == -2) {
            $amount = $order->ts_placed_amount;
            $share = $order->ts_placed_share;
        } else {
            if (in_array($order->ts_trade_type, [31,51])) {
                $amount = $order->ts_placed_amount;
                $share = $order->ts_placed_amount;
            } else {
                $amount = $order->ts_acked_amount;
                $share = $order->ts_acked_share;
            }
        }
        $journal = [
            'ts_txn_id' => $order->ts_txn_id,
            'ts_type' => 'F'.$order->ts_trade_type,
            'ts_date' => $order->ts_trade_date,
            'ts_time' => '15:00:00',
            'ts_amount' => $amount,
            'ts_share' => $share,
            'ts_fee' => $order->ts_acked_fee,
            'ts_order' => $order,
        ];

        if ($journal['ts_date'] == '0000-00-00') {
            // dd("0000-00-00 decteced2", $order);
            Log::error($this->logtag."0000-00-00 detected-2", [$order->ts_txn_id]);
        }

        return $journal;
    }

    protected function buildAckJournal($order)
    {
        if ($order->ts_trade_status == -2) {
            $amount = $order->ts_placed_amount;
            $share = $order->ts_placed_share;
        } else {
            $amount = $order->ts_acked_amount;
            $share = $order->ts_acked_share;
        }

        $journal = [
            'ts_txn_id' => $order->ts_txn_id,
            'ts_type' => 'K'.$order->ts_trade_type,
            'ts_date' => $order->ts_acked_date,
            'ts_time' => '15:00:00',
            'ts_amount' => $amount,
            'ts_share' => $share,
            'ts_fee' => $order->ts_acked_fee,
            'ts_order' => $order,
        ];

        if ($journal['ts_date'] == '0000-00-00') {
            // dd("0000-00-00 decteced1", $order);
            Log::error($this->logtag."0000-00-00 detected-1", [$order->ts_txn_id]);
        }

        return $journal;
    }

}
