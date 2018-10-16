<?php namespace App\Libraries\TradeSdk\Strategy;
use App\BnMarkowitz;
use DB;
use Log;

class AssetAllocationHelper {
    //该类主要作用为根据风险，日期获取资产配置
    //初始化传入type 0 适用于在线服务，1 适用于历史交易
    function __construct($load_type=0)
    {
        $this->date = date('Y-m-d');
        $this->load_type = $load_type;
        $this->asset_allocation = [];
        $this->map = [
                   '0.1'=>'800001',
                   '0.2'=>'800002',
                   '0.3'=>'800003',
                   '0.4'=>'800004',
                   '0.5'=>'800005',
                   '0.6'=>'800006',
                   '0.7'=>'800007',
                   '0.8'=>'800008',
                   '0.9'=>'800009',
                   '1.0'=>'800010',
                    ];
        $this->initAssetAllocation($load_type);
    }

    public static function test()
    {
    }

    //自定义日期获取配置
    function setDate($date)
    {
        $this->date = $date;
    }

    //自定义风险和组合id映射关系
    function setMap($map)
    {
        $this->map = $map;
        $this->initAssetAllocation($this->load_type);
    }

    //获取风险，type，
    function getAssetAllocation($risk)
    {
        // dd($risk);
        $risk = sprintf("%.1f", $risk);
        $alloc_id = $this->map[$risk];
        $position = [];
        if($this->load_type == 0){
            $allocation = BnMarkowitz::where('ra_alloc_id',$alloc_id)->where('ra_date',DB::raw("(SELECT max(ra_date) FROM `ra_allocation_markowitz` where ra_alloc_id={$alloc_id} and ra_date<='{$this->date}')"))->get();
            foreach($allocation as $row){
                $position[$row['ra_asset_id']] = $row['ra_ratio'];
            }
        }else{
            foreach($this->asset_allocation[$risk] as $day=>$row){
                if(strtotime($day) <= strtotime($this->date)){
                    foreach($row as $asset_id=>$ratio){
                        $position[$asset_id] = $ratio;
                    }
                    break;
                }
            }
        }
        return $position;
    }

    //获取日期
    function getAssetAllocationDay($risk)
    {
        // dd($risk);
        $risk = sprintf("%.1f", $risk);
        $alloc_id = $this->map[$risk];
        $position = [];
        if($this->load_type == 0){
            $allocation = BnMarkowitz::where('ra_alloc_id',$alloc_id)->where('ra_date',DB::raw("(SELECT max(ra_date) FROM `ra_allocation_markowitz` where ra_alloc_id={$alloc_id} and ra_date<='{$this->date}')"))->first();
            return $allocation['ra_date'];
        }else{
            foreach($this->asset_allocation[$risk] as $day=>$row){
                if(strtotime($day) <= strtotime($this->date)){
                    return $day;
                }
            }
        }
    }


    //加载资产配置
    function initAssetAllocation($load_type){
        if($load_type == 0){
            return true;
        }
        $asset_allocation = [];
        foreach($this->map as $risk=>$alloc_id){
            $allocation = BnMarkowitz::where('ra_alloc_id',$alloc_id)->orderBy('ra_date','DESC')->get();
            $asset_allocation[$risk] = [];
            foreach($allocation as $row){
                if(isset($asset_allocation[$risk][$row['ra_date']])){
                    $asset_allocation[$risk][$row['ra_date']][$row['ra_asset_id']] = $row['ra_ratio'];
                }else{
                    $asset_allocation[$risk][$row['ra_date']] = [];
                    $asset_allocation[$risk][$row['ra_date']][$row['ra_asset_id']] = $row['ra_ratio'];
                }
            }
            krsort($asset_allocation[$risk]);
        }
        $this->asset_allocation = $asset_allocation;
    }
}
