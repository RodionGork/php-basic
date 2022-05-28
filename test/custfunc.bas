x = 13
def fnt(x) = x * (x+1) / 2
print "triangle number for 10 is", fnt(10)
def fns(y) = fnt(y) + fnt(y - 1)
print "square of 10 is sum of two triangles:", fns(10)
print "And X should remain thirteen:", x