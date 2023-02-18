## Import úvodních dat


### Případná instalace utilit pro rozbalování souborů .7z

    sudo apt install p7zip-full

### Stažení základních sad pro úvodní import

    cd /var/lib/shipard/data-sources/
    cd data--source--id

    mkdir res
    cd res
    wget https://opendata.czso.cz/data/od_org03/res_data.csv

    wget https://wwwinfo.mfcr.cz/ares/ares_seznamIC_VR.csv.7z
    wget https://wwwinfo.mfcr.cz/ares/ares_vreo_all.tar.gz
    7z x ares_seznamIC_VR.csv.7z
    gunzip ares_vreo_all.tar.gz

    cd ..

Aktuální URL pro databázi RES lze najít na https://www.czso.cz/csu/czso/registr-ekonomickych-subjektu-otevrena-data (Seznam ekonomických subjektů).

Aktuální URL pro ARES lze najít na https://wwwinfo.mfcr.cz/ares/ares_opendata.html.cz ("Seznam IČO z VR" a "Výstup pro všechna IČO"). Stahování občas nefunguje (ERROR 403: Forbidden apod.) - je potřeba to případně zkusit z chvíli.

### Spuštění úvodního importu dat

    shpd-app cli-action --action=services.persons/initial-import-cz

