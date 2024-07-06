<?php
namespace Readership\Map;

class Row {
  use Logging;

  private $data = [];
  private $scraper;

  public function __construct($dimensions, $metrics, $row, $scraper) {
    $this->scraper = $scraper;
    $this->data = [];

    $dimension_values = $row->getDimensionValues();
    $metric_values = $row->getMetricValues();

    
    foreach (explode(',', $dimensions) as $i => $column) {
      $this->data[$column] = $dimension_values[$i]->getValue();
    }

    foreach (explode(',', $metrics) as $i => $column) {
      $this->data[$column] = $metric_values[$i]->getValue();
    }
  }

  public function getDate() {
    return $this->data['dateHourMinute'];
  }

  public function getPosition($geoMap) {
    $key = $this->data['region'] . '//' . $this->data['country'];
    if(str_contains($key, '(not set)')){ 
      return null;
    }
    
    return $geoMap[$key];
  }


  public function getLocation() {
    return join(
      ', ',
      array_filter(
        array_unique([$this->data['city'], $this->data['region'], $this->data['country']]),
        function ($var) { return !empty($var) && $var != '(not set)'; }
      )
    );
  }

  private function scrape($url) {
    return $this->scraper->scrape($url);
  }

  public function getMetadata($stream_url) {
    $candidate_urls = [];
    
    if (!empty($this->data['eventName'])) {
      $candidate_urls = [$this->data['eventName']];
    }
    if (!empty($this->data['hostName']) && !empty($this->data['pagePathPlusQueryString'])) {
      if (strpos($stream_url, $this->data['hostName']) === FALSE && strlen($stream_url) > 10) {
        $candidate_urls[] = substr($stream_url, strpos($stream_url, '/', 9), strlen($stream_url)) . $this->data['pagePathPlusQueryString'];
      }

      if (strpos($this->data['hostName'], 'quod-lib-umich-edu') !== FALSE) {
        $candidate_urls[] = 'https://quod.lib.umich.edu' . $this->data['pagePathPlusQueryString'];
      }

      if (strpos($this->data['hostName'], 'fulcrum-org') !== FALSE) {
        $candidate_urls[] = 'https://www.fulcrum.org' . $this->data['pagePathPlusQueryString'];
      }
      $candidate_urls[] = 'https://' . $this->data['hostName'] . $this->data['pagePathPlusQueryString'];
    }

    foreach ($candidate_urls as $url) {
      if (strpos($url, 'http') !== 0) { continue; }
      $ret = $this->scrape($url);
      if ($ret) { return $ret; }
    }

    return NULL;
  }

}

