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

function isSpace($c) {
    return strpos(" \t\r\n", $c) !== false;
}

function scanInput() {
    $val = '';
    while (1) {
        $c = fgetc(STDIN);
        if ($c === false) {
            throwLineError('Unexpected end of input');
        }
        if (!isSpace($c)) {
            $val = $c;
            break;
        }
    }
    while (1) {
        $c = fgetc(STDIN);
        if ($c === false || isSpace($c)) {
            break;
        }
        $val .= $c;
    }
    return is_numeric($val) ? floatval($val) : $val;
}
