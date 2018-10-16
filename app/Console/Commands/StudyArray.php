<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\TsOrder;
use \ReflectionClass;
use \ReflectionMethod;
use Log;
use Closure;

class StudyArray extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tt:array';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test array';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }
    public function test_array_map()
    {
        $a = array(1,2,3,4,5);
        $e = 10;
        $b = array_map(function ($n) use ($e){
            return $n + $e;
        },$a);

        print_r($b);
    }


    public function test_collect()
    {
        $collection = collect(['taylor','abigail',null])->map(function ($name) {
            return strtoupper($name);
        });
        return $collection;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        $log = __CLASS__.'@'.__FUNCTION__;
        print_r("This is $log\n\n\n");

        $arr = array('1','2','3');
        $sum = array_reduce($arr, function($result,$v){
            return $result+$v;
        });
        return $sum;

        //dd(array_diff($arr_all,$arr_trip));
        
        $result = [];
        $list = [
            [
                'type' => 12101,
                'code' => '000009',
                'amount' => 1000,
                'ratio' => 0.25,
            ],
            [
                'type' => 12102,
                'code' => '000010',
                'amount' => 1000,
                'ratio' => 0.25,
            ],
            [
                'type' => 13101,
                'code' => '000015',
                'amount' => 500,
                'ratio' => 0.125,
            ],
            [
                'type' => 13101,
                'code' => '000016',
                'amount' => 500,
                'ratio' => 0.125,
            ],
        ];

        // $this->test_array_map();
        //$collection = $this->test_collect();
        //$collection = collect([1,2,3]);
        //print_r($collection);
        // //$res_json = json_encode($list);
        // //print_r($res_json); 
        // //print_r($list); 
        // $cols = collect($list);
        // //print_r($cols->toJson());

        // //$cols = $cols->groupBy('type');
        // $cols = $cols->keyBy('type');

        // print_r($cols->toJson());
        // //return $cols;
        // //$types = $cols->keys();
    }
}

