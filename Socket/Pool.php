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
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Socket_Pool $Self, qcEvents_Socket $Socket = null, qcEvents_Interface_Stream_Consumer $Pipe = null, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function acquireSocket ($Host, $Port, $Type, $TLS, callable $Callback = null, $Private = null) {
      // Sanatize parameters
      $Port = (int)$Port;
      $Type = (int)$Type;
      $TLS = !!$TLS;
      
      if (($Type != qcEvents_Socket::TYPE_TCP) && ($Type != qcEvents_Socket::TYPE_UDP)) {
        trigger_error ('Invalid socket-type given');
        
        return $this->___raiseCallback ($Callback, null, null, $Private);
      } elseif (($Port < 1) || ($Port > 0xFFFF)) {
        trigger_error ('Invalid port given');
        
        return $this->___raiseCallback ($Callback, null, null, $Private);
      }
      
      // Create a key for the requested socket
      $Key = strtolower (is_array ($Host) ? implode ('-', $Host) : $Host) . '-' . $Port . '-' . $Type . ($TLS ? '-tls' : '');
      
      // Check if we have a socket available
      if (isset ($this->SocketMaps [$Key]))
        foreach ($this->SocketMaps [$Key] as $Index)
          if ($this->SocketStatus [$Index] == self::STATUS_AVAILABLE) {
            $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
            
            return $this->___raiseCallback ($Callback, $this->Sockets [$Index], $this->SocketPipes [$Index], $Private);
          }
      
      // Create new socket
      $Socket = new qcEvents_Socket ($this->eventBase);
      
      // Register the socket
      $this->Sockets [] = $Socket;
      
      if (($Index = array_search ($Socket, $this->Sockets, true)) === false) {
        trigger_error ('Lost socket... Strange!');
        
        return $this->___raiseCallback ($Callback, null, null, $Private);
      }
      
      $this->SocketStatus [$Index] = self::STATUS_CONNECTING;
      $this->SocketPipes [$Index] = null;
      
      if (isset ($this->SocketMaps [$Key]))
        $this->SocketMaps [$Key][$Index] = $Index;
      else
        $this->SocketMaps [$Key] = array ($Index => $Index);
      
      // Try to connect
      return $Socket->connect (
        $Host, $Port, $Type, $TLS,
        function (qcEvents_Socket $Socket, $Status)
        use ($Key, $Index, $Callback, $Private) {
          // Check if the connection was successfull
          if (!$Status) {
            // Quickly de-register the socket
            unset ($this->Sockets [$Index], $this->SocketStatus [$Index], $this->SocketPipes [$Index], $this->SocketCallbacks [$Index], $this->SocketMaps [$Key][$Index]);
            
            if (count ($this->SocketMaps [$Key]) > 0) {
              // Check if there is another socket available for usage
              foreach ($this->SocketMaps [$Key] as $Index)
                if ($this->SocketStatus [$Index] == self::STATUS_AVAILABLE) {
                  $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
                  
                  return $this->___raiseCallback ($Callback, $this->Sockets [$Index], $this->SocketPipes [$Index], $Private);
                }
            } else
              unset ($this->SocketMaps [$Key]);
            
            // Forward the error
            return $this->___raiseCallback ($Callback, null, null, $Private);
          }
          
          // Check wheter to further setup the socket
          if (count ($this->getHooks ('socketConnected')) == 0) {
            $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
            
            return $this->___raiseCallback ($Callback, $Socket, null, $Private);
          }
          
          // Try to enable the socket
          $this->SocketStatus [$Index] = self::STATUS_ENABLING;
          $this->SocketCallbacks [$Index] = array ($Callback, $Private);
          
          $this->___callback ('socketConnected', $Socket);
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
      
      if (isset ($this->SocketCallbacks [$Index]) && ($this->SocketCallbacks [$Index][0] !== null)) {
        $this->SocketStatus [$Index] = self::STATUS_ACQUIRED;
        $this->___raiseCallback ($this->SocketCallbacks [$Index][0], $Socket, $Pipe, $this->SocketCallbacks [$Index][1]);
        
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
      if (($this->SocketStatus [$Index] == self::STATUS_ENABLING) && isset ($this->SocketCallbacks [$Index]) && ($this->SocketCallbacks [$Index][0] !== null))
        $this->___raiseCallback ($this->SocketCallbacks [$Index][0], null, null, $this->SocketCallbacks [$Index][1]);
      
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