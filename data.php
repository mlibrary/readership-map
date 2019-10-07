<?php

require_once __DIR__ . '/vendor/autoload.php';

// From https://developers.google.com/analytics/devguides/reporting/core/v3/quickstart/service-php
// https://ga-dev-tools.appspot.com/dimensions-metrics-explorer/
$pins = [];
$geo_map = [];
$pageviews = [ 'total' => [], 'annual' => [] ];
$max_results = 1000;
$views_metadata = [];

$client = new Google_Client();
$client->useApplicationDefaultCredentials();
$client->setApplicationName('Michigan Publishing Readership Map');
$client->setScopes(['https://www.googleapis.com/auth/analytics.readonly']);
$analytics = new Google_Service_Analytics($client);
$config = load_config('config.yml', load_accounts($analytics));

function scrape($url = NULL) {
  static $urls = [];

  if (is_null($url)) {
    file_put_contents('urls.json', json_encode($urls));
    return NULL;
  }

  if (empty($urls) && file_exists('urls.json')) {
    $urls = json_decode(file_get_contents('urls.json'));
  }

  if (isset($urls[$url])) {
    return $urls[$url];
  }
  $html = @file_get_contents($url);
  if (empty($html)) {
    return $urls[$url] = NULL;
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
  } else {
    $ret[2] = $url;
  }

  $content = qp($qp, "img[@alt='Open Access icon']");
  if ($content->length > 0) {
    $ret[] = 'open';
  }
  else {
    $ret[] = 'subscription';
  }
  if (empty($ret[0]) || empty($ret[1]) || empty($ret[2])) {
    return $urls[$url] = NULL;
  }

  $ret = [
    'citation_title' => $ret[0],
    'citation_author' => $ret[1],
    'citation_url' => $ret[2],
    'access' => $ret[3],
  ];

  return $urls[$url] = $ret;
}

function load_config($file, $accounts) {
  $ret = Symfony\Component\Yaml\Yaml::parsefile($file);
  foreach ((array) $ret['views'] as &$v) {
    $v['id'] = (string) $v['id'];
  }

  foreach ((array) $ret['accounts'] as $account) {
    foreach ((array) $accounts[$account['id']] as $view) {
      $ret['views'][] = [
        'id' => $view['id'],
        'filters' => $account['filters'],
        'metrics' => $account['metrics'],
      ];
    }
  }
  return $ret;
}

