<?php

$opt = getopt("i:o:");

if (isset($opt['i'])) $in_file = $opt['i'];
else die("-i: Input file is not set!\n");
if (isset($opt['o'])) $out_file = $opt['o'];
else die("-o: Output file is not set!\n");

$role_ini = parse_ini_file("role.ini");

$tagsAttributes = [
    "order" => "Order",
    "level" => "Level",
    "001" => "Id",
    "ident" => "Identification",
];
/*
"isrc" => "Sound-Isrc-Id-a",

<Sound>
  <Isrc Id="28334"/>  ==> $a[isrc][a] = 28334
</Sound>

*/
$tagsAttributesSingle = [
    "isrc" => "Sound-Isrc-Id-a",
    "country" => "Sound-Isrc-Country",
    "titles" => "Titles-Title-Name",
    "245" => "Titles-Title-Name-a",
    "year" => "Sound-Isrc-Year",
    "category" => "Categories-Category-Name-a",
];

// get value from attribute
function getAttribute($data, $attribute)
{
    if (!$data) return null;
    if ($attr = $data->attributes()->$attribute) return str_replace("&", "&amp;", $attr->__toString());
    else return null;
}

// get value from element
function getData($xmlReader, $data)
{
    $result = null;
    $a = explode("-", $data);
    $parent = $a[0];
    $child = $a[1];
    if (!isset($xmlReader->$parent)) return $result;
    $result = $xmlReader->$parent->$child->__toString();
    $result = str_replace("<br>", "", $result);
    $result = str_replace("<br.", "", $result);
    $result = str_replace("</b>", "", $result);
    $result = str_replace("<b>", "", $result);
    $result = str_replace("<b.", "", $result);
    $result = trim(preg_replace('/\s+/', ' ', $result));
    return htmlspecialchars($result);
}

function getDataFromAttribute($part, $data, $isArray = false)
{
    $result = null;
    $a = explode("-", $data);
    $parent = $a[0];
    $child = $a[1];
    $attribute = $a[2];
    $subfield = null;
    if (array_key_exists(3, $a)) {
        $subfield = $a[3];
    }

    if ($isArray) $result = array();
    else $result = null;

    if (!isset($part->$parent)) return $result;

    if ($isArray) {
        $i = 0;
        foreach ($part->$parent->$child as $data) {
            if ($subfield) $result[$subfield][$i] = getAttribute($data, $attribute);
            else $result[$i] = getAttribute($data, $attribute);
            $i++;
        }
    } else {
        $data = $part->$parent->$child;
        if ($subfield) $result[$subfield] = getAttribute($data, $attribute);
        else $result = getAttribute($data, $attribute);
    }

    return $result;
}

/**
 * <Part>
 *   <Priorities>
 *     <Priorita>
 *       <Person First="Bedřich" Last="Smetana"/>
 *     </Priorita>
 *   </Priorities>
 * <Part>
 *
 * @return "Last, First"
 */
function getAuthor($part)
{
    if ($part->Priorities)
        if ($part->Priorities->Priorita)
            if ($person = $part->Priorities->Priorita->Person) {
                $first = getAttribute($person, "First");
                $last = getAttribute($person, "Last");
                $a["a"] = "$last, $first";
                return $a;
            }
}

function getLeader(&$result)
{
    $id = substr($result[1]["ident"], 3, 2);
    if ($id === "EH" || $id === "SH") {
        $result[1]["ldr"] = "-----nim-a22-----2uj4500";
        return "HUDBA";
    }
    if ($id === "ES" || $id === "SS") {
        $result[1]["ldr"] = "-----nim-a22-----2ui4500";
        return "MS";
    }
}

function get008($from, $to)
{
    return "------p" . $from . $to . "xr-||----------|||--und-d";
}

/**
 * <Part>
 *   <Sound>
 *     <Isrc Country="CZ"/>
 *   </Sound>
 * </Part>
 *
 * @return all country tags from record
 */
function getCountries($array)
{
    $countries = array();
    foreach ($array as $part) {
        if (array_key_exists("country", $part)) {
            if (!array_key_exists($part["country"], $countries)) $countries[$part["country"]] = 1;
        }
    }
    $result["a"] = array();
    foreach ($countries as $key => $value) {
        array_push($result["a"], $key);
    }
    return $result;
}

/*
  > 15 => 19xx
  <= 15 => 20xx
  
  return all years
*/
function getYears($array)
{
    $years = array();
    foreach ($array as $part) {
        if (array_key_exists("year", $part)) {
            $year = $part["year"];
            if (strlen($year) === 2) {
                if (intval($year) > 15) $year = "19" . $year;
                else $year = "20" . $year;
            }
            if (!array_key_exists($year, $years)) $years[$year] = 1;
        }
    }
    $result["c"] = array();
    foreach ($years as $key => $value) {
        array_push($result["c"], $key);
    }
    return $result;
}

