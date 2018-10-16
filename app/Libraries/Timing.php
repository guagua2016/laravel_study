<?php namespace App\Libraries;

use Log;

class Timing
{
    private $key;
    private $time_start     =   0;
    private $time_end       =   0;
    private $time           =   0;
    private $show           =   true;

    public function __construct($key, $show = true){
        $this->key = $key;
        $this->time_start= microtime(true);
        $this->show = $show;
    }

    public function __destruct(){
        if ($this->show) {
            $this->time_end = microtime(true);
            $this->time = $this->time_end - $this->time_start;
            Log::info(sprintf("%s in %d ms ", $this->key, $this->time * 1000));
        }
    }

    public function key($key) {
        $this->key = $key;
    }
}
