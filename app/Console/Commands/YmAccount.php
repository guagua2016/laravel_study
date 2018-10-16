<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\YingmiAccount;
use App\Libraries\TradeDate;

use DB;
use Log;
use Carbon\Carbon;


class YmAccount extends Command
{
    use TradeDate;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tt:ymacc';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    private $group = 5;
    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        $group = 3;
        //DB:enableQueryLog();
        $uids = YingmiAccount::whereRaw("ya_uid % $this->group = $group")
                                                       ->orderBy('created_at', 'asc')
                                                       ->lists('ya_uid');
        $baseDate = Carbon::today()->toDateString();
        print_r($baseDate);

        //$beforeDate = tradeDate(Carbon::today(),-1);
        //print_r($beforeDate);

        $arr[] = [1 => "one"];
        $arr[] = [2 => "two"];
        $arr[] = [3 => "three"];
        print_r($arr);
        //DB:getQueryLog();
        /* print_r($uids); */
        // print_r("\n");
        // print_r("size of result is:  ".count($uids));
        /* print_r("\n"); */
    }
}
