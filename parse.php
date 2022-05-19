<?php

namespace basicinterpreter;

require_once 'utils.php';

const relops = ['o=', 'o>', 'o<', 'o>=', 'o<=', 'o<>'];

function stripLabel(&$tokens) {
    $tkn0 = $tokens[0];
    $type0 = $tkn0[0];
    if ($type0 == 'n') {
        $remove = 1;
    } elseif ($type0 == 'w' && count($tokens) > 1 && $tokens[1] == 'p:') {
        $remove = 2;
    } else {
        return null;
    }
    array_splice($tokens, 0, $remove);
    return strtoupper(tokenBody($tkn0));
}

function takeStatement(&$tokens) {
    $cmd = $tokens[0];
    expectTokenType($cmd, 'w', 'Command or Variable expected');
    $cmd = strtoupper(tokenBody($cmd));
    switch ($cmd) {
        case 'REM':
            $res = array_splice($tokens, 0);
            return array_slice($res, 0, 1);
        case 'END':
        case 'RETURN':
            return array_splice($tokens, 0, 1);
        case 'IF':
            return takeIfThen($tokens);
        case 'GOTO':
            return takeGoto($tokens);
        case 'NEXT':
            return takeNext($tokens);
        case 'PRINT':
            return takePrint($tokens);
        case 'LET':
            array_shift($tokens);
        default:
            return takeAssign($tokens);
    }
}

function takePrint(&$tokens) {
    $res = [array_shift($tokens)];
    $nextExpr = true;
    while ($tokens && $tokens[0] != 'p:') {
        $delim = '';
        if ($nextExpr) {
             $res[] = takeExpr($tokens);
        } else {
            $delim = array_shift($tokens);
            expectToken($delim, ['p,', 'p;'], 'Delimiter in PRINT expected');
            if ($delim === 'p,') {
                $res[] = ['q '];
            }
        }
        $nextExpr = !$nextExpr;
    }
    if ($delim !== 'p;') {
        $res[] = ["q\n"];
    }
    return $res;
}

function takeIfThen(&$tokens) {
    $res = [array_shift($tokens)];
    $res[] = takeExpr($tokens);
    expectToken($tokens, 'wTHEN', 'THEN expected after IF and expression');
    $tokens[0] = 'p:';
    return $res;
}

function takeGoto(&$tokens) {
    $res = [array_shift($tokens)];
    if (!$tokens) {
        throwLineError('GOTO without label');
    }
    if ($tokens[0][0] == 'w') {
        $res[] = ['q' . strtoupper(tokenBody(array_shift($tokens)))];
    } else {
        $res[] = takeExpr($tokens);
    }
    return $res;
}

function takeNext(&$tokens) {
    if (count($tokens) < 2) {
        throwLineError('NEXT without variable');
    }
    expectTokenType($tokens[1], 'w', 'Garbage instead of variable in NEXT');
    return array_splice($tokens, 0, 2);
}

function takeAssign(&$tokens) {
    if (count($tokens) < 3) {
        throwLineError('Unexpected end of statement');
    }
    $var = takeVariable($tokens);
    if (!$tokens) {
        throwLineError('Unexpected end of assignment');
    }
    expectToken($tokens, 'o=', 'Assignment operator expected');
    array_shift($tokens);
    $expr = takeExpr($tokens);
    return ['wLET', $expr, $var];
}

function takeVariable(&$tokens) {
    expectTokenType($tokens[0], 'w', 'Variable expected');
    $name = strtoupper(array_shift($tokens));
    if (!$tokens || $token[0] != 'p(') {
        $name[0] = 'v';
        return $name;
    }
    array_shift($tokens);
    $name[0] = 'a';
    $subscripts = [takeExpr($tokens)];
    while (1) {
        if (!$tokens) {
            throwLineError('Unexpected end of array subscripts');
        }
        expectToken($tokens[0], ['p,', 'p)'], 'Garbage in array subscripts');
        if ($tokens[0] == 'p)') {
            break;
        }
        $subscripts[] = takeExpr($tokens);
    }
    return [$name, $subscripts];
}

function takeExpr(&$tokens) {
    return takeExprCmp($tokens);
}

function takeExprCmp(&$tokens) {
    $res = takeExprSum($tokens);
    if ($tokens && in_array($tokens[0], relops)) {
        $op = array_shift($tokens);
        $add = takeExprSum($tokens);
        array_add($res, $add);
        $res[] = $op;
    }
    return $res;
}

function takeExprSum(&$tokens) {
    $res = takeExprMul($tokens);
    while ($tokens && $tokens[0] == 'o+' || $tokens[0] == 'o-') {
        $op = array_shift($tokens);
        $mul = takeExprMul($tokens);
        array_add($res, $mul);
        $res[] = $op;
    }
    return $res;
}

function takeExprMul(&$tokens) {
    $res = takeExprVal($tokens);
    while ($tokens && $tokens[0] == 'o*' || $tokens[0] == 'o/') {
        $op = array_shift($tokens);
        $val = takeExprVal($tokens);
        array_add($res, $val);
        $res[] = $op;
    }
    return $res;
}

function takeExprVal(&$tokens) {
    if (!$tokens) {
        throwLineError('Expression expected');
    }
    switch ($tokens[0][0]) {
        case 'n':
        case 'q':
            return [array_shift($tokens)];
        case 'w':
            return [takeVariable($tokens)];
        default:
            if ($tokens[0] == 'p(') {
                return takeSubExpr($tokens);
            } elseif ($tokens[0] == 'o-') {
                return takeNegVal($tokens);
            } else {
                throwLineError('Broken expression syntax');
            }
    }
}

function takeSubExpr(&$tokens) {
    array_shift($tokens);
    $expr = takeExpr($tokens);
    if (!$tokens || $tokens[0] != 'p)') {
        throwLineError('Missing closing parenthesis ")"');
    }
    array_shift($tokens);
    return $expr;
}

function takeNegVal(&$tokens) {
    array_shift($tokens);
    if ($tokens && $tokens[0] == 'o-') {
        throwLineError('Unexpected extra minus sign');
    }
    $res = ['n0'];
    array_add($res, takeExprVal($tokens));
    $res[] = 'o-';
    return $res;
}

