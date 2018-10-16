<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class YingmiTradeStatus extends Model
{
	//
	protected $table = 'yingmi_trade_statuses';
	protected $primaryKey = 'id';
	protected $guarded = [
		'id','created_at','updated_at'
	];
}
