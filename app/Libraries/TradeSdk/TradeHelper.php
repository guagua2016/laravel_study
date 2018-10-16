<?php
/**
 */

namespace App\Libraries\TradeSdk;

use App\BaseRaFundNav;
use App\FundInfos;
use App\FundFee;
use Log;

class TradeHelper 
{
    /****/
//2,2010/1/29,96001,0.0308
//2,2010/1/29,216,0.0803
    public $amount;//初始金额
    public $risk_day;//['风险'=>['调仓日期']]
    public $risk_position; //['风险--调仓日期'=>['基金'=>'比例']]
    public $fund_value;//基金净值
    public $fund_value_day;//基金净值日期
    public $fund_value_day_dict;//基金净值日期键
    public $holiding;//持仓['风险--调仓日期'=>['now'=>['fund_code'=>['基金'],'share'=>['份额'],'time'=>['时间']],
                                             //'later'=>['基金'=>['share'=>'份额','time'=>'时间','worth'=>'价值']],'cost'=>['基金'=>['purchase'=>'申购费','redemption'=>'赎回费']],'change'=>'宽口型换手率','amount'=>'总资产']]
    public $fund_company;//基金对应的公司和转入转出['基金'=>['company_id'=>'公司id','out'=>'转出 是1,否0','in'=>'转入 是1,否0']]
    public $fund_fee;//基金对应的费率['基金'=>['purchase'=>['min_value'=>['区间下限'],'fee'=>[['type'=>'费率单位: 1:百分比 2:元 3:份','value'=>'费率']]],
                                                //'redemption'=>['min_value'=>['区间下限'],'fee'=>[['type'=>'费率单位: 1:百分比 2:元 3:份','value'=>'费率']]]]
    public $cur_fund;//货币基金['fund_code'=>1],
    public $laste;//指针型计算

    public $fund_time;//申购赎回时间 ['purchase'=>'申购天数','redemption'=>'赎回天数']

