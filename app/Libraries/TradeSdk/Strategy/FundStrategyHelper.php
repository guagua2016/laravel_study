<?php namespace App\Libraries\TradeSdk\Strategy;
use App\Libraries\TradeSdk\Strategy\MatchmakingHelper;
use Log;

class FundStrategyHelper {

    //该类主要作用为根据资产策略的结果计算基金结果
    function __construct()
    {
        $this->holding = [];
        $this->hold = [];
        $this->fund_list = [];
        $this->without_list = [];
        $this->tag = [];
        $this->pay_method = [];
        $this->les7days = false;
        $this->les7daysList = array();
    }

    function test()
    {
    }

    //加载持仓
    function setHolding($holding)
    {
        $this->holding = $holding;
    }

    //加载合并的持仓
    function setHold($hold)
    {
        $this->hold = $hold;
    }

    //加载基金池
    function setFundList($fund_list)
    {
        $this->fund_list = $fund_list;
    }

    //加载基金标签
    function setTag($tag)
    {
        $this->tag = $tag;
    }


    //购买或追加时设定pay_method ['付款渠道1'=>金额,'付款渠道2'=>金额]
    function setPayMethod($pay_methods){
        $this->pay_method = $pay_methods;
    }

    //二次调仓，加载上一次调仓已经操作的基金清单，在新的调仓计划里，优先级排最后['added'=>[code1,code2],'deled'=>[code1,code2]]
    function setWithoutList($without_list){
        $this->without_list = $without_list;
    }

    function strategy($asset)
    {
        $strategy = $this->split($asset);
        return $strategy;
    }

    function split($asset)
    {
        $add = [];
        $del = [];
        foreach($asset as $type=>$ratio){
            if($ratio > 0){
                $add[$type] = $ratio;
            }else{
                $del[$type] = $ratio;
            }
        }
        $del_strategy = $this->del($del);
        $add_strategy = $this->add($add,$del_strategy);
        $clean_del_strategy = [];
        foreach($del_strategy as $code=>$row){
            if(!isset($row['flag'])){
                $clean_del_strategy[] = $row;
            }
        }
        $del_strategy = $clean_del_strategy;
        $result = [];
        $result['del'] = $del_strategy;
        $result['add'] = $add_strategy;
/**
        $del_amount = 0;
        foreach($del_strategy as $row){
            $del_amount += $row['amount'];
        }
        $add_amount = 0;
        foreach($add_strategy as $row){
            $add_amount += $row['amount'];
        }
**/
        return $result;
    }

