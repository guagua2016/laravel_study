<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class YingmiWalletShareDetail extends Model
{
    //
    protected $guarded = [
        'id', 'created_at', 'updated_at'
    ];

    protected $table = 'yingmi_wallet_share_details';
    protected $primaryKey = 'id';

    public function tsPayMethod()
    {
        return $this->hasOne('App\TsPayMethod', 'ts_gateway_pay_id', 'yw_pay_method');
    }
}