    public function __construct($record,$amount=10000){
        ini_set('memory_limit', '1024M');
        $this->amount = $amount;
        $this->risk_day = [];
        $this->risk_position = [];
        $this->fund_value = [];
        $this->fund_company = [];
        $this->fund_fee = [];
        $this->cur_fund = [];
        $this->fund_time = [];
        $fund = [];
        foreach($record as $row){
            $row[1] = $this->initDay($row[1]);
            $risk_key = $row[0].'--'.$row[1]; //风险+调仓日期 作唯一键
            $row[2] = sprintf('%06d',$row[2]);
            $row[3] = trim($row[3]);
            if(isset($this->risk_day[$row[0]])){
                if(!in_array($row[1],$this->risk_day[$row[0]])){
                    $this->risk_day[$row[0]][] = $row[1];
                }
            }else{
                $this->risk_day[$row[0]] = [$row[1]];
            } 
            if(isset($this->risk_position[$risk_key])){
                $this->risk_position[$risk_key][$row[2]] = $row[3];
            }else{
                $this->risk_position[$risk_key] = [];
                $this->risk_position[$risk_key][$row[2]] = $row[3];
            }
            if(!isset($fund[$row[2]])){
                $fund[$row[2]] = $row[1]; 
            }elseif(strtotime($row[1])<strtotime($fund[$row[2]])){
                $fund[$row[2]] = $row[1]; 
            }
        }
        foreach($fund as $row=>$v){
            $fund_values = BaseRaFundNav::where('ra_code',$row)->where('ra_date','>=',$v)->orderBy('ra_date','ASC')->get();
            $this->fund_value[$row] = [];
            $this->fund_value_day[$row] = [];
            $this->fund_value_day_dict[$row] = [];
            $tmp_fund_value = &$this->fund_value[$row];
            $tmp_fund_value_day = &$this->fund_value_day[$row];
            $tmp_fund_value_day_dict = &$this->fund_value_day_dict[$row];
            foreach($fund_values as $value){
                $tmp_fund_value[$value['ra_date']] = $value['ra_nav_adjusted'];
                if($value['ra_mask'] == 0 || $value['ra_mask'] == 2){
                    $tmp_fund_value_day[] = $value['ra_date']; 
                    $tmp_fund_value_day_dict[$value['ra_date']] = 1; 
                }
            }
        }
        #$fund_infos = FundInfos::whereIn('fi_code',array_keys($fund))->get();
        $fund_infos = FundInfos::all();
        foreach($fund_infos as $fund_info){
            $fi_code = sprintf('%06d',$fund_info['fi_code']);
            if(mb_strpos($fund_info['fi_name'],'货币') !== false){
                $this->cur_fund[$fi_code] = 1;
            }
            if($fund_info['fi_yingmi_transfor_status'] === 0){
                $this->fund_company[$fi_code] = ['company_id'=>$fund_info['fi_company_id'],'in'=>true,'out'=>true,'name'=>$fund_info['fi_name']];
            }elseif($fund_info['fi_yingmi_transfor_status'] === 1){
                $this->fund_company[$fi_code] = ['company_id'=>$fund_info['fi_company_id'],'in'=>true,'out'=>false,'name'=>$fund_info['fi_name']];
            }elseif($fund_info['fi_yingmi_transfor_status'] === 2){
                $this->fund_company[$fi_code] = ['company_id'=>$fund_info['fi_company_id'],'in'=>false,'out'=>true,'name'=>$fund_info['fi_name']];
            }else{
                $this->fund_company[$fi_code] = ['company_id'=>$fund_info['fi_company_id'],'in'=>false,'out'=>false,'name'=>$fund_info['fi_name']];
            }
            $this->fund_time[$fi_code] = ['purchase'=>$fund_info['fi_yingmi_confirm_time'],'redemption'=>$fund_info['fi_yingmi_to_account_time']];
            $this->fund_fee[$fi_code] = ['purchase'=>['min_value'=>[],'fee'=>[]],'redemption'=>['min_value'=>[],'fee'=>[]]];
        }
        $fund_fees = FundFee::where('ff_type',5)->orderBy('ff_min_value','ASC')->get();
        foreach($fund_fees as $fee){
            $this->fund_fee[$fee['ff_code']]['purchase']['min_value'][] = $fee['ff_min_value'];
            $this->fund_fee[$fee['ff_code']]['purchase']['fee'][] = ['type'=>$fee['ff_fee_type'],'value'=>$fee['ff_fee']];
        }
        $fund_fees = FundFee::where('ff_type',6)->orderBy('ff_min_value','ASC')->get();
        foreach($fund_fees as $fee){
            $this->fund_fee[$fee['ff_code']]['redemption']['min_value'][] = $fee['ff_min_value'];
            $this->fund_fee[$fee['ff_code']]['redemption']['fee'][] = ['type'=>$fee['ff_fee_type'],'value'=>$fee['ff_fee']];
        }
    }

    public function initDay($day){
        if(strpos($day,'/')===false){
            return $day;
        }else{
            return date('Y-m-d',strtotime($day));
        }
    }

    //获取到账时间，type=0购买，type=1赎回
    public function getTime($fund_code,$type){
        if($type==0){
            return 0;
        }elseif($type==1){
            if(isset($this->fund_time[$fund_code])){
                if($this->fund_time[$fund_code]['redemption']){
                    return $this->fund_time[$fund_code]['redemption'];
                }
            }
            return 3;
        } 
    }

    //返回净值 type=0 自然日 type=1 交易日 自然日是当天最新净值，交易日是当天交易，如为节假日，往后最近一天的有效净值
    public function getFundNav($fund_code,$day,$type=0){
        if($type == 1){
            if(isset($this->fund_value_day_dict[$fund_code]) && isset($this->fund_value_day_dict[$fund_code][$day])){
                return $this->fund_value[$fund_code][$day]; 
            }else{
                if(isset($this->fund_value_day[$fund_code])){
                    foreach($this->fund_value_day[$fund_code] as $row){
                        if(strtotime($row) > strtotime($day)){
                            return $this->fund_value[$fund_code][$row]; 
                        }
                    }
                }
                $tmp = BaseRaFundNav::where('ra_code',$fund_code)->where('ra_date','<=',$day)->whereIn('ra_mask',[0,2])->orderBy('ra_date','DESC')->first();
                if($tmp)return $tmp['ra_nav_adjusted'];
                return 1;
            }
        }else{
            if(isset($this->fund_value[$fund_code][$day])){
                return $this->fund_value[$fund_code][$day];
            }else{
                $tmp = BaseRaFundNav::where('ra_code',$fund_code)->where('ra_date','<=',$day)->orderBy('ra_date','DESC')->first();
                if($tmp)return $tmp['ra_nav_adjusted'];
                return 1;
            }
        }
    }

