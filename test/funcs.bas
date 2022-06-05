print "abs(-8) =", abs(-8)
print "int(atn(1)*10000) =", int(atn(1)*10000)
print "int(tan(0.785)*10000) =", int(tan(0.785)*10000)
print "int(cos(pi)*10000) =", int(cos(3.14159)*10000)
print "int(exp(4)) =", int(exp(4))
print "int(log(10)*10000) =", int(log(10)*10000)
print "int(sin(pi/6)*10000) =", int(sin(3.14159/6)*10000)
print "sgn(-2) =", sgn(-2)
print "int(sqr(100000)) =", int(sqr(100000))

print chr(70), asc("X"), mid("blaha",1, 3), right("left", 2), left("right", 2), len("abrakadabra")

x = 3
rem RND is disabled in test, try manually
rem rep: print "rnd(1) =", rnd(1) : x = x - 1 : if x > 0 then goto rep
