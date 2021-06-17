<?php
$options = getopt("o:i:");

$file = $options['i'];
$xml = simplexml_load_file($file);

$timeStart = time();
$cachedIndexes = array();
$cachedFields = array('Osoba', 'Role', 'Jazyk', 'HudebniTeleso', 'HTTyp', 'OceneniAlba', 'OceneniSnimku', 'Subjekt', 'ZanrZakladni', 'ZanrAD', 'ZanrAlbum', 'ZanrEx', 'ZanrSnimek',);
$cache = array();
foreach ($cachedFields as $field) {
    $cache[$field] = array();
}

$l2CachedFields = array('Snimky' => 'Snimek', 'AutorskaDila' => 'Item', 'MistoNahrani' => 'Item');
$l2CachedIndexes = array();
$l2Cache = array();
foreach ($l2CachedFields as $field => $value) {
    $l2Cache[$field] = array();
}

function binarySearch($needle, $array)
{
    $start = 0;
    $end = count($array) - 1;

    while ($start <= $end) {
        $middle = (int)($start + ($end - $start) / 2);
        if ($needle < $array[$middle]) {
            $end = $middle - 1;
        } else if ($needle > $array[$middle]) {
            $start = $middle + 1;
        } else {
            return $needle;
        }
    }

    return false;
}


function getCiselnik($id, $name)
{
    global $xml, $cache, $cachedFields, $cachedIndexes;

    if (in_array($name, $cachedFields) && ($key = binarySearch($id, $cachedIndexes[$name])) != false) {
        $result = $cache[$name][$key];
    } else {
        $result = $xml->xpath("/Katalog/Ciselnik_$name/Item[@ID='$id']");
    }
    return $result[0];
}

function getCiselnik_HudebniTeleso($id)
{
    global $xml;


    $result = $xml->xpath("/Katalog/Ciselnik_HudebniTeleso/Item[@ID='$id']");
    $typ = getCiselnik($result[0]->attributes()->HTTyp->__toString(), 'HTTyp');
    return array('Typ' => $typ,
        'Nazev' => $result[0]->__toString()
    );
}

function getCiselnik_Zanr($id, $name)
{
    if ($name == 'ZanrZakladni') {
        return getCiselnik($id, 'ZanrZakladni');
    }
    $zanr = getCiselnik($id, $name);
    $zakladni = getCiselnik($zanr->attributes()->ZanrZakladni->__toString(), 'ZanrZakladni');
    return array(
        $name => $zanr->__toString(),
        'ZanrZakladni' => $zakladni->__toString()
    );
}

function getL2CacheValue($id, $name)
{
    global $l2Cache, $l2CachedIndexes;
    if (($key = binarySearch($id, $l2CachedIndexes[$name])) != false) {
        return $l2Cache[$name][$key];
    }
    //throw new Exception("L2 cache seachr failded: $id not found for $name");
    return null;
}


function getMistoNahrani($id)
{
    global $xml;
    $result = getL2CacheValue($id, 'MistoNahrani');
    //$result = $xml->xpath("/Katalog/MistoNahrani/Item[@ID='$id']");
    return $result[0]->__toString();
}

function processOsoba(&$xml, $element, $target = null)
{
    $first = true;
    if ($target == null) {
        $target = $xml->$element;
    }
    foreach ($xml->$element as $current) {
        if ($first) {
            $osoby = $target->addChild("Osoby");
        }
        foreach ($current->Item as $item) {
            $osoba = $osoby->addChild("Osoba");
            foreach (getCiselnik($item->attributes()->ID_Osoba->__toString(), 'Osoba') as $name => $value) {
                $osoba->addChild($name, htmlspecialchars($value));
            }
            $osoba->addChild('SupraphonID', $item->attributes()->ID_Osoba->__toString());
            $role = getCiselnik($item->attributes()->ID_Role->__toString(), 'Role')->__toString();
            $osoba->addChild("Role", htmlspecialchars($role));
            $first = false;
        }
    }
}

