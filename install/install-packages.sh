#!/bin/bash

apt --assume-yes --quiet update
apt --assume-yes --quiet upgrade
apt install --assume-yes --quiet ca-certificates apt-transport-https software-properties-common wget curl

#
# convert semVer number to int
#
function versionToInt() {
  local IFS=.
  parts=($1)
  let val=1000000*parts[0]+1000*parts[1]+parts[2]
  echo $val
}

#
# detect OS
#
. /etc/os-release

#
# PHP 8
#
if [ "$NAME" = "Ubuntu" ]; then
    currentVersion=$(versionToInt $VERSION_ID)
    min81Version=$(versionToInt 22.04.0)

    if [ "$currentVersion" -lt "$min81Version" ]; then
        add-apt-repository --yes ppa:ondrej/php
        apt --assume-yes --quiet update
        apt --assume-yes --quiet upgrade
    fi
else
    sudo wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
    apt --assume-yes --quiet update
fi

#
# NGINX
#
apt install --assume-yes --quiet nginx

#
# MariaDB
#
apt install --assume-yes --quiet mariadb-server


apt install --assume-yes --quiet php8.1-cli php8.1-mysql php8.1-fpm php8.1-imap php8.1-xml php8.1-curl php8.1-intl php8.1-zip php8.1-bcmath php8.1-gd php8.1-mbstring php8.1-soap php8.1-mailparse


apt install --assume-yes --quiet librsvg2-bin qrencode imagemagick poppler-utils zip graphviz idn

apt install --assume-yes --quiet net-tools

#
# nodejs
#
#apt install --assume-yes --quiet nodejs npm
curl -fsSL https://deb.nodesource.com/setup_16.x | bash -
apt install --assume-yes --quiet nodejs

#
# composer
#
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

#
# lessc
#
npm install -g --force less less-plugin-clean-css

#
# sass
#
#apt install --assume-yes --quiet sass

echo ttf-mscorefonts-installer msttcorefonts/accepted-mscorefonts-eula select true | sudo debconf-set-selections
apt install --assume-yes --quiet ttf-mscorefonts-installer
#apt install --assume-yes --quiet msttcorefonts


#
# fop + java
#
#apt install --assume-yes --quiet fop libservlet3.1-java


#
# redis
#
apt install --assume-yes --quiet redis-server php8.1-redis

#
# python
#
apt install --assume-yes --quiet python3-pip
pip3 install PyPDF4

#
# chromium & puppeteer
#
if [ "$NAME" = "Ubuntu" ]; then
	apt install --assume-yes --quiet chromium-browser
else
	apt install --assume-yes --quiet chromium
fi

npm -g i puppeteer-core

#
# OCR & att metadata tools
#
apt install --assume-yes --quiet libimage-exiftool-perl docx2txt tesseract-ocr tesseract-ocr-ces ocrmypdf


#apt install postfix


#
# create shpd user
#
adduser --disabled-password --gecos "" --home /home/shpd shpd
adduser www-data shpd
systemctl restart nginx
systemctl restart php8.1-fpm

#
# optional: fop - https://xmlgraphics.apache.org/fop/
#
## apt-get install fop libservlet3.1-java msttcorefonts
