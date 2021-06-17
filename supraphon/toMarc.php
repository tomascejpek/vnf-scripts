<?php
require '../classes/MetadataUtils.php';
$options = getopt("o:i:m:");

$vypustenaPrijmeni = array('Anonym', 'Lidová česká', 'Lidová moravská', 'Lidová', 'Liturgický text');
$role_ini = parse_ini_file("role.ini");
$lang_ini = parse_ini_file("lang.ini");
$lang_ini ['provensálsky (occitanština)'] = 'oci';
$lang_ini ['různé (česky, anglicky, romsky)'] = 'mul';
$format_ini = parse_ini_file('format.ini', true);

const SUBFIELD_INDICATOR = "\x1F";
$updateDate = date('Ymdhis.0', time());

function getNext(&$file)
{
    while (!feof($file)) {
        fseek($file, ftell($file));
        $line = stream_get_line($file, 99999999, '</Item>');
        return strlen($line) == 9 ? false : $line . '</Item>';  //wTF?
    }
    return false;
}

function compareFields($a, $b)
{
    if ($a == 'LDR  ') return -1;
    if ($b == 'LDR  ') return 1;
    return strcmp($a, $b);
}

function parseOsoba(&$xml, &$marc)
{
    global $role_ini;
    $jmeno = $xml->Jmeno;
    if ($jmeno) {
        $jmeno = $jmeno->__toString();
    } else {
        unset($jmeno);
    }

    global $vypustenaPrijmeni;
    $prijmeni = $xml->Prijmeni;
    if ($prijmeni) {
        $prijmeni = $prijmeni->__toString();
        if (preg_match("/^lidová/i", $prijmeni)) {
            addToMarc($marc, '653  ', array('a' => strtolower($prijmeni)));
            return array();
        }
        if (in_array($prijmeni, $vypustenaPrijmeni)) {
            return array();
        }
    }

    $result = array();
    $result['a'] = $prijmeni;
    if (isset($jmeno)) {
        $result['a'] .= ', ' . $jmeno;
    }

    $narozeni = $xml->DatumNarozeni;
    if ($narozeni) {
        $narozeni = MetadataUtils::extractYear($narozeni->__toString());
        if ($narozeni) {
            $result['f'] = $narozeni . '-';
        }
    }

    $umrti = $xml->DatumUmrti;
    if ($umrti) {
        $umrti = MetadataUtils::extractYear($umrti->__toString());
        $result['f'] = empty ($result['f']) ? '' : $result['f'];
        if ($umrti) {
            $result['f'] .= $umrti;
        }
    }

    $role = $xml->Role;
    if ($role) {
        $role = $role->__toString();
        if (array_key_exists($role, $role_ini)) {
            $result['4'] = $role_ini[$role];
        }
        $result['e'] = $role;
    }

    $supraphonID = $xml->SupraphonID;
    if ($supraphonID) {
        $result['7'] = 'sup' . $supraphonID->__toString();
    }

    return $result;
}

function parseHudebniTeleso(&$marc, $teleso)
{
    $result = array();
    $nazev = $teleso->Nazev;
    if ($nazev) {
        $nazev = $nazev->__toString();
        if (in_array($nazev, array("sbor", "smyčce"))) {
            return;
        }
        addToMarc($marc, '7102 ', array('a' => $nazev), true);
    }

    $osoby = $teleso->Osoby;
    if ($osoby) {
        foreach ($osoby->Osoba as $osoba) {
            $current = parseOsoba($osoba, $marc);
            if (!empty($current)) {
                addToMarc($marc, '7001 ', $current, true);
            }
        }
    }
}

function addTo008(&$marc, $insert, $start, $end)
{
    if (!(array_key_exists('008  ', $marc))) {
        $field = str_pad('', 39, ' ', STR_PAD_LEFT);
    } else {
        $field = $marc['008  '];
    }
    $marc['008  '] = substr($field, 0, $start) . $insert . substr($field, $end + 1);
}


function addSimpleNode(&$marc, $node, $field, $subfield, $new = false)
{
    if ($node) {
        addToMarc($marc, $field, array($subfield => $node->__toString()), $new);
    }
}

function getMaxKey(&$array)
{
    $keys = array_keys($array);
    return empty($keys) ? 0 : max($keys);
}

