<?php
$file = fopen("/data/exports/Supraphon.xml", "r");

$stack = array();
$content = "";

$globalArray = array();
$GLOBALS['pointer'] = &$globalArray;

function printArray()
{
    print("called");
    global $globalArray;
    print_r($globalArray);
    exit;
}

function getMaxKey(&$array)
{
    $max = null;
    if ($array == null) {
        return 0;
    }
    foreach (array_keys($array) as $key) {
        if (is_numeric($key)) {
            $max = max($max, intval($key));
        }
    }
    if ($max == null) {
        return 0;
    }
    return $max;
}

function getNextKey(&$array)
{
    $max = null;
    if ($array == null) {
        return 0;
    }
    foreach (array_keys($array) as $key) {
        if (is_numeric($key)) {
            $max = max($max, intval($key));
        }
    }
    if ($max == null) {
        return 0;
    }
    return ++$max;
}

function startElement($parser, $name, $attrs)
{

    global $stack, $globalArray, $content;
    $GLOBALS['pointer'] = &$GLOBALS['pointer'][][$name];
// 	$GLOBALS['pointer'] = &$GLOBALS['pointer'];
// 	end($GLOBALS['pointer']);
// 	$GLOBALS['pointer'] = &$GLOBALS['pointer'][getNextKey($GLOBALS['pointer'])][$name];
    if (!empty($attrs)) {
        $GLOBALS['pointer']['ATTS'] = $attrs;
    }
    $content = "";
    array_push($stack, $name);
}

function endElement($parser, $name)
{
    global $stack;
    global $content;

    if (!empty($content)) {
        $GLOBALS['pointer']['TEXT'] = $content;
    }
    $content = "";


    global $globalArray;
    $pointer = &$globalArray;
    array_pop($stack);

    foreach ($stack as $element) {
        $pointer = &$pointer[getMaxKey($pointer)][$element];
    }
    $GLOBALS['pointer'] = &$pointer;
    if ($name == 'ALBA') {
        printArray();
    }
}

function characters($parser, $data)
{
    global $content;
    $content .= trim($data);
}

// pcntl_signal ( SIGINT , "handler");
// pcntl_signal ( SIGTERM , "handler");
$xml_parser = xml_parser_create();
xml_set_element_handler($xml_parser, "startElement", "endElement");
xml_set_default_handler($xml_parser, "characters");
xml_parser_set_option($xml_parser, XML_OPTION_SKIP_WHITE, true);
$count = 1;
while ($data = fread($file, 128)) {
    if (!xml_parse($xml_parser, $data, feof($file))) {
        die(sprintf("XML error: %s at line %d",
            xml_error_string(xml_get_error_code($xml_parser)),
            xml_get_current_line_number($xml_parser)));
    }
// 	if (++$count == 100) {
// 		print_r($globalArray);
// 		exit;
// 	}
}
xml_parser_free($xml_parser);




