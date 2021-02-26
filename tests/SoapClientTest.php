<?php

  declare (strict_types=1);

  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;

  final class SoapClientTest extends TestCase {
    public function testWorthyWebserviceSetup () : Events\Client\SOAP {
      $eventBase = Events\Base::singleton ();
      $soapClient = new Events\Client\SOAP ($eventBase);

      $soapResult = Events\Synchronizer::do (
        $eventBase,
        $soapClient->loadWSDL ('https://wp-worthy.de/api/?wsdl')
      );
      
      $this->assertTrue ($soapResult);
      
      return $soapClient;
    }
    
    /**
     * @depends testWorthyWebserviceSetup
     **/
    public function testWorthyAccountStatus (Events\Client\SOAP $soapClient) : void {
      $soapResult = Events\Synchronizer::do (
        $soapClient->getEventBase (),
        $soapClient->serviceAccountStatus (time (), 'efg')
      );
      
      $this->assertIsArray ($soapResult);
      $this->assertEquals (
        $soapResult ['Status'],
        'unregistered'
      );
    }
    
    /**
     * @depends testWorthyWebserviceSetup
     **/
    public function testWorthyException (Events\Client\SOAP $soapClient) : void {
      $this->expectException (\SoapFault::class);
      
      $soapResult = Events\Synchronizer::do (
        $soapClient->getEventBase (),
        $soapClient->markersCreate (
          [ 'Username' => time (), 'Password' => 'abc', 'SessionID' => null ],
          1
        )
      );
    }
  }