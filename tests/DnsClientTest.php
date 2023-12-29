<?php

  declare (strict_types=1);
  
  use PHPUnit\Framework\TestCase;
  use quarxConnect\Events;
  use quarxConnect\Events\Stream\DNS;
  
  final class DnsClientTest extends TestCase {
    /**
     * @dataProvider existingRecordsProvider
     **/
    public function testResolverWithExisting ($dnsDomain, $dnsType) {
      $eventBase = Events\Base::singleton ();
      $dnsClient = new Events\Client\DNS ($eventBase);
      $dnsEvent = null;

      $dnsClient->addEventListener (
        Events\Client\DNS\Event\Result::class,
        function ($theEvent) use (&$dnsEvent): void {
          $dnsEvent = $theEvent;
        }
      );
      
      $this->assertIsObject (
        $dnsRecordset = Events\Synchronizer::do (
          $eventBase,
          $dnsClient->resolve ($dnsDomain, $dnsType)
        )
      );
      
      $this->assertGreaterThan (
        0,
        count ($dnsRecordset)
      );
      
      static $typeClasses = [
        DNS\Message::TYPE_A    => DNS\Record\A::class,
        DNS\Message::TYPE_AAAA => DNS\Record\AAAA::class,
      ];
      
      foreach ($dnsRecordset as $dnsRecord)
        if (isset ($typeClasses [$dnsType]))
          $this->assertInstanceOf ($typeClasses [$dnsType], $dnsRecord);
      
      $this->assertIsObject (
        $dnsEvent,
        'DNS-Result-Event was received'
      );

      $this->assertEquals (
        $dnsDomain,
        $dnsEvent->getHostname (),
        'Hostname on Event is the same as the queried one'
      );
    }
    
    /**
     * @dataProvider missingRecordsProvider
     **/
    public function testResolverWithMissing ($dnsDomain, $dnsType) : void {
      $eventBase = Events\Base::singleton ();
      $dnsClient = new Events\Client\DNS ($eventBase);
      
      $this->expectException (\Exception::class);
      
      $dnsRecordset = Events\Synchronizer::do (
        $eventBase,
        $dnsClient->resolve ($dnsDomain, $dnsType)
      );
    }
    
    public function testUnreachableResolver () : void {
      $eventBase = Events\Base::singleton ();
      $dnsClient = new Events\Client\DNS ($eventBase);
      $dnsClient->useSystemNameserver ();

      // Insert non-sense nameserver
      $dnsNameservers = $dnsClient->getNameservers ();
      
      array_unshift (
        $dnsNameservers,
        [ 'ip' => '127.255.255.254' ]
      );
      
      array_unshift (
        $dnsNameservers,
        [ 'ip' => '1fff::53' ]
      );
      
      $dnsClient->setNameservers ($dnsNameservers);
      $dnsClient->setTimeout (1.0);
      
      // Try to resolve an existing record
      $this->assertIsObject (
        $dnsRecordset = Events\Synchronizer::do (
          $eventBase,
          $dnsClient->resolve ('microsoft.com', DNS\Message::TYPE_A)
        )
      );
      
      $this->assertGreaterThan (
        0,
        count ($dnsRecordset)
      );
    }

    public function testTimeout () : void {
      $eventBase = Events\Base::singleton ();
      $dnsClient = new Events\Client\DNS ($eventBase);
      $dnsEvent = null;

      $dnsClient->addEventListener (
        Events\Client\DNS\Event\Timeout::class,
        function ($theEvent) use (&$dnsEvent): void {
          $dnsEvent = $theEvent;
        }
      );
      
      // Insert non-sense nameserver
      $dnsNameservers = [
        [ 'ip' => '127.255.255.254' ],
        [ 'ip' => '1fff::53' ],
      ];
      
      $dnsClient->setNameservers ($dnsNameservers);
      $dnsClient->setTimeout (1.0);
      
      // Try to resolve an existing record
      try {
        $dnsRecordset = Events\Synchronizer::do (
          $eventBase,
          $dnsClient->resolve ('x.microsoft.com', DNS\Message::TYPE_A)
        );

        $this->assertTrue (
          false,
          'An exception was received'
        );
      } catch (\Throwable $dnsError) {
        // No-Op
      }
      
      $this->assertIsObject (
        $dnsEvent,
        'DNS-Result-Event was received'
      );

      $this->assertEquals (
        'x.microsoft.com',
        $dnsEvent->getHostname (),
        'Hostname on Event is the same as the queried one'
      );
    }
    
    public function existingRecordsProvider () : array {
      return [
        'google.com:a'      => [ 'google.com', DNS\Message::TYPE_A ],
        'google.com:aaaa'   => [ 'google.com', DNS\Message::TYPE_AAAA ],
        'tiggerswelt.net:a' => [ 'tiggerswelt.net', DNS\Message::TYPE_A ],
      ];
    }
    
    public function missingRecordsProvider () : array {
      return [
        'missing.quarxconnect.de:a' => [ 'missing.quarxconnect.de', DNS\Message::TYPE_A ],
      ];
    }
  }
