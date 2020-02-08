<?php
namespace Readership\Map;

use Symfony\Component\Yaml\Yaml;

class Configuration {
  private $config;

  private $viewUrls = [];

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
  
  public function addViews($views) {
    // Capture all of the view_id => view_url, start and end associations.
    $viewMetadata = [ 'view_url' => [], 'start' => [], 'end' => [] ];
    foreach (array_keys($viewMetadata) as $meta) {
      foreach ((array) $views as $view) {
        if (isset($view[$meta])) {
          $viewMetadata[$meta][$view['id']] = $view[$meta];
        }
      }
      foreach ((array) $this->config['views'] as $i => $view) {
        if (isset($view['id']) && empty($view[$meta]) && isset($viewMetadata[$meta][$view['id']])) {
          $this->config['views'][$i][$meta] = $viewMetadata[$meta][$view['id']];
        }
      }
    }

    // Only import views from accounts we have defined metrics and filters for the account.
    foreach ((array) $views as $view) {
      $accountId = $view['account_id'];

      if (empty($accountId)) { continue; }

      foreach ((array) $this->config['accounts'] as $account) {
        if ($account['id'] != $accountId) { continue; }
        $candidate = $view + [
          'metrics' => $account['metrics'],
          'filters' => $account['filters'],
        ];
        if (isset($account['start'])) {
          $candidate['start'] = $account['start'];
        }
        if (isset($account['end'])) {
          $candidate['end'] = $account['end'];
        }
        $this->config['views'][] = $candidate;
      }
    }
  }

  private function cleanConfig() {
    // Ensure that view id's are handled like strings.
    foreach ((array) $this->config['views'] as $i => $view) {
      $this->config['views'][$i]['id'] = (string) $view['id'];
      foreach (['start', 'end'] as $date) {
        if (isset($view[$date])) {
          $this->config['views'][$i][$date] = $this->formatDate($view[$date]);
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

  public function getViews() {
    return $this->config['views'];
  }
}
