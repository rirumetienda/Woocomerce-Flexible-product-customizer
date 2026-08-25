Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.Windows.Forms

$ErrorActionPreference = 'Stop'
$OutDir = Join-Path $PSScriptRoot '..\flexible-product-customizer\assets\demo\library'
if (!(Test-Path -LiteralPath $OutDir)) { New-Item -ItemType Directory -Path $OutDir -Force | Out-Null }

function Color-Hex([string]$Hex, [int]$Alpha = 255) {
    if ($Hex -eq 'transparent') { return [System.Drawing.Color]::FromArgb(0,0,0,0) }
    $c = [System.Drawing.ColorTranslator]::FromHtml($Hex)
    return [System.Drawing.Color]::FromArgb($Alpha, $c.R, $c.G, $c.B)
}
function Brush-Hex([string]$Hex, [int]$Alpha = 255) { return New-Object System.Drawing.SolidBrush (Color-Hex $Hex $Alpha) }
function Pen-Hex([string]$Hex, [float]$Width = 2, [int]$Alpha = 255) { return New-Object System.Drawing.Pen (Color-Hex $Hex $Alpha), $Width }
function Rect([float]$X,[float]$Y,[float]$W,[float]$H) { return New-Object System.Drawing.RectangleF($X,$Y,$W,$H) }
function Begin-Canvas([int]$W,[int]$H) {
    $bmp = New-Object System.Drawing.Bitmap($W,$H,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality
    $g.Clear([System.Drawing.Color]::Transparent)
    return @($bmp,$g)
}
function Save-Canvas($Bmp,$G,[string]$Name) {
    $path = Join-Path $OutDir $Name
    $G.Dispose()
    $Bmp.Save($path,[System.Drawing.Imaging.ImageFormat]::Png)
    $Bmp.Dispose()
}
function Fill-Background($G,[int]$W,[int]$H) {
    $bg = New-Object System.Drawing.Drawing2D.LinearGradientBrush((Rect 0 0 $W $H),(Color-Hex '#f7f8fa'),(Color-Hex '#e6eaee'),90)
    $G.FillRectangle($bg,0,0,$W,$H)
    $bg.Dispose()
}
function Fill-RoundRect($G,$X,$Y,$W,$H,$R,[string]$Fill,[string]$Stroke = '#1f2937',[int]$Alpha = 255) {
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $R * 2
    $path.AddArc($X,$Y,$d,$d,180,90)
    $path.AddArc($X+$W-$d,$Y,$d,$d,270,90)
    $path.AddArc($X+$W-$d,$Y+$H-$d,$d,$d,0,90)
    $path.AddArc($X,$Y+$H-$d,$d,$d,90,90)
    $path.CloseFigure()
    $b = Brush-Hex $Fill $Alpha
    $p = Pen-Hex $Stroke 3 120
    $G.FillPath($b,$path)
    $G.DrawPath($p,$path)
    $b.Dispose(); $p.Dispose(); $path.Dispose()
}
function Drop-Shadow($G,$X,$Y,$W,$H,$R=20) {
    for ($i=8; $i -ge 1; $i--) {
        $a = 5 * $i
        Fill-RoundRect $G ($X+$i) ($Y+$i) $W $H $R '#000000' '#000000' $a
    }
}
function Draw-Ellipse($G,$X,$Y,$W,$H,[string]$Fill,[string]$Stroke='#374151',[int]$Alpha=255) {
    $b=Brush-Hex $Fill $Alpha; $p=Pen-Hex $Stroke 3 115
    $G.FillEllipse($b,$X,$Y,$W,$H); $G.DrawEllipse($p,$X,$Y,$W,$H)
    $b.Dispose(); $p.Dispose()
}
function Draw-FabricLines($G,$X,$Y,$W,$H,[string]$Color='#ffffff') {
    $p=Pen-Hex $Color 2 35
    for($i=1;$i -lt 8;$i++){ $xx=$X + $W*$i/8; $G.DrawLine($p,$xx,$Y+10,$xx,$Y+$H-10) }
    $p.Dispose()
}
function Fill-Polygon($G,[array]$Points,[string]$Fill,[string]$Stroke='#111827') {
    $pts = $Points | ForEach-Object { New-Object System.Drawing.PointF($_[0], $_[1]) }
    $b=Brush-Hex $Fill; $p=Pen-Hex $Stroke 4 110
    $G.FillPolygon($b,$pts); $G.DrawPolygon($p,$pts)
    $b.Dispose(); $p.Dispose()
}
function Draw-Shirt([string]$Name,[string]$Hex,[string]$Surface) {
    $c=Begin-Canvas 1200 1200; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1200
    if ($Surface -like 'sleeve-*') {
        Drop-Shadow $g 310 280 580 560 38
        Fill-RoundRect $g 310 280 580 560 38 $Hex '#111827'
        Draw-FabricLines $g 330 300 540 520
        $p=Pen-Hex '#111827' 5 90; $g.DrawLine($p,360,420,840,420); $g.DrawLine($p,360,700,840,700); $p.Dispose()
    } else {
        Fill-Polygon $g @(@(410,260),@(790,260),@(890,410),@(790,510),@(745,930),@(455,930),@(410,510),@(310,410)) $Hex
        Fill-Polygon $g @(@(410,260),@(305,400),@(365,520),@(470,345)) $Hex
        Fill-Polygon $g @(@(790,260),@(895,400),@(835,520),@(730,345)) $Hex
        $b=Brush-Hex '#ffffff' 70; $g.FillEllipse($b,535,250,130,95); $b.Dispose()
        $p=Pen-Hex '#111827' 5 100; $g.DrawArc($p,530,235,140,120,15,150); $p.Dispose()
        Draw-FabricLines $g 455 340 290 500
    }
    Save-Canvas $bmp $g $Name
}
function Draw-Hoodie([string]$Name,[string]$Hex,[string]$Surface,[bool]$Pocket) {
    $c=Begin-Canvas 1200 1200; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1200
    if ($Surface -like 'sleeve-*') {
        Drop-Shadow $g 390 105 420 980 48
        Fill-RoundRect $g 390 105 420 980 48 $Hex '#111827'
        Draw-FabricLines $g 430 140 340 900
        $p=Pen-Hex '#111827' 5 90; $g.DrawLine($p,420,250,780,250); $g.DrawLine($p,420,915,780,915); $p.Dispose()
    } else {
        Fill-Polygon $g @(@(365,330),@(835,330),@(900,900),@(300,900)) $Hex
        Fill-RoundRect $g 415 150 370 280 120 $Hex '#111827'
        $b=Brush-Hex '#ffffff' 50; $g.FillEllipse($b,505,220,190,145); $b.Dispose()
        Fill-Polygon $g @(@(365,330),@(220,430),@(315,610),@(420,445)) $Hex
        Fill-Polygon $g @(@(835,330),@(980,430),@(885,610),@(780,445)) $Hex
        if ($Pocket) { Fill-RoundRect $g 410 690 380 160 34 $Hex '#111827' 255; $p=Pen-Hex '#111827' 4 95; $g.DrawLine($p,460,770,740,770); $p.Dispose() }
        Draw-FabricLines $g 390 365 420 300
    }
    Save-Canvas $bmp $g $Name
}
function Draw-Cap([string]$Name,[string]$Variant,[string]$Surface) {
    $map=@{
        'black'=@('#111111','#111111'); 'black-white'=@('#f7f7f7','#111111'); 'green'=@('#17623a','#17623a'); 'red'=@('#c51f32','#c51f32');
        'black-yellow'=@('#f5d84c','#111111'); 'black-red'=@('#c51f32','#111111'); 'black-green'=@('#17623a','#111111'); 'black-pink'=@('#f2a9c9','#111111')
    }
    $front=$map[$Variant][0]; $mesh=$map[$Variant][1]
    $c=Begin-Canvas 1200 1200; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1200
    Fill-RoundRect $g 310 390 580 300 120 $front '#111827'
    Fill-RoundRect $g 270 475 660 260 90 $mesh '#111827'
    for($x=320;$x -le 880;$x+=38){ for($y=500;$y -le 685;$y+=32){ Draw-Ellipse $g $x $y 8 8 '#ffffff' '#ffffff' 80 } }
    Fill-Polygon $g @(@(300,680),@(900,680),@(1010,790),@(190,790)) $front
    $p=Pen-Hex '#ffffff' 2 70; $g.DrawLine($p,600,395,600,685); $g.DrawArc($p,380,410,440,280,200,140); $p.Dispose()
    Save-Canvas $bmp $g $Name
}
function Draw-Mug([string]$Name,[string]$Type,[string]$Accent='#ffffff') {
    $c=Begin-Canvas 1200 1200; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1200
    $body = if($Type -eq 'black-window' -or $Type -eq 'magic'){'#111111'}else{'#ffffff'}
    Drop-Shadow $g 255 245 560 710 70
    Fill-RoundRect $g 255 245 560 710 70 $body '#c9ced6'
    $top=New-Object System.Drawing.Drawing2D.LinearGradientBrush((Rect 285 215 500 90),(Color-Hex '#ffffff'),(Color-Hex $Accent),0)
    $g.FillEllipse($top,285,215,500,90); $top.Dispose()
    $p=Pen-Hex '#c9ced6' 6 170; $g.DrawEllipse($p,740,385,250,360); $g.DrawEllipse($p,780,435,160,260); $p.Dispose()
    if($Type -eq 'black-window') { Fill-RoundRect $g 315 340 460 430 30 '#ffffff' '#d1d5db' }
    if($Type -eq 'magic') {
        $glow=New-Object System.Drawing.Drawing2D.LinearGradientBrush((Rect 285 330 500 470),(Color-Hex '#202020'),(Color-Hex '#f7f7f7'),0)
        $g.FillRectangle($glow,300,360,210,410); $glow.Dispose()
    }
    $p2=Pen-Hex '#ffffff' 3 65; for($i=0;$i -lt 7;$i++){ $g.DrawLine($p2,335+$i*58,285,335+$i*58,900) }; $p2.Dispose()
    Save-Canvas $bmp $g $Name
}
function Draw-Tumbler([string]$Name) {
    $c=Begin-Canvas 1200 1600; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1600
    Drop-Shadow $g 340 125 520 1320 90
    $grad=New-Object System.Drawing.Drawing2D.LinearGradientBrush((Rect 340 125 520 1320),(Color-Hex '#eef1f3'),(Color-Hex '#9aa3ad'),0)
    $g.FillRectangle($grad,360,205,480,1120); $grad.Dispose()
    Draw-Ellipse $g 360 135 480 160 '#dbe1e6' '#8b949e'
    Draw-Ellipse $g 370 1260 460 150 '#aeb6bf' '#8b949e'
    Fill-RoundRect $g 365 175 470 1180 55 '#c9d0d6' '#7c858f' 210
    Draw-FabricLines $g 390 220 420 1050 '#ffffff'
    Save-Canvas $bmp $g $Name
}
function Draw-Mousepad([string]$Name,[bool]$Round) {
    $c=Begin-Canvas 1200 1200; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1200
    if($Round){ Drop-Shadow $g 220 220 760 760 380; Draw-Ellipse $g 220 220 760 760 '#f8fafc' '#111827'; Draw-Ellipse $g 250 250 700 700 '#ffffff' '#d1d5db' }
    else { Drop-Shadow $g 220 220 760 760 42; Fill-RoundRect $g 220 220 760 760 42 '#f8fafc' '#111827' }
    Save-Canvas $bmp $g $Name
}
function Draw-PinsSet([string]$Name) {
    $c=Begin-Canvas 1200 1200; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1200
    $positions=@(@(240,250),@(490,225),@(740,250),@(365,600),@(615,600))
    foreach($p0 in $positions){ Drop-Shadow $g $p0[0] $p0[1] 220 220 110; Draw-Ellipse $g $p0[0] $p0[1] 220 220 '#ffffff' '#111827'; Draw-Ellipse $g ($p0[0]+20) ($p0[1]+20) 180 180 '#f8fafc' '#d1d5db' }
    Save-Canvas $bmp $g $Name
}
function Draw-Poster([string]$Name,[bool]$Vertical,[bool]$Metal) {
    $w=if($Vertical){1000}else{1400}; $h=if($Vertical){1400}else{1000}
    $c=Begin-Canvas $w $h; $bmp=$c[0]; $g=$c[1]
    $wall=New-Object System.Drawing.Drawing2D.LinearGradientBrush((Rect 0 0 $w $h),(Color-Hex '#eee8dc'),(Color-Hex '#d7cdbc'),90); $g.FillRectangle($wall,0,0,$w,$h); $wall.Dispose()
    for($x=0;$x -lt $w;$x+=80){ $p=Pen-Hex '#ffffff' 1 45; $g.DrawLine($p,$x,0,$x+120,$h); $p.Dispose() }
    $px=if($Vertical){250}else{250}; $py=if($Vertical){250}else{245}; $pw=if($Vertical){500}else{900}; $ph=if($Vertical){860}else{510}
    Drop-Shadow $g $px $py $pw $ph 8
    if($Metal){
        $grad=New-Object System.Drawing.Drawing2D.LinearGradientBrush((Rect $px $py $pw $ph),(Color-Hex '#f3f6f8'),(Color-Hex '#aeb7c0'),30); $g.FillRectangle($grad,$px,$py,$pw,$ph); $grad.Dispose()
        $p=Pen-Hex '#ffffff' 2 90; for($i=0;$i -lt 8;$i++){ $g.DrawLine($p,$px+$i*($pw/7),$py,$px+$pw,$py+$i*($ph/7)) }; $p.Dispose()
    } else { $b=Brush-Hex '#ffffff'; $g.FillRectangle($b,$px,$py,$pw,$ph); $b.Dispose() }
    $p2=Pen-Hex '#c7ccd1' 4 160; $g.DrawRectangle($p2,$px,$py,$pw,$ph); $p2.Dispose()
    Save-Canvas $bmp $g $Name
}
function Draw-Banner([string]$Name) {
    $c=Begin-Canvas 900 1600; $bmp=$c[0]; $g=$c[1]
    $wall=New-Object System.Drawing.Drawing2D.LinearGradientBrush((Rect 0 0 900 1600),(Color-Hex '#e7dccd'),(Color-Hex '#cbb89f'),90); $g.FillRectangle($wall,0,0,900,1600); $wall.Dispose()
    for($y=0;$y -lt 1600;$y+=130){ $p=Pen-Hex '#ffffff' 2 45; $g.DrawLine($p,0,$y,900,$y+25); $p.Dispose() }
    $pcord=Pen-Hex '#7a5f32' 5 210; $g.DrawLine($pcord,250,210,450,50); $g.DrawLine($pcord,450,50,650,210); $pcord.Dispose()
    Fill-RoundRect $g 210 190 480 26 8 '#9a6b35' '#6b4420'
    Fill-RoundRect $g 250 230 400 1040 4 '#ffffff' '#d1d5db'
    Fill-RoundRect $g 210 1270 480 26 8 '#9a6b35' '#6b4420'
    Save-Canvas $bmp $g $Name
}
function Draw-Puzzle([string]$Name) {
    $c=Begin-Canvas 1400 1000; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1400 1000
    Drop-Shadow $g 235 180 930 640 12
    Fill-RoundRect $g 235 180 930 640 12 '#ffffff' '#111827'
    $p=Pen-Hex '#cfd5dc' 2 120; for($x=310;$x -lt 1140;$x+=75){ $g.DrawLine($p,$x,185,$x,815) }; for($y=245;$y -lt 800;$y+=58){ $g.DrawLine($p,240,$y,1160,$y) }; $p.Dispose()
    Save-Canvas $bmp $g $Name
}
function Draw-Notebook([string]$Name,[int]$W,[int]$H,[string]$Color) {
    $c=Begin-Canvas $W $H; $bmp=$c[0]; $g=$c[1]; Fill-Background $g $W $H
    $px=[int]($W*.26); $py=[int]($H*.13); $pw=[int]($W*.50); $ph=[int]($H*.66)
    Drop-Shadow $g $px $py $pw $ph 18
    Fill-RoundRect $g $px $py $pw $ph 18 $Color '#111827'
    $p=Pen-Hex '#111827' 6 140; $g.DrawLine($p,$px+45,$py,$px+45,$py+$ph); $p.Dispose()
    for($y=$py+50;$y -lt $py+$ph-30;$y+=70){ Draw-Ellipse $g ($px+25) $y 35 35 '#e5e7eb' '#9ca3af' }
    Save-Canvas $bmp $g $Name
}
function Draw-Lanyard([string]$Name) {
    $c=Begin-Canvas 1800 500; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1800 500
    Drop-Shadow $g 145 185 1510 130 26
    Fill-RoundRect $g 145 185 1510 130 26 '#ffffff' '#111827'
    $p=Pen-Hex '#d1d5db' 2 100; for($x=200;$x -lt 1600;$x+=90){ $g.DrawLine($p,$x,190,$x+50,310) }; $p.Dispose()
    Fill-RoundRect $g 800 135 200 230 35 '#d9dee5' '#6b7280'
    Save-Canvas $bmp $g $Name
}
function Draw-Clock([string]$Name) {
    $c=Begin-Canvas 1200 1200; $bmp=$c[0]; $g=$c[1]; Fill-Background $g 1200 1200
    Drop-Shadow $g 250 140 700 700 350
    Draw-Ellipse $g 250 140 700 700 '#eef7f4' '#cfd8dc' 230
    Draw-Ellipse $g 285 175 630 630 '#ffffff' '#e5e7eb' 80
    $p=Pen-Hex '#111827' 8 220; $g.DrawLine($p,600,490,600,340); $g.DrawLine($p,600,490,735,555); $p.Dispose()
    $pr=Pen-Hex '#d11f2f' 5 220; $g.DrawLine($pr,600,490,520,490); $pr.Dispose()
    Draw-Ellipse $g 585 475 30 30 '#d11f2f' '#111827'
    Fill-RoundRect $g 370 840 70 120 8 '#111827' '#111827'; Fill-RoundRect $g 760 840 70 120 8 '#111827' '#111827'
    Save-Canvas $bmp $g $Name
}

$palette=@{
 'black'='#111111'; 'white'='#ffffff'; 'red'='#c51f32'; 'navy'='#15233f'; 'aqua'='#20c7c9'; 'dark-gray'='#3c4043'; 'khaki'='#b7a277'; 'yellow'='#f5d84c'; 'light-green'='#8bd36f'; 'dark-green'='#17623a'; 'pastel-pink'='#f2a9c9'; 'magenta'='#d7288f'; 'blue'='#2563eb'
}
$shirtColors=@('black','white','red','navy','aqua','dark-gray','khaki','yellow','light-green','dark-green','pastel-pink','magenta')
foreach($color in $shirtColors){ foreach($surface in @('front','back','sleeve-left','sleeve-right')){ Draw-Shirt "tshirt-$color-$surface.png" $palette[$color] $surface } }
foreach($color in @('black','white')){ foreach($surface in @('front','back','sleeve-left','sleeve-right')){ Draw-Hoodie "hoodie-$color-$surface.png" $palette[$color] $surface $true } }
foreach($color in @('black','white','red','blue','dark-green')){ foreach($surface in @('front','back','sleeve-left','sleeve-right')){ Draw-Hoodie "sweatshirt-$color-$surface.png" $palette[$color] $surface $false } }
foreach($variant in @('black','black-white','green','red','black-yellow','black-red','black-green','black-pink')){ foreach($surface in @('front','visor')){ Draw-Cap "cap-$variant-$surface.png" $variant $surface } }
Draw-Mug 'mug-white-wrap.png' 'white' '#ffffff'
Draw-Mug 'mug-magic-wrap.png' 'magic' '#111111'
Draw-Mug 'mug-accent-black-wrap.png' 'accent' '#111111'
Draw-Mug 'mug-accent-red-wrap.png' 'accent' '#c51f32'
Draw-Mug 'mug-accent-yellow-wrap.png' 'accent' '#f5d84c'
Draw-Mug 'mug-accent-blue-wrap.png' 'accent' '#2563eb'
Draw-Mug 'mug-black-window-wrap.png' 'black-window' '#111111'
Draw-Mousepad 'mousepad-square.png' $false
Draw-Mousepad 'mousepad-round.png' $true
Draw-PinsSet 'pins-set.png'
Draw-Poster 'poster-sulfite-12x18-vertical.png' $true $false
Draw-Poster 'poster-sulfite-12x18-horizontal.png' $false $false
Draw-Poster 'poster-sulfite-12x9-vertical.png' $true $false
Draw-Poster 'poster-sulfite-12x9-horizontal.png' $false $false
Draw-Poster 'poster-metal-20x30-vertical.png' $true $true
Draw-Poster 'poster-metal-20x30-horizontal.png' $false $true
Draw-Poster 'poster-metal-40x60-vertical.png' $true $true
Draw-Poster 'poster-metal-40x60-horizontal.png' $false $true
Draw-Banner 'banner-40x80.png'
Draw-Tumbler 'tumbler-aluminum-wrap.png'
Draw-Puzzle 'puzzle-200.png'
Draw-Notebook 'notebook-university-front.png' 1000 1400 '#ffffff'
Draw-Notebook 'notebook-university-back.png' 1000 1400 '#f8fafc'
Draw-Notebook 'notebook-half-letter-front.png' 900 1200 '#ffffff'
Draw-Notebook 'notebook-half-letter-back.png' 900 1200 '#f8fafc'
Draw-Notebook 'notebook-five-subject-front.png' 1100 1500 '#ffffff'
Draw-Notebook 'notebook-five-subject-back.png' 1100 1500 '#f8fafc'
Draw-Lanyard 'lanyard.png'
Draw-Clock 'glass-clock.png'

Write-Host "Generated $((Get-ChildItem -LiteralPath $OutDir -Filter '*.png').Count) PNG assets in $OutDir"