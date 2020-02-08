<?php
namespace Readership\Map;

class Harvester {
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
    foreach ($this->getViews() as $view) {
      $this->processView($view);
    }

    $this->sortPins(function($a, $b) {
      if ($a['date'] == $b['date']) { return 0; }
      return ($a['date'] < $b['date']) ? -1 : 1;
    });
  }

  private function processView($view) {
    $this->log("Processing view: {$view['id']} / {$view['metrics']}\n");
    try {
      $this->populateGeoMap($view['id']);
      $this->queryView($view);
    }
    catch (Exception $e) {
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

  private function getViewTotals($id, $metrics, $filters) {
    return $this->analytics->getViewTotals($id, $metrics, $filters);
  }

  private function queryViewTotal($id, $metrics, $filters) {
    $events = $this->getViewTotals($id, $metrics, $filters);
    $rows = $events->getRows();
    if (!empty($rows)) {
      $this->pageviews['total'][] = ['count' => intval($rows[0][0]), 'view_id' => (string) $id];
    }
  }

  private function getViewAnnual($id, $metrics, $filters) {
    return $this->analytics->getViewAnnual($id, $metrics, $filters);
  }

  private function getViewRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return $this->analytics->getViewRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters);
  }

  private function queryViewAnnual($id, $metrics, $filters) {
    $events = $this->getViewAnnual($id, $metrics, $filters);
    $rows = $events->getRows();
    if (!empty($rows)) {
      $this->pageviews['annual'][] = ['count' => intval($rows[0][0]), 'view_id' => (string) $id];
    }
  }

  private function getDimensions($metrics) {
    return [
      'ga:pageviews' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:pageTitle', 
      'ga:totalEvents' => 'ga:dateHourMinute,ga:hostname,ga:pagePath,ga:city,ga:region,ga:country,ga:eventLabel'
    ][$metrics];
  }

  private function queryViewRecent($id, $metrics, $filters, $view_url) {
    $before = count($this->pins);
    $id = (string) $id;
    $dimensions = $this->getDimensions($metrics);

    $results = $this->getViewRecent(
      $id,
      $this->recentPinsStart,
      $this->recentPinsEnd,
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
      $metadata = $row->getMetadata($view_url);
      if (empty($metadata)) { continue; }

      $this->pins[] = [
        'date' => $row->getDate(),
        'title' => $metadata['citation_title'],
        'url' => $metadata['citation_url'],
        'author' => $metadata['citation_author'],
        'location' => $location,
        'position' => $position,
        'access' => $metadata['access'],
        'view_id' => (string) $id,
      ];
    }
    $this->log("  Scraped: " . (count($this->pins) - $before) . "\n");
  }

  private function queryView($view) {
    $id = $view['id'];
    $metrics = $view['metrics'];
    $filters = $view['filters'];
    $view_url = isset($view['view_url']) ? $view['view_url'] : '';
    if (is_array($metrics)) { $metrics = implode($metrics, ','); }
    if (is_array($filters)) { $filters = implode($filters, ','); }
    $this->queryViewTotal($id, $metrics, $filters);
    $this->queryViewAnnual($id, $metrics, $filters);
    $this->queryViewRecent($id, $metrics, $filters, $view_url);
  }

  private function log($string) {
    fwrite(STDERR, $string);
  }

  private function getViews() {
    return $this->config->getViews();
  }
}
