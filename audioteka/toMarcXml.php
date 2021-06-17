<?php

$opt = getopt("i:o:");

if (isset($opt['i'])) $in_file = $opt['i'];
else die("-i: Input file is not set!\n");
if (isset($opt['o'])) $out_file = $opt['o'];
else die("-o: Output file is not set!\n");

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
    $a["a"] = "AUD";
    $a["b"] = "cze";
    $a["d"] = "BOA001";
    return $a;
}

function getAuthor($s)
{
    $a = array();
    // Jan Novak -> Novak, Jan
    if (preg_match('/^(\S+) (\S+)$/', $s, $names)) {
        $a["a"] = $names[2] . ", " . $names[1];
    } // Jan Novak ml.-> $aNovak, Jan$cml.
    else if (preg_match('/^(\S+) (\S+) (\S+\.)$/', $s, $names)) {
        $a["a"] = $names[2] . ", " . $names[1];
        $a["c"] = $names[3];
    } // Jan de Novak -> Novak, Jan de
    else if (preg_match('/^(S+) (de) (\S+)$/', $s, $names)) {
        $a["a"] = $names[3] . ", " . $names[1] . " " . $names[2];
    } // Jana Novakova Lahvinkova -> Novakova Lahvinkova, Jana
    else if (preg_match('/(S+)(( \S*ová){2,})/', $s, $names)) {
        $a["a"] = $names[2] . ", " . $names[1];
    } // Jan Karel Novotny -> Novotny, Jan Karel
    else if (preg_match('/(.*) (\S+)/', $s, $names)) {
        $a["a"] = $names[2] . ", " . $names[1];
    } // others
    else {
        $a["a"] = $s;
    }
    if ($s == "") return null;
    return $a;
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

$begin = time();

$categories = parse_ini_file("categories.ini", true);

$xmlReader = simplexml_load_file($in_file, 'SimpleXMLElement', LIBXML_NOCDATA);

$xmlWriter = new XMLWriter();
$xmlWriter->openURI($out_file);
$xmlWriter->startDocument('1.0', 'UTF-8');
$xmlWriter->setIndent(true);
$xmlWriter->startElement('collection');

$id = 1;
foreach ($xmlReader->SHOPITEM as $item) {
    $result = array();
    $newFields = [
        "PRODUCT" => "a",
        "MANUFACTURER" => "b",
        "DESCRIPTION" => "a",
        "URL" => "u",
    ];
    foreach ($newFields as $field => $subfield) {
        if ($field == "DESCRIPTION") {

            if ($data = getData($item, $field)) {
                $data = str_replace("\n", "", $data);
                $result[$field][$subfield] = $data;
            }
        } else if ($d = getData($item, $field)) $result[$field][$subfield] = $d;
    }
    $i = 0; // authors and interprets
    foreach ($item->PARAM as $param) {
        if ($param->PARAM_NAME == "Autor") {
            $authors = split(";", $param->VAL);
            $a = array();
            foreach ($authors as $author) {
                if ($a = getAuthor($author)) {
                    if ($i == 0) $result["100"] = $a;
                    if ($i > 0) {
                        $result["700"][$i - 1] = $a;
                    }
                    ++$i;
                }
            }
        }
        if ($param->PARAM_NAME == "Interpret") {
            $interprets = split(";", $param->VAL);

            foreach ($interprets as $interpret) {
                $result["700"][$i] = getAuthor($interpret);
                $result["700"][$i]["4"] = "adp";
                ++$i;
            }
        } // format: hhmmss
        if ($param->PARAM_NAME == "Délka") {
            preg_match('/([0-9]{1,2}) hod. ([0-9]{1,2}) min./', $param->VAL, $time);
            $hours = (intval($time[1]) < 10) ? "0$time[1]" : $time[1];
            $minutes = (intval($time[2]) < 10) ? "0$time[2]" : $time[2];
            $result["306"]["a"] = $hours . $minutes . "00";
        }
    }

    $cat = split("\|", getData($item, "CATEGORYTEXT"));
    if (array_key_exists(trim($cat[1]), $categories)) {
        $data = $categories[trim($cat[1])];
        foreach ($data as $key => $value) {
            $subfields = explode("\$", $value);
            foreach (array_slice($subfields, 1) as $subfield) {
                $result[$key][$subfield[0]] = trim(substr($subfield, 2));
            }
        }
    }
    $result["001"] = $id++;
    $result["040"] = get040();
    $result["007"] = "s||||||||||||";
    $result["008"] = "------n--------xr-nn||-s------|||||cze-d";
    $result["300"]["a"] = "elektronický zdroj";
    $result["URL"]["y"] = "Koupit nebo přehrát ukázku na www.audioteka.cz";

    $xmlWriter->startElement('record');
    $xmlWriter->writeAttribute('xmlns', "http://www.loc.gov/MARC21/slim");
    $xmlWriter->writeAttribute('xmlns:xsi', "http://www.w3.org/2001/XMLSchema-instance");
    $xmlWriter->writeAttribute('xsi:schemaLocation', "http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd");
    $xmlWriter->startElement('leader');
    $xmlWriter->writeRaw("-----nim-a22-----2ui4500");
    $xmlWriter->endElement();

    $controlFields = [
        "001", "007", "008"
    ];
    writeControlFields($result);
    /* r - repeated fields (700, 710)
       rs - repeated subfields (505)
    */
    $dataFields = [
        "040" => "040  ",
        "100" => "1001 ",
        "PRODUCT" => "24510",
        "MANUFACTURER" => "260  ",
        "300" => "300  ",
        "306" => "306  ",
        "DESCRIPTION" => "520  ",
        "65007" => "65007",
        "653" => "653  ",
        "700" => "7001 r",
        "URL" => "856  ",
    ];
    writeField($result);
    $xmlWriter->endElement(); // record
}
$xmlWriter->endElement(); // collection

?>
