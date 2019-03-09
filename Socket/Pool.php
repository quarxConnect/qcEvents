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
    
    // {{{ acquireSocket
    /**
     * Request a socket from this pool
     * 
     * @param mixed $Host
     * @param int $Port
     * @param enum $Type
     * @param bool $TLS
     * 
     * @access public
     * @return void
     **/
    public function acquireSocket ($Host, $Port, $Type, $TLS, callable $Callback = null, $Private = null) : qcEvents_Promise {
      // Sanatize parameters
      $Port = (int)$Port;
      $Type = (int)$Type;
      $TLS = !!$TLS;
      
      if (($Type != qcEvents_Socket::TYPE_TCP) && ($Type != qcEvents_Socket::TYPE_UDP))
        return qcEvents_Promise::reject ('Invalid socket-type given');
      
      if (($Port < 1) || ($Port > 0xFFFF))
        return qcEvents_Promise::reject ('Invalid port given');
      
      // Create a key for the requested socket
      $Key = strtolower (is_array ($Host) ? implode ('-', $Host) : $Host) . '-' . $Port . '-' . $Type . ($TLS ? '-tls' : '');
      
      // Check if we have a socket available
      if (isset ($this->SocketMaps [$Key]))
        foreach ($this->SocketMaps [$Key] as $Index)
          if ($this->SocketStatus [$Index] == self::STATUS_AVAILABLE) {
            $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
            
            return qcEvents_Promise::resolve ($this->Sockets [$Index], $this->SocketPipes [$Index]);
          }
      
      // Create new socket
      $Socket = new qcEvents_Socket ($this->eventBase);
      
      // Register the socket
      $this->Sockets [] = $Socket;
      
      if (($Index = array_search ($Socket, $this->Sockets, true)) === false)
        return qcEvents_Promise::reject ('Lost socket... Strange!');
      
      $this->SocketStatus [$Index] = self::STATUS_CONNECTING;
      $this->SocketPipes [$Index] = null;
      
      if (isset ($this->SocketMaps [$Key]))
        $this->SocketMaps [$Key][$Index] = $Index;
      else
        $this->SocketMaps [$Key] = array ($Index => $Index);
      
      // Try to connect
      return $Socket->connect ($Host, $Port, $Type, $TLS)->then (
        function () use ($Socket, $Key, $Index, $Callback, $Private) {
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
        function () use ($Key, $Index) {
          // Quickly de-register the socket
          unset ($this->Sockets [$Index], $this->SocketStatus [$Index], $this->SocketPipes [$Index], $this->SocketCallbacks [$Index], $this->SocketMaps [$Key][$Index]);
          
          if (count ($this->SocketMaps [$Key]) > 0) {
            // Check if there is another socket available for usage
            foreach ($this->SocketMaps [$Key] as $Index)
              if ($this->SocketStatus [$Index] == self::STATUS_AVAILABLE) {
                $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
                
                return new qcEvents_Promise_Solution (array ($this->Sockets [$Index], $this->SocketPipes [$Index]));
              }
          } else
            unset ($this->SocketMaps [$Key]);
          
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
      $this->SocketStatus [$Index] = self::STATUS_AVAILABLE;
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