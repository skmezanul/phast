<?php

namespace Kibo\Phast\Filters\Image\WEBPEncoder;

class Factory {

    public function make(array $config) {
        return new Filter($config['images']['filters'][Filter::class]);
    }

}
