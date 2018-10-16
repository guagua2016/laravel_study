<?php namespace App\Libraries;

use Log;

class ProgressAdvancer
{
    protected $progress;
    protected $step;
    protected $message;

    public function __construct($progress, $step, $message){
        $this->progress = $progress;
        $this->step = $step;
        $this->message = $message;
    }

    public function __destruct(){
        $this->progress->advance($this->step, $this->message);
    }
}
