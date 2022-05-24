print "before sub1"
gosub sub
print "after sub1"

print "before sub2" : gosub sub : print "after sub2"
end

sub: print "in the sub"
return
