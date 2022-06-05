<?php

require_once 'basic.php';

$basic = new \basicinterpreter\BasicInterpreter();

$file = count($argv) > 1 ? $argv[1] : 'php://stdin';
$lines = explode("\n", file_get_contents($file));

$basic->parseLines($lines);

if (getenv('PARSED_JS')) {
    file_put_contents(getenv('PARSED_JS'), 'const parsed = ' . $basic->exportParsedAsJson() . ";\n");
}

if (!$basic->errors) {
    $err = $basic->run();
    if ($err) {
        echo "$err\n";
        exit(2);
    }
} else {
    echo "Code not executed because of parse errors:\n";
    foreach ($basic->errors as $err) {
        echo "    $err\n";
    }
    exit(1);
}
