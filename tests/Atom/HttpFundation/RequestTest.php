<?php

declare(strict_types=1);

namespace Tests\Atom\HttpFundation;

use Atom\HttpFoundation\Request;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for the Request component.
 */
class RequestTest extends TestCase
{
    /**
     * Test get method returns correct http method.
     */
    public function test_get_method_returns_correct_http_method()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertEquals('get', $request->getMethod());
        
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request();
        $this->assertEquals('post', $request->getMethod());
    }

    /**
     * Test get url returns correct url without query string.
     */
    public function test_get_url_returns_correct_url_without_query_string()
    {
        $_SERVER['REQUEST_URI'] = '/test/path?query=string';
        $request = new Request();
        $this->assertEquals('/test/path', $request->getUrl());
        
        $_SERVER['REQUEST_URI'] = '/another/path/without/query/string';
        $request = new Request();
        $this->assertEquals('/another/path/without/query/string', $request->getUrl());
    }

    /**
     * Test is get returns true for get requests.
     */
    public function test_is_get_returns_true_for_get_requests()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertTrue($request->isGet());
        
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request();
        $this->assertFalse($request->isGet());
    }

    /**
     * Test is post returns true for post requests.
     */
    public function test_is_post_returns_true_for_post_requests()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = new Request();
        $this->assertTrue($request->isPost());
        
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertFalse($request->isPost());
    }

    /**
     * Test is put returns true for put requests.
     */
    public function test_is_put_returns_true_for_put_requests()
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $request = new Request();
        $this->assertTrue($request->isPut());
        
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertFalse($request->isPut());
    }

    /**
     * Test is delete returns true for delete requests.
     */
    public function test_is_delete_returns_true_for_delete_requests()
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $request = new Request();
        $this->assertTrue($request->isDelete());
        
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertFalse($request->isDelete());
    }

    /**
     * Test is patch returns true for patch requests.
     */
    public function test_is_patch_returns_true_for_patch_requests()
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $request = new Request();
        $this->assertTrue($request->isPatch());
        
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertFalse($request->isPatch());
    }

    /**
     * Test is options returns true for options requests.
     */
    public function test_is_options_returns_true_for_options_requests()
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        $request = new Request();
        $this->assertTrue($request->isOptions());
        
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertFalse($request->isOptions());
    }

    /**
     * Test is head returns true for head requests.
     */
    public function test_is_head_returns_true_for_head_requests()
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $request = new Request();
        $this->assertTrue($request->isHead());
        
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();
        $this->assertFalse($request->isHead());
    }

    /**
     * Test get body returns correct data for get request.
     */
    public function test_get_body_returns_correct_data_for_get_request()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['name' => 'John', 'age' => '30'];
        
        $request = new Request();
        $body = $request->getBody();
        
        $this->assertArrayHasKey('name', $body);
        $this->assertArrayHasKey('age', $body);
    }

    /**
     * Test get body returns correct data for post request.
     */
    public function test_get_body_returns_correct_data_for_post_request()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['name' => 'John', 'age' => '30'];
        
        $request = new Request();
        $body = $request->getBody();
        
        $this->assertArrayHasKey('name', $body);
        $this->assertArrayHasKey('age', $body);
    }

    /**
     * Test set and get route params.
     */
    public function test_set_and_get_route_params()
    {
        $params = ['id' => 1, 'slug' => 'test'];
        $request = new Request();
        $request->setRouteParams($params);
        
        $this->assertEquals($params, $request->getRouteParams());
    }

    /**
     * Test get route param returns correct value.
     */
    public function test_get_route_param_returns_correct_value()
    {
        $params = ['id' => 1, 'slug' => 'test'];
        $request = new Request();
        $request->setRouteParams($params);
        
        $this->assertEquals(1, $request->getRouteParam('id'));
        $this->assertEquals('test', $request->getRouteParam('slug'));
    }

    /**
     * Test get route param returns default value when not exists.
     */
    public function test_get_route_param_returns_default_value_when_not_exists()
    {
        $request = new Request();
        $request->setRouteParams(['id' => 1]);
        
        $this->assertEquals(0, $request->getRouteParam('nonexistent', 0));
    }
}