function processHudebniTeleso(&$xml, $element, $target = null)
{
    $first = true;
    if ($element == 'StezejniHudebniTelesa') {
        $attName = 'ID_HT';
    } else {
        $attName = 'ID';
    }
    foreach ($xml->$element as $current) {
        if ($first) {
            if ($target == null) {
                $hudebniTelesa = $current;
            } else {
                $hudebniTelesa = $target;
            }
        }
        foreach ($current->Item as $item) {
            $hudebniTeleso = $hudebniTelesa->addChild('HudebniTeleso');
            foreach (getCiselnik_HudebniTeleso($item->attributes()->$attName->__toString()) as $name => $value) {
                $hudebniTeleso->addChild($name, htmlspecialchars($value));
            }

            processOsoba($item, 'Ridici', $hudebniTeleso);
            processOsoba($item, 'Hudebnici', $hudebniTeleso);
        }
        $first = false;
    }
}

function processZanr(&$xml, $id, $name, $parent)
{
    $zanr = getCiselnik_Zanr($id, $name);
    $child = $xml->addChild($parent);
    foreach ($zanr as $element => $value) {
        $child->addChild($element, htmlspecialchars($value));
    }
}

function isTextNode($content)
{
    $trimmed = trim((string)$content);
    return !empty($trimmed);
}

function processTextNodes(&$xml, $target)
{
    foreach ($xml as $element => $content) {
        if (isTextNode($content)) {
            $target->addChild($element, htmlspecialchars((string)$content));
        }
    }
}

function processOceneni(&$xml, $typ, $target = null)
{
    $first = true;
    foreach ($xml->Oceneni as $cena) {
        foreach ($cena->Item as $item) {
            if ($first) {
                if ($target == null) {
                    $rodic = $xml->Oceneni;
                } else {
                    $rodic = $target->addChild('OceneniRodic');
                }
            }
            $oceneni = getCiselnik($item->attributes()->ID->__toString(), 'Oceneni' . $typ);
            $rodic->addChild('Oceneni', htmlspecialchars($oceneni->__toString()));
            $first = false;
        }
    }
}

function processAutorskeDilo($position, $id)
{
    global $xml;
    $first = true;
    // $dilo = $xml->xpath("/Katalog/AutorskaDila/Item[@ID='$id']");
    $dilo = getL2CacheValue($id, 'AutorskaDila');
    $autorskeDilo = $position->addChild('AutorskeDilo');
    if (!$dilo || !$dilo[0]) return;
    if ($dilo[0]->Autori->count() == 0) return;
    processOsoba($dilo[0], 'Autori');
    foreach ($dilo[0]->Autori->Osoby->Osoba as $osoba) {
        if ($first) {
            $osoby = $autorskeDilo->addChild('Osoby');
            $first = false;
        }
        $current = $osoby->addChild('Osoba');
        processTextNodes($osoba, $current);
    }
}

function processSnimek(&$position, $id)
{
    global $xml;
    // $current = $xml->xpath("/Katalog/Snimky/Snimek[@ID='$id']");

    $current = getL2CacheValue($id, 'Snimky');
    if (!$current || !$current[0]) return;

    foreach ($current[0]->attributes() as $att => $value) {
        switch ($att) {
            case 'ID':
                //$position->addChild('Snimek_ID', $value->__toString());
                break;
            case 'Jazyk':
                $position->addChild('Jazyk', htmlspecialchars(getCiselnik($value->__toString(), 'Jazyk')));
                break;
            case 'MistoNahrani':
                $position->addChild('MistoNahrani', htmlspecialchars(getMistoNahrani($value->__toString())));
                break;
            case 'Subjekt_PuvodniVydavatel':
                $position->addChild('PuvodniVydavatel', htmlspecialchars(getCiselnik($value->__toString(), 'Subjekt')));
                break;
            case 'Subjekt_Vlastnik':
                $position->addChild('Vlastnik', htmlspecialchars(getCiselnik($value->__toString(), 'Subjekt')));
                break;
            case 'Subjekt_Vydavatel':
                $position->addChild('Vydavatel', htmlspecialchars(getCiselnik($value->__toString(), 'Subjekt')));
                break;
            case 'ZanrEX':
                processZanr($position, $value->__toString(), 'ZanrEX', 'Zanr');
                break;
            case 'ZanrSnimek':
                processZanr($position, $value->__toString(), 'ZanrSnimek', 'Zanr');
                break;
            case 'AutorskeDilo':
                processAutorskeDilo($position, $value->__toString());
                break;
        }
    }

    foreach ($current[0] as $element => $content) {
        if (isTextNode($content) && $position->$element->count() == 0) {
            $position->addChild($element, htmlspecialchars($content));
        }

        switch ($element) {
            case 'AutoriDruhotni' : //fallthrough
            case 'Interpreti' :
                processOsoba($current[0], $element);
                if ($current[0]->Autori->count() == 0) break;
                foreach ($current[0]->Autori->Osoby->Osoba as $osoba) {
                    if ($first) {
                        $osoby = $position->addChild('Osoby');
                        $first = false;
                    }
                    $new = $osoby->addChild('Osoba');
                    processTextNodes($osoba, $new);
                }
                break;

            case 'HudebniTelesa' :
                $telesa = $position->addChild($element);
                processHudebniTeleso($current[0], $element, $telesa);
                break;

            case 'Oceneni' :
                $oceneni = $position->addChild($element);
                processOceneni($current[0], 'Snimku', $oceneni);

                break;
        }
    }


    //processTextNodes($position, $position);

}

