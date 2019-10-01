<?php

require_once __DIR__ . '/vendor/autoload.php';

function scrape($url) {
  static $urls = [];
  if (isset($urls[$url])) {
    return $urls[$url];
  }
  $html = @file_get_contents($url);
  if (empty($html)) {
    return $urls[$url] = ['', '', '', ''];
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

  if (strpos($ret[2], 'doi:') === 0) {
    $ret[2] = 'https://doi.org/' . substr($ret[2], 4, strlen($ret[2]));
  } elseif (strpos($ret[2], '10.') === 0) {
    $ret[2] = 'https://doi.org/' . $ret[2];
  } elseif (strpos($ret[2], '2027') === 0) {
    $ret[2] = 'https://hdl.handle.net/' . $ret[2];
  }

  $content = qp($qp, "img[@alt='Open Access icon']");
  if ($content->length > 0) {
    $ret[] = 'open';
  }
  else {
    $ret[] = 'subscription';
  }

  return $urls[$url] = $ret;
}

$client = new Google_Client();
$client->useApplicationDefaultCredentials();
$client->setApplicationName('Michigan Publishing Readership Map');
$client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
$analytics = new Google_Service_Analytics($client);

$config = Symfony\Component\Yaml\Yaml::parsefile('config.yml');

// From https://developers.google.com/analytics/devguides/reporting/core/v3/quickstart/service-php
// https://ga-dev-tools.appspot.com/dimensions-metrics-explorer/
$pins = [];
$accounts = $analytics->management_accounts->listManagementAccounts();
$geo_map = [];
$pageviews = [ 'total' => [], 'annual' => [] ];
$max_results = 1000;


function query_pageviews($analytics, $view_id) {
  global $pageviews, $pins, $geo_map, $max_results;

  $results = $analytics->data_ga->get(
    'ga:' . $view_id,
    '365daysAgo',
    'today',
    'ga:pageviews',
    ['filters' => 'ga:pagePath=~^/(concern/.+?|epubs)/([A-Za-z0-9])']
  );
  $rows = $results->getRows();
  if ($rows) {
    $pageviews['annual'][] = ['count' => intval($rows[0][0]), 'view_id' => $view_id];
  }

  $results = $analytics->data_ga->get(
    'ga:' . $view_id,
    '2005-01-01',
    'today',
    'ga:pageviews',
    ['filters' => 'ga:pagePath=~^/(concern/.+?|epubs)/([A-Za-z0-9])']
  );
  $rows = $results->getRows();
  if ($rows) {
    $pageviews['total'][] = ['count' => intval($rows[0][0]), 'view_id' => $view_id];
  }

  $results = $analytics->data_ga->get(
    'ga:' . $view_id,
    'yesterday',
    'today',
    'ga:pageviews',
    [
      'dimensions' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:pageTitle',
      'max-results' => $max_results,
      'filters' => 'ga:pagePath=~^/(concern/.+?|epubs)/([A-Za-z0-9])',
    ]
  );
  $rows = $results->getRows();

  fwrite(STDERR, "      Pageviews: " . count($rows) . " / " . $results->getTotalResults() . "\n");
  foreach ((array)$rows as $row) {
    list($date, $hostname, $path, $city, $region, $country, $title, $sessions) = $row;
    if (empty($geo_map["$city//$region//$country"])) { continue; }
    $position = $geo_map["$city//$region//$country"];

    $location = format_location($city, $region, $country);
    if (empty($location)) { continue; }

    $url = "https://{$hostname}{$path}";
    list($citation_title, $citation_author, $citation_url, $access) = scrape($url);

    if ($citation_url && $citation_title && $citation_author) {
      $pins[] = [
        'date' => $date,
        'title' => $citation_title,
        'url' => $citation_url,
        'author' => $citation_author,
        'location' => $location,
        'position' => $position,
        'access' => $access,
        'view_id' => $view_id,
      ];
    }
  }
}

function populate_geo_map($analytics, $view_id, $start_index = 1) {
  global $geo_map;
  $geo_results = $analytics->data_ga->get(
    'ga:' . $view_id,
    '3daysAgo',
    'today',
    'ga:pageviews',
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

function query_events($analytics, $view_id) {
  global $max_results, $pageviews, $pins;

  $events = $analytics->data_ga->get(
    'ga:' . $view_id,
    '2005-01-01',
    'today',
    'ga:totalEvents',
    [ 'filters' => 'ga:eventAction=~download_' ]
  );
  $rows = $events->getRows();
  if (!empty($rows)) {
    $pageviews['total'][] = ['count' => intval($rows[0][0]), 'view_id' => $view_id];
  }

  $events = $analytics->data_ga->get(
    'ga:' . $view_id,
    '365daysAgo',
    'today',
    'ga:totalEvents',
    [ 'filters' => 'ga:eventAction=~download_' ]
  );
  $rows = $events->getRows();
  if (!empty($rows)) {
    $pageviews['annual'][] = ['count' => intval($rows[0][0]), 'view_id' => $view_id];
  }

  $events = $analytics->data_ga->get(
    'ga:' . $view_id,
    'yesterday',
    'today',
    'ga:totalEvents',
    [
      'dimensions' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:eventLabel',
      'max-results' => $max_results,
      'filters' => 'ga:eventAction=~download_',
    ]
  );
  $rows = $events->getRows();
  fwrite(STDERR, "      Events: " . count($rows) . " / " . $events->getTotalResults() . "\n");
  foreach ((array) $rows as $row) {
    list($date, $hostname, $path, $city, $region, $country, $url, $sessions) = $row;
    if (empty($geo_map["$city//$region//$country"])) { continue; }
    $position = $geo_map["$city//$region//$country"];

    $location = format_location($city, $region, $country);
    if (empty($location)) { continue; }

    list($citation_title, $citation_author, $citation_url, $access) = scrape($url);
    if ($citation_url && $citation_title && $citation_author) {
      $pins[] = [
        'date' => $date,
        'title' => $citation_title,
        'url' => $citation_url,
        'author' => $citation_author,
        'location' => $location,
        'position' => $position,
        'access' => $access,
        'view_id' => $view_id,
      ];
    }
  }
}

function format_location($city, $region, $country) {
  return join(
    array_filter(
      array_unique([$city, $region, $country]),
      function ($var) { return !empty($var) && $var != '(not set)'; }
    ),
    ', '
  );
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

      populate_geo_map($analytics, $view_id);
      fwrite(STDERR, "      GeoMap: " . count($geo_map) . "\n");
      query_pageviews($analytics, $view_id);
      query_events($analytics, $view_id);

    }
  }
}

print json_encode(['pageviews' => $pageviews, 'pins' => $pins]);
