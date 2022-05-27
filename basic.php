<?php

namespace basicinterpreter;

require_once 'utils.php';
require_once 'tokens.php';
require_once 'parse.php';

class BasicInterpreter {

    public $code = [];
    public $labels = [];
    public $errors = [];
    public $vars = [];
    public $arrays = [];
    public $dims = [];
    public $callStack = [];
    public $sourceLineNums = [];
    private $binaryOps = [];
    private $funcs = [];

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

    function run() {
        $pc = 0;
        $pc2 = 0;
        try {
            while ($pc < count($this->code)) {
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
                    case 'GOTO':
                        $this->execGoto($stmt, $pc, $pc2);
                        break;
                    case 'GOSUB':
                        $this->execGosub($stmt, $pc, $pc2);
                        break;
                    case 'RETURN':
                        $this->execReturn($stmt, $pc, $pc2);
                    case 'REM':
                        break;
                    case 'PRINT':
                        $this->execPrint($stmt);
                        break;
                    case 'INPUT':
                        $this->execInput($stmt);
                        break;
                    case 'DIM':
                        $this->execDim($stmt);
                        break;
                    case 'END':
                        return;
                    default:
                        throwLineError("Command not implemented: {$stmt[0]}\n");
                }
            }
        } catch (SyntaxException $e) {
            echo "Runtime error (line #$lineNum): {$e->getMessage()}\n";
        }
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
                $this->setVariable($expr, scanInput());
            }
        }
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

    function execIfThen(&$stmt, &$pc, &$pc2) {
        $res = $this->evalExpr($stmt[1]);
        if ($res !== 0 && $res !== '' && $res !== false) {
            return;
        }
        $pc++;
        $pc2 = 0;
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
            if (!is_int($v)) {
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
        if (count($subs) != $n) {
            throwLineError("Wrong number of subscripts, should be $n");
        }
        for ($i = 0; $i < $n; $i++) {
            if ($subs[$i] >= $dims[$i]) {
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
                $stack[] = intval($body);
            } elseif ($v[0] == 'q') {
                $stack[] = $body;
            } elseif ($v[0] == 'v') {
                $stack[] = $this->vars[$body];
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
                array_splice($stack, 0, null, $this->arrays[$name][$idx]);
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
        $idx = $subs[0];
        for ($i = 1; $i < count($dims); $i++) {
            $idx = $idx * $dims[$i] + $subs[$i];
        }
        return $idx;
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
        $this->funcs['INT'] = function($x) { return floor($x); };
        $this->funcs['LOG'] = function($x) { return log($x); };
        $this->funcs['RND'] = function($x) { return rand() / (getrandmax() + 1); };
        $this->funcs['SIN'] = function($x) { return sin($x); };
        $this->funcs['SGN'] = function($x) { return ($x > 0) - ($x < 0); };
        $this->funcs['SQR'] = function($x) { return sqrt($x); };
        $this->funcs['TAN'] = function($x) { return tan($x); };
    }

}
