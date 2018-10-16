<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TsOrderFund extends Model
{
    //
    protected $table = 'ts_order_fund';
    protected $guarded = ['created_at','updated_at'];

    public $value;
}
