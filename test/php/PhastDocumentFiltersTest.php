<?php

namespace Kibo\Phast;

/**
 * @runTestsInSeparateProcesses
 */
class PhastDocumentFiltersTest extends \PHPUnit_Framework_TestCase {

    public function setUp() {
        $_SERVER += [
            'HTTP_HOST' => 'example.com',
            'REQUEST_URI' => '/'
        ];
    }

    public function testDeploy() {
        $handlersBefore = ob_list_handlers();

        PhastDocumentFilters::deploy([]);

        $handlersAfter = ob_list_handlers();

        $this->assertNotEquals($handlersBefore, $handlersAfter);
    }

    public function testApply() {
        $in = '<!doctype html><html><head><title>Hello, World!</title></head><body></body></html>';
        $out = PhastDocumentFilters::apply($in, []);
        $this->assertContains('[Phast]', $out);
    }

    public function testApplyNonHTML() {
        $in = 'Nope';
        $out = PhastDocumentFilters::apply($in, []);
        $this->assertEquals($in, $out);
    }

}
