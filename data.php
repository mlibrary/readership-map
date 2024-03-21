<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

if(!defined('STDIN'))  define('STDIN',  fopen('php://stdin',  'rb'));
if(!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'wb'));
if(!defined('STDERR')) define('STDERR', fopen('php://stderr', 'wb'));
  

require_once __DIR__ . '/vendor/autoload.php';

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\Filter\InListFilter;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\OrderBy;

use Google\Analytics\Admin\V1beta\Account;
use Google\Analytics\Admin\V1beta\Client\AnalyticsAdminServiceClient;
use Google\Analytics\Admin\V1beta\ListAccountsRequest;
use Google\Analytics\Admin\V1beta\ListPropertiesRequest;
use Google\Analytics\Admin\V1beta\DataStream;
use Google\Analytics\Admin\V1beta\GetDataStreamRequest;
use Google\Analytics\Admin\V1beta\ListDataStreamsRequest;


// From https://developers.google.com/analytics/devguides/reporting/core/v3/quickstart/service-php
// https://ga-dev-tools.appspot.com/dimensions-metrics-explorer/
$pins = [];
$geo_map = [];
$pageviews = [ 'total' => [], 'annual' => [] ];
$max_results = 1000;
$streams_metadata = [];

$analytics = new BetaAnalyticsDataClient();
$adminClient = new AnalyticsAdminServiceClient();

$accounts = load_accounts($adminClient);
$config = load_config('config.yml', $accounts);

//echo "<pre>" . json_encode($config, true) . "</pre>";exit;

$pin_start_date = date(
  $config['start']['datefmt'],
   strtotime($config['start']['date'])
);

$pin_end_date = date(
  $config['end']['datefmt'],
  strtotime($config['end']['date'])
);

