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

    function __construct() {
        $this->setupBinaryOps();
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
                        $stmt[0] = strtoupper(tokenBody($stmt[0]));
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
            }
        }
        return $stack[0];
    }
    
    function setupBinaryOps() {
        $this->binaryOps['+'] = function($a, $b) { return $a + $b; };
        $this->binaryOps['-'] = function($a, $b) { return $a - $b; };
        $this->binaryOps['*'] = function($a, $b) { return $a * $b; };
        $this->binaryOps['/'] = function($a, $b) { return $a / $b; };
        $this->binaryOps['^'] = function($a, $b) { return $a ^ $b; };
        $this->binaryOps['<'] = function($a, $b) { return $a < $b; };
        $this->binaryOps['>'] = function($a, $b) { return $a > $b; };
        $this->binaryOps['='] = function($a, $b) { return $a === $b; };
        $this->binaryOps['<='] = function($a, $b) { return $a <= $b; };
        $this->binaryOps['>='] = function($a, $b) { return $a >= $b; };
        $this->binaryOps['<>'] = function($a, $b) { return $a !== $b; };
    }

}