    //交易成本 type=0 购买 type=1赎回
    public function getCost($fund_code,$time,$amount,$type=0){
        if(isset($this->cur_fund[$fund_code]))return 0;
        if($type == 0 ){
            $fee = 0.003;
            $cost = $fee*$amount;
            for($i = 0;$i<count($this->fund_fee[$fund_code]['purchase']['min_value']);$i++){
                if($this->fund_fee[$fund_code]['purchase']['min_value'][$i]<=$amount){
                    if($this->fund_fee[$fund_code]['purchase']['fee'][$i]['type']==1){
                        $fee = $this->fund_fee[$fund_code]['purchase']['fee'][$i]['value'] * 0.2;
                        if($fee>=0.003){
                            $cost = $fee*$amount;
                        }else{
                            $cost = 0.003*$amount;
                        }
                    }else{
                        $cost = $this->fund_fee[$fund_code]['purchase']['fee'][$i]['value'] ;
                    }
                }else{
                    return $cost;
                }
            }
            return $cost;
        }else{
            $fee = 0;
            $cost = 0;
            for($i = 0;$i<count($this->fund_fee[$fund_code]['redemption']['min_value']);$i++){
                if($this->fund_fee[$fund_code]['redemption']['min_value'][$i]<$time){
                    if($this->fund_fee[$fund_code]['redemption']['fee'][$i]['type']==1){
                        $fee = $this->fund_fee[$fund_code]['redemption']['fee'][$i]['value'];
                        $cost = $fee*$amount;
                    }else{
                        $cost = $this->fund_fee[$fund_code]['redemption']['fee'][$i]['value'] ;
                    }
                }else{
                    return $cost;
                }
            }
            return $cost;
        }
    }

    //先对比持仓和调仓结果，计算出要购买和赎回的部分
    public function getChange($holiding,$risk_position,$day){
        $funds = [];
        for($i=0;$i<count($holiding['now']['fund_code']);$i++){
            if(isset($funds[$holiding['now']['fund_code'][$i]])){
                $funds[$holiding['now']['fund_code'][$i]] += $holiding['now']['share'][$i];
            }else{
                $funds[$holiding['now']['fund_code'][$i]] = $holiding['now']['share'][$i];
            }
        }

        foreach($holiding['later'] as $fund_code=>$value){
            if(isset($funds[$fund_code])){
                $funds[$fund_code] += $value['share'];
            }else{
                $funds[$fund_code] = $value['share'];
            }
        }

        $tmp = [];
        $all_amount = 0;
        foreach($funds as $fund_code=>$share){
            $nav = $this->getFundNav($fund_code,$day);
            $tmp[$fund_code] = $nav * $share;
            $all_amount += $nav * $share;
        } 

        //获取新调仓中，需要完全剔除的和变化的
        $add = []; 
        $del = [];
        $risk_position_codes = array_keys($risk_position);
        foreach($tmp as $fund_code=>$amount){
            if(!in_array($fund_code,$risk_position_codes)){
                $del[$fund_code] = $amount;
            }else{
                if($amount>($risk_position[$fund_code] * $all_amount)){
                    $del[$fund_code] = $amount - ($risk_position[$fund_code] * $all_amount);
                }elseif($amount < ($risk_position[$fund_code] * $all_amount)){
                    $add[$fund_code] = ($risk_position[$fund_code] * $all_amount) - $amount;
                }
            } 
        } 
        
        //获取新调仓中，需要完全新增的
        $fund_codes = array_keys($funds);
        foreach($risk_position as $fund_code=>$ratio){
            if(!in_array($fund_code,$fund_codes)){
                $add[$fund_code] = $ratio * $all_amount;
            }
        }
        //获取换手率
        $change = (array_sum($add)+array_sum($del))/$all_amount;
        return ['add'=>$add,'del'=>$del,'amount'=>$all_amount,'change'=>$change];
    }

