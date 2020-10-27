<?PHP

  /**
   * qcEvents - Client-Socket Pool
   * Copyright (C) 2017 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Defered.php');
  require_once ('qcEvents/Socket/Pool/Session.php');
  
  class qcEvents_Socket_Pool extends qcEvents_Hookable {
    /* The event-base to use */
    private $eventBase = null;
    
    /* Socket-Pool */
    private $Sockets = array ();
    
    /* Socket-Status */
    const STATUS_CONNECTING = 0;
    const STATUS_ENABLING = 1;
    const STATUS_AVAILABLE = 2;
    const STATUS_ACQUIRED = 3;
    
    private $socketStatus = array ();
    
    /* Stream-Consumers for sockets */
    private $socketPipes = array ();
    
    /* Map socket-keys to Socket */
    private $socketMaps = array ();
    
    /* Defered Promises when enabling sockets */
    private $socketPromises = array ();
    
    /* Enqueued Socket-Requests */
    private $socketQueue = array ();
    
    /* Sessions on this pool */
    private $socketSessions = array ();
    
    /* Maximum number of open sockets */
    private $maxSockets = 64;
    
    // {{{ __construct
    /**
     * Create a new socket-pool
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase) {
      $this->eventBase = $eventBase;
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
     * @return qcEvents_Socket_Pool_Session
     **/
    public function getSession () : qcEvents_Socket_Pool_Session {
      return ($this->socketSessions [] =new qcEvents_Socket_Pool_Session);
    }
    // }}}
    
    // {{{ removeSession
    /**
     * Remove a session from this pool
     * 
     * @access public
     * @return void
     **/
    public function removeSession (qcEvents_Socket_Pool_Session $poolSession) {
      if (($sessionIndex = array_search ($poolSession, $this->socketSessions, true)) === false)
        return;
      
      unset ($this->socketSessions [$sessionIndex]);
    }
    // }}}
    
    // {{{ acquireSocket
    /**
     * Request a socket from this pool
     * 
     * @param mixed $remoteHost
     * @param int $remotePort
     * @param enum $socketType
     * @param bool $useTLS
     * 
     * @access public
     * @return void
     **/
    public function acquireSocket ($remoteHost, $remotePort, $socketType, $useTLS, qcEvents_Socket_Pool_Session $poolSession = null) : qcEvents_Promise {
      // Sanatize parameters
      $remotePort = (int)$remotePort;
      $socketType = (int)$socketType;
      $useTLS = !!$useTLS;
      
      if (($socketType != qcEvents_Socket::TYPE_TCP) && ($socketType != qcEvents_Socket::TYPE_UDP))
        return qcEvents_Promise::reject ('Invalid socket-type given');
      
      if (($remotePort < 1) || ($remotePort > 0xFFFF))
        return qcEvents_Promise::reject ('Invalid port given');
      
      if (!is_array ($remoteHost))
        $remoteHost = array ($remoteHost);
      
      // Push request to queue
      $deferedPromise = new qcEvents_Defered ($this->eventBase);
      
      $this->socketQueue [] = array ($remoteHost, $remotePort, $socketType, $useTLS, $deferedPromise);
      
      // Try to run the queue
      $this->checkSocketQueue ();
      
      // Return the promise
      return $deferedPromise->getPromise ();
    }
    // }}}
    
    // {{{ enableSocket
    /**
     * Mark a socket as enabled/connection established
     * 
     * @param qcEvents_Socket $Socket
     * @param qcEvents_Interface_Stream_Consumer $Pipe (optional)
     * 
     * @access public
     * @return void
     **/
    public function enableSocket (qcEvents_Socket $Socket, qcEvents_Interface_Stream_Consumer $Pipe = null) {
      // Try to find the socket on pool
      if (($Index = array_search ($Socket, $this->Sockets, true)) === false) {
        trigger_error ('Trying to enable unknown socket');
        
        return false;
      }
      
      // Check the status
      if ($this->socketStatus [$Index] != self::STATUS_ENABLING) {
        trigger_error ('Cannot enable socket on this status');
        
        return false;
      }
      
      // Enable the socket
      $this->socketPipes [$Index] = $Pipe;
      
      if (isset ($this->socketPromises [$Index])) {
        $this->socketStatus [$Index] = self::STATUS_ACQUIRED;
        $this->socketPromises [$Index]->resolve ($Socket, $Pipe);
        
        unset ($this->socketPromises [$Index]);
      } else
        $this->socketStatus [$Index] = self::STATUS_AVAILABLE;
    }
    // }}}
    
    // {{{ releaseSocket
    /**
     * Remove a socket from this pool
     * 
     * @param qcEvents_Socket $Socket
     * 
     * @access public
     * @return void
     **/
    public function releaseSocket (qcEvents_Socket $Socket) {
      // Try to find the socket on pool
      if (($Index = array_search ($Socket, $this->Sockets, true)) === false) {
        trigger_error ('Trying to release unknown socket');
        
        return false;
      }
      
      return $this->releaseSocketByIndex ($Index);
    }
    // }}}
    
    // {{{ releaseSocketByIndex
    /**
     * Remove a socket by index from this pool
     * 
     * @param int $Index
     * 
     * @access private
     * @return void
     **/
    private function releaseSocketByIndex ($Index) {
      // Check wheter to run a failed-callback
      if (($this->socketStatus [$Index] == self::STATUS_ENABLING) && isset ($this->socketPromises [$Index]))
        $this->socketPromises [$Index]->reject ('Socket was released');
      
      // Call close
      if ($this->socketPipes [$Index])
        $this->socketPipes [$Index]->close ();
      else
        $this->Sockets [$Index]->close ();
      
      // Cleanup
      unset ($this->Sockets [$Index], $this->socketStatus [$Index], $this->socketPipes [$Index], $this->socketPromises [$Index]);
      
      foreach ($this->socketMaps as $Key=>$Map)
        if (isset ($Map [$Index])) {
          unset ($this->socketMaps [$Key][$Index]);
          
          if (count ($this->socketMaps [$Key]) == 0)
            unset ($this->socketMaps [$Key]);
          
          break;
        }
      
      // Check if we could connect queued sockets
      $this->checkSocketQueue ();
    }
    // }}}
    
    // {{{ checkSocketQueue
    /**
     * Check if we can connect sockets from our queue
     * 
     * @access private
     * @return void
     **/
    private function checkSocketQueue () {
      // Check for available sockets
      if (count ($this->socketMaps) > 0)
        foreach ($this->socketQueue as $queueIndex=>$queueInfo) {
          // Unpack the info
          $remoteHost = $queueInfo [0];
          $remotePort = $queueInfo [1];
          $socketType = $queueInfo [2];
          $useTLS = $queueInfo [3];
          $deferedPromise = $queueInfo [4];
          
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
            $deferedPromise->resolve ($this->Sockets [$socketIndex], $this->socketPipes [$socketIndex]);
            
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
      while (($activeSockets < $this->maxSockets) && ($requiredSockets > 0)) {
        // Unpack the info
        $queueInfo = array_shift ($this->socketQueue);
        $requiredSockets--;
        $remoteHost = $queueInfo [0];
        $remotePort = $queueInfo [1];
        $socketType = $queueInfo [2];
        $useTLS = $queueInfo [3];
        $deferedPromise = $queueInfo [4];
        
        // Create a key for the requested socket
        $socketKey = strtolower (implode ('-', $remoteHost)) . '-' . $remotePort . '-' . $socketType . ($useTLS ? '-tls' : '');
        
        // Create a new socket
        $this->Sockets [] = $newSocket = new qcEvents_Socket ($this->eventBase);
        
        // Get index of new socket
        if (($socketIndex = array_search ($newSocket, $this->Sockets, true)) === false) {
          $deferedPromise->reject ('Just lost a socket... Strange!');
          
          continue;
        }
        
        // Setup sockets status
        if (isset ($this->socketMaps [$socketKey]))
          $this->socketMaps [$socketKey][$socketIndex] = $newSocket;
        else
          $this->socketMaps [$socketKey] = array ($socketIndex => $newSocket);
        
        $this->socketStatus [$socketIndex] = self::STATUS_CONNECTING;
        $this->socketPipes [$socketIndex] = null;
        
        $activeSockets++;
        
        // Try to connect
        $newSocket->connect ($remoteHost, $remotePort, $socketType, $useTLS)->then (
          function () use ($newSocket, $socketKey, $socketIndex, $deferedPromise) {
            // Check wheter to further setup the socket
            if (count ($this->getHooks ('socketConnected')) == 0) {
              // Mark the socket as aquired
              $this->socketStatus [$socketIndex] = self::STATUS_ACQUIRED;
              
              // Push socket to promise
              $deferedPromise->resolve ($newSocket, null);
              
              return;
            }
            
            // Try to enable the socket
            $this->socketStatus [$socketIndex] = self::STATUS_ENABLING;
            $this->socketPromises [$socketIndex] = $deferedPromise;
            
            // Fire the callback
            $this->___callback ('socketConnected', $newSocket);
          },
          function () use ($newSocket, $socketKey, $socketIndex, $deferedPromise) {
            // Quickly de-register the socket
            unset (
              $this->Sockets [$socketIndex],
              $this->socketStatus [$socketIndex],
              $this->socketPipes [$socketIndex],
              $this->socketPromises [$socketIndex],
              $this->socketMaps [$socketKey][$socketIndex]
            );
            
            if (count ($this->socketMaps [$socketKey]) > 0) {
              // Check if there is another socket available for usage
              foreach ($this->socketMaps [$socketKey] as $socketIndex) {
                if ($this->socketStatus [$socketIndex] != self::STATUS_AVAILABLE)
                  continue;
                
                $this->socketStatus [$socketIndex] = self::STATUS_ACQUIRED;
                
                $deferedPromise->resolve ($this->Sockets [$socketIndex], $this->socketPipes [$socketIndex]);
                $deferedPromise = null;
              }
            } else
              unset ($this->socketMaps [$socketKey]);
            
            if ($deferedPromise)
              call_user_func_array (array ($deferedPromise, 'reject'), func_get_args ());
            
            // Check if we could connect queued sockets
            $this->checkSocketQueue ();
          }
        );
      }
    }
    // }}}
    
    
    // {{{ socketConnected
    /**
     * Callback: A new socket on the pool has connected to its destination
     * 
     * @param qcEvents_Socket $Socket
     * 
     * @access protected
     * @return void
     **/
    protected function socketConnected (qcEvents_Socket $Socket) { }
    // }}}
  }

?>