function processSkladby(&$xml)
{
    $skladby = $xml->Skladby;
    foreach ($skladby->Skladba as $skladba) {
        $snimek = $skladba->attributes()->Snimek;
        if (!$snimek) continue;
        processSnimek($skladba, $snimek->__toString());
    }
}


$count = 0;
$fout = fopen($options['o'], "w");
if (!$fout) {
    die("cannot open output file");
}

//build caches
foreach ($cachedFields as $field) {
    $fieldName = 'Ciselnik_' . $field;
    foreach ($xml->$fieldName->Item as $current) {

        $id = $current->attributes()->ID->__toString();
        $cache[$field][$id] = $current;
    }
}

foreach ($cachedFields as $field) {
    ksort($cache[$field]);
    $cachedIndexes[$field] = array_keys($cache[$field]);
}

//build level 2 caches
foreach ($l2CachedFields as $field => $value) {
    foreach ($xml->$field->$value as $current) {
        $id = $current->attributes()->ID->__toString();
        $l2Cache[$field][$id] = $current;
    }
}

foreach ($l2CachedFields as $field => $value) {
    ksort($l2Cache[$field]);
    $l2CachedIndexes[$field] = array_keys($l2Cache[$field]);
}

fwrite($fout, "<Alba>" . PHP_EOL);
foreach ($xml->Alba->Item as $album) {
    //---attributy
    $content = $album->asXML();
    $copy = new SimpleXMLElement($content);
    $copy->addChild('ID', htmlspecialchars($album->attributes()->ID->__toString()));
    $copy->
    addChild('SubjektVydavatel', htmlspecialchars(getCiselnik($copy->attributes()->SubjektVydavatel->__toString()
        , 'Subjekt')->__toString()));

    $vlastnik = $copy->attributes()->SubjektVlastnikPrav;
    if ($vlastnik) {
        $copy->
        addChild('SubjektVlastnikPrav', htmlspecialchars(getCiselnik($vlastnik->__toString()
            , 'Subjekt')->__toString()));
    }

    $katCislo = $copy->KatCislo;
    if ($katCislo) {
        $katCislo = $katCislo->__toString();
        $online = $copy->Supraphonline;
        if ($online) {
            $online = $online->__toString();
            if ($online == '1') {
                $copy->addChild('Online', 'www.supraphonline.cz/?catnumber=' . $katCislo);
            }
        }
    }
    //-----
    $zanr = $copy->attributes()->ZanrAlbum;
    if ($zanr) {
        processZanr($copy, $zanr->__toString(), 'ZanrAlbum', 'Zanr');
    }
    $zanr = $copy->attributes()->ZanrEx;
    if ($zanr) {
        processZanr($copy, $zanr->__toString(), 'ZanrEx', 'Zanr');
    }
    processOsoba($copy, 'StezejniAutori');
    processOsoba($copy, 'Dalsi');
    processOsoba($copy, 'StezejniInterpreti');
    processHudebniTeleso($copy, 'StezejniHudebniTelesa');
    processOceneni($copy, 'Alba');

    processSkladby($copy);
    $dom = new DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($copy->asXML(), LIBXML_NOENT);
    $dom->encoding = 'utf-8';

    fwrite($fout, $dom->saveXML($dom->documentElement));
    fwrite($fout, "\n");
    if (++$count % 100 == 0) {
        print($date = date('m/d/Y h:i:s a', time()) . ' ' . $count . ' albums processed, average speed ' . $count / (time() - $timeStart) . ' albums/s' . "\n");
        //exit;
    }
}

fwrite($fout, PHP_EOL . '</Alba>');
