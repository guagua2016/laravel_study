<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class YingmiAccount extends Model
{
    //
    protected $table = 'yingmi_accounts';
    protected $primaryKey = 'ya_uid';
    protected $fillable = array(
        'ya_uid',
        'ya_account_id',
        'ya_name',
        'ya_identity_type',
        'ya_identity_no',
        'ya_phone',
        'ya_email',
        'ya_active',
        'ya_risk_grade',
    );
}
