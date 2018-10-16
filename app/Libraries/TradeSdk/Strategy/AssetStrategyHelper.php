<?php namespace App\Libraries\TradeSdk\Strategy;
use Log;
use App\Libraries\TradeSdk\Strategy\UserHelper;

class AssetStrategyHelper {
    public static $deviation = 0.2; //偏离度设定
    public static $mark = 0.9; //偏离度合格分数90分
    public static $add_mark = 0.91; //追加金额目标分数91分
    //该类主要作用为根据调仓，赎回，购买计算出资产变化的金额
    //初始化传入当前分类持仓 hold=['111010'=>10000,'111020'=>2000];key为资产池,value为资金
    function __construct($hold)
    {
        $this->position = []; 
        $this->hold = $hold;
    }

    function test()
    {
    }

    //传入目标比例 position = ['111010'=>0.2,'111020'=>0.8]; key为资产池，value为比例
    function setPosition($position)
    {
        if(abs(array_sum($position) - 1) > 0.00001){
            return false;
        }
        $this->position = $position; 
    }


    //计算偏离度 position1 现有  position2 目标
    public static function getDeviation($position1,$position2){    
        $tmp = 0;
        foreach($position2 as $k=>$v){
            if(isset($position1[$k])){
                if($position1[$k] < $v){
                    $tmp += $v - $position1[$k]; 
                }
            }else{
                $tmp += $v;
            }
        }
        return $tmp;
    }

    public static function checkPositionDeviation($position1,$position2){
        $tmp = self::getDeviation($position1,$position2);
        if(self::$deviation >= $tmp){
            $status = false;
        }else{
            $status = true;
        }
        $tmp = $tmp/self::$deviation * (1-self::$mark);
        return $status;
    }

    public static function getAbleAmount($hold,$position,$amount){
        $hold['merge']['cur'] = $amount; 
        $new_hold = [];
        $sum = array_sum($hold['merge']);
        foreach($hold['merge'] as $k=>$v){
            $new_hold[$k] = $v/$sum;
        }
        $change = self::deviation($new_hold,$position);
        $able = 0;
        foreach($change as $k=>$v){
            if($k!='cur' && $v<0){
                $able += abs($v);
            }
        } 
        return $able;
    }

    public static function checkDeviation($holding,$fund_list,$position){
        $hold = UserHelper::mergeHolding($holding,$fund_list);
        if(array_sum($hold['merge'])<100){
            return ['status'=>false,'fund'=>[],'percent'=>1,'add'=>0];
        }
        $tmp = self::getDeviation($hold['change'],$position);
        //echo ' 调仓比例:'.$tmp." 总资金:".array_sum($hold['merge'])."\n"; 
        $add = 0;
        if(self::$deviation >= $tmp){
            $status = false;
        }else{
            $status = true;
            $add_deviation = ((1-self::$add_mark)/(1-self::$mark))*self::$deviation;
            $sum = array_sum($hold['merge']);
            if($sum>1000 && self::getAbleAmount($hold,$position,2000)<=$add_deviation){
                $add = 2000;
            }elseif($sum>1000 && self::getAbleAmount($hold,$position,$sum*2)<=$add_deviation){
                $min = 2000;
                $max = $sum*2;
                $add = 0;
                while($min<=$max){
                    $mid = ($min+$max)/2;
                    $re = self::getAbleAmount($hold,$position,$mid);
                    if($re <= $add_deviation && $re >= ($add_deviation-0.01)){
                        $add = ceil($mid);
                        break;
                    }elseif($re>$add_deviation){
                        $min = $mid+1;
                    }else{
                        $max = $mid-1;
                    }
                }
            }
        }
        $tmp = $tmp/self::$deviation * (1-self::$mark);
        if($tmp<0.0001)$tmp=0;
        return ['status'=>$status,'fund'=>false,'percent'=>$tmp,'add'=>$add];
    }


