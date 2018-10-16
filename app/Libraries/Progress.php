<?php namespace App\Libraries;

use Log;

class Progress
{
    private $key;
    private $time_start     =   0;
    private $time_last      =   0;

    private $finished;
    private $total;

    public function __construct($key, $total = 0){
        $this->finished = 0;
        $this->total = $total;
        $this->key = $key;
        $this->time_start= microtime(true);
        $this->time_last = $this->time_start;
    }

    public function __destruct(){
        $time_end = microtime(true);
        $time = $time_end - $this->time_start;
        Log::info(sprintf("[100%% in %d ms] %s", $time * 1000, $this->key));
    }

    public function total($total)
    {
        $this->total = $total;
    }


    public function advance($step, $message = null)
    {
        $this->finished += $step;

        $now = microtime(true);
        $elapsed = $now - $this->time_last;
        if ($elapsed > 1) {
            if (!$message) {
                $message = $this->key;
            }

            if ($this->total != 0) {
                $percent = 100.0 * $this->finished / $this->total;
                Log::info(sprintf("[%.1f%% in %d ms] %s", $percent, $elapsed * 1000, $message));
            } else {
                Log::info(sprintf("[%d ms] %s", $elapsed * 1000, $message));
            }

            $this->time_last = $now;
        }
    }
}
