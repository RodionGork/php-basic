<?php

require_once 'basic.php';

$basic = new \basicinterpreter\BasicInterpreter();

$file = count($argv) > 1 ? $argv[1] : 'php://stdin';
$lines = explode("\n", file_get_contents($file));

$basic->parseLines($lines);

//var_export($basic->code); echo "\n";

if (!$basic->errors) {
    $err = $basic->run();
    if ($err) {
        echo "$err\n";
    }
} else {
    echo "Code not executed because of parse errors:\n";
    foreach ($basic->errors as $err) {
        echo "    $err\n";
    }
}

/*

expr: andop
orop: andop [or andop]*
andop: cmp [and cmp]*
cmp: sum [relop sum]
sum:  prod [+ or - prod]*
prod: pwr [* or / pwr]*
pwr: value [^ pwr]?
value: [-]pvalue
pvalue: num or (expr) or func(expr) or var or var(subscr)
subscr: expr [, expr]*
*/