    public static function getPercent($hold,$position,$risk){
        $old_position = [];
        $high = [];
        $low = [];
        $cur = [];
        $high_low  = ['high'=>0,'low'=>0,'mid'=>0,'money'=>0];
        $high_position = [];
        $low_position = [];
        $cur_position = [];
        $high_low_position  = ['high'=>0,'low'=>0,'mid'=>0,'money'=>0];
        $low_id = ['121010','121020'];
        $cur_id = ['131010'];
        foreach($hold['change'] as $k=>$v){
            if(in_array($k,$low_id)){
                $low[$k] = $v;  
                $high_low['low'] += $v;  
            }elseif(in_array($k,$cur_id)){
                $cur[$k] = $v;  
                $high_low['money'] += $v;  
            }elseif($k == -1){ 
                $high_low['mid'] += $v;  
            }elseif($k == 'redeem' || $k == 'cur'){ 
                continue;
            }else{
                $high[$k] = $v;  
                $high_low['high'] += $v;  
            }    
        }    
        if (array_sum($high_low) == 0) {
            return 0;
        }
        foreach($high as $k=>$v){
            $high_position[$k] = round($v/array_sum($high),4);
        }
        foreach($low as $k=>$v){
            $low_position[$k] = round($v/array_sum($low),4);
        }
        foreach($high_low as $k=>$v){
            $high_low_position[$k] = round($v/array_sum($high_low),4);
        }
        $high_ratio = 0;
        $low_ratio = 0;
        $cur_ratio = 0;
        foreach($position as $k=>$v){
            if(in_array($k,$low_id)){
                $low_ratio += $v;
            }
            if(in_array($k,$cur_id)){
                $cur_ratio += $v;
            }
        }
        $high_ratio = 1-$low_ratio-$cur_ratio;
        $high_low_ratio = ['high'=>$high_ratio,'low'=>$low_ratio,'money'=>$cur_ratio];
        $position_high = [];
        $position_low = [];
        $position_cur = [];
        foreach($position as $k=>$v){
            if(in_array($k,$low_id)){
                $position_low[$k] = $v/$low_ratio;
            }elseif(in_array($k,$cur_id)){
                $position_cur[$k] = $v/$cur_ratio;
            }else{
                $position_high[$k] = $v/$high_ratio;
            }
        }
        $high_tmp = self::getDeviation($high_position,$position_high);
        $low_tmp = self::getDeviation($low_position,$position_low);
        $high_low_tmp = self::getDeviation($high_low_position,$high_low_ratio);
        //if(isset($high_low_ratio['high']) && isset($high_low_ratio['low']) && ($high_low_ratio['low'] == 0 || $high_low_ratio['high'] ==0)){
        if($risk == 1 || $risk == 0){
            $high_param = 1;
            $low_param = 1;
        }else{
            $high_param = 1/2;
            $low_param = 1/2;
        }
        $high_percent = (1-$high_tmp/(self::$deviation*$high_param)*(1-self::$mark))*100;
        $low_percent = (1-$low_tmp/(self::$deviation*$low_param)*(1-self::$mark))*100;
        $high_low_percent = (1-$high_low_tmp/(self::$deviation*1/2)*(1-self::$mark))*100;
        if($high_percent<0)$high_percent=0;
        if($low_percent<0)$low_percent=0;
        if($high_low_percent<0)$high_low_percent=0;
        if($high_low_percent>0 && $high_percent>0 && $low_percent>0 ){
            if($high_ratio + $low_ratio != 0){
                $percent = 1/((($high_ratio/($high_ratio+$low_ratio))/$high_percent+($low_ratio/($high_ratio+$low_ratio))/$low_percent)*max($high_low_ratio)+(1-max($high_low_ratio))/$high_low_percent);
            }else{
                $percent = 1/(1/$high_low_percent);
            }
        }else{
            $percent = 0;
        }
        //echo "high_tmp:".$high_tmp." high:".$high_percent." high_ratio:".$high_ratio." low_tmp:".$low_tmp." low:".$low_percent." low_ratio:".$low_ratio." high_low_tmp:".$high_low_tmp." high_low:".$high_low_percent." high_low_ratio:".json_encode($high_low_ratio)." all:".$percent."\n";
        return $percent;
    }

    public static function getAblePercent($holding,$fund_list,$position,$amount,$risk){
        $hold = UserHelper::mergeHolding($holding,$fund_list,$amount);
        $change = self::deviation($hold['change'],$position);
       // ['12110'=>0.1,'14111'=>0.3,'-1'=>-0.2,'cur'=>-0.2]
        $add = [];
        foreach($change as $type=>$ratio){
            if($ratio>0){
                $add[$type] = $ratio;
            }
        } 
        $add = self::optimizeAsset(array_sum($hold['merge']),$add);
        $new_hold = $hold['change'];
        foreach($add as $type=>$ratio){
            if(isset($new_hold[$type])){
                $new_hold[$type] += abs($change['cur'])*($ratio/array_sum($add));
            }else{
                $new_hold[$type] = abs($change['cur'])*($ratio/array_sum($add));
            }
        }
        $new_hold['cur'] = 0;
        return self::getPercent(['change'=>$new_hold],$position,$risk);
    }

