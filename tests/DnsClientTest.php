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