    //把未来的持仓移到已有持仓
    public function updateHoliding($holiding){
        $tmp = ['now'=>$holiding['now'],'later'=>[],'cost'=>[]];
        foreach($holiding['later'] as $fund_code=>$list){
            $tmp['now']['fund_code'][] = $fund_code; 
            $tmp['now']['share'][] = $list['share']; 
            $tmp['now']['time'][] = $list['time']; 
        } 
        return $tmp;
    }

    //清除为0的已有持仓
    public function cleanHoliding($holiding,$day){
        $tmp = ['now'=>['fund_code'=>[],'share'=>[],'time'=>[]],'later'=>$holiding['later'],'cost'=>$holiding['cost']];
        if(isset($holiding['now'])){
            for($i=0;$i<count($holiding['now']['fund_code']);$i++){
                if($holiding['now']['share'][$i] != 0){
                    $tmp['now']['fund_code'][] = $holiding['now']['fund_code'][$i]; 
                    $tmp['now']['share'][] = $holiding['now']['share'][$i]; 
                    $tmp['now']['time'][] = $holiding['now']['time'][$i]; 
                }
            }
        }
        $amount = $this->getRowNav($holiding,$day);
        $new_cost = [];
        if(isset($holiding['cost'])){
            foreach($holiding['cost'] as $fund_code=>$list){
                if($list['purchase'] !=0 || $list['redemption'] !=0){
                    $new_cost[$fund_code] = ['purchase'=>$list['purchase']/$amount,'redemption'=>$list['redemption']/$amount];
                } 
            } 
        }
        $tmp['cost'] = $new_cost;
        return $tmp;
    }

    public function daysbetweendates($date1, $date2){ 
        $date1 = strtotime($date1); 
        $date2 = strtotime($date2); 
        $days = ceil(abs($date1 - $date2)/86400); 
        return $days; 
    }

