<?php namespace App\Libraries;

function intcmp($a, $b) {
    if ($a == $b) {
        return 0;
    }
    return ($a - $b) < 0 ? -1 : 1;
}

function floatcmp($a, $b) {
    if ($a < $b) {
        return -1;
    }

    if ($a > $b) {
        return 1;
    }

    return 0;
}

function floorp($val, $precision = 2)
{
    $mult = pow(10, $precision);
    return ((float)floor($val * $mult)) / $mult;
}

function floorp_format($val, $precision = 2, $dot = '.', $comma = '')
{
    return number_format(floorp($val + 0.000000001, $precision), $precision, $dot, $comma);
}

function numfmt($val, $precision = 2, $dot = '.', $comma = '')
{
    return number_format(round($val, $precision), $precision, $dot, $comma);
}

function normalize_commpany_name($name, $common_name) {
    if ($common_name) {
        $names = explode(',', $common_name);
        return $names[0];
    }
    return $name;
}

function fts($dir, $callable) {
    $result = array();

    $ffs = scandir($dir);

    foreach ($ffs as $ff) {
        if ($ff == '.' || $ff == '..')  continue;

        $path = sprintf("%s/%s", $dir, $ff);
        $entry = call_user_func($callable, $path);

        if ($entry) {
            $result[] = $entry;
        }

        if (is_dir($path))  {
            $tmp = fts($path, $callable);

            $result = array_merge($result, $tmp);
        }
    }

    return $result;
}

function relative_time($datetime, $relative_to = null)
{
    if ($relative_to == null) {
        $relative_to = time();

    }

    $diff = $relative_to - $datetime;

    $diff = floor($diff/60);

    if ($diff <= 0) {
        return "1分钟前";
    }

    if ($diff < 60) {
        return sprintf('%d分钟前', $diff);
    }

    $diff = floor($diff / 60);
    if ($diff < 24) {
        return sprintf("%d小时前", $diff);
    }

    return "一天前";
}

function today()
{
    return date('Y-m-d');
}

function yesterday($day = false)
{
    if ($day) {
        return date('Y-m-d', strtotime($day) - 86400);
    } else {
        return date('Y-m-d', time() - 86400);
    }
}

function date_range($first, $last, $step = '+1 day', $format = 'Y-m-d')
{
	$dates = array();
	$current = strtotime( $first );
	$last = strtotime( $last );

	while( $current <= $last ) {

		$dates[] = date( $format, $current );
		$current = strtotime( $step, $current );
	}

	return $dates;
}

