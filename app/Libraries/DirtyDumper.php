<?php namespace App\Libraries;

use Log;

class  DirtyDumper
{
    public static function xlogDirty($m, $str = null, $ctx = [])
    {
        $keys = [];
        $changes = [];
        $dirties = $m->getDirty();
        foreach ($dirties as $k => $v) {
            $changes[] = ['key' => $k, 'old' => $m->getOriginal($k), 'new' => $v ];
        }

        $key = $m->getKeyName();
        if (is_array($key)) {
            foreach ($key as $k) {
                $keys[] = [$k => $m->$k];
            }
        } else {
            $keys = [$key => $m->getKey()];
        }
        Log::info($str ? $str :"changes", array_merge($ctx, [
            'key' => $keys,
            'dirty' => $changes
        ]));
    }
}
