<?php
namespace Readership\Map;

/**
 * @codeCoverageIgnore
 */
class GoogleClientDriver {
  private $client;
  private $analytics;
  private $views = [];
  private $accountInfo = [];
  private $googleClientConfig = [
    'retries' => 5,
  ];

  public function __construct($name, $scopes) {
    $this->client = new \Google_Client($this->googleClientConfig);
    $this->client->useApplicationDefaultCredentials();
    $this->client->setApplicationName($name);
    $this->client->setScopes($scopes);
    $this->analytics = new \Google_Service_Analytics($this->client);
    $this->loadAccountData();
  }

  public function query($id, $start, $end, $metrics, $options) {
    try {
      return $this->analytics->data_ga->get(
        $id,
        $start,
        $end,
        $metrics,
        $options
      );
    }
    catch (\Exception $e) {
      return new NullResults();
    }
  }

  public function getAccountInfo() {
    return $this->accountInfo;
  }

  public function getViews() {
    return $this->views;
  }

  private function listManagementAccounts() {
    try {
      return $this
        ->analytics
        ->management_accounts
        ->listManagementAccounts()
        ->getItems();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  private function listManagementWebproperties(string $accountId) {
    try {
      return $this
        ->analytics
        ->management_webproperties
        ->listManagementWebproperties($accountId)
        ->getItems();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  private function listManagementProfiles(string $accountId, string $propertyId) {
    try {
      return $this
        ->analytics
        ->management_profiles
        ->listManagementProfiles($accountId, $propertyId)
        ->getItems();
    }
    catch (\Exception $e) {
      return [];
    }
  }

  public function loadAccountData() {

    $accountList = $this->listManagementAccounts();
    foreach ($accountList as $account) {
      $accountId = $account->getId();
      $this->accountInfo[$accountId] = [];
      $accountName = $account->getName();
      $propertyList = $this->listManagementWebproperties($accountId);
      foreach ($propertyList as $property) {
        $propertyId = $property->getId();
        $propertyName = $property->getName();
        $profilesList = $this->listManagementProfiles($accountId, $propertyId);
        foreach ($profilesList as $profile) {
          $viewId = (string) $profile->getId();
          $viewName = $profile->getName();
          $viewURL  = $profile->getWebsiteUrl();
          $this->views[] = [
            'id' => $viewId,
            'view_name' => $viewName,
            'view_url'  => $viewURL,
            'property_name' => $propertyName,
            'property_id' => $propertyId,
            'account_name' => $accountName,
            'account_id' => $accountId,
          ];
        }
      }
    }
  }
}
