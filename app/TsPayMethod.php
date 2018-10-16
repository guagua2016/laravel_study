<?php

namespace App;
use Log;

use Illuminate\Database\Eloquent\Model;

class TsPayMethod extends Model
{
    protected $table = 'ts_pay_method';
    protected $guarded = ['created_at', 'updated_at'];

    public static function tlsPayMethod($gateway, $paymethod)
    {
        $tspay = TsPayMethod::where('ts_gateway_id', $gateway)
            ->where('ts_gateway_pay_id', $paymethod)
            ->first();
        if (!$tspay) {
            Log::error('SNH: missing pay method', [$gateway, $paymethod]);
            if ($paymethod) {
                return "1:$paymethod";
            }
            // debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            // dd('SNH: missing pay method', [$gateway, $paymethod]);
            return 0;
        }

        return $tspay->globalid;
    }

    public static function getGetwayPaymethod($tsPaymethod)
    {
        return substr($tsPaymethod, 2);
    }

    public function ympay()
    {
        return $this->belongsTo('App\YingmiPaymentMethod', 'ts_gateway_pay_id', 'yp_payment_method_id');
    }
}
