<?php

namespace basicinterpreter;

require_once 'utils.php';
require_once 'tokens.php';
require_once 'parse.php';

class BasicInterpreter {

    public $code = [];
    public $readdata = [];
    public $readptr = 0;
    public $labels = [];
    public $errors = [];
    public $vars = [];
    public $arrays = [];
    public $dims = [];
    public $callStack = [];
    public $sourceLineNums = [];
    private $binaryOps = [];
    private $funcs = [];
    private $inputStream = null;

    function __construct() {
        $this->setupBinaryOps();
        $this->setupFunctions();
    }

    function parseLines(&$lines) {
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            try {
                $tokens = tokenize($line);
                if (count($tokens) == 0) {
                    continue;
                }
                $label = stripLabel($tokens);
                if ($label) {
                    $this->labels[$label] = count($this->code);
                }
                $out = [];
                while (count($tokens) > 0) {
                    $stmt = takeStatement($tokens);
                    if ($stmt) {
                        $stmt[0] = tokenBody($stmt[0]);
                        $out[] = $stmt;
                    }
                    if ($tokens) {
                        $delim = array_shift($tokens);
                        expectToken($delim, 'p:', 'Extra tokens at the end of statement');
                    }
                }
                if ($out) {
                    $this->sourceLineNums[count($this->code)] = $i + 1;
                    $this->code[] = $out;
                }
            } catch (SyntaxException $e) {
                $this->errors[] = 'Line #' . ($i + 1) . ": {$e->getMessage()}";
            }
        }
    }

    function run($input = null, $limit = INF) {
        $this->setInput($input);
        $errData = $this->extractData();
        if ($errData) {
            return $errData;
        }
        return $this->executeCode($limit);
    }

    function setInput($input) {
        $this->inputStream = $input == null ? STDIN
            : fopen('data://text/plain;base64,' . base64_encode($input), 'r');
    }

    function executeCode($limit) {
        $opcount = 0;
        $pc = 0;
        $pc2 = 0;
        try {
            while ($pc < count($this->code)) {
                if ($opcount++ >= $limit) {
                    throwLineError("Execution limit reached: $limit statements");
                }
                $lineNum = $this->sourceLineNums[$pc];
                $line = &$this->code[$pc];
                $stmt = &$line[$pc2];
                if (++$pc2 >= count($line)) {
                    $pc++;
                    $pc2 = 0;
                }
                switch ($stmt[0]) {
                    case 'LET':
                        $this->execAssign($stmt);
                        break;
                    case 'IF':
                        $this->execIfThen($stmt, $pc, $pc2);
                        break;
                    case 'FOR':
                        $this->execFor($stmt, $pc, $pc2);
                        break;
                    case 'NEXT':
                        $this->execNext($stmt, $pc, $pc2);
                        break;
                    case 'GOTO':
                        $this->execGoto($stmt, $pc, $pc2);
                        break;
                    case 'GOSUB':
                        $this->execGosub($stmt, $pc, $pc2);
                        break;
                    case 'RETURN':
                        $this->execReturn($stmt, $pc, $pc2);
                    case 'REM':
                    case 'DATA':
                        break;
                    case 'PRINT':
                        $this->execPrint($stmt);
                        break;
                    case 'INPUT':
                        $this->execInput($stmt);
                        break;
                    case 'READ':
                        $this->execRead($stmt);
                        break;
                    case 'RESTORE':
                        $this->execRestore($stmt);
                        break;
                    case 'DIM':
                        $this->execDim($stmt);
                        break;
                    case 'DEF':
                        $this->execDef($stmt);
                        break;
                    case 'END':
                        return;
                    default:
                        throwLineError("Command not implemented: {$stmt[0]}\n");
                }
            }
        } catch (SyntaxException $e) {
            return "Runtime error (line #$lineNum): {$e->getMessage()}";
        }
        return null;
    }

    function execPrint(&$stmt) {
        for ($i = 1; $i < count($stmt); $i++) {
            echo $this->evalExpr($stmt[$i]);
        }
    }

    function execInput(&$stmt) {
        for ($i = 1; $i < count($stmt); $i++) {
            $expr = $stmt[$i];
            if (is_string($expr)) {
                echo tokenBody($expr);
            } else {
                $this->setVariable($expr, scanInput($this->inputStream));
            }
        }
    }

    function execRead(&$stmt) {
        for ($i = 1; $i < count($stmt); $i++) {
            if ($this->readptr >= count($this->readdata)) {
                throwLineError('Out of data for READ');
            }
            $expr = $stmt[$i];
            $this->setVariable($expr, $this->readdata[$this->readptr++]);
        }
    }

    function execRestore(&$stmt) {
        $this->readptr = 0;
    }

    function execAssign(&$stmt) {
        $value = $this->evalExpr($stmt[1]);
        $this->setVariable($stmt[2], $value);
    }

    function setVariable(&$expr, $value) {
        $name = tokenBody($expr[0]);
        if (count($expr) == 1) {
            $this->vars[$name] = $value;
        } else {
            $stack = [];
            $this->evalExpr($expr[1], $stack);
            $idx = $this->arrayIndex($name, $stack);
            $this->arrays[$name][$idx] = $value;
        }
    }

    function getVariable($name) {
        $value = $this->vars[$name];
        if ($value === null) {
            throwLineError("Variable not defined: $name");
        }
        return $value;
    }

    function execIfThen(&$stmt, &$pc, &$pc2) {
        $res = $this->evalExpr($stmt[1]);
        if ($res !== 0 && $res !== '' && $res !== false) {
            return;
        }
        $pc++;
        $pc2 = 0;
    }

    function execFor(&$stmt, $pc, $pc2) {
        $var = tokenBody($stmt[1]);
        $from = $this->evalExpr($stmt[2]);
        $till = $this->evalExpr($stmt[3]);
        $step = count($stmt) > 4 ? $this->evalExpr($stmt[4]) : 1;
        if ($step <= 0) {
            throwLineError('Negative or zero STEP in FOR is not allowed');
        }
        $this->callStack[] = ['f', $var, $step, $till, $pc, $pc2];
        $this->vars[$var] = $from;
    }

    function execNext(&$stmt, &$pc, &$pc2) {
        $frame = null;
        if ($this->callStack) {
            $frame = $this->callStack[count($this->callStack) - 1];
        }
        if ($frame === null || array_shift($frame) != 'f') {
            throwLineError('NEXT without preceding FOR execution');
        }
        list($var, $step, $till, $pcnext, $pc2next) = $frame;
        if ($var != tokenBody($stmt[1])) {
            throwLineError('NEXT for wrong variable, expected: ' . $var);
        }
        $value = $this->vars[$var] + $step;
        if ($value > $till) {
            array_pop($this->callStack);
            return;
        }
        $this->vars[$var] = $value;
        $pc = $pcnext;
        $pc2 = $pc2next;
    }

    function execGoto(&$stmt, &$pc, &$pc2) {
        $label = $this->evalExpr($stmt[1]);
        if (!isset($this->labels[$label])) {
            throwLineError("No such label: $label");
        }
        $pc = $this->labels[$label];
        $pc2 = 0;
    }

    function execGosub(&$stmt, &$pc, &$pc2) {
        $this->callStack[] = ['c', $pc, $pc2];
        $this->execGoto($stmt, $pc, $pc2);
    }

    function execReturn(&$stmt, &$pc, &$pc2) {
        while ($this->callStack) {
            $elem = array_pop($this->callStack);
            if ($elem[0] == 'c') {
                $pc = $elem[1];
                $pc2 = $elem[2];
                return;
            }
        }
        throwLineError('RETURN executed but there was no GOSUB before');
    }

    function execDim(&$stmt) {
        for ($i = 1; $i < count($stmt); $i++) {
            $expr = $stmt[$i];
            $var = tokenBody($expr[count($expr) - 1]);
            if (array_key_exists($var, $this->arrays)) {
                throwLineError("Array '$var' already exists");
            }
            $expr = array_slice($expr, 0, count($expr) - 1);
            $dims = [];
            $this->evalExpr($expr, $dims);
            $this->checkSubscripts($dims);
            $this->dims[$var] = $dims;
            $p = 1;
            foreach ($dims as $d) {
                $p *= $d;
            }
            $this->arrays[$var] = array_fill(0, $p, 0);
        }
    }

    function checkSubscripts(&$subs, &$dims = null) {
        foreach ($subs as $v) {
            if ($v != intval($v)) {
                throwLineError("Non-integer array index: $v");
            }
            if ($v < 0) {
                throwLineError("Negative array index: $v");
            }
        }
        if ($dims === null) {
            return;
        }
        $n = count($dims);
        $ns = count($subs);
        if ($ns < $n) {
            throwLineError("Not enough subscripts, should be $n");
        }
        $offs = $ns - $n;
        for ($i = 0; $i < $n; $i++) {
            if ($subs[$i + $offs] >= $dims[$i]) {
                throwLineError("Index#$i out of range: {$subs[$i]}");
            }
        }
    }

    function execDef(&$stmt) {
        $name = $stmt[1];
        if ($this->funcs[$name]) {
            throwLineError("Function $name already defined");
        }
        $expr = $stmt[2];
        $this->funcs[$stmt[1]] = function($x) use ($expr) {
            $saved = $this->vars['_1'];
            $this->vars['_1'] = $x;
            $res = $this->evalExpr($expr);
            if ($saved !== null) {
                $this->vars['_1'] = $saved;
            }
            return $res;
        };
    }

    function evalExpr(&$expr, &$stack = null) {
        if ($stack === null) {
            $stack = [];
        }
        foreach ($expr as &$v) {
            $body = tokenBody($v);
            if ($v[0] == 'n') {
                $stack[] = floatval($body);
            } elseif ($v[0] == 'q') {
                $stack[] = $body;
            } elseif ($v[0] == 'v') {
                $stack[] = $this->getVariable($body);
            } elseif ($v[0] == 'o') {
                $v2 = array_pop($stack);
                $v1 = array_pop($stack);
                $stack[] = call_user_func($this->binaryOps[$body], $v1, $v2);
            } elseif ($v[0] == 'f') {
                $v1 = array_pop($stack);
                $stack[] = call_user_func($this->funcs[$body], $v1);
            } elseif ($v[0] == 'a') {
                $name = tokenBody($v);
                $idx = $this->arrayIndex($name, $stack);
                $arrsz = count($this->dims[$name]);
                array_splice($stack, -$arrsz, $arrsz, $this->arrays[$name][$idx]);
            }
        }
        return $stack[0];
    }

    function arrayIndex($name, &$subs) {
        if (!array_key_exists($name, $this->arrays)) {
            throwLineError("Array not defined: $name");
        }
        $dims = &$this->dims[$name];
        $this->checkSubscripts($subs, $dims);
        $offs = count($subs) - count($dims);
        $idx = $subs[$offs];
        for ($i = 1; $i < count($dims); $i++) {
            $idx = $idx * $dims[$i] + $subs[$i + $offs];
        }
        return $idx;
    }

    function extractData() {
        try {
            foreach ($this->code as $num => &$line) {
                $lineNum = $this->sourceLineNums[$num];
                $stmt = &$line[0];
                if ($stmt[0] == 'DATA') {
                    for ($i = 1; $i < count($stmt); $i++) {
                        $this->readdata[] = $this->evalExpr($stmt[$i]);
                    }
                }
                for ($i = 1; $i < count($line); $i++) {
                    if ($line[$i][0] == 'DATA') {
                        throwLineError('DATA statement should be first in its line');
                    }
                }
            }
        } catch (SyntaxException $e) {
            return "Runtime error (line #$lineNum): {$e->getMessage()}";
        }
        return null;
    }

    function setupBinaryOps() {
        $this->binaryOps['+'] = function($a, $b) {
            return is_string($a) || is_string($b) ? $a . $b : $a + $b; };
        $this->binaryOps['-'] = function($a, $b) { return $a - $b; };
        $this->binaryOps['*'] = function($a, $b) { return $a * $b; };
        $this->binaryOps['/'] = function($a, $b) { return $a / $b; };
        $this->binaryOps['%'] = function($a, $b) { return $a % $b; };
        $this->binaryOps['^'] = function($a, $b) { return pow($a, $b); };
        $this->binaryOps['<'] = function($a, $b) { return logicRes($a < $b); };
        $this->binaryOps['>'] = function($a, $b) { return logicRes($a > $b); };
        $this->binaryOps['='] = function($a, $b) { return logicRes($a == $b); };
        $this->binaryOps['<='] = function($a, $b) { return logicRes($a <= $b); };
        $this->binaryOps['>='] = function($a, $b) { return logicRes($a >= $b); };
        $this->binaryOps['<>'] = function($a, $b) { return logicRes($a != $b); };
        $this->binaryOps['&'] = function($a, $b) { return logicRes($a && $b); };
        $this->binaryOps['|'] = function($a, $b) { return logicRes($a || $b); };
    }

    function setupFunctions() {
        $this->funcs['ABS'] = function($x) { return $x >= 0 ? $x : -$x; };
        $this->funcs['ATN'] = function($x) { return atan($x); };
        $this->funcs['COS'] = function($x) { return cos($x); };
        $this->funcs['EXP'] = function($x) { return exp($x); };
        $this->funcs['INT'] = function($x) { return intval($x); };
        $this->funcs['LOG'] = function($x) { return log($x); };
        $this->funcs['RND'] = function($x) { return rand() / (getrandmax() + 1); };
        $this->funcs['SIN'] = function($x) { return sin($x); };
        $this->funcs['SGN'] = function($x) { return ($x > 0) - ($x < 0); };
        $this->funcs['SQR'] = function($x) { return sqrt($x); };
        $this->funcs['TAN'] = function($x) { return tan($x); };
    }

}
