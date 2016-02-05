<?PHP

  /**
   * qcEvents - I/O-Stream Handler
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  require_once ('qcEvents/Interface/Loop.php');
  require_once ('qcEvents/Interface/Stream.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Abstract/Pipe.php');
  
  abstract class qcEvents_IOStream extends qcEvents_Abstract_Pipe implements qcEvents_Interface_Loop, qcEvents_Interface_Stream, qcEvents_Interface_Stream_Consumer {
    const DEFAULT_READ_LENGTH = 4096;
    
    /* Internal handle of our event-loop */
    private $eventLoop = null;
    
    /* Our stream-file-descriptors */
    private $readFD = null;
    private $writeFD = null;
    
    /* Watcher-States */
    private $watchReads = true;
    private $watchWrites = false;
    private $watchSetup = null;
    
    /* Closing state */
    private $isClosing = false;
    
    /* Internal write-buffer */
    private $writeBuffer = array ();
    
    // {{{ __construct
    /**
     * Create a new IOStream
     * 
     * @param qcEvents_Base $Base
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null) {
      if ($Base)
        $this->setEventBase ($Base);
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return qcEvents_Base May be NULL if none is assigned
     **/
    public function getEventBase () {
      return $this->eventLoop;
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Set a new event-loop-handler
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (qcEvents_Base $Base) {
      // Check if the event-loop if different from the current one
      if ($Base === $this->eventLoop)
        return;
      
      // Remove ourself from the current event loop
      if ($this->eventLoop)
        $this->eventLoop->removeEvent ($this);
      
      // Assign the new event-loop
      $this->eventLoop = $Base;
      
      $Base->addEvent ($this);
    }
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void
     **/
    public function unsetEventBase () {
      if (!$this->eventLoop)
        return;
      
      $this->eventLoop->removeEvent ($this);
      $this->eventLoop = null;
    }
    // }}}
    
    // {{{ isWatching
    /**
     * Get/Set if we are watching events on this I/O-Stream
     *
     * @pararm bool $Set (optional) Set the status
     * 
     * @access public
     * @return bool
     **/
    public function isWatching ($Set = null) {
      if ($Set !== null) {
        $this->watchSetup = ($Set ? true : false);
        
        if ($this->eventLoop) {
          if ($this->watchSetup)
            $this->eventLoop->addEvent ($this);
          else
            $this->eventLoop->removeEvent ($this);
        }
        
        return true;
      }
      
      return ($this->watchSetup !== false);
    }
    // }}}
    
    // {{{ setStreamFD
    /**
     * Set the used stream-file-descriptor
     * 
     * @param resource $fd
     * 
     * @access protected
     * @return bool
     **/
    protected function setStreamFD ($fd) {
      if (!is_resource ($fd))
        return false;
      
      $this->readFD = $fd;
      $this->writeFD = $fd;
      
      if ($this->eventLoop)
        $this->eventLoop->updateEvent ($this);
      
      return true;
    }
    // }}}
    
    // {{{ setStreamFDs
    /**
     * Setup stream-descriptors separately
     * 
     * @param resource $readFD
     * @param resource $writeFD
     * 
     * @access protected
     * @return bool
     **/
    protected function setStreamFDs ($readFD, $writeFD) {
      if (!is_resource ($readFD) || !is_resource ($writeFD))
        return false;
      
      $this->readFD = $readFD;
      $this->writeFD = $writeFD;
      
      if ($this->eventLoop)
        $this->eventLoop->updateEvent ($this);
      
      return true;
    }
    // }}}
    
    
    /*****************************************************************
     * Stream reading                                                *
     *****************************************************************/
    
    // {{{ getReadFD
    /**
     * Retrive the stream-resource to watch for reads
     * 
     * @access public
     * @return resource May return NULL if no reads should be watched
     **/
    public function getReadFD () {
      if (($this->watchSetup !== false) && $this->watchReads)
        return $this->readFD;
      
      return null;
    }
    // }}}
    
    // {{{ read
    /**
     * Try to read pending data from this source
     * 
     * @param int $Size (optional)
     *    
     * @access public
     * @return string
     **/
    public function read ($Size = null) {
      return $this->___read ($Size);
    }
    // }}}
    
    // {{{ ___read
    /**
     * Read from the underlying stream
     * 
     * @param int $Length (optional)
     * 
     * @access protected
     * @return string
     **/
    abstract protected function ___read ($Length = null);
    // }}}
    
    // {{{ watchRead
    /**
     * Set/Retrive the current event-watching status
     * 
     * @param bool $Set (optional) Set the status
     * 
     * @access public
     * @return bool
     **/
    public function watchRead ($Set = null) {
      // Check wheter to change the status
      if ($Set !== null) {
        // Change the status, remember the old
        $o = $this->watchReads;
        $this->watchReads = ($Set ? true : false);
        
        // Update the event-loop if there were changes
        if ($this->eventLoop && ($o != $this->watchReads) && !$this->eventLoop->updateEvent ($this)) {
          $this->watchReads = $o;
          
          return false;
        }
       
        return true;
      } 
      
      // Return the current status
      return $this->watchReads;
    }
    // }}}
    
    
    /*****************************************************************
     * Stream writing                                                *
     *****************************************************************/
    
    // {{{ getWriteFD
    /**
     * Retrive the stream-resource to watch for writes
     * 
     * @access public
     * @return resource May return NULL if no writes should be watched
     **/
    public function getWriteFD ($Force = false) {
      if (($this->watchSetup !== false) && ($Force || $this->watchWrites || (count ($this->writeBuffer) > 0)))
        return $this->writeFD;
      
      return null;
    }
    // }}}
    
    // {{{ write
    /**
     * Write data to this sink
     * 
     * @param string $Data The data to write to this sink
     * @param callable $Callback (optional) The callback to raise once the data was written
     * @param mixed $Private (optional) A private parameter to pass to the callback
     * 
     * The Callback will be raised in the form of
     * 
     *   function (qcEvents_IOStream $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function write ($Data, callable $Callback = null, $Private = null) {
      // Clear private if there is no callback
      if ($Callback === null)
        $Private = null;
      
      // Enqueue the packet
      $this->writeBuffer [] = array ($Data, $Callback, $Private);
      
      // Make sure we catch write-events
      if (!$this->watchWrites && $this->eventLoop)
        $this->eventLoop->updateEvent ($this);
      
      return true;
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
    
    // {{{ ___write
    /**
     * Write to the underlying stream
     * 
     * @param string $Data
     * 
     * @access protected
     * @return int The number of bytes that have been written
     **/
    abstract protected function ___write ($Data);
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
      // Check wheter to change the status
      if ($Set !== null) {
        // Change the status, remember the old
        $o = $this->watchWrites;
        $this->watchWrites = ($Set ? true : false);
        
        // Update the event-loop if there were changes
        if ($this->eventLoop && (($o || (count ($this->writeBuffer) > 0)) != $this->watchWrites) && !$this->eventLoop->updateEvent ($this)) {
          $this->watchWrites = $o;
          
          return false;
        }
        
        return true;
      }
      
      // Return the current status
      return ($this->watchWrites || (count ($this->writeBuffer) > 0));
    }
    // }}}
    
    
    /*****************************************************************
     * Stream closeing                                               *
     *****************************************************************/
    
    // {{{ close
    /**
     * Close this I/O-Stream
     * 
     * @param callable $Callback (optional) Callback to raise once the interface is closed
     * @param mixed $Private (optional) Private data to pass to the callback
     * @param bool $Force (optional) Force close even if there is data on the write-buffer
     * 
     * @access public
     * @return void
     **/
    public function close (callable $Callback = null, $Private = null, $Force = false) {
      // Mark ourself as closing
      $this->isClosing = array ($Callback, $Private);
      
      // Check if there are writes pending
      if (count ($this->writeBuffer) > 0) {
        if (!$Force)
          return;
        
        foreach ($this->writeBuffer as $writeBuffer)
          $this->___raiseCallback ($writeBuffer [1], false, $writeBuffer [2]);
        
        $this->writeBuffer = array ();
      }
      
      // Do the low-level-close
      $this->___close ();
      $this->isWatching (false);
      
      // Fire callbacks
      if ($Callback !== null)
        call_user_func ($Callback, $Private);
      
      $this->___callback ('eventClosed');
    }
    // }}}
    
    // {{{ ___close
    /**
     * Close the stream at the handler
     * 
     * @access protected
     * @return bool
     **/
    abstract protected function ___close ();
    // }}}
    
    
    
    /*****************************************************************
     * Catch event-raises from the event-loop                        *
     *****************************************************************/
    
    // {{{ raiseRead
    /**
     * Callback: The Event-Loop detected a read-event
     * 
     * @access public
     * @return void  
     **/
    public function raiseRead () {
      $this->___callback ('eventReadable');
    }
    // }}}
    
    // {{{ raiseWrite
    /**
     * Callback: The Event-Loop detected a write-event
     * 
     * @access public
     * @return void
     **/
    public function raiseWrite () {
      // Check if there are items on the buffer
      if (count ($this->writeBuffer) == 0) {
        $this->___callback ('eventWritable');
        
        if (!$this->watchWrites && $this->eventLoop)
          $this->eventLoop->updateEvent ($this);
        
        return;
      }
      
      // Write pending packets as long as we can
      while (count ($this->writeBuffer) > 0) {
        // Ask our handler to do the write
        $Length = $this->___write ($this->writeBuffer [0][0]);
        
        if (($Length === false) || ($Length === null))
          return;
        
        // Truncate the write-buffer
        if ($Length > 0)
          $this->writeBuffer [0][0] = substr ($this->writeBuffer [0][0], $Length);
        
        // Check if the buffer is now empty
        if (strlen ($this->writeBuffer [0][0]) > 0)
          return;
        
        // Remove the chunk from the buffer
        $Finished = array_shift ($this->writeBuffer);
        
        // Raise a callback if requested
        if ($Finished [1] !== null)
          $this->___raiseCallback ($Finished [1], true, $Finished [2]);
      }
      
      // Fire the event when
      $this->___callback ('eventDrained');
      
      if ($this->isClosing)
        return $this->close ($this->isClosing [0], $this->isClosing [1]);
      
      $this->___callback ('eventWritable');
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
     *   function (qcEvents_Interface_Source $Source, qcEvents_Interface_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      $this->___raiseCallback ($Callback, $Source, $this, true, $Private);
      $this->___callback ('eventPiped', $Source);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Stream $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     *    
     * The callback will be raised in the form of
     *  
     *   function (qcEvents_Interface_Stream $Source, qcEvents_Interface_Stream_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source, callable $Callback = null, $Private = null) {
      $this->___raiseCallback ($Callback, $Source, $this, true, $Private);
      $this->___callback ('eventPipedStream', $Source);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of 
     * 
     *   function (qcEvents_Interface_Source $Source, qcEvents_Interface_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      $this->___raiseCallback ($Callback, $Source, $this, true, $Private);
      $this->___callback ('eventUnpiped', $Source);
    }
    // }}}
    
    // {{{ raiseError
    /**
     * Callback: The Event-Loop detected an error-event
     * 
     * @access public
     * @return void
     **/
    public function raiseError () {
      $this->___callback ('eventError');
    }
    // }}}
    
    
    // {{{ eventRead
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventReadable () { }
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
    
    // {{{ eventDrained
    /**
     * Callback: The write-buffer was completely written
     * 
     * @access protected
     * @return void
     **/
    protected function eventDrained () { }
    // }}}
    
    // {{{ eventError
    /**
     * Callback: An error was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    protected function eventError () { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: This stream was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
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

    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param qcEvents_Interface_Stream $Source
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
  }

?>