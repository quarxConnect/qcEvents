<?PHP

  /**
   * qcEvents - MySQL Client Implementation
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket.php');
  
  /**
   * MySQL Client
   * ------------
   * Non-blocking and asyncronous MySQL-Client for MySQL 3.2+ / 4.1+
   * 
   * @class qcEvents_Socket_Client_MySQL
   * @extends qcEvents_Socket
   * @package qcEvents
   * @revision 01
   * 
   * @todo Add support to change the current user
   **/
  class qcEvents_Socket_Client_MySQL extends qcEvents_Socket {
    /* Defaults for IMAP */
    const DEFAULT_PORT = 3306;
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    
    /* Refresh-Types */
    const MYSQL_REFRESH_GRANT   = 0x01;
    const MYSQL_REFRESH_LOG     = 0x02;
    const MYSQL_REFRESH_TABLES  = 0x04;
    const MYSQL_REFRESH_HOSTS   = 0x08;
    const MYSQL_REFRESH_STATUS  = 0x10;
    const MYSQL_REFRESH_THREADS = 0x20;
    const MYSQL_REFRESH_SLAVE   = 0x40;
    const MYSQL_REFRESH_MASTER  = 0x80;
    
    /* Username/Password to authenticate with */
    private $Username = 'root';
    private $Password = '';
    
    /* Database we wish to connect to */
    private $Database = null;
    
    /* Protocol-States */
    const MYSQL_STATE_CONNECTING = 1;
    const MYSQL_STATE_AUTHENTICATING = 2;
    const MYSQL_STATE_CONNECTED = 3;
    const MYSQL_STATE_DISCONNECTING = 4;
    const MYSQL_STATE_DISCONNECTED = 0;
    
    private $mysqlState = qcEvents_Socket_Client_MySQL::MYSQL_STATE_DISCONNECTED;
    
    /* Features / Capabilities */
    const CAPA_LONG_PASSWORD     = 0x00000001;
    const CAPA_FOUND_ROWS        = 0x00000002;
    const CAPA_LONG_FLAG         = 0x00000004;
    const CAPA_CONNECT_WITH_DB   = 0x00000008;
    const CAPA_NO_SCHEMA         = 0x00000010;
    const CAPA_COMPRESS          = 0x00000020;
    const CAPA_ODBC              = 0x00000040; # Unimplemented
    const CAPA_LOCAL_FILES       = 0x00000080; # Unimplemented / ToDo
    const CAPA_IGNORE_SPACE      = 0x00000100;
    const CAPA_PROTOCOL_41       = 0x00000200;
    const CAPA_INTERACTIVE       = 0x00000400; 
    const CAPA_SSL               = 0x00000800; # Unimplemented / ToDo
    const CAPA_IGNORE_SIGPIPE    = 0x00001000;
    const CAPA_TRANSACTIONS      = 0x00002000;
    const CAPA_RESERVED          = 0x00004000;
    const CAPA_SECURE_CONNECTION = 0x00008000;
    const CAPA_MULTI_STATEMENTS  = 0x00010000; # Unimplemented
    const CAPA_MULTI_RESULTS     = 0x00020000; # Unimplemented
    const CAPA_PS_MULTI_RESULTS  = 0x00040000; # Unimplemented
    const CAPA_PLUGIN_AUTH       = 0x00080000; # Unimplemented
    const CAPA_CONNECT_ATTRS     = 0x00100000; # Unimplemented
    const CAPA_PLUGIN_AUTH_LENC  = 0x00200000;
    
    private $mysqlCapabilities   = 0x00000000;
    
    /* Character-Set used on server */
    private $mysqlCharacterset = 0x08;
    
    /* Use compression on this connection */
    private $mysqlCompress = false;
    
    /* Internal Buffer for protocol-data */
    private $mysqlBuffer = '';
    
    /* Internal Buffer for compressed protocol-data */
    private $mysqlCompressedBuffer = '';
    
    /* Current sequence of mysql-packets */
    private $mysqlSequence = 0;
    
    /* Current mysql-commnd */
    private $mysqlCommand = null;
    
    /* Waiting mysql-commands */
    private $mysqlCommands = array ();
    
    // {{{ __construct   
    /**
     * Create a new server-client
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent
      call_user_func_array ('parent::__construct', func_get_args ());

      // Register hooks
      $this->addHook ('socketConnected', array ($this, 'mysqlSocketConnected'));
    }
    // }}}
    
    // {{{ quit
    /**
     * Initiate a server-disconnect
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function quit ($Callback = null, $Private = null) {
      return $this->mysqlCommand (0x01, null, $Callback, $Private, self::MYSQL_STATE_DISCONNECTING);
    }
    // }}}
    
    // {{{ changeDatabase
    /**
     * Set a new default database
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function changeDatabase ($Name, $Callback = null, $Private = null) {
      return $this->mysqlCommand (0x02, $Name, $Callback, $Private);
    }
    // }}}
    
    // {{{ query
    /**
     * Issue a query on the server
     * 
     * If the query returns a resultset, the callback is expected to be in this form:
     *   function (bool $Status, string $Query, array $Fields, array $Rows, mixed $Private) { }
     * 
     * All other callbacks are expected to be in this form
     *   function (bool $Status, string $Query, int $affectedRows, int $lastInsertId, mixed $Private) { }
     * 
     * If a per-row-callback is given $Rows aren't stored in memory and not forwarded to final callback,
     * the per-row-callback is expected to be in this form:
     *   function (string $Query, array $Fields, array $Row, mixed $Private) { }
     * 
     * 
     * @param string $Query
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * @param callback $perRowCallback (optional)
     * @param mixed $perRowPrivate (optional)
     * 
     * @access public
     * @return void
     **/
    public function query ($Query, $Callback = null, $Private = null, $perRowCallback = null, $perRowPrivate = null) {
      if (!is_callable ($perRowCallback)) {
        $perRowCallback = null;
        $perRowPrivate = null;
      }
      
      return $this->mysqlCommand (0x03, $Query, array ($this, 'queryResult'), array ($Callback, $Query, $Private), array ($this, 'queryHandler'), array ($perRowCallback, $perRowPrivate));
    }
    // }}}
    
    // {{{ queryHandler
    /**
     * Internal callback: Receive a resultset
     * 
     * @param int $Sequence
     * @param string $Packet
     * @param mixed $Private
     * 
     * @access private
     * @return void
     **/
    private function queryHandler ($Sequence, $Packet, $Private) {
      // Check for a generic packet
      if (($Packet [0] == "\x00") || ($Packet [0] == "\xFF"))
        return false;
      
      # TODO: Check for LOCAL_INFILE_REQUEST
      
      // Check if we received the number of fields
      if (!isset ($this->mysqlCommand ['fieldCount'])) {
        $this->mysqlCommand ['fieldCount'] = $this->mysqlReadIntegerLenc ($Packet);
        $this->mysqlCommand ['fields'] = array ();
        $this->mysqlCommand ['queryState'] = 0;
        $this->mysqlCommand ['rows'] = array ();
        
        return;
      }
      
      // Check if we are receiving a field
      if ($this->mysqlCommand ['queryState'] == 0) {
        if ($Packet [0] == "\xFE") {
          $this->mysqlCommand ['queryState'] = 1;
          
          return;
        }
        
        $this->mysqlCommand ['fields'][] = $this->mysqlReadField ($Packet);
      
      // Check if we are receiving a record
      } elseif ($this->mysqlCommand ['queryState'] == 1) {
        if ($Packet [0] == "\xFE")
          return false;
        
        $row = array ();
        $p = 0;
        $l = strlen ($Packet);
        
        while ($p < $l) {
          $Field = $this->mysqlCommand ['fields'][count ($row)];
          
          if ($Packet [$p] == "0xFB") {
            $row [$Field ['name']] = null;
            $p++;
          } else {
            $Value = $this->mysqlReadStringLenc ($Packet, $p, true);
            
            $row [$Field ['name']] = $Value;
          }
        }
        
        if ($Private [0] !== null) {
          if (!is_array ($Private [0]) || ($Private [0][0] !== $this))
            call_user_func ($Private [0], $this, $this->mysqlCommand [3][1], $this->mysqlCommand ['fields'], $row, $Private [1]);
          else
            call_user_func ($Private [0], $this->mysqlCommand [3][1], $this->mysqlCommand ['fields'], $row, $Private [1]);
        } else
          $this->mysqlCommand ['rows'][] = $row;
      }
    }
    // }}}
    
    // {{{ queryResult
    /**
     * Internal Callback: A result of a query was received completly
     * 
     * @param bool $Status
     * @param int $Warnings
     * @param string $Info
     * @param int $Flags
     * @param mixed $Private
     * @param int $affectedRows
     * @param int $lastInsertId
     * 
     * @access private
     * @return void
     **/
    private function queryResult ($Status, $Warnings, $Info, $Flags, $Private, $affectedRows, $lastInsertId) {
      if (!is_callable ($Private [0]))
        return;
      
      if (isset ($this->mysqlCommand ['rows']))
        $Args = array ($Status, $Private [1], $this->mysqlCommand ['fields'], $this->mysqlCommand ['rows'], $Private [2]);
      else
        $Args = array ($Status, $Private [1], $affectedRows, $lastInsertId, $Private [2]);
      
      if (!is_array ($Private [0]) || ($Private [0][0] !== $this))
        array_unshift ($Args, $this);
      
      call_user_func_array ($Private [0], $Args);
    }
    // }}}
    
    // {{{ listFields
    /**
     * Retrive the fields of a given table
     * 
     * The Callback is expected to be in this form:
     *   function (bool $Status, string $Table, string $Wildcard, array $Fields, mixed $Private) { }
     * 
     * @param string $Table
     * @param string $Wildcard (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function listFields ($Table, $Wildcard = null, $Callback = null, $Private = null) {
      return $this->mysqlCommand (0x04, $Table . "\x00" . $Wildcard, array ($this, 'listResult'), array ($Callback, $Table, $Wildcard, $Private), array ($this, 'listFieldsHandler'));
    }
    // }}}
    
    // {{{ listFieldsHandler
    /**
     * Internal Callback: Handle an incoming response to a list_fields-request
     * 
     * @param int $Sequence
     * @param string $Packet
     * @param mixed $Private
     * 
     * @access private
     * @return void
     **/
    private function listFieldsHandler ($Sequence, $Packet, $Private) {
      // Check for a generic packet
      if (($Packet [0] == "\xFE") || ($Packet [0] == "\xFF"))
        return false;
      
      if (!isset ($this->mysqlCommand ['fields']))
        $this->mysqlCommand ['fields'] = array ();
      
      $this->mysqlCommand ['fields'][] = $this->mysqlReadField ($Packet);
    }
    // }}}
    
    // {{{ listResult
    /**
     * Internal callback: Forward a completed list_fields-result
     * 
     * @param bool $Status
     * @param int $Warnings
     * @param string $Info
     * @param int $Flags
     * @param mixed $Private
     * 
     * @access private
     * @return void
     **/
    private function listResult ($Status, $Warnings, $Info, $Flags, $Private) {
      if (!is_callable ($Private [0]))
        return;
      
      $Args = array ($Status, $Private [1], $Private [2], $this->mysqlCommand ['fields'], $Private [3]);
      
      if (!is_array ($Private [0]) || ($Private [0][0] !== $this))
        array_unshift ($Args, $this);
      
      call_user_func_array ($Private [0], $Args);
    }
    // }}}
    
    // {{{ mysqlReadField
    /**
     * Parse a Field-Definition from MySQL
     * 
     * @param string $Packet
     * 
     * @access private
     * @return arrya
     **/
    private function mysqlReadField (&$Packet) {
      $p = 0;
      
      if ($this->mysqlCapabilities & self::CAPA_PROTOCOL_41) {
        $catalog = $this->mysqlReadStringLenc ($Packet, $p, true);
        $schema = $this->mysqlReadStringLenc ($Packet, $p, true); 
        $table = $this->mysqlReadStringLenc ($Packet, $p, true);  
        $org_table = $this->mysqlReadStringLenc ($Packet, $p, true);
        $name = $this->mysqlReadStringLenc ($Packet, $p, true);
        $org_name = $this->mysqlReadStringLenc ($Packet, $p, true);
        $this->mysqlReadIntegerLenc ($Packet, $p, true);
        $characterset = $this->mysqlReadInteger ($Packet, 2, $p, true);
        $length = $this->mysqlReadInteger ($Packet, 4, $p, true);
        $type = $this->mysqlReadInteger ($Packet, 1, $p, true);  
        $flags = $this->mysqlReadInteger ($Packet, 2, $p, true); 
        $decimals = $this->mysqlReadInteger ($Packet, 1, $p, true);
      } else {
        $catalog = '';
        $schema = ''; 
        $table = $this->mysqlReadStringLenc ($Packet, $p, true);
        $org_table = '';
        $name = $this->mysqlReadStringLenc ($Packet, $p, true);
        $org_name = '';
        $characterset = '';
        $l = $this->mysqlReadIntegerLenc ($Packet, $p, true);
        $length = $this->mysqlReadInteger ($Packet, $l, $p, true);
        $l = $this->mysqlReadIntegerLenc ($Packet, $p, true);
        $type = $this->mysqlReadInteger ($Packet, $l, $p, true);
        $l = $this->mysqlReadIntegerLenc ($Packet, $p, true);   
        $flags = $this->mysqlReadInteger ($Packet, $l - 1, $p, true);
        $decimals = $this->mysqlReadInteger ($Packet, 1, $p, true);  
      }
      
      $Field = array (
        'catalog' => $catalog,
        'schema' => $schema,  
        'table' => $table,    
        'org_table' => $org_table,
        'name' => $name,
        'org_name' => $org_name,
        'characterset' => $characterset,
        'length' => $length,
        'type' => $type,
        'flags' => $flags,
        'decimals' => $decimals,
      );
      
      if (strlen ($Packet) > $p)
        $Field ['default'] = $this->mysqlReadStringLenc ($Packet, $p, true);
      
      return $Field;
    }
    // }}}
    
    // {{{ refresh
    /**
     * Perform a refresh on the server
     * 
     * @param int $What
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void  
     **/
    public function refresh ($What, $Callback = null, $Private = null) {
      return $this->mysqlCommand (0x07, $this->mysqlWriteInteger ($What, 1), $Callback, $Private);
    }
    // }}}
    
    // {{{ shutdown
    /**
     * Request a server-shutdown
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void  
     **/
    public function shutdown ($Callback = null, $Private = null) {
      return $this->mysqlCommand (0x08, null, $Callback, $Private);
    }
    // }}}
    
    // {{{ killProcess
    /**
     * Kill a process on the server
     * 
     * @param int $Process
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void  
     **/
    public function killProcess ($Process, $Callback = null, $Private = null) {
      return $this->mysqlCommand (0x0C, $this->mysqlWriteInteger ($Process, 4), $Callback, $Private);
    }
    // }}}
    
    // {{{ ping
    /**
     * Ping the server
     *  
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void  
     **/
    public function ping ($Callback = null, $Private = null) {
      return $this->mysqlCommand (0x0E, null, $Callback, $Private);
    }  
    // }}}
    
    
    // {{{ mysqlCommand
    /**
     * Queue a command to be send to the server
     * 
     * @param int $Command
     * @param string $Data (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * @param callback $Receiver (optional)
     * @param mixed $rPrivate (optional)
     * 
     * @access private
     * @return void
     **/
    private function mysqlCommand ($Command, $Data = null, $Callback = null, $Private = null, $Receiver = null, $rPrivate = null) {
      // Put the command to the queue
      $this->mysqlCommands [] = array ($Command, $Data, $Callback, $Private, $Receiver, $rPrivate);
      
      // Check wheter to run the next command directly
      $this->mysqlIssueCommand ();
    }
    // }}}
    
    // {{{ mysqlIssueCommand
    /**
     * Check wheter to send the next MySQL-Command
     * 
     * @access private
     * @return void
     **/
    private function mysqlIssueCommand () {
      // Check if there is a command active
      if ($this->mysqlCommand !== null)
        return;
      
      // Check if there are commands pending
      if (count ($this->mysqlCommands) == 0)
        return;
      
      // Get the next command from queue
      $this->mysqlCommand = array_shift ($this->mysqlCommands);
      
      // Issue the command
      $this->mysqlSequence = 0;
      $this->mysqlSendPacket ($this->mysqlWriteInteger ($this->mysqlCommand [0], 1) . ($this->mysqlCommand [1] !== null ? $this->mysqlCommand [1] : ''));
    }
    // }}}
    
    
    // {{{ mysqlSetState
    /**
     * Change the internal status of our protocol
     * 
     * @param enum $State
     * 
     * @access private
     * @return void
     **/
    private function mysqlSetState ($State) {
      if ($State == $this->mysqlState)
        return;
      
      $oState = $this->mysqlState;
      $this->mysqlState = $State;
      
      $this->___callback ('mysqlStateChanged', $State, $oState);
    }
    // }}}
    
    // {{{ mysqlSocketConnected
    /**
     * Internal Callback: The Socket-Connection was established
     * 
     * @access protected
     * @return void
     **/
    protected final function mysqlSocketConnected () {
      $this->mysqlSetState (self::MYSQL_STATE_CONNECTING);
    }
    // }}}
    
    // {{{ socketReadable
    /**
     * Internal Callback: There is data available on our buffer
     * 
     * @remark This Client does not work as buffered client as it maintains its own
     * 
     * @access protected
     * @return void
     **/
    protected final function socketReadable () {
      $this->socketReceive ($this->readBuffer ());
    }
    // }}}
    
    // {{{ socketReceive
    /**
     * Internal Callback: Receive data from our socket
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected final function socketReceive ($Data) {
      // Handle compression
      if ($this->mysqlCompress) {
        $this->mysqlCompressedBuffer .= $Data;
        
        // Check if the next packet is ready
        while (($l = strlen ($this->mysqlCompressedBuffer)) >= 4) {
          // Retrive the length of the next packet
          $Length = $this->mysqlReadInteger ($this->mysqlCompressedBuffer, 3);
          
          if ($l < $Length + 7)
            break;
          
          // Retrive the sequence
          # $Sequence = $this->mysqlReadInteger ($this->mysqlCompressedBuffer, 1, $p = 3);
          
          // Retrive the original length
          # $oLength = $this->mysqlReadInteger ($this->mysqlCompressedBuffer, 3, $p = 4);
          
          // Retrive the compressed chunk
          $this->mysqlBuffer .= gzuncompress (substr ($this->mysqlCompressedBuffer, 7, $Length));
          $this->mysqlCompressedBuffer = substr ($this->mysqlCompressedBuffer, $Length + 7);
        }
        
      // Append Data to our internal buffer
      } else
        $this->mysqlBuffer .= $Data;
      
      // Check if the next packet is ready
      while (($l = strlen ($this->mysqlBuffer)) >= 4) {
        // Retrive the length of the next packet
        $Length = $this->mysqlReadInteger ($this->mysqlBuffer, 3);
        
        // Check if the buffer is big enough
        if ($l < $Length + 4)
          return;
        
        // Retrive the sequence
        $Sequence = $this->mysqlReadInteger ($this->mysqlBuffer, 1, $p = 3);
        
        // Retrive the packet from the buffer
        $Packet = substr ($this->mysqlBuffer, 4, $Length);
        $this->mysqlBuffer = substr ($this->mysqlBuffer, $Length + 4);
        
        // Handle the packet
        $this->mysqlReceivePacket ($Sequence, $Packet);
      }
    }
    // }}}
    
    // {{{ mysqlReceivePacket
    /**
     * Process an incoming MySQL-Packet from the server
     * 
     * @param int $Sequence
     * @param string $Packet
     * 
     * @access private
     * @return void
     **/
    private function mysqlReceivePacket ($Sequence, $Packet) {
      // Update local sequence
      $this->mysqlSequence = $Sequence + 1;
      
      // Check our protocol-state
      if ($this->mysqlState == self::MYSQL_STATE_CONNECTING) {
        // Check the version of the server
        $Version = ord ($Packet [0]);
        $p = 1;
        
        // Handle handshake based on version
        switch ($Version) {
          case 10:
            // Read the human readable server-version from packet
            if (($sVersion = $this->mysqlReadStringNUL ($Packet, $p, true)) === false)
              return $this->mysqlProtocolError ('Could not read server-version');
            
            // Read the connection-id
            if (($connectionID = $this->mysqlReadInteger ($Packet, 4, $p, true)) === false)
              return $this->mysqlProtocolError ('Could not read connection-id');
            
            // Read Scrambling-Data for authentication
            if (($authScramble = $this->mysqlReadStringFixed ($Packet, 8, $p, true)) === false)
              return $this->mysqlProtocolError ('Could not read auth-plugin-data');
            
            // Skip one byte ahead
            $p++;
            
            // Read capabilities
            if (($Capabilties = $this->mysqlReadInteger ($Packet, 2, $p, true)) === false)
              return $this->mysqlProtocolError ('Could not read capabilities');
            
            // Check if there is more data available
            if (strlen ($Packet) > $p) {
              // Read the character-set
              if (($charset = $this->mysqlReadInteger ($Packet, 1, $p, true)) === false)
                return $this->mysqlProtocolError ('Could not read character-set');
              
              // Read status flags
              if (($status = $this->mysqlReadInteger ($Packet, 2, $p, true)) === false)
                return $this->mysqlProtocolError ('Could not read status flags');
              
              // Read more capabilities
              if (($Capabilties2 = $this->mysqlReadInteger ($Packet, 2, $p, true)) === false)
                return $this->mysqlProtocolError ('Could not read capabilities');
              
              $Capabilties += ($Capabilties2 << 16);
              
              // Read length auf auth-plugin-data
              if (($authL = $this->mysqlReadInteger ($Packet, 1, $p, true)) === false)
                return $this->mysqlProtocolError ('Could not read length of authentication-data');
              
              // Skip some bytes ahead
              $p += 10;
              
              // Read additional data for auth-scrambling
              if ($Capabilties & self::CAPA_SECURE_CONNECTION) {
                if (($authScrambleExt = $this->mysqlReadStringFixed ($Packet, max (12, $authL - 8), $p, true)) === false)
                  return $this->mysqlProtocolError ('Could not read auth-plugin-data-2');
                
                # $authScramble .= $authScrambleExt;
              }
              
              unset ($authL, $Capabilties2);
              
              // Read auth_method (Some MySQL-Versions don't send a NUL-terminating Byte at the end)
              if (($authName = $this->mysqlReadStringNUL ($Packet, $p, true)) === false)
                $authName = substr ($Packet, $p);
            } else {
              $charset = 0x08;
              $status = 0x0000;
              $authName = null;
            }
            
            // Store the received values
            $this->mysqlCapabilities = $Capabilties;
            $this->mysqlCharacterset = $charset;
            
            break;
          case  9:
            // Read the human readable server-version from packet
            if (($sVersion = $this->mysqlReadStringNUL ($Packet, $p, true)) === false)
              return $this->mysqlProtocolError ('Could not read server-version');
            
            // Read the connection-id
            if (($connectionID = $this->mysqlReadInteger ($Packet, 4, $p, true)) === false)
              return $this->mysqlProtocolError ('Could not read connection-id');
            
            // Read Scrambling-Data for authentication
            if (($authScramble = $this->mysqlReadStringNUL ($Packet, $p, true)) === false)
              return $this->mysqlProtocolError ('Could not read auth-plugin-data');
        }
        
        // Setup basic/common capabiltities (these work with both 3.20 and 4.1 protocol!)
        $Capabilities =
          (($this->mysqlCapabilities & self::CAPA_LONG_PASSWORD) ? self::CAPA_LONG_PASSWORD : 0) |
          (($this->mysqlCapabilities & self::CAPA_FOUND_ROWS) ? self::CAPA_FOUND_ROWS : 0) |
          (($this->mysqlCapabilities & self::CAPA_LONG_FLAG) ? self::CAPA_LONG_FLAG : 0) |
          (($this->mysqlCapabilities & self::CAPA_CONNECT_WITH_DB) && ($this->Database !== null) ? self::CAPA_CONNECT_WITH_DB : 0) |
          # (($this->mysqlCapabilities & self::CAPA_COMPRESS) ? self::CAPA_COMPRESS : 0) |
          # (($this->mysqlCapabilities & self::CAPA_LOCAL_FILES) ? self::CAPA_LOCAL_FILES : 0) |
          (($this->mysqlCapabilities & self::CAPA_IGNORE_SPACE) ? self::CAPA_IGNORE_SPACE : 0) |
          # (($this->mysqlCapabilities & self::CAPA_INTERACTIVE) ? self::CAPA_INTERACTIVE : 0) |
          # (($this->mysqlCapabilities & self::CAPA_SSL) ? self::CAPA_SSL : 0) |
          (($this->mysqlCapabilities & self::CAPA_TRANSACTIONS) ? self::CAPA_TRANSACTIONS : 0) |
          (($this->mysqlCapabilities & self::CAPA_SECURE_CONNECTION) ? self::CAPA_SECURE_CONNECTION : 0);
        
        // Generate Password for authentication
        if (strlen ($this->Password) == 0)
          $authData = '';
        
        elseif ($Capabilities & self::CAPA_SECURE_CONNECTION)
          $authData = sha1 ($authScramble . $authScrambleExt . sha1 ($x1 = sha1 ($this->Password, true), true), true) ^ $x1;
        
        // Fallback to old password-method
        else
          $authData = $this->mysqlScrambleOld ($authScramble, $this->Password);
        
        // Generate handshake-response
        if (($this->mysqlCapabilities & self::CAPA_PROTOCOL_41) == self::CAPA_PROTOCOL_41) {
          $authName = '';
          $attrs = '';
          
          $this->mysqlSendPacket (
            // Capabiltites
            $this->mysqlWriteInteger ($Capabilities | self::CAPA_PROTOCOL_41, 4) .
            
            // Max Packet Size
            $this->mysqlWriteInteger (0x01000000, 4) .
            
            // Characterset of this client (UTF-8 by default)
            $this->mysqlWriteInteger (0x21, 1) .
            
            // Some wasted space =)
            "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
            
            // Username for this connection
            $this->mysqlWriteStringNUL ($this->Username) .
            
            // Write out authentication-data
            ($Capabilities & self::CAPA_PLUGIN_AUTH_LENC ?
              $this->mysqlWriteStringLength ($authData, true) :
              ($Capabilities & self::CAPA_SECURE_CONNECTION ?
                $this->mysqlWriteStringLength ($authData) :
                $this->mysqlWriteStringNUL ($authData)
              )
            ) .
            
            // Select database
            ($Capabilities & self::CAPA_CONNECT_WITH_DB ? $this->mysqlWriteStringNUL ($this->Database) : '') .
            
            // Write out plugin-name for authenticateion
            ($Capabilities & self::CAPA_PLUGIN_AUTH ? $this->mysqlWriteStringNUL ($authName) : '') .
            
            // Write out connection-attributes
            ($Capabilitites & self::CAPA_CONNECT_ATTRS ? $attrs : '')
          );
          
        } else
          $this->mysqlSendPacket (
            // Capabiltites
            $this->mysqlWriteInteger ($Capabilities, 2) .
            
            // Max Packet Size
            $this->mysqlWriteInteger (0xFF0000, 3) .
            
            // Username for this connection
            $this->mysqlWriteStringNUL ($this->Username) .
            
            ($Capabilities & self::CAPA_CONNECT_WITH_DB ?
              $this->mysqlWriteStringNUL ($authData) .
              $this->mysqlWriteStringNUL ($this->Database) :
              $authData
            )
          );
        
        // Change the state
        $this->mysqlSetState (self::MYSQL_STATE_AUTHENTICATING);
        
        // Set our compression-status
        $this->mysqlCompress = (($Capabilities & self::CAPA_COMPRESS) == self::CAPA_COMPRESS);
      
      // Check if we just received an authenticationg-response
      } elseif ($this->mysqlState == self::MYSQL_STATE_AUTHENTICATING) {
        if (ord ($Packet [0]) == 0x00) {
          $this->mysqlSetState (self::MYSQL_STATE_CONNECTED);
          $this->___callback ('mysqlAuthenticated');
          $this->mysqlIssueCommand ();
        } else {
          $this->mysqlSetState (self::MYSQL_STATE_DISCONNECTED);
          $this->___callback ('mysqlAuthenticationFailed');
          
          return $this->disconnect ();
        }
      
      // Check if we are in connected state
      } elseif ($this->mysqlState == self::MYSQL_STATE_CONNECTED) {
        // Check if there is a command active
        if ($this->mysqlCommand === null)
          return;
        
        // Check if the current command has it's own receiver
        if (($this->mysqlCommand [4] !== null) && (call_user_func ($this->mysqlCommand [4], $Sequence, $Packet, $this->mysqlCommand [5]) !== false))
          return;
        
        // Check if there is a valid callback for this result
        if (is_callable ($this->mysqlCommand [2])) {
          // Try to parse an OK Packet
          $p = 1;
          
          if ($Packet [0] == "\x00") {
            $status = true;
            $affectedRows = $this->mysqlReadIntegerLenc ($Packet, $p, true);
            $lastInsertId = $this->mysqlReadIntegerLenc ($Packet, $p, true);
            
            if ($this->mysqlCapabilities & self::CAPA_PROTOCOL_41) {
              $statusFlags = $this->mysqlReadInteger ($Packet, 2, $p, true);
              $warnings = $this->mysqlReadInteger ($Packet, 2, $p, true);
            } elseif ($this->mysqlCapabilities & self::CAPA_TRANSACTIONS) {
              $statusFlags = $this->mysqlReadInteger ($Packet, 2, $p, true);
              $warnings = 0x0000;
            } else {
              $statusFlags = 0x0000;
              $warnings = 0x0000;
            }
            
            $info = substr ($Packet, $p);
            unset ($Packet);
          
          // Try to parse an ERR Packet
          } elseif ($Packet [0] == "\xFF") {
            $status = false;
            $warnings = $this->mysqlReadInteger ($Packet, 2, $p, true);
            $affectedRows = null;
            $lastInsertId = null;
            
            if ($this->mysqlCapabilities & self::CAPA_PROTOCOL_41)
              $sqlState = $this->mysqlReadStringFixed ($Packet, 6, $p, true);
            else
              $sqlState = '';
            
            $info = substr ($Packet, $p);
            unset ($Packet);
          
          // Try to parse an EOF Packet
          } elseif ($Packet [0] == "\xFE") {
            $status = true;
            $info = '';
            $affectedRows = null;
            $lastInsertId = null;
            
            if ($this->mysqlCapabilities & self::CAPA_PROTOCOL_41) {
              $warnings = $this->mysqlReadInteger ($Packet, 2, $p, true);
              $statusFlags = $this->mysqlReadInteger ($Packet, 2, $p, true);
            } else {
              $statusFlags = 0x0000;
              $warnings = 0x0000;
            }
          } else
            return trigger_error ('Unknown packet received');
          
          // Fire callback
          $Args = array ($status, $warnings, $info, $statusFlags, $this->mysqlCommand [3], $affectedRows, $lastInsertId);
          
          if (!is_array ($this->mysqlCommand [2]) || ($this->mysqlCommand [2][0] !== $this))
            array_unshift ($Args, $this);
          
          call_user_func_array ($this->mysqlCommand [2], $Args);
        }
        
        // Free the command
        $this->mysqlCommand = null;
        $this->mysqlIssueCommand ();
      }
    }
    // }}}
    
    // {{{ mysqlSendPacket
    /**
     * Write a mysql-packet to the wire
     * 
     * @param string $Packet
     * 
     * @access private
     * @return void
     **/
    private function mysqlSendPacket ($Packet) {
      // Remember the original length of the packet
      $l = strlen ($Packet);
    
      // Generate Header
      $Packet = $this->mysqlWriteInteger ($l, 3) . chr ($this->mysqlSequence++) . $Packet;
      
      // Check wheter not to use compression
      if (!$this->mysqlCompress)
        return $this->write ($Packet);
      
      // Check wheter to compress
      if ($l < 50)
        return $this->write ($this->mysqlWriteInteger ($l + 4, 3) . chr ($this->mysqlSequence) . "\x00\x00\x00" . $Packet);
      
      // Compress the packet
      $Packet = gzcompress ($Packet);
      
      return $this->write ($this->mysqlWriteInteger (strlen ($Packet), 3) . chr ($this->mysqlSequence) . $this->mysqlWriteInteger ($l + 4, 3) . $Packet);
    }
    // }}}
    
    // {{{ mysqlHashOld
    /**
     * Generate a MySQL-Hash
     * 
     * @param string $Data
     * 
     * @access private
     * @return array
     **/
    private function mysqlHashOld ($Data) {
      $nr = (double)1345345333;
      $nr2 = (double)0x12345671;
      $tmp = (double)0;
      $add = 7;
      
      for ($i = 0; $i < strlen ($Data); $i++) {
        if (($Data [$i] == ' ') || ($Data [$i] == "\t"))
          continue;
        
        $tmp = (double)ord ($Data[$i]);
        $nr ^= ((($nr & 63) + $add) * $tmp) + ($nr << 8);
        $nr2 += ($nr2 << 8) ^ $nr;
        $add += $tmp;
      }
      
      return array (
        $nr & (((double)1 << 31) - 1),
        $nr2 & (((double)1 << 31) - 1)
      );
    }
    // }}}
    
    // {{{ mysqlScrambleOld
    /**
     * Scramble a message
     * 
     * @param string $Message
     * @param string $Password
     * 
     * @access private
     * @return string
     **/
    private function mysqlScrambleOld ($Message, $Password) {
      $rc = '';
      
      if (strlen ($Message) > 0) {
        $p = $this->mysqlHashOld ($Password);
        $m = $this->mysqlHashOld ($Message);
        
        $s1 = ($p [0] ^ $m [0]) % 0x3FFFFFFF;
        $s2 = ($p [1] ^ $m [1]) % 0x3FFFFFFF;
        
        for ($i = 0; $i < strlen ($Message); $i++) {
          $s1 = ($s1 * 3 + $s2) % 0x3FFFFFFF;
          $s2 = ($s1 + $s2 + 33) % 0x3FFFFFFF;
          $rc .= chr ((floor ($s1 / 0x3FFFFFFF) * 31) + 64);
        }
        
        $s1 = ($s1 * 3 + $s2) % 0x3FFFFFFF;
        $s2 = ($s1 + $s2 + 33) % 0x3FFFFFFF;
        $e = chr ((floor ($s1 / 0x3FFFFFFF) * 31) + 64);
        
        for ($i = 0; $i < strlen ($rc); $i++)
          $rc [$i] = chr (ord ($rc [$i]) ^ $e);
      }
      
      return $rc . "\x00";
    }
    // }}}
    
    // {{{ mysqlWriteInteger
    /**
     * Convert an integer of a given size into MySQL's binary coding
     * 
     * @param int $Value
     * @param int $Size
     * 
     * @access private
     * @return string
     **/
    private function mysqlWriteInteger ($Value, $Size) {
      // Convert the interger to binary
      $buf = '';
      
      for ($i = 0; $i < $Size; $i++) {
        $buf .= chr ($Value & 0xFF);
        $Value = $Value >> 8;
      }
      
      return $buf;
    }
    // }}}
    
    // {{{ mysqlWriteIntegerLenc
    /**
     * Convert an integer into a length-encoded binary coding for MySQL
     * 
     * @param int $Value
     * 
     * @access private
     * @return string
     **/
    private function mysqlWriteIntegerLenc ($Value) {
      // Check if the value is small enough
      if ($Value < 0xFB)
        return chr ($Value);
      
      // Convert the interger to binary
      $buf = '';
      
      while ($Value != 0) {
        $buf .= chr ($Value & 0xFF);
        $Value = $Value >> 8;
      }
      
      // Padd additional zeros
      switch (strlen ($buf)) {
        case 1: $buf .= "\x00";
        case 2: $buf = "\xFC" . $buf;
          break;
        case 3: $buf = "\xFD" . $buf;
          break;
        case 4: $buf .= "\x00";
        case 5: $buf .= "\x00";
        case 6: $buf .= "\x00";
        case 7: $buf .= "\x00";
        case 8: $buf = "\xFE" . $buf;
          break;
        default:
          return false;
      }
      
      return $buf;
    }
    // }}}
    
    // {{{ mysqlWriteStringNUL
    /**
     * Convert a string into a NUL-terminated one
     * 
     * @param string $Data
     * 
     * @access private
     * @return string
     **/
    private function mysqlWriteStringNUL ($Data) {
      return $Data . "\x00";
    }
    // }}}
    
    // {{{ mysqlWriteStringLength
    /**
     * Convert a stirng into a length-encoded string
     * 
     * @param string $Data
     * @param bool $asLenEnc (optional) Generate a length-encoded integer
     * 
     * @access private
     * @return string
     **/
    private function mysqlWriteStringLength ($Data, $asLenEnc = false) {
      return ($asLenEnc ? $this->mysqlWriteIntegerLenc (strlen ($Data)) : $this->mysqlWriteInteger (strlen ($Data), 1)) . $Data;
    }
    // }}}
    
    // {{{ mysqlReadInteger
    /**
     * Read an integer from a binary packet
     * 
     * @param string $Data
     * @param int $Size
     * @param int $Pos (optional)
     * @param bool $uPos (optional)
     * 
     * @access private
     * @return int
     **/
    private function mysqlReadInteger (&$Data, $Size, &$Pos = 0, $uPos = false) {
      // Retrive the integer from data
      $Value = 0;
      
      for ($i = 0; $i < $Size; $i++)
        $Value += ord ($Data [$Pos + $i]) << ($i * 8);
      
      // Check wheter to update the position-marker
      if ($uPos)
        $Pos += $Size;
      
      return $Value;
    }
    // }}}
    
    // {{{ mysqlReadIntegerLenc
    /**
     * Read a length encoded integer
     * 
     * @param string $Data
     * @param int $Pos
     * @param bool $uPos (optional)
     * 
     * @access private
     * @return int
     **/
    private function mysqlReadIntegerLenc (&$Data, &$Pos = 0, $uPos = false) {
      // Read the length-byte
      $Value = ord ($Data [$Pos]);
      $L = 1;
      
      // Check if there are more bytes to read
      if ($Value > 0xFA) {
        switch ($Value) {
          case 0xFB: // NULL
            $Value = null;
            break;
          case 0xFC: // Two-Byte Integer
            $L = 3;
            break;
          case 0xFD: // Tree-Byte Integer
            $L = 4;
            break;
          case 0xFE: // Eight-Byte Integer
            $L = 9;
            break;
          case 0xFF:
            $Value = false;
        }
        
        if ($L != 1) {
          $p = $Pos + 1;
          $Value = $this->mysqlReadInteger ($Data, $L - 1, $p);
        }
      }
      
      // Update the position-marker
      if ($uPos)
        $Pos += $L;
      
      return $Value;
    }
    // }}}
    
    // {{{ mysqlReadStringFixed
    /**
     * Retrive a fixed-size string from a binary packet
     * 
     * @param string $Data
     * @param int $Size
     * @param int $Pos
     * @param bool $uPos (optional)
     * 
     * @access private
     * @return string
     **/
    private function mysqlReadStringFixed (&$Data, $Size, &$Pos, $uPos = false) {
      // Remember the requested position
      $s = $Pos;
      
      // Check wheter to update the position-marker
      if ($uPos)
        $Pos += $Size;
      
      // Return the requested string
      return substr ($Data, $s, $Size);
    }
    // }}}
    
    // {{{ mysqlReadStringNUL
    /**
     * Read a NULL-Terminated string from a packet
     * 
     * @param string $Data
     * @param int $Pos
     * @param bool $uPos (optional)
     * 
     * @access private
     * @return string
     **/
    private function mysqlReadStringNUL (&$Data, &$Pos, $uPos = false) {
      // Check if there is a NULL-Terminator on the data
      if (($p = strpos ($Data, "\x00", $Pos)) === false)
        return false;
      
      // Remember the requested position
      $s = $Pos;
      
      // Check wheter to update the position-marker
      if ($uPos)
        $Pos = $p + 1;
      
      // Return the substring
      return substr ($Data, $s, $p - $s);
    }
    // }}}
    
    // {{{ mysqlReadStringLenc
    /**
     * Read a Length-Encoded String from MySQL-Packet
     * 
     * @param string $Data
     * @param int $Pos
     * @param bool $uPos (optional)
     * 
     * @access private
     * @return string
     **/
    private function mysqlReadStringLenc (&$Data, &$Pos, $uPos = false) {
      $lPos = $Pos;
      
      if (($Length = $this->mysqlReadIntegerLenc ($Data, $lPos, true)) === false)
        return false;
      
      if ($uPos)
        $Pos = $lPos + $Length;
      
      return substr ($Data, $lPos, $Length);
    }
    // }}}
    
    
    // {{{ mysqlStateChanged
    /**
     * Callback: The MySQL-Protocol-State was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ mysqlAuthenticated
    /**
     * Callback: The MySQL-Connection was authenticated
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlAuthenticated () { }
    // }}}
    
    // {{{ mysqlAuthenticationFailed
    /**
     * Callback: Authentication failed
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlAuthenticationFailed () { }
    // }}}
  }

?>