    //根据添加的进行增加
    function add($add_src,$del_strategy)
    {
        $add = [];
        foreach($add_src as $k=>$v){
            $add[$k] = round($v * $this->hold['amount'],2);
        }
        if(isset($this->hold['merge']) && isset($this->hold['merge']['cur']) && $this->hold['merge']['cur'] !=0 && abs($this->hold['merge']['cur']-array_sum($add))>0.00001){
            foreach($add as $k=>$v){
                $add[$k] += $this->hold['merge']['cur'] - array_sum($add);
                break;
            }
        }
        $hold_num = UserHelper::getHoldNum($this->hold['amount']);
        $hold_limit = UserHelper::getHoldLimit($this->hold['amount']);
        $hold_ratio = UserHelper::getHoldRatio($this->hold['amount']);
        $pay_list = $this->pay_method;
        $black_list = [];
        $tmp_balance = 0;
        $balance = 0;
        if(isset($this->hold['merge']['cur']) && $this->hold['merge']['cur']>0){
            $balance = $this->hold['merge']['cur'];
        }else{
            $pay_list = [];
            foreach($del_strategy as $row){
                $black_list[$row['code']] = 1;
                if(isset($pay_list[$row['pay_method']])){
                    $pay_list[$row['pay_method']] += $row['amount'];
                }else{
                    $pay_list[$row['pay_method']] = $row['amount'];
                }
                $tmp_balance += $row['amount'];
            }
        }
        if($balance != 0){
            $change_balance = $balance - array_sum($add);
            foreach($add as $k=>$v){
                $add[$k] += $change_balance;
                break;
            }
        }else{
            $change_balance = $tmp_balance - array_sum($add);
            foreach($add as $k=>$v){
                $add[$k] += $change_balance;
                break;
            }
        }
        $type_num = [];
        $type_code = [];
        //统计类型个数，确定购买列表(已持有的或者新增)，每个pay_method下的金额去购买可购买的
    /** holding = [
                    'holding'=>[['code'=>'','share'=>'','amount'=>'','date'=>'','redeemable_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'buying'=>[['id'=>'','code'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'redeeming'=>[['id'=>,'code'=>'','share'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'bonusing'=>[['code'=>'','share'=>'','amount'=>'','record_date'=>'','dividend_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'yingmi'=>[['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>''],['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>'']],
                ]
    **/
        foreach($this->holding['holding'] as $row){
            $type = -1;
            if(isset($this->fund_list['fund_type'][$row['code']])){
                $type = $this->fund_list['fund_type'][$row['code']];
            }
            if(isset($type_num[$type])){
                if(!isset($type_code[$row['code']])){
                    $type_num[$type] += 1;
                    $type_code[$row['code']] = 1;
                }
            }else{
                if(!isset($type_code[$row['code']])){
                    $type_num[$type] = 1;
                    $type_code[$row['code']] = 1;
                }
            }
        }
        foreach($this->holding['buying'] as $row){
            $type = -1;
            if(isset($this->fund_list['fund_type'][$row['code']])){
                $type = $this->fund_list['fund_type'][$row['code']];
            }
            if(isset($type_num[$type])){
                if(!isset($type_code[$row['code']])){
                    $type_num[$type] += 1;
                    $type_code[$row['code']] = 1;
                }
            }else{
                if(!isset($type_code[$row['code']])){
                    $type_num[$type] = 1;
                    $type_code[$row['code']] = 1;
                }
            }
        }
        foreach($this->holding['bonusing'] as $row){
            $type = -1;
            if(isset($this->fund_list['fund_type'][$row['code']])){
                $type = $this->fund_list['fund_type'][$row['code']];
            }
            if(isset($type_num[$type])){
                if(!isset($type_code[$row['code']])){
                    $type_num[$type] += 1;
                    $type_code[$row['code']] = 1;
                }
            }else{
                if(!isset($type_code[$row['code']])){
                    $type_num[$type] = 1;
                    $type_code[$row['code']] = 1;
                }
            }
        }
    //获取可用基金池和所有基金类型，返回['pool_fund'=>['111010'=>['buy'=>['000216','000100'],'stay'=>['001882']]],'fund_type'=>['000216'=>'111010'],'global_fund_type'=>['000216'=>'111010']]
    //pool_fund为现有基金池，fund_type为现有基金池基金和对应的基金池id，global_fund_type 为全部基金(包含过去)和对应的基金池id
    /** holding = [
                    'holding'=>[['code'=>'','share'=>'','amount'=>'','date'=>'','redeemable_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'buying'=>[['id'=>'','code'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'redeeming'=>[['id'=>,'code'=>'','share'=>'','amount'=>'','date'=>'','ack_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'bonusing'=>[['code'=>'','share'=>'','amount'=>'','record_date'=>'','dividend_date'=>'','pay_method'=>'','portfolio_id'=>'']],
                    'yingmi'=>[['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>''],['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1,'pay_method'=>'','portfolio_id'=>'']],
                ]
    **/
        $result = [];
        $result_code = [];
        foreach($pay_list as $pay_method=>$pay_amount){
            $type_list = [];
            foreach($add as $k=>$v){
                if($v<=0)continue;
                if($v >= $pay_amount){
                    $type_list[$k] = $pay_amount;
                    $add[$k] = $v-$pay_amount;
                    $pay_amount = 0;
                    break;
                }else{
                    $type_list[$k] = $v;
                    $pay_amount -= $v;
                    $add[$k] = 0;
                }
            }
            foreach($type_list as $k=>$v){
                $m = abs($v);
                if($m == 0)continue;
                $hold_buy_list = [];
                $no_hold_buy_list = [];
                $delay_buy_list = [];
                $without_buy_list = [];
                foreach($this->fund_list['pool_fund'][$k]['buy'] as $code){
                    if(isset($black_list[$code]))continue;
                    /**
                        1:个人首次申购最低金额
                        2:个人追加申购最低金额
                        3:个人最高申购金额
                        4:个人定投申购最低金额
                        5:个人定投申购最高金额
                        6:个人持有最低份额
                        7:个人赎回最低份额
                        8:个人转换最低份额
                        9:赎回时间序列
                        10:赎回手续费序列
                        21:公司id
                        22:起购起点
                        23:申购状态
                        24:转入转出状态
                        25:每日限购
                        26:申购时间序列
                   **/
                    if(isset($this->hold['hold_code_list'][$code])){
                        //已持有追加
                        if(isset($this->tag[$code]) && isset($this->tag[$code][2]) && $this->tag[$code][2] <= $hold_limit){
                            if($this->without_list && in_array($code,$this->without_list['deled'])){
                                $without_buy_list[] = $code;
                            }else{
                                if(!MatchmakingHelper::getTradeDate($code,0,1)){
                                    $delay_buy_list[] = $code;
                                }else{
                                    $hold_buy_list[] = $code;
                                }
                            }
                        }
                    }else{
                        //未持有首购
                        if(isset($this->tag[$code]) && isset($this->tag[$code][1]) && $this->tag[$code][1] <= $hold_limit){
                            if($this->without_list && in_array($code,$this->without_list['deled'])){
                                $without_buy_list[] = $code;
                            }else{
                                if(!MatchmakingHelper::getTradeDate($code,0,1)){
                                    $delay_buy_list[] = $code;
                                }else{
                                    $no_hold_buy_list[] = $code;
                                }
                            }
                        }
                    }
                }
                $code_sort = $this->hold['code_list']; 
                asort($code_sort);
                $tmp_hold_buy_list = [];
                foreach($code_sort as $s_code=>$s_v){
                    if(in_array($s_code,$hold_buy_list)){
                        $tmp_hold_buy_list[] = $s_code;
                    }
                }
                $hold_buy_list = $tmp_hold_buy_list;
                $num = $hold_num;
                if(($m/$hold_num)/($this->hold['amount']) < $hold_ratio){
                    for($n=$num;$n>=1;$n--){
                        if($n==1){
                            $num = $n;
                            break;
                        }
                        if(($m/$n)/($this->hold['amount']) >= $hold_ratio){
                            $num = $n;
                            break;
                        }
                    }
                }
                $buy_list = array_merge($hold_buy_list,$no_hold_buy_list,$delay_buy_list,$without_buy_list);
                $limit = round($m/$num,2);
                $new_buy_list = [];
                foreach($buy_list as $code){
                    //待处理，单日限额问题
//                    if(isset($this->tag[$code]) && isset($this->tag[$code][25]) && $this->tag[$code][25] ){
 //                       continue;
  //                  }
                    $start_limit = 1;
                    $end_limit = 10000000;
                    if(isset($this->hold['hold_code'][$pay_method]) && isset($this->hold['hold_code'][$pay_method][$code])){
                        //if(isset($this->tag[$code]) && isset($this->tag[$code][2]) && $this->tag[$code][2] <= $limit){
                        //    $start_limit = $this->tag[$code][2];
                        if(isset($this->tag[$code]) && isset($this->tag[$code][1]) && $this->tag[$code][1] <= $limit){
                            $start_limit = $this->tag[$code][1];
                        }else{
                            continue;
                        }
                    }else{
                        if(isset($this->tag[$code]) && isset($this->tag[$code][1]) && $this->tag[$code][1] <= $limit){
                            $start_limit = $this->tag[$code][1];
                        }else{
                            continue;
                        }
                    }
                    if(isset($this->tag[$code]) && isset($this->tag[$code][3]) ){
                        $end_limit = $this->tag[$code][3];
                    }
                    if($start_limit > $limit){
                        continue;
                    }
                    $had_sum = 0;
                    if(isset($this->hold['code_list'][$code])){
                        $had_sum = $this->hold['code_list'][$code];
                        if(isset($result_code[$code])){
                            $end_limit -= $result_code[$code];
                        }
                        $end_limit -= $had_sum;
                        if($end_limit < $start_limit){
                            continue;
                        }
                    }
                    $new_buy_list[$code] = ['code'=>$code,'start_limit'=>$start_limit,'end_limit'=>$end_limit,'balance'=>$end_limit];
                }
                while(true){
                    if(empty($new_buy_list)){
                        break;
                    }
                    $type_balance = 0;
                    foreach($new_buy_list as $code=>$row){
                        if($m<=0)break;
                        if($m<$limit)$limit = $m;
                        if($limit <= $row['balance']){
                            if($m<($hold_limit+ $limit)){
                                $limit = $m;
                                $m = 0;
                            }
                            $cost = UserHelper::getBuyFee($code,$limit,$this->tag);
                            $old_cost = UserHelper::getBuyFee($code,$limit,$this->tag,true);
                            $result[] = ['code'=>$code,'amount'=>$limit,'cost'=>$cost,'old_cost'=>$old_cost,'type'=>$k,'pay_method'=>$pay_method];
                            if(isset($result_code[$code])){
                                $result_code[$code] += $limit;
                            }else{
                                $result_code[$code] = $limit;
                            }
                            $m -= $limit;
                            $new_buy_list[$code]['balance'] -= $limit;
                        }else{
                            if($row['balance'] == 0)continue;
                            $cost = UserHelper::getBuyFee($code,$row['balance'],$this->tag);
                            $old_cost = UserHelper::getBuyFee($code,$row['balance'],$this->tag,true);
                            $result[] = ['code'=>$code,'amount'=>$row['balance'],'cost'=>$cost,'old_cost'=>$old_cost,'type'=>$k,'pay_method'=>$pay_method];
                            if(isset($result_code[$code])){
                                $result_code[$code] += $row['balance'];
                            }else{
                                $result_code[$code] = $row['balance'];
                            }
                            $m -= $row['balance'];
                            $new_buy_list[$code]['balance'] = 0;
                        }
                        $type_balance += $new_buy_list[$code]['balance'];
                    }
                    if($m<=0 || $type_balance<=0)break;
                }
            }
        }
        $new = [];
        $remain_amount = 0;
        $tmp_code = false;
        foreach($result as $row){
            $code = $row['code']."|".$row['pay_method'];
            $tmp_code = $code;
            $remain_amount += $row['amount'];
            if(isset($new[$code])){
                $new[$code]['amount'] += $row['amount'];
                $new[$code]['cost'] += $row['cost'];
                $new[$code]['old_cost'] += $row['old_cost'];
            }else{
                $new[$code] = ['code'=>$row['code'],'amount'=>$row['amount'],'cost'=>$row['cost'],'old_cost'=>$row['old_cost'],'type'=>$row['type'],'pay_method'=>$row['pay_method']];
            }
        }
        $total_amount = array_sum($pay_list);
        if($total_amount > $remain_amount && $total_amount - $remain_amount <100){
            if($tmp_code){
                $new[$tmp_code]['amount'] += $total_amount-$remain_amount; 
            } 
        }
        return array_values($new);
    }

