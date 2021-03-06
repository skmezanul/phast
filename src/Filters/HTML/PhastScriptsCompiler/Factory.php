<?php


namespace Kibo\Phast\Filters\HTML\PhastScriptsCompiler;


use Kibo\Phast\Cache\File\Cache;
use Kibo\Phast\Filters\HTML\HTMLFilterFactory;

class Factory implements HTMLFilterFactory {

    public function make(array $config) {
        $cache = new Cache($config['cache'], 'phast-scripts');
        $compiler = new PhastJavaScriptCompiler($cache);
        return new Filter($compiler);
    }


}
