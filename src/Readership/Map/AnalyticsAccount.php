<?php
namespace Readership\Map;

class AnalyticsAccount {
  private $applicationName = 'Michigan Publishing Readership Map';
  private $scopes = [ 'https://www.googleapis.com/auth/analytics.readonly' ];
  private $views;
  private $accountInfo;
  private $driver;
  
  public function __construct($driver = 'Readership\Map\GoogleClientDriver') {
    $this->driver = new $driver($this->applicationName, $this->scopes);
    $this->views = $this->driver->getViews();
    $this->accountInfo = $this->driver->getAccountInfo();
  }

  public function getViewRecent($id, $start, $end, $metrics, $dimensions, $max_results, $filters) {
    return $this->driver->query(
      'ga:' . $id,
      $start,
      $end,
      $metrics,
      [
        'dimensions' => $dimensions,
        'max-results' => $max_results,
        'filters' => $filters,
        'sort' => 'ga:dateHourMinute',
      ]
    );
  }

  public function getViewAnnual($id, $metrics, $filters) {
    return $this->driver->query(
      'ga:' . $id,
      '365daysAgo',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  public function getViewTotals($id, $metrics, $filters) {
    return $this->driver->query(
      'ga:' . $id,
      '2005-01-01',
      'today',
      $metrics,
      [ 'filters' => $filters ]
    );
  }

  public function getGeoData($id, $index) {
    return $this->driver->query(
      'ga:' . $id,
      '7daysAgo',
      'today',
      'ga:pageviews',
      [
        'dimensions' => 'ga:city,ga:region,ga:country,ga:latitude,ga:longitude',
        'start-index' => $index,
      ]
    );
  }

  public function getViews() {
    return $this->views;
  }

  public function getAccountInfo() {
    return $this->accountInfo;
  }
}
