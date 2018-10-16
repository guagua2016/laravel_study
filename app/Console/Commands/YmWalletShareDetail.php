<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\YingmiWalletShareDetail;
use App\Libraries\Progress;
use App\Libraries\ProgressAdvancer;
use Log;
use DB;

class YmWalletShareDetail extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tt:ymWSDetail {--id= : id of users.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test wallet share detail.';

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
        $uid = $this->option('id');

        $uids = YingmiWalletShareDetail::where('yw_uid',$uid)
              // ->lists('yw_uid','yw_account_id');
              ->lists('yw_account_id','yw_uid')
              ->toArray();
        //$uids = YingmiWalletShareDetail::groupBy('yw_uid');
        // $uids = DB::table('yingmi_wallet_share_details')
        //       //->lists('yw_uid');
        //       ->groupBy('yw_uid');
        // // ->get();
        

        // ->get() --> array
        // ->lists()   -->  array
        // model->lists()  --> collection
        // ->groupBy()  --> object
        $ymObj = new YingmiWalletShareDetail;
        $model = $ymObj->tsPayMethod();
        dd(gettype($model->get()));        
        dd($uids);
        dd(gettype($uids));  //get the type of uids
        //print_r(sizeof($uids));
        $uid_counts = [];
        $cnt_max = 0;
        $max_uid = 0;
        $progress = $this->output->createProgressBar(count($uid));
        foreach($uids as $uid) {
           // $arr_uid = (array)($uid);
            //$yw_uid = $arr_uid['yw_uid'];
            $yw_uid = $uid->yw_uid;
            if ($yw_uid) {
                $cnt = YingmiWalletShareDetail::where('yw_uid',$yw_uid)
                    ->count();
                if ($cnt_max < $cnt) {
                    $cnt_max = $cnt;  //fix the max uid
                    $max_uid = $yw_uid;
                }
                $uid_counts[$yw_uid] = $cnt;
            }
            $progress->advance(1);
        }
        $progress->finish();
        print_r("\n");
        print_r($max_uid."  ".$cnt_max);
        print_r("\n");

        //print_r($uids->first());

        /* $result = YingmiWalletShareDetail::where('yw_uid',$uid) */
        // ->select('yw_wallet_id','yw_pay_method')
        // ->lists('yw_pay_method','yw_wallet_id');

        // if($result) {
        // $result = $result->toArray();
        // }

        /* print_r($result); */
    }
}
