<?PHP

  /**
   * qcEvents - SSH Channel Open Message
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Abstract/Source.php');
  require_once ('qcEvents/Interface/Stream.php');
  require_once ('qcEvents/Stream/SSH/ChannelRequest.php');
  
  class qcEvents_Stream_SSH_Channel extends qcEvents_Abstract_Source implements qcEvents_Interface_Stream {
    /* Instance of stream this channel is hosted on */
    private $Stream = null;
    
    /* Type of this channel */
    const TYPE_SESSION = 'session';
    const TYPE_X11 = 'x11';
    const TYPE_TCP_DIRECT = 'direct-tcpip';
    const TYPE_TCP_FORWARDED = 'forwarded-tcpip';
    
    private $Type = null;
    
    /* Local ID of this channel */
    private $localID = null;
    
    /* Local Connection-Information on forwarded connections */
    private $localAddress = null;
    private $localPort = null;
    
    /* Remote ID of this channel */
    private $remoteID = null;
    
    /* Remaining window-size to send data */
    private $remoteWindowSize = null;
    
    /* Maximum size of data-packets to send */
    private $remoteMaximumPacketSize = null;
    
    /* Remote Connection-Information on forwarded connections */
    private $remoteAddress = null;
    private $remotePort = null;
    
    /* Stream for stderr (extended data) */
    private $stdErr = null;
    
    /* Queue for channel-requests that want a reply */
    private $Requests = array ();
    
    /* Watch for write-events */
    private $watchWrite = false;
    
    /* We don't want any further write's */
    private $isEOF = false;
    
    /* Check if we are trying to close the channel */
    private $isClosing = false;
    
    /* Connection-Promise */
    private $ConnectionPromise = null;
    
    // {{{ __construct
    /**
     * Create a new SSH-Channel
     * 
     * @param qcEvents_Stream_SSH $Stream
     * @param int $localID
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Stream_SSH $Stream, $localID, $Type) {
      // Store initial settings
      $this->Stream = $Stream;
      $this->Type = $Type;
      $this->localID = $localID;
      
      // Create a connection-promise
      $this->ConnectionPromise = array (null);
      $this->ConnectionPromise [0] = new qcEvents_Promise (function (callable $Resolve, callable $Reject) {
        // Check for a race-condition
        if ($this->remoteID === -1)
          return call_user_func ($Reject, 'Unknown reason (race condition)');
        
        if ($this->remoteID !== null)
          return call_user_func ($Resolve , $this);
        
        // Store the callbacks
        $this->ConnectionPromise [1] = $Resolve;
        $this->ConnectionPromise [2] = $Reject;
      });
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Prepare nicer output for var_dump()
     * 
     * @access friendly
     * @return array
     **/
    function __debugInfo () {
      $Info = array (
        'type' => $this->Type,
        'localID' => $this->localID,
        'remoteID' => $this->remoteID,
      );
      
      if (($this->Type == 'direct-tcpip') ||
          ($this->Type == 'forwarded-tcpip')) {
        $Info ['localAddress'] = $this->localAddress;
        $Info ['localPort'] = $this->localPort;
        $Info ['remoteAddress'] = $this->remoteAddress;
        $Info ['remotePort'] = $this->remotePort;
      }
      
      return $Info;
    }
    // }}}
    
    // {{{ getStream
    /**
     * Retrive the instance of the SSH-Stream this channel is hosted on
     * 
     * @access public
     * @return qcEvents_Stream_SSH
     **/
    public function getStream () : qcEvents_Stream_SSH {
      return $this->Stream;
    }
    // }}}
    
    // {{{ getType
    /**
     * Retrive the type of this channel
     * 
     * @access public
     * @return string
     **/
    public function getType () {
      return $this->Type;
    }
    // }}}
    
    // {{{ getLocalID
    /**
     * Retrive the local id of this channel
     * 
     * @access public
     * @return int
     **/
    public function getLocalID () {
      return $this->localID;
    }
    // }}}
    
    // {{{ getConnectionPromise
    /**
     * Retrive a promise to be resolved once this channel was established
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getConnectionPromise () : qcEvents_Promise {
      return $this->ConnectionPromise [0];
    }
    // }}}
    
    // {{{ getStdErr
    /**
     * Retrive stream for stderr-data
     * 
     * @access public
     * @return qcEvents_Interface_Source
     **/
    public function getStdErr () : qcEvents_Interface_Source {
      if (!$this->stdErr)
        $this->stdErr = new qcEvents_Abstract_Source;
      
      return $this->stdErr;
    }
    // }}}
    
    // {{{ setEnv
    /**
     * Set an environment-variable on this channel
     * 
     * @param string $Name
     * @param string $Value
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function setEnv ($Name, $Value) : qcEvents_Promise {
      if ($this->Type != 'session')
        return qcEvents_Promise::reject ('Not a session-channel');
      
      $Message = new qcEvents_Stream_SSH_ChannelRequest;
      $Message->RecipientChannel = $this->remoteID;
      $Message->Type = 'env';
      $Message->wantReply = false;
      $Message->envName = $Name;
      $Message->envValue = $Value;
      
      return $this->Stream->writeMessage ($Message);
    }
    // }}}
    
    // {{{ shell
    /**
     * Request a shell for this channel
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function shell () : qcEvents_Promise {
      if ($this->Type != 'session')
        return qcEvents_Promise::reject ('Not a session-channel');
      
      $Message = new qcEvents_Stream_SSH_ChannelRequest;
      $Message->RecipientChannel = $this->remoteID;
      $Message->Type = 'shell';
      
      return $this->wantReply ($Message);
    }
    // }}}
    
    // {{{ exec
    /**
     * Request execution of a command for this channel
     * 
     * @param string $Command
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function exec ($Command) : qcEvents_Promise {
      if ($this->Type != 'session')
        return qcEvents_Promise::reject ('Not a session-channel');
      
      $Message = new qcEvents_Stream_SSH_ChannelRequest;
      $Message->RecipientChannel = $this->remoteID;
      $Message->Type = 'exec';
      $Message->Command = $Command;
      
      return $this->wantReply ($Message);
    }
    // }}}
    
    // {{{ requestSubsystem
    /**
     * Request a subsystem for this channel
     * 
     * @param string $Name
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function requestSubsystem ($Name) : qcEvents_Promise {
      if ($this->Type != 'session')
        return qcEvents_Promise::reject ('Not a session-channel');
      
      $Message = new qcEvents_Stream_SSH_ChannelRequest;
      $Message->RecipientChannel = $this->remoteID;
      $Message->Type = 'subsystem';
      $Message->Command = $Name;
      
      return $this->wantReply ($Message);
    }
    // }}}
    
    // {{{ wantReply
    /**
     * Write out a channel-request and expect a reply for it
     * 
     * @param qcEvents_Stream_SSH_ChannelRequest $Message
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function wantReply (qcEvents_Stream_SSH_ChannelRequest $Message) : qcEvents_Promise {
      return new qcEvents_Promise (
        function (callable $Resolve, callable $Reject) use ($Message) {
          // Make sure the reply-bit is set
          $Message->wantReply = true;
          
          // Push to queue
          $this->Requests [] = array ($Message, $Resolve, $Reject);
          
          // Check wheter to write out the request
          if (count ($this->Requests) == 1)
            $this->Stream->writeMessage ($Message)->catch ($Reject);
        }
      );
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
      $this->write ($Data);
    }
    // }}}
    
    // {{{ write
    /**
     * Write data to this sink
     * 
     * @param string $Data The data to write to this sink
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function write ($Data) : qcEvents_Promise {
      // Make sure this channel is connected
      if (($this->remoteID === null) ||
          !$this->Stream)
        return qcEvents_Promise::reject ('Channel not connected');
      
      if ($this->isEOF)
        return qcEvents_Promise::reject ('Channel already closed on sending side');
      
      // Encapsulate data
      $Message = new qcEvents_Stream_SSH_ChannelData;
      $Message->RecipientChannel = $this->remoteID;
      $Message->Data = $Data;
      
      // Write out the data
      return $this->Stream->writeMessage ($Message);
    }
    // }}}
    
    // {{{ watchWrite
    /**
     * Set/Retrive the current event-watching status
     * 
     * @param bool $Set (optional) Set the status
     *  
     * @access public
     * @return bool
     **/
    public function watchWrite ($Set = null) {
      // Check wheter just to return our status
      if ($Set === null)
        return ($this->watchWrite != false);
      
      // Retrive the source-stream of our SSH-Stream
      if (!is_object ($Stream = $this->Stream->getStream ()) ||
          $this->isEOF)
        return null;
      
      // Check for changes
      if ($this->watchWrite && !$Set) {
        $Stream->getStream ()->removeHook ('eventWriteable', $this->watchWrite);
        
        if (count ($Stream->getHooks ('eventWriteable')) == 0)
          $Stream->watchWrite (false);
        
        return false;
      } elseif ($Set == ($this->watchWrite != false))
        return null;
      
      $this->watchWrite = function () {
        # TODO: Check for window-size
        
        // Raise the callback here
        $this->___callback ('eventWriteable');
      };
      
      $Stream->addHook ('eventWriteable', $this->watchWrite);
      $Stream->watchWrite (true);
      
    }
    // }}}
    
    // {{{ eof
    /**
     * Signal end of stream from our side
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function eof () : qcEvents_Promise {
      // Check if we are already there
      if ($this->isEOF)
        return qcEvents_Promise::resolve ();
      
      // Mark ourself as EOF
      $this->isEOF = true;
      
      // Signal EOF
      $Message = new qcEvents_Stream_SSH_ChannelEnd;
      $Message->RecipientChannel = $this->remoteID;
        
      return $this->Stream->writeMessage ($Message);
    }
    // }}}
    
    // {{{ close
    /**
     * Try to close this channel
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Check if we are already trying to close the channel
      if (!$this->isClosing) {
        // Mark ourself as closing
        $this->isClosing = true;
        
        // Request close of the channel
        $Message = new qcEvents_Stream_SSH_ChannelClose;
        $Message->RecipientChannel = $this->remoteID;
        
        $this->Stream->writeMessage ($Message);
      }
      
      // Wait for the channel to be closed
      return $this->once ('eventClosed');
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     *  
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      if ($Callback)
        $this->___raiseCallback ($Callback, true, $Private);
      
      $this->___callback ('eventPiped', $Source);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ receiveMessage
    /**
     * Receive a message from our SSH-Stream
     * 
     * @param qcEvents_Stream_SSH_Message $Message
     * 
     * @access public
     * @return void
     **/
    public function receiveMessage (qcEvents_Stream_SSH_Message $Message) {
      // Check if a pending channel was confirmed
      if (($Message instanceof qcEvents_Stream_SSH_ChannelOpen) ||
          ($Message instanceof qcEvents_Stream_SSH_ChannelConfirmation)) {
        // Make sure this doesn't happen more than once
        if ($this->remoteID !== null)
          return trigger_error ('Duplicate channel-open-confirmation received');
        
        // Assign the values
        $this->remoteID = $Message->SenderChannel;
        $this->remoteWindowSize = $Message->InitialWindowSize;
        $this->remoteMaximumPacketSize = $Message->MaximumPacketSize;
        
        if (($Message instanceof qcEvents_Stream_SSH_ChannelOpen) &&
            (($Message->Type == 'forwarded-tcpip') || ($Message->Type == 'direct-tcpip'))) {
          $this->localAddress = $Message->DestinationAddress;
          $this->localPort = $Message->DestinationPort;
          $this->remoteAddress = $Message->OriginatorAddress;
          $this->remotePort = $Message->OriginatorPort;
        }
        
        // Try to resolve the promise
        if (isset ($this->ConnectionPromise [1])) {
          $Resolve = $this->ConnectionPromise [1];
          $this->ConnectionPromise = array ($this->ConnectionPromise [0]);
          
          call_user_func ($Resolve, $this);
        }
        
      // Check if a pending channel was rejected
      } elseif ($Message instanceof qcEvents_Stream_SSH_ChannelRejection) {
        // Make sure we don't reject an established channel
        if ($this->remoteID !== null)
          return trigger_error ('Duplicate channel-open-confirmation received');
        
        // Mark the channel as invalid
        $this->remoteID = -1;
        
        // Remove this channel
        $this->Stream->removeChannel ($this);
        
        // Try to resolve the promise
        if (isset ($this->ConnectionPromise [2])) {
          $Resolve = $this->ConnectionPromise [2];
          $this->ConnectionPromise = array ($this->ConnectionPromise [0]);
          
          call_user_func ($Resolve, $Message->Code, $Message->Reason);
        }
      
      // Receive-Window-Should be adjusted
      } elseif ($Message instanceof qcEvents_Stream_SSH_ChannelWindowAdjust) {
        $this->remoteWindowSize += $Message->bytesToAdd;
      
      // Receive data from remote side
      } elseif ($Message instanceof qcEvents_Stream_SSH_ChannelData) {
        # TODO: Check Window
        # TODO: Check Length-Constraints
        
        $this->sourceInsert ($Message->Data);
        
      } elseif ($Message instanceof qcEvents_Stream_SSH_ChannelExtendedData) {
        if (($Message->Type == $Message::TYPE_STDERR) && $this->stdErr)
          $this->stdErr->sourceInsert ($Message->Data);
      
      // Recieve a channel-EOF
      } elseif ($Message instanceof qcEvents_Stream_SSH_ChannelEnd) {
        // Ignored, just forward as event
        $this->___callback ('channelEnd');
      
      // Close the remote end of the channel
      } elseif ($Message instanceof qcEvents_Stream_SSH_ChannelClose) {
        // Check if we should close as well
        $this->close ();
        
        // Mark the stream as closed (and raise events for this)
        parent::close ();
        
        // Remove this channel from our parent
        $this->Stream->removeChannel ($this);
        
        // Shutdown stderr
        if ($this->stdErr) {
          $this->stdErr->close ();
          $this->stdErr->removeHooks ();
          $this->stdErr = null;
        }
      
      // Process channel-requests
      } elseif ($Message instanceof qcEvents_Stream_SSH_ChannelRequest) {
        if (($Message->Type == 'pty-req') ||
            ($Message->Type == 'x11-req') ||
            ($Message->Type == 'env') ||
            ($Message->Type == 'shell') ||
            ($Message->Type == 'exec') ||
            ($Message->Type == 'subsystem') ||
            ($Message->Type == 'window-change') ||
            ($Message->Type == 'xon-xoff') ||
            ($Message->Type == 'signal'))
          $Result = false; // Unsupported
        
        // Process Command-Exit
        elseif ($Message->Type == 'exit-status')
          $this->commandStatus = $Message->Status;
        elseif ($Message->Type == 'exit-signal') {
          if ($this->commandStatus === null)
            $this->commandStatus = 0xff;
          
          $this->commandSignal = $Message->Signal;
          
          # $Message->CoreDumped
          # $Message->errorMessage / $Message->errorLanguage
          
          $Result = true;
        } else
          $Result = false;
        
        if ($Message->wantReply) {
          $Reply = ($Result ? new qcEvents_Stream_SSH_ChannelSuccess : new qcEvents_Stream_SSH_ChannelFailure);
          $Reply->RecipientChannel = $this->remoteID;
          
          $this->Stream->writeMessage ($Reply);
        }
      
      // Last pending request was successfull or not
      } elseif (($Message instanceof qcEvents_Stream_SSH_ChannelSuccess) ||
                ($Message instanceof qcEvents_Stream_SSH_ChannelFailure)) {
        // Make sure there is a request pending at all
        if (count ($this->Requests) < 1)
          return trigger_error ('Received reply for a channel-request without pending request');
        
        // Get the request from queue
        $RequestInfo = array_shift ($this->Requests);
        
        // Resolve or reject the promise
        call_user_func ($RequestInfo [($Message instanceof qcEvents_Stream_SSH_ChannelSuccess ? 1 : 2)]);
        
        // Check wheter to write out the next request
        if (count ($this->Requests) > 0)
          $this->Stream->writeMessage ($this->Requests [0][0])->catch ($this->Requests [0][2]);
        
      }
    }
    // }}}
    
    
    // {{{ eventWritable
    /**
     * Callback: A writable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventWritable () { }
    // }}}
    
    // {{{ eventPiped
    /**
     * Callback: A source was attached to this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPiped (qcEvents_Interface_Source $Source) { }
    // }}}
    
    // {{{ channelEnd
    /**
     * Callback: Channel-End-Message was received
     * 
     * @access protected
     * @return void
     **/
    protected function channelEnd () { }
    // }}}
  }

?>