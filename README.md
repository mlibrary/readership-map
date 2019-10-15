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


## TODO

Investigate [enhanced ecommerce for Google Analytics](https://developers.google.com/analytics/devguides/collection/gtagjs/enhanced-ecommerce#measure_product_detail_views).

```javascript
gtag('event', 'view_item', {
  "items": [
    {
      "id": "P12345",
      "name": "Android Warhol T-Shirt",
      "list_name": "Search Results",
      "brand": "Google",
      "category": "Apparel/T-Shirts",
      "variant": "Black",
      "list_position": 1,
      "quantity": 2,
      "price": '2.0'
    }
  ]
});
```

In this case, I imagine we could encode the following:

```yaml
id: url
name: title
brand: author
variant: open | subscription
```

`category` and `list_name` might be available for future use.  `list_position` are probably not going to be relevant.
Quantity and price seem irrelevant, and unlikely to be useful.

An alternative to using the enhanced ecommerce gtag.js would be using the [Google Analytics Measurment protocol](https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters).

Possible concerns: limits on the lengths of these fields

500 bytes is the limit on most of the fields.
