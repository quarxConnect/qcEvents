<?php

  /**
   * quarxConnect Events - Limited Socket Factory
   * Copyright (C) 2020-2023 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Socket\Factory;

  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Base;
  use quarxConnect\Events\Emitter;
  use quarxConnect\Events\Promise;

  class Limited extends Emitter implements ABI\Socket\Factory {
    /* Instance of socket-factory we should add limits to */
    private ABI\Socket\Factory $socketFactory;
    
    /* Collection of pending connection-requests */
    private $pendingConnections = [ ];
    
    /* Collection of active connections */
    private $activeConnections = [ ];
    
    /* Callback for connection-release */
    private $releaseCallback = null;
    
    /* Maximum number of parallel connection-leases */
    private $maxConnections = 64;
    
    // {{{ __construct
    /**
     * Create a new limited Socket-Factory
     * 
     * @param ABI\Socket\Factory $socketFactory
     * 
     * @access friendly
     * @return void
     **/
    function __construct (ABI\Socket\Factory $socketFactory) {
      $this->socketFactory = $socketFactory;
      
      $this->releaseCallback = function (ABI\Stream $releasedSocket) {
        $this->releaseConnection ($releasedSocket); 
      };
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrieve the instance of our event-base
     * 
     * @access public
     * @return Base
     **/
    public function getEventBase (): ?Base {
      return $this->socketFactory->getEventBase ();
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Set the event-base of this source
     * 
     * @param Base $eventBase
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (Base $eventBase): void {
      $this->socketFactory->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove the assigned event-base
     * 
     * @access public
     * @return void
     **/
    public function unsetEventBase (): void {
      $this->socketFactory->unsetEventBase ();
    }
    // }}}

    // {{{ getSocketFactory
    /**
     * Retrieve instance of our parent socket-factory
     *
     * @access public
     * @return ABI\Socket\Factory
     **/
    public function getSocketFactory (): ABI\Socket\Factory {
      return $this->socketFactory;
    }
    // }}}
    
    // {{{ getSession
    /**
     * Create a new session on this pool
     * 
     * @access public
     * @return ABI\Socket\Factory
     **/
    public function getSession (): ABI\Socket\Factory {
      return (new Session ($this));
    }
    // }}}
    
    // {{{ removeSession
    /**
     * Remove a session from this factory
     * 
     * @param ABI\Socket\Factory $factorySession
     * 
     * @access public
     * @return void
     **/
    public function removeSession (ABI\Socket\Factory $factorySession): void {
      $this->checkPendingConnections ();
    }
    // }}}

    // {{{ setMaxConnections
    /**
     * Set the maximum of parallel leased connections
     * 
     * @param int $maxConnections
     * 
     * @access public
     * @return void
     **/
    public function setMaxConnections (int $maxConnections): void {
      if ($maxConnections < 1)
        throw new \RangeException ('Connection-Limit must not be lower than one');
      
      $this->maxConnections = $maxConnections;
    }
    // }}}
    
    // {{{ pendingConnections
    /**
     * Retrieve number of pending connections
     * 
     * @access public
     * @return int
     **/
    public function pendingConnections (): int {
      return count ($this->pendingConnections);
    }
    // }}}

    // {{{ activeConnections
    /**
     * Retrieve number of active connections
     * 
     * @access public
     * @return int
     **/
    public function activeConnections (): int {
      return count ($this->activeConnections);
    }
    // }}}

    // {{{ totalConnections
    /**
     * Retrieve number of total connections here
     * 
     * @access public
     * @return int
     **/
    public function totalConnections (): int {
      return count ($this->pendingConnections) + count ($this->activeConnections);
    }
    // }}}
    
    // {{{ createConnection
    /**
     * Request a connected socket from this factory
     * 
     * @param array|string $remoteHost
     * @param int $remotePort
     * @param int $socketType
     * @param bool $useTLS (optional)
     * @param bool $allowReuse (optional)
     * @param ABI\Socket\Factory $factorySession (optional)
     * 
     * @access public
     * @return Promise
     **/
    public function createConnection (
      array|string $remoteHost,
      int $remotePort,
      int $socketType,
      bool $useTLS = false,
      bool $allowReuse = false,
      ABI\Socket\Factory $factorySession = null
    ): Promise {
      $requestPromise = new Promise\Deferred ();
      
      $this->pendingConnections [] = [
        'promise' => $requestPromise,
        'host' => $remoteHost,
        'port' => $remotePort,
        'type' => $socketType,
        'tls' => $useTLS,
        'reuse' => $allowReuse,
        'session' => $factorySession,
      ];
      
      $this->checkPendingConnections ($factorySession);
      
      return $requestPromise->getPromise ();
    }
    // }}}
    
    // {{{ releaseConnection
    /**
     * Return a connected socket back to the factory
     * 
     * @param ABI\Stream $leasedConnection
     * 
     * @access public
     * @return void
     **/
    public function releaseConnection (ABI\Stream $leasedConnection): void {
      // Make sure we know this connection
      $connectionIndex = null;
      
      foreach ($this->activeConnections as $activeIndex=>$activeConnection)
        if ($activeConnection ['connection'] === $leasedConnection) {
          $connectionIndex = $activeIndex;
          
          break;
        }
      
      if ($connectionIndex === null)
        return;
      
      $connectionMeta = $this->activeConnections [$connectionIndex];
      
      // Remove the connection from our connection-list
      unset ($this->activeConnections [$connectionIndex]);
      
      // Invoke our parent
      $this->socketFactory->releaseConnection ($leasedConnection);
      
      // Check for pending connections
      $this->checkPendingConnections ($connectionMeta ['session'], $connectionMeta);
    }
    // }}}
    
    // {{{ checkPendingConnections
    /**
     * Try to process pending connection-requests
     * 
     * @param ABI\Socket\Factory $factorySession (optional)
     * @param array $connectionMeta (optional) Meta of last released connection
     * 
     * @access private
     * @return void
     **/
    private function checkPendingConnections (ABI\Socket\Factory $factorySession = null, array $connectionMeta = null): void {
      while (
        (count ($this->pendingConnections) > 0) &&
        (count ($this->activeConnections) < $this->maxConnections)
      ) {
        // Check if there is a pending connection matching a released connection
        $nextRequest = null;
        
        if (
          $connectionMeta &&
          $connectionMeta ['reuse'] &&
          is_callable ([ $connectionMeta ['connection'], 'isConnected' ]) &&
          $connectionMeta ['connection']->isConnected ()
        ) {
          foreach ($this->pendingConnections as $pendingIndex=>$pendingConnection)
            if (
              ($connectionMeta ['host'] == $pendingConnection ['host']) &&
              ($connectionMeta ['port'] == $pendingConnection ['port']) &&
              ($connectionMeta ['type'] == $pendingConnection ['type']) &&
              ($connectionMeta ['tls'] == $pendingConnection ['tls']) &&
              ($connectionMeta ['reuse'] == $pendingConnection ['reuse']) &&
              (!$factorySession || ($pendingConnection ['session'] === $factorySession))
            ) {
              $nextRequest = $pendingConnection;
              
              unset ($this->pendingConnections [$pendingIndex]);
              break;
            }
          
          $connectionMeta = null;
        }
        
        if ($factorySession && !$nextRequest) {
          // Check if there is a pending request for current session
          foreach ($this->pendingConnections as $pendingIndex=>$pendingConnection)
            if ($pendingConnection ['session'] === $factorySession) {
              $nextRequest = $pendingConnection;
              
              unset ($this->pendingConnections [$pendingIndex]);
              break;
            }
          
          // Try again without session on next loop
          if (!$nextRequest) {
            $this->getEventBase ()->forceCallback (
              function () {
                $this->checkPendingConnections ();
              }
            );
            
            return;
          }
        }
        
        // Just pick next request from pending connections
        if (!$nextRequest)
          $nextRequest = array_shift ($this->pendingConnections);
        
        // Move to active (although it's actually pending)
        $this->activeConnections [] = $nextRequest;
        
        // Request the connection at our parent
        $this->socketFactory->createConnection (
          $nextRequest ['host'],
          $nextRequest ['port'],
          $nextRequest ['type'],
          $nextRequest ['tls'],
          $nextRequest ['reuse']
        )->then (
          function (ABI\Stream $activeConnection) use ($nextRequest) {
            // Find request on active connections
            if (($requestIndex = array_search ($nextRequest, $this->activeConnections, true)) === false) {
              trigger_error ('Connection-Request not found on active connections - this should never happen', E_USER_WARNING);
              
              return $activeConnection;
            }
            
            // Replace promise with stream on active connections
            $requestPromise = $this->activeConnections [$requestIndex]['promise'];

            $this->activeConnections [$requestIndex]['connection'] = $activeConnection;

            unset ($this->activeConnections [$requestIndex]['promise']);
            
            // Resolve the promise
            $requestPromise->resolve ($activeConnection);
            
            // Check for additional pending connections
            $this->checkPendingConnections ();
          },
          function () use ($nextRequest) {
            // Remove from active connections
            if (($requestIndex = array_search ($nextRequest, $this->activeConnections, true)) !== false)
              unset ($this->activeConnections [$requestIndex]);
            else
              trigger_error ('Connection-Request not found on active connections - this should never happen', E_USER_WARNING);
            
            // Forward the rejection
            call_user_func_array ([ $nextRequest ['promise'], 'reject' ], func_get_args ());
            
            // Check for additional pending connections
            $this->checkPendingConnections ();
          }
        );
      }
    }
    // }}}
  }
