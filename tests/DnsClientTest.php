<?php

  declare (strict_types=1);
  
  use quarxConnect\Events;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Client\DNS\Event;
  use quarxConnect\Events\Client\DNS\Exception\Timeout;
  use quarxConnect\Events\Stream\DNS;
  use quarxConnect\Events\Test\TestCase;
  
  class DnsClientTest extends TestCase {
    /**
     * @dataProvider existingRecordsProvider
     **/
    public function testResolverWithExisting (string $dnsDomain, int $dnsType): Promise {
      $dnsClient = new Events\Client\DNS ($this->getEventBase ());
      $dnsEvent = null;

      $dnsClient->addEventListener (
        Event\Result::class,
        function ($theEvent) use (&$dnsEvent): void {
          $dnsEvent = $theEvent;
        }
      );

      return $dnsClient->resolve ($dnsDomain, $dnsType)->then (
        function (DNS\Recordset $dnsRecordSet) use (&$dnsEvent, $dnsDomain, $dnsType): void {
          // Make sure there was something found
          $this->assertGreaterThan (
            0,
            count ($dnsRecordSet)
          );

          // Make sure classes are correct
          static $typeClasses = [
            DNS\Message::TYPE_A    => DNS\Record\A::class,
            DNS\Message::TYPE_AAAA => DNS\Record\AAAA::class,
          ];

          foreach ($dnsRecordSet as $dnsRecord)
            if (isset ($typeClasses [$dnsType]))
              $this->assertInstanceOf ($typeClasses [$dnsType], $dnsRecord);

          // Check the event
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
      );
      
    }
    
    /**
     * @dataProvider missingRecordsProvider
     **/
    public function testResolverWithMissing ($dnsDomain, $dnsType): Promise {
      $dnsClient = new Events\Client\DNS ($this->getEventBase ());
      
      $this->expectException (\Exception::class);
      
      return $dnsClient->resolve ($dnsDomain, $dnsType);
    }
    
    public function testUnreachableResolver (): Promise {
      $dnsClient = new Events\Client\DNS ($this->getEventBase ());
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
      return $dnsClient->resolve ('microsoft.com', DNS\Message::TYPE_A)->then (
        function (DNS\Recordset $dnsRecordSet): void {
          // Make sure there was something found
          $this->assertGreaterThan (
            0,
            count ($dnsRecordSet)
          );
        }
      );
    }

    public function testTimeout (): Promise {
      $dnsClient = new Events\Client\DNS ($this->getEventBase ());
      $dnsEvent = null;

      $dnsClient->addEventListener (
        Event\Timeout::class,
        function ($theEvent) use (&$dnsEvent): void {
          $dnsEvent = $theEvent;
        }
      );
      
      // Insert non-sense nameserver
      $dnsNameservers = [
        [ 'ip' => '1fff::54' ],
        [ 'ip' => '127.255.255.253' ],
      ];
      
      $dnsClient->setNameservers ($dnsNameservers);
      $dnsClient->setTimeout (1.0);
      
      // Try to resolve an existing record
      return $dnsClient->resolve ('x.microsoft.com', DNS\Message::TYPE_A)->then (
        fn () => $this->assertTrue (
          false,
          'An exception was received'
        ),
        function (Throwable $dnsError) use (&$dnsEvent): void {
          $this->assertInstanceOf (
            Timeout::class,
            $dnsError,
            'Exception indicates it was a timeout'
          );

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
      );
    }
    
    public function existingRecordsProvider (): array {
      return [
        'google.com:a'      => [ 'google.com', DNS\Message::TYPE_A ],
        'google.com:aaaa'   => [ 'google.com', DNS\Message::TYPE_AAAA ],
        'tiggerswelt.net:a' => [ 'tiggerswelt.net', DNS\Message::TYPE_A ],
      ];
    }
    
    public function missingRecordsProvider (): array {
      return [
        'missing.quarxconnect.de:a' => [ 'missing.quarxconnect.de', DNS\Message::TYPE_A ],
      ];
    }
  }