function is_trade_date($day)
{
    $holidays = array(
        //2018
        '2018-10-05' => '1',
        '2018-10-04' => '1',
        '2018-10-03' => '1',
        '2018-10-02' => '1',
        '2018-10-01' => '1',
        '2018-09-24' => '1',
        '2018-06-18' => '1',
        '2018-05-01' => '1',
        '2018-04-30' => '1',
        '2018-04-06' => '1',
        '2018-04-05' => '1',
        '2018-02-21' => '1',
        '2018-02-20' => '1',
        '2018-02-19' => '1',
        '2018-02-16' => '1',
        '2018-02-15' => '1',
        '2018-01-01' => '1',

        //2017
        '2017-10-06' => '1',
        '2017-10-05' => '1',
        '2017-10-04' => '1',
        '2017-10-03' => '1',
        '2017-10-02' => '1',
        '2017-05-30' => '1',
        '2017-05-29' => '1',
        '2017-05-01' => '1',
        '2017-04-04' => '1',
        '2017-04-03' => '1',
        '2017-02-02' => '1',
        '2017-02-01' => '1',
        '2017-01-31' => '1',
        '2017-01-30' => '1',
        '2017-01-27' => '1',
        '2017-01-02' => '1',

        //2016
        '2016-10-07' => '1',
        '2016-10-06' => '1',
        '2016-10-05' => '1',
        '2016-10-04' => '1',
        '2016-10-03' => '1',
        '2016-09-16' => '1',
        '2016-09-15' => '1',
        '2016-06-10' => '1',
        '2016-06-09' => '1',
        '2016-05-02' => '1',
        '2016-04-04' => '1',
        '2016-02-12' => '1',
        '2016-02-11' => '1',
        '2016-02-10' => '1',
        '2016-02-09' => '1',
        '2016-02-08' => '1',
        '2016-01-01' => '1',

        '2015-10-07' => '1',
        '2015-10-06' => '1',
        '2015-10-05' => '1',
        '2015-10-02' => '1',
        '2015-10-01' => '1',
        '2015-09-04' => '1',
        '2015-09-03' => '1',
        '2015-06-22' => '1',
        '2015-05-01' => '1',
        '2015-04-06' => '1',
        '2015-02-24' => '1',
        '2015-02-23' => '1',
        '2015-02-20' => '1',
        '2015-02-19' => '1',
        '2015-02-18' => '1',
        '2015-01-02' => '1',
        '2015-01-01' => '1',
        '2014-10-07' => '1',
        '2014-10-06' => '1',
        '2014-10-03' => '1',
        '2014-10-02' => '1',
        '2014-10-01' => '1',
        '2014-09-08' => '1',
        '2014-06-02' => '1',
        '2014-05-02' => '1',
        '2014-05-01' => '1',
        '2014-04-07' => '1',
        '2014-02-06' => '1',
        '2014-02-05' => '1',
        '2014-02-04' => '1',
        '2014-02-03' => '1',
        '2014-01-31' => '1',
        '2014-01-01' => '1',
        '2013-10-07' => '1',
        '2013-10-04' => '1',
        '2013-10-03' => '1',
        '2013-10-02' => '1',
        '2013-10-01' => '1',
        '2013-09-20' => '1',
        '2013-09-19' => '1',
        '2013-06-12' => '1',
        '2013-06-11' => '1',
        '2013-06-10' => '1',
        '2013-05-01' => '1',
        '2013-04-30' => '1',
        '2013-04-29' => '1',
        '2013-04-05' => '1',
        '2013-04-04' => '1',
        '2013-02-15' => '1',
        '2013-02-14' => '1',
        '2013-02-13' => '1',
        '2013-02-12' => '1',
        '2013-02-11' => '1',
        '2013-01-03' => '1',
        '2013-01-02' => '1',
        '2013-01-01' => '1',
        '2012-10-05' => '1',
        '2012-10-04' => '1',
        '2012-10-03' => '1',
        '2012-10-02' => '1',
        '2012-10-01' => '1',
        '2012-06-22' => '1',
        '2012-05-01' => '1',
        '2012-04-30' => '1',
        '2012-04-04' => '1',
        '2012-04-03' => '1',
        '2012-04-02' => '1',
        '2012-01-27' => '1',
        '2012-01-26' => '1',
        '2012-01-25' => '1',
        '2012-01-24' => '1',
        '2012-01-23' => '1',
        '2012-01-03' => '1',
        '2012-01-02' => '1',
        '2011-10-07' => '1',
        '2011-10-06' => '1',
        '2011-10-05' => '1',
        '2011-10-04' => '1',
        '2011-10-03' => '1',
        '2011-09-12' => '1',
        '2011-06-06' => '1',
        '2011-05-02' => '1',
        '2011-04-05' => '1',
        '2011-04-04' => '1',
        '2011-02-08' => '1',
        '2011-02-07' => '1',
        '2011-02-04' => '1',
        '2011-02-03' => '1',
        '2011-02-02' => '1',
        '2011-01-03' => '1',
        '2010-10-07' => '1',
        '2010-10-06' => '1',
        '2010-10-05' => '1',
        '2010-10-04' => '1',
        '2010-10-03' => '1',
        '2010-10-02' => '1',
        '2010-10-01' => '1',
        '2010-09-24' => '1',
        '2010-09-23' => '1',
        '2010-09-22' => '1',
        '2010-06-16' => '1',
        '2010-06-15' => '1',
        '2010-06-14' => '1',
        '2010-05-03' => '1',
        '2010-05-02' => '1',
        '2010-05-01' => '1',
        '2010-04-05' => '1',
        '2010-04-04' => '1',
        '2010-04-03' => '1',
        '2010-02-19' => '1',
        '2010-02-18' => '1',
        '2010-02-17' => '1',
        '2010-02-16' => '1',
        '2010-02-15' => '1',
        '2010-02-14' => '1',
        '2010-02-13' => '1',
        '2010-01-03' => '1',
        '2010-01-02' => '1',
        '2010-01-01' => '1',
    );

    if (isset($holidays[$day])) {
        return false;
    }

    $dw = date( "w", strtotime($day));

    if ($dw == 0 || $dw == 6) {
        return false;
    }

    return true;
}

