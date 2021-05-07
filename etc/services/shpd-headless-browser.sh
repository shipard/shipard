#!/usr/bin/env bash

echo -n "" > /run/shpd-headless-browser.json
/usr/bin/google-chrome --remote-debugging-port=9222 --headless --no-sandbox &
#/usr/bin/chromium-browser --remote-debugging-port=9222 --headless --no-sandbox &
echo $! > /run/shpd-headless-browser.pid
while ! [ -s /var/run/shpd-headless-browser.json ]; do
    sleep 0.5
		curl "http://127.0.0.1:9222/json/version" > /run/shpd-headless-browser.json
done

exit 0
