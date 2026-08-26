# Readership map

[![Build Status Badge](https://api.travis-ci.org/mlibrary/readership-map.svg?branch=master)](https://travis-ci.org/mlibrary/readership-map)
[![Coverage Status](https://coveralls.io/repos/github/mlibrary/readership-map/badge.svg?branch=master)](https://coveralls.io/github/mlibrary/readership-map?branch=master)

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


## Container / Kubernetes deployment

### Build and run locally

```bash
docker build -t readership-map:local .

# Run with your Google service-account credentials mounted
docker run --rm \
  -v /path/to/credentials.json:/var/run/secrets/google/credentials.json:ro \
  -v $(pwd)/runtime:/var/www/html/runtime \
  -p 8080:80 \
  readership-map:local
```

### Google credentials secret

Create `k8s/secret-google-creds.yaml` from the provided example template, fill in
your service-account JSON, then apply it (keep the file out of git):

```bash
cp k8s/secret-google-creds.example.yaml k8s/secret-google-creds.yaml
# Edit k8s/secret-google-creds.yaml with real credentials
kubectl apply -f k8s/secret-google-creds.yaml
```

The `GOOGLE_APPLICATION_CREDENTIALS` environment variable inside the container
points to `/var/run/secrets/google/credentials.json`, which is where the secret is
mounted by the Deployment and CronJob.

### Applying Kubernetes manifests

Apply in this order (resources referenced by later manifests must exist first):

```bash
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/secret-google-creds.yaml   # your real secret (not the example)
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/pvc.yaml
kubectl apply -f k8s/deployment.yaml
kubectl apply -f k8s/service.yaml
kubectl apply -f k8s/ingress.yaml
kubectl apply -f k8s/cronjob.yaml
```

> **Note:** `k8s/pvc.yaml` requests `ReadWriteMany` access so that web pod replicas
> and the CronJob pod can all share the same runtime volume.  RWX support depends on
> your cluster's storage class (e.g. NFS, CephFS, AWS EFS).  If your cluster only
> supports `ReadWriteOnce`, change the `accessModes` value accordingly and limit the
> Deployment to a single replica.

### How the CronJob updates pins.json

`k8s/cronjob.yaml` schedules `bin/generate-pins.sh` to run every 15 minutes.  The
script calls `php data.php`, writes the output to a temporary file in the shared PVC,
then atomically renames it to `$PINS_FILE` (`/var/www/html/runtime/pins.json`).  The
web container serves this pre-generated JSON directly, keeping response times low.

### Placeholders that must be replaced before production use

| File | Placeholder | Description |
|---|---|---|
| `k8s/deployment.yaml` | `sha-REPLACE_ME` | Container image tag (long SHA from CI) |
| `k8s/cronjob.yaml` | `sha-REPLACE_ME` | Same image tag as Deployment |
| `k8s/ingress.yaml` | `readership-map.example.org` | Your actual hostname |
| `k8s/ingress.yaml` | `readership-map-tls` | Your TLS certificate secret name |
| `k8s/secret-google-creds.example.yaml` | `REPLACE_ME` fields | Real service-account credentials |

The GitHub Actions workflow (`.github/workflows/container-build.yml`) automatically
builds and pushes a tagged image to `ghcr.io/mlibrary/readership-map` on every push
to `master`.  Use the resulting `sha-<long-sha>` tag as the image tag in the manifests.

---

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