    //根据移除的进行扣减
    function del($del_src)
    {
        $this->les7days = false;
        $this->les7daysList = array();
        $del = [];
        foreach($del_src as $k=>$v){
            $del[$k] = $v*($this->hold['amount']);
        }
        $mergeShare = $this->mergeShare();
        $re = [];
        $les7days_merge_share = [];
        if($this->les7daysList){
            foreach($mergeShare as $code=>$row){
                $les7days_merge_share[$code] = 0;
                foreach($row as $v){
                    $les7days_merge_share[$code] += $v['share'];
                }
            }
        }
        //单个基金
        foreach($del as $k=>$v){
            if($k == 'yingmi')continue;
            $m = abs($v);
            if($m == 0)continue;
            //持仓中的
            $hold_merge_share = [];
            foreach($mergeShare as $code=>$row){
                $fund_code = explode('|',$code);
                $fund_code = $fund_code[0];
                if(isset($this->fund_list['fund_type'][$fund_code]) && $this->fund_list['fund_type'][$fund_code] != $k){
                    continue;
                }elseif(!isset($this->fund_list['fund_type'][$fund_code]) && $k != -1){
                    continue;
                }
                $hold_merge_share[$code] = 0;
                foreach($row as $chunk){
                    if($chunk['flag']==0){
                        if($chunk['amount']>=$m){
                            if(abs($chunk['amount'] - $m)<=1){
                                $re[] = ['code'=>$code,'amount'=>$chunk['amount'],'share'=>$chunk['share'],'cost'=>$chunk['cost'],'type'=>$k];
                                $hold_merge_share[$code] += $chunk['share'];
                            }else{
                                $re[] = ['code'=>$code,'amount'=>$m,'share'=>round($chunk['share']*$m/$chunk['amount'],2),'cost'=>$chunk['cost']*$m/$chunk['amount'],'type'=>$k];
                                $hold_merge_share[$code] += round($chunk['share']*$m/$chunk['amount'],2);
                            }
                            $m = 0;
                            break;
                        }else{
                            $re[] = ['code'=>$code,'amount'=>$chunk['amount'],'share'=>$chunk['share'],'cost'=>$chunk['cost'],'type'=>$k];
                            $hold_merge_share[$code] += $chunk['share'];
                            $m -= $chunk['amount'];
                        }
                    }else{
                        if($chunk['amount']>=$m){
                            $re[] = ['code'=>$code,'amount'=>$chunk['amount'],'share'=>$chunk['share'],'cost'=>$chunk['cost'],'type'=>$k];
                            $hold_merge_share[$code] += $chunk['share'];
                            $m = 0;
                            break;
                        }else{
                            $re[] = ['code'=>$code,'amount'=>$chunk['amount'],'share'=>$chunk['share'],'cost'=>$chunk['cost'],'type'=>$k];
                            $hold_merge_share[$code] += $chunk['share'];
                            $m -= $chunk['amount'];
                        }
                    }
                }
                if($m<=0){
                    break;
                }
            }
            if($this->les7days == false){
                foreach($hold_merge_share as $code=>$del_share){
                    if(isset($this->les7daysList[$code]) && $les7days_merge_share[$code] - $del_share - $this->les7daysList[$code]<0.00001){
                        $this->les7days = true;
                        break;
                    }
                }
            }
            //购买中的
            if($m>0){
                foreach($this->holding['buying'] as $row){
                    if($this->fund_list['fund_type'][$row['code']] != $k){
                        continue;
                    }
                    $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
                    if($row['amount']>=$m){
                        if(abs($row['amount'] - $m)<=1){
                            $re[] = ['code'=>$code,'amount'=>$row['amount'],'cost'=>0,'type'=>$k];
                        }else{
                            $re[] = ['code'=>$code,'amount'=>$m,'cost'=>0,'type'=>$k];
                        }
                        $m = 0;
                        break;
                    }else{
                        $re[] = ['code'=>$code,'amount'=>$row['amount'],'cost'=>0,'type'=>$k];
                        $m -= $row['amount'];
                    }
                }
            }
            //分红中的
            if($m>0){
                foreach($this->holding['bonusing'] as $row){
                    if($this->fund_list['fund_type'][$row['code']] != $k){
                        continue;
                    }
                    $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
                    if($row['amount']>=$m){
                        if(abs($row['amount'] - $m)<=1){
                            $re[] = ['code'=>$code,'amount'=>$row['amount'],'cost'=>0,'type'=>$k];
                        }else{
                            $re[] = ['code'=>$code,'amount'=>$m,'cost'=>0,'type'=>$k];
                        }
                        $m = 0;
                        break;
                    }else{
                        $re[] = ['code'=>$code,'amount'=>$row['amount'],'cost'=>0,'type'=>$k];
                        $m -= $row['amount'];
                    }
                }
            }
        }

        $redeem_re = [];
        foreach($this->holding['redeeming'] as $row){
            $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
            if(isset($this->fund_list['fund_type'][$row['code']])){
                $type = $this->fund_list['fund_type'][$row['code']];
            }else{
                $type = -1;
            }
            $redeem_re[] = ['code'=>$code,'amount'=>$row['amount'],'cost'=>0,'type'=>$type,'flag'=>1,'pay_method'=>$row['pay_method']];
        }

        //盈米组合赎回
        if(isset($del['yingmi']) && $del['yingmi']!=0){
            foreach($this->holding['yingmi'] as $row){
                foreach($row['list'] as $r){
                    $code = $r['code']."|".$row['pay_method']."|".$row['portfolio_id'];
                    $type = -1;
                    if(isset($this->fund_list['fund_type'][$r['code']])){
                        $type = $this->fund_list['fund_type'][$r['code']];
                    }
                    $re[] = ['code'=>$code,'amount'=>$r['amount'],'cost'=>0,'type'=>$type];
                }
            }
        }

        $result = [];
        foreach($re as $row){
            $fund_code = explode('|',$row['code']);
            $portfolio_id = $fund_code[2];
            $pay_method = $fund_code[1];
            $fund_code = $fund_code[0];
            if(isset($result[$row['code']])){
                $result[$row['code']]['amount'] += $row['amount'];
                $result[$row['code']]['cost'] += $row['cost'];
            }else{
                $result[$row['code']] = ['code'=>$fund_code,'amount'=>$row['amount'],'cost'=>$row['cost'],'type'=>$row['type'],'pay_method'=>$pay_method,'portfolio_id'=>$portfolio_id];
            }
        }
        $results = [];
        $results = array_merge(array_values($result),$redeem_re);
        return $results;
    }

