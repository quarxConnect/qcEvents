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
    
    private $SocketStatus = array ();
    
    /* Stream-Consumers for sockets */
    private $SocketPipes = array ();
    
    /* Map socket-keys to Socket */
    private $SocketMaps = array ();
    
    /* Enqueued Callbacks */
    private $SocketCallbacks = array ();
    
    /* Enqueued Socket-Requests */
    private $SocketQueue = array ();
    
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
      
      // Create a key for the requested socket
      $socketKey = strtolower (implode ('-', $remoteHost)) . '-' . $remotePort . '-' . $socketType . ($useTLS ? '-tls' : '');
      
      // Check if we have a socket available
      if (isset ($this->SocketMaps [$socketKey]))
        foreach ($this->SocketMaps [$socketKey] as $Index)
          if ($this->SocketStatus [$Index] == self::STATUS_AVAILABLE) {
            $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
            
            return qcEvents_Promise::resolve ($this->Sockets [$Index], $this->SocketPipes [$Index]);
          }
      
      // Check if we may create a new socket
      if (count ($this->Sockets) >= $this->maxSockets) {
        // Check if we could kick some sockets
        foreach (array_keys ($this->SocketStatus, self::STATUS_AVAILABLE) as $kKey) {
          // Release the socket
          $this->releaseSocketByIndex ($kKey);
          
          // Check if we may proceed already
          if (count ($this->Sockets) < $this->maxSockets)
            break;
        }
        
        // Check if enough sockets were released
        if (count ($this->Sockets) >= $this->maxSockets)
          return new qcEvents_Promise (
            function ($resolve, $reject) use ($remoteHost, $remotePort, $socketType, $useTLS) {
              $this->SocketQueue [] = array ($remoteHost, $remotePort, $socketType, $useTLS, $resolve, $reject);
            }
          );
      }
      
      // Create new socket
      $Socket = new qcEvents_Socket ($this->eventBase);
      
      // Register the socket
      $this->Sockets [] = $Socket;
      
      if (($Index = array_search ($Socket, $this->Sockets, true)) === false)
        return qcEvents_Promise::reject ('Lost socket... Strange!');
      
      $this->SocketStatus [$Index] = self::STATUS_CONNECTING;
      $this->SocketPipes [$Index] = null;
      
      if (isset ($this->SocketMaps [$socketKey]))
        $this->SocketMaps [$socketKey][$Index] = $Index;
      else
        $this->SocketMaps [$socketKey] = array ($Index => $Index);
      
      // Try to connect
      return $Socket->connect ($remoteHost, $remotePort, $socketType, $useTLS)->then (
        function () use ($Socket, $socketKey, $Index) {
          // Check wheter to further setup the socket
          if (count ($this->getHooks ('socketConnected')) == 0) {
            $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
            
            return $Socket;
          }
          
          // Try to enable the socket
          return new qcEvents_Promise (function ($resolve, $reject) use ($Index, $Socket) {
            $this->SocketStatus [$Index] = self::STATUS_ENABLING;
            $this->SocketCallbacks [$Index] = array ($resolve, $reject);
            
            $this->___callback ('socketConnected', $Socket);
          });
        },
        function () use ($socketKey, $Index) {
          // Quickly de-register the socket
          unset ($this->Sockets [$Index], $this->SocketStatus [$Index], $this->SocketPipes [$Index], $this->SocketCallbacks [$Index], $this->SocketMaps [$socketKey][$Index]);
          
          if (count ($this->SocketMaps [$socketKey]) > 0) {
            // Check if there is another socket available for usage
            foreach ($this->SocketMaps [$socketKey] as $Index)
              if ($this->SocketStatus [$Index] == self::STATUS_AVAILABLE) {
                $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
                
                return new qcEvents_Promise_Solution (array ($this->Sockets [$Index], $this->SocketPipes [$Index]));
              }
          } else
            unset ($this->SocketMaps [$socketKey]);
          
          // Check if we could connect queued sockets
          $this->checkSocketQueue ();
          
          // Forward the error
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
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
      if ($this->SocketStatus [$Index] != self::STATUS_ENABLING) {
        trigger_error ('Cannot enable socket on this status');
        
        return false;
      }
      
      // Enable the socket
      $this->SocketPipes [$Index] = $Pipe;
      
      if (isset ($this->SocketCallbacks [$Index])) {
        $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
        
        call_user_func ($this->SocketCallbacks [$Index][0], $Socket, $Pipe);
        
        unset ($this->SocketCallbacks [$Index]);
      } else
        $this->SocketStatus [$Index] = self::STATUS_AVAILABLE;
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
      if (($this->SocketStatus [$Index] == self::STATUS_ENABLING) && isset ($this->SocketCallbacks [$Index]))
        call_user_func ($this->SocketCallbacks [$Index][1], 'Socket was released');
      
      // Call close
      if ($this->SocketPipes [$Index])
        $this->SocketPipes [$Index]->close ();
      else
        $this->Sockets [$Index]->close ();
      
      // Cleanup
      unset ($this->Sockets [$Index], $this->SocketStatus [$Index], $this->SocketPipes [$Index], $this->SocketCallbacks [$Index]);
      
      foreach ($this->SocketMaps as $Key=>$Map)
        if (isset ($Map [$Index])) {
          unset ($this->SocketMaps [$Key][$Index]);
          
          if (count ($this->SocketMaps [$Key]) == 0)
            unset ($this->SocketMaps [$Key]);
          
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
      foreach ($this->SocketQueue as $Pos=>$Info) {
        $Host = $Info [0];
        $Port = $Info [1];
        $Type = $Info [2];
        $TLS = $Info [3];
        
        $Key = strtolower (is_array ($Host) ? implode ('-', $Host) : $Host) . '-' . $Port . '-' . $Type . ($TLS ? '-tls' : '');
        
        // Check if we have a socket available
        if (isset ($this->SocketMaps [$Key]))
          foreach ($this->SocketMaps [$Key] as $Index)
            if ($this->SocketStatus [$Index] == self::STATUS_AVAILABLE) {
              // Mark the socket as acquired
              $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
              
              // Remove from queue
              unset ($this->SocketQueue [$Pos]);
              
              // Push the socket
              call_user_func ($Info [4], $this->Sockets [$Index], $this->SocketPipes [$Index]);
            }
      }
      
      // Connect sockets as long as possible
      while ((count ($this->Sockets) < $this->maxSockets) && (count ($this->SocketQueue) > 0)) {
        // Get the next request from queue
        $Info = array_shift ($this->SocketQueue);
        
        // Re-Request the socket
        $this->acquireSocket ($Info [0], $Info [1], $Info [2], $Info [3])->then ($Info [4], $Info [5]);
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