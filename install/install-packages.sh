#!/bin/sh

apt --assume-yes --quiet update
apt --assume-yes --quiet upgrade
apt install --assume-yes --quiet ca-certificates apt-transport-https software-properties-common

add-apt-repository --yes ppa:ondrej/php
apt --assume-yes --quiet update
apt --assume-yes --quiet upgrade

apt install --assume-yes --quiet nginx

apt install --assume-yes --quiet mariadb-server


apt install --assume-yes --quiet php-cli php-mysql php-fpm php-imap php-xml php-curl php-json php-intl php-zip php-bcmath php-gd php-mbstring php-curl php-soap


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
apt install --assume-yes --quiet chromium-browser
npm -g i puppeteer-core

#apt install postfix


#
# create shpd user
#
adduser --disabled-password --gecos "" --home /home/shpd shpd
adduser www-data shpd
systemctl restart nginx
systemctl restart php8.0-fpm