function getAnotation($part)
{
    $s = getData($part, "Anotations-Anotation");
    $data["a"] = trim($s);
    return $data;
}

function getActivity($part, &$result, $type)
{
    global $role_ini;
    if (($creators = $part->Creators) && $creators->Creator) {
        foreach ($creators->Creator as $creator) {
            $a = array();
            if ($type === "person") {
                if ($creator->Person) {
                    $first = getAttribute($creator->Person, "First");
                    $last = getAttribute($creator->Person, "Last");
                    if ($first && $last) $a["a"] = "$last, $first";
                    if ($act = $creator->Activity) {
                        $role = getAttribute($act, "Name");
                        if (array_key_exists($role, $role_ini)) $a["4"] = $role_ini[$role];
                    }
                }
            } // "person"
            else if ($type === "organization") {
                if ($org = $creator->Oganization) {
                    $a["a"] = getAttribute($org, "Name");
                }
                if ($act = $creator->Activity) {
                    $role = getAttribute($act, "Name");
                    if (array_key_exists($role, $role_ini)) $a["4"] = $role_ini[$role];
                }
            } // else if

            if (!isset($a["a"]) || !isset($a["4"])) continue;
            $b = false;
            foreach ($result as $res) {
                if ($res["a"] === $a["a"] && $res["4"] === $a["4"]) $b = true;
            }
            if (!$b) array_push($result, $a);

        } // foreach
    }
}

function get505($data, $type)
{
    $result = null;
    $numberLevels = 0;
    if ($type === "HUDBA") $numberLevels = 3;
    else if ($type === "MS") $numberLevels = 2;
    $i = 0;
    foreach (array_slice($data, 1) as $field) {
        if (($field["level"] <= $numberLevels)) {
            $result[$i]["t"] = $field["titles"];
            $result[$i]["g"] = "(" . $field["length"] . ")";

            ++$i;
        }
    }
    return $result;
}

function getUrl($data)
{
    $a["u"] = "http://www.radioteka.cz/detail/CRo_xml_$data/";
    $a["y"] = "Koupit na www.radioteka.cz";
    return $a;
}

function get040()
{
    $a["a"] = "SUP";
    $a["b"] = "cze";
    $a["d"] = "BOA001";
    return $a;
}

function getIsrc($data)
{
    $result["a"] = array();
    foreach (array_slice($data, 1) as $field) {
        if (array_key_exists("isrc", $field)) {
            if (strlen($field["isrc"]) == 5) array_push($result["a"], $field["isrc"]);
        }
    }
    return $result;
}

function getYearFromCopyright($part, $type)
{
    if ($copy = $part->Copyrights->Copyright) {
        foreach ($copy as $c) {
            if (getAttribute($c, "Type") === $type) {
                $year = getAttribute($c, "Year");
                if (($year == null) || (strlen($year) != 4)) {
                } else return $year;
            }
        }
    }
}

function getOrganizationName($part)
{
    if ($copy = $part->Copyrights->Copyright) {
        foreach ($copy as $c) {
            if (getAttribute($c, "Type") === "C") {
                if ($result = getAttribute($c->Oganization, "Name")) return $result;
            }
        }
    }
}

function parseYears($data, $type)
{
    if (array_key_exists($type, $data[1])) {
        if (preg_match("/[0-9]{4}/", $data[1][$type])) return $data[1][$type];
    }
    $result = null;
    if ($type === "secondYear") {
        foreach ($data as $field) {
            if (array_key_exists($type, $field)) {
                if ($result === null) $result = $field[$type];
                if (intval($field[$type]) && (intval($field[$type]) < ($result))) $result = $field[$type];
            }
        }
    }

    if (preg_match("/[0-9]{4}/", $result)) return $result;
    else return "----";
}

// output format hhmmss
function getLength($data)
{
    $length = getAttribute($data, "Length");
    $hours = null;
    $time = split(":", $length);
    $minutes = intval($time[0]);

    $hours = floor($minutes / 60);
    $minutes = $minutes % 60;
    if ($hours < 10) $hours = "0$hours";
    if ($minutes < 10) $minutes = "0$minutes";
    return "$hours$minutes$time[1]";

}

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
        if ($type === "rs") {
            foreach ($data as $subfield) {
                foreach ($subfield as $code => $value) {
                    if (is_array($value)) {
                        foreach ($value as $val) {
                            writeSubField($code, $val);
                        }
                    } else {
                        writeSubField($code, $value);
                    }
                } // for $subfield
            } // for $data
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
    else {
        writeSubField($code, $data);
    }
    $xmlWriter->endElement();
}

