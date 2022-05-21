<?php

namespace basicinterpreter;

require_once 'utils.php';

function &tokenize($line) {
    $len = strlen($line);
    $res = [];
    while (1) {
        $line = preg_replace('/^\s+/', '', $line);
        if ($line === '') {
            break;
        }
        $m = [];
        if (preg_match('/^[a-z][a-z0-9]*\$?/i', $line, $m)) {
            $type = 'w';
            $m[0] = strtoupper($m[0]);
        } elseif (preg_match('/^[0-9]+/', $line, $m)) {
            $type = 'n';
        } elseif (preg_match('/^(?:>=|<=|<>|>|<|=|\+|-|\*|\/|\^)/', $line, $m)) {
            $type = 'o';
        } elseif (preg_match('/^[,;:\(\)]/', $line, $m)) {
            $type = 'p';
        } elseif ($line[0] == '"') {
            $type = 'e';
            $pos = 1;
            while ($pos < strlen($line)) {
                $pos = strpos($line, '"', $pos);
                if ($pos === false) {
                    break;
                }
                $pos++;
                if ($pos >= strlen($line) || $line[$pos] != '"') {
                    $m[] = substr($line, 0, $pos);
                    $type = 'q';
                    break;
                }
                $pos++;
            }
            if ($type == 'e') {
                $m[] = 'Unclosed string literal';
            }
        } else {
            $type = 'e';
            $m[] = 'Unrecognized character at position ' . ($len - strlen($line) + 1);
        }
        $match = $m[0];
        $line = substr($line, strlen($match));
        if ($type == 'q') {
            $match = substr($match, 1, -1);
        }
        $res[] = "$type$match";
        if ($type == 'e') {
            break;
        }
    }
    return $res;
}

function expectToken($token, $expected, $errmsg) {
    if (is_array($token)) {
        $token = $token ? $token[0] : '';
    }
    if (!is_array($expected)) {
        if ($token === $expected) return;
    } else {
        foreach ($expected as $exp) {
            if ($token === $exp) return;
        }
    }
    expectTokenFailed($token, $errmsg);
}

function expectTokenType($token, $expected, $errmsg) {
    if (is_array($token)) {
        $token = $token ? $token[0] : '?';
    }
    if (strpos($expected, $token[0]) === false) {
        expectTokenFailed($token, $errmsg);
    }
}

function expectTokenFailed($token, $errmsg) {
    if ($token[0] == 'e') {
        $errmsg = tokenBody($token);
    }
    throwLineError($errmsg);
}
