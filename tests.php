<?php

$dirname = './test';

$testdir = opendir($dirname);
while (1) {
    $entry = readdir($testdir);
    if (!$entry) {
        break;
    }
    if (!preg_match('/.*\.bas$/', $entry)) {
        continue;
    }
    $name = substr($entry, 0, -4);
    $inname = "$dirname/$name.in";
    if (!file_exists($inname)) {
        $inname = '';
    }
    $expcode = 0;
    $codename = "$dirname/$name.x";
    if (file_exists($codename)) {
        $expcode = intval(file_get_contents($codename));
    }
    $outname = "$dirname/$name.out";
    echo "$entry -> ";
    if (!file_exists($outname)) {
        echo "---\n";
        continue;
    }
    $expected = explode("\n", file_get_contents($outname));
    foreach ($expected as &$line) {
        $line = rtrim($line);
    }
    $expected = rtrim(implode("\n", $expected));
    $output = [];
    $code = 0;
    $cmd = "php main.php $dirname/$entry";
    if ($inname) {
        $cmd .= " < $inname";
    }
    exec($cmd, $output, $code);
    $output = implode("\n", $output);
    if ($code != $expcode) {
        echo "wrong exit code: $code";
    } elseif ($output != $expected) {
        echo "output differs";
    } else {
        echo "OK";
    }
    echo "\n";
}
closedir($testdir);
