<?php

namespace Kibo\Phast\Factories;

use Kibo\Phast\Factories\Filters\Image\CompositeImageFilterFactory;
use Kibo\Phast\Factories\Filters\Image\ImageFactory;
use Kibo\Phast\ImageFilteringService;
use Kibo\Phast\Security\ImagesOptimizationSignature;

class ImageFilteringServiceFactory {

    public static function make(array $config) {
        return new ImageFilteringService(
            new ImageFactory($config),
            new CompositeImageFilterFactory($config),
            new ImagesOptimizationSignature($config['securityToken'])
        );
    }

}