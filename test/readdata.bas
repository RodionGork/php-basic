rep:
read x,y
if x < 1 then restore : goto rep2
print "hypot(";x;",";y;")=";sqr(x^2 + y^2)
goto rep
data 3,4,5,12,0,0

rep2:
read a : print a : if a > 0 then goto rep2