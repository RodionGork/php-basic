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
        try {
            while ($pc < count($this->code)) {
                $lineNum = $this->sourceLineNums[$pc];
                $line = &$this->code[$pc++];
                foreach ($line as &$stmt) {
                    switch ($stmt[0]) {
                        case 'LET':
                            $this->execAssign($stmt);
                            break;
                        case 'IF':
                            if (!$this->execIfThen($stmt)) {
                                continue 3;
                            }
                            break;
                        case 'GOTO':
                            $this->execGoto($stmt, $pc);
                            break;
                        case 'REM':
                            break;
                        case 'END':
                            return;
                        case 'PRINT':
                            $this->execPrint($stmt);
                            break;
                        default:
                            throwLineError("Command not implemented: {$stmt[0]}\n");
                    }
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

    function execAssign(&$stmt) {
        $value = $this->evalExpr($stmt[1]);
        $this->vars[tokenBody($stmt[2])] = $value;
    }

    function execIfThen(&$stmt) {
        $res = $this->evalExpr($stmt[1]);
        return ($res !== 0 && $res !== '' && $res !== false);
    }

    function execGoto(&$stmt, &$pc) {
        $label = $this->evalExpr($stmt[1]);
        if (!isset($this->labels[$label])) {
            throwLineError("No such label: $label");
        }
        $pc = $this->labels[$label];
    }

    function evalExpr(&$expr) {
        $stack = [];
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
            }
        }
        return $stack[0];
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

function logicRes($y) {
    return (!!$y) ? 1 : 0;
}
