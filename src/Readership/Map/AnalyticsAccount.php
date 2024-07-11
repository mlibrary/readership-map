<?php
namespace Readership\Map;

class AnalyticsAccount {
  private $applicationName = 'Michigan Publishing Readership Map';
  private $scopes = [ 'https://www.googleapis.com/auth/analytics.readonly' ];
  private $properties;
  private $accountInfo;
  private GoogleClientDriver $driver;
  
  public function __construct($driver = 'Readership\Map\GoogleClientDriver') {
    $this->driver = new $driver($this->applicationName, $this->scopes);
    $this->properties = $this->driver->getProperties();
    $this->accountInfo = $this->driver->getAccountInfo();
  }

  public function getPropertyRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
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

  public function getPropertyAnnual($id, $metrics, $filters) {
    return $this->driver->query(
      $id,
      '365daysAgo',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  public function getPropertyTotals($id, $metrics, $filters) {
    return $this->driver->query(
      $id,
      '2015-08-14',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  public function getGeoData($id, $index) {
    return $this->driver->query(
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

  public function getProperties() {
    return $this->properties;
  }

  public function getAccountInfo() {
    return $this->accountInfo;
  }
}
