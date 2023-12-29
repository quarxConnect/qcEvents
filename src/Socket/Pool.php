<?php

  /**
   * quarxConnect Events - Client-Socket Pool
   * Copyright (C) 2017-2021 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Socket;

  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Base;
  use quarxConnect\Events\Emitter;
  use quarxConnect\Events\Feature;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Socket;
  use quarxConnect\Events\Socket\Event\Connected as ConnectedEvent;
  use quarxConnect\Events\Socket\Exception\InvalidPort;
  use quarxConnect\Events\Socket\Exception\InvalidType;
  
  class Pool extends Emitter implements ABI\Socket\Factory {
    use Feature\Based;
    
    /* Socket-Pool */
    private $Sockets = [ ];
    
    /* Socket-Status */
    private const STATUS_CONNECTING = 0;
    private const STATUS_ENABLING = 1;
    private const STATUS_AVAILABLE = 2;
    private const STATUS_ACQUIRED = 3;
    
    private $socketStatus = [ ];
    
    /* Stream-Consumers for sockets */
    private $socketPipes = [ ];
    
    /* Map socket-keys to Socket */
    private $socketMaps = [ ];
    
    /* Deferred Promises when enabling sockets */
    private $socketPromises = [ ];
    
    /* Enqueued Socket-Requests */
    private $socketQueue = [ ];
    
    /* Sessions of sockets */
    private $socketSessions = [ ];
    
    /* Sessions on this pool */
    private $activeSessions = [ ];
    
    /* Maximum number of open sockets */
    private $maxSockets = 64;
    
    // {{{ __construct
    /**
     * Create a new socket-pool
     * 
     * @param Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Base $eventBase) {
      $this->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Prepare output for var_dump()
     * 
     * @access friendly
     * @return array
     **/
    function __debugInfo () : array {
      return [
        'sockets' => count ($this->Sockets),
        'sessions' => count ($this->activeSessions),
      ];
    }
    // }}}
    
    // {{{ setMaximumSockets
    /**
     * Set the maximum amounts of open sockets on the pool
     * 
     * @param int $Number
     * 
     * @access public
     * @return bool
     **/
    public function setMaximumSockets ($Number) {
      $this->maxSockets = max (1, (int)$Number);
      
      return true;
    }
    // }}}
    
    // {{{ getSession
    /**
     * Create a new session on this pool
     * 
     * @access public
     * @return Pool\Session
     **/
    public function getSession () : Pool\Session {
      return ($this->activeSessions [] = new Pool\Session ($this));
    }
    // }}}
    
    // {{{ removeSession
    /**
     * Remove a session from this pool
     * 
     * @param Pool\Session $poolSession
     * 
     * @access public
     * @return void
     **/
    public function removeSession (Pool\Session $poolSession) {
      if (($sessionIndex = array_search ($poolSession, $this->activeSessions, true)) === false)
        unset ($this->activeSessions [$sessionIndex]);
      
      $this->checkSocketQueue ();
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
     * @param Pool\Session $poolSession (optional)
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
      Pool\Session $poolSession = null
    ): Promise {
      // Sanitize socket-type
      if (
        ($socketType != Socket::TYPE_TCP) &&
        ($socketType != Socket::TYPE_UDP)
      )
        return Promise::reject (new InvalidType ());
      
      // Sanitize the port
      if (
        ($remotePort < 1) ||
        ($remotePort > 0xffff)
      )
        return Promise::reject (new InvalidPort ());
      
      // Make sure remote host is an array
      if (!is_array ($remoteHost))
        $remoteHost = [ $remoteHost ];
      
      // Push request to queue
      $deferredPromise = new Promise\Deferred ($this->getEventBase ());
      
      $this->socketQueue [] = [ $remoteHost, $remotePort, $socketType, $useTLS, $allowReuse, $poolSession, $deferredPromise ];
      
      // Try to run the queue
      $this->checkSocketQueue ($poolSession);
      
      // Return the promise
      return $deferredPromise->getPromise ();
    }
    // }}}
    
    // {{{ enableSocket
    /**
     * Mark a socket as enabled/connection established
     * 
     * @param Socket $Socket
     * @param ABI\Stream\Consumer $Pipe (optional)
     * 
     * @access public
     * @return void
     **/
    public function enableSocket (Socket $Socket, ABI\Stream\Consumer $Pipe = null): void {
      // Try to find the socket on pool
      $socketIndex = array_search ($Socket, $this->Sockets, true);

      if ($socketIndex === false)
        return;
      
      // Check the status
      if ($this->socketStatus [$socketIndex] != self::STATUS_ENABLING)
        throw new Error ('Cannot enable socket on this status');
      
      // Enable the socket
      $this->socketPipes [$socketIndex] = $Pipe;
      
      if (isset ($this->socketPromises [$socketIndex])) {
        $this->socketStatus [$socketIndex] = self::STATUS_ACQUIRED;
        $this->socketPromises [$socketIndex]->resolve ($Socket, $Pipe);
        
        unset ($this->socketPromises [$socketIndex]);
      } else {
        $this->socketStatus [$socketIndex] = self::STATUS_AVAILABLE;
        $this->checkSocketQueue ($this->socketSessions [$socketIndex]);
      }
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
      // Try to find the socket on pool
      if (($socketIndex = array_search ($leasedConnection, $this->Sockets, true)) === false)
        return;
      
      $this->releaseSocketByIndex ($socketIndex);
    }
    // }}}
    
    // {{{ releaseSocketByIndex
    /**
     * Remove a socket by index from this pool
     * 
     * @param int $socketIndex
     * 
     * @access private
     * @return void
     **/
    private function releaseSocketByIndex ($socketIndex) {
      // Check if the socket is known
      if (!isset ($this->Sockets [$socketIndex]))
        return;
      
      // Get the socket from pool
      $releasedSocket = $this->Sockets [$socketIndex];
      unset ($this->Sockets [$socketIndex]);
      
      // Check whether to run a failed-callback
      if (
        ($this->socketStatus [$socketIndex] == self::STATUS_ENABLING) &&
        isset ($this->socketPromises [$socketIndex])
      )
        $this->socketPromises [$socketIndex]->reject ('Socket was released');
      
      // Call close
      if ($this->socketPipes [$socketIndex])
        $this->socketPipes [$socketIndex]->close ();
      else
        $releasedSocket->close ();
      
      $socketSession = $this->socketSessions [$socketIndex];
      
      // Cleanup
      unset (
        $this->socketStatus [$socketIndex],
        $this->socketPipes [$socketIndex],
        $this->socketPromises [$socketIndex],
        $this->socketSessions [$socketIndex]
      );
      
      foreach ($this->socketMaps as $Key=>$Map)
        if (isset ($Map [$socketIndex])) {
          unset ($this->socketMaps [$Key][$socketIndex]);
          
          if (count ($this->socketMaps [$Key]) == 0)
            unset ($this->socketMaps [$Key]);
          
          break;
        }
      
      // Check if we could connect queued sockets
      $this->checkSocketQueue ($socketSession);
    }
    // }}}
    
    // {{{ checkSocketQueue
    /**
     * Check if we can connect sockets from our queue
     * 
     * @param Pool\Session $forSession (optional)
     * 
     * @access private
     * @return void
     **/
    private function checkSocketQueue (Pool\Session $forSession = null) {
      // Check for available sockets
      if (count ($this->socketMaps) > 0)
        foreach ($this->socketQueue as $queueIndex=>$queueInfo) {
          // Unpack the info
          $remoteHost = $queueInfo [0];
          $remotePort = $queueInfo [1];
          $socketType = $queueInfo [2];
          $useTLS = $queueInfo [3];
          $allowReuse = $queueInfo [4];
          $poolSession = $queueInfo [5];
          $deferredPromise = $queueInfo [6];
          
          if ($forSession && ($forSession !== $poolSession))
            continue;
          
          // Create a key for the requested socket
          $socketKey = strtolower (implode ('-', $remoteHost)) . '-' . $remotePort . '-' . $socketType . ($useTLS ? '-tls' : '');
          
          // Check if we have a socket available
          if (!isset ($this->socketMaps [$socketKey]))
            continue;
          
          foreach (array_keys ($this->socketMaps [$socketKey]) as $socketIndex) {
            if ($this->socketStatus [$socketIndex] != self::STATUS_AVAILABLE)
              continue;
            
            // Mark the socket as acquired
            $this->socketStatus [$socketIndex] = self::STATUS_ACQUIRED;
            
            // Remove from queue
            unset ($this->socketQueue [$queueIndex]);
            
            // Push the socket
            $deferredPromise->resolve ($this->Sockets [$socketIndex], $this->socketPipes [$socketIndex]);
            
            // Move to next queued socket
            continue (2);
          }
        }
      
      // Check if we should release some sockets
      $activeSockets = count ($this->Sockets);
      $requiredSockets = count ($this->socketQueue);
      
      if ($activeSockets + $requiredSockets > $this->maxSockets)
        foreach (array_keys ($this->socketStatus, self::STATUS_AVAILABLE) as $socketIndex) {
          // Release the socket
          $this->releaseSocketByIndex ($socketIndex);
          
          $activeSockets--;
          
          // Check if we may proceed already
          if ($activeSockets + $requiredSockets <= $this->maxSockets)
            break;
        }
      
      // Make sure we may do anything
      if ($activeSockets >= $this->maxSockets)
        return;
      
      // Process the queue again
      foreach ($this->socketQueue as $queueIndex=>$queueInfo) {
        // Unpack the info
        $remoteHost = $queueInfo [0];
        $remotePort = $queueInfo [1];
        $socketType = $queueInfo [2];
        $useTLS = $queueInfo [3];
        $allowReuse = $queueInfo [4];
        $poolSession = $queueInfo [5];
        $deferredPromise = $queueInfo [6];
        
        if ($forSession && ($forSession !== $poolSession))
          continue;
        
        unset ($this->socketQueue [$queueIndex]);
        
        // Create a key for the requested socket
        $socketKey = strtolower (implode ('-', $remoteHost)) . '-' . $remotePort . '-' . $socketType . ($useTLS ? '-tls' : '');
        
        // Request event-base
        if (!is_object ($eventBase = $this->getEventBase ())) {
          $deferredPromise->reject ('No event-base assigned');
          
          continue;
        }
        
        // Create a new socket
        $this->Sockets [] = $newSocket = new Socket ($eventBase);
        
        // Get index of new socket
        if (($socketIndex = array_search ($newSocket, $this->Sockets, true)) === false) {
          $deferredPromise->reject ('Just lost a socket... Strange!');
          
          continue;
        }
        
        // Setup sockets status
        if (isset ($this->socketMaps [$socketKey]))
          $this->socketMaps [$socketKey][$socketIndex] = $newSocket;
        else
          $this->socketMaps [$socketKey] = [ $socketIndex => $newSocket ];
        
        $this->socketStatus [$socketIndex] = self::STATUS_CONNECTING;
        $this->socketPipes [$socketIndex] = null;
        $this->socketSessions [$socketIndex] = $poolSession;
        
        $activeSockets++;
        
        // Try to connect
        $newSocket->connect ($remoteHost, $remotePort, $socketType, $useTLS)->then (
          function () use ($newSocket, $socketKey, $socketIndex, $deferredPromise) {
            // Check whether to further setup the socket
            if (count ($this->getHooks ('socketConnected')) == 0) {
              // Mark the socket as acquired
              $this->socketStatus [$socketIndex] = self::STATUS_ACQUIRED;
              
              // Push socket to promise
              $deferredPromise->resolve ($newSocket, null);
              
              return;
            }
            
            // Try to enable the socket
            $this->socketStatus [$socketIndex] = self::STATUS_ENABLING;
            $this->socketPromises [$socketIndex] = $deferredPromise;
            
            // Dispatch event
            $this->dispatch (
              new ConnectedEvent ($newSocket)
            );
          },
          function () use ($newSocket, $socketKey, $socketIndex, $deferredPromise, $poolSession) {
            // Quickly de-register the socket
            unset (
              $this->Sockets [$socketIndex],
              $this->socketStatus [$socketIndex],
              $this->socketPipes [$socketIndex],
              $this->socketPromises [$socketIndex],
              $this->socketMaps [$socketKey][$socketIndex],
              $this->socketSessions [$socketIndex]
            );
            
            if (count ($this->socketMaps [$socketKey]) > 0) {
              // Check if there is another socket available for usage
              foreach (array_keys ($this->socketMaps [$socketKey]) as $socketIndex) {
                if ($this->socketStatus [$socketIndex] != self::STATUS_AVAILABLE)
                  continue;
                
                $this->socketStatus [$socketIndex] = self::STATUS_ACQUIRED;
                
                $deferredPromise->resolve ($this->Sockets [$socketIndex], $this->socketPipes [$socketIndex]);
                $deferredPromise = null;
              }
            } else
              unset ($this->socketMaps [$socketKey]);
            
            if ($defreredPromise)
              call_user_func_array ([ $deferredPromise, 'reject' ], func_get_args ());
            
            // Check if we could connect queued sockets
            $this->checkSocketQueue ($poolSession);
          }
        );
        
        $newSocket->once ('eventClosed')->then (
          function () use ($socketIndex) {
            $this->releaseSocketByIndex ($socketIndex);
          }
        );
        
        if ($activeSockets >= $this->maxSockets)
          break;
      }
      
      if ($forSession && ($activeSockets < $this->maxSockets))
        $this->getEventBase ()->forceCallback (
          function () {
            $this->checkSocketQueue ();
          }
        );
    }
    // }}}
  }
