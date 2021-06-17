skript používá knihovny z recormanageru, takže je potřeba dát složku supraphon do kořene repozitáře

konverzi spustit jako:

php5 toXML.php -i Supraphon2014.xml -o output.xml // nahradi odkazy za skutečná jména

výstup z předchozího se použije dál
 
php5 toMarc.php -i output.xml -o output.mrc // konverze do marcu

-----------
Supraphon2014.xml - původní data

Supraphon_new.xml - nová data. Nefunguje na ně starý skript


