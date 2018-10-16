<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\YingmiTradeStatus;
use Log;
use DB;

class YmTradeStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tt:ym
                            {--uid=1000000006 : uid}
   ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test yingmi trade status.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function test_transform()
    {
        $collection = collect([1,2,3,4,5]);
        print_r($collection->all());
        $collection->transform(function($item,$key){
            print_r($key);
            return $item * 2;
        });
        print_r($collection->all());
        return;
    }

    public function test_list()
    {
        $txnIds[] = [
            
        ];
        $uids = [
            '1000000520',
            '1000000414',
            //'1000000006',
            '1000112253',
        ];
        $orders = YingmiTradeStatus::whereIn('yt_uid',$uids)
                ->lists('yt_uid','yt_yingmi_order_id');

        $txnIds = $orders->keys();
        //print_r(gettype($txnIds));
        // print_r($txnIds->toArray());
        // print_r("\n");
        
        // print_r($orders->toArray());
        // print_r("\n");
        //qqqdd(($orders->toArray()));
        //$size = 30;
        //$arr_order = array_chunk($orders->toArray(), $size);
        //dd($arr_order);
        $chunks = $txnIds->chunk(30);
        foreach ($chunks as $chunk) {
            //dd($chunk);
            $str_orders = implode(",",$chunk->toArray());

            print_r($str_orders);
            print_r("\n");
        }
    }
    
    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // $uids = [0,1,2,3,4,5,6,7,8,9,10];
        // $chunks = array_chunk($uids,4);
        // foreach ($chunks as $chunk) {
        //     print_r($chunk);
        //     $strIds = implode(",", $chunk);
        //     print_r($strIds);
        //     print_r("\n");
        // }

        $this->test_list();
        return;

        //print_r("Hi this is test yingmi trade status.\n");
        $uid = $this->option('uid');
        //$txn = $this->option('txn'); 
        if ($uid) {
            //DB::enableQueryLog();
            $txns = YingmiTradeStatus::where('yt_uid',$uid)->get();
            //$txns = YingmiTradeStatus::where('yt_uid','1000000006')->first();
            //dd(DB::getQueryLog()); 
            //Log::info($txns);
            //dd($txns);
	    //
            $nSize = sizeof($txns);
            Log::info("size = $nSize\n");
        }
        //print_r("uid=$uid, txn=$txn");
    }
}
