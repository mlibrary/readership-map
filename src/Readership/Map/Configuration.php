<?php
namespace Readership\Map;

use Google\Analytics\Data\V1beta\DateRange;
use Symfony\Component\Yaml\Yaml;

class Configuration {
  private $config;

  private $propertyUrls = [];

  public function __construct($yaml_file = 'config.yml') {
    $this->config = Yaml::parsefile($yaml_file);
    $this->cleanConfig();
  }

  private function formatDate($date) {
    if (is_array($date)) {
      return date($date['datefmt'], strtotime($date['date']));
    }
    return $date;
  }

  public function pinStartDate() {
    return $this->formatDate($this->config['start']);
  }

  public function pinEndDate() {
    return $this->formatDate($this->config['end']);
  }
  
  public function addProperties($properties) {
    // Capture all of the stream_id => stream_url, start and end associations.
    $propertyMetadata = [ 'property_url' => [], 'start' => [], 'end' => [] ];
    foreach (array_keys($propertyMetadata) as $meta) {
      foreach ((array) $properties as $property) {
        if (isset($property[$meta])) {
          $propertyMetadata[$meta][$property['id']] = $property[$meta];
        }
      }
      foreach ((array) $this->config['properties'] as $i => $property) {
        if (isset($property['id']) && empty($property[$meta]) && isset($propertyMetadata[$meta][$property['id']])) {
          $this->config['properties'][$i][$meta] = $propertyMetadata[$meta][$property['id']];
        }
      }
    }

    // Only import streams from accounts we have defined metrics and filters for the account.
    foreach ((array) $properties as $property) {
      $accountId = $property['account_id'];

      if (empty($accountId)) { continue; }

      foreach ((array) $this->config['accounts'] as $account) {
        if ($account['id'] != $accountId) { continue; }
        $candidate = $property + [
          'metrics' => $account['metrics'],
          'filters' => $account['filters'],
        ];
        if (isset($account['start'])) {
          $candidate['start'] = $account['start'];
        }
        if (isset($account['end'])) {
          $candidate['end'] = $account['end'];
        }
        $this->config['properties'][] = $candidate;
      }
    }
  }

  private function cleanConfig() {
    // Ensure that stream id's are handled like strings.
    foreach ((array) $this->config['properties'] as $i => $property) {
      $this->config['properties'][$i]['id'] = (string) $property['id'];
      foreach (['start', 'end'] as $date) {
        if (isset($property[$date])) {
          $this->config['properties'][$i][$date] = $this->formatDate($property[$date]);
        }
      }
    }

    foreach ((array) $this->config['accounts'] as $i => $account) {
      foreach (['start', 'end'] as $date) {
        if (isset($account[$date])) {
          $this->config['accounts'][$i][$date] = $this->formatDate($account[$date]);
        }
      }
    }
  }

  public function getProperties() {
    return $this->config['properties'];
  }
}
