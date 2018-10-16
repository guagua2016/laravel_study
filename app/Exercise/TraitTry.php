<?php

namespace App\Exercise;

use App\Libraries\TsHelper;

use Log;

class Base
{
    public function hello()
    {
        echo 'method hello from class Base'.'<br>';
    }
}

trait Hello
{
    public function hello()
    {
        echo 'method hello from Trait Hello!'.'<br>';
    }

    public function hi()
    {
        echo 'method hi from Trait Hello'.'<br>';
    }

    abstract public function getValue();

    static public function staticMethod()
    {
        echo 'static method staticMethod from Trait Hello'.'<br>';
    }

    public function staticValue()
    {
        static $value;
        $value++;
        echo "$value".'<br>';
    }
}


trait Hi
{
    public function hello()
    {
        parent::hello();
        echo 'method hello from Trait Hi!'.'<br>';
    }

    public function hi()
    {
        echo 'method hi from Trait Hi'.'<br>';
    }
}

trait HelloHi
{
    use Hello, Hi{
        Hello::hello insteadof Hi;
        Hi::hi insteadof Hello;
    }
}

class TraitTry extends Base
{
    use HelloHi;
    private $value = 'class TraitTry'.'<br>';

    public function hi()
    {
        echo 'method hi from class MyNew'.'<br>';
    }

    public function getValue()
    {
        return $this->value;
    }
    
    public function __construct()
    {
       
    }
}