    //撮合交易,优先级概念:转入转出优先,持仓久的先卖出
    public function updateTrade($change,$old_holiding,$day){
        $holiding = $this->updateHoliding($old_holiding); 
        $add = $change['add'];
        $del = $change['del'];
        //获取最长赎回时间
        $del_time = 0;
        foreach($del as $del_code=>$del_amount){
            $tmp = $this->getTime($del_code,1);
            if($tmp>$del_time){
                $del_time = $tmp;
            }
        }
        //找到可转入转出
        foreach($change['add'] as $add_code=>$add_amount){
            foreach($change['del'] as $del_code=>$del_amount){
                if($add[$add_code]<=0)break;
                if($this->fund_company[$add_code]['company_id'] == $this->fund_company[$del_code]['company_id'] && $this->fund_company[$add_code]['in'] && $this->fund_company[$del_code]['out']){
                    //当转入金额大于转出金额
                    if($add[$add_code] >= $del[$del_code]){
                        $nav = $this->getFundNav($del_code,$day,0); 
                        $del_share = $del[$del_code]/$nav;
                        $del_cost = 0;
                        for($i=0;$i<count($holiding['now']['fund_code']);$i++){
                            if($holiding['now']['fund_code'][$i] == $del_code){
                                if($holiding['now']['share'][$i] >= $del_share){
                                    $cost = $this->getCost($del_code,$this->daysbetweendates($day,$holiding['now']['time'][$i]),$del_share*$nav,1);
                                    if(isset($holiding['cost'][$del_code])){
                                        $holiding['cost'][$del_code]['redemption'] += $cost ;
                                    }else{
                                        $holiding['cost'][$del_code] = ['purchase'=>0,'redemption'=>0];
                                        $holiding['cost'][$del_code]['redemption'] += $cost; 
                                    }
                                    $holiding['now']['share'][$i] -= $del_share;
                                    $del_cost += $cost;
                                    $del_share = 0;
                                    break;
                                }else{
                                    $cost = $this->getCost($del_code,$this->daysbetweendates($day,$holiding['now']['time'][$i]),$holiding['now']['share'][$i]*$nav,1);
                                    if(isset($holiding['cost'][$del_code])){
                                        $holiding['cost'][$del_code]['redemption'] += $cost; 
                                    }else{
                                        $holiding['cost'][$del_code] = ['purchase'=>0,'redemption'=>0];
                                        $holiding['cost'][$del_code]['redemption'] += $cost;
                                    }
                                    $del_share -= $holiding['now']['share'][$i];
                                    $del_cost += $cost;
                                    $holiding['now']['share'][$i] = 0;
                                }
                            } 
                        }
                        //单个赎回再单个购买
                        //$time = $this->getTime($del_code,1) + $this->getTime($add_code,0);
                        //等都赎回再统一购买
                        $time = $del_time + $this->getTime($add_code,0);
                        $new_day =  date('Y-m-d',strtotime($day.' '.$time.' day'));//获取申购净值日期
                        $add_nav = $this->getFundNav($add_code,$new_day,1);//获取申购确认的净值
                        if(isset($holiding['later'][$add_code])){
                            $holiding['later'][$add_code]['share'] += ($del[$del_code]-$del_cost)/$add_nav;  
                            $holiding['later'][$add_code]['time'] = $new_day; 
                            $holiding['later'][$add_code]['worth'] += ($del[$del_code]-$del_cost); 
                        }else{
                            $holiding['later'][$add_code] = [];
                            $holiding['later'][$add_code]['share'] = ($del[$del_code]-$del_cost)/$add_nav;  
                            $holiding['later'][$add_code]['time'] = $new_day; 
                            $holiding['later'][$add_code]['worth'] = ($del[$del_code]-$del_cost); 
                        }
                        $add[$add_code] = $add[$add_code] - $del[$del_code];
                        $del[$del_code] = 0;
                    }else{
                        //当转出金额大于转入金额
                        $nav = $this->getFundNav($del_code,$day,0); 
                        $del_share = $add[$add_code]/$nav;
                        $del_cost = 0; 
                        for($i=0;$i<count($holiding['now']['fund_code']);$i++){
                            if($holiding['now']['fund_code'][$i] == $del_code){
                                if($holiding['now']['share'][$i] >= $del_share){
                                    $cost = $this->getCost($del_code,$this->daysbetweendates($day,$holiding['now']['time'][$i]),$del_share*$nav,1);
                                    if(isset($holiding['cost'][$del_code])){
                                        $holiding['cost'][$del_code]['redemption'] += $cost; 
                                    }else{
                                        $holiding['cost'][$del_code] = ['purchase'=>0,'redemption'=>0];
                                        $holiding['cost'][$del_code]['redemption'] += $cost;
                                    }
                                    $holiding['now']['share'][$i] -= $del_share;
                                    $del_cost += $cost;
                                    $del_share = 0;
                                    break;
                                }else{
                                    $cost = $this->getCost($del_code,$this->daysbetweendates($day,$holiding['now']['time'][$i]),$holiding['now']['share'][$i]*$nav,1);
                                    if(isset($holiding['cost'][$del_code])){
                                        $holiding['cost'][$del_code]['redemption'] += $cost; 
                                    }else{
                                        $holiding['cost'][$del_code] = ['purchase'=>0,'redemption'=>0];
                                        $holiding['cost'][$del_code]['redemption'] += $cost;
                                    }
                                    $del_share -= $holiding['now']['share'][$i];
                                    $del_cost += $cost;
                                    $holiding['now']['share'][$i] = 0;
                                }
                            } 
                        }
                        //单个赎回再单个购买
                        //$time = $this->getTime($del_code,1) + $this->getTime($add_code,0);
                        //等都赎回再统一购买
                        $time = $del_time + $this->getTime($add_code,0);
                        $new_day =  date('Y-m-d',strtotime($day.' '.$time.' day'));//获取申购净值日期
                        $add_nav = $this->getFundNav($add_code,$new_day,1);//获取申购确认的净值
                        if(isset($holiding['later'][$add_code])){
                            $holiding['later'][$add_code]['share'] += ($add[$add_code]-$del_cost)/$add_nav;  
                            $holiding['later'][$add_code]['time'] = $new_day; 
                            $holiding['later'][$add_code]['worth'] += ($add[$add_code]-$del_cost); 
                        }else{
                            $holiding['later'][$add_code] = [];
                            $holiding['later'][$add_code]['share'] = ($add[$add_code]-$del_cost)/$add_nav;  
                            $holiding['later'][$add_code]['time'] = $new_day; 
                            $holiding['later'][$add_code]['worth'] = ($add[$add_code]-$del_cost); 
                        }
                        $del[$del_code] = $del[$del_code] - $add[$add_code];
                        $add[$add_code] = 0;
                    }
                }
            }
        }

        //处理剩余需删除的持仓
        foreach($del as $del_code=>$del_amount){
            $nav = $this->getFundNav($del_code,$day,0); 
            $del_share = $del[$del_code]/$nav;
            $del_cost = 0;
            for($i=0;$i<count($holiding['now']['fund_code']);$i++){
                if($holiding['now']['fund_code'][$i] == $del_code){
                    if($holiding['now']['share'][$i] >= $del_share){
                        $cost = $this->getCost($del_code,$this->daysbetweendates($day,$holiding['now']['time'][$i]),$del_share*$nav,1);
                        if(isset($holiding['cost'][$del_code])){
                            $holiding['cost'][$del_code]['redemption'] += $cost; 
                        }else{
                            $holiding['cost'][$del_code] = ['purchase'=>0,'redemption'=>0];
                            $holiding['cost'][$del_code]['redemption'] += $cost;
                        }
                        $holiding['now']['share'][$i] -= $del_share;
                        $del_cost += $cost;
                        $del_share = 0;
                        break;
                    }else{
                        $cost = $this->getCost($del_code,$this->daysbetweendates($day,$holiding['now']['time'][$i]),$holiding['now']['share'][$i]*$nav,1);
                        if(isset($holiding['cost'][$del_code])){
                            $holiding['cost'][$del_code]['redemption'] += $cost; 
                        }else{
                            $holiding['cost'][$del_code] = ['purchase'=>0,'redemption'=>0];
                            $holiding['cost'][$del_code]['redemption'] += $cost;
                        }
                        $del_share -= $holiding['now']['share'][$i];
                        $del_cost += $cost;
                        $holiding['now']['share'][$i] = 0;
                    }
                } 
            }
            foreach($add as $add_code=>&$add_amount){
                if($del_amount<=$add_amount){
                    //单个赎回再单个购买
                    //$time = $this->getTime($del_code,1) + $this->getTime($add_code,0);
                    //等都赎回再统一购买
                    $time = $del_time + $this->getTime($add_code,0);
                    $new_day =  date('Y-m-d',strtotime($day.' '.$time.' day'));//获取申购净值日期
                    $add_nav = $this->getFundNav($add_code,$new_day,1);//获取申购确认的净值
                    $cost = $this->getCost($add_code,0,$del_amount,0);
                    if(isset($holiding['later'][$add_code])){
                        $holiding['later'][$add_code]['share'] += ($del_amount-$cost)/$add_nav;  
                        $holiding['later'][$add_code]['time'] = $new_day; 
                        $holiding['later'][$add_code]['worth'] += ($del_amount-$cost); 
                    }else{
                        $holiding['later'][$add_code] = [];
                        $holiding['later'][$add_code]['share'] = ($del_amount-$cost)/$add_nav;  
                        $holiding['later'][$add_code]['time'] = $new_day; 
                        $holiding['later'][$add_code]['worth'] = ($del_amount-$cost); 
                    }
                    if(isset($holiding['cost'][$add_code])){
                        $holiding['cost'][$add_code]['purchase'] += $cost; 
                    }else{
                        $holiding['cost'][$add_code] = ['purchase'=>0,'redemption'=>0];
                        $holiding['cost'][$add_code]['purchase'] += $cost;
                    }
                    $add_amount -= $del_amount;
                    break;
                }else{
                    //单个赎回再单个购买
                    //$time = $this->getTime($del_code,1) + $this->getTime($add_code,0);
                    //等都赎回再统一购买
                    $time = $del_time + $this->getTime($add_code,0);
                    $new_day =  date('Y-m-d',strtotime($day.' '.$time.' day'));//获取申购净值日期
                    $add_nav = $this->getFundNav($add_code,$new_day,1);//获取申购确认的净值
                    $cost = $this->getCost($add_code,0,$add_amount,0);
                    if(isset($holiding['later'][$add_code])){
                        $holiding['later'][$add_code]['share'] += ($add_amount-$cost)/$add_nav;  
                        $holiding['later'][$add_code]['time'] = $new_day; 
                        $holiding['later'][$add_code]['worth'] += ($add_amount-$cost); 
                    }else{
                        $holiding['later'][$add_code] = [];
                        $holiding['later'][$add_code]['share'] = ($add_amount-$cost)/$add_nav;  
                        $holiding['later'][$add_code]['time'] = $new_day; 
                        $holiding['later'][$add_code]['worth'] = ($add_amount-$cost); 
                    }
                    if(isset($holiding['cost'][$add_code])){
                        $holiding['cost'][$add_code]['purchase'] += $cost; 
                    }else{
                        $holiding['cost'][$add_code] = ['purchase'=>0,'redemption'=>0];
                        $holiding['cost'][$add_code]['purchase'] += $cost;
                    }
                    $add_amount = 0;
                }
            }
        }
        //处理剩余新增的持仓
        foreach($add as $add_code=>$add_amount){
            //单个赎回再单个购买
            //$time = $this->getTime($add_code,0);
            //等都赎回再统一购买
            $time = $del_time + $this->getTime($add_code,0);
            $new_day =  date('Y-m-d',strtotime($day.' '.$time.' day'));//获取申购净值日期
            $add_nav = $this->getFundNav($add_code,$new_day,1);//获取申购确认的净值
            $cost = $this->getCost($add_code,0,$add_amount,0);
            if(isset($holiding['later'][$add_code])){
                $holiding['later'][$add_code]['share'] += ($add_amount-$cost)/$add_nav;  
                $holiding['later'][$add_code]['worth'] += ($add_amount-$cost); 
            }else{
                $holiding['later'][$add_code] = [];
                $holiding['later'][$add_code]['share'] = ($add_amount-$cost)/$add_nav;  
                $holiding['later'][$add_code]['time'] = $new_day; 
                $holiding['later'][$add_code]['worth'] = ($add_amount-$cost); 
            }
            if(isset($holiding['cost'][$add_code])){
                $holiding['cost'][$add_code]['purchase'] += $cost; 
            }else{
                $holiding['cost'][$add_code] = ['purchase'=>0,'redemption'=>0];
                $holiding['cost'][$add_code]['purchase'] += $cost;
            }
        }
        return $holiding; 
    }

