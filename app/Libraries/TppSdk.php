<?php namespace App\Libraries;
use App\Libraries\SxfSdk\SxfHelper;
use App\TppTradeStatus;
use App\TppList;
use App\TppBankList;
class TppSdk 
{
    function __construct() {
        $this->pay_list = ['随行付'=>new SxfHelper];
    }

    public function getBalance($pay){
        return $this->pay_list[$pay]->getBalance();
    }

    //list = ['amount'=>,'bank_name'=>,'name'=>,'card'=>,'uid'=>,'order_id'=>];
    public function pay($business_id,$list){
        $split = $this->split($business_id,$list);
        $doing = [];
        foreach($split['doing'] as $payment=>$row){
            $doing = array_merge($row,$doing);
            $sdk = $this->pay_list[$payment];
            $trade_status = $sdk->pay($row);
            $this->insertTradeStatus($business_id,$trade_status);
        }
        return ['doing'=>$doing,'error'=>$split['error'],'duplicate'=>$split['duplicate']]; 
    } 

    public function duplicate($business_id,$list){
        $status = TppTradeStatus::where('tt_business_id',$business_id)->where('tt_business_order_id',$list['order_id'])->whereIn('tt_trade_status',[0,1])->first();
        if($status){
            return false;
        }else{
            return true;
        }
    }
    
    public function insertTradeStatus($business_id,$trade_status){
        $insert = [];
        $time = date('Y-m-d H:i:s');
        foreach($trade_status as $row){
            if($row['status']){
                $status = 0;
            }else{
                $status = 2;
            }
            $insert[] = ['tt_payment_type'=>$row['payment'],'tt_contrace_no'=>$row['no'],'tt_mer_deal_number'=>$row['number'],'tt_uid'=>$row['uid'],'tt_name'=>$row['name'],
                            'tt_business_id'=>$business_id,'tt_business_order_id'=>$row['order_id'],'tt_trade_type'=>0,'tt_trade_status'=>$status,
                            'tt_trade_amt'=>$row['amount'],'tt_bank_account'=>$row['card'],'tt_bank'=>$row['bank_name'],'tt_trade_time'=>$row['trade_time'],'created_at'=>$time,'updated_at'=>$time];
        } 
        TppTradeStatus::insert($insert);
    }
    
    //拆单至对应渠道
    public function split($business_id,$list){
        $doing = [];
        $error = [];
        $duplicate = [];
        $fee = $this->getFee();
        foreach($list as $row){
            $payment = $this->getPayment($row,$fee); 
            if(!$payment){
                $error[] = $row;
            }else{
                if($this->duplicate($business_id,$row)){ 
                    $row['bank'] = $payment['code'];
                    $row['payment'] = $payment['id'];
                    if(isset($doing[$payment['name']])){
                        $doing[$payment['name']][] = $row; 
                    }else{
                        $doing[$payment['name']] = []; 
                        $doing[$payment['name']][] = $row; 
                    }
                }else{
                    $duplicate[] = $row;
                }
            }
        } 
        return ['doing'=>$doing,'error'=>$error,'duplicate'=>$duplicate];
    }
    
    //获取最佳渠道
    public function getPayment($pay,$fee){
        $result = [];
        $cost = 100000;
        if(isset($fee[$pay['bank_name']])){
            foreach($fee[$pay['bank_name']] as $row){
                if($row['type'] == 0){
                    if($cost>$row['fee']){
                        $result = $row;
                        $cost = $row['fee'];
                    }  
                }else{
                    $tmp = $pay['amount'] * $row['fee'];
                    if($cost>$tmp){
                        $result = $row;
                        $cost = $tmp;
                    }  
                }
            }
        }
        if($cost == 100000){
            return false;
        }else{
            return $result;
        }
    }

    //获取费率和渠道对应关系 
    public function getFee(){
        $fee = [];
        $tpp = TppList::all();
        foreach($tpp as $row){
            $fee[$row['id']] = ['name'=>$row['tl_payment_name'],'fee'=>$row['tl_fee'],'type'=>$row['tl_type']];
        }
        $banks = TppBankList::all();
        $result = [];
        foreach($banks as $row){
            if(isset($result[$row['tb_bank_name']])){
                $result[$row['tb_bank_name']][] = ['id'=>$row['tb_pay_id'],'code'=>$row['tb_bank_code'],'fee'=>$fee[$row['tb_pay_id']]['fee'],'type'=>$fee[$row['tb_pay_id']]['type'],'name'=>$fee[$row['tb_pay_id']]['name']];
            }else{
                $result[$row['tb_bank_name']] = [];
                $result[$row['tb_bank_name']][] = ['id'=>$row['tb_pay_id'],'code'=>$row['tb_bank_code'],'fee'=>$fee[$row['tb_pay_id']]['fee'],'type'=>$fee[$row['tb_pay_id']]['type'],'name'=>$fee[$row['tb_pay_id']]['name']];
            }
        }
        return $result; 
    }
    
    public function updatePayResult(){
        $list = TppTradeStatus::where('tt_trade_status',0)->get();        
        $tpp = TppList::all();
        $payments = [];
        foreach($tpp as $row){
            $payments[$row['id']] = $row['tl_payment_name'];
        }
        foreach($list as $row){
            $res = $this->getPayResult($payments[$row['tt_payment_type']],$row['tt_contrace_no']);
            if($res){
                $this->updateTradeStatus($row['id'],$res);
            }
        }
    }

    public function getPayResult($payment,$order_id){
        $sdk = $this->pay_list[$payment];
        $trade_status = $sdk->getPayResult($order_id);
        return $trade_status;
    }

    public function updateTradeStatus($id,$status){
        TppTradeStatus::where('id',$id)->update(['tt_trade_status'=>$status['status'],'tt_cancel_flag'=>$status['msg']]);
    }

    public static function getTradeStatus($business_id,$order_id){
        $status = TppTradeStatus::where('tt_business_id',$business_id)->where('tt_business_order_id',$order_id)->orderBy('id','DESC')->first(); 
        if($status){
            return $status['tt_trade_status'];
        }
        return false;
    }
}
?>
