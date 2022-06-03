<?php

namespace basicinterpreter;

require_once 'utils.php';

const relops = ['o=', 'o>', 'o<', 'o>=', 'o<=', 'o<>'];

const funcArgCnt = ['ABS' =>1 , 'ATN' => 1, 'ASC' => 1, 'CHR' => 1, 'COS' => 1,
    'EXP' => 1, 'INT' => 1, 'LEFT' => 2, 'LEN' => 1, 'LOG' => 1, 'MID' => 3,
    'RIGHT' => 2, 'RND' => 1, 'SIN' => 1, 'SGN' => 1, 'SQR' => 1, 'TAN' => 1];

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
    return tokenBody($tkn0);
}

function takeStatement(&$tokens) {
    $cmd = $tokens[0];
    expectTokenType($cmd, 'w', 'Command or Variable expected');
    $cmd = tokenBody($cmd);
    switch ($cmd) {
        case 'REM':
            $res = array_splice($tokens, 0);
            return array_slice($res, 0, 1);
        case 'END':
        case 'RETURN':
        case 'RESTORE':
            return array_splice($tokens, 0, 1);
        case 'IF':
            return takeIfThen($tokens);
        case 'GOTO':
        case 'GOSUB':
            return takeGo($tokens);
        case 'FOR':
            return takeFor($tokens);
        case 'NEXT':
            return takeNext($tokens);
        case 'PRINT':
            return takePrint($tokens);
        case 'INPUT':
            return takeInput($tokens);
        case 'DIM':
            return takeDim($tokens);
        case 'DEF':
            return takeDef($tokens);
        case 'READ':
            return takeRead($tokens);
        case 'DATA':
            return takeData($tokens);
        case 'LET':
            array_shift($tokens);
        default:
            return takeAssign($tokens);
    }
}

