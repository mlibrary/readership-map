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

  public function pinStartDate() {
    return date(
      $this->config['start']['datefmt'],
      strtotime($this->config['start']['date'])
    );
  }

  public function pinEndDate() {
    return date(
      $this->config['end']['datefmt'],
      strtotime($this->config['end']['date'])
    );
  }
  
  public function addViews($views) {
    // Capture all of the view_id => view_url associations.
    $viewUrls = [];
    foreach ((array) $views as $view) {
      $viewUrls[$view['id']] = $view['view_url'];
    }
    foreach ((array) $this->config['views'] as $i => $view) {
      if (isset($view['id']) && empty($view['view_url'])) {
        $this->config['views'][$i]['view_url'] = $viewUrls[$view['id']];
      }
    }

    // Only import views from accounts we have defined metrics and filters for.
    foreach ((array) $views as $view) {
      $accountId = $view['account_id'];

      if (empty($accountId)) { continue; }

      foreach ((array) $this->config['accounts'] as $account) {
        if ($account['id'] != $accountId) { continue; }

        $this->config['views'][] = $view + [
          'metrics' => $account['metrics'],
          'filters' => $account['filters'],
        ];
      }
    }
  }

  private function cleanConfig() {
    // Ensure that view id's are handled like strings.
    foreach ((array) $this->config['views'] as $i => $view) {
      $this->config['views'][$i]['id'] = (string) $view['id'];
    }
  }

  public function getViews() {
    return $this->config['views'];
  }
}
