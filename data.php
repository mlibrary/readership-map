<?php
// ini_set('max_execution_time', '3000'); //3000 seconds = 50 minutes

if(!defined('STDIN'))  define('STDIN',  fopen('php://stdin',  'rb'));
if(!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'wb'));
if(!defined('STDERR')) define('STDERR', fopen('php://stderr', 'wb'));
  

require_once __DIR__ . '/vendor/autoload.php';

error_reporting(error_reporting() ^ E_DEPRECATED);

use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\OrderBy;

use Google\Analytics\Admin\V1beta\Client\AnalyticsAdminServiceClient;
use Google\Analytics\Admin\V1beta\ListAccountsRequest;
use Google\Analytics\Admin\V1beta\ListPropertiesRequest;
use Google\Analytics\Admin\V1beta\ListDataStreamsRequest;


// From https://developers.google.com/analytics/devguides/reporting/core/v3/quickstart/service-php
// https://ga-dev-tools.appspot.com/dimensions-metrics-explorer/
$pins = [];
$geo_map = [];
$geo_count = 0;
$pageviews = [ 'total' => [], 'annual' => [] ];
$max_results = 1000;
$streams_metadata = [];
$loop_count = 1000;
$start = new DateTime();

$analytics = new BetaAnalyticsDataClient();
$adminClient = new AnalyticsAdminServiceClient();

$accounts = load_accounts($adminClient);
$config = load_config('config.yml', $accounts);
// echo json_encode($config);exit;

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

  if (is_null($url)) {
    file_put_contents('urls.json', json_encode($urls));
    return NULL;
  }

  if (empty($urls) && file_exists('urls.json')) {
    $urls = json_decode(file_get_contents('urls.json'), TRUE);
  }

  if (isset($urls[$url])) {
    return $urls[$url];
  }

  if (strpos($url, '/data/downloads') !== false) {
    return $urls[$url] = NULL;
  }

  $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => true]]);
  $html = @file_get_contents($url, false, $context);
  

  if (empty($html)) {
    fwrite(STDERR, "  Scrape failed: $url empty\n");
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
      $content = $qp->find("meta[@name='$tag']")->eq(0)->attr('content');

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

  $content = $qp->find("img[@alt='Open Access icon']");
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
        'property_id' => (string) $stream['property_id'],
        'account_id' => (string) $account['id'],
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
  $listAccountsRequest = new ListAccountsRequest();
  $accounts = $adminClient->listAccounts($listAccountsRequest);

  foreach ($accounts as $account) {
    $account_id = explode('/', $account->getName())[1];
    $ret[$account_id] = [];
    $account_name = $account->getDisplayName();
    
    $filter = new ListPropertiesRequest();
    $filter->setFilter("parent:accounts/$account_id");
    $properties = $adminClient->listProperties($filter);

    foreach ($properties as $property) {
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
      }
    }
  }
  return $ret;
}

