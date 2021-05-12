#!/usr/bin/env bash

echo -n "" > /var/lib/shipard/shpd/shpd-headless-browser.json
/usr/bin/chromium-browser --remote-debugging-port=9222 --headless --no-sandbox &
echo $! > /var/lib/shipard/shpd/shpd-headless-browser.pid
while ! [ -s /var/lib/shipard/shpd/shpd-headless-browser.json ]; do
    sleep 0.5
		curl "http://127.0.0.1:9222/json/version" > /var/lib/shipard/shpd/shpd-headless-browser.json
done

exit 0
