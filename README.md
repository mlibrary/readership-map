# Readership map

## Setup (not quite there yet for full real-time)

1. git clone https://github.com/mlibrary/readership-map
2. cd readership-map
3. composer install
4. have .htaccess set GOOGLE_APPLICATION_CREDENTIALS to the path of the readership map's .json file.

```
setenv GOOGLE_APPLICATION_CREDENTIALS /path/to/readership-map.json
```
5. View the map at index.html
6. See the data at data.php

## Low-latency mode

Because data.php takes some time to run:

1. `git clone https://github.com/mlibrary/readership-map`
2. `cd readership-map`
3. `composer install`
4. `GOOGLE_APPLICATION_CREDENTIALS=/path/to/credentials/file php data.php > pins.json`
5. `php readership-map.js.php > readership-map.js`
6. Copy `index.html` `*.js` and `*.json` to the place where the maps are served.
7. Have cron run `GOOGLE_APPLICATION_CREDENTIALS=/path/to/credentials/file php data.php > pins.tmp && mv pins.tmp pins.js`

