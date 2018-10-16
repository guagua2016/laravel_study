<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Exercise\TraitTry;
use App\Exercise\Container;
use App\Exercise\ContainerTester;
use App\Exercise\XiaoFang;
use App\TsOrderFund;
use App\Exercise\Train;
use App\Exercise\Shoes;
use App\Exercise\Skirt;
use App\Exercise\Fire;
use Closure;
use Artisan;

class FirstController extends Controller
{
    public function index(Request $request)
    {
        $command = $request->input('c');
        $key = $request->input('p');
        if ($command == 'tt:array') {
            $result = Artisan::call('tt:array');
            return $result;
        } elseif ($command == 'trait') {
            $obj = new TraitTry();
            $obj->hello();
            return;
        } elseif ($command == 'container') {
            ContainerTester::test();
            //$ins = new \ReflectionClass('TsOrderFund');
            //$app = new Container();
            //$app->bind("Visit","Train"); //没有回调函数，产生默认的回调函数
            //$app->bind("traveller","Traveller");

            //$tra = $app->make("traveller");
            //$tra->visitTibet();
            return;
        } elseif ($command == 'decorator') {
            $api = resolve('HelpSpot\API');
            dd($api);
            $xf = new XiaoFang('小芳');
            $shoes = new Shoes($xf);
            $skirt = new Skirt($shoes);
            $fire = new Fire($skirt);
            $fire->display();
            return;
        }
        else{
            return 'Unknown command!';
        }
	    return 'i love budda! I am '.$command.'   '.$key;
    }
}
