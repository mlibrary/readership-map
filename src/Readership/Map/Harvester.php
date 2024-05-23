<?php
namespace Readership\Map;

class Harvester {
  use Logging;

  private $config;
  private $analytics;
  private $scraper;
  private $geoMap;
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
    foreach ($this->getStreams() as $stream) {
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
      $this->populateGeoMap($stream['id']);
      $this->queryStream($stream);
    }
    catch (\Exception $e) {
      $this->log("  Exception caught: " . $e->getMessage() . "\n");
    }
  }

  private function getGeoData($id, $index) {
    return $this->analytics->getGeoData($id, $index);
  }

  private function populateGeoMap($id, $start_index = 1) {
    $geo_results = $this->getGeoData($id, $start_index);

    foreach ((array)$geo_results->getRows() as $row) {
      list($city, $region, $country, $lat, $lng) = $row;
      if ($lat == '0.000' && $lng == '0.000') {
        continue;
      }
      $this->geoMap["$city//$region//$country"] = ['lat' => floatval($lat), 'lng' => floatval($lng)];
    }

    $start_index += 1000;
    if ($geo_results->getTotalResults() > $start_index && $start_index < 5000) {
      $this->populateGeoMap($id, $start_index);
    }
  }

  private function getStreamTotals($id, $metrics, $filters) {
    return $this->analytics->getStreamTotals($id, $metrics, $filters);
  }

  private function queryStreamTotal($id, $metrics, $filters) {
    $events = $this->getStreamTotals($id, $metrics, $filters);
    $rows = $events->getRows();
    if (!empty($rows)) {
      $this->pageviews['total'][] = ['count' => intval($rows[0][0]), 'stream_id' => (string) $id];
    }
  }

  private function getStreamAnnual($id, $metrics, $filters) {
    return $this->analytics->getStreamAnnual($id, $metrics, $filters);
  }

  private function getStreamRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return $this->analytics->getStreamRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters);
  }

  private function queryStreamAnnual($id, $metrics, $filters) {
    $events = $this->getStreamAnnual($id, $metrics, $filters);
    $rows = $events->getRows();
    if (!empty($rows)) {
      $this->pageviews['annual'][] = ['count' => intval($rows[0][0]), 'stream_id' => (string) $id];
    }
  }

  // TODO: (Testing) Update tags
  private function getDimensions($metrics) {
    return [
      'screenPageViews' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,pageTitle',
      'eventCount' => 'dateHourMinute,hostName,pagePathPlusQueryString,city,region,country,eventName'
    ][$metrics];
  }

  private function queryStreamRecent($id, $start, $end, $metrics, $filters, $stream_url) {
    $before = count($this->pins);
    $id = (string) $id;
    $dimensions = $this->getDimensions($metrics);

    $results = $this->getStreamRecent(
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
  }

  // TODO: Update to include both stream id and property id
  private function queryStream($stream) {
    $id = $stream['id'];
    $metrics = $stream['metrics'];
    $filters = $stream['filters'];
    $start   = isset($stream['start']) ? $stream['start'] : $this->recentPinsStart;
    $end     = isset($stream['end']) ? $stream['end'] : $this->recentPinsEnd;

    $stream_url = isset($stream['stream_url']) ? $stream['stream_url'] : '';
    if (is_array($metrics)) { $metrics = implode(',', $metrics); }
    if (is_array($filters)) { $filters = implode(',', $filters); }
    $this->queryStreamTotal($id, $metrics, $filters);
    $this->queryStreamAnnual($id, $metrics, $filters);
    $this->queryStreamRecent($id, $start, $end, $metrics, $filters, $stream_url);
  }

  private function getStreams() {
    return $this->config->getStreams();
  }
}
