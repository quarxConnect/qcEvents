<?PHP

  /**
   * qcEvents - MySQL Stream Implementation
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * MySQL Client
   * ------------
   * Non-blocking and asyncronous MySQL-Client for MySQL 3.2+ / 4.1+
   * 
   * @class qcEvents_Socket_Client_MySQL
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 01
   * 
   * @todo Add support to change the current user
   **/
  class qcEvents_Stream_MySQL extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* Callback-Types */
    const CALLBACK_FULL   = 0x00;
    const CALLBACK_STATUS = 0x01;
    const CALLBACK_FLAT   = 0x02;
    
    /* Status Flags */
    const STATUS_IN_TRANSACTION       = 0x0001;
    const STATUS_AUTOCOMMIT           = 0x0002;
    const STATUS_MORE_RESULTS         = 0x0008;
    const STATUS_NO_GOOD_INDEXES_USED = 0x0010; // !!
    const STATUS_NO_INDEXES_USED      = 0x0020; // !!
    const STATUS_CURSOR_EXISTS        = 0x0040;
    const STATUS_LAST_ROW_SENT        = 0x0080;
    const STATUS_DB_DROPPED           = 0x0100;
    const STATUS_NO_BACKSLASH_ESCAPES = 0x0200;
    const STATUS_NETADATA_CHANGED     = 0x0400;
    const STATUS_QUERY_WAS_SLOW       = 0x0800;
    const STATUS_PS_OUT_PARAMS        = 0x1000;
    const STAUTS_IN_TRANS_READONLY    = 0x2000;
    const STATUS_SESSION_STATE_CHANGE = 0x4000;
    
    /* Refresh-Types */
    const MYSQL_REFRESH_GRANT   = 0x01;
    const MYSQL_REFRESH_LOG     = 0x02;
    const MYSQL_REFRESH_TABLES  = 0x04;
    const MYSQL_REFRESH_HOSTS   = 0x08;
    const MYSQL_REFRESH_STATUS  = 0x10;
    const MYSQL_REFRESH_THREADS = 0x20;
    const MYSQL_REFRESH_SLAVE   = 0x40;
    const MYSQL_REFRESH_MASTER  = 0x80;
    
    /* Handle of the stream we are attached to */
    private $Stream = null;
    
    /* Username/Password to authenticate with */
    private $Username = 'root';
    private $Password = '';
    
    /* Callbacks to raise once authentication was finished */
    private $authCallbacks = array ();
    
    /* Database we wish to connect to */
    private $Database = null;
    
    /* Callbacks to raise once the pipe is ready */
    private $initCallbacks = array ();
    
    /* Protocol-States */
    const MYSQL_STATE_CONNECTING = 1;		// We are piped with a stream and waiting for server-helo
    const MYSQL_STATE_CONNECTED = 2;		// We have received the helo and may have negotiated SSL (whenever supported by this class)
    const MYSQL_STATE_AUTHENTICATING = 3;	// We have started authentication
    const MYSQL_STATE_AUTHENTICATED = 4;	// We have successfully authenticated and are now in the COMMAND-State of the protocol
    const MYSQL_STATE_DISCONNECTING = 5;
    const MYSQL_STATE_DISCONNECTED = 0;
    
    private $mysqlState = qcEvents_Stream_MySQL::MYSQL_STATE_DISCONNECTED;
    
    /* Protocol-version */
    private $mysqlProtocolVersion = 0;
    
    /* Features / Capabilities */
    const CAPA_LONG_PASSWORD     = 0x00000001;
    const CAPA_FOUND_ROWS        = 0x00000002;
    const CAPA_LONG_FLAG         = 0x00000004;
    const CAPA_CONNECT_WITH_DB   = 0x00000008;
    const CAPA_NO_SCHEMA         = 0x00000010;
    const CAPA_COMPRESS          = 0x00000020;
    const CAPA_LOCAL_FILES       = 0x00000080; # Unimplemented / TODO
    const CAPA_IGNORE_SPACE      = 0x00000100;
    const CAPA_PROTOCOL_41       = 0x00000200;
    const CAPA_INTERACTIVE       = 0x00000400; 
    const CAPA_SSL               = 0x00000800; # Unimplemented / TODO
    const CAPA_IGNORE_SIGPIPE    = 0x00001000;
    const CAPA_TRANSACTIONS      = 0x00002000;
    const CAPA_RESERVED          = 0x00004000;
    const CAPA_SECURE_CONNECTION = 0x00008000;
    
    const CAPA_MULTI_STATEMENTS  = 0x00010000;
    const CAPA_MULTI_RESULTS     = 0x00020000;
    const CAPA_PS_MULTI_RESULTS  = 0x00040000; # Unimplemented (we need the binary protocol and prepared statements for this)
    const CAPA_PLUGIN_AUTH       = 0x00080000;
    const CAPA_CONNECT_ATTRS     = 0x00100000; # Unimplemented / TODO
    const CAPA_PLUGIN_AUTH_LENC  = 0x00200000;
    const CAPA_EXPIRED_PASSWORDS = 0x00400000;
    const CAPA_SESSION_TRACK     = 0x00800000;
    const CAPA_DEPRECATE_EOF     = 0x01000000;
    
    private $mysqlServerCapabilities   = 0x00000000;
    private $mysqlClientCapabilities = null;
    
    /* Informations for authentication */
    private $mysqlAuthMethod = null;
    private $mysqlAuthScramble = array ();
    
    /* Character-Set used on server */
    private $mysqlCharacterset = 0x08;
    
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
    
    // {{{ isBusy
    /**
     * Check if this stream is busy at the moment
     * 
     * @access public
     * @return bool
     **/
    public function isBusy () {
      return ($this->mysqlCommand !== null);
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Perform authentication on this stream
     * 
     * @param string $Username
     * @param string $Password
     * @param string $Database (optional)
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, string $Username, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function authenticate ($Username, $Password, $Database = null, callable $Callback = null, $Private = null) {
      // Make sure the state is right
      if ($this->mysqlState >= $this::MYSQL_STATE_AUTHENTICATING) {
        # TODO: Implement support for COM_CHANGE_USER
        $this->___raiseCallback ($Callback, $Username, false, $Private);
        
        return false;
      }
      
      if ((($this->Username != $Username) || ($this->Password != $Password)) && (count ($this->authCallbacks) > 0)) {
        foreach ($this->authCallbacks as $CB)
          $this->___raiseCallback ($CB [0], $this->Username, null, $CB [1]);
        
        $this->authCallbacks = array ();
      }
      
      // Store the requested values
      $this->Username = $Username;
      $this->Password = $Password;
      $this->Database = $Database;
      $this->authCallbacks [] = array ($Callback, $Private);
      
      // Check if we may begin the authentication
      if ($this->mysqlState != $this::MYSQL_STATE_CONNECTED)
        return;
      
      // Setup capabilities (if not already done)
      if ($this->mysqlClientCapabilities === null)
        $this->mysqlClientCapabilities =
          self::CAPA_LONG_PASSWORD |
          self::CAPA_FOUND_ROWS |
          self::CAPA_IGNORE_SPACE |
          self::CAPA_TRANSACTIONS |
          self::CAPA_SECURE_CONNECTION |
          self::CAPA_PLUGIN_AUTH |
          self::CAPA_PLUGIN_AUTH_LENC |
          self::CAPA_EXPIRED_PASSWORDS |
          self::CAPA_SESSION_TRACK |
          self::CAPA_DEPRECATE_EOF |
          self::CAPA_MULTI_STATEMENTS |
          self::CAPA_MULTI_RESULTS |
          ($this->Database !== null ? self::CAPA_CONNECT_WITH_DB : 0) |
          (extension_loaded ('zlib') && false ? self::CAPA_COMPRESS : 0);
      
      // Make sure we do not use more capabilties than supported by the server
      $this->mysqlClientCapabilities = $this->mysqlClientCapabilities & $this->mysqlServerCapabilities;
      
      // Generate Password for authentication
      if (($authData = $this->mysqlAuthenticate ($this->mysqlAuthScramble, true)) === false)
        return $this->mysqlReceiveAuthentication (0, "\xFF");
      
      // Write out the handshake-response
      if ($this->mysqlServerCapabilities & self::CAPA_PROTOCOL_41) {
        $this->mysqlClientCapabilities = $this->mysqlClientCapabilities | self::CAPA_PROTOCOL_41;
        $mysqlClientCapabilities = $this->mysqlClientCapabilities;
        $this->mysqlClientCapabilities = (($this->mysqlClientCapabilities & ~self::CAPA_COMPRESS));
        $attrs = '';
        
        $this->mysqlWritePacket (
          // Capabiltites
          $this->mysqlWriteInteger ($mysqlClientCapabilities, 4) .
          
          // Max Packet Size
          $this->mysqlWriteInteger (0x01000000, 4) .
          
          // Characterset of this client (UTF-8 by default)
          $this->mysqlWriteInteger (0x21, 1) .
          
          // Some wasted space =)
          "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00" .
          
          // Username for this connection
          $this->mysqlWriteStringNUL ($this->Username) .
          
          // Write out authentication-data
          ($this->mysqlClientCapabilities & self::CAPA_PLUGIN_AUTH_LENC ?
            $this->mysqlWriteStringLenc ($authData, true) :
            ($this->mysqlClientCapabilities & self::CAPA_SECURE_CONNECTION ?
              $this->mysqlWriteStringLenc ($authData) :
              $this->mysqlWriteStringNUL ($authData)
            )
          ) .
          
          // Select database
          ($this->mysqlClientCapabilities & self::CAPA_CONNECT_WITH_DB ? $this->mysqlWriteStringNUL ($this->Database) : '') .
          
          // Write out plugin-name for authenticateion
          ($this->mysqlClientCapabilities & self::CAPA_PLUGIN_AUTH ? $this->mysqlWriteStringNUL ($this->mysqlAuthMethod) : '') .
          
          // Write out connection-attributes
          ($this->mysqlClientCapabilities & self::CAPA_CONNECT_ATTRS ? $attrs : '')
        );
        
        $this->mysqlClientCapabilities = $mysqlClientCapabilities;
      } else
        $this->mysqlWritePacket (
          // Capabiltites
          $this->mysqlWriteInteger ($this->mysqlClientCapabilities, 2) .
          
          // Max Packet Size
          $this->mysqlWriteInteger (0xFF0000, 3) .
          
          // Username for this connection
          $this->mysqlWriteStringNUL ($this->Username) .
          
          ($this->mysqlClientCapabilities & self::CAPA_CONNECT_WITH_DB ?
            $this->mysqlWriteStringNUL ($authData) .   
            $this->mysqlWriteStringNUL ($this->Database) :
            $authData
          )
        );
      
      // Change the state
      $this->mysqlSetProtocolState (self::MYSQL_STATE_AUTHENTICATING);
      
      return true;
    }
    // }}}
    
    // {{{ getDatabase
    /**
     * Retrive the name of the active default database
     * 
     * @access public
     * @return string
     **/
    public function getDatabase () {
      return $this->Database;
    }
    // }}}
    
    // {{{ setDatabase
    /**
     * Set the default database
     * 
     * @param string $Name
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, string $Database, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function setDatabase ($Database, callable $Callback = null, $Private = null) {
      // Check if we are changing anything
      if ($this->Database == $Database)
        return $this->___raiseCallback ($Callback, $Database, true, $Private);
      
      return $this->mysqlEnqueueCommand (0x02, $Database, null, function (qcEvents_Stream_MySQL $Self, $Status) use ($Database, $Callback, $Private) {
        // Store the database
        if ($Status) {
          $this->Database = $Database;
          
          // Fire generic callback
          $this->___callback ('mysqlDatabaseChanged', $Database);
        }
        
        // Fire the user-defined callback with status
        $this->___raiseCallback ($Callback, $Database, $Status, $Private);
      });
    }
    // }}}
    
    // {{{ exec
    /**
     * Execute a MySQL-Statement and only care about if it was successfull
     * 
     * @param string $Query
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, string $Query, bool $Status, int $affectedRows, int $lastInsertID, int $Flags, int $Warnings, mixed $Private = null) { }
     * 
     * @access public
     * @return bool  
     **/
    public function exec ($Query, callable $Callback = null, $Private = null) {
      return $this->dispatchQuery ($Query, 0x00, $Callback, $Private);
    }
    // }}}
    
    // {{{ query
    /**
     * Execute a MySQL-Query and retrive the result at once
     * 
     * @param string $Query
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, string $Query, bool $Status, array $Fields, array $Rows, int $Flags, mixed $Private = null) { }
     * 
     * @access public
     * @return bool  
     **/
    public function query ($Query, callable $Callback, $Private = null) {
      return $this->dispatchQuery ($Query, 0x01, $Callback, $Private);
    }
    // }}}
    
    // {{{ queryAsync
    /**
     * Execute a MySQL-Query and retrive the result one-by-one
     * 
     * @param string $Query
     * @param callable $Callback
     * @param mixed $Private (optional)
     * @param callable $FinalCallback (optional)
     * @param mxied $FinalPrivate (optional)
     * 
     * The per-row callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, array $Fields, array $Row, mixed $Private = null) { }  
     * 
     * The final callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, stirng $Query, bool $Status, int $Flags, mixed $Private = null) { }
     * 
     * @access public
     * @return bool  
     **/
    public function queryAsync ($Query, callable $Callback, $Private = null, callable $FinalCallback = null, $FinalPrivate = null) {
      return $this->dispatchQuery ($Query, 0x02, $FinalCallback, $FinalPrivate, $Callback, $Private);
    }
    // }}}
    
    // {{{ dispatchQuery
    /**
     * Execute a query on the server and handle the response according to the original function-call
     * 
     * @param string $Query The query to run
     * @param enum $Type Type of the original function-call
     * @param callable $FinalCallback (optional) The callback to run once the operation was finished
     * @param mixed $FinalPrivate (optional) Any data to pass to the callback above
     * @param callable $Callback (optional) The callback to run when new data for the active query was received
     * @param mixed $Private (optional) Any data to pass to the callback above
     * 
     * @access private
     * @return bool
     **/
    private function dispatchQuery ($Query, $qType, callable $FinalCallback = null, $FinalPrivate = null, callable $Callback = null, $Private = null) {
      $FieldCount = null;
      $Fields = null;
      $Rows = null;
      
      return $this->mysqlEnqueueCommand (
        0x03,
        $Query,
        null,
        null,
        null,
        null,
        function ($Sequence, $Packet) use ($qType, $Query, &$FieldCount, &$Fields, &$Rows, $FinalCallback, $FinalPrivate, $Callback, $Private) {
          // Check if we reached end of the operation
          $Type = ord ($Packet [0]);
          
          if ((($Type == 0xFE) && ($Rows !== null) && (strlen ($Packet) < 9)) || ($Type == 0x00)) {
            // Gather all required data for the callback
            if ($Type != 0x00) {
              $Data = $this->mysqlReadEof ($Packet);
              $Data ['affectedRows'] = $Data ['lastInsertId'] = null;
            } else
              $Data = $this->mysqlReadOK ($Packet);
            
            // Fire the callback
            if ($qType == 0x00)
              $this->___raiseCallback ($FinalCallback, $Query, true, $Data ['affectedRows'], $Data ['lastInsertId'], $Data ['statusFlags'], $Data ['warnings'], $FinalPrivate);
            elseif ($qType == 0x01)
              $this->___raiseCallback ($FinalCallback, $Query, true, $Fields, $Rows, $Data ['statusFlags'], $FinalPrivate);
            elseif ($qType == 0x02)
              $this->___raiseCallback ($FinalCallback, $Query, true, $Data ['statusFlags'], $FinalPrivate);
            
            // Reset the state
            $FieldCount = null;
            $Fields = null;
            $Rows = null;
            
            return (($Data ['statusFlags'] & self::STATUS_MORE_RESULTS) > 0);
          
          // ... or if there was an error
          } elseif ($Type == 0xFF) {
            if ($qType == 0x00)
              $this->___raiseCallback ($FinalCallback, $Query, false, null, null, null, null, $FinalPrivate);
            elseif ($qType == 0x01)
              $this->___raiseCallback ($FinalCallback, $Query, false, null, null, null, $FinalPrivate);
            elseif ($qType == 0x02)
              $this->___raiseCallback ($FinalCallback, $Query, false, null, $FinalPrivate);
            
            return false;
          }
          
          # TODO: Add Support for LOCAL_INFILE_Request
          
          // Check if we have received the field-count
          if (($FieldCount === null) && ($Fields === null)) {
            $FieldCount = $this->mysqlReadIntegerLenc ($Packet);
            $Fields = array ();
            
            return true;
          }
          
          // Check if we are receiving fields
          if ($FieldCount !== null) {
            // Check if we finished reading fields
            if (($Type == 0xFE) && (strlen ($Packet) < 9)) {
              $FieldCount = null;
              $Rows = array ();
              
              return true;
            }
            
            // Read a new field from packet
            $Fields [] = $this->mysqlReadField ($Packet);
            
            // According to documentation we do not get an EOF in the case they are deprecated
            // http://dev.mysql.com/doc/internals/en/com-query-response.html#packet-ProtocolText::Resultset
            # TODO: Check this behaviour
            if (($this->mysqlClientCapabilities & self::CAPA_DEPRECATE_EOF) && (count ($Fields) == $FieldCount)) {
              $FieldCount = null;
              $Rows = array ();
            }
            
            return true;
          }
          
          // We are receiving a single row
          $Row = array ();
          $c = 0;
          $p = 0;
          $l = strlen ($Packet);
          
          while ($p < $l)
            // Check if the current value is NULL
            if ($Packet [$p] == "\xFB") {
              $Row [$Fields [$c++]['name']] = null;
              $p++;
            } else {
              $Row [$Fields [$c]['name']] = $this->mysqlConvert ($this->mysqlReadStringLenc ($Packet, $p, true), $Fields [$c]['type']);
              $c++;
            }
          
          if ($Callback)
            $this->___raiseCallback ($Callback, $Fields, $Row, $Private);
          else
            $Rows [] = $Row;
        }
      );
    }
    // }}}
    
    // {{{ listFields
    /**
     * Request the fields of a given table
     * 
     * @param string $Table
     * @param string $Wildcard (optional)
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, string $Table, string $Wildcard, array $Fields, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function listFields ($Table, $Wildcard = null, callable $Callback, $Private = null) {
      $Fields = array ();
      
      return $this->mysqlEnqueueCommand (
        0x04,
        $Table . "\x00" . $Wildcard,
        null,
        null,
        null,
        null,
        function ($Sequence, $Packet) use ($Table, $Wildcard, &$Fields, $Callback, $Private) {
          // Check if the list is complete
          $Type = ord ($Packet [0]);
          
          if ((($Type == 0xFE) && (strlen ($Packet) < 9)) || (($Type == 0x00) && ($this->mysqlClientCapabilities & self::CAPA_DEPRECATE_EOF))) {
            $this->___raiseCallback ($Callback, $Table, $Wildcard, $Fields, true, $Private);
            
            return false;
          
          // ... or if there was an error
          } elseif ($Type == 0xFF) {
            $this->___raiseCallback ($Callback, $Table, $Wildcard, null, false, $Private);
            
            return false;
          }
          
          $Fields [] = $this->mysqlReadField ($Packet);
          
          return true;
        }
      );
    }
    // }}}
    
    // {{{ ping
    /**
     * Ping the server
     * 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void  
     **/
    public function ping (callable $Callback, $Private = null) {
      return $this->mysqlEnqueueCommand (0x0E, null, null, $Callback, $Private, self::CALLBACK_STATUS);
    }
    // }}}
    
    // {{{ refresh
    /**
     * Perform a refresh on the server
     * 
     * @param int $What
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function refresh ($What, callable $Callback = null, $Private = null) {
      return $this->mysqlEnqueueCommand (0x07, $this->mysqlWriteInteger ($What, 1), null, $Callback, $Private, self::CALLBACK_STATUS);
    }
    // }}}
    
    // {{{ kill
    /**
     * Kill a connection on the server
     * 
     * @param int $Connection
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function kill ($Connection, callable $Callback = null, $Private = null) {  
      return $this->mysqlEnqueueCommand (0x0C, $this->mysqlWriteInteger ($Connection, 4), null, $Callback, $Private, self::CALLBACK_STATUS);
    }
    // }}}
    
    // {{{ shutdown
    /**
     * Request a server-shutdown
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function shutdown (callable $Callback = null, $Private = null) {
      return $this->mysqlEnqueueCommand (0x08, null, null, $Callback, $Private, self::CALLBACK_STATUS);
    }
    // }}}
    
    // {{{ close
    /**
     * Initiate a server-disconnect
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Check if our stream is already closed
      if (!is_object ($this->Stream) || !$this->Stream->isWatching ()) {
        // Make sure we are in disconnected state
        if ($this->mysqlState != $this::MYSQL_STATE_DISCONNECTED) {
          $this->mysqlSetProtocolState ($this::MYSQL_STATE_DISCONNECTED);
          $this->___callback ('mysqlDisconnected');
        }
        
        // Return resolved promise
        return qcEvents_Promise::resolve ();
      }
      
      // Check if we are in command-state
      if (is_object ($this->Stream) && ($this->mysqlState < $this::MYSQL_STATE_AUTHENTICATED))
        return $this->Stream->close ();
      
      return new qcEvents_Promise (function ($resolve, $reject) {
        return $this->mysqlEnqueueCommand (
          0x01,
          null,
          self::MYSQL_STATE_DISCONNECTING,
          function ($Self, $Status) use ($resolve, $reject) {
            if ($Status)
              $resolve ();
            else
              $reject ('Command failed');
          },
          null,
          self::CALLBACK_STATUS
        );
      });
    } 
    // }}}
    
    
    // {{{ mysqlSetProtocolState
    /**
     * Change the internal status of our protocol
     * 
     * @param enum $State
     *   
     * @access private
     * @return void
     **/
    private function mysqlSetProtocolState ($State) {
      // Check if the state is changed
      if ($State == $this->mysqlState)
        return;
      
      // Remember the old state and set the new one
      $oState = $this->mysqlState;
      $this->mysqlState = $State;
      
      // Let others notice the change
      $this->___callback ('mysqlProtocolStateChanged', $State, $oState);
    }   
    // }}}
    
    // {{{ mysqlEnqueueCommand
    /**
     * Queue a command to be send to the server
     *  
     * @param int $Command
     * @param string $Data (optional)
     * @param enum $newStatus (optional)
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * @param callable $Receiver (optional)
     * @param mixed $ReceiverPrivate (optional)
     * 
     * @access private
     * @return void
     **/
    private function mysqlEnqueueCommand ($Command, $Data = null, $newStatus = null, callable $Callback = null, $Private = null, $CallbackType = self::CALLBACK_FULL, callable $Receiver = null, $ReceiverPrivate = null) {
      // Put the command to the queue
      $this->mysqlCommands [] = array ($Command, $Data, $newStatus, $Callback, $Private, $CallbackType, $Receiver, $ReceiverPrivate);
      
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
      $this->mysqlWritePacket ($this->mysqlWriteInteger ($this->mysqlCommand [0], 1) . ($this->mysqlCommand [1] !== null ? $this->mysqlCommand [1] : ''));
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Stream_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source, callable $Callback = null, $Private = null) {
      // Check if this is really a new stream
      if ($this->Stream === $Source)
        return $this->___raiseCallback ($Callback, false, $Private);
      
      // Check if we have a stream assigned
      if (is_object ($this->Stream))
        $this->Stream->unpipe ($this);
      
      // Reset our state
      $this->Username = null;
      $this->Password = null;
      $this->Database = null;
      
      $this->mysqlBuffer = '';
      $this->mysqlCompressedBuffer = '';
      $this->mysqlSequence = 0;
      $this->mysqlCommand = null;
      $this->mysqlCommands = array ();
      $this->mysqlClientCapabilities = null;
      
      $this->mysqlSetProtocolState ($this::MYSQL_STATE_CONNECTING);
      
      // Register the callback
      $this->initCallbacks [] = array ($Callback, $Private);
      
      // Assign the new stream
      $this->Stream = $Source;
      
      # if (!$Finishing)
      #   $Source->addHook ('eventClosed', function () { });
      
      // Raise an event for this
      $this->___callback ('mysqlConnecting', $Source);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of 
     * 
     *   function (qcEvents_Interface_Stream_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Check if the source is authentic
      if ($this->Stream !== $Source)
        return $this->___raiseCallback ($Callback, false, $Private);
      
      // Remove the stream
      $this->Stream = null;
      
      // Reset our state
      $this->close ();
      
      // Raise the custom callback
      $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      // Make sure the source is authentic
      if ($this->Stream !== $Source)
        return;
      
      // Handle compression
      if ($this->mysqlClientCapabilities & self::CAPA_COMPRESS) {
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
          $oLength = $this->mysqlReadInteger ($this->mysqlCompressedBuffer, 3, $p = 4);
          
          // Retrive the compressed chunk
          if ($oLength > 0)
            $this->mysqlBuffer .= gzuncompress (substr ($this->mysqlCompressedBuffer, 7, $Length));
          else
            $this->mysqlBuffer .= substr ($this->mysqlCompressedBuffer, 7, $Length);
          
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
        $p = 3;
        $Sequence = $this->mysqlReadInteger ($this->mysqlBuffer, 1, $p);
        
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
      
      switch ($this->mysqlState) {
        case $this::MYSQL_STATE_CONNECTING:
          return $this->mysqlReceiveHandshake ($Sequence, $Packet);
        
        case $this::MYSQL_STATE_AUTHENTICATING:
          return $this->mysqlReceiveAuthentication ($Sequence, $Packet);
        
        case $this::MYSQL_STATE_AUTHENTICATED:
        case $this::MYSQL_STATE_DISCONNECTING:
          return $this->mysqlReceiveResponse ($Sequence, $Packet);
        
        case $this::MYSQL_STATE_CONNECTED:
          // We do not expect any input here...
          trigger_error ('Received unexpected data');
          
          return $this->close ();
        case $this::MYSQL_STATE_DISCONNECTED:
          trigger_error ('Received data in disconnected mode, strange!');
          
          return;
      }
    }
    // }}}
    
    // {{{ mysqlReceiveHandshake
    /**
     * Receive Handshake from MySQL-Server
     * 
     * @param int $Sequence
     * @param string $Packet
     * 
     * @access private
     * @return void
     **/
    private function mysqlReceiveHandshake ($Sequence, $Packet) {
      // Check the protocol-version of the server
      $Version = ord ($Packet [0]);   
      $p = 1;
      
      // Check if we support the protocol-version
      if ($Version == 9) {
        // Read the human readable server-version from packet
        if (($sVersion = $this->mysqlReadStringNUL ($Packet, $p, true)) === false)
          return $this->mysqlProtocolError ('Could not read server-version');
        
        // Read the connection-id
        if (($connectionID = $this->mysqlReadInteger ($Packet, 4, $p, true)) === false)
          return $this->mysqlProtocolError ('Could not read connection-id');
        
        // Read Scrambling-Data for authentication
        if (($authScramble = $this->mysqlReadStringNUL ($Packet, $p, true)) === false)
          return $this->mysqlProtocolError ('Could not read auth-plugin-data');
        
        $this->mysqlAuthMethod = null;
        $this->mysqlAuthScramble = array ($authScramble, '');
        
        // Set empty capabilities
        $this->mysqlServerCapabilities = 0x00000000;
      } elseif ($Version == 10) {
        // Read the human readable server-version from packet
        if (($sVersion = $this->mysqlReadStringNUL ($Packet, $p, true)) === false)
          return $this->mysqlProtocolError ('Could not read server-version');
        
        // Read the connection-id
        if (($connectionID = $this->mysqlReadInteger ($Packet, 4, $p, true)) === false)
          return $this->mysqlProtocolError ('Could not read connection-id');
        
        // Read Scrambling-Data for authentication
        if (($authScramble = $this->mysqlReadStringFixed ($Packet, 8, $p, true)) === false)
          return $this->mysqlProtocolError ('Could not read auth-plugin-data');
        
        $this->mysqlAuthScramble = array ($authScramble, '');
        
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
            
            $this->mysqlAuthScramble [1] = $authScrambleExt;
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
        $this->mysqlServerCapabilities = $Capabilties;
        $this->mysqlCharacterset = $charset;
        $this->mysqlAuthMethod = $authName;
        
      // The server does not want us to connect
      } else {
        if ($Version == 0xFF)
          # TODO: This is an ERR-Packet - parse it correctly
          $this->___callback ('mysqlConnectFailed');
        else
          $this->mysqlProtocolError ('Unsupported MySQL-Version');
        
        foreach ($this->initCallbacks as $Callback)
          $this->___raiseCallback ($Callback [0], false, $Callback [1]);
        
        $this->initCallbacks = array ();
        
        return $this->close ();
      }
      
      // Store the protocol-version
      $this->mysqlProtocolVersion = $Version;
      
      # TODO: Check wheter to negotiate SSL here
      # http://dev.mysql.com/doc/internals/en/connection-phase-packets.html#packet-Protocol::SSLRequest
      
      // Set new protocol-state
      $this->mysqlSetProtocolState ($this::MYSQL_STATE_CONNECTED);
      
      // Check wheter to start authentication
      if ($this->Username !== null)
        return $this->authenticate ($this->Username, $this->Password, $this->Database, function (qcEvents_Stream_MySQL $Self, $Username, $Status) {
          foreach ($this->initCallbacks as $Callback)
            $this->___raiseCallback ($Callback [0], $Status, $Callback [1]);
          
          $this->initCallbacks = array ();
          
          if ($Status)
            $this->___callback ('eventPipedStream', $this->Stream);
          else
            $this->close ();
        });
      
      // Fire initial callbacks
      foreach ($this->initCallbacks as $Callback)
        $this->___raiseCallback ($Callback [0], true, $Callback [1]);
      
      $this->initCallbacks = array ();
      $this->___callback ('eventPipedStream', $this->Stream);
    }
    // }}}
    
    // {{{ mysqlReceiveAuthentication
    /**
     * Handle authentication-phase
     * 
     * @param int $Sequence
     * @param string $Packet
     * 
     * @access private
     * @return void
     **/
    private function mysqlReceiveAuthentication ($Sequence, $Packet) {
      // Extract packet-type
      $Type = ord ($Packet [0]);
      
      // Check if the server accepted the authentication
      if ($Type == 0x00) {
        # TODO: Read the full packet
        // Change the state
        $this->mysqlSetProtocolState (self::MYSQL_STATE_AUTHENTICATED);
        
        // Fire all registered callbacks
        foreach ($this->authCallbacks as $CB)
          $this->___raiseCallback ($CB [0], $this->Username, true, $CB [1]);
        
        $this->authCallbacks = array ();
        
        // Fire a callback to tell everyone that we are authenticated now
        $this->___callback ('mysqlAuthenticated', $this->Username);
        
        // Try to issue the first command
        return $this->mysqlIssueCommand ();
      
      // Check for plugin-authentication
      } elseif ($Type == 0xFE) {
        // Process the packet
        if (strlen ($Packet) > 1) {
          $this->mysqlAuthMethod = $this->mysqlReadStringNUL ($Packet, $p = 1, true);
          $Data = substr ($Packet, $p);
        } else {
          $this->mysqlAuthMethod = 'mysql_old_password';
          $Data = null;
        }
        
        // Try to handle the plugin-request
        if (($Data = $this->mysqlAuthenticate ($Data, true)) !== false)
          return $this->mysqlWritePacket ($Data);
      
      // Check if the plugin-auth needs more data
      } elseif (($Type == 0x01) && (($Data = $this->mysqlAuthenticate (substr ($Packet, 1))) !== false))
        return $this->mysqlWritePacket ($Data);
      
      // Fire all registered callbacks
      foreach ($this->authCallbacks as $CB)
        $this->___raiseCallback ($CB [0], $this->Username, false, $CB [1]);
      
      $this->authCallbacks = array ();
      
      // Tell everyone that authentication failed
      $this->___callback ('mysqlAuthenticationFailed', $this->Username);
      
      // Stop trying
      $this->close ();
    }
    // }}}
    
    // {{{ mysqlAuthenticate
    /**
     * Generate an authentication-packet for MySQL-Handshake
     * 
     * @param string $Challenge A challenge received from server
     * @param bool $Reset (optional) Start a new authentication-round
     * 
     * @access private
     * @return void
     **/
    private function mysqlAuthenticate ($Challenge, $Reset = false) {
      if (strlen ($this->Password) == 0)
        return '';
      
      if (is_array ($Challenge))
        $Challenge = implode ('', $Challenge);
      
      if ($this->mysqlAuthMethod === null)
        $this->mysqlAuthMethod = ($this->mysqlClientCapabilities & self::CAPA_SECURE_CONNECTION ? '' : ' mysql_old_password');
      
      switch ($this->mysqlAuthMethod) {
        case 'sha256_password':
          return hash ('sha256', $this->Password ^ $Challenge, true);
        
        case 'mysql_native_password':
          return sha1 ($Challenge . sha1 ($x1 = sha1 ($this->Password, true), true), true) ^ $x1;
        
        case 'mysql_old_password':
          return $this->mysqlScrambleOld (substr ($Challenge, 0, 8), $this->Password);
        
        case 'mysql_clear_password':
          return $this->Password;
        
        case 'authentication_windows_client':
          return false; // !!!!
      }
      
      return false;
    }
    // }}}
    
    // {{{ mysqlReceiveResponse
    /**
     * Process an incoming response from the MySQL-Server
     * 
     * @param int $Sequence
     * @param string $Packet
     * 
     * @access private
     * @return void
     **/
    private function mysqlReceiveResponse ($Sequence, $Packet) {
      // Check if there is a command active
      if ($this->mysqlCommand === null)
        return;
      
      // Check if the current command has it's own receiver
      if ($this->mysqlCommand [6] && (call_user_func ($this->mysqlCommand [6], $Sequence, $Packet, $this->mysqlCommand [7]) !== false))
        return;
      
      // Extract type of packet
      $Type = ord ($Packet [0]);
      
      // Try to parse the rest of the packet
      if (($Type == 0x00) && (($Data = $this->mysqlReadOK ($Packet)) === false))
        return $this->mysqlProtocolError ('Malformed OK-packet received');
      
      elseif (($Type == 0xFE) && (($Data = $this->mysqlReadEof ($Packet)) === false))
        return $this->mysqlProtocolError ('Malformed EOF-packet received');
      
      elseif (($Type == 0xFF) && (($Data = $this->mysqlReadErr ($Packet)) === false))
        return $this->mysqlProtocolError ('Malformed ERR-packet received');
      
      elseif (!is_array ($Data))
        return $this->mysqlProtocolError ('Unknown response received');
      
      // Check wheter to set a new state
      if ($this->mysqlCommand [2] && (($Data ['type'] == 0x00) || ($Data ['type'] == 0xFE)))
        $this->mysqlSetProtoclState ($this->mysqlCommand [2]);
      
      // Move forward
      $currentCommand = $this->mysqlCommand;
      $this->mysqlCommand = null; 
      $this->mysqlIssueCommand ();
      
      // Fire a callback for this
      if ($currentCommand [3])
        switch ($currentCommand [5]) {
          case self::CALLBACK_FLAT:
            return $this->___raiseCallback ($currentCommand [3], $currentCommand [4]);
          
          case self::CALLBACK_STATUS:
            return $this->___raiseCallback ($currentCommand [3], (($Data ['type'] == 0x00) || ($Data ['type'] == 0xFE)), $currentCommand [4]);
          
          case self::CALLBACK_FULL:
          default:
            $this->___raiseCallback ($currentCommand [3], $Data, $currentCommand [4]);
        }
    }
    // }}}
    
    // {{{ mysqlReadOK
    /**
     * Parse an OK-packet from MySQL-server
     * 
     * @param string $Packet
     * 
     * @access private
     * @return array
     **/
    private function mysqlReadOK ($Packet) {
      // Make sure the packet is an OK-Packet
      if ($Packet [0] != "\x00")
        return false;
      
      // Read the initial structure
      $p = 1;
      $rc = array (
        'type'         => 0x00,
        'affectedRows' => $this->mysqlReadIntegerLenc ($Packet, $p, true),
        'lastInsertId' => $this->mysqlReadIntegerLenc ($Packet, $p, true),
        'statusFlags'  => null,
        'warnings'     => null,
        'info'         => null,
      );
      
      // Check if status-flags and/or warnings are to be expected
      if ($this->mysqlClientCapabilities & self::CAPA_PROTOCOL_41) {
        $rc ['statusFlags'] = $this->mysqlReadInteger ($Packet, 2, $p, true);  
        $rc ['warnings'] = $this->mysqlReadInteger ($Packet, 2, $p, true);
      } elseif ($this->mysqlClientCapabilities & self::CAPA_TRANSACTIONS)
        $rc ['statusFlags'] = $this->mysqlReadInteger ($Packet, 2, $p, true);
      
      # TODO: Interpret status-flags as they are quite interesting
      
      // Check wheter to check for session-changes
      if ($this->mysqlClientCapabilities & self::CAPA_SESSION_TRACK) {
        $rc ['info'] = $this->mysqlReadStringLenc ($Packet, $p, true);
        
        if ($rc ['statusFlags'] & self::STATUS_SESSION_STATE_CHANGED) {
          $sessionState = $this->mysqlReadStringLenc ($Packet, $p, true);
          $stateType = ord ($sessionState [0]);
          $data = $this->mysqlReadStringLenc ($sessionState, 1);
          
          unset ($sessionState);
          
          switch ($stateType) {
            case 0x00: // SESSION_TRACK_SYSTEM_VARIABLES - one or more system variables changed
              // Extract the changed variables
              $l = strlen ($data);
              $p = 0;
              $vs = array ();
              
              while ($p < $v) {
                if ((($n = $this->mysqlReadStringLenc ($data, $p, true)) === false) ||
                    (($v = $this->mysqlReadStringLenc ($data, $p, true)) === false))
                  break;
                
                $vs [$n] = $v;
              }
              
              // Fire a callback for this
              $this->___callback ('mysqlVariablesChanged', $vs);
              
              break;
            case 0x01: // SESSION_TRACK_SCHEMA - schema changed
              // Update default database
              $this->Database = $this->mysqlReadStringLenc ($data, 0);
              
              // Inform via callback about the change
              $this->___callback ('mysqlDatabaseChanged', $this->Database);
              
              break;
            case 0x02: // SESSION_TRACK_STATE_CHANGE - "track state change" changed
              // The information is transfered as Lenc-String and in ASCII
              $isTracked = ($this->mysqlReadStringLenc ($data, 0) == 1);
              
              break;
         }
        }
      } else
        $rc ['info'] = substr ($Packet, $p);
      
      return $rc;
    }
    // }}}
    
    // {{{ mysqlReadEof
    /**
     * Parse an EOF-packet from MySQL-Server
     * 
     * @param string $Packet
     * 
     * @access private
     * @return array
     **/
    private function mysqlReadEof ($Packet) {
      // Make sure the is an eof-packet
      if ($Packet [0] != "\xFE")
        return false;
      
      // This packet begins to become interesting on protocol-4.1
      if (!($this->mysqlClientCapabilities & self::CAPA_PROTOCOL_41))
        return array (
          'type'        => 0xFE,
          'warnings'    => null,
          'statusFlags' => null,
        );
      
      // Just parse the packet (as it is not that complex at the moment)
      $p = 1;
      
      return array (
        'type'        => 0xFE,
        'warnings'    => $this->mysqlReadInteger ($Packet, 2, $p),
        'statusFlags' => $this->mysqlReadInteger ($Packet, 2, $p)
      );
    }
    // }}}
    
    // {{{ mysqlReadErr
    /**
     * Parse an ERR-packet from MySQL-Server
     * 
     * @param string $Packet
     * 
     * @access private
     * @return array
     **/
    private function mysqlReadErr ($Packet) {
      // Make sure the is an error-packet
      if ($Packet [0] != "\xFF")
        return false;
      
      // Just parse the packet (as it is not that complex at the moment)
      $rc = array (
        'type'    => 0xFF,
        'code'    => $this->mysqlReadInteger ($Packet, 2, 1),
        'message' => substr ($Packet, 3 + ($this->mysqlClientCapabilities & self::CAPA_PROTOCOL_41 ? 6 : 0)),
        'state'   => ($this->mysqlClientCapabilities & self::CAPA_PROTOCOL_41 ? substr ($Packet, 4, 5): null),
      );
      
      if ($rc ['code'] == 1820)
        $this->___callback ('mysqlPasswordExpired');
      
      return $rc;
    }
    // }}}
    
    // {{{ mysqlConvert
    /**
     * Convert a given string into a native type
     * 
     * @param string $Data
     * @param enum $Type
     * 
     * @access private
     * @return mixed
     **/
    private function mysqlConvert ($Data, $Type) {
      switch ($Type) {
        case 0x00: // MYSQL_TYPE_DECIMAL
        case 0x04: // MYSQL_TYPE_FLOAT
        case 0x05: // MYSQL_TYPE_DOUBLE
        case 0xF6: // MYSQL_TYPE_NEWDECIMA
          return (float)$Data;
        
        case 0x08: // MYSQL_TYPE_LONGLONG (64-bit integer)
          if (PHP_INT_SIZE < 8) 
            return (float)$Data;
        
        case 0x01: // MYSQL_TYPE_TINY
        case 0x02: // MYSQL_TYPE_SHORT
        case 0x03: // MYSQL_TYPE_LONG
        case 0x09: // MYSQL_TYPE_INT24
        case 0x0D: // MYSQL_TYPE_YEAR
        case 0x10: // MYSQL_TYPE_BIT
          return (int)$Data;
        
        case 0x06: // MYSQL_TYPE_NULL
          return null;
        
        case 0x07: // MYSQL_TYPE_TIMESTAMP
        case 0x0A: // MYSQL_TYPE_DATE
        case 0x0B: // MYSQL_TYPE_TIME
        case 0x0C: // MYSQL_TYPE_DATETIME
          return strtotime ($Data);
        
        case 0xF8: // MYSQL_TYPE_SET
          return explode (' ', $Data);
        
        case 0x0F: // MYSQL_TYPE_VARCHAR
        case 0xF7: // MYSQL_TYPE_ENUM
        case 0xF9: // MYSQL_TYPE_TINY_BLOB
        case 0xFA: // MYSQL_TYPE_MEDIUM_BLOB
        case 0xFB: // MYSQL_TYPE_LONG_BLOB
        case 0xFC: // MYSQL_TYPE_BLOB
        case 0xFD: // MYSQL_TYPE_VAR_STRING
        case 0xFE: // MYSQL_TYPE_STRING
        case 0xFF: // MYSQL_TYPE_GEOMETRY
          return $Data;
      }
      
      return $Data;
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
    
    // {{{ mysqlReadField
    /**
     * Parse a Field-Definition from MySQL
     * 
     * @param string $Packet
     * 
     * @access private
     * @return array
     **/
    private function mysqlReadField (&$Packet) {
      $p = 0;
      
      if ($this->mysqlServerCapabilities & self::CAPA_PROTOCOL_41) {
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
    
    // {{{ mysqlWritePacket
    /**
     * Write a mysql-packet to the wire
     * 
     * @param string $Packet
     * 
     * @access private
     * @return void
     **/
    private function mysqlWritePacket ($Packet) {   
      // Remember the original length of the packet
      $l = strlen ($Packet);
      
      // Generate Header
      $Packet = $this->mysqlWriteInteger ($l, 3) . chr ($this->mysqlSequence++) . $Packet;
    
      // Check wheter not to use compression
      if (!($this->mysqlClientCapabilities & self::CAPA_COMPRESS))
        return $this->Stream->write ($Packet);
      
      // Check wheter to compress
      if ($l < 50)
        return $this->Stream->write ($this->mysqlWriteInteger ($l + 4, 3) . chr ($this->mysqlSequence) . "\x00\x00\x00" . $Packet);
    
      // Compress the packet
      $Packet = gzcompress ($Packet);
      
      return $this->Stream->write ($this->mysqlWriteInteger (strlen ($Packet), 3) . chr ($this->mysqlSequence) . $this->mysqlWriteInteger ($l + 4, 3) . $Packet);
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
    
    // {{{ mysqlWriteStringLenc
    /**
     * Convert a stirng into a length-encoded string
     * 
     * @param string $Data
     * @param bool $asLenEnc (optional) Generate a length-encoded integer
     * 
     * @access private
     * @return string
     **/
    private function mysqlWriteStringLenc ($Data, $asLenEnc = false) {
      return ($asLenEnc ? $this->mysqlWriteIntegerLenc (strlen ($Data)) : $this->mysqlWriteInteger (strlen ($Data), 1)) . $Data;
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
    
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param qcEvents_Interface_Stream $Source
     * @param bool $Finishing
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (qcEvents_Interface_Stream $Source) { }
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (qcEvents_Interface_Source $Source) { }
    // }}}
    
    // {{{ mysqlProtocolStateChanged
    /**
     * Callback: The MySQL-Protocol-State was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlProtocolStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ mysqlDatabaseChanged
    /**
     * Callback: The default database was changed
     * 
     * @param string $Database
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlDatabaseChanged ($Database) { }
    // }}}
    
    // {{{ mysqlVariablesChanged
    /**
     * Callback: One or more system-variables were changed
     * 
     * @param array $Variables
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlVariablesChanged ($Variables) { }
    // }}}
    
    // {{{ mysqlPasswordExpired
    /**
     * Callback: The server indicated an expired password - an aware client should renew the password
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlPasswordExpired () { }
    // }}}
    
    // {{{ mysqlConnecting
    /**
     * Callback: An attemp to establish connection to MySQL-Server is made
     * 
     * @param qcEvents_Interface_Stream $Stream
     *  
     * @access protected
     * @return void
     **/
    protected function mysqlConnecting (qcEvents_Interface_Stream $Stream) { }
    // }}}
    
    // {{{ mysqlDisconnected  
    /** 
     * Callback: Connection to MySQL-Server was closed
     * 
     * @access protected
     * @return void
     **/  
    protected function mysqlDisconnected () { }
    // }}}
    
    // {{{ mysqlAuthenticated
    /**
     * Callback: The MySQL-Connection was authenticated
     * 
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlAuthenticated ($Username) { }
    // }}}
    
    // {{{ mysqlAuthenticationFailed
    /**
     * Callback: Authentication failed
     * 
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlAuthenticationFailed ($Username) { }
    // }}}
  }

?>