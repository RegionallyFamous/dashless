<?php
/** Pure parser checks; no WordPress installation or process mutation. */
define('ABSPATH',__DIR__.'/');
require_once __DIR__.'/../../wordpress/hosted/Support.php';
require_once __DIR__.'/../../wordpress/hosted/Runtime.php';
foreach(["pid 12's current affinity list: 0-4\n"=>0,"pid 12's current affinity list: 6,8-11\n"=>6,"pid 12's current affinity list: 23\n"=>23] as $text=>$expected){
    if(\Dashless\Site\Runtime::firstAllowedCpu($text)!==$expected)throw new RuntimeException('Wrong CPU');
}
foreach(['','pid 12: ','pid 12: -1','pid 12: 0; echo unsafe','pid 12: 0-4 garbage'] as $text){
    try{\Dashless\Site\Runtime::firstAllowedCpu($text);throw new RuntimeException('Accepted invalid CPU list');}catch(\Dashless\Site\Failure $e){if($e->slug!=='runtime_unavailable')throw $e;}
}
echo "PASS CPU affinity: first allowed CPU selected; malformed output fails closed (8 checks)\n";
