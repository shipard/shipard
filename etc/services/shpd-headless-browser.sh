#!/usr/bin/env bash

echo -n "" > /var/lib/shipard/shpd/shpd-headless-browser.json

if [ -f "/usr/bin/chromium-browser" ]; then
    /usr/bin/chromium-browser --remote-debugging-port=9222 --headless --no-sandbox --font-render-hinting=none &
else
    /usr/bin/chromium --remote-debugging-port=9222 --headless --no-sandbox --font-render-hinting=none &
fi

echo $! > /var/lib/shipard/shpd/shpd-headless-browser.pid
while ! [ -s /var/lib/shipard/shpd/shpd-headless-browser.json ]; do
    sleep 1
		curl -s "http://127.0.0.1:9222/json/version" > /var/lib/shipard/shpd/shpd-headless-browser.json
done

exit 0
