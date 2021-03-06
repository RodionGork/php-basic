function BasicInterpreter(parsed) {
    this.code = parsed['code'];
    this.readdata = [];
    this.readptr = 0;
    this.labels = parsed['labels'];
    this.errors = parsed['errors'];
    this.vars = [];
    this.arrays = [];
    this.dims = [];
    this.sourceLineNums = parsed['sourceLineNums'];
    this.pc = 0;
    this.pc2 = 0;
    this.callStack = [];
    this.inputStream = [];
    
    this.output = function(text) {
        console.log(text);
    }

    this.addInput = function(text) {
        text.split('').forEach(c => this.inputStream.push(c));
    }

    this.run = function(input, limit) {
        this.input = !input ? null : input;
        if (!limit) {
            limit = Infinity;
        }
        let errData = this.extractData();
        if (errData) {
            return errData;
        }
        this.executeCode(limit);
    }
    
    this.executeCode = function(limit) {
        let opcount = 0;
        this.pc = 0;
        this.pc2 = 0;
        try {
            while (this.pc < this.code.length) {
                if (opcount++ >= limit) {
                    throwLineError("Execution limit reached: " + limit + " statements");
                }
                let lineNum = this.sourceLineNums[this.pc];
                let line = this.code[this.pc];
                let stmt = line[this.pc2];
                if (++this.pc2 >= line.length) {
                    this.pc++;
                    this.pc2 = 0;
                }
                switch (stmt[0]) {
                    case 'DATA':
                        break;
                    case 'DEF':
                        this.execDef(stmt); break;
                    case 'DIM':
                        this.execDim(stmt); break;
                    case 'END':
                        this.pc = this.code.length; break;
                    case 'GOSUB':
                        this.execGosub(stmt); break;
                    case 'GOTO':
                        this.execGoto(stmt); break;
                    case 'FOR':
                        this.execFor(stmt); break;
                    case 'IF':
                        this.execIfThen(stmt); break;
                    case 'INPUT':
                        this.execInput(stmt); break;
                    case 'LET':
                        this.execAssign(stmt); break;
                    case 'NEXT':
                        this.execNext(stmt); break;
                    case 'PRINT':
                        this.execPrint(stmt); break;
                    case 'READ':
                        this.execRead(stmt); break;
                    case 'REM':
                        break;
                    case 'RESTORE':
                        this.execRestore(stmt); break;
                    case 'RETURN':
                        this.execReturn(stmt); break;
                    default:
                        throwLineError("Command not implemented: " + stmt[0]);
                }
            }
        } catch (e) {
            if (typeof(e) == 'string') {
                alert(e);
            } else {
                console.log(e);
            }
        }
        return null;
    }
    
    this.execAssign = function(stmt) {
        let value = this.evalExpr(stmt[1]);
        this.setVariable(stmt[2], value);
    }

    this.execDef = function(stmt) {
        let name = stmt[1];
        if (this.funcs[name] !== undefined) {
            throwLineError("Function " + name + " already defined");
        }
        let expr = stmt[2];
        let that = this;
        this.funcs[name] = function(x) {
            let saved = that.vars['_1'] !== undefined ? that.vars['_1'] : null;
            that.vars['_1'] = x;
            let res = that.evalExpr(expr);
            if (saved !== null) {
                that.vars['_1'] = saved;
            }
            return res;
        };
    }

    this.execDim = function(stmt) {
        for (let i = 1; i < stmt.length; i++) {
            let expr = stmt[i];
            let vvar = tokenBody(expr[expr.length - 1]);
            if (this.arrays[vvar]) {
                throwLineError("Array '" + vvar + "' already exists");
            }
            expr = expr.slice(0, -1);
            let dims = [];
            this.evalExpr(expr, dims);
            this.checkSubscripts(dims);
            this.dims[vvar] = dims;
            let p = 1;
            dims.forEach(d => {
                p *= d;
            })
            this.arrays[vvar] = arrayFill(p, 0);
        }
    }

    this.execFor = function(stmt) {
        let vvar = tokenBody(stmt[1]);
        let from = this.evalExpr(stmt[2]);
        let till = this.evalExpr(stmt[3]);
        let step = stmt.length > 4 ? this.evalExpr(stmt[4]) : 1;
        if (step <= 0) {
            throwLineError('Negative or zero STEP in FOR is not allowed');
        }
        this.callStack.push(['f', vvar, step, till, this.pc, this.pc2]);
        this.vars[vvar] = from;
    }

    this.execGosub = function(stmt) {
        this.callStack.push(['c', this.pc, this.pc2]);
        this.execGoto(stmt);
    }

    this.execGoto = function(stmt) {
        let label = this.evalExpr(stmt[1]);
        if (this.labels[label] === undefined) {
            throwLineError("No such label: " + label);
        }
        this.pc = this.labels[label];
        this.pc2 = 0;
    }

    this.execIfThen = function(stmt) {
        if (!logicRes(this.evalExpr(stmt[1]))) {
            this.pc++;
            this.pc2 = 0;
        }
    }

    this.execInput = function(stmt) {
        let stopOnSpace = true;
        for (let i = 1; i < stmt.length; i++) {
            let expr = stmt[i];
            if (typeof(expr) === 'string') {
                if (expr !== '') {
                    this.output(expr);
                } else {
                    stopOnSpace = false;
                }
            } else {
                this.setVariable(expr, this.scanInput(stopOnSpace));
            }
        }
    }

    this.execNext = function(stmt) {
        let frame = null;
        if (this.callStack.length) {
            frame = this.callStack[this.callStack.length - 1];
        }
        if (frame === null || frame[0] != 'f') {
            throwLineError('NEXT without preceding FOR execution');
        }
        let vvar = frame[1];
        //list('f', $var, $step, $till, $pcnext, $pc2next) = $frame;
        if (vvar != tokenBody(stmt[1])) {
            throwLineError('NEXT for wrong variable, expected: ' + vvar);
        }
        let value = this.vars[vvar] + frame[2];
        if (value > frame[3]) {
            this.callStack.pop();
            return;
        }
        this.vars[vvar] = value;
        this.pc = frame[4];
        this.pc2 = frame[5];
    }

    this.execPrint = function(stmt) {
        for (let i = 1; i < stmt.length; i++) {
            this.output(this.evalExpr(stmt[i]));
        }
    }

    this.execRead = function(stmt) {
        for (let i = 1; i < stmt.length; i++) {
            if (this.readptr >= this.readdata.length) {
                throwLineError('Out of data for READ');
            }
            let expr = stmt[i];
            this.setVariable(expr, this.readdata[this.readptr++]);
        }
    }

    this.execRestore = function(stmt) {
        this.readptr = 0;
    }

    this.execReturn = function(stmt) {
        while (this.callStack.length) {
            let elem = this.callStack.pop();
            if (elem[0] == 'c') {
                this.pc = elem[1];
                this.pc2 = elem[2];
                return;
            }
        }
        throwLineError('RETURN executed but there was no GOSUB before');
    }

    this.setVariable = function(expr, value) {
        let name = tokenBody(expr[0]);
        if (expr.length == 1) {
            this.vars[name] = value;
        } else {
            let stack = [];
            this.evalExpr(expr[1], stack);
            let idx = this.arrayIndex(name, stack);
            this.arrays[name][idx] = value;
        }
    }

    this.getVariable = function(name) {
        let value = this.vars[name];
        if (value === undefined) {
            throwLineError("Variable not defined: " + name);
        }
        return value;
    }

    this.checkSubscripts = function(subs, dims) {
        subs.forEach(v => {
            if (v != Math.floor(v)) {
                throwLineError("Non-integer array index: " + v);
            }
            if (v < 0) {
                throwLineError("Negative array index: " + v);
            }
        });
        if (typeof(dims) === 'undefined') {
            return;
        }
        let n = dims.length;
        let ns = subs.length;
        if (ns < n) {
            throwLineError("Not enough subscripts, should be " + n);
        }
        let offs = ns - n;
        for (let i = 0; i < n; i++) {
            if (subs[i + offs] >= dims[i]) {
                throwLineError("Index#" + i + " out of range: " + subs[i]);
            }
        }
    }

    this.evalExpr = function(expr, stack) {
        if (typeof(stack) === 'undefined') {
            stack = [];
        }
        expr.forEach(v => {
            let body = tokenBody(v);
            if (v[0] == 'n') {
                stack.push(parseFloat(body));
            } else if (v[0] == 'q') {
                stack.push(body);
            } else if (v[0] == 'v') {
                stack.push(this.getVariable(body));
            } else if (v[0] == 'o') {
                let v2 = stack.pop();
                let v1 = stack.pop();
                stack.push(this.binaryOps[body](v1, v2));
            } else if (v[0] == 'f') {
                let v1 = stack.pop();
                stack.push(this.funcs[body](v1));
            } else if (v[0] == 'F') {
                let v1 = array_splice(stack, -funcArgCnt[body]);
                stack.push(call_user_func_array(funcs[body], v1));
            } else if (v[0] == 'a') {
                let name = tokenBody(v);
                let idx = this.arrayIndex(name, stack);
                let arrsz = this.dims[name].length;
                while (arrsz-- > 0) { stack.pop() }
                stack.push(this.arrays[name][idx]);
            }
        });
        return stack[0];
    }

    this.arrayIndex = function(name, subs) {
        if (this.arrays[name] === undefined) {
            throwLineError("Array not defined: " + name);
        }
        let dims = this.dims[name];
        this.checkSubscripts(subs, dims);
        let offs = subs.length - dims.length;
        let idx = subs[offs];
        for (let i = 1; i < dims.length; i++) {
            idx = idx * dims[i] + subs[i + offs];
        }
        return idx;
    }

    this.extractData = function() {
        this.readdata = [];
        let lineNum = 0;
        try {
            this.code.forEach((line, num) => {
                lineNum = this.sourceLineNums[num];
                let stmt = line[0];
                if (stmt[0] == 'DATA') {
                    for (let i = 1; i < stmt.length; i++) {
                        this.readdata.push(this.evalExpr(stmt[i]));
                    }
                }
            });
        } catch (e) {
            return "Runtime error (line #" + lineNum + "): " + e;
        }
        return null;
    }

    function throwLineError(msg) {
        throw msg;
    }
    function tokenBody(token) {
        return token.substring(1);
    }
    
    this.binaryOps = {
        '+': (a, b) => {return a+b},
        '-': (a, b) => {return a-b},
        '*': (a, b) => {return a*b},
        '/': (a, b) => {return a/b},
        '%': (a, b) => {return a%b},
        '^': (a, b) => {return Math.pow(a, b)},
        '<': (a, b) => {return logicRes(a < b)},
        '>': (a, b) => {return logicRes(a > b)},
        '=': (a, b) => {return logicRes(a == b)},
        '<=': (a, b) => {return logicRes(a <= b)},
        '>=': (a, b) => {return logicRes(a >= b)},
        '<>': (a, b) => {return logicRes(a != b)},
        '&': (a, b) => {return logicRes(a && b)},
        '|': (a, b) => {return logicRes(a || b)},
    }

    this.funcs = {
        'ABS': x => Math.abs(x),
        'ATN': x => Math.atan(x),
        'ASC': s => s.charCodeAt(0),
        'CHR': x => (x > 31 && x < 128) ? String.fromCharCode(x) : '',
        'COS': x => Math.cos(x),
        'EXP': x => Math.exp(x),
        'INT': x => Math.floor(x),
        'LEFT': (s, n) => s.substr(0, n),
        'LEN': s => s.length,
        'LOG': x => Math.log(x),
        'MID': (s, n, p) => s.substr(n, p),
        'RIGHT': (s, n) => s.substr(-n),
        'RND': x => Math.random(),
        'SIN': x => Math.sin(x),
        'SGN': x => Math.sign(x),
        'SQR': x => Math.sqrt(x),
        'TAN': x => Math.tan(x),
    }

    function logicRes(v) {
        return v ? 1 : 0;
    }

    function arrayFill(cnt, val) {
        let res = [];
        while (cnt-- > 0) {
            res.push(val);
        }
        return res;
    }

    this.scanInput = function(stopOnSpace) {
        let stopChars = stopOnSpace ? " \t\r\n" : "\r\n";
        let val = '';
        while (1) {
            if (!this.inputStream.length) {
                throwLineError('Unexpected end of input');
            }
            let c = this.inputStream.shift();
            if (!stopChars.includes(c)) {
                val = c;
                break;
            }
        }
        while (1) {
            if (!this.inputStream.length) {
                break;
            }
            let c = this.inputStream.shift();
            if (stopChars.includes(c)) {
                break;
            }
            val += c;
        }
        return isNaN(val) ? val : parseFloat(val);
    }
}