function load_accounts($analytics) {
  global $views_metadata;

  $ret = [];
  $accounts = $analytics->management_accounts->listManagementAccounts();
  foreach ($accounts->getItems() as $account) {
    $account_id = $account->getId();
    $ret[$account_id] = [];
    $account_name = $account->getName();
    $properties = $analytics->management_webproperties->listManagementWebproperties($account_id);
    foreach ($properties->getItems() as $property) {
      $property_id = $property->getId();
      $property_name = $property->getName();
      $views = $analytics->management_profiles->listManagementProfiles($account_id, $property_id);
      foreach ($views->getItems() as $view) {
        $view_id = $view->getId();
        $view_name = $view->getName();
        $view_url  = $view->getWebsiteUrl();
        $ret[$account_id][] = $views_metadata[$view_id] = [
          'id' => $view_id,
          'view_name' => $view_name,
          'view_url' => $view_url,
          'property_name' => $property_name,
          'property_id' => $property_id,
          'account_name' => $account_name,
          'account_id' => $account_id,
        ];
      }
    }
  }
  return $ret;
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

function format_location($city, $region, $country) {
  return join(
    array_filter(
      array_unique([$city, $region, $country]),
      function ($var) { return !empty($var) && $var != '(not set)'; }
    ),
    ', '
  );
}

function query_view_total($id, $metrics, $filters) {
  global $pageviews, $analytics;
  $events = $analytics->data_ga->get(
    'ga:' . $id,
    '2005-01-01',
    'today',
    $metrics,
    [ 'filters' => $filters ]
  );
  $rows = $events->getRows();
  if (!empty($rows)) {
    $pageviews['total'][] = ['count' => intval($rows[0][0]), 'view_id' => $id];
  }
}

function query_view_annual($id, $metrics, $filters) {
  global $pageviews, $analytics;
  $events = $analytics->data_ga->get(
    'ga:' . $id,
    '365daysAgo',
    'today',
    $metrics,
    [ 'filters' => $filters ]
  );
  $rows = $events->getRows();
  if (!empty($rows)) {
    $pageviews['annual'][] = ['count' => intval($rows[0][0]), 'view_id' => $id];
  }
}

function interpret_row($dimensions, $metrics, $row) {
  $ret = [];
  $columns = array_map(
    function($item) { return substr($item, 3, strlen($item) -2); },
    explode(',', implode(',', [$dimensions, $metrics]))
  );

  foreach ($columns as $i => $column) {
    $ret[$column] = $row[$i];
  }

  return $ret;
}

function query_view_recent($id, $metrics, $filters) {
  global $max_results, $analytics, $pins;

  $dimensions_map = [
    'ga:pageviews' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:pageTitle',
    'ga:totalEvents' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:eventLabel'
  ];

  $dimensions = $dimensions_map[$metrics];

  $results = $analytics->data_ga->get(
    'ga:' . $id,
    'yesterday',
    'today',
    $metrics,
    [
      'dimensions' => $dimensions,
      'max-results' => $max_results,
      'filters' => $filters,
    ]
  );

  $rows = $results->getRows();
  fwrite(STDERR, "  Found: " . count($rows) . "\n");
  foreach ((array)$rows as $row) {
    $row = interpret_row($dimensions, $metrics, $row);
    $position = get_position($row);
    if (empty($position)) { continue; }
    $location = get_location($row);
    if (empty($location)) { continue; }
    $metadata = get_metadata($row, $id);
    if (empty($metadata)) { continue; }

    $pins[] = [
      'date' => $row['dateHourMinute'],
      'title' => $metadata['citation_title'],
      'url' => $metadata['citation_url'],
      'author' => $metadata['citation_author'],
      'location' => $location,
      'position' => $position,
      'access' => $metadata['access'],
      'view_id' => $id,
    ];
  }
}

function get_position($row) {
  global $geo_map;
  $key = "{$row['city']}//{$row['region']}//{$row['country']}";
  if (empty($geo_map[$key])) { return null; }
  return $geo_map[$key];
}

function get_location($row) {
  return format_location($row['city'], $row['region'], $row['country']);
}

function get_metadata($row, $id) {
  global $views_metadata;

  $candidate_urls = [];

  if (!empty($row['eventLabel'])) {
    $candidate_urls = [$row['eventLabel']];
  } elseif (!empty($row['hostname']) && !empty($row['pagePath'])) {
    $view_url = $views_metadata[$id]['view_url'];
    if (strpos($view_url, $row['hostname']) === FALSE) {
      $candidate_urls[] = substr($view_url, strpos($view_url, '/', 9), strlen($view_url)) . $row['pagePath'];
    }

    if (strpos($row['hostname'], 'quod-lib-umich-edu') !== FALSE) {
      $candidate_urls[] = 'https://quod.lib.umich.edu' . $row['pagePath'];
    }
    elseif (strpos($row['hostname'], 'fulcrum-org') !== FALSE) {
      $candidate_urls[] = 'https://www.fulcrum.org' . $row['pagePath'];
    }
    $candidate_urls[] = 'https://' . $row['hostname'] . $row['pagePath'];
  } else {
    return NULL;
  }

  foreach ($candidate_urls as $url) {
    $ret = scrape($url);
    if ($ret) { return $ret; }
  }
  return NULL;
}

function query_view($id, $metrics, $filters) {
  if (is_array($metrics)) { $metrics = implode($metrics, ','); }
  if (is_array($filters)) { $filters = implode($filters, ','); }
  query_view_total($id, $metrics, $filters);
  query_view_annual($id, $metrics, $filters);
  query_view_recent($id, $metrics, $filters);
}

function process_view($analytics, $view) {
  fwrite(STDERR, "Processing view: {$view['id']}\n");
  try {
    populate_geo_map($analytics, $view['id']);
    query_view($view['id'], $view['metrics'], $view['filters']);
  }
  catch (Exception $e) {
    fwrite(STDERR, "  Exception caught: " . $e->getMessage() . "\n");
  }
}

function process_views($analytics, $views) {
  foreach ($views as $view) {
    process_view($analytics, $view);
  }
}

process_views($analytics, $config['views']);

usort($pins, function($a, $b) {
  if ($a['date'] == $b['date']) { return 0; }
  return ($a['date'] < $b['date']) ? -1 : 1;
});

scrape();

print json_encode(['pageviews' => $pageviews, 'pins' => $pins]);
