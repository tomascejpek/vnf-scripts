<?php

$opt = getopt("i:o:");

if (isset($opt['i'])) $in_file = $opt['i'];
else die("-i: Input file is not set!\n");
if (isset($opt['o'])) $out_file = $opt['o'];
else die("-o: Output file is not set!\n");

$lang_ini = parse_ini_file("lang.ini");
$podil_ini = parse_ini_file("podil.ini");
$country_ini = parse_ini_file("country.ini");
$spec_ini = parse_ini_file("spec.ini");


$in_spec_del = fopen("spec_delete.txt", "r");
while ($line = fgets($in_spec_del)) {
    $spec_delete[trim($line)] = 1;
}

// get text from element
function getData($xmlReader, $element)
{
    $result = null;

    if (!isset($xmlReader->$element)) return $result;
    $result = $xmlReader->$element->__toString();

    return htmlspecialchars($result);
}

function get040()
{
    $a["a"] = "Česká televize";
    $a["b"] = "cze";
    $a["d"] = "BOA001";
    return $a;
}

function firstUpper($s)
{
    //return mb_strtoupper(mb_substr($s,0,1)).mb_strtolower(mb_substr($s,1));
    return mb_convert_case($s, MB_CASE_TITLE, "UTF-8");
    return "";

}

function getAuthor505($jmeno, $prijmeni)
{
    return firstUpper($jmeno) . " " . firstUpper($prijmeni);
}

function getAuthor($jmeno, $prijmeni)
{
    $author = firstUpper($prijmeni) . ", " . firstUpper($jmeno);
    return (strlen($author) > 2) ? $author : "";
}

function getLang($lang)
{
    global $lang_ini;
    if (array_key_exists($lang, $lang_ini)) return $lang_ini[$lang];
    return false;
}

function getPodil($podil)
{
    global $podil_ini;
    $podil = mb_strtolower($podil);
    if (array_key_exists($podil, $podil_ini)) return $podil_ini[$podil];
    return $podil;
}

// <subfield code="$code">$value</subfield>
function writeSubField($code, $value)
{
    global $xmlWriter;
    $xmlWriter->startElement('subfield');
    $xmlWriter->writeAttribute('code', $code);
    $xmlWriter->writeRaw($value);
    $xmlWriter->endElement();
}

function writeDataField($tag, $ind1, $ind2, $data, $type = "n")
{
    global $xmlWriter;
    $xmlWriter->startElement('datafield');
    $xmlWriter->writeAttribute('tag', $tag);
    $xmlWriter->writeAttribute('ind1', $ind1);
    $xmlWriter->writeAttribute('ind2', $ind2);

    if (is_array($data)) {
        if ($type === "rs") { // repeat subfields -> 505 $t$g$t$g$t$g
            foreach ($data as $subfield) {
                foreach ($subfield as $code => $value) {
                    if (is_array($value)) {
                        foreach ($value as $val) {
                            writeSubField($code, $val);
                        }
                    } else {
                        writeSubField($code, $value);
                    }
                } // foreach $subfield
            } // foreach $data
        } // if "rs"
        else {
            foreach ($data as $code => $value) {
                if (is_array($value)) {
                    foreach ($value as $val) {
                        writeSubField($code, $val);
                    }
                } else {
                    writeSubField($code, $value);
                }
            }
        } // else "rs"
    } // if is_array
    /*   else{
         writeSubField($code, $data);
       }*/
    $xmlWriter->endElement();
}

function writeField($result)
{
    global $dataFields;
    global $xmlWriter;
    foreach ($dataFields as $oldTag => $newTag) {
        $ind1 = substr($newTag, 3, 1);
        $ind2 = substr($newTag, 4, 1);
        $tag = substr($newTag, 0, 3);
        if (($len = strlen($newTag)) > 5) {
            $type = substr($newTag, 5, $len - 5);
        } else $type = "n"; // normal
        if (!array_key_exists($oldTag, $result)) continue;

        if ($type === "r") { // repeated field
            foreach ($result[$oldTag] as $field) {
                if ($field) {
                    writeDataField($tag, $ind1, $ind2, $field);
                }
            }
        } else writeDataField($tag, $ind1, $ind2, $result[$oldTag], $type);
    }
}

function writeControlFields($result)
{
    global $controlFields;
    global $xmlWriter;
    foreach ($controlFields as $key) {
        if (array_key_exists($key, $result)) {
            $xmlWriter->startElement('controlfield');
            $xmlWriter->writeAttribute('tag', $key);
            $xmlWriter->writeRaw($result[$key]);
            $xmlWriter->endElement();
        }
    }
}

function uniqValues($result, $values, $field, $subfield)
{
    $i = 0;
    foreach ($values as $key => $value) {
        $result[$field][$i][$subfield] = $value;
        ++$i;
    }
    return $result;
}

function toArray(&$array, $value, $prefix = "")
{
    if (strlen($value) > 1) {
        if (!in_array($prefix . mb_strtolower($value), $array)) $array[$prefix . mb_strtolower($value)] = $prefix . $value;
    }
}

