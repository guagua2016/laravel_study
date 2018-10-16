<?php namespace App\Libraries\TradeSdk\Strategy;
use App\BnRaPool;
use App\BnRaPoolFund;
use DB;
use Log;

class FundPoolHelper {
    //该类主要作用为根据调仓，赎回，购买加载可以操作的基金池
    //初始化传入type 0 适用于在线服务，1 适用于历史交易
    function __construct($load_type=0)
    {
        $this->load_type = $load_type; 
        $this->date = date('Y-m-d'); 
        $this->stay_fund = [2];//不购买的基金level,2级基金池
        $this->black_list = [];
        $this->pools = [];
        $this->fund_pool = [];
        $this->initPool();
        $this->initFundPool($load_type);
    }

    public static function test()
    {
    }

    //自定义日期挑选基金 
    function setDate($date)
    {
        $this->date = $date; 
    }

    //传入不购买的基金黑名单 ['000216','000100']
    function setBlackList($list){
        $this->black_list = $list; 
    }

    //获取可用基金池和所有基金类型，返回['pool_fund'=>['111010'=>['buy'=>['000216','000100'],'stay'=>['001882']]],'l2_fund_type'=>['000216'=>'111010'],'fund_type'=>['000216'=>'111010'],'global_fund_type'=>['000216'=>'111010']]
    //pool_fund为现有基金池，fund_type为现有基金池基金和对应的基金池id,l2_fund_type为二级基金池，global_fund_type 为全部基金(包含过去)和对应的基金池id
    function getFundPool()
    {
        $pool_fund = [];
        $tmp = [];
        $fund_type = [];
        $l2_fund_type = [];
        $global_fund_type = [];
        if($this->load_type == 0){
            foreach($this->pools as $pool=>$type){
                $funds = BnRaPoolFund::where('ra_pool_id',$pool)->where('ra_date',DB::raw("(SELECT max(ra_date) FROM `ra_pool_fund` where ra_pool_id={$pool} and ra_date<='{$this->date}')"))->orderBy('ra_jensen','DESC')->orderBy('ra_fund_code','DESC')->get();
                $pool_fund[$pool] = ['buy'=>[],'stay'=>[]];
                foreach($funds as $fund){
                    $fund_type[$fund['ra_fund_code']] = $pool;
                    $global_fund_type[$fund['ra_fund_code']] = $pool;
                    $tmp[$fund['ra_fund_code']] = $this->date;
                    if(in_array($fund['ra_fund_level'],$this->stay_fund)){
                        $pool_fund[$pool]['stay'][] = $fund['ra_fund_code']; 
                        $l2_fund_type[$fund['ra_fund_code']] = $pool;
                    }else{
                        if(in_array($fund['ra_fund_code'],$this->black_list)){
                            $pool_fund[$pool]['stay'][] = $fund['ra_fund_code']; 
                            $l2_fund_type[$fund['ra_fund_code']] = $pool;
                        }else{
                            $pool_fund[$pool]['buy'][] = $fund['ra_fund_code']; 
                        }
                    }
                }
            } 
            $bn = BnRaPoolFund::where('ra_date','<=',$this->date)->orderBy('ra_date','DESC')->get();
            foreach($bn as $fund){
                if(!isset($global_fund_type[$fund['ra_fund_code']])){
                    $global_fund_type[$fund['ra_fund_code']] = $fund['ra_pool_id'];
                    $tmp[$fund['ra_fund_code']] = $fund['ra_date'];
                }else{
                    if(strtotime($tmp[$fund['ra_fund_code']])<strtotime($fund['ra_date'])){
                        $global_fund_type[$fund['ra_fund_code']] = $fund['ra_pool_id'];
                        $tmp[$fund['ra_fund_code']] = $fund['ra_date'];
                    }
                }
            } 
        }else{
            foreach($this->pools as $pool=>$type){
                $pool_fund[$pool] = ['buy'=>[],'stay'=>[]];
                foreach($this->fund_pool[$pool] as $day=>$list){
                    if(strtotime($this->date) >= strtotime($day)){
                        if($pool_fund[$pool]['stay'] == [] && $pool_fund[$pool]['buy'] == []){
                            foreach($list as $row){
                                $fund_type[$row['code']] = $pool; 
                                $global_fund_type[$row['code']] = $pool; 
                                $tmp[$row['code']] = $day; 
                                if(in_array($row['level'],$this->stay_fund)){
                                    $pool_fund[$pool]['stay'][] = $row['code']; 
                                    $l2_fund_type[$row['code']] = $pool; 
                                }else{
                                    if(in_array($row['code'],$this->black_list)){
                                        $pool_fund[$pool]['stay'][] = $row['code']; 
                                        $l2_fund_type[$row['code']] = $pool; 
                                    }else{
                                        $pool_fund[$pool]['buy'][] = $row['code']; 
                                    }
                                }
                            }    
                        }else{
                            foreach($list as $row){
                                if(!isset($global_fund_type[$row['code']])){
                                    $global_fund_type[$row['code']] = $pool; 
                                    $tmp[$row['code']] = $day; 
                                }else{
                                    if(strtotime($tmp[$row['code']])<strtotime($day)){
                                        $global_fund_type[$row['code']] = $pool; 
                                        $tmp[$row['code']] = $day; 
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        return ['pool_fund'=>$pool_fund,'l2_fund_type'=>$l2_fund_type,'fund_type'=>$fund_type,'global_fund_type'=>$global_fund_type,'pool_type'=>$this->pools];
    }


    //加载资产池
    function initPool(){
        $bn_pool = BnRaPool::all();
        foreach($bn_pool as $pool){
            $result[$pool['globalid']] = $pool['ra_fund_type']; 
        }
        $this->pools = $result; 
    }

    //加载基金池
    function initFundPool($load_type){
        if($load_type == 0){
            return true;
        }
        $fund_pool = [];
        foreach($this->pools as $pool=>$type){
            $funds = BnRaPoolFund::where('ra_pool_id',$pool)->orderBy('ra_date','ASC')->orderBy('ra_jensen','DESC')->orderBy('ra_fund_code','DESC')->get();
            $fund_pool[$pool] = [];
            foreach($funds as $fund){
                if(isset($fund_pool[$pool][$fund['ra_date']])){
                    $fund_pool[$pool][$fund['ra_date']][] = ['code'=>sprintf('%06d',$fund['ra_fund_code']),'level'=>$fund['ra_fund_level']];
                }else{
                    $fund_pool[$pool][$fund['ra_date']] = [];
                    $fund_pool[$pool][$fund['ra_date']][] = ['code'=>sprintf('%06d',$fund['ra_fund_code']),'level'=>$fund['ra_fund_level']];
                }
            }
            krsort($fund_pool[$pool]);
        } 
        $this->fund_pool = $fund_pool;
    }
}
