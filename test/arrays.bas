10 dim a(3, 4)
23 y = 0
25 x = 0
30 a(y,x) = (x+1)^(y+1)
31 print a(y,x);" ";
33 x = x + 1 : if x < 4 then goto 30
34 print
35 y = y + 1 : if y < 3 then goto 25