function authorIn505($array, $author)
{
    return (in_array($author, $array)) ? true : false;
}

function getElementText($element)
{
    $s = str_replace("&", "&amp;", $element->__toString());
    $s = str_replace(", ", ",", $s);
    $s = str_replace(",", ", ", $s);
    $s = str_replace(". ", ".", $s);
    $s = str_replace(".", ". ", $s);
    $s = str_replace(" (", "(", $s);
    $s = str_replace("(", " (", $s);
    $s = str_replace(") ", ")", $s);
    $s = str_replace(")", ") ", $s);
    $s = str_replace(array("/ ", " /"), "/", $s);
    $s = str_replace("/", " / ", $s);
    $s = str_replace(array("; ", " ;"), ";", $s);
    $s = str_replace(";", " ; ", $s);
    $s = str_replace("... ", "...", $s);
    $s = str_replace("...", "... ", $s);
    $s = str_replace("&amp ; ", "&amp;", $s);

    return $s;
}

function init()
{
    global $iskladba;
    global $ijazyk;
    global $iinstituce;
    global $nazevporadu;
    global $nastroj;
    global $specializace;
    global $orignazevskladby;
    global $authors_new;
    global $institutions;

    $id = 1;
    $iskladba = 0;
    $ijazyk = 0;
    $iinstituce = 0;
    $nazevporadu = array();
    $nastroj = array();
    $specializace = array();
    $orignazevskladby = array();
    $authors_new = array();
    $institutions = array();
}

function skladba($param)
{
    global $iskladba;
    global $ijazyk;
    global $iinstituce;
    global $nazevporadu;
    global $nastroj;
    global $specializace;
    global $orignazevskladby;
    global $result;
    global $spec_ini;
    global $authors_new;
    global $institutions;
    global $spec_delete;

    // languages
    foreach ($param->JAZYK as $lang) {
        $l = getLang(mb_strtolower(getElementText($lang)));
        if ($l) {
            if (isset($result["041"]["a"])) if (in_array($l, $result["041"]["a"])) {
                continue;
            }
            $result["041"]["a"][$ijazyk] = $l;
            ++$ijazyk;
        }

    }

    if (strlen(getElementText($param->NAZEV_PORADU)) > 0 && !isset($result["245"]["a"])) $result["245"]["a"] = getElementText($param->NAZEV_PORADU);
    if (strlen(getElementText($param->NAZEV_SKLADBY)) > 0 && !isset($result["SKLADBA"])) $result["SKLADBA"] = getElementText($param->NAZEV_SKLADBY);

    $result["505"][$iskladba - 1]["g"] = getElementText($param->UROVEN_SKLADBY);
    $result["505"][$iskladba - 1]["t"] = getElementText($param->NAZEV_SKLADBY);

    toArray($nazevporadu, getElementText($param->NAZEV_PORADU), "Z pořadu: ");
    toArray($orignazevskladby, getElementText($param->ORIGNAZEV_SKLADBY, ""));


    $i505r = 0;
    foreach ($param->PODIL as $podil) {
        // author
        $author = getAuthor(getElementText($podil->JMENO), getElementText($podil->PRIJMENI));
        $instituce = getElementText($podil->INSTITUCE);
        $typpodilu = getPodil(getElementText($podil->TYP_PODILU));
        if (strlen($author) > 0) {
            if (!isset($authors_new[$author])) $authors_new[$author] = array();
            if (!in_array($typpodilu, $authors_new[$author])) array_push($authors_new[$author], $typpodilu);

            $exist = false;
            if (!isset($result["505"][$iskladba - 1]["r"])) {
                $exist = false;
                //$result["505"][$iskladba-1]["r"] = array();
            } else $exist = authorIn505($result["505"][$iskladba - 1]["r"], getAuthor505(getElementText($podil->JMENO), getElementText($podil->PRIJMENI)));
            if (!$exist) $result["505"][$iskladba - 1]["r"][$i505r++] = getAuthor505(getElementText($podil->JMENO), getElementText($podil->PRIJMENI));

        } // instituce
        else {
            if (!isset($institutions[$instituce])) $institutions[$instituce] = array();
            if (!in_array($typpodilu, $institutions[$instituce])) array_push($institutions[$instituce], $typpodilu);
            ++$instituce;
        }

        toArray($nastroj, getElementText($podil->NASTROJ), "Nástroje: ");

        $spec = $podil->SPECIALIZACE->__toString();
        if (!isset($spec_delete[$spec])) {
            if ($spec == "Jiří Stivín & co.") $spec1 = "Jiří Stivín &amp; Co Jazz Quartet";
            else if (array_key_exists($spec, $spec_ini)) $spec1 = $spec_ini[$spec];
            else $spec1 = $spec;
            toArray($specializace, $spec1);
        }
    }
    ++$iskladba;
    foreach ($param->SKLADBA as $jelen) {
        skladba($jelen);
    }

}

$begin = time();


$xmlReader = simplexml_load_file($in_file, 'SimpleXMLElement', LIBXML_NOCDATA);

