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

  private function getGeoData($property_id, $id, $index) {
    return $this->analytics->getGeoData($property_id, $id, $index);
  }

  private function populateGeoMap($property_id, $id, $start_index = 1) {
    $geo_results = $this->getGeoData($property_id, $id, $start_index);
    fwrite(STDERR, json_encode($geo_results, JSON_PRETTY_PRINT) . PHP_EOL);
    fwrite(STDERR, "HEY!" . PHP_EOL);
    if(count($this->geoMap) >= 10) exit;

    $rows = $geo_results->getRows();

    foreach ($rows as $row) {
      $vals = $row->getDimensionValues();

      $stuff[] = $vals[0]->getValue();
      $stuff[] = $vals[1]->getValue();
      $stuff[] = $vals[2]->getValue();
      $stuff[] = $vals[3]->getValue();
      $stuff[] = $vals[4]->getValue();
      $stuff[] = $vals[5]->getValue();
      $stuff[] = $vals[6]->getValue();

      fwrite(STDERR, json_encode($stuff, JSON_PRETTY_PRINT) . PHP_EOL); 
      exit;
      fwrite(STDERR, "City: $city\nRegion: \$region\nCountry: $country\nLat: $lat\nLng: $lng");
      if ($lat == '0.000' && $lng == '0.000') {
        continue;
      }
      $this->geoMap["$city//$region//$country"] = ['lat' => floatval($lat), 'lng' => floatval($lng)];
    }

    $start_index += 1000;
    if (count($rows) > $start_index && $start_index < 5000) {
      $this->populateGeoMap($id, $start_index);
    }
  }

  private function getStreamTotals($property_id, $id, $metrics, $filters) {
    return $this->analytics->getStreamTotals($property_id, $id, $metrics, $filters);
  }

  private function queryStreamTotal($property_id, $id, $metrics, $filters) {
    $events = $this->getStreamTotals($property_id, $id, $metrics, $filters);
    $rows = $events->getRows();
    if (!empty($rows)) {
      $this->pageviews['total'][] = ['count' => intval($rows[0]->getMetricValues()[0]->getValue()), 'stream_id' => (string) $id];
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
    $rows = (array) $events->getRows();
    if (!empty($rows) && !empty($rows[0])) {
      $this->pageviews['annual'][] = ['count' => intval($rows[0]->getMetricValues()[0]->getValue()), 'stream_id' => (string) $id];
    }
  }

  // TODO: (Testing) Update tags
  private function getDimensions($metrics) {
    return [
      'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
      'eventCount' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,eventName'
    ][$metrics];
  }

  private function queryStreamRecent($property_id, $id, $start, $end, $metrics, $filters, $stream_url) {
    $before = count($this->pins);
    $id = (string) $id;
    $map = function($name) { return new Dimension(["name" => $name]); };
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
    $rows = (array) $results->getRows();
    $this->log("  Found: " . count($rows) . "\n");
    foreach ($rows as $row) {
      $row = new Row($dimensions, $metrics, $row, $this->scraper);
      $position = $row->getPosition($this->geoMap);
      if (empty($position)) { continue; }
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
    exit;
  }

  // TODO: Update to include both stream id and property id
  private function queryStream($stream) {
    $property_id = $stream['property_id'];
    $id = $stream['id'];
    $metrics = $stream['metrics'];
    $filters = $stream['filters'];
    $start   = isset($stream['start']) ? $stream['start'] : $this->recentPinsStart;
    $end     = isset($stream['end']) ? $stream['end'] : $this->recentPinsEnd;

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
