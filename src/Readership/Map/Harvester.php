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
  }

  public function toJSON() {
    return json_encode(['pageviews' => $this->pageviews, 'pins' => $this->pins]);
  }

  public function sortPins($fn) {
    usort($this->pins, $fn);
  }

  public function run() {
    $properties = $this->getProperties();
    // $this->log(json_encode($streams));

    foreach ($properties as $property) {
      $this->processProperty($property);
    }

    $this->sortPins(function($a, $b) {
      if ($a['date'] == $b['date']) { return 0; }
      return ($a['date'] < $b['date']) ? -1 : 1;
    });
  }

  private function processProperty($property) {
    $this->log("Processing property: {$property['id']} / {$property['metrics']}\n");
    try {
      $this->populateGeoMap($property['id']);
      $this->queryProperty($property);
    }
    catch (\Exception $e) {
      $this->log("  Exception caught: " . $e->getMessage() . "\n");
    }
  }

  private function getGeoData($id, $index) {
    return $this->analytics->getGeoData($id, $index);
  }

  private function populateGeoMap($id, $start_index = 1) {
    $geo_results = [];
    $geo_file = getcwd() . '/geo_map.json';
  
    if (count($this->geoMap) == 0 && file_exists($geo_file)) {
      $this->geoMap = json_decode(file_get_contents($geo_file), TRUE);
    }

    $geo_results = $this->getGeoData($id, $start_index);
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
          fwrite(STDERR, "Region: $region\nCountry: $country\nLat: $lat\nLng: $lng\n");
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
      $this->populateGeoMap($id, $start_index);
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
      fwrite(STDERR, "$geocode_url\n");
      fwrite(STDERR, "$contents\n");
    }
    return NULL;
  }

  private function getPropertyTotals($id, $metrics, $filters) {
    return $this->analytics->getPropertyTotals($id, $metrics, $filters);
  }

  private function queryPropertyTotal($id, $metrics, $filters) {
    $events = $this->getPropertyTotals($id, $metrics, $filters);
    $rows = $events->getRows();
    $total = 0;
    foreach($rows as $row) {
      $total += intval($row->getMetricValues()[0]->getValue());
    }
    if (count($rows) > 0) {
      $this->pageviews['total'][] = ['count' => $total, 'property_id' => (string) $id ];
    }
  }

  private function getPropertyAnnual($id, $metrics, $filters) {
    return $this->analytics->getPropertyAnnual($id, $metrics, $filters);
  }

  private function getPropertyRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return $this->analytics->getPropertyRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters);
  }

  private function queryPropertyAnnual($id, $metrics, $filters) {
    $events = $this->getPropertyAnnual($id, $metrics, $filters);
    $rows = $events->getRows();
    $total = 0;
    foreach($rows as $row) {
      $total += intval($row->getMetricValues()[0]->getValue());
    }
    if ($rows->count() > 0) {
      $this->pageviews['annual'][] = ['count' => $total, 'property_id' => (string) $id];
    }
  }

  private function getDimensions($metrics) {
    return [
      'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
      'eventCount' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,eventName'
    ][$metrics];
  }

  private function queryPropertyRecent($id, $start, $end, $metrics, $filters, $property_url) {
    $before = count($this->pins);
    $id = (string) $id;
    $dimensions = $this->getDimensions($metrics);

    $results = $this->getPropertyRecent(
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
      if (empty($position)) { continue; }
      $location = $row->getLocation();
      if (empty($location)) { continue; }
      $metadata = $row->getMetadata($property_url);
      if (empty($metadata)) { continue; }

      $this->pins[] = [
        'date' => $row->getDate(),
        'title' => $metadata['citation_title'],
        'url' => $metadata['citation_url'],
        'author' => $metadata['citation_author'],
        'location' => $location,
        'position' => $position,
        'access' => $metadata['access'],
        'property_id' => (string) $id,
      ];
    }
    $this->log("  Scraped: " . (count($this->pins) - $before) . "\n");
  }

  private function queryProperty($property) {
    $id = $property['id'];
    $metrics = $property['metrics'];
    $filters = $property['filters'];
    $start   = isset($property['start']) ? $property['start'] : $this->recentPinsStart;
    $end     = isset($property['end']) ? $property['end'] : $this->recentPinsEnd;

    $property_url = isset($property['property_url']) ? $property['property_url'] : '';
    if (is_array($metrics)) { $metrics = implode(',', $metrics); }
    if (is_array($filters)) { $filters = implode(',', $filters); }

    $this->queryPropertyTotal($id, $metrics, $filters);
    $this->queryPropertyAnnual($id, $metrics, $filters);
    $this->queryPropertyRecent($id, $start, $end, $metrics, $filters, $property_url);
  }

  private function getProperties() {
    return $this->config->getProperties();
  }
}