$xmlWriter = new XMLWriter();
$xmlWriter->openURI($out_file);
$xmlWriter->startDocument('1.0', 'UTF-8');
$xmlWriter->setIndent(true);
$xmlWriter->startElement('collection');

$id = 9829;
$iskladba = 0;
$ijazyk = 0;
$iinstituce = 0;
$nazevporadu = array();
$nastroj = array();
$specializace = array();
$orignazevskladby = array();
$result = array();
$authors_new = array();
$institutions = array();

foreach ($xmlReader->NOSIC as $item) {
    global $result;
    $result = array();
    $newFields = [
        "ZEME" => "a",
        "DATUM_VYDANI" => "c"
    ];
    foreach ($newFields as $field => $subfield) {
        if ($field == "DESCRIPTION") {

            if ($data = getData($item, $field)) {
                $data = str_replace("\n", "", $data);
                $result[$field][$subfield] = $data;
            }
        } else if ($d = getData($item, $field)) $result[$field][$subfield] = $d;
    }
    if (strlen(getElementText($item->VYRCE)) > 0) $result["260"]["b"] = getElementText($item->VYRCE);
    if (isset($result["DATUM_VYDANI"]["c"]) && strlen($result["DATUM_VYDANI"]["c"]) > 0) $result["260"]["c"] = $result["DATUM_VYDANI"]["c"];

    init();
    foreach ($item->SKLADBA as $param) {
        skladba($param);
    }
    $result = uniqValues($result, $specializace, "650", "a");
    $result = uniqValues($result, $nastroj, "NASTROJ", "a");
    $result = uniqValues($result, $nazevporadu, "NAZEV_PORADU", "a");
    $result = uniqValues($result, $orignazevskladby, "765", "t");

    $result["001"] = $id++;
    $result["005"] = date('YmdHis') . "0";
    $result["007"] = "s||||||||||||";

    $year = (isset($result["DATUM_VYDANI"]["c"]) && ($result["DATUM_VYDANI"]["c"]) > 0) ? $result["DATUM_VYDANI"]["c"] : "----";
    $lang = (isset($result["041"]["a"][0])) ? $result["041"]["a"][0] : "---";
    $result["008"] = "------n" . $year . "---------||--------|||||" . $lang . "-d";

    if (strlen($item->ORIG_CIS_NOSICE) > 0) {
        $result["028"]["a"] = $item->ORIG_CIS_NOSICE;
        $result["028"]["b"] = "Česká televize";
    }
    $result["040"] = get040();

    if (!isset($result["245"]["a"])) $result["245"]["a"] = $result["SKLADBA"];

    $result["300"]["a"] = "1 zvukový záznam";
    if (isset($result["505"]) && count($result["505"]) > 1) {
        $i = 0;
        foreach ($result["505"] as $f505) {
            $s = "";
            $s = $f505["t"] . " / ";
            if (isset($f505["r"])) {
                foreach ($f505["r"] as $r) $s .= $r . ", ";
                $s = substr($s, 0, strlen($s) - 2);
            }
            $s .= " (" . $f505["g"] . ") -- ";
            $s = str_replace("  ", " ", $s);
            $result["505new"][$i++]["a"] = $s;
        }
        if (($i = count($result["505new"])) > 0) {
            $result["505new"][$i - 1]["a"] = substr($result["505new"][$i - 1]["a"], 0, strlen($s) - 4);
        }
    }

    $i = 0;
    foreach ($authors_new as $key => $value) {
        if ($i == 0) {
            $result["100"]["a"] = $key;
            $result["100"]["4"] = $value;
        } else {
            $result["700"][$i - 1]["a"] = $key;
            $result["700"][$i - 1]["4"] = $value;
        }
        ++$i;
    }

    $i = 0;
    foreach ($institutions as $key => $value) {
        $result["710"][$i - 1]["a"] = $key;
        $result["710"][$i - 1]["e"] = $value;

        ++$i;
    }

    $xmlWriter->startElement('record');
    $xmlWriter->writeAttribute('xmlns', "http://www.loc.gov/MARC21/slim");
    $xmlWriter->writeAttribute('xmlns:xsi', "http://www.w3.org/2001/XMLSchema-instance");
    $xmlWriter->writeAttribute('xsi:schemaLocation', "http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd");
    $xmlWriter->startElement('leader');
    $xmlWriter->writeRaw("-----njm-a22-----2a-4500");
    $xmlWriter->endElement();

    $controlFields = [
        "001", "005", "007", "008"
    ];
    writeControlFields($result);
    /* r - repeated fields (700, 710)
       rs - repeated subfields (505)
    */
    $dataFields = [
        "028" => "0281 ",
        "040" => "040  ",
        "041" => "041  n",
        "100" => "1001 ",
        "245" => "24510",
        "260" => "2601 ",
        "300" => "300  ",
        "NAZEV_PORADU" => "500  r",
        "NASTROJ" => "500  r",
        "505new" => "50500rs",
        "650" => "650#4r",
        "700" => "7001#r",
        "710" => "7101#r",
        "765" => "7651 r",
    ];
    writeField($result);
    $xmlWriter->endElement(); // record
}
$xmlWriter->endElement(); // collection

?>
