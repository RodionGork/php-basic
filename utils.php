<?php

namespace basicinterpreter;

class SyntaxException extends \Exception {
}

function tokenBody($token) {
    return substr($token, 1);
}

function throwLineError($msg) {
    throw new SyntaxException($msg);
}

function array_add(&$a, $b) {
    array_splice($a, count($a), 0, $b);
}

function logicRes($y) {
    return (!!$y) ? 1 : 0;
}

function scanInput($inputStream, $stopOnSpace) {
    $stopChars = $stopOnSpace ? " \t\r\n" : "\r\n";
    $val = '';
    while (1) {
        $c = fgetc($inputStream);
        if ($c === false) {
            throwLineError('Unexpected end of input');
        }
        if (strpos($stopChars, $c) === false) {
            $val = $c;
            break;
        }
    }
    while (1) {
        $c = fgetc($inputStream);
        if ($c === false || strpos($stopChars, $c) !== false) {
            break;
        }
        $val .= $c;
    }
    return is_numeric($val) ? floatval($val) : $val;
}