    //赎回分块
    function mergeShare(){
        $this->les7daysList = array();
        $this->les7days = false;
        $holding = $this->holding;
        //货币基金列表
        $typeMoney = $this->getMoneyFunds();
        $fund_list = $this->fund_list;
        $tags = $this->tag;
        $list = [];
        $sum_list = [];
        $tmp_list = [];
        $cost_list = [];
        $amount_list = [];
        $day = date('Y-m-d');
        foreach($holding['holding'] as $row){
            $code = $row['code']."|".$row['pay_method']."|".$row['portfolio_id'];
            $fund_code = $row['code'];
            /**
            if($tags[$fund_code][23]!==0 && $tags[$fund_code][23] != 5){
                continue;
            }
            **/
            //这是什么意思
            $cost = 0.00001;
            $days = UserHelper::daysbetweendates($day,$row['date']);
            //标志是是否是7天内赎回
            $is7Days = False;
            if ($days < 7  && !in_array($fund_code, $typeMoney)){
                $is7Days = true;
                if(isset($this->les7daysList[$code])){
                    $this->les7daysList[$code] += $row['share'];
                }else{
                    $this->les7daysList[$code] = $row['share'];
                }
            }
            if(isset($tags[$fund_code]) && isset($tags[$fund_code][9])){
                for($i = 0; $i < count($tags[$fund_code][9]);$i++){
                    if($i!=count($tags[$fund_code][9])-1 && $tags[$fund_code][9][$i]>= $days){
                        $cost = $tags[$fund_code][10][$i];
                        break;
                    }else{
                        $cost = $tags[$fund_code][10][$i];
                    }
                }
                if ($is7Days && (float)$cost<0.015) {
                    $cost = "0.01500";
                }
                if(!isset($list[$code])){
                    $list[$code] = [];
                    $sum_list[$code] = [];
                    $amount_list[$code] = 0;
                }
                if(!isset($list[$code][$cost])){
                    $list[$code][$cost] = $row['share'];
                    $sum_list[$code][$cost] = $row['amount'];
                    $amount_list[$code] += $row['amount'];
                }else{
                    $list[$code][$cost] += $row['share'];
                    $sum_list[$code][$cost] += $row['amount'];
                    $amount_list[$code] += $row['amount'];
                }
            }else{
                if(!isset($list[$code])){
                    $list[$code] = [];
                    $sum_list[$code] = [];
                    $amount_list[$code] = 0;
                }
                $cost = 0;
                if(!isset($list[$code][$cost])){
                    $list[$code][$cost] = $row['share'];
                    $sum_list[$code][$cost] = $row['amount'];
                    $amount_list[$code] += $row['amount'];
                }else{
                    $list[$code][$cost] += $row['share'];
                    $sum_list[$code][$cost] += $row['amount'];
                    $amount_list[$code] += $row['amount'];
                }
            }
        }
        foreach($list as $code=>$row){
            $fund_code = explode('|',$code);
            $fund_code = $fund_code[0];
            ksort($row);
            if(isset($tags[$fund_code][6]) && isset($tags[$fund_code][7])){
                if($tags[$fund_code][6] + $tags[$fund_code][7] > array_sum($row)){
                    //单个只能全部赎回
                    $cost = 0;
                    foreach($sum_list[$code] as $fee=>$sum){
                        $cost += $fee*$sum;
                    }
                    $tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($sum_list[$code])]]; //这里
                    if(isset($cost_list[$code])){
                        $cost_list[$code] += $cost;
                    }else{
                        $cost_list[$code] = $cost;
                    }
                }else{
                    $tmp_list[$code] = [];
                    $arr_keys = array_keys($row);
                    $start = ['share'=>0,'amount'=>0,'flag'=>1,'cost'=>0,'fee'=>0];
                    $end = ['share'=>0,'amount'=>0,'flag'=>2,'cost'=>0,'fee'=>0];
                    //添加单个基金第一块(最小赎回)
                    while(true){
                        if(empty($arr_keys))break;
                        $k=array_shift($arr_keys);
                        $start_num = $tags[$fund_code][7] - $start['share'];
                        if($start_num > $row[$k]){
                            $start['share'] += $row[$k];
                            $start['amount'] += $sum_list[$code][$k];
                            $start['cost'] += $sum_list[$code][$k]*$k;
                            unset($sum_list[$code][$k]);
                            unset($row[$k]);
                        }else{
                            $start['share'] += $start_num;
                            $start['amount'] += $sum_list[$code][$k]*$start_num/$row[$k];
                            $start['cost'] += $sum_list[$code][$k]*$k*$start_num/$row[$k];
                            if($start_num == $row[$k]){
                                unset($sum_list[$code][$k]);
                                unset($row[$k]);
                            }else{
                                $sum_list[$code][$k] -= $sum_list[$code][$k]*$start_num/$row[$k];
                                $row[$k] -= $start_num;
                                array_unshift($arr_keys,$k);
                            }
                            break;
                        }
                    }
                    if($start['amount']!=0){
                        $start['fee'] = $start['cost']/$start['amount'];
                    }else{
                        $start['fee'] = 0;
                    }
                    //添加单个基金最后一块(最小持仓)
                    while(true){
                        if(empty($arr_keys))break;
                        $k=array_pop($arr_keys);
                        $end_num = $tags[$fund_code][6] - $end['share'];
                        if($end_num > $row[$k]){
                            $end['share'] += $row[$k];
                            $end['amount'] += $sum_list[$code][$k];
                            $end['cost'] += $sum_list[$code][$k]*$k;
                            unset($sum_list[$code][$k]);
                            unset($row[$k]);
                        }else{
                            $end['share'] += $end_num;
                            $end['amount'] += $sum_list[$code][$k]*$end_num/$row[$k];
                            $end['cost'] += $sum_list[$code][$k]*$k*$end_num/$row[$k];
                            if($end_num == $row[$k]){
                                unset($sum_list[$code][$k]);
                                unset($row[$k]);
                            }else{
                                $sum_list[$code][$k] -= $sum_list[$code][$k]*$end_num/$row[$k];
                                $row[$k] -= $end_num;
                                array_push($arr_keys,$k);
                            }
                            break;
                        }
                    }
                    if($end['amount']!=0){
                        $end['fee'] = $end['cost']/$end['amount'];
                    }else{
                        $end['fee'] = 0;
                    }
                    //插入首块
                    $tmp_list[$code][] = $start;
                    if(isset($cost_list[$code])){
                        $cost_list[$code] += $start['cost'];
                    }else{
                        $cost_list[$code] = $start['cost'];
                    }
                    //把剩余块插入
                    foreach($arr_keys as $k){
                        $mid = ['share'=>$row[$k],'amount'=>$sum_list[$code][$k],'flag'=>0,'cost'=>$sum_list[$code][$k]*$k,'fee'=>$k];
                        $tmp_list[$code][] = $mid;
                        if(isset($cost_list[$code])){
                            $cost_list[$code] += $mid['cost'];
                        }else{
                            $cost_list[$code] = $mid['cost'];
                        }
                    }
                    //插入末块
                    $tmp_list[$code][] = $end;
                    if(isset($cost_list[$code])){
                        $cost_list[$code] += $end['cost'];
                    }else{
                        $cost_list[$code] = $end['cost'];
                    }
                }
            }else{
                //单个只能全部赎回
                $cost = 0;
                foreach($sum_list[$code] as $fee=>$sum){
                    $cost += $fee*$sum;
                }
                $tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($sum_list[$code])]]; //这里
                if(isset($cost_list[$code])){
                    $cost_list[$code] += $cost;
                }else{
                    $cost_list[$code] = $cost;
                }
            }
        }
        $new_cost_list = [];
        foreach($cost_list as $code=>$row){
            $new_cost_list[$code] = [$row/$amount_list[$code],$row];
        }