function takePrint(&$tokens) {
    $res = [array_shift($tokens)];
    $nextExpr = true;
    $delim = '';
    while ($tokens && $tokens[0] != 'p:') {
        if ($nextExpr) {
            $res[] = takeExpr($tokens);
            $delim = '';
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

function takeInput(&$tokens, $isRead = false) {
    $res = [array_shift($tokens)];
    $cmd = tokenBody($res[0]);
    $nextExpr = true;
    $delim = '';
    while ($tokens && $tokens[0] != 'p:') {
        if ($nextExpr) {
            if ($tokens[0][0] != 'q' || $isRead) {
                $res[] = takeLvalue($tokens);
            } else {
                $res[] = array_shift($tokens);
            }
        } else {
            $delim = array_shift($tokens);
            expectToken($delim, 'p,', "Comma in $cmd expected");
        }
        $nextExpr = !$nextExpr;
    }
    return $res;
}

function takeRead(&$tokens) {
    return takeInput($tokens, true);
}

function takeData(&$tokens) {
    $res = [array_shift($tokens)];
    $nextExpr = true;
    $delim = '';
    while ($tokens && $tokens[0] != 'p:') {
        if ($nextExpr) {
            $expr = takeExpr($tokens);
            $res[] = $expr;
        } else {
            $delim = array_shift($tokens);
            expectToken($delim, 'p,', 'Comma in DATA expected');
        }
        $nextExpr = !$nextExpr;
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

function takeGo(&$tokens) {
    $res = [array_shift($tokens)];
    if (!$tokens) {
        throwLineError(tokenBody($res[0]) . ' without label');
    }
    if ($tokens[0][0] == 'w') {
        $res[] = ['q' . tokenBody(array_shift($tokens))];
    } else {
        $res[] = takeExpr($tokens);
    }
    return $res;
}

function takeFor(&$tokens) {
    $res = [array_shift($tokens)];
    expectTokenType($tokens, 'w', 'Garbage instead of variable in FOR');
    $var = takeVariable($tokens);
    $res[] = $var[0];
    expectToken($tokens, 'o=', 'equals sign expected in FOR');
    array_shift($tokens);
    $res[] = takeExpr($tokens);
    expectToken($tokens, 'wTO', 'TO expected in FOR');
    array_shift($tokens);
    $res[] = takeExpr($tokens);
    if ($tokens && $tokens[0] == 'wSTEP') {
        array_shift($tokens);
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

function takeDim(&$tokens) {
    $res = [array_shift($tokens)];
    $res[] = takeVariable($tokens);
    while ($tokens && $tokens[0] == 'p,') {
        array_shift($tokens);
        $res[] = takeVariable($tokens);
    }
    return $res;
}

function takeDef(&$tokens) {
    $res = [array_shift($tokens)];
    expectTokenType($tokens, 'w', 'Custom function name expected after DEF');
    $name = tokenBody(array_shift($tokens));
    if (!preg_match('/^FN[A-Z]$/', $name)) {
        throwLineError('Custom function name should be FNx where x = A..Z');
    }
    $res[] = $name;
    expectToken(array_shift($tokens), 'p(', 'Expected "(" after function name');
    expectTokenType($tokens, 'w', 'Function argument name expected');
    $arg = array_shift($tokens);
    $arg[0] = 'v';
    expectToken(array_shift($tokens), 'p)', 'Expected ")" after function parameter');
    expectToken(array_shift($tokens), 'o=', 'Expected "=" after function name and parameter');
    $expr = takeExpr($tokens);
    foreach ($expr as &$elem) {
        if ($elem == $arg) {
            $elem = 'v_1';
        }
    }
    $res[] = $expr;
    return $res;
}

function takeAssign(&$tokens) {
    if (count($tokens) < 3) {
        throwLineError('Unexpected end of statement');
    }
    $var = takeLvalue($tokens);
    if (!$tokens) {
        throwLineError('Unexpected end of assignment');
    }
    expectToken($tokens, 'o=', 'Assignment operator expected');
    array_shift($tokens);
    $expr = takeExpr($tokens);
    $res = ['wLET', $expr, $var];
    return $res;
}

function takeVariable(&$tokens, $funcsAlso = false) {
    expectTokenType($tokens[0], 'w', 'Variable expected');
    $name = array_shift($tokens);
    $pureName = tokenBody($name);
    if (!$tokens || $tokens[0] != 'p(') {
        $name[0] = 'v';
        return [$name];
    }
    array_shift($tokens);
    $isFunc = isFuncName(tokenBody($name));
    if ($isFunc) {
        if (!$funcsAlso) {
            throwLineError("Function $pureName isn't expected here");
        }
        $name[0] = $isFunc > 1 ? 'F' : 'f';
        $msgPart = 'array subscripts';
    } else {
        $name[0] = 'a';
        $msgPart = 'function arguments';
    }
    $funcExpr = takeExpr($tokens);
    $subCnt = 1;
    while (1) {
        if (!$tokens) {
            throwLineError("Unexpected end of $msgPart");
        }
        expectToken($tokens[0], ['p,', 'p)'], "Garbage in $msgPart");
        if (array_shift($tokens) == 'p)') {
            break;
        }
        array_add($funcExpr, takeExpr($tokens));
        $subCnt++;
    }
    if ($isFunc != 0 && $isFunc !== $subCnt) {
        throwLineError("Function $pureName expects $isFunc argument(s)");
    }
    $funcExpr[] = $name;
    return $funcExpr;
}

function takeLvalue(&$tokens) {
    $var = takeVariable($tokens);
    $res = [array_pop($var)];
    if ($var) {
        $res[] = $var;
    }
    return $res;
}

function takeExpr(&$tokens) {
    return takeExprOr($tokens);
}

function takeExprOr(&$tokens) {
    $res = takeExprAnd($tokens);
    while ($tokens && $tokens[0] === 'wOR') {
        array_shift($tokens);
        $and = takeExprAnd($tokens);
        array_add($res, $and);
        $res[] = 'o|';
    }
    return $res;
}

function takeExprAnd(&$tokens) {
    $res = takeExprCmp($tokens);
    while ($tokens && $tokens[0] === 'wAND') {
        array_shift($tokens);
        $cmp = takeExprCmp($tokens);
        array_add($res, $cmp);
        $res[] = 'o&';
    }
    return $res;
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
    while ($tokens && ($tokens[0] == 'o+' || $tokens[0] == 'o-')) {
        $op = array_shift($tokens);
        $mul = takeExprMul($tokens);
        array_add($res, $mul);
        $res[] = $op;
    }
    return $res;
}

function takeExprMul(&$tokens) {
    $res = takeExprPwr($tokens);
    while ($tokens && ($tokens[0] == 'o*' || $tokens[0] == 'o/'
            || $tokens[0] === 'wMOD')) {
        $op = array_shift($tokens);
        $pwr = takeExprPwr($tokens);
        array_add($res, $pwr);
        $res[] = $op[0] == 'o' ? $op : 'o%';
    }
    return $res;
}

function takeExprPwr(&$tokens) {
    $res = takeExprVal($tokens);
    while ($tokens && $tokens[0] == 'o^') {
        $op = array_shift($tokens);
        $pwr = takeExprPwr($tokens);
        array_add($res, $pwr);
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
            return takeVariable($tokens, true);
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

function isFuncName($name) {
    if (array_key_exists($name, funcArgCnt)) {
        return funcArgCnt[$name];
    }
    return preg_match('/^FN[A-Z]/', $name) ? 1 : 0;
}
