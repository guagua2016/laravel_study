<?php namespace App\Libraries;

use Log;

trait JobStatus
{
    public function status($rc, $message = 'Succeed', $ctx = [])
    {
        return [$rc, $message, $ctx];
    }

    public function xinfo($message, $ctx = [], $rc = true)
    {
        Log::info($message, $ctx);
        if (empty($ctx)) {
            printf("%s\n", $message);
        } else {
            printf("%s: %s\n", $message, json_encode($ctx));
        }
        return [$rc, $message, $ctx];
    }

    public function xwarn($message, $ctx = [], $rc = true)
    {
        Log::warning($message, $ctx);
        if (empty($ctx)) {
            printf("warning: %s\n", $message);
        } else {
            printf("warning: %s: %s\n", $message, json_encode($ctx));
        }
        return [$rc, $message, $ctx];
    }

    public function xerror($message, $ctx = [], $rc = false)
    {
        Log::error($message, $ctx);
        if (empty($ctx)) {
            printf("error: %s\n", $message);
        } else {
            printf("error: %s: %s\n", $message, json_encode($ctx));
        }
        return [$rc, $message, $ctx];
    }
}
