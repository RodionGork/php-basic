<?php

require_once 'basic.php';

$basic = new \basicinterpreter\BasicInterpreter();

$lines = explode("\n", file_get_contents('php://stdin'));

$basic->parseLines($lines);

//var_export($basic->labels);
//var_export($basic->code);

if (!$basic->errors) {
    $basic->run();
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
