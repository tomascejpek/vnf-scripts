#!/bin/bash

IN_FILE="/home/tomas/supraphon/supraphon_out.xml"
OUT_FILE="/home/tomas/supraphon/supraphon.mrc"
TMP_FILE="/home/tomas/supraphon/supraphon_out.xml.tmp"

LOG_DATE=$(date '+%Y_%m_%d')
LOG_FILE="/home/tomas/supraphon/convert-"$LOG_DATE

cd /home/tomas/git/RecordManager/supraphon
#php toXML.php -o $TMP_FILE -i $IN_FILE

#sed -E -i.old '/(ID_HT|ID_Osoba)/d;s/SUPRAPHON/Supraphon/g' -i $TMP_FILE

php toMarc.php -o $OUT_FILE -i $TMP_FILE -m internal

#rm $TMP_FILE
