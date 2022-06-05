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
    private $dims = [];
    private $pc = 0;
    private $pc2 = 0;
    private $callStack = [];
    private $sourceLineNums = [];
    private $binaryOps = [];
    private $funcs = [];
    private $funcArgs = [];
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
                        if ($stmt[0] == 'DATA' && $out) {
                            throwLineError('DATA statement should be first in its line');
                        }
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
        $this->pc = 0;
        $this->pc2 = 0;
        try {
            while ($this->pc < count($this->code)) {
                if ($opcount++ >= $limit) {
                    throwLineError("Execution limit reached: $limit statements");
                }
                $lineNum = $this->sourceLineNums[$this->pc];
                $line = &$this->code[$this->pc];
                $stmt = &$line[$this->pc2];
                if (++$this->pc2 >= count($line)) {
                    $this->pc++;
                    $this->pc2 = 0;
                }
                //php 7.2+ optimizes this with jump table
                switch ($stmt[0]) {
                    case 'DATA':
                        break;
                    case 'DEF':
                        $this->execDef($stmt); break;
                    case 'DIM':
                        $this->execDim($stmt); break;
                    case 'END':
                        $this->pc = count($this->code); break;
                    case 'FOR':
                        $this->execFor($stmt); break;
                    case 'GOSUB':
                        $this->execGosub($stmt); break;
                    case 'GOTO':
                        $this->execGoto($stmt); break;
                    case 'IF':
                        $this->execIfThen($stmt); break;
                    case 'INPUT':
                        $this->execInput($stmt); break;
                    case 'LET':
                        $this->execAssign($stmt); break;
                    case 'NEXT':
                        $this->execNext($stmt); break;
                    case 'PRINT':
                        $this->execPrint($stmt); break;
                    case 'READ':
                        $this->execRead($stmt); break;
                    case 'REM':
                        break;
                    case 'RESTORE':
                        $this->execRestore($stmt); break;
                    case 'RETURN':
                        $this->execReturn($stmt); break;
                    default:
                        throwLineError("Command not implemented: {$stmt[0]}\n");
                }
            }
        } catch (SyntaxException $e) {
            return "Runtime error (line #$lineNum): {$e->getMessage()}";
        }
        return null;
    }

    function execAssign(&$stmt) {
        $value = $this->evalExpr($stmt[1]);
        $this->setVariable($stmt[2], $value);
    }

    function execDef(&$stmt) {
        $name = $stmt[1];
        if (array_key_exists($name, $this->funcs)) {
            throwLineError("Function $name already defined");
        }
        $expr = $stmt[2];
        $this->funcs[$stmt[1]] = function($x) use ($expr) {
            $saved = array_key_exists('_1', $this->vars) ? $this->vars['_1'] : null;
            $this->vars['_1'] = $x;
            $res = $this->evalExpr($expr);
            if ($saved !== null) {
                $this->vars['_1'] = $saved;
            }
            return $res;
        };
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

    function execFor(&$stmt) {
        $var = tokenBody($stmt[1]);
        $from = $this->evalExpr($stmt[2]);
        $till = $this->evalExpr($stmt[3]);
        $step = count($stmt) > 4 ? $this->evalExpr($stmt[4]) : 1;
        if ($step <= 0) {
            throwLineError('Negative or zero STEP in FOR is not allowed');
        }
        $this->callStack[] = ['f', $var, $step, $till, $this->pc, $this->pc2];
        $this->vars[$var] = $from;
    }

    function execGosub(&$stmt) {
        $this->callStack[] = ['c', $this->pc, $this->pc2];
        $this->execGoto($stmt);
    }

    function execGoto(&$stmt) {
        $label = $this->evalExpr($stmt[1]);
        if (!isset($this->labels[$label])) {
            throwLineError("No such label: $label");
        }
        $this->pc = $this->labels[$label];
        $this->pc2 = 0;
    }

    function execIfThen(&$stmt) {
        if (!logicRes($this->evalExpr($stmt[1]))) {
            $this->pc++;
            $this->pc2 = 0;
        }
    }

    function execInput(&$stmt) {
        $stopOnSpace = true;
        for ($i = 1; $i < count($stmt); $i++) {
            $expr = $stmt[$i];
            if (is_string($expr)) {
                if ($expr !== '') {
                    echo $expr;
                } else {
                    $stopOnSpace = false;
                }
            } else {
                $this->setVariable($expr, scanInput($this->inputStream, $stopOnSpace));
            }
        }
    }

    function execNext(&$stmt) {
        $frame = null;
        if ($this->callStack) {
            $frame = &$this->callStack[count($this->callStack) - 1];
        }
        if ($frame === null || $frame[0] != 'f') {
            throwLineError('NEXT without preceding FOR execution');
        }
        list($ff, $var, $step, $till, $pcnext, $pc2next) = $frame;
        if ($var != tokenBody($stmt[1])) {
            throwLineError('NEXT for wrong variable, expected: ' . $var);
        }
        $value = $this->vars[$var] + $step;
        if ($value > $till) {
            array_pop($this->callStack);
            return;
        }
        $this->vars[$var] = $value;
        $this->pc = $pcnext;
        $this->pc2 = $pc2next;
    }

    function execPrint(&$stmt) {
        for ($i = 1; $i < count($stmt); $i++) {
            echo $this->evalExpr($stmt[$i]);
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

    function execReturn(&$stmt) {
        while ($this->callStack) {
            $elem = array_pop($this->callStack);
            if ($elem[0] == 'c') {
                $this->pc = $elem[1];
                $this->pc2 = $elem[2];
                return;
            }
        }
        throwLineError('RETURN executed but there was no GOSUB before');
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
            } elseif ($v[0] == 'F') {
                $v1 = array_splice($stack, -funcArgCnt[$body]);
                $stack[] = call_user_func_array($this->funcs[$body], $v1);
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
        $this->funcs['ASC'] = function($x) { return ord($x); };
        $this->funcs['CHR'] = function($x) {
            return ($x == intval($x) && $x > 31 && $x < 128) ? chr($x) : ""; };
        $this->funcs['COS'] = function($x) { return cos($x); };
        $this->funcs['EXP'] = function($x) { return exp($x); };
        $this->funcs['INT'] = function($x) { return intval($x); };
        $this->funcs['LEFT'] = function($s, $len) {
            return substr($s, 0, $len); };
        $this->funcs['LEN'] = function($s) { return strlen($s); };
        $this->funcs['LOG'] = function($x) { return log($x); };
        $this->funcs['MID'] = function($s, $pos, $len) {
            return substr($s, $pos, $len); };
        $this->funcs['RIGHT'] = function($s, $len) {
            return substr($s, -$len); };
        $this->funcs['RND'] = function($x) { return rand() / (getrandmax() + 1); };
        $this->funcs['SIN'] = function($x) { return sin($x); };
        $this->funcs['SGN'] = function($x) { return ($x > 0) - ($x < 0); };
        $this->funcs['SQR'] = function($x) { return sqrt($x); };
        $this->funcs['TAN'] = function($x) { return tan($x); };
    }

    function exportParsedAsJson() {
        return json_encode(['code' => $this->code, 'labels' => $this->labels,
            'sourceLineNums' => $this->sourceLineNums, 'errors' => $this->errors]);
    }

}