function scrape($url = NULL) {
  static $urls = [];

  echo "<pre>URL: $url</pre>";
  if (is_null($url)) {
    file_put_contents('urls.json', json_encode($urls));
    return NULL;
  }

  // echo "<pre>URLS: " . json_encode($urls) . "</pre>";
  if (empty($urls) && file_exists('urls.json')) {
    $urls = json_decode(file_get_contents('urls.json'), TRUE);
  }

  if (isset($urls[$url])) {
    echo "<pre>SET - returning</pre>";
    return $urls[$url];
  }

  if (strpos($url, '/data/downloads') !== false) {
    echo "<pre>DATA DOWNLOADS ($url) - Returning</pre>";
    echo "<pre>" . strpos($url, '/data/downloads') . "</pre>";
    return $urls[$url] = NULL;
  }

  echo "<pre>Getting File Contents $url</pre>";
  $html = @file_get_contents($url);
  
  // echo "<pre>$html</pre>";
  if (empty($html)) {
    fwrite(STDERR, "  Scrape failed: $url empty\n");
    echo "<pre>Scrape failed: $url empty</pre>";
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
    fwrite(STDERR, "  Scrape failed: $url unable to find metadata\n");
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
  $stream_ids = [];
  foreach ((array) $ret['streams'] as &$v) {
    $v['id'] = $stream_ids[] = (string) $v['id'];
  }



  foreach ((array) $ret['accounts'] as $account) {
    foreach ((array) $accounts[$account['id']] as $stream) {
      $ret['streams'][] = [
        'id' => (string) $stream['id'],
        //'property_name' => $property_name,
        'property_id' => (string) $stream['property_id'],
        // 'account_name' => $account_name,
        'account_id' => (string) $account['id'],
        //'stream_name' => $stream['stream_name'],
        //'stream_url' => $stream['stream_url'],
        'filters' => $account['filters'],
        'metrics' => $account['metrics'],
      ];
    }
  }
  return $ret;
}

function load_accounts($adminClient) {
  global $streams_metadata;

  $ret = [];
  // Prepare the request message.
  $listAccountsRequest = new ListAccountsRequest();
  $accounts = $adminClient->listAccounts($listAccountsRequest);

  foreach ($accounts as $account) {

    // printf('<pre>Account data: %s</pre>' . PHP_EOL, $account->serializeToJsonString());
    $account_id = explode('/', $account->getName())[1];
    $ret[$account_id] = [];
    $account_name = $account->getDisplayName();
    
    $filter = new ListPropertiesRequest();
    $filter->setFilter("parent:accounts/$account_id");
    $properties = $adminClient->listProperties($filter);

    foreach ($properties as $property) {
      // printf('<pre>Property data: %s</pre>' . PHP_EOL, $property->serializeToJsonString());
      // printf('<pre>Property ID data: %s</pre>' . PHP_EOL,json_encode(explode('/', $property->getName())));
      $property_id = (string) explode('/', $property->getName())[1];
      $property_name = $property->getDisplayName();
      $streamRequest = (new ListDataStreamsRequest());
      $streamRequest->setParent("properties/$property_id");
      $streams = $adminClient->listDataStreams($streamRequest);

     foreach ($streams as $stream) {
        $stream_id = (string) explode('/', $stream->getName())[3];
        $stream_name = $stream->getDisplayName();
        $stream_url  = $stream->getWebStreamData()->getDefaultUri();

        $ret[$account_id][] = $streams_metadata[$stream_id] = [
          'id' => $stream_id,
          'stream_name' => $stream_name,
          'stream_url' => $stream_url,
          'property_name' => $property_name,
          'property_id' => $property_id,
          'account_name' => $account_name,
          'account_id' => $account_id,
        ];
        
        // printf('<pre>Return data: %s</pre>' . PHP_EOL, json_encode($streams_metadata[$stream_id]));
      }
    }
  }
  return $ret;
}

function populate_geo_map($analytics, $adminClient, $property_id, $stream_id, $start_index = 1) {
  global $geo_map;
  $geo_results = [];

  $dateRanges =  [ get_date_range("weeklyDateRange", "7daysAgo", "today") ];
  $dimensions = [
    get_dimension("city"),
    get_dimension("region"),
    get_dimension("country")
  ];
  
  $metrics = [get_metric("screenPageViews")];
  /*
    [
      'dimensions' => 'ga:city,ga:region,ga:country,ga:latitude,ga:longitude',
      'start-index' => $start_index,
    ]
  */

  $request = new RunReportRequest([
    'property' => "properties/$property_id",
    'dimensions' => $dimensions,
    'date_ranges' => $dateRanges,
    'metrics' => $metrics,
    "dimension_filter" => new FilterExpression([
      'filter' => get_stream_id_filter($stream_id)
    ])
  ]);
  
  $response = $analytics->runReport($request);
  
  foreach($response->getRows() as $data_row) {
    $dimension_values = $data_row->getDimensionValues();
    $metric_values = $data_row->getMetricValues(); 

    $city = $dimension_values[0]->getValue();
    $region = $dimension_values[1]->getValue();
    $country = $dimension_values[2]->getValue();
    $screen_page_views = $metric_values[0]->getValue();

    /*echo '<pre>' . 
      json_encode([ 
        "property_id" => $property_id,
        "stream_id" => $stream_id,
        "city" => $city, 
        "region" => $region, 
        "country" => $country,
        "screen_page_views" => $screen_page_views
      ]) . 
      "</pre>";*/
  }

  /*
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
  }*/
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

function query_stream_total($property_id, $stream_id, $metrics, $filters) {
  global $pageviews;

  try{
    $dateRanges = [ get_date_range('total_range', '2015-08-14', 'today') ];
    $dimensions = [ get_dimension("streamId") ];

    $screen_page_views = get_query_stream_page_views($property_id, $dateRanges, $metrics, $stream_id, $filters);
    $pageviews['total'][] = get_page_views_object($screen_page_views, $stream_id, $property_id);
  }
  catch (Exception $e) {
    echo "<pre>Total Exception caught: " . $e->getMessage() . "</pre>";
    echo "<pre>Total Exception caught: " . $e->getTraceAsString() . "</pre>";
  }
}

function query_stream_annual($property_id, $stream_id, $metrics, $filters) {
  global $pageviews;
  
  try{
    $dateRanges = [ get_date_range('annual_range', '365daysAgo', 'today') ];
    $dimensions = [ get_dimension("streamId") ];

    $screen_page_views = get_query_stream_page_views($property_id, $dateRanges, $metrics, $stream_id, $filters);
    $pageviews['annual'][] = get_page_views_object($screen_page_views, $stream_id, $property_id);
  }
  catch (Exception $e) {
    echo "<pre>Total Exception caught: " . $e->getMessage() . "</pre>";
    echo "<pre>Total Exception caught: " . $e->getTraceAsString() . "</pre>";
  }
}

function query_stream_recent($property_id, $stream_id, $metrics, $filters) {
  global $max_results, $pins, $pin_start_date, $pin_end_date, $analytics;

  $before = count($pins);
  $stream_id = (string) $stream_id;
  $dateRanges = [ get_date_range('recent_range', $pin_start_date, $pin_end_date) ];

  $dimensions_map = [
    'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
    'ga:totalEvents' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,ga:eventLabel'
  ];
  $dimensions = array_map('get_dimension', explode(",", $dimensions_map[$metrics->getName()]));

  $request = new RunReportRequest([
    'property' => "properties/$property_id",
    'date_ranges' => $dateRanges,
    'dimensions' => $dimensions,
    'metrics' => [ $metrics ],
    "dimension_filter" => get_query_stream_filter_expression($stream_id, $filters),
    'order_bys' => [
      new OrderBy([
          'dimension' => new OrderBy\DimensionOrderBy([
              'dimension_name' => 'dateHourMinute', 
              'order_type' => OrderBy\DimensionOrderBy\OrderType::ALPHANUMERIC
          ]),
          'desc' => false,
      ]),],
  ]);
  
  $rows = $analytics->runReport($request)->getRows();
  fwrite(STDERR, "  Found: " . count($rows) . "\n");

  foreach($rows as $data_row){
    $row_values = $data_row->getDimensionValues();
    $metadata = get_metadata($row_values, $stream_id);
    if (empty($metadata)) { continue; }

    $pins[] = [
      'date' => $row_values[0]->getValue(),
      'title' => $row_values[1]->getValue(),
      'url' => $row_values[2]->getValue(),
      'author' => $metadata['citation_author'],
      //'position' => $position,
      'access' => $metadata['access'],
      'stream_id' => (string) $stream_id,
    ];
  }


  /*

  $rows = $results->getRows();
  foreach ((array)$rows as $row) {
    $row = interpret_row($dimensions, $metrics, $row);
    $position = get_position($row);
    if (empty($position)) { continue; }
    if (empty($metadata)) { continue; }

    $pins[] = [
      'date' => $row['dateHourMinute'],
      'title' => $metadata['citation_title'],
      'url' => $metadata['citation_url'],
      'author' => $metadata['citation_author'],
      'position' => $position,
      'access' => $metadata['access'],
      'stream_id' => (string) $id,
    ];
  }
  fwrite(STDERR, "  Scraped: " . (count($pins) - $before) . "\n");*/
}

function get_date_range($name, $start_date, $end_date) { 
  $dateRange = new DateRange(["name" => $name]);
  $dateRange->setStartDate($start_date); 
  $dateRange->setEndDate($end_date);

  return $dateRange;
}

function get_dimension($name) {
  return new Dimension(["name" => $name]);
}

function get_metric($name) {
  return new Metric(['name' => $name]);
}

function get_stream_id_filter($stream_id){
  return new FilterExpression([
    'filter' => 
      new Filter([
        'field_name' => 'streamId',
        'string_filter' => new StringFilter([
          'value' => "$stream_id",
          'match_type' => Filter\StringFilter\MatchType::EXACT
        ])
      ])
        ]);
}

function get_query_stream_filter_expression($stream_id, $filters){
  return new FilterExpression([
    'and_group' => new FilterExpressionList([
      'expressions' => [
        get_stream_id_filter($stream_id),
        new FilterExpression($filters)
      ]
    ])
  ]);
}

function get_query_stream_report_request($property_id, $dateRanges, $metrics, $stream_id, $filters) {
  return  new RunReportRequest([
    'property' => "properties/$property_id",
    'date_ranges' => $dateRanges,
    'metrics' => [ $metrics ],
    "dimension_filter" => get_query_stream_filter_expression($stream_id, $filters)
  ]);
}

function get_query_stream_page_views($property_id, $dateRanges, $metrics, $stream_id, $filters) {
  global $analytics;
  $screen_page_views = 0;

  $request = get_query_stream_report_request($property_id, $dateRanges, $metrics, $stream_id, $filters);
  $rows = $analytics->runReport($request)->getRows();

  if(count($rows) == 1){
    $screen_page_views = $rows[0]->getMetricValues()[0]->getValue();
  }

  return intval($screen_page_views);
}

function get_page_views_object($screen_page_views, $stream_id, $property_id) {
  return [
    'count' => $screen_page_views, 
    'stream_id' => (string) $stream_id, 
    'property_id' => (string) $property_id
  ];
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

function get_lat_long($city, $region, $country){

    $address = str_replace(" ", "+", $address);

    $json = file_get_contents("http://maps.google.com/maps/api/geocode/json?city=$city&region=$region&country=$country&sensor=false");
    $json = json_decode($json);

    $lat = $json->{'results'}[0]->{'geometry'}->{'location'}->{'lat'};
    $long = $json->{'results'}[0]->{'geometry'}->{'location'}->{'lng'};
    return $lat.','.$long;
}

enum DimensionName: int
  {
      case HostName = 1;
      case PagePath = 2;
  }

function get_metadata($row, $id) {
  global $streams_metadata;
  // 'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
    $candidate_urls = [];

  /*if (!empty($row['eventLabel'])) {
    $candidate_urls = [$row['eventLabel']];
  }*/
  if (count($row) >= 3 && !empty($row[DimensionName::HostName->value]->getValue()) && !empty($row[DimensionName::PagePath->value]->getValue())) {
    $stream_url = $streams_metadata[$id]['stream_url'];
    if (strpos($stream_url, $row[DimensionName::HostName->value]->getValue()) === FALSE) {
      $candidate_urls[] = substr($stream_url, strpos($stream_url, '/', 9), strlen($stream_url)) . $row[DimensionName::PagePath->value]->getValue();
    }

    if (strpos($row[DimensionName::HostName->value]->getValue(), 'quod-lib-umich-edu') !== FALSE) {
      $candidate_urls[] = 'https://quod.lib.umich.edu' . $row[DimensionName::PagePath->value]->getValue();
    }
    elseif (strpos($row[DimensionName::HostName->value]->getValue(), 'fulcrum-org') !== FALSE) {
      $candidate_urls[] = 'https://www.fulcrum.org' . $row[DimensionName::PagePath->value]->getValue();
    }
    $candidate_urls[] = 'https://' . $row[DimensionName::HostName->value]->getValue() . $row[DimensionName::PagePath->value]->getValue();
  }

  // echo json_encode($candidate_urls);
  foreach ($candidate_urls as $url) {
    echo "<pre>Processing $url</pre>";
    if (strpos($url, 'http') !== 0) { continue; }
    $ret = scrape($url);
    if ($ret) { return $ret; }
  }
  return NULL;
}

function query_stream($property_id, $stream_id, $metrics_string, $filters_string) {
  // if (is_array($metrics)) { $metrics = implode($metrics, ','); }
  // if (is_array($filters)) { $filters = implode($filters, ','); }
  $filters_data = explode('=~', $filters_string, 2);
  // echo "<pre>" . json_encode($filters_data) . "</pre>";

  if(count($filters_data) == 2 && !str_starts_with($filters_data[0], "ga:")) {
    $filters = [
        'filter' => 
          new Filter([
            'field_name' => htmlspecialchars($filters_data[0]),
            'string_filter' => new StringFilter([
            'value' => htmlspecialchars($filters_data[1]),
            'match_type' => Filter\StringFilter\MatchType::PARTIAL_REGEXP
          ])
        ])
    ];
    $metrics = get_metric($metrics_string);

    //query_stream_total($property_id, $stream_id, $metrics, $filters);
    //query_stream_annual($property_id, $stream_id, $metrics, $filters);
    query_stream_recent($property_id, $stream_id, $metrics, $filters);
  }
  

}

function process_stream($analytics, $adminClient, $stream) {
  if(array_key_exists('property_id', $stream))
  {
    fwrite(STDERR, "Processing stream: {$stream['id']} / {$stream['property_id']} / {$stream['metrics']}\n");
    try {
      $filters = $stream['filters'];
      /*echo "<pre>" . 
        json_encode([
          'property_id' => $stream['property_id'], 
          'stream_id' => $stream['id'], 
          'metrics' => $stream['metrics'], 
          'filters' => $filters
        ])
        . "</pre>";*/
      //populate_geo_map($analytics, $stream['id']);
      query_stream((string) $stream['property_id'], (string) $stream['id'], $stream['metrics'], $filters);
    }
    catch (Exception $e) {
      fwrite(STDERR, "  Exception caught: " . $e->getMessage() . "\n");
      echo "<pre>Exception caught: " . $e->getMessage() . "</pre>";
      echo "<pre>Exception caught: " . $e->getTraceAsString() . "</pre>";
    }
  }
}

function process_streams($analytics, $adminClient, $streams) {
  // echo "<pre>" . json_encode($streams, true) . "</pre>";
  foreach ($streams as $stream) {
    process_stream($analytics, $adminClient, $stream);
  }
}

echo "<pre>Processing " . count($config['streams']) . " streams ...</pre>";
// echo "<pre>" . json_encode($config['streams'], true) . "</pre>";exit;
process_streams($analytics, $adminClient, $config['streams']);
echo "<pre>Processed.</pre>";

usort($pins, function($a, $b) {
  if ($a['date'] == $b['date']) { return 0; }
  return ($a['date'] < $b['date']) ? -1 : 1;
});

//scrape();

print json_encode(['pageviews' => $pageviews, 'pins' => $pins]);