    //获取持仓在某一天的净值 
    public function getRowNav($holiding,$day){ 
        $result = 0;
        if(isset($holiding['now'])){
            for($i=0;$i<count($holiding['now']['fund_code']);$i++){
                $nav = $this->getFundNav($holiding['now']['fund_code'][$i],$day,0);
                $result += $nav * $holiding['now']['share'][$i];
            }
        }
        foreach($holiding['later'] as $fund_code=>$list){
            if(strtotime($list['time'])>strtotime($day)){
                $result += $list['worth'];
            }else{
                $nav = $this->getFundNav($fund_code,$day,0);
                $result += $nav * $list['share'];
            }
        }
        return $result;
    }

    public function cal()
    {
        foreach($this->risk_day as $risk=>$days){
            $laste = [];
            foreach($days as $day){
                $risk_key = $risk.'--'.$day;//风险+调仓日期 作唯一键
                if($laste == []){
                    //第一次购买
                    $this->holiding[$risk_key] = ['now'=>[],'later'=>[],'cost'=>[]];
                    $pay_cost = 0;
                    foreach($this->risk_position[$risk_key] as $fund_code=>$ratio){
                        $time = $this->getTime($fund_code,0);//获取申购净值天数
                        $new_day =  date('Y-m-d',strtotime($day.' '.$time.' day'));//获取申购净值日期
                        $nav = $this->getFundNav($fund_code,$new_day,1);//获取申购确认的净值
                        $cost = $this->getCost($fund_code,0,$this->amount * $ratio,0);//获取申购手续费
                        $share = ($this->amount * $ratio - $cost)/$nav;//获取申购确认份额
                        $this->holiding[$risk_key]['later'][$fund_code] = ['share'=>$share,'time'=>$new_day,'worth'=>$this->amount - $cost];
                        $this->holiding[$risk_key]['cost'][$fund_code] = ['purchase'=>$cost/$this->amount ,'redemption'=>0];
                        $this->holiding[$risk_key]['now'] = ['fund_code'=>[] ,'share'=>[],'time'=>[]];
                        $pay_cost += $cost;
                    }
                    $this->holiding[$risk_key]['amount'] = $this->amount - $pay_cost;
                    $this->holiding[$risk_key]['change'] = 1;
                    $laste = $this->holiding[$risk_key];
                }else{
                    //调仓
                    //获取调仓增删变化
                    $change = $this->getChange($laste,$this->risk_position[$risk_key],$day);
                    $laste = $this->updateTrade($change,$laste,$day);
                    $laste = $this->cleanHoliding($laste,$day);
                    $laste['amount'] = $change['amount'];
                    $laste['change'] = $change['change'];
                    $this->holiding[$risk_key] = $laste;
                }    
            }
        } 
    }