function writeField($result)
{
    global $marcFields;
    global $xmlWriter;
    foreach ($marcFields as $oldTag => $newTag) {
        $ind1 = substr($newTag, 3, 1);
        $ind2 = substr($newTag, 4, 1);
        $tag = substr($newTag, 0, 3);
        if (($len = strlen($newTag)) > 5) {
            $type = substr($newTag, 5, $len - 5);
        } else $type = "n"; // normal
        if (!array_key_exists($oldTag, $result)) continue;

        if ($type === "r") { // repeated
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

$xmlReader = simplexml_load_file($in_file, 'SimpleXMLElement', LIBXML_NOCDATA);
$xmlWriter = new XMLWriter();
$xmlWriter->openURI($out_file);
$xmlWriter->startDocument('1.0', 'UTF-8');
$xmlWriter->setIndent(true);

$xmlWriter->startElement('collection');

$result = array();
$actPerson = array();
$actOrganization = array();
foreach ($xmlReader->Parts as $parts) {
    $i = 1;
    $type = null;
    foreach ($parts->Part as $part) {
        $attr = $part->attributes();

        if ($i === 1) {
            foreach ($tagsAttributes as $key => $value) {
                $data = explode("-", $key);

                if (sizeof($data) > 1) {
                    if ($attr->$value) $result[$i][$data[0]][$data[1]] = getAttribute($part, $value);
                } else if ($attr->$value) $result[$i][$data[0]] = getAttribute($part, $value);

                if (isset($result[$i]["ident"])) {
                    $type = getLeader($result);
                }
            }
            $result[$i]["306"]["a"] = getLength($part);
            $result[$i]["260"]["b"] = getOrganizationName($part);
            $result[$i]["anotation"] = getAnotation($part);
            $result[$i]["url"] = getUrl($result[1]["001"]);
            $auth = getAuthor($part);
            if ($auth) $result[$i]["author"] = $auth;
        }
        $result[$i]["level"] = getAttribute($part, "Level");
        $result[$i]["length"] = getAttribute($part, "Length");

        foreach ($tagsAttributesSingle as $key => $value) {
            $data = getDataFromAttribute($part, $value);
            if ($data) $result[$i][$key] = $data;
        }

        getActivity($part, $actPerson, "person");
        getActivity($part, $actOrganization, "organization");
        $result[$i]["secondYear"] = getYearFromCopyright($part, "P");
        $result[$i]["firstYear"] = getYearFromCopyright($part, "C");

        ++$i;
    }
    $result[1]["secondYear"] = parseYears($result, "secondYear");
    $result[1]["firstYear"] = parseYears($result, "firstYear");
    $result[1]["008"] = get008($result[1]["firstYear"], $result[1]["secondYear"]);
    $result[1]["260"]["c"] = $result[1]["firstYear"];
    $result[1]["007"] = "s||||||||||||";
    $result[1]["300"]["a"] = "elektronický zdroj";
    $result[1]["040"] = get040();
    $result[1]["actPerson"] = $actPerson;
    $result[1]["actOrganization"] = $actOrganization;
    $result[1]["countries"] = getCountries($result);
    $result[1]["year"] = getYears($result);
    if ($res = get505($result, $type)) $result[1]["505"] = $res;
//    print_r($result);
    $result = $result[1];
//    print_r($result);
}

$xmlWriter->startElement('record');
$xmlWriter->writeAttribute('xmlns', "http://www.loc.gov/MARC21/slim");
$xmlWriter->writeAttribute('xmlns:xsi', "http://www.w3.org/2001/XMLSchema-instance");
$xmlWriter->writeAttribute('xsi:schemaLocation', "http://www.loc.gov/MARC21/slim http://www.loc.gov/standards/marcxml/schema/MARC21slim.xsd");
if (array_key_exists("ldr", $result)) {
    $xmlWriter->startElement('leader');
    $xmlWriter->writeRaw($result["ldr"]);
    $xmlWriter->endElement();
}

$controlFields = [
    "001", "007", "008"
];
writeControlFields($result);
/* r - repeated fields (700, 710)
   rs - repeated subfields (505)
*/
$marcFields = [
    "isrc" => "024  ",
    "040" => "040  ",
    "countries" => "044  ",
    "author" => "1001 ",
    "245" => "24510",
    "260" => "260  ",
    "300" => "300  ",
    "306" => "306  ",
    "505" => "50500rs",
    "anotation" => "5203 ",
    "category" => "653  ",
    "actPerson" => "700  r",
    "actOrganization" => "710  r",
    "url" => "856  ",
];
writeField($result);
$xmlWriter->endElement(); // record

$xmlWriter->endElement(); // collection

?>
