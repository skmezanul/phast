<?php

namespace Kibo\Phast\Logging\LogReaders;

use Kibo\Phast\Logging\Common\JSONLFileLogTrait;
use Kibo\Phast\Logging\LogEntry;
use Kibo\Phast\Logging\LogReader;

class JSONLFileLogReader implements LogReader {
    use JSONLFileLogTrait;

    public function readEntries() {
        $fp = @fopen($this->filename, 'r');
        while ($fp && ($row = @fgets($fp))) {
            $decoded = @json_decode($row, true);
            if (!$decoded) {
                continue;
            }
            yield new LogEntry(@$decoded['level'], @$decoded['message'], @$decoded['context']);
        }
    }


}