    public static function checkDeviationNew($holding,$fund_list,$position,$risk){
        $hold = UserHelper::mergeHolding($holding,$fund_list);
        if(array_sum($hold['merge'])<100){
            return ['status'=>false,'fund'=>[],'percent'=>1,'add'=>0];
        }
        $percent = self::getPercent($hold,$position,$risk);
        $add = 0;
        if($percent>=self::$mark*100){
            $status = false;
        }else{
            $status = true;
//            $add_deviation = ((1-self::$add_mark)/(1-self::$mark))*self::$deviation;
            $sum = array_sum($hold['merge']);
            if($sum>1000 && self::getAblePercent($holding,$fund_list,$position,2000,$risk)>=(self::$add_mark*100)){
                $add = 2000;
            }elseif($sum>1000 && self::getAblePercent($holding,$fund_list,$position,$sum*2,$risk)>=(self::$add_mark*100)){
                $min = 2000;
                $max = $sum*2;
                $add = 0;
                while($min<=$max){
                    $mid = ($min+$max)/2;
                    $re = self::getAblePercent($holding,$fund_list,$position,$mid,$risk);
                    if($re >= (self::$add_mark*100) && $re <= ((self::$add_mark*100)+1)){
                        $add = ceil($mid);
                        break;
                    }elseif($re<(self::$add_mark*100)){
                        $min = $mid+1;
                    }else{
                        $max = $mid-1;
                    }
                }
            }
        }
        if($percent>99.99){
            $tmp=0;
        }else{
            $tmp = (100-$percent)/100;
        }
        return ['status'=>$status,'fund'=>false,'percent'=>$tmp,'add'=>$add];
    }

    //工具类：计算分类比例转化值 position1 现有  position2 目标
    // case: 调仓['111010'=>0.2,'111020'=>0.8] 调整为['111010'=>0.5,'111020'=>0.5] ，返回['111010'=>0.3,'111020'=> -0.3] 
    // case: 购买,其中cur为购买的现金比例 ['111010'=>0.2,'111020'=>0.7,'cur'=>0.1] 调整为['111010'=>0.5,'111020'=>0.5] ，返回['111010'=>0.3,'111020'=> -0.2,'cur'=> -0.1] 
    //返回结果中正数为增加的，负数为给类型基金需要减少的
    public static function deviation($position1,$position2){
        $tmp = [];
        foreach($position1 as $k=>$v){
            if(isset($position2[$k])){
                $t = $position2[$k] - $v;
                if($t != 0){
                    $tmp[$k] = $t;
                }
            }else{
                $tmp[$k] = 0 - $v;
            }
        }
        foreach($position2 as $k=>$v){
            if(!isset($position1[$k])){
                $tmp[$k] = $v;
            }
        }
        return $tmp;
    }