function populate_geo_map($analytics, $adminClient, $property_id, $stream_id, $start_index = 0) {
  global $geo_map, $geo_count;
  $geo_results = [];

  if (empty($geo_map) && file_exists('geo_map.json')) {
    $geo_map = json_decode(file_get_contents('geo_map.json'), TRUE);
  }

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

  // TODO: Update with paging
  $request = new RunReportRequest([
    'property' => "properties/$property_id",
    'dimensions' => $dimensions,
    'date_ranges' => $dateRanges,
    'metrics' => $metrics,
    "dimension_filter" => get_stream_id_filter($stream_id)
  ]);
  
  $response = $analytics->runReport($request);
  
  foreach($response->getRows() as $data_row) {
    $dimension_values = $data_row->getDimensionValues();
    $metric_values = $data_row->getMetricValues(); 

    $city = $dimension_values[0]->getValue();
    $region = $dimension_values[1]->getValue();
    $country = $dimension_values[2]->getValue();
    $key = "$region//$country";
    $keypos = strpos($key, "(not set)");

    if($keypos === false && !array_key_exists($key, $geo_map))
    {
      $geo_count++;
      $screen_page_views = $metric_values[0]->getValue();
      $lat_lng = get_lat_lng($city, $region, $country); 

      if(!is_null($lat_lng) || substr_count($lat_lng, ',') == 1) {
        $position_array = explode(',', $lat_lng);
        $geo_map[$key] = ['lat' => floatval($position_array[0]), 'lng' => floatval($position_array[1])];
      }
    }
  }

  $start_index += 10000;
  if (count($geo_results) > $start_index && $start_index < 5000) {
    populate_geo_map($analytics, $adminClient, $property_id, $stream_id, $start_index);
  }

  if (!is_null($geo_map)) {
    file_put_contents('geo_map.json', json_encode($geo_map));
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

  $dateRanges = [ get_date_range('total_range', '2015-08-14', 'today') ];
  $dimensions = [ get_dimension("streamId") ];

  $screen_page_views = get_query_stream_page_views($property_id, $dateRanges, $metrics, $stream_id, $filters);
  $pageviews['total'][] = get_page_views_object($screen_page_views, $stream_id, $property_id);
}

function query_stream_annual($property_id, $stream_id, $metrics, $filters) {
  global $pageviews;
  
  $dateRanges = [ get_date_range('annual_range', '365daysAgo', 'today') ];
  $dimensions = [ get_dimension("streamId") ];

  $screen_page_views = get_query_stream_page_views($property_id, $dateRanges, $metrics, $stream_id, $filters);
  $pageviews['annual'][] = get_page_views_object($screen_page_views, $stream_id, $property_id);
}

function query_stream_recent($property_id, $stream_id, $metrics, $filters) {
  global $loop_count, $max_results, $pins, $pin_start_date, $pin_end_date, $analytics;

  $before = count($pins);
  $stream_id = (string) $stream_id;
  $dateRanges = [ get_date_range('recent_range', $pin_start_date, $pin_end_date) ];

  $dimensions_map = [
    'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
    'eventCount' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,eventName'
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

  $row_num = 0; 
  foreach($rows as $data_row){
    $row_values = $data_row->getDimensionValues();
    $metadata = get_metadata($row_values, $stream_id, $property_id);
    if (empty($metadata)) { 
      continue; 
    }
    $city = $row_values[3]->getValue();
    $region = $row_values[4]->getValue();
    $country = $row_values[5]->getValue();
    $position =get_position($city, $region, $country);

    $pins[] = [
      'date' => $row_values[0]->getValue(),
      'title' => $row_values[1]->getValue(),
      'url' => $row_values[2]->getValue(),
      'author' => $metadata['citation_author'],
      'position' => $position,
      'access' => $metadata['access'],
      'stream_id' => (string) $stream_id,
    ];

    // if($row_num++ >= $loop_count) break;
  }
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

function get_position($city, $region, $country) {
  global $geo_map;
  $key = "$region//$country";
  if (empty($geo_map[$key])) { return null; }
  return $geo_map[$key];
}

function get_location($city, $region, $country) {
  return format_location($city, $region, $country);
}

function get_lat_lng($city, $region, $country){
  $not_set = '(not set)';
  $address = "$region,$country";
  if(str_contains($address, $not_set) || trim($address) == ",") {
    return NULL;
  }

  $address = str_replace(" ", "+", $address);
  $geocode_url = "https://maps.google.com/maps/api/geocode/json?address=$address" . 
                  "&sensor=false&key=AIzaSyBIV3qqPB5gLLGc21eWXyRbugB_MLH9Azs";
                  
  $contents = file_get_contents($geocode_url);
  $json = json_decode($contents);

  if(count($json->{'results'}) > 0) {
    $lat = $json->{'results'}[0]->{'geometry'}->{'location'}->{'lat'};
    $long = $json->{'results'}[0]->{'geometry'}->{'location'}->{'lng'};
    
    return $lat.','.$long;
  }
  else {
    fwrite(STDERR, "$geocode_url\n");
    fwrite(STDERR, "$contents\n");
  }
  return NULL;
}

enum DimensionName: int
{
    case HostName = 1;
    case PagePath = 2;
}

function get_metadata($row, $id, $property_id) {
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

  foreach ($candidate_urls as $url) {
    if (strpos($url, 'http') !== 0) { continue; }
    
    $ret = scrape($url);
    if ($ret) { return $ret; }
  }
  return NULL;
}

function query_stream($property_id, $stream_id, $metrics_string, $filters_string) {
  $filters_data = explode('=~', $filters_string, 2);

  if(count($filters_data) == 2) {
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

    query_stream_total($property_id, $stream_id, $metrics, $filters);
    query_stream_annual($property_id, $stream_id, $metrics, $filters);
    query_stream_recent($property_id, $stream_id, $metrics, $filters);
  }
  else {
    fwrite(STDERR, "filters: " . html_entity_decode($filters_string) . "\n");
  }
}

function process_stream($analytics, $adminClient, $stream) {
  if(array_key_exists('property_id', $stream))
  {
    try {
      $stream_id = (string) $stream['id'];
      $property_id = (string) $stream['property_id'];
      $metrics = $stream['metrics'];
      $filters = $stream['filters'];

      fwrite(STDERR, "Processing stream: $stream_id / $property_id / $metrics\n");
      
      populate_geo_map($analytics, $adminClient, $property_id, $stream_id);
      query_stream($property_id, $stream_id, $metrics, $filters);
    }
    catch (Exception $e) {
      fwrite(STDERR, "  Exception caught: " . $e->getMessage() . "\n");
    }
  }
}

function process_streams($analytics, $adminClient, $streams) {
  global $loop_count;
  $stream_num = 0;
  foreach ($streams as $stream) {
    process_stream($analytics, $adminClient, $stream);
    // if($stream_num++ == $loop_count) break;
  }
}

process_streams($analytics, $adminClient, $config['streams']);

usort($pins, function($a, $b) {
  if ($a['date'] == $b['date']) { return 0; }
  return ($a['date'] < $b['date']) ? -1 : 1;
});

scrape();
$elapsed = $start->diff(new DateTime())->format("%H:%i:%s");

print json_encode(['geo_results' => $geo_results, 'pageviews' => $pageviews, 'pins' => $pins]);
