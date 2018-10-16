<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class TsOrder extends Model
{
    //
    protected $table = 'ts_order';
    protected $guarded = ['created_at','updated_at'];
}
