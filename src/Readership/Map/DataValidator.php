<?php
namespace Readership\Map;

class DataValidator {
  private $pinAttributes = [
    'title',
    'author',
    'view_id',
    'url',
    'access',
    'date',
    'position',
    'location',
  ];

  public function validFile($file) {
    $json = json_decode(file_get_contents($file), TRUE);
    if ($this->validJSON($json)) {
      return 0;
    }
    return 1;
  }

  public function validJSON($json) {
    if (!is_array($json)) { return FALSE; }
    if (empty($json['pageviews'])) { return FALSE; }
    if (empty($json['pageviews']['total'])) { return FALSE; }
    if (empty($json['pageviews']['annual'])) { return FALSE; }
    if (!$this->validCounts($json['pageviews']['total'])) { return FALSE; }
    if (!$this->validCounts($json['pageviews']['annual'])) { return FALSE; }
    if (empty($json['pins'])) { return FALSE; }
    return $this->validPins($json['pins']);
  }

  private function validPins($pins) {
    foreach ($pins as $pin) {
      foreach ($this->pinAttributes as $attr) {
        if (!isset($pin[$attr])) {
          return FALSE;
        }
      }
      if (!isset($pin['position']['lat'])) { return FALSE; }
      if (!isset($pin['position']['lng'])) { return FALSE; }
    }
    return TRUE;
  }

  private function validCounts($counts) {
    foreach ($counts as $count) {
      if (!isset($count['count']) || !isset($count['property_id'])) {
        return FALSE;
      }
    }
    return TRUE;
  }
}
