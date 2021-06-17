<?php

$opt = getopt("i:");

if (isset($opt['i'])) $in_file = $opt['i'];
else die("-i: Input file is not set!\n");

if (file_exists($in_file)) {
    $in = fopen($in_file, "r");
} else {
    echo "Soubor " . $in_file . " neexistuje\n";
    die();
}

$newline = false;
$iskladba = 0;
$iexpected = 0;
while ($line = fgets($in)) {
//print_r($iskladba);

    if (preg_match('/<SKLADBA/', $line)) {
        ++$iskladba;
    }

    if (preg_match('/\/SKLADBA/', $line)) {
        --$iskladba;
    }

    if (preg_match('/\/NOSIC>/', $line)) {

        if ($iskladba > 0) {
            echo str_repeat("</SKLADBA>\n", $iskladba);
        }
        $iskladba = 0;
    }
    echo $line;
}

?>