    //获取持仓
    public function getRiskDay(){
        return $this->risk_day;
    }

    //模拟下一次交易
    public function nextCal($risk,$day,$isEnd=false)
    {
        $risk_key = $risk.'--'.$day;//风险+调仓日期 作唯一键
        $old_laste = $this->laste;
        if($this->laste == []){
            //第一次购买
            $this->holiding[$risk_key] = ['now'=>[],'later'=>[],'cost'=>[]];
            $pay_cost = 0;
            foreach($this->risk_position[$risk_key] as $fund_code=>$ratio){
                $time = $this->getTime($fund_code,0);//获取申购净值天数
                $new_day =  date('Y-m-d',strtotime($day.' '.$time.' day'));//获取申购净值日期
                $nav = $this->getFundNav($fund_code,$new_day,1);//获取申购确认的净值
                $cost = $this->getCost($fund_code,0,$this->amount * $ratio,0);//获取申购手续费
                $share = ($this->amount * $ratio - $cost)/$nav;//获取申购确认份额
                $this->holiding[$risk_key]['later'][$fund_code] = ['share'=>$share,'time'=>$new_day,'worth'=>$this->amount - $cost];
                $this->holiding[$risk_key]['cost'][$fund_code] = ['purchase'=>$cost/$this->amount ,'redemption'=>0];
                $this->holiding[$risk_key]['now'] = ['fund_code'=>[] ,'share'=>[],'time'=>[]];
                $pay_cost += $cost;
            }
            $this->holiding[$risk_key]['amount'] = $this->amount - $pay_cost;
            $this->holiding[$risk_key]['change'] = 1;
            $this->laste = $this->holiding[$risk_key];
        }else{
            //调仓
            //获取调仓增删变化
            $change = $this->getChange($this->laste,$this->risk_position[$risk_key],$day);
            $this->laste = $this->updateTrade($change,$this->laste,$day);
            $this->laste = $this->cleanHoliding($this->laste,$day);
            $this->laste['amount'] = $change['amount'];
            $this->laste['change'] = $change['change'];
            $this->holiding[$risk_key] = $this->laste;
        } 
        if($isEnd){
            $this->laste = [];
        }
        return ['holiding'=>$this->holiding[$risk_key],'old_holiding'=>$old_laste,'risk_key'=>$risk_key];
    }

