<?php
namespace Readership\Map;

use Google\Analytics\Data\V1beta\DateRange;
use Symfony\Component\Yaml\Yaml;

class Configuration {
  private $config;

  private $streamUrls = [];

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
  
  public function addStreams($streams) {
    // Capture all of the stream_id => stream_url, start and end associations.
    $streamMetadata = [ 'stream_url' => [], 'start' => [], 'end' => [] ];
    foreach (array_keys($streamMetadata) as $meta) {
      foreach ((array) $streams as $stream) {
        if (isset($stream[$meta])) {
          $streamMetadata[$meta][$stream['id']] = $stream[$meta];
        }
      }
      foreach ((array) $this->config['streams'] as $i => $stream) {
        if (isset($stream['id']) && empty($stream[$meta]) && isset($streamMetadata[$meta][$stream['id']])) {
          $this->config['streams'][$i][$meta] = $streamMetadata[$meta][$stream['id']];
        }
      }
    }

    // Only import streams from accounts we have defined metrics and filters for the account.
    foreach ((array) $streams as $stream) {
      $accountId = $stream['account_id'];

      if (empty($accountId)) { continue; }

      foreach ((array) $this->config['accounts'] as $account) {
        if ($account['id'] != $accountId) { continue; }
        $candidate = $stream + [
          'metrics' => $account['metrics'],
          'filters' => $account['filters'],
        ];
        if (isset($account['start'])) {
          $candidate['start'] = $account['start'];
        }
        if (isset($account['end'])) {
          $candidate['end'] = $account['end'];
        }
        $this->config['streams'][] = $candidate;
      }
    }
  }

  private function cleanConfig() {
    // Ensure that stream id's are handled like strings.
    foreach ((array) $this->config['streams'] as $i => $stream) {
      $this->config['streams'][$i]['id'] = (string) $stream['id'];
      $this->config['streams'][$i]['property_id'] = (string) $stream['property_id'];
      foreach (['start', 'end'] as $date) {
        if (isset($stream[$date])) {
          $this->config['streams'][$i][$date] = $this->formatDate($stream[$date]);
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

  public function getStreams() {
    return $this->config['streams'];
  }
}
