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