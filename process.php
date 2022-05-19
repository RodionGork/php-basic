<?php

class BasicInterpreter {

    public $code = [];
    public $labels = [];
    public $errors = [];

    function throwLineError($msg) {
        $e = new \Exception($msg);
        $e->parseError = $msg;
        throw $e;
    }
    
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
			} elseif (preg_match('/^[0-9]+/', $line, $m)) {
				$type = 'n';
			} elseif (preg_match('/^\"[^\"]*\"/', $line, $m)) {
				$type = 'q';
			} elseif (preg_match('/^(?:>=|<=|<>|>|<|=|\+|-|\*|\/|\^)/', $line, $m)) {
				$type = 'o';
			} elseif (preg_match('/^[,;:\(\)]/', $line, $m)) {
				$type = 'p';
			} else {
			    $this->throwLineError('Unrecognized character at position ' . ($len - strlen($line) + 1));
			}
			$match = $m[0];
			$line = substr($line, strlen($match));
			$res[] = "$type$match";
		}
		return $res;
	}

    function stripLabel(&$tokens) {
        $tkn0 = $tokens[0];
        $type0 = $tkn0[0];
        if ($type0 == 'n') {
            $remove = 1;
        } elseif ($type0 == 'w' && count($tokens) > 1 && $tokens[1] == 'p:') {
            $remove = 2;
        } else {
            return;
        }
        $this->labels[substr($tkn0, 1)] = count($this->code);
        array_splice($tokens, 0, $remove);
    }

    function takeStatement(&$tokens) {
        $cmd = $tokens[0];
        if ($cmd[0] != 'w') {
            $this->throwLineError('Command or Variable expected');
        }
        $cmd = strtoupper(substr($cmd, 1));
        switch ($cmd) {
            case 'REM':
                $res = array_splice($tokens, 0);
                return array_slice($res, 0, 1);
            case 'NEXT':
                return array_splice($tokens, 0, 2);
            default:
                return null;
        }
    }

    function parseLines(&$lines) {
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            try {
                $tokens = $this->tokenize($line);
                if (count($tokens) == 0) {
                    continue;
                }
                $this->stripLabel($tokens);
                $out = [];
                while (count($tokens) > 0) {
                    $stmt = $this->takeStatement($tokens);
                    if ($stmt) {
                        $out[] = $stmt;
                    }
                    if ($tokens) {
                        if ($tokens[0] == 'p:') {
                            array_shift($tokens);
                        } else {
                            $this->throwLineError('Extra tokens at the end of statement');
                        }
                    }
                }
                $this->code[] = $out;
            } catch (Exception $e) {
                if (isset($e->parseError)) {
                    $this->errors[] = 'Line #' . ($i + 1) . ": {$e->parseError}";
                } else {
                    throw $e;
                }
            }
        }
    }
}

$basic = new \BasicInterpreter();

$lines = explode("\n", file_get_contents('php://stdin'));

$basic->parseLines($lines);

foreach ($basic->labels as $k => $v) {
    echo "$k -> $v\n";
}
for ($i = 0; $i < count($basic->code); $i++) {
    echo "$i# " . json_encode($basic->code[$i]) . "\n";
}
foreach ($basic->errors as $err) {
    echo "$err\n";
}

/*

expr: andop
orop: andop [or andop]*
andop: cmp [and cmp]*
cmp: sum [relop sum]
sum:  prod [+ or - prod]*
prod: pwr [* or / pwr]*
pwr: value [^ pwr]?
value: [-]pvalue
pvalue: num or (expr) or func(expr) or var or var(subscr)
subscr: expr [, expr]*
*/
