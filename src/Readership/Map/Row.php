<?php
namespace Readership\Map;

class Row {
  private $data;

  public function __construct($dimensions, $metrics, $row, $scraper) {
    $this->scraper = $scraper;
    $this->data = [];
    $this->columns = array_map(
      function($item) { return substr($item, 3, strlen($item) -2); },
      explode(',', implode(',', [$dimensions, $metrics]))
    );
    foreach ($this->columns as $i => $column) {
      $this->data[$column] = $row[$i];
    }
  }

  public function getDate() {
    return $this->data['dateHourMinute'];
  }

  public function getPosition($geoMap) {
    $key = "{$this->data['city']}//{$this->data['region']}//{$this->data['country']}";
    if (empty($geoMap[$key])) { return null; }
    return $geoMap[$key];
  }


  public function getLocation() {
    return join(
      array_filter(
        array_unique([$this->data['city'], $this->data['region'], $this->data['country']]),
        function ($var) { return !empty($var) && $var != '(not set)'; }
      ),
      ', '
    );
  }

  private function scrape($url) {
    return $this->scraper->scrape($url);
  }

  public function getMetadata($view_url) {
    $candidate_urls = [];
    
    if (!empty($this->data['eventLabel'])) {
      $candidate_urls = [$this->data['eventLabel']];
    }
    if (!empty($this->data['hostname']) && !empty($this->data['pagePath'])) {
      if (strpos($view_url, $this->data['hostname']) === FALSE && strlen($view_url) > 10) {
        $candidate_urls[] = substr($view_url, strpos($view_url, '/', 9), strlen($view_url)) . $this->data['pagePath'];
      }

      if (strpos($this->data['hostname'], 'quod-lib-umich-edu') !== FALSE) {
        $candidate_urls[] = 'https://quod.lib.umich.edu' . $this->data['pagePath'];
      }

      if (strpos($this->data['hostname'], 'fulcrum-org') !== FALSE) {
        $candidate_urls[] = 'https://www.fulcrum.org' . $this->data['pagePath'];
      }
      $candidate_urls[] = 'https://' . $this->data['hostname'] . $this->data['pagePath'];
    }

    foreach ($candidate_urls as $url) {
      if (strpos($url, 'http') !== 0) { continue; }
      $ret = $this->scrape($url);
      if ($ret) { return $ret; }
    }

    return NULL;
  }

  /**
   * @codeCoverageIgnore
   */
  private function log($string) {
    fwrite(STDERR, $string);
  }
}