function addToMarc(&$marc, $field, $insert, $new = false, $repeated = false)
{
    if (!array_key_exists($field, $marc)) {
        $marc[$field] = array();
    }

    if ($new == true) {
        $marc[$field][] = array();
        foreach ($insert as $subfield => $value) {
            $marc[$field][getMaxKey($marc[$field])][$subfield] = $value;
        }
    } else {
        $key = getMaxKey($marc[$field]);
        if ($key == 0) {
            $marc[$field] = empty($marc[$field]) ? array() : $marc[$field];
        }
        foreach ($insert as $subfield => $value) {
            if ($repeated) {
                if (isset($marc[$field][$key][$subfield])) {
                    $value = $marc[$field][$key][$subfield] . SUBFIELD_INDICATOR . $value;
                }
            }
            $marc[$field][$key][$subfield] = $value;
        }
    }
}

function convertStopaz($stopaz)
{
    if (!is_numeric($stopaz)) {
        return '';
    }

    $hours = floor($stopaz / 3600);
    $stopaz %= 3600;
    $minutes = floor($stopaz / 60);
    $stopaz %= 60;

    return str_pad($hours, 2, '0', STR_PAD_LEFT) . str_pad($minutes, 2, '0', STR_PAD_LEFT) . str_pad($stopaz, 2, '0', STR_PAD_LEFT);
}


function getLangs($lang)
{
    global $lang_ini;
    $result = array();

    if (!isset($lang_ini[$lang])) {
        print("Missing language '$lang'" . PHP_EOL);
        return $result;
    }
    $exploded = explode('.', $lang_ini[$lang]);
    foreach ($exploded as $current) {
        $result[] = $current;
    }

    return $result;
}

$uid = 1;
$fin = fopen($options['i'], "r");
$fout = fopen($options['o'], "w");
$mode = $options['m'];
$internalMode = false;
if (strcasecmp('internal', $mode) == 0) {
    $internalMode = true;
}

