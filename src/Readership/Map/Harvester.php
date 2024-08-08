<?php
namespace Readership\Map;
use Google\Analytics\Data\V1beta\Dimension;

class Harvester {
  use Logging;

  private $config;
  private $analytics;
  private $scraper;
  private $geoMap = [];
  private $pageviews;
  private $recentPinsStart;
  private $recentPinsEnd;
  private $recentMaxResults;
  private $pins;

  public function __construct(Configuration $config, AnalyticsAccount $account, Scraper $scraper) {
    $this->config = $config;
    $this->analytics = $account;
    $this->scraper = $scraper;
    $this->pageviews = ['total' => [], 'annual' => []];
    $this->recentPinsStart = $this->config->pinStartDate();
    $this->recentPinsEnd = $this->config->pinEndDate();
    $this->recentMaxResults = 1000;
    $this->pins = [];
    $this->populateHistoricalData();
  }

  public function toJSON() {
    return json_encode(['pageviews' => $this->pageviews, 'pins' => $this->pins], JSON_PRETTY_PRINT);
  }

  public function sortPins($fn) {
    usort($this->pins, $fn);
  }

  public function run() {
    $streams = $this->getStreams();
    // $this->log(json_encode($streams));

    foreach ($streams as $stream) {
      $this->processStream($stream);
    }

    $this->sortPins(function($a, $b) {
      if ($a['date'] == $b['date']) { return 0; }
      return ($a['date'] < $b['date']) ? -1 : 1;
    });
  }

  private function processStream($stream) {
    $this->log("Processing stream: {$stream['id']} / {$stream['metrics']}\n");
    try {
      $this->populateGeoMap($stream['property_id'], $stream['id']);
      $this->queryStream($stream);
    }
    catch (\Exception $e) {
      $this->log("  Exception caught: " . $e->getMessage() . "\n");
    }
  }

  private function populateHistoricalData() {
    $historical_file = getcwd() . '/data/historical_pageviews.json';
    $id_file = getcwd() . '/data/id_map.json';

    if(file_exists($historical_file) && file_exists($id_file)) {
      $historical_data = json_decode(file_get_contents($historical_file), TRUE);
      $id_map = json_decode(file_get_contents($id_file), TRUE);

      foreach($historical_data['pageviews']['total'] as $count_record) {
        $id_data = $this->getIdMapping($count_record, $id_map);

        if(!empty($id_data)) {
          $this->pageviews['total'][] = [
            'count' => $count_record['count'], 
            'property_id' => (string) $id_data['ga4_id'], 
            'stream_id' => (string) $id_data['stream_id']
          ];
        }
      }
    }
  }

  private function getIdMapping($count_record, $id_map) {
    $id_data = null;
    $index = 0;

    while(empty($id_data) && $index < count($id_map)) {
      $id_record = $id_map[$index];
      if(!empty($id_record) && $id_record['ua_id'] == $count_record['view_id']){
        $id_data = $id_record;
      }
      $index++;
    }

    return $id_data;
  }

  private function getGeoData($property_id, $id, $index) {
    return $this->analytics->getGeoData($property_id, $id, $index);
  }

