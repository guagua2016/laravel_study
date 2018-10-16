<?php

namespace App\Exercise;

use Illuminate\Console\Command;

use Log;
use Closure;



interface VisitInterface
{
    public function go();
}

class Leg implements VisitInterface
{
    public function go()
    {
        echo "walt to Tibet!!!";
    }
}

class Car implements VisitInterface
{
    public function go()
    {
        echo "drive car to Tibet!!!";
    }
}

class Train implements VisitInterface
{
    public function go()
    {
        echo "go to Tibet by train!!!";
    }
}

class Traveller
{
    protected $trafficTool;
    public function __construct(VisitInterface $trafficInterface)
    {
        $this->trafficTool = $trafficInterface;
    }

    public function visitTibet()
    {
        $this->trafficTool->go();
    }
}


class Container
{
    protected $binds;
    protected $instances;

    public function bind($abstract,$concrete)
    {
        if ($concrete  instanceof Closure) {
             $this->binds[$abstract] = $concrete;
        } else {
            $this->instances[$abstract] = $concrete;
        }
    }

    public function make($abstract, $parameters=[])
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        //array_unshift($parameters,$this);
        return call_user_func_array($this->binds[$abstract],$parameters);
    }
}

class ContainerTester
{
    public function __construct()
    {
    }

    public static function test()
    {
        $containter = new Container;
        $f1 = function($containter,$moduleName) {
            return new Traveller($containter->make($moduleName));
        };
        //
        //创建Traveller类对象
        //
        $containter->bind('Traveller', function($containter,$moduleName) {
            return new Traveller($containter->make($moduleName));
        });

        //
        // 创建交通工具类对象
        //
        $containter->bind('Train',function($containter){
            return new Train;
        });

        //
        // 创建交通工具类对象
        //
        $containter->bind('Car',function($containter){
            return new Car;
        });

        //dd('++++');
        $travel_1 = $containter->make('Traveller',array('Car'));
        $travel_1->visitTibet();
        dd($travel_1);        
    }
}

// class Container
// {
//     protected $bindings = [];

//     public function bind($abstract, $concrete=null, $share=false)
//     {
//         if (!$concrete instanceof Closure) {
//             // 如果提供的不是回调函数，则产生默认的回调函数
//             $concrete = $this->getClosure($abstract,$concrete);
//             dd($concrete);
//         }
//         $this->bindings[$abstract] = compact('concrete','shared');
//     }

//     //
//     // 默认生成实例的回调函数
//     //
//     protected function getClosure($abstract, $concrete)
//     {
//         return function($c) use ($abstract, $concrete)
//         {
//             $method = ($abstract == $concrete) ? 'build' : 'make';
//             return $c->$method($concrete);
//         };
//     }

//     //
//     // 生成实例对象，首先解决接口和要实例类之间的依赖有关系
//     //
//     public function make($abstract)
//     {
//         $concrete = $this->getConcrete($abstract);
//         if ($this->isBuildable($concrete,$abstract)) {
//             $object = $this->build($concrete);
//         } else {
//             $object = $this->make($concrete);
//         }

//         return $object;
//     }

//     protected function isBuildable($concrete,$abstract)
//     {
//         return $concrete === $abstract || $concrete instanceof Closure;
//     }

//     //
//     // 获取绑定的回调函数
//     //
//     protected function getConcrete($abstract)
//     {
//         if (!isset($this->buildings[$abstract]))
//         {
//             return $abstract;
//         }

//         return $this->bindings[$abstract]['concrete'];
//     }

//     //
//     // 实例化对象
//     //
//     public function build($concrete)
//     {
//         if ($concrete instanceof Closure) {
//             return $concrete($this);
//         }

//         $reflector = new \ReflectionClass($concrete);
//         if (!$reflector->isInstantiable()) {
//             echo $message = "Target [$concrete] is not instantiable.";
//         }

//         $constructor = $reflector->getConstructor();
//         if ( is_null($constructor)) {
//             return new $concrete;
//         }

//         $dependencies = $constructor->getParameters();
//         $instances = $this->getDependencies($dependencies);
//         return $reflector->newInstanceArgs($instances);
//     }

//     //
//     // 解决通过反射机制实例对象时的依赖
//     //
//     protected function getDependencies($parameters)
//     {
//         $dependencies = [];
//         foreach ($parameters as $parameter) {
//             $dependency = $parameter->getClass();
//             if (is_null($dependency)) {
//                 $dependencies[] = NULL;
//             } else {
//                 $dependencies[] = $this->resolveClass($parameter);
//             }
//         }
//         return  (array)$dependencies;
//     }

//     protected function resolveClass(ReflectionParameter $parameter)
//     {
//         return $this->make($parameter->getClass()->name);
//     }
    
// }

