<?php

  /**
   * quarxConnect Events - Limited Socket Factory
   * Copyright (C) 2020-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  use \quarxConnect\Events;
  use \quarxConnect\Events\ABI;
  
  class Limited extends Events\Hookable implements ABI\Socket\Factory {
    /* Instance of socket-factory we should add limits to */
    private $socketFactory = null;
    
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
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return Events\Base
     **/
    public function getEventBase () : ?Events\Base {
      return $this->socketFactory->getEventBase ();
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Set the Event-Base of this source
     * 
     * @param Events\Base $eventBase
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (\quarxConnect\Events\Base $eventBase) : void {
      $this->socketFactory->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void
     **/
    public function unsetEventBase () : void {
      $this->socketFactory->unsetEventBase ();
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
    public function setMaxConnections (int $maxConnections) : void {
      if ($maxConnections < 1)
        throw new \RangeException ('Connection-Limit must not be lower than one');
      
      $this->maxConnections = $maxConnections;
    }
    // }}}
    
    // {{{ getSession
    /**
     * Create a new session on this pool
     * 
     * @access public
     * @return ABI\Socket\Factory
     **/
    public function getSession () : ABI\Socket\Factory {
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
    public function removeSession (ABI\Socket\Factory $factorySession) : void {
      $this->checkPendingConnections ();
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
     * @return Events\Promise
     **/
    public function createConnection ($remoteHost, int $remotePort, int $socketType, bool $useTLS = false, bool $allowReuse = false, ABI\Socket\Factory $factorySession = null) : Events\Promise {
      $requestPromise = new Events\Promise\Defered ();
      
      $this->pendingConnections [] = [ $requestPromise, $remoteHost, $remotePort, $socketType, $useTLS, $allowReuse, $factorySession ];
      
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
    public function releaseConnection (ABI\Stream $leasedConnection) : void {
      // Make sure we know this connection
      $connectionIndex = null;
      
      foreach ($this->activeConnections as $activeIndex=>$activeConnection)
        if ($activeConnection [0] === $leasedConnection) {
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
      $this->checkPendingConnections ($connectionMeta [6], $connectionMeta);
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
    private function checkPendingConnections (ABI\Socket\Factory $factorySession = null, array $connectionMeta = null) : void {
      while (
        (count ($this->pendingConnections) > 0) &&
        (count ($this->activeConnections) < $this->maxConnections)
      ) {
        // Find best next connection-request
        $nextRequest = null;
        
        if (
          $connectionMeta &&
          $connectionMeta [5] &&
          is_callable ([ $connectionMeta [0], 'isConnected' ]) &&
          $connectionMeta [0]->isConnected ()
        ) {
          foreach ($this->pendingConnections as $pendingIndex=>$pendingConnection)
            if (
              ($connectionMeta [1] == $pendingConnection [1]) &&
              ($connectionMeta [2] == $pendingConnection [2]) &&
              ($connectionMeta [3] == $pendingConnection [3]) &&
              ($connectionMeta [4] == $pendingConnection [4]) &&
              ($connectionMeta [5] == $pendingConnection [5]) &&
              (!$factorySession || ($pendingConnection [6] === $factorySession))
            ) {
              $nextRequest = $pendingConnection;
              
              unset ($this->pendingConnections [$pendingIndex]);
              break;
            }
          
          $connectionMeta = null;
        }
        
        if ($factorySession && !$nextRequest) {
          foreach ($this->pendingConnections as $pendingIndex=>$pendingConnection)
            if ($pendingConnection [6] === $factorySession) {
              $nextRequest = $pendingConnection;
              
              unset ($this->pendingConnections [$pendingIndex]);
              break;
            }
          
          if (!$nextRequest) {
            $this->getEventBase ()->forceCallback (
              function () {
                $this->checkPendingConnections ();
              }
            );
            
            return;
          }
        }
        
        if (!$nextRequest)
          $nextRequest = array_shift ($this->pendingConnections);
        
        // Move to active (although it's actually pending)
        $this->activeConnections [] = $nextRequest;
        
        // Request the connection at our parent
        $this->socketFactory->createConnection (
          $nextRequest [1],
          $nextRequest [2],
          $nextRequest [3],
          $nextRequest [4],
          $nextRequest [5]
        )->then (
          function (ABI\Stream $activeConnection) use ($nextRequest) {
            // Replace promise with active connection
            if (($requestIndex = array_search ($nextRequest, $this->activeConnections, true)) === false) {
              trigger_error ('Connection-Request not found on active connections - this should never happen', E_USER_WARNING);
              
              return $activeConnection;
            }
            
            // Replace promise with stream on active connections
            $requestPromise = $this->activeConnections [$requestIndex][0];
            $this->activeConnections [$requestIndex][0] = $activeConnection;
            
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
            call_user_func_array ([ $nextRequest [0], 'reject' ], func_get_args ());
            
            // Check for additional pending connections
            $this->checkPendingConnections ();
          }
        );
      }
    }
    // }}}
  }
