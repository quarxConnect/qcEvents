<?PHP

  /**
   * qcEvents - Multicast Socket Interface
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
  
  // Make sure sockets-Extension is available
  if (!extension_loaded ('sockets') && (!function_exists ('dl') || !dl ('sockets.so'))) {
    trigger_error ('Missing sockets-Extension, multicast-sockets not working');
    
    return;
  }
  
  require_once ('qcEvents/Socket/Server.php');
  
  /**
   * Event-Based multicast-listener
   * 
   * @class qcEvents_Socket_Multicast_Listener
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Multicast_Listener extends qcEvents_Socket_Server {
    /* Socket-Resource */
    private $Socket = null;
    
    /* Multicast-Groups */
    private $Groups = array ();
    
    // {{{ bind
    /** 
     * Bind the multicast-socket
     * 
     * @param int $Port
     * @param string $Host (optional)
     * 
     * @access public
     * @return bool
     **/
    public function bind ($Port, $Host = null) {
      return $this->listen ($this::TYPE_UDP, $Port, $Host);
    }
    // }}}
    
    // {{{ listen
    /**
     * Create a the server-process
     * 
     * @param enum $Type
     * @param int $Port
     * @param string $Host (optional)
     * @param int $Backlog (optional) Ignored
     * 
     * @access public
     * @return bool
     **/
    public function listen ($Type, $Port, $Host = null, $Backlog = null) {
      // Make sure a UDP-Socket is requested
      if ($Type != $this::TYPE_UDP) {
        trigger_error ('Multicast-Server requires UDP-Sockets');
        
        return false;
      }
      
      // Make sure we try to bind to something
      if ($Host === null)
        $Host = '0.0.0.0';
      
      // Try to create the socket
      if (!is_resource ($Socket = stream_socket_server ('udp://' . $Host . ':' . $Port, $ErrNo, $ErrStr, STREAM_SERVER_BIND)))
        return false;
      
      // Assign the socket and update our event-bsae
      $this->setServerSocket ($Socket, $this::TYPE_UDP, true);
      $this->Socket = socket_import_stream ($Socket);
      
      // Fire callback
      $this->___callback ('serverOnline');
      
      // Join pre-registered groups
      $this->multicastDoJoin ();
      
      return true;
    }
    // }}}
    
    // {{{ multicastJoinGroup
    /**
     * Join a given multicast-group
     * 
     * @param string $Group
     * @param mixed $Interface (optional)
     * 
     * @access public
     * @return bool
     **/
    public function multicastJoinGroup ($Group, $Interface = 0) {
      // Generate a group-key
      $Key = strtolower ($Group . $Interface);
      
      // Check if the group has already been joined
      if (isset ($this->Groups [$Key]))
        return true;
      
      // Register the group
      $this->Groups [$Key] = array (
        'group' => $Group,
        'interface' => $Interface,
        'blocks' => array (),
      );
      
      // Join the group
      if ($this->Socket)
        return $this->multicastDoJoin ($Key);
      
      return true;
    }
    // }}}
    
    // {{{ multicastDoJoin
    /**
     * Join all or a given set of registered multicast-groups
     * 
     * @param mixed $Keys (optional)
     * 
     * @access private
     * @return bool
     **/
    private function multicastDoJoin ($Keys = null) {
      // Make sure we have some keys to process
      if ($Keys === null)
        $Keys = array_keys ($this->Groups);
      elseif (!is_array ($Keys))
        $Keys = array ($Keys);
      
      // Make sure we have a socket
      if (!$this->Socket)
        return false;
      
      // Perform the joins
      foreach ($Keys as $Key) {
        // Make sure the key is valid
        if (!isset ($this->Groups [$Key]))
          continue;
        
        // Try to join the group
        $Group = array (
          'group' => $this->Groups [$Key]['group'],
          'interface' => $this->Groups [$Key]['interface'],
        );
        
        if (socket_set_option ($this->Socket, IPPROTO_IP, MCAST_JOIN_GROUP, $Group) === false) {
          trigger_error ('Failed to join multicast-group ' . $Group ['group']);
          
          return false;
        }
        
        // Raise a callback for this
        $this->___callback ('multicastGroupJoined', $Group ['group'], $Group ['interface']);
        
        // Block sources
        foreach ($this->Groups [$Key]['blocks'] as $K=>$Source) {
          $Group ['source'] = $Source;
          
          if (socket_set_option ($this->Socket, IPPROTO_IP, MCAST_BLOCK_SOURCE, $Group) === false) {
            trigger_error ('Failed to block source ' . $Source . ' on ' . $Group ['group']);
            
            unset ($this->Groups [$Key]['blocks'][$K]);
          } else
            $this->___callback ('multicastSourceBlocked', $Source, $Group ['group'], $Group ['interface']);
        }
      }
      
      return true;
    }
    // }}}
    
    // {{{ multicastLeaveGroup
    /**
     * Leave a multicast-group
     * 
     * @param string $Group
     * @param mixed $Interface (optional)
     * 
     * @access public
     * @return void
     **/
    public function multicastLeaveGroup ($Group, $Interface = 0) {
      // Generate a group-key
      $Key = strtolower ($Group . $Interface);
      
      // Check if the group has been joined
      if (!isset ($this->Groups [$Key]))
        return true;
      
      // Check if we have to really leave the group
      if ($this->Socket) {
        $Group = array (
          'group' => $this->Groups [$Key]['group'],
          'interface' => $this->Groups [$Key]['interface'],
        );
        
        foreach ($this->Groups [$Key]['blocks'] as $Source) {
          $Group ['source'] = $Source;
          
          if (socket_set_option ($this->Socket, IPPROTO_IP, MCAST_UNBLOCK_SOURCE, $Group) !== false)
            $this->___callback ('multicastSourceUnblocked', $Source, $Group ['group'], $Group ['interface']);
        }
        
        unset ($Group ['source']);
        
        // Try to leave the group
        if (socket_set_option ($this->Socket, IPPROTO_IP, MCAST_LEAVE_GROUP, $Group) === false)
          return false;
        
        $this->___callback ('multicastGroupLeaved', $Group ['group'], $Group ['interface']);
      }
      
      // Remove the group
      unset ($this->Groups [$Key]);
      
      return true;
    }
    // }}}
    
    // {{{ multicastBlockSource
    /**
     * Block a source on this listener
     * 
     * @param string $Source
     * @param string $Group (optional)
     * @param mixed $Interface (otpional)
     * 
     * @access public
     * @return void
     **/
    public function multicastBlockSource ($Source, $Group = null, $Interface = 0) {
      // Check where to block the source
      if ($Group !== null)
        $Keys = array (strtolower ($Group . $Interface));
      else
        $Keys = array_keys ($this->Groups);
      
      foreach ($Keys as $Key) {
        // Make sure the group is joined
        if (!isset ($this->Groups [$Key]))
          continue;
        
        // Check if the source already has been blocked
        if (in_array ($Source, $Groups [$Key]['blocks']))
          continue;
        
        // Add to blocked sources
        if ($this->Socket) {
          $Group = array (
            'group' => $this->Groups [$Key]['group'],
            'interface' => $this->Groups [$Key]['interface'],
            'source' => $Source,
          );
          
          if (socket_set_option ($this->Socket, IPPROTO_IP, MCAST_BLOCK_SOURCE, $Group) === false) {
            trigger_error ('Failed to block source ' . $Source . ' on ' . $Group ['group']);
            
            continue;
          }
          
          $Groups [$Key]['blocks'][] = $Source;
          $this->___callback ('multicastSourceBlocked', $Source, $Group ['group'], $Group ['interface']);
        } else
          $Groups [$Key]['blocks'][] = $Source;
      }
    }
    // }}}
    
    // {{{ multicastUnblockSource
    /**
     * Remove a source from list of blocked sources
     * 
     * @param string $Source
     * @param string $Group (optional)
     * @param mixed $Interface (optional)
     * 
     * @access public
     * @return void
     **/
    public function multicastUnblockSource ($Source, $Group = null, $Interface = 0) {
      // Check where to block the source
      if ($Group !== null)
        $Keys = array (strtolower ($Group . $Interface));
      else  
        $Keys = array_keys ($this->Groups);
      
      foreach ($Keys as $Key) {
        // Make sure the group is joined
        if (!isset ($this->Groups [$Key]))
          continue;
          
        // Check if the source already has been blocked
        if (($Pos = array_search ($Source, $Groups [$Key]['blocks'])) === false)
          continue;
        
        // Remove from set
        unset ($this->Groups [$Key]['blocks'][$Pos]);
        
        // Try to unblock
        if (!$this->Socket)
          continue;
        
        $Group = array (
          'group' => $this->Groups [$Key]['group'],
          'interface' => $this->Groups [$Key]['interface'],
          'source' => $Source,
        );
        
        if (socket_set_option ($this->Socket, IPPROTO_IP, MCAST_UNBLOCK_SOURCE, $Group) !== false)
          $this->___callback ('multicastSourceUnblocked', $Source, $Group ['group'], $Group ['interface']);
      }
    }
    // }}}
    
    
    // {{{ multicastGroupJoined
    /**
     * Callback: A multicast-group was joined
     * 
     * @param string $Group
     * @param mixed $Interface
     * 
     * @access protected
     * @return void
     **/
    protected function multicastGroupJoined ($Group, $Interface) { }
    // }}}
    
    // {{{ multicastGroupLeaved
    /**
     * Callback: A multicast-group was leaved
     * 
     * @param string $Group
     * @param mixed $Interface
     * 
     * @access protected
     * @return void
     **/
    protected function multicastGroupLeaved ($Group, $Interface) { }
    // }}}
    
    // {{{ multicastSourceBlocked
    /**
     * A source was blocked on a multicast-group
     * 
     * @param string $Source
     * @param string $Group
     * @param mixed $Interface
     * 
     * @access protected
     * @return void
     **/
    protected function multicastSourceBlocked ($Source, $Group, $Interface) { }
    // }}}
    
    // {{{ multicastSourceUnblocked
    /**
     * A previously blocked source was unblocked on a multicast-group
     * 
     * @param string $Source
     * @param stirng $Group
     * @param mixed $Interface
     * 
     * @access protected
     * @return void
     **/
    protected function multicastSourceUnblocked ($Source, $Group, $Interface) { }
    // }}}
  }

?>