<?php
/**
 */

namespace App\Libraries\TradeSdk;

use App\BnRaPoolFund;
use App\BnRaPool;
use App\FundInfos;
use App\BaseFundStatus;
use App\FundFee;
use App\BnMarkowitz;
use App\YingmiPortfolioTradeStatus;
use App\MfPortfolioTradeStatus;
use App\MfFundTradeStatus;
use App\BaseDailyLimit;
use App\TsBlackList;
use Log;

class TradeStrategyHelper{
    public static $type_list = ['GLNC'=>42,'SP500.SPI'=>41,'oscillation'=>14,'HSCI.HI'=>43,'convertiblebond'=>23,
        'creditbond'=>22,'decline'=>15,'growth'=>16,'largecap'=>11,'ratebond'=>21,'rise'=>13,'smallcap'=>12,'value'=>17,'money'=>31];//复合资产类型(11:大盘;12:小盘;13:上涨;14:震荡;15:下跌;16:成长;17:价值;21:利率债;22:信用债;23:可转债;31:货币;41:标普;42:黄金;43:恒生)
    public static $b_type = ['92101'=>[11,12,13,14,15,16,17],'92201'=>[21,22],'92301'=>[31],'41'=>[41],'42'=>[42],'43'=>[43]];
    //分类序列基金可用性识别
    public static $risk_list = ['1'=>'800001','2'=>'800002','3'=>'800003','4'=>'800004','5'=>'800005','6'=>'800006','7'=>'800007','8'=>'800008','9'=>'800009','10'=>'800010',];
    public static $start_limit= 0.05;//单只基金比例下限
    public static $deviation = 0.2; //偏离度设定
    public static $mark = 0.9; //偏离度合格分数90分
    public static $chunk_limit=100;//单块100元下限;
    public static $chunk_max_limit=2000;//单块2000元上限;
    public static $fee_discount=0.2;//申购打折2折;
    public static $redemption_amount_limit=2000;//赎回后剩余金额不得低于该值;
    public static $able_pools = ['111010','111020']; //选用的基金池,如果同一天产生的基金池，同一只基金出现在大盘，价值中，取大盘标签，或者出现在小盘，成长中，取小盘
    
    public static function updateType($list,$tag=false){
        $tmp = [];
        foreach($list as $date=>$row){
            $t = [];
            foreach($row as $type=>$ratio){
                /**
                foreach(self::$b_type as $key=>$value){
                    if($tag==false){
                        $t_type = self::$type_list[$type];
                    }else{
                        $t_type = $type;
                    }
                    if(in_array($t_type,$value)){
                        $new_type = $key;
                        continue;
                    }
                }
                **/
                $new_type = $type;
                if(isset($t[$new_type])){
                    $t[$new_type] += $ratio;
                }else{
                    $t[$new_type] = $ratio;
                }
               // $t[self::$type_list[$type]] = $ratio;
            }
            $tmp[$date] = $t;
        }
        return $tmp;
    }


    public function __construct(){
    }


    //打入约束标签
    public static function setTag($codes,$day=false){
        $fee = FundFee::whereIn('ff_code',$codes)->orderBy('ff_min_value','asc')->get();
        $fund_info = [];
        $fund_old_info = [];
        $fund_old_info = BaseFundStatus::where('fs_date',$day)->whereIn('fs_fund_code',$codes)->get();
        if(!$fund_old_info && strtotime($day)<strtotime('2016-09-03')){
            $fund_old_info = BaseFundStatus::where('fs_date','2016-09-03')->whereIn('fs_fund_code',$codes)->get();
        }
        $fund_info = FundInfos::whereIn('fi_code',$codes)->get();
        $daily_limit= BaseDailyLimit::whereIn('fd_fund_code',$codes)->orderBy('fd_begin_date','ASC')->get();
        $tmp = [];
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
                    27:申购手续费序列
                    **/
        foreach($fund_info as $row){
            if(!isset($tmp[sprintf('%06d',$row['fi_code'])])){
                $tmp[sprintf('%06d',$row['fi_code'])] = [];
            } 
            $tmp[sprintf('%06d',$row['fi_code'])][21] = $row['fi_company_id'];
            //if(strtotime($day)<strtotime('2016-08-03')){
            //    $tmp[sprintf('%06d',$row['fi_code'])][22] = 1;
            //}else{
                $tmp[sprintf('%06d',$row['fi_code'])][22] = $row['fi_yingmi_amount'];
            //}
            $tmp[sprintf('%06d',$row['fi_code'])][23] = $row['fi_yingmi_subscribe_status'];
	   // if($row['fi_code'] == '161125'){
           //     $tmp[sprintf('%06d',$row['fi_code'])][23] = 4;
	   // }
            $tmp[sprintf('%06d',$row['fi_code'])][24] = $row['fi_yingmi_transfor_status'];
        }
        foreach($fund_old_info as $row){
            //if($day && strtotime($day)<strtotime('2016-08-03')){
            //    $tmp[sprintf('%06d',$row['fs_fund_code'])][23] = 0;
            //}else{
               // $tmp[sprintf('%06d',$row['fs_fund_code'])][23] = $row['fs_subscribe_status'];
            //}
       //     $tmp[sprintf('%06d',$row['fs_fund_code'])][24] = $row['fs_transfor_status'];
        }
        foreach($daily_limit as $row){
            if(!isset($tmp[sprintf('%06d',$row['fd_fund_code'])])){
                $tmp[sprintf('%06d',$row['fd_fund_code'])] = [];
                $tmp[sprintf('%06d',$row['fd_fund_code'])][25] = $row['fd_limit'];
            }else{
                $tmp[sprintf('%06d',$row['fd_fund_code'])][25] = $row['fd_limit'];
            }
        }
        foreach($fee as $row){
            if(!isset($tmp[$row['ff_code']])){
                $tmp[$row['ff_code']] = [];
            } 
            switch($row['ff_type']){
                case 10:
               //     if($day && strtotime($day)<strtotime('2016-08-03')){
               //         $tmp[$row['ff_code']][1] = 1;
               //     }else{
               //         $tmp[$row['ff_code']][1] = $row['ff_fee'];
               //     }
                    break; 
                case 11:
                //    if($day && strtotime($day)<strtotime('2016-08-03')){
                //        $tmp[$row['ff_code']][2] = 1;
                //    }else{
                        $tmp[$row['ff_code']][2] = $row['ff_fee'];
                 //   }
                    break; 
                case 12:
                    if(isset($tmp[$row['ff_code']][3])){
                        if($tmp[$row['ff_code']][3] > $row['ff_fee']){
                            $tmp[$row['ff_code']][3] = $row['ff_fee'];
                        }  
                    }else{
                        $tmp[$row['ff_code']][3] = $row['ff_fee'];
                    }
                    break; 
                case 18:
                    if(isset($tmp[$row['ff_code']][3])){
                        if($tmp[$row['ff_code']][3] > $row['ff_fee']){
                            $tmp[$row['ff_code']][3] = $row['ff_fee'];
                        }  
                    }else{
                        $tmp[$row['ff_code']][3] = $row['ff_fee'];
                    }
                    break; 
                case 13:
                    $tmp[$row['ff_code']][4] = $row['ff_fee'];
                    break; 
                case 14:
                    $tmp[$row['ff_code']][5] = $row['ff_fee'];
                    break; 
                case 15:
                    $tmp[$row['ff_code']][6] = $row['ff_fee'];
                    break; 
                case 16:
                    $tmp[$row['ff_code']][7] = $row['ff_fee'];
                    break; 
                case 17:
                    $tmp[$row['ff_code']][8] = $row['ff_fee'];
                    break; 
                case 6:
                    if(isset($tmp[$row['ff_code']][9])){
                        $tmp[$row['ff_code']][9][] = $row['ff_max_value'];
                    }else{
                        $tmp[$row['ff_code']][9] = [$row['ff_max_value']];
                    }
                    if(isset($tmp[$row['ff_code']][10])){
                        $tmp[$row['ff_code']][10][] = $row['ff_fee'];
                    }else{
                        $tmp[$row['ff_code']][10] = [$row['ff_fee']];
                    }
                    break; 
                case 5:
                    if(isset($tmp[$row['ff_code']][26])){
                        $tmp[$row['ff_code']][26][] = $row['ff_max_value'];
                    }else{
                        $tmp[$row['ff_code']][26] = [$row['ff_max_value']];
                    }
                    if(isset($tmp[$row['ff_code']][27])){
                        $tmp[$row['ff_code']][27][] = $row['ff_fee'];
                    }else{
                        $tmp[$row['ff_code']][27] = [$row['ff_fee']];
                    }
                    break; 
                default:
                    break; 
            }
        }
        return $tmp;
    }