fseek($fin, strlen("<Alba>"));
$albumCount = $skladbaCount = 0;
$startTime = time();
while (($record = getNext($fin)) != false) {
    $records = array();

    $xml = simplexml_load_string($record);
    unset($record);
    $parentMarc = array();
    $records[] = &$parentMarc;

    $id = $xml->ID->__toString();
    $id = str_pad($id, 9, 0, STR_PAD_LEFT);
    $parentLDR = '-----njm-a22-----2ua4500';
    $parentMarc['001  '] = $id;
    $parentMarc['007  '] = 's|^|||||||||||';
    $parentMarc['008  '] = '------s--------xr-||----------|||--und-d';

    $katCislo = $xml->KatCislo;
    if ($katCislo) {
        addToMarc($parentMarc, '0280 ', array('a' => $katCislo->__toString(), 'b' => 'Supraphon'));
    }
    addSimpleNode($parentMarc, $xml->Nazev, '24510', 'a');
    addSimpleNode($parentMarc, $xml->UPC, '0241 ', 'a');
    addSimpleNode($parentMarc, $xml->ISRC, '0240 ', 'a');
    addSimpleNode($parentMarc, $xml->EAN, '0243 ', 'a');
    addSimpleNode($parentMarc, $xml->Online, '85642', 'u');
    addSimpleNode($parentMarc, $xml->SubjektVydavatel, '260  ', 'b');

    addToMarc($parentMarc, '85642', array('u' => $xml->Online->__toString(), 'y' => 'Koupit nebo přehrát na www.supraphonline.cz.'));
    $vydano = $xml->Vydano;
    if ($vydano) {
        $vydano = MetadataUtils::extractYear($vydano->__toString());
        if ($vydano) {
            $rokVydani = $vydano;
            addTo008($parentMarc, 's' . $vydano, 6, 10);
            addToMarc($parentMarc, '260  ', array('c' => $vydano), false);
        } else {
            unset($vydano);
        }
    }

    addSimpleNode($parentMarc, $xml->Dokonceni, '0260#', 'g', false);

    $poprveVydano = $xml->PrvniRokVydani;
    if ($poprveVydano) {
        $poprveVydano = MetadataUtils::extractYear($poprveVydano->__toString());
        if ($poprveVydano) {
            addToMarc($parentMarc, '500  ', array('a' => 'Vydáno poprvé v roce ' . $poprveVydano), true);
            if ($poprveVydano != $rokVydani) {
                addTo008($parentMarc, 'p' . $rokVydani . $poprveVydano, 6, 14);
            }
        }
    }

    $kompilace = $xml->Kompilace;
    if ($kompilace) {
        $kompilace = $kompilace->__toString();
        if ($kompilace == '1') {
            addToMarc($parentMarc, '500  ', array('a' => 'Kompilace'), true);
        }
    }

    $anotace = $xml->Anotace;
    if ($anotace) {
        $anotaceString = $anotace->__toString();
        $fixed = str_replace(array("\r\n", "\n"), "EOL_ENT", $anotaceString);
        $anotaceString = $fixed == null ? $anotaceString : $fixed;
        addToMarc($parentMarc, '520  ', array('a' => $anotaceString));
    }


    $first = true;
    $stezejniAutori = $xml->StezejniAutori;
    if ($stezejniAutori && $stezejniAutori->Osoby) {
        foreach ($stezejniAutori->Osoby->Osoba as $osoba) {
            $current = parseOsoba($osoba, $parentMarc);
            if ($first) {
                addToMarc($parentMarc, '100  ', $current, true);
                $first = false;
            }
            addToMarc($parentMarc, '7001 ', $current, true);
        }
    }

    $StezejniInterpreti = $xml->StezejniInterpreti;
    if ($StezejniInterpreti && $StezejniInterpreti->Osoby) {
        foreach ($StezejniInterpreti->Osoby->Osoba as $osoba) {
            $current = parseOsoba($osoba, $parentMarc);
            if (!empty($current)) {
                addToMarc($parentMarc, '7001 ', $current, true);
            }
        }
    }

    $dalsi = $xml->Dalsi;
    if ($dalsi && $dalsi->Osoby) {
        foreach ($dalsi->Osoby->Osoba as $osoba) {
            $current = parseOsoba($osoba, $parentMarc);
            if (!empty($current)) {
                addToMarc($parentMarc, '7001 ', $current, true);
            }
        }
    }

    $stezejniHT = $xml->StezejniHudebniTelesa;
    if ($stezejniHT) {
        foreach ($stezejniHT->HudebniTeleso as $teleso) {
            parseHudebniTeleso($parentMarc, $teleso);
        }
    }

    $zanr = $xml->Zanr;
    if ($zanr) {
        $zanr = $zanr->ZanrAlbum;
        if ($zanr) {
            $zanr = $zanr->__toString();
            addToMarc($parentMarc, '653  ', array('a' => $zanr));
        }
    }


    //---------------------------
    $firstSkladba = true;
    $jazykFirst = true;
    $skladby = $xml->Skladby;
    if ($skladby) {
        foreach ($skladby->Skladba as $skladba) {
            $skladbaMarc = array();
            $skladbaAtts = $skladba->attributes();

            $skladbaId = $skladbaAtts->Snimek;
            if ($skladbaId) {
                $skladbaId = $skladbaId->__toString();
            } else {
                $skladbaId = 'u' . $uid++;
            }
            $skladbaId = str_pad($skladbaId, 9, 0, STR_PAD_LEFT) . 'x' . str_pad($skladbaCount++, 6, 0, STR_PAD_LEFT);

            $skladbaLDR = '-----njm-a22-----2uc4500';
            $skladbaMarc['001  '] = $skladbaId;
            $skladbaMarc['007  '] = 's|^|||||||||||';
            $skladbaMarc['008  '] = $parentMarc['008  '];

            #FIXME load library code from settings
            addToMarc($parentMarc, 'LKR  ', array('a' => 'DOWN', 'b' => $skladbaId, 'c' => 'SUP'), true);
            addToMarc($skladbaMarc, 'LKR  ', array('a' => 'UP', 'b' => $id, 'c' => 'SUP'));

            addSimpleNode($skladbaMarc, $skladba->ISRC, '0240 ', 'a');
            addToMarc($skladbaMarc, '7730 ',
                array('a' => $parentMarc['24510'][0]['a'],
                    'g' => $id,
                    'd' => $parentMarc['260  '][0]['b'] .
                    isset($vydano) ? ', ' . $vydano : ''), true);


            $subfield8 = '';
            if ($skladbaAtts->Poradi) {
                $subfield8 .= $skladbaAtts->Poradi->__toString();
            }
            $subfield8 .= '.';
            if ($skladbaAtts->PoradiNosice) {
                $subfield8 .= $skladbaAtts->PoradiNosice->__toString();
            }
            $subfield8 .= '.';
            if ($skladbaAtts->Oznaceni) {
                $subfield8 .= $skladbaAtts->Oznaceni->__toString();
            }
            $subfield8 .= '\x';

            addSimpleNode($parentMarc, $skladba->Nazev, '50500', 't', true);
            $stopaz = $skladba->Stopaz;
            if ($stopaz) {
                $stopaz = $stopaz->__toString();
                addToMarc($parentMarc, '50500', array('g' => convertStopaz($stopaz)), false);
                addToMarc($skladbaMarc, '306  ', array('a' => convertStopaz($stopaz)), true);
            }
            addToMarc($parentMarc, '50500', array('8' => $subfield8), false);

            addSimpleNode($skladbaMarc, $skladba->Nazev, '24510', 'a', true);
            addToMarc($skladbaMarc, '24510', array('8' => $subfield8), false);


            //500 --------
            if ($skladbaAtts->MediumNosice) {
                $format = $skladbaAtts->MediumNosice->__toString();
                if (array_key_exists($format, $format_ini['500a'])) {
                    addToMarc($skladbaMarc, '500  ', array('a' => $format_ini['500a'][$format]), true);
                    if ($firstSkladba) {
                        addToMarc($parentMarc, '500  ', array('a' => $format_ini['500a'][$format]), true);
                    }
                }

                if (array_key_exists($format, $format_ini['007'])) {
                    $skladbaMarc['007  '] = $format_ini['007'][$format] . '^|||||||||||';
                    if ($firstSkladba) {
                        $parentMarc['007  '] = $format_ini['007'][$format] . '^|||||||||||';
                    }
                }

                //internal mode
//				if ($internalMode) {
                if (array_key_exists($format, $format_ini['facet'])) {
//						addToMarc($skladbaMarc, 'FCT  ', array('a' => $format_ini['facet'][$format]), true);		
//						if ($firstSkladba) {
                    addToMarc($parentMarc, 'FCT  ', array('a' => $format_ini['facet'][$format]), true);
//						}
                } else {
                    var_dump('NO FORMAT');
                }
//				}

                $firstSkladba = false;

            }
            $puvodniVydavatel = $skladba->PuvodniVydavatel;
            if ($puvodniVydavatel) {
                $puvodniVydavatel = $puvodniVydavatel->__toString();
            } else {
                unset($puvodniVydavatel);
            }

            $prvniRokVydani = $skladba->PrvniRokVydani;
            if ($prvniRokVydani) {
                $prvniRokVydani = $prvniRokVydani->__toString();
            } else {
                unset($prvniRokVydani);
            }
            date('Ymdhis.0', time());
            if (isset($prvniRokVydani)) {
                $field500 = 'Vydáno poprvé v roce ' . $prvniRokVydani;
                if (isset($puvodniVydavatel)) {
                    $field500 .= ' ve vydavatelství ' . $puvodniVydavatel;
                }
                addToMarc($skladbaMarc, '500  ', array('a' => $field500), true);
            } else {
                if (isset($puvodniVydavatel)) {
                    addToMarc($skladbaMarc, '500  ', array('a' => 'Původně vydáno ve vydavatelství ', $puvodniVydavatel), true);
                }
            }

            $dokonceni = $skladba->Dokonceni;
            if ($dokonceni) {
                $dokonceniFull = $dokonceni->__toString();
                $dokonceni = MetadataUtils::extractYear($dokonceniFull);
                if (strlen($dokonceni) != 4) {
                    unset($dokonceni);
                }
                try {
                    $date = date_create($dokonceniFull);
                    $date = date_format($date, 'd. m. Y');
                    if ($date) {
                        $dokonceniFull = $date;
                    }
                } catch (Exception $e) {
                    //ignorovat data se spatnym formatem
                }
            }

            $mistoNahrani = $skladba->MistoNahrani;
            if ($mistoNahrani) {
                $mistoNahrani = $mistoNahrani->__toString();
            } else {
                unset($mistoNahrani);
            }

            if (isset($dokonceniFull) && isset($mistoNahrani)) {
                addToMarc($skladbaMarc, '518  ', array('a' => 'Místo náhrání: ' . $mistoNahrani . ', ' . $dokonceniFull), true);
            } elseif (isset($dokonceniFull)) {
                addToMarc($skladbaMarc, '518  ', array('a' => 'Náhráno ' . $dokonceniFull), true);
            } elseif (isset($mistoNahrani)) {
                addToMarc($skladbaMarc, '518  ', array('a' => 'Místo náhrání: ' . $mistoNahrani), true);
            }

            //nastaveni 008 u skladby
            if (isset($prvniRokVydani) && isset($dokonceni)) {
                if ($prvniRokVydani == $dokonceni) {
                    addTo008($skladbaMarc, 's' . $prvniRokVydani . '----', 6, 14);
                } else {
                    addTo008($skladbaMarc, 'p' . $prvniRokVydani . $dokonceni, 6, 14);
                }
            } elseif (isset($prvniRokVydani)) {
                addTo008($skladbaMarc, 's' . $prvniRokVydani . '----', 6, 14);
            } elseif (isset($dokonceni)) {
                addTo008($skladbaMarc, 's' . $dokonceni . '----', 6, 14);
            }


            //Casti
            $skladbaCasti = $skladba->Casti;
            if ($skladbaCasti) {
                foreach ($skladbaCasti->Cast as $cast) {
                    $castAtts = $cast->attributes();
                    $sb8 = '';
                    if ($castAtts->Oznaceni) {
                        $sb8 .= $castAtts->Oznaceni->__toString();
                    }
                    if ($castAtts->Poradi) {
                        $sb8 .= '.' . $castAtts->Poradi->__toString();
                    }
                    addToMarc($skladbaMarc, '50500', array('8' => $sb8));
                    addSimpleNode($skladbaMarc, $cast->Nazev, '50500', 't', false);
                    $stopaz = $cast->Stopaz;
                    if ($stopaz) {
                        $stopaz = $stopaz->__toString();
                        addToMarc($skladbaMarc, '50500', array('g' => convertStopaz($stopaz)));
                    }
                }
            }


            $jazyk = $skladba->Jazyk;
            if ($jazyk) {
                $jazyk = $jazyk->__toString();
                $translatedLangs = getLangs($jazyk);
                for ($i = 0; $i < count($translatedLangs); $i++) {
                    if ($i == 0) {
                        addTo008($skladbaMarc, $translatedLangs[$i], 35, 37);
                    } else {
                        addToMarc($skladbaMarc, '041  ', array('a' => $translatedLangs[$i]), false, true);
                    }
                    //pridat prvni jazyk skladby do rodice 008
                    if ($jazykFirst) {
                        addTo008($parentMarc, $translatedLangs[$i], 35, 37);
                    }
                }
            }

            $first = true;
            $autorskeDilo = $skladba->AutorskeDilo;
            if ($autorskeDilo && $autorskeDilo->Osoby) {
                foreach ($autorskeDilo->Osoby->Osoba as $osoba) {
                    $osoba = parseOsoba($osoba, $skladbaMarc);
                    $field = '7001 ';
                    if ($first) {
                        $field = '100  ';
                        addToMarc($parentMarc, '50500', array('r' => isset($osoba['a']) ? $osoba['a'] : ''), false);
                    }
                    addToMarc($skladbaMarc, $field, $osoba, true);
                    $first = false;
                }
            }

            $skladbaHT = $skladba->HudebniTelesa;
            if ($skladbaHT) {
                foreach ($skladbaHT->HudebniTeleso as $ht) {
                    parseHudebniTeleso($skladbaMarc, $ht);
                }
            }

            $skladbaZanr = $skladba->Zanr;
            if ($skladbaZanr) {
                $zakladni = $skladbaZanr->ZanrZakladni;
                if ($zakladni) {
                    $zakladni = $zakladni->__toString();
                    if ($zakladni == 'mluvené slovo') {
                        $parentLDR[6] = $skladbaLDR[6] = 'i';
                    }
                    addToMarc($skladbaMarc, '653  ', array('a' => $zakladni), true);
                }

                $ad = $skladbaZanr->ZanrAD;
                if ($ad) {
                    $ad = $ad->__toString();
                    if ($ad == 'mluvené slovo') {
                        $parentLDR[6] = $skladbaLDR[6] = 'i';
                    }
                }

                $zanrSnimek = $skladbaZanr->ZanrSnimek;
                if ($zanrSnimek) {
                    $zanrSnimek = $zanrSnimek->__toString();
                    if ($zanrSnimek == 'mluvené slovo') {
                        $parentLDR[6] = $skladbaLDR[6] = 'i';
                    }
                    addToMarc($skladbaMarc, '653  ', array('a' => $zanrSnimek), true);
                }
            }

            $skladbaMarc['LDR  '] = $skladbaLDR;
            $records[] = $skladbaMarc;
        }
    }
    //---------------------------

    for ($i = 0; $i < count($records); $i++) {
        $records[$i]['003  '] = 'CZ-SUP';
        $records[$i]['005  '] = $updateDate;
        addToMarc($records[$i], '040  ', array('a' => 'SUP', 'b' => 'cze', 'd' => 'BOA001'));
    }

    $parentMarc['LDR  '] = $parentLDR;

    //deduplikace osob (merge roli, klic je jmeno)
    for ($i = 0; $i < count($records); $i++) {
        $current = $records[$i];
        $dudpArray = array();
        if (isset($current['100  ']) && $current['100  '][0]) {
            $point = &$current['100  '][0];
            $key100 = '';
            $key100 .= isset($point['a']) ? $point['a'] : '';
            $key100 .= isset($point['f']) ? $point['f'] : '';
            //add 100 to 7001
            $current['7001 '][] = $point;
        }
        if (isset($current['7001 '])) {
            foreach ($current['7001 '] as $field700) {
                $key = '';
                $key .= isset($field700['a']) ? $field700['a'] : '';
                $key .= isset($field700['f']) ? $field700['f'] : '';
                if (empty($key)) {
                    continue;
                }

                if (array_key_exists($key, $dudpArray)) {
                    if (isset($field700['e'])) {
                        $dudpArray[$key]['e'][] = $field700['e'];
                    }
                    if (isset($field700['4'])) {
                        $dudpArray[$key]['4'][] = $field700['4'];
                    }

                } else {
                    $dudpArray[$key] = $field700;
                    foreach (array('e', '4') as $currentSubfield) {
                        if (isset($field700[$currentSubfield])) {
                            $temp = $dudpArray[$key][$currentSubfield];
                            $dudpArray[$key][$currentSubfield] = array();
                            $dudpArray[$key][$currentSubfield][] = $temp;
                        } else {
                            $dudpArray[$key][$currentSubfield] = array();
                        }
                    }
                }

            }
            $current['7001 '] = array();
            if (isset($key100) && isset($dudpArray[$key100])) {

                $current['100  '] = array($dudpArray[$key100]);
                unset($dudpArray[$key100]);
                unset($key100);
            }
            foreach ($dudpArray as $deduped) {
                $current['7001 '][] = $deduped;
            }
            $records[$i] = $current;

        }

    }
    foreach ($records as $current) {
        uksort($current, "compareFields");
        foreach ($current as $field => $values) {
            if ($field == 'LDR  ' || strcmp('010', $field) > 0) {
                fwrite($fout, $field . $values . PHP_EOL);
            } else {
                foreach ($values as $value) {
                    fwrite($fout, $field);
                    ksort($value);
                    foreach ($value as $subfield => $val) {
                        if (is_array($val)) {
                            foreach ($val as $val2) {
                                foreach (explode(SUBFIELD_INDICATOR, $val2) as $exloded) {
                                    fwrite($fout, '$' . $subfield . $exloded);
                                }
                            }
                        } else {
                            foreach (explode(SUBFIELD_INDICATOR, $val) as $exloded) {
                                fwrite($fout, '$' . $subfield . $exloded);
                            }
                        }
                        unset($val);
                    }
                    fwrite($fout, PHP_EOL);
                }
            }
        }
        fwrite($fout, PHP_EOL);
    }

    if (++$albumCount % 100 == 0) {
        print("Created" . $albumCount + $skladbaCount . ' records from ' . $albumCount . ' albums, ' . $albumCount / (time() - $startTime) . ' albums/s' . PHP_EOL);
    }

}
