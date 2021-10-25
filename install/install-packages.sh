#!/bin/sh

apt --assume-yes --quiet update
apt --assume-yes --quiet upgrade
apt install --assume-yes --quiet ca-certificates apt-transport-https software-properties-common wget curl

#
# detect OS
#
. /etc/os-release

#
# PHP 8
#
if [ "$NAME" = "Ubuntu" ]; then
    add-apt-repository --yes ppa:ondrej/php
    apt --assume-yes --quiet update
    apt --assume-yes --quiet upgrade
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


apt install --assume-yes --quiet php-cli php-mysql php-fpm php-imap php-xml php-curl php-json php-intl php-zip php-bcmath php-gd php-mbstring php-soap php-mailparse


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
apt install --assume-yes --quiet redis-server php-redis

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
systemctl restart php8.0-fpm
