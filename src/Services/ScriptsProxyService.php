<?php

namespace Kibo\Phast\Services;

use Kibo\Phast\Common\JSMinifier;
use Kibo\Phast\Retrievers\Retriever;
use Kibo\Phast\Security\ServiceSignature;

class ScriptsProxyService extends ProxyService {

    /**
     * @var bool
     */
    private $removeLicenseHeaders = true;

    public function __construct(ServiceSignature $signature, $whitelist, Retriever $retriever, $removeLicenseHeaders) {
        parent::__construct($signature, $whitelist, $retriever);
        $this->removeLicenseHeaders = $removeLicenseHeaders;
    }

    protected function handle(array $request) {
        $response = parent::handle($request);
        $response->setHeader('Content-Type', 'application/javascript');
        return $response;
    }

    protected function doRequest(array $request) {
        $result = parent::doRequest($request);
        return (new JSMinifier($result, $this->removeLicenseHeaders))->min();
    }

}