    //优化配置
    public static function optimizeAsset($amount,$position,$old_position=false){
        $optimize_position = [];
        $add = [];
        $del = [];
        $tmp_add = [];
        $tmp_del = [];
        $change = 0;
        $limit = 0.005;
        $limit_amount = 10;
        foreach($position as $type=>$ratio){
            if($type!='yingmi' && abs($ratio)<$limit && abs($ratio)*$amount < $limit_amount){
                if($old_position && isset($old_position[$type]) && $ratio<0 && $ratio+$old_position[$type]==0){
                    if($ratio>0){
                        $add[(string)$type] = $ratio;
                        if(isset($old_position[$type]) && $ratio+$old_position[$type] != 0){
                            $tmp_add[(string)$type] = $ratio;
                        }
                    }else{
                        $del[(string)$type] = $ratio;
                        if(isset($old_position[$type]) && $ratio+$old_position[$type] != 0){
                            $tmp_del[(string)$type] = $ratio;
                        }
                    }
                }else{
                    $change += $ratio;
                }
            }else{
                if($ratio>0){
                    $add[(string)$type] = $ratio;
                    if(isset($old_position[$type]) && $ratio+$old_position[$type] != 0){
                        $tmp_add[(string)$type] = $ratio;
                    }
                }else{
                    $del[(string)$type] = $ratio;
                    if(isset($old_position[$type]) && $ratio+$old_position[$type] != 0){
                        $tmp_del[(string)$type] = $ratio;
                    }
                }
            } 
        }
        if($change!=0){
            $del_sum = array_sum($tmp_del);
            $add_sum = array_sum($tmp_add);
            if($change>0){
                if($del_sum!=0){
                    foreach($tmp_del as $type=>$ratio){
                        $del[$type] += ($ratio/$del_sum)*$change;
                    }
                }else{
                    foreach($tmp_add as $type=>$ratio){
                        $add[$type] += ($ratio/$add_sum)*$change;
                    } 
                }
            }else{
                if($add_sum!=0){
                    foreach($tmp_add as $type=>$ratio){
                        $add[$type] += ($ratio/$add_sum)*$change;
                    }
                }else{
                    foreach($tmp_del as $type=>$ratio){
                        $del[$type] += ($ratio/$del_sum)*$change;
                    }
                }
            }
        }
        $optimize_position = [];
        foreach($add as $type=>$ratio){
            $optimize_position[$type] = $ratio;
        }
        foreach($del as $type=>$ratio){
            $optimize_position[$type] = $ratio;
        }
        if(!$optimize_position){
            return $position;
        }
        return $optimize_position;
    }

    //直接按配置组合比例购买 
    function buyAsset()
    {
        $change = self::deviation(['cur'=>1],$this->position);
        $add = [];
        foreach($change as $type=>$ratio){
            if($ratio>0){
                $add[$type] = $ratio*$this->hold['merge']['cur']/array_sum($this->hold['merge']);
            }
        } 
        $position = $add;
        return $position; 
    }


    //按配置组合比例和当前持仓追加购买 
    function additionalAsset()
    {
        $change = self::deviation($this->hold['change'],$this->position);
       // ['12110'=>0.1,'14111'=>0.3,'-1'=>-0.2,'cur'=>-0.2]
        $add = [];
        foreach($change as $type=>$ratio){
            if($ratio>0){
                $add[$type] = $ratio;
            }
        } 
        $add = self::optimizeAsset(array_sum($this->hold['merge']),$add);
        $position = [];
        foreach($add as $type=>$ratio){
            $position[$type] = abs($change['cur'])*($ratio/array_sum($add));
        }
        return $position;
    }

    //赎回,用于非调仓过程中的赎回
    function redemptionAsset($redemption)
    {
        $change = [];
        if(abs(array_sum($this->hold['merge']))>0.0000001){
            foreach($this->hold['merge'] as $type=>$amount){
                $del_amount = 0;
                if(isset($this->hold['merge_yingmi'][$type])){
                    $del_amount = $this->hold['merge_yingmi'][$type];
                }
                $change[$type] = ($amount-$del_amount)/array_sum($this->hold['merge']);
            }
            $change['yingmi'] = array_sum($this->hold['merge_yingmi'])/(array_sum($this->hold['merge']));
            
        }
        $del = [];
        if(isset($change['yingmi']) && $change['yingmi']<=0.2){
            $redemption = round(($redemption-$change['yingmi']) * 1 / (1-$change['yingmi']),2);
        }
        foreach($change as $type=>$ratio){
            if($type == 'yingmi' && $ratio<=0.2){
                $del[$type] = 0 - $ratio; 
            }else{
                if($ratio<0.01){
                    $del[$type] = 0 - $ratio; 
                }else{
                    $del[$type] = 0 - $redemption * $ratio; 
                }
            }
        }
        return $del; 
    }

    //调仓
    function reallocationAsset(){
        $change = [];
        foreach($this->hold['merge'] as $type=>$amount){
            $del_amount = 0;
            if(isset($this->hold['merge_yingmi'][$type])){
                $del_amount = $this->hold['merge_yingmi'][$type];
            }
            $change[$type] = ($amount-$del_amount)/array_sum($this->hold['merge']);
        }
        $change['yingmi'] = array_sum($this->hold['merge_yingmi'])/(array_sum($this->hold['merge']));
        $new_change = self::deviation($change,$this->position);
        $position = self::optimizeAsset(array_sum($this->hold['merge']),$new_change,$change);
        return $position;
    } 

}
