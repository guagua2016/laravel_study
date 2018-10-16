<?php namespace App\Libraries;

use  Illuminate\Database\Eloquent\Collection;

function collection_partition_matrix($collection, $callable, $resultAsCollection = false)
{
    $result = [];

    foreach ($collection as $v) {
        list($r, $c) = call_user_func($callable, $v);

        if (!isset($result[$r])) {
            $result[$r] = array();
        }
        $row = &$result[$r];

        if (!isset($row[$c])) {
            $row[$c] = $resultAsCollection ? new Collection() : array();
        }
        if ($resultAsCollection) {
            $row[$c]->add($v);
        } else {
            $row[$c][] = $v;
        }
    }

    return $result;
}

function collection_partition_cube($collection, $callable, $resultAsCollection = false)
{
    $result = [];

    foreach ($collection as $v) {
        list($r, $c, $z) = call_user_func($callable, $v);

        if (!isset($result[$r])) {
            $result[$r] = array();
        }
        $row = &$result[$r];

        if (!isset($row[$c])) {
            $row[$c] = array();
        }
        $col = &$row[$c];

        if (!isset($col[$z])) {
            $col[$z] = $resultAsCollection ? new Collection() : array();
        }
        if ($resultAsCollection) {
            $col[$z]->add($v);
        } else {
            $col[$z][] = $v;
        }
    }

    return $result;
}

function collection_partition($collection, $keyOrCallable)
{
    $result = [];

    foreach ($collection as $v) {
        if (is_callable($keyOrCallable)) {
            $k = call_user_func($keyOrCallable, $v);
        } else {
            $k = $v->{$keyOrCallable};
        }

        if ($k !== null) {
            if (!isset($result[$k])) {
                $result[$k] = new Collection;
            }
            $result[$k]->add($v);
        }
    }

    return $result;
}

function assoc_map($callback, $assoc_array)
{
    $result = array();
    foreach ($assoc_array as $k => $v) {
        list($kn, $vn) = call_user_func($callback, $k, $v);
        $result[$kn] = $vn;
    }
    return $result;
}

function array_fill_keys_func($keys, $callable)
{
    $result = array();
    foreach ($keys as $k) {
        $result[$k] = call_user_func($callable, $k);
    }
    return $result;
}

function array_dict($arr, $keyOrCallable)
{
    $result = array();
    foreach ($arr as $v) {
        if (is_callable($keyOrCallable)) {
            $k = call_user_func($keyOrCallable, $v);
        } else {
            $k = $v->{$keyOrCallable};
        }
        if ($k !== null) {
            $result[$k] = $v;
        }
    }
    return $result;
}

function assoc_join($a, $b, $key)
{
    $dict = array_dict($b, function($v) {
        return isset($b[$key]) ? $b[$key] : null;
    });

    $result = array();
    foreach($a as $v) {
        $k = isset($a[$key]) ? $a[$k] : null;
        if ($k === null) continue;

        if (isset($dict[$k])) {
            foreach ($dict[$k] as $kb => $vb) {
                if ($kb != $k) {
                    $v[$kb] = $vb;
                }
            }
        }
        $result[] = $v;
    }

    return $result;
}

function object_array_join($key, $a, $b, $c = null)
{
    $kfun = function($v) {
        return isset($b->$key) ? $b->$key : null;
    };

    $dict = array_dict($b, $kfun);

    if ($c) {
        $dict2 = array_dict($c, $kfun);
    }

    $result = array();
    foreach($a as $v) {
        $k = isset($a->$key) ? $a->$k : null;
        if ($k === null) continue;

        if (isset($dict[$k])) {
            foreach ($dict[$k] as $kb => $vb) {
                if ($kb != $k) {
                    $v->$kb = $vb;
                }
            }
        }

        if (isset($dict2[$k])) {
            foreach ($dict2[$k] as $kc => $vc) {
                if ($kc != $k) {
                    $v->$kc = $vc;
                }
            }
        }

        $result[] = $v;
    }

    return $result;
}


function array_calc_diu($old, $new, $key_cmp, $val_cmp)
{
    usort($old, $key_cmp);
    usort($new, $key_cmp);

    $inserted = array();
    $deleted = array();
    $updated = array();

    while (current($old) && current($new)) {
        $left = current($old);
        $right = current($new);

        $ret = call_user_func($key_cmp, $left, $right);
        if ($ret == 0) {
            if (!$val_cmp || call_user_func($val_cmp, $left, $right) != 0) {
                $updated[] = $right;
            }
            next($old);
            next($new);
        } elseif ($ret < 0) {
            $deleted[] = $left;
            next($old);
        } else {
            $inserted[] = $right;
            next($new);
        }
    }

    for (; current($old); next($old)) {
        $deleted[] = current($old);
    }

    for (; current($new); next($new)) {
        $inserted[] = current($new);
    }

    return array($deleted, $inserted, $updated);
}

function model_array_cud($old, $new, $cmp)
{
    usort($old, $cmp);
    usort($new, $cmp);

    $inserted = array();
    $deleted = array();
    $updated = array();

    while (current($old) && current($new)) {
        $left = current($old);
        $right = current($new);

        $ret = call_user_func($cmp, $left, $right);
        if ($ret == 0) {
            if ($left->fill($right)->isDirty()) {
                $updated[] = $left;
            }

            next($old);
            next($new);
        } elseif ($ret < 0) {
            $deleted[] = $left;
            next($old);
        } else {
            $inserted[] = $right;
            next($new);
        }
    }

    for (; current($old); next($old)) {
        $deleted[] = current($old);
    }

    for (; current($new); next($new)) {
        $inserted[] = current($new);
    }

    return array($inserted, $updated, $deleted);
}

function array_sum($arr, $keyOrFun)
{
    $total = 0;

    foreach ($arr as $a) {
        if (is_callable($keyOrFun)) {
            $total += call_user_func($keyOrFun, $a);
        } else {
            $total += $a->{$keyOrFun};
        }
    }

    return $total;
}
