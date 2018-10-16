<?php namespace App\Libraries;

use DB;
use Log;
use App\LogSync;

class BatchUpdater
{
    public $timestamps = true;
    // private $key;
    // private $time_start     =   0;
    // private $time_end       =   0;
    // private $time           =   0;

    // public function __construct($key){
    //     $this->key = $key;
    //     $this->time_start= microtime(true);
    // }

    // public function __destruct(){
    //     $this->time_end = microtime(true);
    //     $this->time = $this->time_end - $this->time_start;
    //     Log::info(sprintf("Execute %s in %d ms ", $this->key, $this->time * 1000));
    // }

    public static function batchKey($m)
    {
        if (method_exists($m, 'logkeys')) {
            return $m->logkeys();
        } else {
            $keyName = $m->getKeyName();
            if (is_array($keyName)) {
                $key = [];
                foreach ($keyName as $k) {
                    $key[$k] = $m->getAttribute($k);
                }
            } else {
                $key = [$keyName => $m->getKey()];
            }

            return $key;
        }
    }

    public static function batchKeyName($m)
    {
        if (method_exists($m, 'logkeys')) {
            return array_keys($m->logkeys());
        } else {
            return $m->getKeyName();
        }
    }

    public static function batchArrayKey($m, $keyName)
    {
        if (is_array($keyName)) {
            $key = [];
            foreach ($keyName as $k) {
                $key[$k] = isset($m[$k]) ? $m[$k] : null;
            }
        } else {
            if (isset($m[$keyName])) {
                $key = [$keyName => $m[$keyName]];
            } else {
                $key = [$keyName => ''];
            }
        }
        return $key;
    }

    public static function batch($modelClass, $toInsert = [], $toUpdate = [], $toDelete = [], $verbose = [])
    {
        DB::transaction(function () use ($modelClass, $toInsert, $toUpdate, $toDelete, $verbose) {
                //
                // prodess delete
                //
                if (!empty($toDelete)) {
                    foreach ($toDelete as $m) {
                        if ($verbose) {
                            if (isset($verbose["dfun"])) {
                                call_user_func($verbose["dfun"], $m);
                            } else {
                                Log::info(sprintf(
                                    "%s::delete", $modelClass), ['key' => $this->batchKey($m)]);
                            }
                        }

                        $m->delete();
                    }
                }

                //
                // process update
                //
                if (!empty($toUpdate)) {
                    foreach ($toUpdate as $m) {
                        if ($verbose) {
                            $changes = [];
                            $dirties = $m->getDirty();
                            foreach ($dirties as $k => $v) {
                                $changes[] = ['key' => $k, 'old' => $m->getOriginal($k), 'new' => $v ];
                            }
                            if (isset($verbose["ufun"])) {
                                call_user_func($verbose["ufun"], $changes, $m);
                            } else {
                                Log::info(sprintf("%s::update", $modelClass), [
                                    'key' => static::batchKey($m),
                                    'dirty' => $changes
                                ]);
                            }
                        }
                        // var_dump($m);
                        $m->save();
                    }
                }

                //
                // process insert
                //
                if (!empty($toInsert)) {
                    if ($verbose) {
                        if (isset($verbose["ifun"])) {
                            call_user_func($verbose["dfun"], $m);
                        } else {
                            $keyName = $this->batchKeyName(new $modelClass());
                            if (count($toInsert) < 10) {
                                foreach ($toInsert as $m) {
                                    Log::info(sprintf("%s::insert", $modelClass), [
                                        'key' => static::batchArrayKey($m, $keyName)
                                    ]);
                                }
                            } else {
                                Log::info(sprintf("%s::insert and etc", $modelClass), [
                                    'key' => static::batchArrayKey($toInsert[0], $keyName),
                                    'count' => count($toInsert)
                                ]);
                            }
                        }

                        // if (isset($verbose["ifun"])) {
                        //     call_user_func($verbose["ifun"], $toInsert);
                        // } else {
                        //     Log::info(sprintf("%s::insert", $modelClass), [
                        //         'count' => count($toInsert)
                        //     ]);
                        // }
                    }

                    if ($this->timestamps) {
                        $now = DB::raw('NOW()');
                        foreach ($toInsert as &$m) {
                            $m['created_at'] = $now;
                            $m['updated_at'] = $now;
                        }
                    }

                    $chunks = array_chunk($toInsert, 200);
                    foreach ($chunks as $chunk) {
                        $modelClass::insert($chunk);
                    }
                }
            }
        );
    }

    public static function batchWithInTransation($modelClass, $toInsert = [], $toUpdate = [], $toDelete = [], $verbose = [], $timestamps = true)
    {
        //
        // prodess delete
        //
        if (!empty($toDelete)) {
            foreach ($toDelete as $m) {
                if ($verbose) {
                    if (isset($verbose["dfun"])) {
                        call_user_func($verbose["dfun"], $m);
                    } else {
                        Log::info(sprintf(
                            "%s::delete", $modelClass), ['key' => static::batchKey($m)]);
                    }
                }

                $m->delete();
            }
        }

        //
        // process update
        //
        if (!empty($toUpdate)) {
            foreach ($toUpdate as $m) {
                if ($verbose) {
                    $changes = [];
                    $dirties = $m->getDirty();
                    foreach ($dirties as $k => $v) {
                        $changes[] = ['key' => $k, 'old' => $m->getOriginal($k), 'new' => $v ];
                    }
                    if (isset($verbose["ufun"])) {
                        call_user_func($verbose["ufun"], $changes, $m);
                    } else {
                        Log::info(sprintf("%s::update", $modelClass), [
                            'key' => static::batchKey($m),
                            'dirty' => $changes
                        ]);
                    }
                }
                // var_dump($m);
                $m->save();
            }
        }

        //
        // process insert
        //
        if (!empty($toInsert)) {
            if ($verbose) {
                if (isset($verbose["ifun"])) {
                    call_user_func($verbose["dfun"], $m);
                } else {
                    $keyName = static::batchKeyName(new $modelClass());
                    if (count($toInsert) < 10) {
                        foreach ($toInsert as $m) {
                            Log::info(sprintf("%s::insert", $modelClass), [
                                'key' => static::batchArrayKey($m, $keyName)
                            ]);
                        }
                    } else {
                        Log::info(sprintf("%s::insert and etc", $modelClass), [
                            'key' => static::batchArrayKey($toInsert[0], $keyName),
                            'count' => count($toInsert)
                        ]);
                    }
                }
            }

            if ($timestamps) {
                $now = DB::raw('NOW()');
                foreach ($toInsert as &$m) {
                    $m['created_at'] = $now;
                    $m['updated_at'] = $now;
                }
            }

            $chunks = array_chunk($toInsert, 200);
            foreach ($chunks as $chunk) {
                $modelClass::insert($chunk);
            }
        }
    }
}