//        asort($new_cost_list);
        uasort($new_cost_list, function($a, $b) {
            if ($a[0] == $b[0]){
                if($a[1] == $b[1]){
                    return 0;
                }else{
                    return ($a[1] < $b[1]) ? -1 : 1;
                }
            }
            return ($a[0] < $b[0]) ? -1 : 1;
        });
        $l2_chunk_list = [];
        $chunk_list = [];
        foreach($new_cost_list as $code=>$row){
            $fund_code = explode('|',$code);
            $fund_code = $fund_code[0];
            if(isset($fund_list['l2_fund_type'][$fund_code])){
                $l2_chunk_list[$code] = $tmp_list[$code];
            }else{
                $chunk_list[$code] = $tmp_list[$code];
            }
        }
        $result = [];
        $without_chunk_list = [];
        foreach($l2_chunk_list as $code=>$row){
            if($this->without_list && in_array($code,$this->without_list['added'])){
                $without_chunk_list[$code] = $row;
            }else{
                $result[$code] = $row;
            }
        }
        foreach($chunk_list as $code=>$row){
            if($this->without_list && in_array($code,$this->without_list['added'])){
                $without_chunk_list[$code] = $row;
            }else{
                $result[$code] = $row;
            }
        }
        foreach($without_chunk_list as $code=>$row){
            $result[$code] = $row;
        }
        return $result;
    }
    private function getMoneyFunds() {
        $result = array();
        if (empty($this->fund_list)) {
            return $result;
        }
        if (!isset($this->fund_list['global_fund_type'])) {
            return $result;
        }
        $glTypes = $this->fund_list['global_fund_type'];
        foreach ($glTypes as $k => $v) {
            if ($v == 131010) {
                $result [] = $k;
            }
        }
        return $result;
    }

}
