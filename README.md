# php-basic
Basic language interpreter in PHP

- supports both numeric (old-style) and literal (with colon) labels.
- variables can hold numeric and string values
- multi-dimension arrays with zero-based indices
- calculated goto (both with numeric and text labels)

_interactive mode (REPL) is not added yet, mainly due to lack of usage for it_

### Supported statements

We follow "mainstream" from original (Dartmouth) BASIC which was also widely
found in "home computers era", like Apple, Commodore, ZX Spectrum.

- `IF` ... `THEN` (no else, multiple statements per line, old-school style)
- `PRINT` with commans and semicolons
- `INPUT` automatically deciding on type of value (numeric or string)
- `GOTO` and `GOSUB` allowing expression as argument, `RETURN`
- `FOR` and `NEXT` statements (with optional positive `STEP`)
- `READ`, `DATA` and `RESTORE`
- `DIM` allocating arrays
- `DEF` for defining custom functions
- `LET` which could be omitted in assignment
- `END` which just stops the program
- `REM` skips till the end of line

### Supported operations

    + - * / MOD
    ^ (power)
    > < = <> >= <= (true represented by 1, false by 0)
    AND OR

### Supported built-in functions

    ABS SGN INT
    SIN COS TAN ATN
    EXP SQL LOG - first two could also be represented with power operator
    RND - requires argument but ignores it

### Extra features yet to come
- arrays declared with no dimension to serve as hashtables (e.g. using string variables)
- string functions
