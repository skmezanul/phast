<?php
require_once __DIR__ . '/../../vendor/autoload.php';
$config = require_once __DIR__ . '/../../src/config-example.php';
try {
    list ($type, $image) = \Kibo\Phast\Factories\ImageFilteringServiceFactory::make($config['images'])->serve($_GET);
    if ($type == \Kibo\Phast\Filters\Image\Image::TYPE_JPEG) {
        header('Content-type: image/jpeg');
    } else if ($type == \Kibo\Phast\Filters\Image\Image::TYPE_PNG) {
        header('Content-type: image/png');
    }
    echo $image;
} catch (\Kibo\Phast\Exceptions\ItemNotFoundException $exception) {
    http_response_code(404);
} catch (\Kibo\Phast\Exceptions\UnauthorizedException $e) {
    http_response_code(403);
} catch (Exception $e) {
    http_response_code(500);
}