    public static function tradeStrategy($id,$risk,$holding,$amount,$redemption,$op,$me,$position,$fund_list,$tags,$day){
        //return ['hold'=>$tmp,'hold_share'=>$tmp_share,'able'=>$able,'able_share'=>$able_share,'amount'=>$amount,'cash'=>$cash];
        //return ['list'=>$list,'sm_list'=>$sm_list,'funds'=>$funds];
        //
        //到这里了把持仓基金转换成类型
        $old_position = []; 
        if($op == 1 || $op == 3 || $op == 4){
            foreach($me['holding_type'] as $k=>$v){
                $old_position[$k] = round($v/($me['amount']+$amount),4);
            }
            $old_position['cur'] = round($amount/($me['amount']+$amount),4);
            $change = TradeStrategyHelper::deviation($old_position,$position);
            $new_change = [];
            $tmp = 0;
            foreach($change as $k=>$v){
                if($v<0){
                    $tmp += $v;  
                } 
            }
            $l = null;
            $m = 0;
            foreach($change as $k=>$v){
                if($v > 0){
                    $am = round(($change['cur']/$tmp)*$v*($amount+$me['amount']),0);
                    $new_change[$k] = $am; 
                    $l = $k;
                    $m += $am;
                }
            }
            $new_change[$l] += $amount-$m;
            return  TradeStrategyHelper::matchFund($new_change,$amount,$holding,$me,$fund_list,$tags,$op,$day);
        }elseif($op == 2){
            foreach($me['holding_type_redemption'] as $k=>$v){
                $old_position[$k] = round($v/$me['amount_redemption'],6);
            }
            /**
            $new_position = []; 
            $tmp = 0;
            foreach($position as $k=>$v){
                $t = round($v*(1-$redemption),4);
                $new_position[$k] = $t; 
                $tmp += $t;
            }
            $new_position['cur'] = 1 - $tmp;
            $change = TradeStrategyHelper::deviation($old_position,$new_position);
            $tmp = 0;
            foreach($change as $k=>$v){
                if($v>0){
                    $tmp += $v;  
                } 
            }
            foreach($change as $k=>$v){
                if($v < 0){
                    $am = round(($change['cur']/$tmp)*$v*$me['amount_redemption'],0);
                    $new_change[$k] = $am; 
                }
            }
            **/
            $tmp = [];
            if(array_sum($me['able'])!=0 && $me['yingmi_amount_able']/array_sum($me['able']) < 0.05){
                $yingmi_redemption = 1;
            }else{
                $yingmi_redemption = $redemption;
            }
            foreach($holding['yingmi'] as $key=>$row){
                if($row['tag'] == 1 && $row['lower'] <= $yingmi_redemption && $row['upper'] >= $yingmi_redemption){
                    $tmp[] = ['op'=>6,'id'=>$key,'redemption'=>$yingmi_redemption];
                }elseif($row['tag'] == 1 && $yingmi_redemption==1 ){
                    $tmp[] = ['op'=>6,'id'=>$key,'redemption'=>$yingmi_redemption];
                } 
            }
            $new_change = [];
            foreach($old_position as $k=>$v){
                $new_change[$k] = 0 - round($redemption*$v*$me['amount_redemption'],4);
            }
            $result = TradeStrategyHelper::matchFund($new_change,$amount,$holding,$me,$fund_list,$tags,$op,$day);
            return array_merge($tmp,$result);
        }elseif($op == 5){
            if($amount==0){
                foreach($me['holding_type'] as $k=>$v){
                    $old_position[$k] = $v/$me['amount'];
                }
                $change = TradeStrategyHelper::deviation($old_position,$position);
                $new_change = [];
                foreach($change as $k=>$v){
                    if($v < 0){
                        $am = $v*$me['amount'];
                        $new_change[$k] = $am; 
                    }
                }
                if(!$new_change){
                    foreach($position as $k=>$v){
                        $new_change[$k] = 0; 
                    }
                }
                $tmp = [];
                foreach($me['yingmi'] as $k=>$row){
                    if($row['tag'] != 1)continue;
                    $old_tmp = [];
                    $l = 0;
                    foreach($row['type'] as $key=>$value){
                        if(isset($new_change[$key]) && $new_change[$key]<0){
                            if(abs($new_change[$key]) >= $value){
                                $l = 1;
                                break;
                            }else{
                                $t_l = abs($new_change[$key])/$value;
                                if($t_l > $l){
                                    $l = $t_l;
                                }
                            }
                        }
                    }
                    if($l!=0){
                        $l = ceil($l*100)/100;
			//临时全赎回盈米
			$l = 1;
                        if($l>=$row['lower'] && $l<=$row['upper']){
                            $tmp[] = ['op'=>6,'id'=>$k,'redemption'=>$l];
                        }elseif($l<$row['lower']){
                            $l = $row['lower'];
                            $tmp[] = ['op'=>6,'id'=>$k,'redemption'=>$l];
                        }else{
                            $l = 1;
                            $tmp[] = ['op'=>6,'id'=>$k,'redemption'=>$l];
                        }
                        foreach($row['type'] as $key=>$value){
                            if(isset($new_change[$key]) && $new_change[$key] <0){
                                if(abs($new_change[$key]) >= $value*$l){
                                    $new_change[$key] += $value*$l;
                                }else{
                                    unset($new_change[$key]);                                }
                            }
                        }
                    }
                }
                $result = TradeStrategyHelper::matchFund($new_change,$amount,$holding,$me,$fund_list,$tags,$op,$day);
                return array_merge($tmp,$result);
            }else{
                foreach($me['holding_type'] as $k=>$v){
                    $old_position[$k] = round($v/($me['amount']+$amount),4);
                 //   $old_position[$k] = $v/($me['amount']+$amount);
                }
                $old_position['cur'] = round($amount/($me['amount']+$amount),4);
               // $old_position['cur'] = $amount/($me['amount']+$amount);
                if($old_position['cur'] == 0){
                    return [];
                }
                $change = TradeStrategyHelper::deviation($old_position,$position);
                $new_change = [];
                $tmp = 0;
                foreach($change as $k=>$v){
                    if($v<0){
                        $tmp += $v;  
                    } 
                }
                $l = null;
                $m = 0;
                foreach($change as $k=>$v){
                    if($v > 0){
                        $am = round(($change['cur']/$tmp)*$v*($amount+$me['amount']),0);
                       // $am = ($change['cur']/$tmp)*$v*($amount+$me['amount']);
                        $new_change[$k] = $am; 
                        $l = $k;
                        $m += $am;
                    }
                }
                $new_change[$l] += $amount-$m;
                return  TradeStrategyHelper::matchFund($new_change,$amount,$holding,$me,$fund_list,$tags,$op,$day);
            }    
        } 
    }

