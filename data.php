<?php

require_once __DIR__ . '/vendor/autoload.php';

function scrape($url) {
  static $urls = [];
  if (isset($urls[$url])) {
    return $urls[$url];
  }
  $html = @file_get_contents($url);
  if (empty($html)) {
    return ['', '', ''];
  }

  $ret = [];
  $qp = html5qp($html);

  $meta_tags = [
    ['citation_title'],
    ['citation_author'],
    ['citation_doi', 'DC.Identifier', 'citation_hdl']
  ];

  foreach ($meta_tags as $tag_list) {
    $value = NULL;
    foreach($tag_list as $tag) {
      $content = qp($qp, "meta[@name='$tag']")->attr('content');
      if (!empty($content)) {
        $value = $content;
        break;
      }
    }
    if ($value) {
      $ret[] = $value;
    }
    else {
      $ret[] = '';
    }
  }
  return $urls[$url] = $ret;
}

$client = new Google_Client();
$client->useApplicationDefaultCredentials();
$client->setApplicationName('Michigan Publishing Readership Map');
$client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
$analytics = new Google_Service_Analytics($client);

// From https://developers.google.com/analytics/devguides/reporting/core/v3/quickstart/service-php
// https://ga-dev-tools.appspot.com/dimensions-metrics-explorer/
$pins = [];
$accounts = $analytics->management_accounts->listManagementAccounts();
$geo_map = [];
$pageviews = [ 'total' => 0, 'annual' => 0 ];
$views = [

];

function query_pageviews($analytics, $view_id) {
  global $pageviews;

  $results = $analytics->data_ga->get(
    'ga:' . $view_id,
    '365daysAgo',
    'today',
    'ga:pageviews'
  );
  $rows = $results->getRows();
  if ($rows) {
    $pageviews['annual'] += $rows[0][0];
  }

  $results = $analytics->data_ga->get(
    'ga:' . $view_id,
    '2005-01-01',
    'today',
    'ga:pageviews'
  );
  $rows = $results->getRows();
  if ($rows) {
    $pageviews['total'] += $rows[0][0];
  }
}

function populate_geo_map($analytics, $view_id, $start_index = 1) {
  global $geo_map;
  $geo_results = $analytics->data_ga->get(
    'ga:' . $view_id,
    'yesterday',
    'today',
    'ga:sessions',
    [
      'dimensions' => 'ga:city,ga:region,ga:country,ga:latitude,ga:longitude',
      'start-index' => $start_index,
    ]
  );
  
  foreach ((array)$geo_results->getRows() as $row) {
    list($city, $region, $country, $lat, $lng) = $row;
    if ($lat == '0.000' && $lng == '0.000') {
      continue;
    }
    $geo_map["$city//$region//$country"] = ['lat' => floatval($lat), 'lng' => floatval($lng)];
  }
  $start_index += 1000;
  if ($geo_results->getTotalResults() > $start_index && $start_index < 5000) {
    populate_geo_map($analytics, $view_id, $start_index);
  }
}

foreach ($accounts->getItems() as $account) {
  $account_id = $account->getId();
  $account_name = $account->getName();
  fwrite(STDERR, "Account ID: $account_id / $account_name\n");
  $properties = $analytics->management_webproperties->listManagementWebproperties($account_id);
  foreach ($properties->getItems() as $property) {
    $property_id = $property->getId();
    $property_name = $property->getName();
    fwrite(STDERR, "  Property ID: $property_id / $property_name\n");
    $views = $analytics->management_profiles->listManagementProfiles($account_id, $property_id);
    foreach ($views->getItems() as $view) {
      $view_id = $view->getId();
      $view_name = $view->getName();
      fwrite(STDERR, "    View ID: $view_id / $view_name\n");
      if (strpos($view_name, 'Filtered') === false && strpos($view_name, '(filtered)') === false) {
        fwrite(STDERR, "      Skipping unfiltered view\n");
        continue;
      }

      query_pageviews($analytics, $view_id);
      populate_geo_map($analytics, $view_id);
      fwrite(STDERR, "      GeoMap: " . count($geo_map) . "\n");

      $results = $analytics->data_ga->get(
        'ga:' . $view_id,
        'yesterday',
        'today',
        'ga:pageviews',
        [
          'dimensions' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:pageTitle',
          'max-results' => 50,
        ]
      );
      $rows = $results->getRows();
      $merged_rows = [];
       
      fwrite(STDERR, "      Rows: " . count($rows) . " / " . $results->getTotalResults() . "\n");
      foreach ((array)$rows as $row) {
        list($date, $hostname, $path, $city, $region, $country, $title, $sessions) = $row;
        if (empty($geo_map["$city//$region//$country"])) { continue; }
        $position = $geo_map["$city//$region//$country"];

        $location = join(
          array_filter(
            array_unique([$city, $region, $country]),
            function ($var) { return !empty($var) && $var != '(not set)'; }
          ),
          ', '
        );
        if (empty($location)) { continue; }

        $url = "https://{$hostname}{$path}";
        list($citation_title, $citation_author, $citation_url) = scrape($url);

        if ($citation_url && $citation_title && $citation_author) {
          if (strpos($citation_url, 'doi:') === 0) {
            $citation_url = 'https://doi.org/' . substr($citation_url, 4, strlen($citation_url));
          }
          $pins[] = [
            'date' => $date,
            'title' => $citation_title,
            'url' => $citation_url,
            'author' => $citation_author,
            'location' => $location,
            'position' => $position,
          ];
        }
      }
    }
  }
}

// Subtract out the pins from the page views so that the UI can count up and be honest.
$pageviews['total']  -= count($pins);
$pageviews['annual'] -= count($pins);

print json_encode(['pageviews' => $pageviews, 'pins' => $pins]);
