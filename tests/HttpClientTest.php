<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class HttpClientTest extends TestCase {
    public function testGithubRequest () : Events\Client\HTTP {
      $eventBase = Events\Base::singleton ();
      $httpClient = new Events\Client\HTTP ($eventBase);
      $httpClient->useSessionCookies (true);
      
      $httpResult = Events\Synchronizer::do (
        $eventBase,
        $httpClient->request ('https://www.github.com')
      );
      
      $this->assertIsString ($httpResult);
      $this->assertGreaterThan (
        1024,
        strlen ($httpResult)
      );
      
      return $httpClient;
    }
    
    /**
     * @depends testGithubRequest
     **/
    public function testCookies (Events\Client\HTTP $httpClient) : void {
      if (count ($httpClient->getSessionCookies ()) == 0) {
        $this->markTestSkipped ('Initial test did not return any cookies');
        
        return;
      }
      
      $eventBase = $httpClient->getEventBase ();
      
      $testFile = tempnam (sys_get_temp_dir (), 'SessionCookieTest');
      unlink ($testFile);
      
      clearstatcache ();
      $this->assertFalse (is_file ($testFile));
      
      Events\Synchronizer::do (
        $eventBase,
        $httpClient->setSessionPath ($testFile)
      );
      
      clearstatcache ();
      $this->assertTrue (is_file ($testFile));
      
      $cookieCount = count ($httpClient->getSessionCookies ());
      
      $newHTTPClient = new Events\Client\HTTP ($eventBase);
      $newHTTPClient->useSessionCookies (true);
      
      $this->assertCount (0, $newHTTPClient->getSessionCookies ());
      
      Events\Synchronizer::do (
        $eventBase,
        $newHTTPClient->setSessionPath ($testFile)
      );
      
      $this->assertCount ($cookieCount, $newHTTPClient->getSessionCookies ());
      
      @unlink ($testFile);
    }
  }