    public static function matchFund($change,$amount,$holding,$me,$fund_list,$tags,$op,$day){
        $tmp = [];
        if($op == 1 || $op == 3 || $op == 4 || ($op == 5 && $amount != 0)){
                /**
                    1:个人首次申购最低金额
                    2:个人追加申购最低金额
                    3:个人最高申购金额
                    4:个人定投申购最低金额
                    5:个人定投申购最高金额
                    6:个人持有最低份额 
                    7:个人赎回最低份额
                    8:个人转换最低份额
                    9:赎回时间
                    10:赎回最低手续费
                    21:公司id
                    22:起购起点
                    23:申购状态
                    24:转入转出状态
                    25:每日限额
                    **/
            $limit = round(($me['amount']+$amount)*TradeStrategyHelper::$start_limit,2);
            if($limit<TradeStrategyHelper::$chunk_limit){
                $limit = TradeStrategyHelper::$chunk_limit;
            }
            if($limit>TradeStrategyHelper::$chunk_max_limit){
                $limit = TradeStrategyHelper::$chunk_max_limit;
            }
            $result = [];
            $result_type = [];
            $all_out_sum = 0;
            $delay = [];
            foreach($change as $k=>$v){
                if($v<=$limit){
                    $chunk = 1; 
                }else{
                    $chunk = ceil($v/$limit);
                }
                $list = [];
                $own_list = [];
                $without_list = [];
            	$is_delay = false;
                if($chunk > 1){
                    foreach($fund_list['list'][$k] as $code){
                        if(isset($me['funds'][$code])){
                            if(!(isset($tags[$code]) && isset($tags[$code][23]) && $tags[$code][23]===0))continue;//申购状态
                            if(!(isset($tags[$code]) && isset($tags[$code][1]))){
                                if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$limit))continue;//起购起点
                            }else{
                                //最低追加金额
                                if($tags[$code][1]>$limit){
                                    continue;
                                }
                            }
                            $own_list[] = $code;
                        }else{
                            if(!(isset($tags[$code]) && isset($tags[$code][23]) && $tags[$code][23]===0))continue;//申购状态
                            if(!(isset($tags[$code]) && isset($tags[$code][1]) && $tags[$code][1]<=$limit)){
                                //最低申购金额
                                if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$limit))continue;//起购起点
                            }
                            $without_list[] = $code;
                        }
                    }
                }else{
                    foreach($fund_list['list'][$k] as $code){
                        if(isset($me['funds'][$code])){
                            if(!(isset($tags[$code]) && isset($tags[$code][23]) && $tags[$code][23]===0))continue;//申购状态
                            if(!(isset($tags[$code]) && isset($tags[$code][1]))){
                                if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$v))continue;//起购起点
                            }else{
                                //最低追加金额
                                if($tags[$code][1]>$v){
                                    continue;
                                }
                            }
                            $own_list[] = $code;
                        }else{
                            if(!(isset($tags[$code]) && isset($tags[$code][23]) && $tags[$code][23]===0))continue;//申购状态
                            if(!(isset($tags[$code]) && isset($tags[$code][1]) && $tags[$code][1]<=$v)){
                                //最低申购金额
                                if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$v))continue;//起购起点
                            }
                            $without_list[] = $code;
                        }
                    }
                }
		if(!array_merge($without_list,$own_list)){
			$is_delay = true;
			if($chunk > 1){
			    foreach($fund_list['list'][$k] as $code){
				if(isset($me['funds'][$code])){
				    if(!(isset($tags[$code]) && isset($tags[$code][1]))){
					if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$limit))continue;//起购起点
				    }else{
					//最低追加金额
					if($tags[$code][1]>$limit){
					    continue;
					}
				    }
				    $own_list[] = $code;
				}else{
				    if(!(isset($tags[$code]) && isset($tags[$code][1]) && $tags[$code][1]<=$limit)){
					//最低申购金额
					if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$limit))continue;//起购起点
				    }
				    $without_list[] = $code;
				}
			    }
			}else{
			    foreach($fund_list['list'][$k] as $code){
				if(isset($me['funds'][$code])){
				    if(!(isset($tags[$code]) && isset($tags[$code][1]))){
					if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$v))continue;//起购起点
				    }else{
					//最低追加金额
					if($tags[$code][1]>$v){
					    continue;
					}
				    }
				    $own_list[] = $code;
				}else{
				    if(!(isset($tags[$code]) && isset($tags[$code][1]) && $tags[$code][1]<=$v)){
					//最低申购金额
					if(!(isset($tags[$code]) && isset($tags[$code][22]) && $tags[$code][22]<=$v))continue;//起购起点
				    }
				    $without_list[] = $code;
				}
			    }
			}
		}
                $list = array_merge($without_list,$own_list);
                $tmp_list = array_merge($own_list,$without_list);
                if($list){
                    $type_list = [];
                    if($chunk==1){
                        foreach($tmp_list as $code){
                            $had_code = 0;
                            if(isset($result[$code])){
                                $had_code = $result[$code];
                            }else{
                                $had_code = 0;
                            }
                            if(isset($me['fund_hold'][$code])){
                                $fund_hold = $me['fund_hold'][$code];
                            }else{
                                $fund_hold = 0;
                            }
                            if(isset($me['fund_buying'][$code])){
                                $fund_buying = $me['fund_buying'][$code];
                            }else{
                                $fund_buying = 0;
                            }
                            if(isset($tags[$code]) && isset($tags[$code][3]) && $v+$fund_hold+$had_code > $tags[$code][3]){
                                continue;
                            }
                            if(isset($tags[$code]) && isset($tags[$code][25]) && $v+$fund_buying+$had_code> $tags[$code][25]){
                                continue;
                            }
                            $type_list[$code] = $v;
                            break;
                        }
                    }else{
                        if(count($list) > $chunk-1){
                            for($n=1;$n<$chunk;$n++){
                                $type_list[$list[$n-1]] = $limit; 
                            }
                            foreach($type_list as $code=>$sum){
                                $type_list[$code] += number_format(($v-$limit*($chunk-1))/count($type_list), 2, '.', '');
                            }
                        }else{
                            foreach($list as $code){
                                $type_list[$code] = number_format($limit*($chunk-1)/count($list),2,'.',''); 
                            }
                            foreach($type_list as $code=>$sum){
                                $type_list[$code] += number_format(($v-$limit*($chunk-1))/count($type_list),2,'.','');
                            }
                        }
                        $out_sum = 0;
                        $out_list = [];
                        foreach($type_list as $code=>$sum){
                            if(isset($result[$code])){
                                $had_code = $result[$code];
                            }else{
                                $had_code = 0;
                            }
                            if(isset($me['fund_hold'][$code])){
                                $fund_hold = $me['fund_hold'][$code];
                            }else{
                                $fund_hold = 0;
                            }
                            if(isset($me['fund_buying'][$code])){
                                $fund_buying = $me['fund_buying'][$code];
                            }else{
                                $fund_buying = 0;
                            }
                            $out = -1;
                            if(isset($tags[$code]) && isset($tags[$code][3]) && $sum+$fund_hold+$had_code > $tags[$code][3]){
                                $out = $tags[$code][3] - $fund_hold - $had_code;
				if($out<0)$out = 0;
                            }
                            if(isset($tags[$code]) && isset($tags[$code][25]) && $sum+$fund_buying+$had_code> $tags[$code][25]){
                                $out_daily = $tags[$code][25] - $fund_buying - $had_code;
                                if($out>$out_daily){
                                    $out = $out_daily;
                                }
                            }
                            if($out>0 && isset($tags[$code]) && ((isset($tags[$code][2]) && $tags[$code][2]>$out) || (isset($tags[$code][1]) && $tags[$code][1]>$out))){
                                $out = 0;
                            }

                            
                            if($out>=0){
                                $type_list[$code] = $out;
                                $out_sum += $sum-$out;
                                $out_list[] = $code;
                            }
                        }
                        if($out_list){
                            $count = count($type_list)-count($out_list);
                            foreach($type_list as $code=>$sum){
                                if(!in_array($code,$out_list)){
                                    $type_list[$code] += number_format($out_sum/$count,2,'.','');
                                }
                            }
                        }
                    }
                    foreach($type_list as $code=>$sum){
                        if($sum == 0)continue;
                        if(isset($result[$code])){
                            $result[$code] += $sum;
                            if($is_delay){
                                $delay[$code] = true;
                            }else{
                                $delay[$code] = false;
                            }
                        }else{
                            $result[$code] = $sum;
                            if($is_delay){
                                $delay[$code] = true;
                            }else{
                                $delay[$code] = false;
                            }
                            $result_type[$code] = $k;
                        }
                    }
                }else{
                    $all_out_sum += $v;
                }
            } 
            $results = [];
            $add_sum = 0;
            if($all_out_sum>0){
                if(!$result){
                    if($amount<=50){
                        foreach($holding['buying'] as $row){
                            if(!(isset($tags[$row['code']]) && isset($tags[$row['code']][22]) && $tags[$row['code']][22]<=$amount))continue;//起购起点
                            $results = [];
                	    $results[] = ['code'=>$row['code'],'amount'=>$amount,'cost'=>0,'pool'=>-1,'type'=>-1,'is_delay'=>false];
                            return $results;
                        }
			$codes = [];
			foreach($holding['redeeming'] as $row){
				$codes[$row['code']] = 1;
			}
                        foreach(array_reverse($holding['holding']) as $row){
                            if(isset($codes[$row['code']]))continue;
                            if(!(isset($tags[$row['code']]) && isset($tags[$row['code']][2]) && $tags[$row['code']][2]<=$amount))continue;//起购起点
                            $results = [];
                	    $results[] = ['code'=>$row['code'],'amount'=>$amount,'cost'=>0,'pool'=>-1,'type'=>-1,'is_delay'=>false];
                            return $results;
                        }
                    }
                    return [];
                }
                $add_sum = number_format($all_out_sum/count($result),2,'.','');
            }
            $n = count($result)-1;
            $all_sum = 0;
            foreach($result as $code=>$sum){
                if($n==0){
                    $add_sum = array_sum($change) - array_sum($result);
                    $add_sum = number_format($add_sum,2,'.','');
                }
                $sum += $add_sum;
                $all_sum += $sum;
                $result[$code] = $sum;
                $fee = 0;
                if(isset($tags[$code][26])){
                    for($i = 0; $i < count($tags[$code][26]);$i++){
                        if($i!=count($tags[$code][26])-1 && $tags[$code][26][$i]> $sum){
                            $fee = $tags[$code][27][$i]*TradeStrategyHelper::$fee_discount;
                            break;
                        }else{
                            $fee = $tags[$code][27][$i]*TradeStrategyHelper::$fee_discount;
                        }
                    }
                }
                
                if($fee >= 1){
                    $cost = $fee;
                }else{
                    if($fee>=0.003){
//                        $cost = $sum*$fee;
                        $cost = $sum-($sum/(1+$fee));
                    }else{
                        if(isset($tags[$code][26])){
//                            $cost = $sum*0.003;
                            $cost = $sum-($sum/(1+0.003));
                      //  $cost = $sum-($sum/(1+$fee));
                        }else{
                            $cost = 0;
                        }
                    }
                }
                $results[] = ['code'=>$code,'amount'=>$sum,'cost'=>$cost,'pool'=>$result_type[$code],'type'=>$fund_list['pool_type'][$result_type[$code]],'is_delay'=>$delay[$code]];
                $n--;
            }
            if($all_sum != $amount){
                if($results){
                    $change_sum = $amount - $all_sum;
                    $results[0]['amount'] += $change_sum;
                }
            }
            return $results;
        }elseif($op == 5 && $amount ==0){
            $list = [];
            $sum_list = [];
            $tmp_list = [];
            $other = [];
            foreach($holding['holding'] as $row){
                $code = $row['code'];
                if($tags[$code][23]!==0 && $tags[$code][23] != 5){
                    continue;
                } 
                $cost = 0.00001; 
                $days = TradeStrategyHelper::daysbetweendates($day,$row['date']);
                if(!isset($fund_list['funds'][$code])){
                    $other_cost = 0;
                    if(isset($tags[$code]) && isset($tags[$code][9])){
                        for($i = 0; $i < count($tags[$code][9]);$i++){
                            if($i!=count($tags[$code][9])-1 && $tags[$code][9][$i]>= $days){
                                $cost = $tags[$code][10][$i];
                                break;
                            }else{
                                $cost = $tags[$code][10][$i];
                            }
                        }
                        $other_cost = $row['amount']*$cost;
                    }
                    $type = -1;
                    if(isset($fund_list['all_funds'][$code])){
                        $type = $fund_list['all_funds'][$code];
                    }
                    $other[] = ['code'=>$code,'share'=> $row['share'],'cost'=>$other_cost,'type'=>$type];
                }elseif(isset($tags[$code]) && isset($tags[$code][9])){
                    for($i = 0; $i < count($tags[$code][9]);$i++){
                        if($i!=count($tags[$code][9])-1 && $tags[$code][9][$i]>= $days){
                            $cost = $tags[$code][10][$i];
                            break;
                        }else{
                            $cost = $tags[$code][10][$i];
                        }
                    }
                    if(!isset($list[$code])){
                        $list[$code] = [];
                        $sum_list[$code] = [];
                    }
                    if(!isset($list[$code][$cost])){
                        $list[$code][$cost] = $row['share'];
                        $sum_list[$code][$cost] = $row['amount'];
                    }else{
                        $list[$code][$cost] += $row['share'];
                        $sum_list[$code][$cost] += $row['amount'];
                    }
                }else{
                    if(!isset($list[$code])){
                        $list[$code] = [];
                        $sum_list[$code] = [];
                    }
                    $cost = 0;
                    if(!isset($list[$code][$cost])){
                        $list[$code][$cost] = $row['share'];
                        $sum_list[$code][$cost] = $row['amount'];
                    }else{
                        $list[$code][$cost] += $row['share'];
                        $sum_list[$code][$cost] += $row['amount'];
                    }
                }
            }
            foreach($list as $code=>$row){
                ksort($row);
                if(isset($tags[$code][6]) && isset($tags[$code][7])){
                    if($tags[$code][6] + $tags[$code][7] > array_sum($row)){
                        //单个只能全部赎回
                        $cost = 0;
                        foreach($sum_list[$code] as $fee=>$sum){
                            $cost += $fee*$sum;
                        }
                        if(abs(array_sum($sum_list[$code]))<0.000000001){
                            continue;
                        }
                        $tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($sum_list[$code])]]; //这里
                    }else{
                        $tmp_list[$code] = [];
                        $arr_keys = array_keys($row);
                        $start = ['share'=>0,'amount'=>0,'flag'=>1,'cost'=>0,'fee'=>0];
                        $end = ['share'=>0,'amount'=>0,'flag'=>2,'cost'=>0,'fee'=>0];
                        //添加单个基金第一块(最小赎回)
                        while(true){
                            $k=array_shift($arr_keys);
                            $start_num = $tags[$code][7] - $start['share'];
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
                            $k=array_pop($arr_keys);
                            $end_num = $tags[$code][6] - $end['share'];
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
                        //把剩余块插入
                        foreach($arr_keys as $k){
                            $mid = ['share'=>$row[$k],'amount'=>$sum_list[$code][$k],'flag'=>0,'cost'=>$sum_list[$code][$k]*$k,'fee'=>$k];
                            $tmp_list[$code][] = $mid;
                        }
                        //插入末块
                        $tmp_list[$code][] = $end;
                    }
                }else{
                    //单个只能全部赎回
                    $cost = 0;
                    foreach($sum_list[$code] as $fee=>$sum){
                        $cost += $fee*$sum;
                    }
                    $tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($sum_list[$code])]]; //这里
                }
            }
            $type_list = [];
            foreach($tmp_list as $code=>$arr){
                if(isset($fund_list['funds'][$code])){
                    if(isset($type_list[$fund_list['funds'][$code]])){
                        $type_list[$fund_list['funds'][$code]][] = $code;
                    }else{
                        $type_list[$fund_list['funds'][$code]] = [$code];
                    }
                }
            }
            $re = [];
            foreach($change as $k=>$v){
                $m = abs($v);
                while(true){
                    $tmp = 1; 
                    $code = null;
                    if(isset($type_list[$k])){
                        foreach($type_list[$k] as $co){
                            if(!$tmp_list[$co]){
                                continue;
                            }
                            if($tmp_list[$co][0]['fee']<$tmp){
                                $code = $co; 
                                $tmp = $tmp_list[$co][0]['fee']; 
                            } 
                        }
                    }
                    if($code){
                        if($tmp_list[$code][0]['flag']==0){
                            if($tmp_list[$code][0]['amount']>=$m){
                                if(abs($tmp_list[$code][0]['amount'] - $m)<=1){
                                    $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                    array_shift($tmp_list[$code]);
                                }else{
                                    $re[] = ['code'=>$code,'share'=>round($tmp_list[$code][0]['share']*$m/$tmp_list[$code][0]['amount'],2),'cost'=>$tmp_list[$code][0]['cost']*$m/$tmp_list[$code][0]['amount'],'type'=>$k];
                                    $tmp_list[$code][0]['share'] -= round($tmp_list[$code][0]['share']*$m/$tmp_list[$code][0]['amount'],2);
                                    $tmp_list[$code][0]['cost'] -= $tmp_list[$code][0]['cost']*$m/$tmp_list[$code][0]['amount'];
                                    $tmp_list[$code][0]['amount'] -= $m;
                                }
                                $m = 0;
                                break;
                            }else{
                                $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                $m -= $tmp_list[$code][0]['amount'];
                                array_shift($tmp_list[$code]);
                            }
                        }else{
                            if($tmp_list[$code][0]['amount']>=$m){
                                $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                array_shift($tmp_list[$code]);
                                $m = 0;
                                break;
                            }else{
                                $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                $m -= $tmp_list[$code][0]['amount'];
                                array_shift($tmp_list[$code]);
                            }
                        }
                    }else{
                        break;
                    }
                }
            }
            //合并数据 
            $result_code = []; 
            $result_cost = []; 
            $result_type = []; 
            foreach($other as $row){
                $result_type[$row['code']] = $row['type'];
                if(isset($result_code[$row['code']])){
                    $result_code[$row['code']] += $row['share'];
                    $result_cost[$row['code']] += $row['cost'];
                }else{
                    $result_code[$row['code']] = $row['share'];
                    $result_cost[$row['code']] = $row['cost'];
                }
            }
            foreach($re as $row){
                $result_type[$row['code']] = $row['type'];
                if(isset($result_code[$row['code']])){
                    $result_code[$row['code']] += $row['share'];
                    $result_cost[$row['code']] += $row['cost'];
                }else{
                    $result_code[$row['code']] = $row['share'];
                    $result_cost[$row['code']] = $row['cost'];
                }
            }
            $result = [];
            foreach($result_code as $code=>$share){
                if($share==0)continue;
                if(!isset($result_type[$code]))$result_type[$code] = -1;
                if(!isset($fund_list['pool_type'][$result_type[$code]]))$fund_list['pool_type'][$result_type[$code]] = -1;
                $result[] = ['code'=>$code,'share'=>$share,'cost'=>$result_cost[$code],'pool'=>$result_type[$code],'type'=>$fund_list['pool_type'][$result_type[$code]]];
            }
            return $result;
        }elseif($op == 2){
            $list = [];
            $sum_list = [];
            $tmp_list = [];
            $other_list = [];
            $other_sum_list = [];
            $other_tmp_list = [];
            foreach($holding['holding'] as $row){
                $code = $row['code'];
                if($tags[$code][23]!==0 && $tags[$code][23] != 5){
                    continue;
                } 
                $cost = 0.00001; 
                $days = TradeStrategyHelper::daysbetweendates($day,$row['date']);
                if(!isset($fund_list['funds'][$code])){
                    $other_cost = 0;
                    if(isset($tags[$code]) && isset($tags[$code][9])){
                        for($i = 0; $i < count($tags[$code][9]);$i++){
                            if($i!=count($tags[$code][9])-1 && $tags[$code][9][$i]>= $days){
                                $cost = $tags[$code][10][$i];
                                break;
                            }else{
                                $cost = $tags[$code][10][$i];
                            }
                        }
                        if(!isset($other_list[$code])){
                            $other_list[$code] = [];
                            $other_sum_list[$code] = [];
                        }
                        if(!isset($other_list[$code][$cost])){
                            $other_list[$code][$cost] = $row['share'];
                            $other_sum_list[$code][$cost] = $row['amount'];
                        }else{
                            $other_list[$code][$cost] += $row['share'];
                            $other_sum_list[$code][$cost] += $row['amount'];
                        }
                    }else{
                        if(!isset($other_list[$code])){
                            $other_list[$code] = [];
                            $other_sum_list[$code] = [];
                        }
                        $cost = 0;
                        if(!isset($other_list[$code][$cost])){
                            $other_list[$code][$cost] = $row['share'];
                            $other_sum_list[$code][$cost] = $row['amount'];
                        }else{
                            $other_list[$code][$cost] += $row['share'];
                            $other_sum_list[$code][$cost] += $row['amount'];
                        }
                    }
                }elseif(isset($tags[$code]) && isset($tags[$code][9])){
                    for($i = 0; $i < count($tags[$code][9]);$i++){
                        if($i!=count($tags[$code][9])-1 && $tags[$code][9][$i]>= $days){
                            $cost = $tags[$code][10][$i];
                            break;
                        }else{
                            $cost = $tags[$code][10][$i];
                        }
                    }
                    if(!isset($list[$code])){
                        $list[$code] = [];
                        $sum_list[$code] = [];
                    }
                    if(!isset($list[$code][$cost])){
                        $list[$code][$cost] = $row['share'];
                        $sum_list[$code][$cost] = $row['amount'];
                    }else{
                        $list[$code][$cost] += $row['share'];
                        $sum_list[$code][$cost] += $row['amount'];
                    }
                }else{
                    if(!isset($list[$code])){
                        $list[$code] = [];
                        $sum_list[$code] = [];
                    }
                    $cost = 0;
                    if(!isset($list[$code][$cost])){
                        $list[$code][$cost] = $row['share'];
                        $sum_list[$code][$cost] = $row['amount'];
                    }else{
                        $list[$code][$cost] += $row['share'];
                        $sum_list[$code][$cost] += $row['amount'];
                    }
                }
            }
            //处理不在基金池
            foreach($other_list as $code=>$row){
                ksort($row);
                if(isset($tags[$code][6]) && isset($tags[$code][7])){
                    if($tags[$code][6] + $tags[$code][7] > array_sum($row)){
                        //单个只能全部赎回
                        $cost = 0;
                        foreach($other_sum_list[$code] as $fee=>$sum){
                            $cost += $fee*$sum;
                        }
                        $other_tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($other_sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($other_sum_list[$code])]]; //这里
                    }else{
                        $other_tmp_list[$code] = [];
                        $arr_keys = array_keys($row);
                        $start = ['share'=>0,'amount'=>0,'flag'=>1,'cost'=>0,'fee'=>0];
                        $end = ['share'=>0,'amount'=>0,'flag'=>2,'cost'=>0,'fee'=>0];
                        //添加单个基金第一块(最小赎回)
                        while(true){
                            $k=array_shift($arr_keys);
                            $start_num = $tags[$code][7] - $start['share'];
                            if($start_num > $row[$k]){
                                $start['share'] += $row[$k];
                                $start['amount'] += $other_sum_list[$code][$k];
                                $start['cost'] += $other_sum_list[$code][$k]*$k;
                                unset($other_sum_list[$code][$k]);
                                unset($row[$k]);
                            }else{
                                $start['share'] += $start_num;
                                $start['amount'] += $other_sum_list[$code][$k]*$start_num/$row[$k];
                                $start['cost'] += $other_sum_list[$code][$k]*$k*$start_num/$row[$k];
                                if($start_num == $row[$k]){
                                    unset($other_sum_list[$code][$k]);
                                    unset($row[$k]);
                                }else{
                                    $other_sum_list[$code][$k] -= $other_sum_list[$code][$k]*$start_num/$row[$k];
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
                            $k=array_pop($arr_keys);
                            $end_num = $tags[$code][6] - $end['share'];
                            if($end_num > $row[$k]){
                                $end['share'] += $row[$k];
                                $end['amount'] += $other_sum_list[$code][$k];
                                $end['cost'] += $other_sum_list[$code][$k]*$k;
                                unset($other_sum_list[$code][$k]);
                                unset($row[$k]);
                            }else{
                                $end['share'] += $end_num;
                                $end['amount'] += $other_sum_list[$code][$k]*$end_num/$row[$k];
                                $end['cost'] += $other_sum_list[$code][$k]*$k*$end_num/$row[$k];
                                if($end_num == $row[$k]){
                                    unset($other_sum_list[$code][$k]);
                                    unset($row[$k]);
                                }else{
                                    $other_sum_list[$code][$k] -= $other_sum_list[$code][$k]*$end_num/$row[$k];
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
                        $other_tmp_list[$code][] = $start;
                        //把剩余块插入
                        foreach($arr_keys as $k){
                            $mid = ['share'=>$row[$k],'amount'=>$other_sum_list[$code][$k],'flag'=>0,'cost'=>$other_sum_list[$code][$k]*$k,'fee'=>$k];
                            $other_tmp_list[$code][] = $mid;
                        }
                        //插入末块
                        $other_tmp_list[$code][] = $end;
                    }
                }else{
                    //单个只能全部赎回
                    $cost = 0;
                    foreach($other_sum_list[$code] as $fee=>$sum){
                        $cost += $fee*$sum;
                    }
                    $other_tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($other_sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($other_sum_list[$code])]]; //这里
                }
            }
            //处理在基金池
            foreach($list as $code=>$row){
                ksort($row);
                if(isset($tags[$code][6]) && isset($tags[$code][7])){
                    if($tags[$code][6] + $tags[$code][7] > array_sum($row)){
                        //单个只能全部赎回
                        $cost = 0;
                        foreach($sum_list[$code] as $fee=>$sum){
                            $cost += $fee*$sum;
                        }
                        $tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($sum_list[$code])]]; //这里
                    }else{
                        $tmp_list[$code] = [];
                        $arr_keys = array_keys($row);
                        $start = ['share'=>0,'amount'=>0,'flag'=>1,'cost'=>0,'fee'=>0];
                        $end = ['share'=>0,'amount'=>0,'flag'=>2,'cost'=>0,'fee'=>0];
                        //添加单个基金第一块(最小赎回)
                        while(true){
                            $k=array_shift($arr_keys);
                            $start_num = $tags[$code][7] - $start['share'];
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
                            $k=array_pop($arr_keys);
                            $end_num = $tags[$code][6] - $end['share'];
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
                        //把剩余块插入
                        foreach($arr_keys as $k){
                            $mid = ['share'=>$row[$k],'amount'=>$sum_list[$code][$k],'flag'=>0,'cost'=>$sum_list[$code][$k]*$k,'fee'=>$k];
                            $tmp_list[$code][] = $mid;
                        }
                        //插入末块
                        $tmp_list[$code][] = $end;
                    }
                }else{
                    //单个只能全部赎回
                    $cost = 0;
                    foreach($sum_list[$code] as $fee=>$sum){
                        $cost += $fee*$sum;
                    }
                    $tmp_list[$code] = [['share'=>array_sum($row),'flag'=>1,'amount'=>array_sum($sum_list[$code]),'cost'=>$cost,'fee'=>$cost/array_sum($sum_list[$code])]]; //这里
                }
            }
            //处理不在基金池的持仓分类list
            $other_type_list = [];
            foreach($other_tmp_list as $code=>$arr){
                if(isset($fund_list['funds'][$code])){
                    if(isset($other_type_list[$fund_list['funds'][$code]])){
                        $other_type_list[$fund_list['funds'][$code]][] = $code;
                    }else{
                        $other_type_list[$fund_list['funds'][$code]] = [$code];
                    }
                }else{
                    if(isset($other_type_list[-1])){
                        $other_type_list[-1][] = $code;
                    }else{
                        $other_type_list[-1] = [$code];
                    }
                }
            }
            //处理在基金池的持仓分类list
            $type_list = [];
            foreach($tmp_list as $code=>$arr){
                if(isset($fund_list['funds'][$code])){
                    if(isset($type_list[$fund_list['funds'][$code]])){
                        $type_list[$fund_list['funds'][$code]][] = $code;
                    }else{
                        $type_list[$fund_list['funds'][$code]] = [$code];
                    }
                }
            }
            $re = [];
            //处理不在基金池
            foreach($change as $k=>$v){
                $m = abs($v);
                if($m == 0)continue;
                while(true){
                    $tmp = 1; 
                    $code = null;
                    if(isset($other_type_list[$k])){
                        foreach($other_type_list[$k] as $co){
                            if(!$other_tmp_list[$co]){
                                continue;
                            }
                            if($other_tmp_list[$co][0]['fee']<$tmp){
                                $code = $co; 
                                $tmp = $other_tmp_list[$co][0]['fee']; 
                            } 
                        }
                    }
                    if($code){
                        if($other_tmp_list[$code][0]['flag']==0){
                            if($other_tmp_list[$code][0]['amount']>=$m){
                                if(abs($other_tmp_list[$code][0]['amount'] - $m)<=1){
                                    $re[] = ['code'=>$code,'share'=>$other_tmp_list[$code][0]['share'],'cost'=>$other_tmp_list[$code][0]['cost'],'type'=>$k];
                                    array_shift($other_tmp_list[$code]);
                                }else{
                                    $re[] = ['code'=>$code,'share'=>round($other_tmp_list[$code][0]['share']*$m/$other_tmp_list[$code][0]['amount'],2),'cost'=>$other_tmp_list[$code][0]['cost']*$m/$other_tmp_list[$code][0]['amount'],'type'=>$k];
                                    $other_tmp_list[$code][0]['share'] -= round($other_tmp_list[$code][0]['share']*$m/$other_tmp_list[$code][0]['amount'],2);
                                    $other_tmp_list[$code][0]['cost'] -= $other_tmp_list[$code][0]['cost']*$m/$other_tmp_list[$code][0]['amount'];
                                    $other_tmp_list[$code][0]['amount'] -= $m;
                                }
                                $m = 0;
                                break;
                            }else{
                                $re[] = ['code'=>$code,'share'=>$other_tmp_list[$code][0]['share'],'cost'=>$other_tmp_list[$code][0]['cost'],'type'=>$k];
                                $m -= $other_tmp_list[$code][0]['amount'];
                                array_shift($other_tmp_list[$code]);
                            }
                        }else{
                            if($other_tmp_list[$code][0]['amount']>=$m){
                                $re[] = ['code'=>$code,'share'=>$other_tmp_list[$code][0]['share'],'cost'=>$other_tmp_list[$code][0]['cost'],'type'=>$k];
                                array_shift($other_tmp_list[$code]);
                                $m = 0;
                                break;
                            }else{
                                $re[] = ['code'=>$code,'share'=>$other_tmp_list[$code][0]['share'],'cost'=>$other_tmp_list[$code][0]['cost'],'type'=>$k];
                                $m -= $other_tmp_list[$code][0]['amount'];
                                array_shift($other_tmp_list[$code]);
                            }
                        }
                    }else{
                        break;
                    }
                }
                $change[$k] = 0-$m;
            }
            //处理基金池中的
            foreach($change as $k=>$v){
                $m = abs($v);
                if($m == 0)continue;
                while(true){
                    $tmp = 1; 
                    $code = null;
                    if(isset($type_list[$k])){
                        foreach($type_list[$k] as $co){
                            if(!$tmp_list[$co]){
                                continue;
                            }
                            if($tmp_list[$co][0]['fee']<$tmp){
                                $code = $co; 
                                $tmp = $tmp_list[$co][0]['fee']; 
                            } 
                        }
                    }
                    if($code){
                        if($tmp_list[$code][0]['flag']==0){
                            if($tmp_list[$code][0]['amount']>=$m){
                                if(abs($tmp_list[$code][0]['amount'] - $m)<=1){
                                    $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                    array_shift($tmp_list[$code]);
                                }else{
                                    $re[] = ['code'=>$code,'share'=>round($tmp_list[$code][0]['share']*$m/$tmp_list[$code][0]['amount'],2),'cost'=>$tmp_list[$code][0]['cost']*$m/$tmp_list[$code][0]['amount'],'type'=>$k];
                                    $tmp_list[$code][0]['share'] -= round($tmp_list[$code][0]['share']*$m/$tmp_list[$code][0]['amount'],2);
                                    $tmp_list[$code][0]['cost'] -= $tmp_list[$code][0]['cost']*$m/$tmp_list[$code][0]['amount'];
                                    $tmp_list[$code][0]['amount'] -= $m;
                                }
                                $m = 0;
                                break;
                            }else{
                                $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                $m -= $tmp_list[$code][0]['amount'];
                                array_shift($tmp_list[$code]);
                            }
                        }else{
                            if($tmp_list[$code][0]['amount']>=$m){
                                $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                array_shift($tmp_list[$code]);
                                $m = 0;
                                break;
                            }else{
                                $re[] = ['code'=>$code,'share'=>$tmp_list[$code][0]['share'],'cost'=>$tmp_list[$code][0]['cost'],'type'=>$k];
                                $m -= $tmp_list[$code][0]['amount'];
                                array_shift($tmp_list[$code]);
                            }
                        }
                    }else{
                        break;
                    }
                }
            }
            //合并数据 
            $result_code = []; 
            $result_cost = []; 
            $result_type = []; 
            foreach($re as $row){
                if($row['type'] == -1 && isset($fund_list['all_funds'][$row['code']])){
                    $result_type[$row['code']] = $fund_list['all_funds'][$row['code']];
                }else{
                    $result_type[$row['code']] = $row['type'];
                }
                if(isset($result_code[$row['code']])){
                    $result_code[$row['code']] += $row['share'];
                    $result_cost[$row['code']] += $row['cost'];
                }else{
                    $result_code[$row['code']] = $row['share'];
                    $result_cost[$row['code']] = $row['cost'];
                }
            }
            $tmp = [];
            foreach($result_code as $code=>$share){
                if($share==0)continue;
                if(!isset($result_type[$code]))$result_type[$code] = -1;
                if(!isset($fund_list['pool_type'][$result_type[$code]]))$fund_list['pool_type'][$result_type[$code]] = -1;
                $tmp[] = ['code'=>$code,'share'=>$share,'cost'=>$result_cost[$code],'pool'=>$result_type[$code],'type'=>$fund_list['pool_type'][$result_type[$code]]];
            }
        }
        return $tmp;
    }
  
    public static function daysbetweendates($date1, $date2){ 
        $date1 = strtotime($date1); 
        $date2 = strtotime($date2); 
        $days = ceil(abs($date1 - $date2)/86400); 
        return $days; 
    }
    //op 1：购买 2：赎回 3：追加 4：定投 5:调仓
    /** holding = [
                    'holding'=>[['code'=>'','share'=>'','amount'=>'','date'=>'']],
                    'buying'=>[['id'=>'','code'=>'','amount'=>'','date'=>'','source'=>'']],
                    'cancel_buying'=>[['code'=>'','amount'=>'','date'=>'']],
                    'redeeming'=>[['id'=>,'code'=>'','share'=>'','amount'=>'','date'=>'']],
                    'cancel_redeeming'=>[['code'=>'','share'=>'','amount'=>'','date'=>'']],
                    'yingmi'=>['1'=>['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1],'10'=>['list'=>[['code'=>'','share'=>'','amount'=>''],['code'=>'','share'=>'','amount'=>'']],'lower'=>0,'upper'=>1,'tag'=>1]],
                    'yingmi_redeeming'=>['amount'=>0];
                ]

    **/
    public static function matchTrade($id,$risk,$holding,$amount,$redemption,$op,$day=false){
	$fundCodes = [];	
        $uid = $id;
	if($op == 5 and $amount !=0){
            $mf_fund = MfFundTradeStatus::where('mf_portfolio_txn_id',$id)->where('mf_trade_type','W05')->get();
            foreach($mf_fund as $row){
                $fundCodes[] = sprintf('%06d',$row['mf_fund_code']);
		$uid = $row['mf_uid'];
            }
	}
	$portfolio = MfPortfolioTradeStatus::where('mp_txn_id',$id)->first();
	if($portfolio){
	    $uid = $portfolio['mp_uid'];
	}
	$black_list = TsBlackList::where('ts_uid',$uid)->get();
	foreach($black_list as $row){
            $fundCodes[] = sprintf('%06d',$row['ts_fund_code']);
	}
        //合并
        if(!$day){
            $day = TradeStrategyHelper::getTradeDay();
            $fund_list = TradeStrategyHelper::getFundList($id,$fundCodes); 
        }else{
            $fund_list = TradeStrategyHelper::getFundList($id,$fundCodes,$day); 
        }
        $me = TradeStrategyHelper::mergeHolding($holding,$fund_list,$op,$amount,$day);
        $position = TradeStrategyHelper::getPosition($risk,$day);
        $tags = TradeStrategyHelper::setTag(array_unique(array_merge(array_keys($fund_list['funds']),array_keys($me['funds']))),$day); 
        $do = TradeStrategyHelper::tradeStrategy($id,$risk,$holding,$amount,$redemption,$op,$me,$position,$fund_list,$tags,$day);
        $tmp = [];
        if($op == 2 || ($op == 5 && $amount == 0)){
            foreach($do as $row){
                if(isset($row['op']) && $row['op'] == 6){
                    $tmp[] = $row; 
                }else{
                    $tmp[] = ['op'=>2,'fundCode'=>$row['code'],'share'=>$row['share'],'total_share'=>$me['able_share'][$row['code']],'total_asset'=>$me['able'][$row['code']],'cost'=>$row['cost'],'type'=>$row['type'],'pool'=>$row['pool']]; 
                }
            }
        }else{
            foreach($do as $row){
		if(isset($row['is_delay']) && $row['is_delay']){
                    $tmp[] = ['op'=>11,'fundCode'=>$row['code'],'amount'=>$row['amount'],'cost'=>$row['cost'],'type'=>$row['type'],'pool'=>$row['pool']]; 
		}else{
                    $tmp[] = ['op'=>1,'fundCode'=>$row['code'],'amount'=>$row['amount'],'cost'=>$row['cost'],'type'=>$row['type'],'pool'=>$row['pool']]; 
		}
            }
        }

        return $tmp; 
        //return ['hold'=>$tmp,'able'=>$able,'amount'=>$amount,'cash'=>$cash];
    }

    public static function simulateTrade($id,$risk,$holding,$amount,$redemption,$op,$day=false){
        $cost = 0;
        Log::info('simulate_test1:'. json_encode($holding));
        $ops = TradeStrategyHelper::matchTrade($id,$risk,$holding,$amount,$redemption,$op,$day);
        $result = TradeStrategyHelper::doing($holding,$ops);
        $cost += $result['cost'];
        if($op == 5 && $amount ==0){
            $ops = TradeStrategyHelper::matchTrade($id,$risk,$result['holding'],$result['amount'],$redemption,$op,$day);
            $result = TradeStrategyHelper::doing($result['holding'],$ops);
            $cost += $result['cost'];
        }
        Log::info('simulate_test2:'. json_encode($result['holding']));
        return ['holding'=>$result['holding'],'cost'=>$cost]; 
    }


    public static function getTradeDay(){
        $h = date('H');
        if($h>=15){
            return date('Y-m-d',strtotime("+1 day"));
        }else{
            return date('Y-m-d');
        }
    }

    //获取某风险最新配置
    public static function getPosition($risk,$day){
        $risk = self::$risk_list[$risk];
        $tmp = BnMarkowitz::where('ra_alloc_id',$risk)->where('ra_date','<=',$day)->orderBy('ra_date','DESC')->first();
        $tmp = BnMarkowitz::where('ra_alloc_id',$risk)->where('ra_date',$tmp['ra_date'])->get();
        $re = [$day=>[]]; 
        foreach($tmp as $row){
            $re[$day][$row['ra_asset_id']] = $row['ra_ratio']; 
        }
        $ratio = TradeStrategyHelper::updateType($re,true);
        return $ratio[$day];
    }
    
    //获取基金池
    public static function getFundList($id=false,$fundCodes=[],$day=false){
        $list = []; //不分上涨下跌，按股票，债券，黄金，标普，货币分类
        $funds = [];//当前基金池类型
        $all_fund = [];//所有基金池类型
        $all_fund_type = [];//所有基金类型
        $tmp = [];
        $bn_pool = BnRaPool::all();
        $pools = [];
        $poll_type = [];
        foreach($bn_pool as $pool){
            $pools[] = $pool['globalid'];
            $pool_type[$pool['globalid']] = $pool['ra_fund_type'];
        }
        //剔除存在self::$able_pools且存在其他类型的
        if($day){
            $date = BnRaPoolFund::whereIn('ra_pool_id',self::$able_pools)->where('ra_date','<=',$day)->orderBy('ra_date','DESC')->first();        
        }else{
            $date = BnRaPoolFund::whereIn('ra_pool_id',self::$able_pools)->orderBy('ra_date','DESC')->first();        
        }
        $re = BnRaPoolFund::whereIn('ra_pool_id',self::$able_pools)->where('ra_date',$date['ra_date'])->orderBy('ra_fund_level','ASC')->orderBy('ra_jensen','DESC')->get();        
        $out_list = [];
        foreach($re as $row){
            $out_list[$row['ra_fund_code']] = $row['ra_pool_id'];
        }
        //判断事件id是否包含老组合
        $is_yingmi = YingmiPortfolioTradeStatus::where('yp_mf_txn_id',$id)->first();
	//临时增加购买新基金
//	$is_yingmi = true;
        //剔除存在self::$able_pools且存在其他类型的
        foreach($pools as $type){
            $list[$type] = [];
            if($day){
                $date = BnRaPoolFund::where('ra_pool_id',$type)->where('ra_date','<=',$day)->orderBy('ra_date','DESC')->first();        
            }else{
                $date = BnRaPoolFund::where('ra_pool_id',$type)->orderBy('ra_date','DESC')->first();        
            }
            $re = BnRaPoolFund::where('ra_pool_id',$type)->where('ra_date',$date['ra_date'])->orderBy('ra_fund_level','ASC')->orderBy('ra_jensen','DESC')->get();        
            foreach($re as $row){
                //剔除存在self::$able_pools且存在其他类型的
                if(isset($out_list[$row['ra_fund_code']]) && !in_array($type,self::$able_pools)){
                    continue;
                }
                $funds[$row['ra_fund_code']] = $type;
                if($is_yingmi){
                    if($row['ra_fund_level'] != 3){
                        continue;
                    }
                }else{
                    if($row['ra_fund_level'] != 1 && $row['ra_fund_level'] != 3){
                        continue;
                    }
                }
                if(in_array($row['ra_fund_code'],$fundCodes)){
                    continue;
                }
                if(!isset($tmp[$row['ra_fund_code']])){
                    $list[$type][] = $row['ra_fund_code'];
                    $tmp[$row['ra_fund_code']] = 1;
                }
            }
        }
        $all = BnRaPoolFund::orderBy('ra_date','ASC')->get();        
        foreach($all as $row){
            $all_fund[$row['ra_fund_code']] = $row['ra_pool_id'];
        }
        foreach($list as $k=>$v){
            foreach($v as $row){
                $all_fund[$row] = $k;
            }
        }
        foreach($out_list as $k=>$v){
            $all_fund[$k] = $v;
        }
        return ['list'=>$list,'funds'=>$funds,'all_funds'=>$all_fund,'pool_type'=>$pool_type];
    }

    
    //合并总资产
    public static function mergeHolding($holding,$fund_list,$op,$able_amount,$day){
        $tmp = [];//当前基金资产
        $holding_type = [] ;//当前基金资产中类比例
        $holding_type_redemption = [] ;//当前可赎回基金资产中类比例
        $able = [];//可以操作的基金资产
        $able_share = [];//可以操作的基金资产份额
        $amount = 0;//当前基金资产市值
        $amount_redemption = 0;//当前可赎回基金资产市值
        $cash = 0;//后续可操作资金:正在取消购买和正在赎回到账的资金
        $codes = [];//所有基金id
        $fund_hold = [];//基金公司持仓:包含该正在赎回的
        $fund_hold_share = [];//基金公司持仓份额:包含该正在赎回的
        $fund_buying = [];//当前交易日购买的
        $out_code = []; //持仓基金不在最新基金池
        $yingmi = [];//盈米持仓
        $yingmi_amount = 0;//当前盈米基金资产市值
        $yingmi_amount_able = 0;//当前盈米可赎回基金资产市值
        $holding_type_yingmi = []; //盈米总持仓类型
        foreach($holding['holding'] as $row){
            $amount += $row['amount'];
            $amount_redemption += $row['amount'];
            $codes[$row['code']] = 1;
            if(isset($tmp[$row['code']])){
                $tmp[$row['code']] += $row['amount'];
            }else{
                $tmp[$row['code']] = $row['amount'];
            }
            if(isset($fund_hold[$row['code']])){
                $fund_hold[$row['code']] += $row['amount'];
            }else{
                $fund_hold[$row['code']] = $row['amount'];
            }
            if(isset($fund_hold_share[$row['code']])){
                $fund_hold_share[$row['code']] += $row['share'];
            }else{
                $fund_hold_share[$row['code']] = $row['share'];
            }
            if($op == 2 || $op == 5){
                if(isset($able[$row['code']])){
                    $able[$row['code']] += $row['amount'];
                }else{
                    $able[$row['code']] = $row['amount'];
                }
                if(isset($able_share[$row['code']])){
                    $able_share[$row['code']] += $row['share'];
                }else{
                    $able_share[$row['code']] = $row['share'];
                }
            }
            if(!isset($fund_list['funds'][$row['code']])){
                $out_code[$row['code']] = 1;
            }
        }
        foreach($holding['buying'] as $row){
            $amount += $row['amount'];
            $codes[$row['code']] = 1;
            if(isset($tmp[$row['code']])){
                $tmp[$row['code']] += $row['amount'];
            }else{
                $tmp[$row['code']] = $row['amount'];
            }
            if(isset($fund_hold[$row['code']])){
                $fund_hold[$row['code']] += $row['amount'];
            }else{
                $fund_hold[$row['code']] = $row['amount'];
            }
            if(strtotime($row['date'])>=strtotime($day)){
                if(isset($fund_buying[$row['code']])){
                    $fund_buying[$row['code']] += $row['amount'];
                }else{
                    $fund_buying[$row['code']] = $row['amount'];
                }
            }
            if(!isset($fund_list['funds'][$row['code']])){
                $out_code[$row['code']] = 1;
            }
        }
        foreach($holding['yingmi'] as $key=>$row){
            $yingmi[$key] = [];
            $yingmi[$key]['type'] = [];
            $yingmi[$key]['lower'] = $row['lower'];
            $yingmi[$key]['upper'] = $row['upper'];
            $yingmi[$key]['tag'] = $row['tag'];
            $yingmi[$key]['amount'] = 0;
            foreach($row['list'] as $t){
                $k = $t['code'];
                $v = $t['amount'];
                $amount += $v;
                $yingmi_amount += $v;
                if($row['tag'] == 1){
                    $yingmi_amount_able += $v;
                }
                $yingmi[$key]['amount'] += $v;
                $codes[$k] = 1;
                if(isset($fund_list['funds'][$k])){
                    if(isset($holding_type_yingmi[$fund_list['funds'][$k]])){
                        $holding_type_yingmi[$fund_list['funds'][$k]] += $v;
                    }else{
                        $holding_type_yingmi[$fund_list['funds'][$k]] = $v;
                    }
                    if(isset($yingmi[$key]['type'][$fund_list['funds'][$k]])){
                        $yingmi[$key]['type'][$fund_list['funds'][$k]] += $v;
                    }else{
                        $yingmi[$key]['type'][$fund_list['funds'][$k]] = $v;
                    }
                }else{
                    if(isset($holding_type_yingmi[-1])){
                        $holding_type_yingmi[-1] += $v;
                    }else{
                        $holding_type_yingmi[-1] = $v;
                    }
                    if(isset($yingmi[$key]['type'][-1])){
                        $yingmi[$key]['type'][-1] += $v;
                    }else{
                        $yingmi[$key]['type'][-1] = $v;
                    }
                }
                if(isset($fund_list['funds'][$k])){
                    if(isset($holding_type[$fund_list['funds'][$k]])){
                        $holding_type[$fund_list['funds'][$k]] += $v;
                    }else{
                        $holding_type[$fund_list['funds'][$k]] = $v;
                    }
                }else{
                    if(isset($holding_type[-1])){
                        $holding_type[-1] += $v;
                    }else{
                        $holding_type[-1] = $v;
                    }
                }
            }
             
        }
        foreach($holding['cancel_buying'] as $row){
            $codes[$row['code']] = 1;
            $cash += $row['amount'];
        }
        foreach($holding['redeeming'] as $row){
            $codes[$row['code']] = 1;
            $cash += $row['amount'];
            if($able_amount== 0 && $op == 5){
                $amount += $row['amount'];
            }
            if(isset($fund_hold[$row['code']])){
                $fund_hold[$row['code']] += $row['amount'];
            }else{
                $fund_hold[$row['code']] = $row['amount'];
            }
        }
        if(isset($holding['yingmi_redeeming'])&& isset($holding['yingmi_redeeming']['amount'])){
            if($able_amount== 0 && $op == 5){
            	$amount += $holding['yingmi_redeeming']['amount']; 
            }
            $cash += $holding['yingmi_redeeming']['amount']; 
        }
        foreach($holding['cancel_redeeming'] as $row){
            $amount += $row['amount'];
            $codes[$row['code']] = 1;
            if(isset($tmp[$row['code']])){
                $tmp[$row['code']] += $row['amount'];
            }else{
                $tmp[$row['code']] = $row['amount'];
            }
            if(isset($fund_hold[$row['code']])){
                $fund_hold[$row['code']] += $row['amount'];
            }else{
                $fund_hold[$row['code']] = $row['amount'];
            }
            if(isset($fund_hold_share[$row['code']])){
                $fund_hold_share[$row['code']] += $row['share'];
            }else{
                $fund_hold_share[$row['code']] = $row['share'];
            }
            if(!isset($fund_list['funds'][$row['code']])){
                $out_code[$row['code']] = 1;
            }
        }
        foreach($tmp as $k=>$v){
            if(isset($fund_list['funds'][$k])){
                if(isset($holding_type[$fund_list['funds'][$k]])){
                    $holding_type[$fund_list['funds'][$k]] += $v;
                }else{
                    $holding_type[$fund_list['funds'][$k]] = $v;
                }
            }else{
                if(isset($holding_type[-1])){
                    $holding_type[-1] += $v;
                }else{
                    $holding_type[-1] = $v;
                }
            }
        }
        foreach($able as $k=>$v){
            if(isset($fund_list['funds'][$k])){
                if(isset($holding_type_redemption[$fund_list['funds'][$k]])){
                    $holding_type_redemption[$fund_list['funds'][$k]] += $v;
                }else{
                    $holding_type_redemption[$fund_list['funds'][$k]] = $v;
                }
            }else{
                if(isset($holding_type_redemption[-1])){
                    $holding_type_redemption[-1] += $v;
                }else{
                    $holding_type_redemption[-1] = $v;
                }
            }
        }
        return ['hold'=>$tmp,'able'=>$able,'able_share'=>$able_share,'amount'=>$amount,'cash'=>$cash,'funds'=>$codes,'holding_type'=>$holding_type,'fund_hold'=>$fund_hold,'fund_buying'=>$fund_buying,'holding_type_redemption'=>$holding_type_redemption,'amount_redemption'=>$amount_redemption,'fund_hold_share'=>$fund_hold_share,'out_code'=>$out_code,'yingmi'=>$yingmi,'holding_type_yingmi'=>$holding_type_yingmi,'yingmi_amount'=>$yingmi_amount,'yingmi_amount_able'=>$yingmi_amount_able];
    } 

    //计算转化值 position1 现有  position2 目标
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

    public static function getDeviationOnline($risk,$first_day,$last_day=false){
        if($first_day != null){
            $position = TradeStrategyHelper::getPosition($risk,$first_day);
        }else{
            $position = [];
        }
        if($last_day == false){
            $last_day = TradeStrategyHelper::getTradeDay();
        }
        $new_position = TradeStrategyHelper::getPosition($risk,$last_day);
        $tmp = TradeStrategyHelper::getDeviation($position,$new_position);
        if(TradeStrategyHelper::$deviation >= $tmp){
            $status = false;
        }else{
            $status = true;
        }
        return $status;
    }

    public static function checkDeviation($risk,$holding,$day=false){
        if(!$day){
            $day = TradeStrategyHelper::getTradeDay();
        }
        $fund_list = TradeStrategyHelper::getFundList(); 
        $me = TradeStrategyHelper::mergeHolding($holding,$fund_list,5,0,$day);
        $position = TradeStrategyHelper::getPosition($risk,$day);
        $old_position = [];
        if($me['amount']<1){
            return ['status'=>false,'fund'=>[],'percent'=>1];
        }
        foreach($me['holding_type'] as $k=>$v){
            $old_position[$k] = round($v/$me['amount'],4);
        }
        $tmp = TradeStrategyHelper::getDeviation($old_position,$position);
        if(TradeStrategyHelper::$deviation >= $tmp){
            $status = false;
        }else{
            $status = true;
        }
        $tmp = $tmp/TradeStrategyHelper::$deviation * (1-TradeStrategyHelper::$mark);
        return ['status'=>$status,'fund'=>$me['out_code'],'percent'=>$tmp];
    }

    public static function Alternative($id,$risk, $holding ,$fundCode,$amount){
        $day = TradeStrategyHelper::getTradeDay();
        $fund_list = TradeStrategyHelper::getFundList($id,[$fundCode]); 
        $me = TradeStrategyHelper::mergeHolding($holding,$fund_list,1,0,$day);
        $tags = TradeStrategyHelper::setTag(array_unique(array_merge(array_keys($fund_list['funds']),array_keys($me['funds'])))); 
        $new_change = [$fund_list['funds'][$fundCode]=>$amount];
        $do = TradeStrategyHelper::matchFund($new_change,$amount,$holding,$me,$fund_list,$tags,1,$day);
        $tmp = [];
        foreach($do as $k=>$v){
            $tmp[] = ['op'=>1,'fundCode'=>$k,'amount'=>$v]; 
        }
        return $tmp; 
    }

    public static function redemptionShare($id,$risk,$holding){
        $day = TradeStrategyHelper::getTradeDay();
//        $fund_list = TradeStrategyHelper::getFundList(); 
        $me = TradeStrategyHelper::mergeHolding($holding,[],2,0,$day);
        $position = TradeStrategyHelper::getPosition($risk,$day);
        $limit = self::$redemption_amount_limit;
        $tags = TradeStrategyHelper::setTag(array_unique(array_keys($me['funds']))); 
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
                    **/
        $amount = array_sum($me['able']);
        $max = 0;
        $min = 1;
        $flag = false;
        foreach($holding['holding'] as $row){
            $code = $row['code'];
            if($tags[$code][23]!==0 && $tags[$code][23] != 5){
                return ['lowest'=>0,'highest'=>0,'amount'=>0];
            } 
            if($row['share'] == 0){
                return ['lowest'=>0,'highest'=>0,'amount'=>0];
            }    
        }
        foreach($holding['yingmi'] as $row){
            if($row['tag'] != 1){
                return ['lowest'=>0,'highest'=>0,'amount'=>0];
            }
        }
        if($amount+$me['yingmi_amount_able'] <= $limit){
            $lowest = 1;
            $highest = 1;
            return ['lowest'=>$lowest,'highest'=>$highest,'amount'=>$amount+$me['yingmi_amount_able']];
        }
        foreach($me['able_share'] as $k=>$v){
            if(isset($tags[$k]) && isset($tags[$k][6]) && isset($tags[$k][7])){
                if($tags[$k][6] + $tags[$k][7] <= $v){
                    $tmp = $tags[$k][7]/$v;
                    $tt = 1 - $tags[$k][6]/$v;
                }else{
                    //$max = 1;
                    //$min = 1;
                    //break;
                    continue;
                }
            }else{
                //$min = 1;
                //$max = 1;
                //break;
                continue;
            }
            if($tmp > $max){
                $max = $tmp;
            }
            if($tt < $min){
                $min = $tt;
            }
            
        }
        $lowest = ceil($max*100)/100;
        $highest = floor($min*100)/100;
        if($lowest > $highest){
            $lowest = 1;
            $highest = 1;
        }
        if($amount+$me['yingmi_amount_able']!=0){
            $high_limit = floor(($amount+$me['yingmi_amount_able']-$limit)/($amount+$me['yingmi_amount_able'])*100)/100;
            if($high_limit <= $lowest){
                $lowest = 1;
                $highest = 1;
            }elseif($high_limit < $highest){
                $highest = $high_limit;
            }
        }
        foreach($me['yingmi'] as $key=>$row){
            if($row['tag'] != 1){
                continue;
            }
            if($row['lower'] == 1 && $amount/$me['yingmi_amount_able']>20){
                continue;
            }
            if($lowest<$row['lower']){
                $lowest = $row['lower'];
            } 
            if($highest>$row['upper']){
                $highest = $row['upper'];
            } 
        }
        if($lowest > $highest){
            $lowest = 1;
            $highest = 1;
        }

        return ['lowest'=>$lowest,'highest'=>$highest,'amount'=>$amount+$me['yingmi_amount_able']];
    }

    public static function doing($holdings,$op){
        $holding = $holdings;
        $costs = 0;
        $amount = 0;
        foreach($op as $row){
            if($row['op'] == 1){
                $cost = $row['cost'];
                $costs += $cost;
                $holding['holding'][] = ['code'=>$row['fundCode'],'share'=>0,'amount'=>round($row['amount']-$cost,2),'pool'=>$row['pool'],'type'=>$row['type']];
            }elseif($row['op'] == 2){
                $share = $row['share'];
                for($i = 0;$i< count($holding['holding']);$i++){
                    if($holding['holding'][$i]['code'] == $row['fundCode']){
                        if($row['share'] >= $holding['holding'][$i]['share']){
                            $cost = $row['cost']*$holding['holding'][$i]['share']/$share;
                            $costs += $cost;
                            $row['share'] -= $holding['holding'][$i]['share'];
                            $amount += $holding['holding'][$i]['amount']-$cost;
                            $holding['holding'][$i]['share'] = 0;
                            $holding['holding'][$i]['amount'] = 0;
                        }else{
                            $cost = $row['cost']*$row['share']/$share;
                            $costs += $cost;
                            $amount += $holding['holding'][$i]['amount']*($row['share']/$holding['holding'][$i]['share'])-$cost;
                            $holding['holding'][$i]['amount'] -= $holding['holding'][$i]['amount']*($row['share']/$holding['holding'][$i]['share']);
                            $holding['holding'][$i]['share'] -= $row['share'];
                            if($holding['holding'][$i]['share'] <0){
                                $holding['holding'][$i]['share'] = 0;
                                $holding['holding'][$i]['amount'] = 0;
                            }
                            break;
                        }
                    }
                }    
            }elseif($row['op'] == 6){
                $tmp = [];
                foreach($holding['yingmi'][$row['id']]['list'] as $r){
                    $amount += $r['amount']*$row['redemption'];
                    $tmp[] = ['code'=>$r['code'],'share'=>$r['share']*(1-$row['redemption']),'amount'=>$r['amount']*(1-$row['redemption'])];
                }
                $holding['yingmi'][$row['id']]['list'] = $tmp;
            }
        }
        return ['holding'=>$holding,'cost'=>$costs,'amount'=>$amount];
    }

} 

