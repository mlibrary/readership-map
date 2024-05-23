<?php
namespace Readership\Map;

class AnalyticsAccount {
  private $applicationName = 'Michigan Publishing Readership Map';
  private $scopes = [ 'https://www.googleapis.com/auth/analytics.readonly' ];
  private $streams;
  private $accountInfo;
  private $driver;
  
  public function __construct($driver = 'Readership\Map\GoogleClientDriver') {
    $this->driver = new $driver($this->applicationName, $this->scopes);
    $this->streams = $this->driver->getStreams();
    $this->accountInfo = $this->driver->getAccountInfo();
  }

  // TODO: (Testing) Update to streams
  public function getStreamRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return $this->driver->query(
      $id,
      $start,
      $end,
      $metrics,
      [
        'dimensions' => $dimensions,
        'max-results' => $max_results,
        'filters' => $filters,
        'sort' => 'dateHourMinute',
      ]
    );
  }

  // TODO: (Testing) Update to streams
  public function getStreamAnnual($id, $metrics, $filters) {
    return $this->driver->query(
      $id,
      '365daysAgo',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  // TODO: (Testing) Update to streams
  public function getStreamTotals($id, $metrics, $filters) {
    return $this->driver->query(
      $id,
      '2005-01-01',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  // TODO: (Testing) Update tags
  public function getGeoData($id, $index) {
    return $this->driver->query(
      $id,
      '7daysAgo',
      'today',
      'screenPageViews',
      [
        'dimensions' => 'city,region,country,latitude,longitude',
        'start-index' => $index,
      ]
    );
  }

  public function getStreams() {
    return $this->streams;
  }

  public function getAccountInfo() {
    return $this->accountInfo;
  }
}