    //回滚到上一次持仓
    public function rollBack($list){
        unset($this->holiding[$list['risk_key']]);
        $this->laste = $list['old_holiding'];
    }

    //修改某风险某天配置
    public function updatePosition($risk,$day,$position){
        $risk_key = $risk.'--'.$day;//风险+调仓日期 作唯一键
        $this->risk_position[$risk_key] = $position;
    }

    public function getRealNav(){
        $tmp = [];
        $end_day = date('Y-m-d');
        $day_list = [];
        foreach($this->risk_day as $risk=>$days){
            $laste = [];
            $tmp[$risk] = [];
            $day_list[$risk] = [];
            $time = $this->daysbetweendates($days[0],$end_day);
            for($i=0;$i<$time;$i++){
                $day = date('Y-m-d',strtotime($days[0].' '.$i.' day'));
                $tmp_risk_day = $risk.'--'.$day;
                if(isset($this->holiding[$tmp_risk_day])){
                    $laste = $this->holiding[$tmp_risk_day];
                }
                $day_list[$risk][] = $day;
                $tmp[$risk][$day] = $this->getRowNav($laste,$day);
            }
        } 
        return [$day_list,$tmp];
    }

    public function getRealHoliding($risk_key){
        $result = [];
        foreach($this->risk_day as $risk=>$days){
            if($risk_key == $risk){
                foreach($days as $day){ 
                    $risk_key = $risk.'--'.$day;//风险+调仓日期 作唯一键
                    $result[$day] = $this->holiding[$risk_key];
                }
            }
        }
        return $result;
    }
}
