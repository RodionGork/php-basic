10 x = 1
20 res = ""
25 if x mod 3 = 0 then res = res + "fizz"
30 if x mod 5 = 0 then res = res + "buzz"
50 if res then print x, res
40 x = x+1
50 goto (x > 30) * 40 + 20
60 end
