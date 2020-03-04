<?php

namespace Readership\Map;

class NullResults {
   public function getRows() {
     return [];
   }

   public function getTotalResults() {
     return 0;
   }
}
