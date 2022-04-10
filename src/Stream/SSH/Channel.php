<?php

  /**
   * qcEvents - SSH Channel Open Message
   * Copyright (C) 2019-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream\SSH;
  use \quarxConnect\Events;
  
  class Channel extends Events\Virtual\Source implements Events\ABI\Stream {
    public const DEFAULT_WINDOW_SIZE = 2097152;       //  2 MB
    public const DEFAULT_MAXIMUM_PACKET_SIZE = 32768; // 32 KB
    
    /* Instance of stream this channel is hosted on */
    private $sshStream = null;
    
    /* Type of this channel */
    public const TYPE_SESSION = 'session';
    public const TYPE_X11 = 'x11';
    public const TYPE_TCP_DIRECT = 'direct-tcpip';
    public const TYPE_TCP_FORWARDED = 'forwarded-tcpip';
    
    private $channelType = null;
    
    /* Local ID of this channel */
    private $localID = null;
    
    /* Current allowed window-size */
    private $localWindowSize = null;
    
    /* Maximum size of data-packets to receive */
    private $localMaximumPacketSize = null;
    
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
    private $channelRequests = [ ];
    
    /* Watch for write-events */
    private $watchWrite = false;
    
    /* We don't want any further write's */
    private $isEOF = false;
    
    /* Check if we are trying to close the channel */
    private $isClosing = false;
    
    /* Status of command that was executed on this channel */
    private $commandStatus = null;
    
    /* Connection-Promise */
    private $connectionPromise = null;
    
    // {{{ __construct
    /**
     * Create a new SSH-Channel
     * 
     * @param Events\Stream\SSH $sshStream
     * @param int $localID
     * @param string $channelType
     * @param int $connectionTimeout (optional) Reject the channel when it takes longer than this to establish
     * @param int $windowSize (optional)
     * @param int $maximumPacketSize (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Stream\SSH $sshStream, int $localID, string $channelType, int $connectionTimeout = null, int $windowSize = self::DEFAULT_WINDOW_SIZE, int $maximumPacketSize = self::DEFAULT_MAXIMUM_PACKET_SIZE) {
      // Store initial settings
      $this->sshStream = $sshStream;
      $this->channelType = $channelType;
      $this->localID = $localID;
      $this->localWindowSize = $windowSize;
      $this->localMaximumPacketSize = $maximumPacketSize;
      
      // Create a connection-promise
      $this->connectionPromise = new Events\Promise\Defered ();
      
      // Enqueue connection-timeout
      if (
        ($connectionTimeout > 0) &&
        ($sshStream = $this->sshStream->getStream ()) &&
        ($eventBase = $sshStream->getEventBase ())
      )
        $eventBase->addTimeout ($connectionTimeout)->then (
          function () {
            // Reject the channel
            $this->connectionPromise->reject ('Timeout');
          }
        );
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Prepare nicer output for var_dump()
     * 
     * @access friendly
     * @return array
     **/
    function __debugInfo () : array {
      $debugInfo = [
        'type' => $this->channelType,
        'localID' => $this->localID,
        'remoteID' => $this->remoteID,
      ];
      
      if (
        ($this->channelType == self::TYPE_TCP_DIRECT) ||
        ($this->channelType == self::TYPE_TCP_FORWARDED)
      ) {
        $debugInfo ['localAddress'] = $this->localAddress;
        $debugInfo ['localPort'] = $this->localPort;
        $debugInfo ['remoteAddress'] = $this->remoteAddress;
        $debugInfo ['remotePort'] = $this->remotePort;
      }
      
      return $debugInfo;
    }
    // }}}
    
    // {{{ getStream
    /**
     * Retrive the instance of the SSH-Stream this channel is hosted on
     * 
     * @access public
     * @return Events\Stream\SSH
     **/
    public function getStream () : Events\Stream\SSH {
      return $this->sshStream;
    }
    // }}}
    
    // {{{ getType
    /**
     * Retrive the type of this channel
     * 
     * @access public
     * @return string
     **/
    public function getType () : string {
      return $this->channelType;
    }
    // }}}
    
    // {{{ getLocalID
    /**
     * Retrive the local id of this channel
     * 
     * @access public
     * @return int
     **/
    public function getLocalID () : int {
      return $this->localID;
    }
    // }}}
    
    // {{{ getConnectionPromise
    /**
     * Retrive a promise to be resolved once this channel was established
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getConnectionPromise () : Events\Promise {
      return $this->connectionPromise->getPromise ();
    }
    // }}}
    
    // {{{ getStdErr
    /**
     * Retrive stream for stderr-data
     * 
     * @access public
     * @return Events\ABI\Source
     **/
    public function getStdErr () : Events\ABI\Source {
      if (!$this->stdErr)
        $this->stdErr = new Events\Virtual\Source;
      
      return $this->stdErr;
    }
    // }}}
    
    // {{{ setEnv
    /**
     * Set an environment-variable on this channel
     * 
     * @param string $variableName
     * @param string $variableValue
     * 
     * @access public
     * @return Events\Promise
     **/
    public function setEnv (string $variableName, string $variableValue) : Events\Promise {
      if ($this->channelType != self::TYPE_SESSION)
        return Events\Promise::reject ('Not a session-channel');
      
      $sshMessage = new ChannelRequest ();
      $sshMessage->RecipientChannel = $this->remoteID;
      $sshMessage->Type = 'env';
      $sshMessage->wantReply = false;
      $sshMessage->envName = $variableName;
      $sshMessage->envValue = $variableValue;
      
      return $this->sshStream->writeMessage ($sshMessage);
    }
    // }}}
    
    // {{{ shell
    /**
     * Request a shell for this channel
     * 
     * @access public
     * @return Events\Promise
     **/
    public function shell () : Events\Promise {
      if ($this->channelType != self::TYPE_SESSION)
        return Events\Promise::reject ('Not a session-channel');
      
      $sshMessage = new ChannelRequest ();
      $sshMessage->RecipientChannel = $this->remoteID;
      $sshMessage->Type = 'shell';
      
      return $this->wantReply ($sshMessage);
    }
    // }}}
    
    // {{{ exec
    /**
     * Request execution of a command for this channel
     * 
     * @param string $commandLine
     * 
     * @access public
     * @return Events\Promise
     **/
    public function exec (string $commandLine) : Events\Promise {
      if ($this->channelType != self::TYPE_SESSION)
        return Events\Promise::reject ('Not a session-channel');
      
      $sshMessage = new ChannelRequest ();
      $sshMessage->RecipientChannel = $this->remoteID;
      $sshMessage->Type = 'exec';
      $sshMessage->Command = $commandLine;
      
      return $this->wantReply ($sshMessage);
    }
    // }}}
    
    // {{{ requestSubsystem
    /**
     * Request a subsystem for this channel
     * 
     * @param string $subsystemName
     * 
     * @access public
     * @return Events\Promise
     **/
    public function requestSubsystem (string $subsystemName) : Events\Promise {
      if ($this->channelType != self::TYPE_SESSION)
        return Events\Promise::reject ('Not a session-channel');
      
      $sshMessage = new ChannelRequest ();
      $ssMessage->RecipientChannel = $this->remoteID;
      $sshMessage->Type = 'subsystem';
      $sshMessage->Command = $subsystemName;
      
      return $this->wantReply ($sshMessage);
    }
    // }}}
    
    // {{{ getCommandStatus
    /**
     * Retrive the exit-status of the command executed on this channel (if any)
     * 
     * @access public
     * @return int
     **/
    public function getCommandStatus () : ?int {
      return $this->commandStatus;
    }
    // }}}
    
    // {{{ wantReply
    /**
     * Write out a channel-request and expect a reply for it
     * 
     * @param ChannelRequest $sshMessage
     * 
     * @access private
     * @return Events\Promise
     **/
    private function wantReply (ChannelRequest $sshMessage) : Events\Promise {
      // Create defered promise
      $deferedPromise = new Events\Promise\Defered ();
      
      // Make sure the reply-bit is set
      $sshMessage->wantReply = true;
      
      // Push to queue
      $this->channelRequests [] = [ $sshMessage, $deferedPromise ];
      
      // Check wheter to write out the request
      if (count ($this->channelRequests) == 1)
        $this->sshStream->writeMessage ($sshMessage)->catch ([ $deferedPromise, 'reject' ]);
      
      // Return the promise
      return $deferedPromise->getPromise ();
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $channelData
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return void
     **/
    public function consume ($channelData, Events\ABI\Source $sourceStream) {
      $this->write ($channelData);
    }
    // }}}
    
    // {{{ write
    /**
     * Write data to this sink
     * 
     * @param string $channelData The data to write to this sink
     * 
     * @access public
     * @return Events\Promise
     **/
    public function write (string $channelData) : Events\Promise {
      // Make sure this channel is connected
      if (
        ($this->remoteID === null) ||
        !$this->sshStream
      )
        return Events\Promise::reject ('Channel not connected');
      
      if ($this->isEOF)
        return Events\Promise::reject ('Channel already closed on sending side');
      
      // Encapsulate data
      $sshMessage = new ChannelData ();
      $sshMessage->RecipientChannel = $this->remoteID;
      $sshMessage->Data = $channelData;
      
      // Write out the data
      return $this->sshStream->writeMessage ($sshMessage);
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
    public function watchWrite ($setState = null) : bool {
      // Check wheter just to return our status
      if ($setState === null)
        return ($this->watchWrite != false);
      
      // Retrive the source-stream of our SSH-Stream
      if (
        !is_object ($sourceStream = $this->sshStream->getStream ()) ||
        $this->isEOF
      )
        return false;
      
      // Check for changes
      if ($this->watchWrite && !$setState) {
        $sourceStream->getStream ()->removeHook ('eventWriteable', $this->watchWrite);
        
        if (count ($sourceStream->getHooks ('eventWriteable')) == 0)
          $sourceStream->watchWrite (false);
        
        return false;
      } elseif ($setState == ($this->watchWrite != false))
        return ($this->watchWrite != false);
      
      $this->watchWrite = function () {
        # TODO: Check for window-size
        
        // Raise the callback here
        $this->___callback ('eventWriteable');
      };
      
      $sourceStream->addHook ('eventWriteable', $this->watchWrite);
      $sourceStream->watchWrite (true);
    }
    // }}}
    
    // {{{ eof
    /**
     * Signal end of stream from our side
     * 
     * @access public
     * @return Events\Promise
     **/
    public function eof () : Events\Promise {
      // Check if we are already there
      if ($this->isEOF)
        return Events\Promise::resolve ();
      
      // Mark ourself as EOF
      $this->isEOF = true;
      
      if ($this->watchWrite)
        $this->watchWrite (false);
      
      // Signal EOF
      $sshMessage = new ChannelEnd ();
      $sshMessage->RecipientChannel = $this->remoteID;
        
      return $this->sshStream->writeMessage ($sshMessage);
    }
    // }}}
    
    // {{{ close
    /**
     * Try to close this channel
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      // Check if we are already trying to close the channel
      if (!$this->isClosing) {
        // Mark ourself as closing
        $this->isClosing = true;
        
        // Request close of the channel
        $sshMessage = new ChannelClose ();
        $sshMessage->RecipientChannel = $this->remoteID;
        
        $this->sshStream->writeMessage ($sshMessage);
      }
      
      // Wait for the channel to be closed
      return $this->once ('eventClosed');
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initConsumer (Events\ABI\Source $sourceStream) : Events\Promise {
      $this->___callback ('eventPiped', $sourceStream);
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\ABI\Source $sourceStream) : Events\Promise {
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ receiveMessage
    /**
     * Receive a message from our SSH-Stream
     * 
     * @param Message $sshMessage
     * 
     * @access public
     * @return void
     **/
    public function receiveMessage (Message $sshMessage) : void {
      // Check if a pending channel was confirmed
      if (
        ($sshMessage instanceof ChannelOpen) ||
        ($sshMessage instanceof ChannelConfirmation)
      ) {
        // Make sure this doesn't happen more than once
        if ($this->remoteID !== null) {
          trigger_error ('Duplicate channel-open-confirmation received');
          
          return;
        }
        
        // Assign the values
        $this->remoteID = $sshMessage->SenderChannel;
        $this->remoteWindowSize = $sshMessage->InitialWindowSize;
        $this->remoteMaximumPacketSize = $sshMessage->MaximumPacketSize;
        
        if (
          ($sshMessage instanceof ChannelOpen) &&
          (($sshMessage->Type == 'forwarded-tcpip') || ($sshMessage->Type == 'direct-tcpip'))
        ) {
          $this->localAddress = $sshMessage->DestinationAddress;
          $this->localPort = $sshMessage->DestinationPort;
          $this->remoteAddress = $sshMessage->OriginatorAddress;
          $this->remotePort = $sshMessage->OriginatorPort;
        }
        
        // Resolve the promise
        $this->connectionPromise->resolve ($this);
        
      // Check if a pending channel was rejected
      } elseif ($sshMessage instanceof ChannelRejection) {
        // Make sure we don't reject an established channel
        if ($this->remoteID !== null) {
          trigger_error ('Duplicate channel-open-confirmation received');
          
          return;
        }
        
        // Mark the channel as invalid
        $this->remoteID = -1;
        
        // Remove this channel
        $this->sshStream->removeChannel ($this);
        
        // Try to reject the promise
        $this->connectionPromise->reject ($sshMessage->Reason, $Message);
      
      // Receive-Window-Should be adjusted
      } elseif ($sshMessage instanceof ChannelWindowAdjust) {
        $this->remoteWindowSize += $sshMessage->bytesToAdd;
      
      // Receive data from remote side
      } elseif (
        ($sshMessage instanceof ChannelData) ||
        ($sshMessage instanceof ChannelExtendedData)
      ) {
        // Check if the window is large enough to receive this message
        $dataLength = strlen ($sshMessage->Data);
        
        if ($this->localWindowSize < $dataLength)
          # TODO
          return;
        
        // Remove the received bytes from the window
        $this->localWindowSize -= $dataLength;
        
        // Forward the data
        if ($sshMessage instanceof ChannelData)
          $this->sourceInsert ($sshMessage->Data);
        elseif (($sshMessage->Type == $sshMessage::TYPE_STDERR) && $this->stdErr)
          $this->stdErr->sourceInsert ($sshMessage->Data);
        
        // Check wheter to adjust the window
        if ($this->localWindowSize <= $this->localMaximumPacketSize) {
          $sshReply = new ChannelWindowAdjust ();
          $sshReply->RecipientChannel = $this->remoteID;
          $this->localWindowSize += $sshReply->bytesToAdd = self::DEFAULT_WINDOW_SIZE; # TODO: Static value
          
          $this->sshStream->writeMessage ($sshReply);
        }
        
      // Recieve a channel-EOF
      } elseif ($sshMessage instanceof ChannelEnd) {
        // Ignored, just forward as event
        $this->___callback ('channelEnd');
      
      // Close the remote end of the channel
      } elseif ($sshMessage instanceof ChannelClose) {
        // Check if we should close as well
        $this->close ();
        
        // Mark the stream as closed (and raise events for this)
        parent::close ();
        
        // Remove this channel from our parent
        $this->sshStream->removeChannel ($this);
        
        // Shutdown stderr
        if ($this->stdErr) {
          $this->stdErr->close ();
          $this->stdErr->removeHooks ();
          $this->stdErr = null;
        }
      
      // Process channel-requests
      } elseif ($sshMessage instanceof ChannelRequest) {
        if (
          ($sshMessage->Type == 'pty-req') ||
          ($sshMessage->Type == 'x11-req') ||
          ($sshMessage->Type == 'env') ||
          ($sshMessage->Type == 'shell') ||
          ($sshMessage->Type == 'exec') ||
          ($sshMessage->Type == 'subsystem') ||
          ($sshMessage->Type == 'window-change') ||
          ($sshMessage->Type == 'xon-xoff') ||
          ($sshMessage->Type == 'signal')
        )
          $requestResult = false; // Unsupported
        
        // Process Command-Exit
        elseif ($sshMessage->Type == 'exit-status') {
          $this->commandStatus = $sshMessage->Status;
          $requestResult = true;
        } elseif ($sshMessage->Type == 'exit-signal') {
          if ($this->commandStatus === null)
            $this->commandStatus = 0xff;
          
          $this->commandSignal = $sshMessage->Signal;
          
          # $Message->CoreDumped
          # $Message->errorMessage / $Message->errorLanguage
          
          $requestResult = true;
        } else
          $requestResult = false;
        
        if ($sshMessage->wantReply) {
          $sshReply = ($requestResult ? new ChannelSuccess () : new ChannelFailure ());
          $sshReply->RecipientChannel = $this->remoteID;
          
          $this->sshStream->writeMessage ($sshReply);
        }
      
      // Last pending request was successfull or not
      } elseif (
        ($sshMessage instanceof ChannelSuccess) ||
        ($sshMessage instanceof ChannelFailure)
      ) {
        // Make sure there is a request pending at all
        if (count ($this->channelRequests) < 1) {
          trigger_error ('Received reply for a channel-request without pending request');
          
          return;
        }
        
        // Get the request from queue
        $requestInfo = array_shift ($this->channelRequests);
        
        // Resolve or reject the promise
        if ($sshMessage instanceof ChannelSuccess)
          $requestInfo [1]->resolve ();
        else
          $requestInfo [1]->reject ('Received a failure for this request');
        
        // Check wheter to write out the next request
        if (count ($this->channelRequests) > 0)
          $this->sshStream->writeMessage ($this->channelRequests [0][0])->catch ([ $this->channelRequests [0][1], 'reject' ]);
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
    protected function eventWritable () : void {
      // No-Op
    }
    // }}}
    
    // {{{ eventPiped
    /**
     * Callback: A source was attached to this consumer
     * 
     * @param Events\ABI\Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPiped (Events\ABI\Source $sourceStream) : void {
      // No-Op
    }
    // }}}
    
    // {{{ channelEnd
    /**
     * Callback: Channel-End-Message was received
     * 
     * @access protected
     * @return void
     **/
    protected function channelEnd () : void {
     // No-Op
    }
    // }}}
  }