function next_trade_date($day, $step = 1) {
    $current = strtotime("1 day", strtotime($day));

    while (true) {
        if (is_trade_date(date('Y-m-d', $current))) {
            $step--;
        }

        if ($step == 0) {
            break;
        }

        $current = strtotime( "1 day ", $current );
    }

    return date('Y-m-d', $current);
}

function latest_trade_date($day) {
    if (is_trade_date($day)) {
        return $day;
    }

	$current = strtotime($day);
    while (true) {
		$current = strtotime( "-1 day ", $current );
        $day = date('Y-m-d', $current);
        if (is_trade_date($day)) {
            break;
        }
    }

    return $day;
}

function basename_class($classname)
{
    if ($pos = strrpos($classname, '\\'))  {
        return substr($classname, $pos + 1);
    }
    return $classname;
}

function rest_rpc_call($host, $path, $params, $showUrl = false) {
    if (isset($_COOKIE['laravel_session'])) {
        $sid = $_COOKIE['laravel_session'];
    } else {
        $sid = '';
    }
    if (!$params) {
        $url = sprintf("%s%s", $host, $path);
    } else {
        $url = sprintf("%s%s?%s", $host, $path, http_build_query($params));
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,$url);
    // curl_setopt($ch, CURLOPT_POST, 1);
    // curl_setopt($ch, CURLOPT_POSTFIELDS,$credentials);

    curl_setopt($ch, CURLOPT_COOKIE, sprintf('laravel_session=%s', $sid));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec ($ch);

    curl_close ($ch);
    // var_dump($response);

    $json =  json_decode($response, true);
    if ($showUrl || $json == false || env('APP_DEBUG_RPC', false)) {
        $context = [
            'url' => $url,
            'sid' => $sid,
            'sid2' => Session::getId(),
            'json' => $json,
        ];
        if ($json == false) {
            Log::error('rest_rpc_call error:', $context);
        } else {
            Log::info('rest_rpc_call result', $context);
        }
    }

    if ($json) {
        return $json;
    } else {
        return $response;
    }
}

function rest_post($host, $path, $data = false, $params = false, $showUrl = false) {
    if (isset($_COOKIE['laravel_session'])) {
        $sid = $_COOKIE['laravel_session'];
    } else {
        $sid = '';
    }

    if (!$params) {
        $url = sprintf("%s%s", $host, $path);
    } else {
        $url = sprintf("%s%s?%s", $host, $path, http_build_query($params));
    }

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL,$url);
    curl_setopt($ch, CURLOPT_POST, 1);

    $postData = '';
    if ($data) {
        if (is_string($data)) {
            $postData = $data;
        } else {
            $postData = json_encode($data);
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS,$postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Content-Length: ' . strlen($postData)));
    }
    curl_setopt($ch, CURLOPT_COOKIE, sprintf('laravel_session=%s', $sid));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec ($ch);

    curl_close ($ch);
    // var_dump($response);

    $json =  json_decode($response, true);
    if ($showUrl || $json == false || env('APP_DEBUG_RPC', false)) {
        $context = [
            'url' => $url,
            'postData' => $postData,
            'sid' => $sid,
            'sid2' => Session::getId(),
            'json' => $json,
        ];
        if ($json == false) {
            Log::error('rest_rpc_call error:', $context);
        } else {
            Log::info('rest_rpc_call result', $context);
        }
    }

    if ($json) {
        return $json;
    } else {
        return $response;
    }
}

function human_money($money)
{
    if ($money < 1000) {
        return $money;
    }
    $money = $money / 1000;
    if ($money < 10) {
        return sprintf('%d千', $money);
    }
    $money = $money / 10;
    if ($money < 10000) {
        return sprintf('%d万', $money);
    }
    $money = $money / 10000;
    return sprintf('%d亿', $money);
}

function blade_string($value, array $args = array())
{
    $generated = \Blade::compileString($value);

    ob_start() and extract($args, EXTR_SKIP);

    // We'll include the view contents for parsing within a catcher
    // so we can avoid any WSOD errors. If an exception occurs we
    // will throw it out to the exception handler.
    try
    {
        eval('?>'.$generated);
    }

    // If we caught an exception, we'll silently flush the output
    // buffer so that no partially rendered views get thrown out
    // to the client and confuse the user with junk.
    catch (\Exception $e)
    {
        ob_get_clean(); // throw $e;
        $content = false;
    }

    $content = ob_get_clean();

    return $content;
}
