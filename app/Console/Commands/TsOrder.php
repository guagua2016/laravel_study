<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Container\Container;
use App\TsOrder as TSOModel;
use App\TsOrderFund;
use Log;

class TsOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tt:tso';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'test ts_order model';

    protected $logtag;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->logtag = sprintf("[TEST:HUWM:TsOrder]");
    }

    public function TestTsOrderFund()
    {
        $txnIds=[
            '20160822B000001S',
        ];
        $model = TsOrderFund::whereIn('ts_portfolio_txn_id',$txnIds)
               ->get();
        print_r($model);print_r("\n");
                       
        $model = TsOrderFund::whereIn('ts_portfolio_txn_id',$txnIds)
               ->get()
               ->keyBy('ts_txn_id')
               ->toArray();
        print_r(gettype($model));print_r("\n");
        //->keyBy('ts_txn_id');
        //print_r($model);
    }

    public function TestUse()
    {
        function test( ){
            $word ='world';
            $func = function($para) use (&$word){
                echo $para." ".$word."\n";
            };
            $word = 'php';
            return $func;// hello php    
        }
            $func = test( );
        $func('Hello');
        return;
    }

     /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //$this->TestUse();
        //$this->TestTsOrderFund();
        $collect = collect([
            ['product' => 'desk','price' => 100],
            ['product' => 'chair', 'price' => 30],
        ]);
        //dd($collect->contains('product','desk'));
        // $filter = $collect->only('desk');
        //dd($filter->all());

        //TsOrderFund
        $ref = new ReflectionClass('TsOrder');
        $ins = $ref->newInstanceArgs();
        
        //$app = Container::getInstance();
        //$tsOrder = $app->make("TsOrder");
        //$container = new Container();
        //$container->singleton("TsOrderFund");
        //$objTsOrder = $container->make('TsOrderFund');
        //$objTsOrder->value = 'aaa';

        //dd($objTsOrder->value);
        
        return;

        
        $model = TSOModel::where('ts_origin',8)
               ->orderBy('id','ASC')
               ->orderBy('ts_txn_id','ASC')
               ->selectRaw('DISTINCT ts_uid, ts_txn_id, ts_portfolio_id');

        $rows = $model->get();
        $orders = $rows->groupBy('ts_uid')->transform(function ($v,$k) {
            return $v->groupBy('ts_txn_id');
        });

        foreach ($orders as $uid => $rows) {
            print_r("uid = $uid");
            print_r("\n");
        }
        
        // foreach ($orders as $uid => $rows) {
        //     foreach ($rows as $txnId => $v) {
        //         print_r("uid = $uid,txnId = $txnId");
        //         print_r("\n");
        //     }
        //     print_r("===========");
        //     print_r("\n");
        //}
        
    }
}
