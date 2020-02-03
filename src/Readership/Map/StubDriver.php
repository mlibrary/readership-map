<?php
namespace Readership\Map;

class StubDriver {
  public function __construct($name, $scope) {
  }

  public function getViews() {
    return [];
  }

  public function getAccountInfo() {
    return [];
  }
}
