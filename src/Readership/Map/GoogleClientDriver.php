<?php
namespace Readership\Map;


use Google\Analytics\Data\V1beta\Client\BetaAnalyticsDataClient;
use Google\Analytics\Data\V1beta\Dimension;
use Google\Analytics\Data\V1beta\DateRange;
use Google\Analytics\Data\V1beta\RunReportRequest;
use Google\Analytics\Data\V1beta\Metric;
use Google\Analytics\Data\V1beta\Filter;
use Google\Analytics\Data\V1beta\FilterExpression;
use Google\Analytics\Data\V1beta\FilterExpressionList;
use Google\Analytics\Data\V1beta\Filter\InListFilter;
use Google\Analytics\Data\V1beta\Filter\StringFilter;
use Google\Analytics\Data\V1beta\OrderBy;

use Google\Analytics\Admin\V1beta\Account;
use Google\Analytics\Admin\V1beta\Client\AnalyticsAdminServiceClient;
use Google\Analytics\Admin\V1beta\ListAccountsRequest;
use Google\Analytics\Admin\V1beta\ListPropertiesRequest;
use Google\Analytics\Admin\V1beta\DataStream;
use Google\Analytics\Admin\V1beta\GetDataStreamRequest;
use Google\Analytics\Admin\V1beta\ListDataStreamsRequest;
/**
 * @codeCoverageIgnore
 */
class GoogleClientDriver {
  private $analyticsClient;
  private $adminClient;
  private $streams = [];
  private $accountInfo = [];
  private $googleClientConfig = [
    'retries' => 5,
  ];

  public function __construct($name, $scopes) {
    $this->analyticsClient = new BetaAnalyticsDataClient();
    $this->adminClient = new AnalyticsAdminServiceClient();
    $this->loadAccountData();
  }

  private function get_date_range($name, $start_date, $end_date) { 
    $dateRange = new DateRange(["name" => $name]);
    $dateRange->setStartDate($start_date); 
    $dateRange->setEndDate($end_date);
  
    return $dateRange;
  }

  // TODO: Add in dimensions and filters
  public function query($id, $start, $end, $metrics, $options) {
    try {
      $dateRanges = [ $this->get_date_range('recent_range', $start, $end) ];

      $request = new RunReportRequest([
        'property' => $id,
        'date_ranges' => $dateRanges,
        // 'dimensions' => $dimensions,
        'metrics' => [ $metrics ],
        // "dimension_filter" => get_query_stream_filter_expression($id, $filters),
        'order_bys' => [
          new OrderBy([
              'dimension' => new OrderBy\DimensionOrderBy([
                  'dimension_name' => 'dateHourMinute', 
                  'order_type' => OrderBy\DimensionOrderBy\OrderType::ALPHANUMERIC
              ]),
              'desc' => false,
          ]),],
      ]);

      return $this->analyticsClient->runReport($request);
    }
    catch (\Exception $e) {
      return new NullResults();
    }
  }

  public function getAccountInfo() {
    return $this->accountInfo;
  }

  public function getStreams() {
    return $this->streams;
  }

  private function listAccounts() {
    try {
      $listAccountsRequest = new ListAccountsRequest();
      return $this->adminClient->listAccounts($listAccountsRequest);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  private function listProperties(string $accountId) {
    try {
      $filter = new ListPropertiesRequest();
      $filter->setFilter("parent:accounts/$accountId");
      return $this->adminClient->listProperties($filter);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  private function listDataStreams(string $propertyId) {
    try {
      $streamRequest = (new ListDataStreamsRequest());
      $streamRequest->setParent("properties/$propertyId");
      return $this->adminClient->listDataStreams($streamRequest);
    }
    catch (\Exception $e) {
      return [];
    }
  }

  public function loadAccountData() {

    $accountList = $this->listAccounts();
    foreach ($accountList as $account) {
      $accountId = explode('/', $account->getName())[1];
      $this->accountInfo[$accountId] = [];
      $accountName = $account->getDisplayName();
    
      $filter = new ListPropertiesRequest();
      $filter->setFilter("parent:accounts/$accountId");
      $propertyList = $this->adminClient->listProperties($filter);

      foreach ($propertyList as $property) {
        $propertyId = (string) explode('/', $property->getName())[1];
        $propertyName = $property->getDisplayName();
        $streams = $this->listDataStreams($propertyId);

        foreach ($streams as $stream) {
          $stream_id = (string) explode('/', $stream->getName())[3];
          $stream_name = $stream->getDisplayName();
          $stream_url  = $stream->getWebStreamData()->getDefaultUri();

          $this->streams[] = [
            'id' => $stream_id,
            'stream_name' => $stream_name,
            'stream_url' => $stream_url,
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
