<?php
namespace Readership\Map;

class AnalyticsAccount {
  private $applicationName = 'Michigan Publishing Readership Map';
  private $scopes = [ 'https://www.googleapis.com/auth/analytics.readonly' ];
  private $streams;
  private $accountInfo;
  private GoogleClientDriver $driver;
  
  public function __construct($driver = 'Readership\Map\GoogleClientDriver') {
    $this->driver = new $driver($this->applicationName, $this->scopes);
    $this->streams = $this->driver->getStreams();
    $this->accountInfo = $this->driver->getAccountInfo();
  }

  public function testStream() {
    $retVal = $this->driver->queryTest();
    
    return $retVal;
  }

  public function getStreamRecent($property_id, $id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return $this->driver->query(
      $property_id,
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

  public function getStreamAnnual($property_id, $id, $metrics, $filters) {
    return $this->driver->query(
      $property_id,
      $id,
      '365daysAgo',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  public function getStreamTotals($property_id, $id, $metrics, $filters) {
    return $this->driver->query(
      $property_id,
      $id,
      '2015-08-14',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  public function getGeoData($property_id, $id, $index) {
    return $this->driver->query(
      $property_id,
      $id,
      '7daysAgo',
      'today',
      'screenPageViews',
      [
        'dimensions' => 'city,region,country',
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