  private function populateGeoMap($property_id, $id, $start_index = 1) {
    $geo_results = [];
    $geo_file = getcwd() . '/geo_map.json';
  
    if (count($this->geoMap) == 0 && file_exists($geo_file)) {
      $this->geoMap = json_decode(file_get_contents($geo_file), TRUE);
    }

    $geo_results = $this->getGeoData($property_id, $id, $start_index);
    $rows = $geo_results->getRows();

    foreach ($rows as $row) {
      $dimension_values = $row->getDimensionValues();
      $city = $dimension_values[0]->getValue();
      $region = $dimension_values[1]->getValue();
      $country = $dimension_values[2]->getValue();
      $geo_key = "$region//$country";

      if(!array_key_exists($geo_key, $this->geoMap)) {
        $lat_lng = $this->get_lat_lng($city, $region, $country);
        if($lat_lng != NULL) {
          $lat_lng_arr = explode(',', $lat_lng);
          $lat = $lat_lng_arr[0];
          $lng = $lat_lng_arr[1];

          //fwrite(STDERR, json_encode(['city'=>$city, 'region'=>$region, 'country'=>$country], JSON_PRETTY_PRINT) . PHP_EOL); 
          //exit;
          // fwrite(STDERR, "Region: $region\nCountry: $country\nLat: $lat\nLng: $lng\n");
          if ($lat == '0.000' && $lng == '0.000') {
            continue;
          }
          $this->geoMap[$geo_key] = ['lat' => floatval($lat), 'lng' => floatval($lng)];
        }
      }
    }

    if (!is_null($this->geoMap)) {
      file_put_contents($geo_file, json_encode($this->geoMap));
    }

    $start_index += 1000;
    if (count($rows) > $start_index && $start_index < 5000) {
      $this->populateGeoMap($property_id, $id, $start_index);
    }
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
      // fwrite(STDERR, "$geocode_url\n");
      // fwrite(STDERR, "$contents\n");
    }
    return NULL;
  }

  private function getStreamTotals($property_id, $id, $metrics, $filters) {
    return $this->analytics->getStreamTotals($property_id, $id, $metrics, $filters);
  }

  private function queryStreamTotal($property_id, $id, $metrics, $filters) {
    $events = $this->getStreamTotals($property_id, $id, $metrics, $filters);
    $rows = $events->getRows();
    $total = 0;
    foreach($rows as $row) {
      $total += intval($row->getMetricValues()[0]->getValue());
    }
    
    // fwrite(STDERR, PHP_EOL . "Total: $total" . PHP_EOL);

    if($total > 0)
    {
      $this->pageviews['total'][] = [
        'count' => $total, 
        'property_id' => (string) $property_id, 
        'stream_id' => (string) $id 
      ];
    }
  }

  private function getStreamAnnual($property_id, $id, $metrics, $filters) {
    return $this->analytics->getStreamAnnual($property_id, $id, $metrics, $filters);
  }

  private function getStreamRecent($property_id, $id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return $this->analytics->getStreamRecent($property_id, $id, $start, $end, $metrics, $dimensions, $max_results, $filters);
  }

  private function queryStreamAnnual($property_id, $id, $metrics, $filters) {
    $events = $this->getStreamAnnual($property_id, $id, $metrics, $filters);
    //fwrite(STDERR, $events->serializeToJsonString());exit;

    $rows = $events->getRows();
    $total = 0;
    foreach($rows as $row) {
      $total += intval($row->getMetricValues()[0]->getValue());
    }
    
    // fwrite(STDERR, PHP_EOL . "Annual Total: $total" . PHP_EOL);

    if($total > 0) {
      $this->pageviews['annual'][] = [
        'count' => $total, 
        'property_id' => (string) $property_id,
        'stream_id' => (string) $id
      ];
    }
  }

  private function getDimensions($metrics) {
    return [
      'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
      'eventCount' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,eventName'
    ][$metrics];
  }

  private function queryStreamRecent($property_id, $id, $start, $end, $metrics, $filters, $stream_url) {
    $before = count($this->pins);
    $id = (string) $id;
    $dimensions = $this->getDimensions($metrics);

    $results = $this->getStreamRecent(
      $property_id,
      $id,
      $start,
      $end,
      $metrics,
      $dimensions,
      $this->recentMaxResults,
      $filters
    );
    $rows = $results->getRows();
    $this->log("  Found: " . count($rows) . "\n");
    foreach ($rows as $row) {
      $row = new Row($dimensions, $metrics, $row, $this->scraper);
      $position = $row->getPosition($this->geoMap);
      if (empty($position) || empty($position['lat']) || empty($position['lng'])) { continue; }
      $location = $row->getLocation();
      if (empty($location)) { continue; }
      $metadata = $row->getMetadata($stream_url);
      if (empty($metadata)) { continue; }

      $this->pins[] = [
        'date' => $row->getDate(),
        'title' => $metadata['citation_title'],
        'url' => $metadata['citation_url'],
        'author' => $metadata['citation_author'],
        'location' => $location,
        'position' => $position,
        'access' => $metadata['access'],
        'stream_id' => (string) $id,
      ];
    }
    $this->log("  Scraped: " . (count($this->pins) - $before) . "\n");
  }

  private function queryStream($stream) {
    $property_id = $stream['property_id'];
    $id = $stream['id'];
    $metrics = $stream['metrics'];
    $filters = $stream['filters'];
    $start   = isset($stream['start']) ? $stream['start'] : $this->recentPinsStart;
    $end     = isset($stream['end']) ? $stream['end'] : $this->recentPinsEnd;

    //fwrite(STDERR, "Property ID: $property_id\nStart: $start\nEnd: $end");

    $stream_url = isset($stream['stream_url']) ? $stream['stream_url'] : '';
    if (is_array($metrics)) { $metrics = implode(',', $metrics); }
    if (is_array($filters)) { $filters = implode(',', $filters); }

    $this->queryStreamTotal($property_id, $id, $metrics, $filters);
    $this->queryStreamAnnual($property_id, $id, $metrics, $filters);
    $this->queryStreamRecent($property_id, $id, $start, $end, $metrics, $filters, $stream_url);
  }

  private function getStreams() {
    return $this->config->getStreams();
  }
}
