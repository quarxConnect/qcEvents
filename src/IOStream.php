<?php

  /**
   * quarxConnect Events - I/O-Stream Handler
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events;
  
  abstract class IOStream extends Virtual\Pipe implements ABI\Loop, ABI\Stream, ABI\Stream\Consumer {
    use Feature\Based;
    
    protected const DEFAULT_READ_LENGTH = 4096;
    
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
    private $writeBuffer = [ ];
    
    // {{{ __construct
    /**
     * Create a new IOStream
     * 
     * @param Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Base $eventBase = null) {
      if ($eventBase)
        $this->setEventBase ($eventBase);
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
        
        if ($eventBase = $this->getEventBase ()) {
          if ($this->watchSetup)
            $eventBase->addEvent ($this);
          else
            $eventBase->removeEvent ($this);
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
      
      if ($eventBase = $this->getEventBase ())
        $eventBase->updateEvent ($this);
      
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
      
      if ($eventBase = $this->getEventBase ())
        $eventBase->updateEvent ($this);
      
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
     * @param bool $setState (optional) Set the status
     * 
     * @access public
     * @return bool
     **/
    public function watchRead ($setState = null) {
      // Check wheter to change the status
      if ($setState !== null) {
        // Change the status, remember the old
        $originalSetting = $this->watchReads;
        $this->watchReads = !!$setState;
        
        // Update the event-loop if there were changes
        if (($eventBase = $this->getEventBase ()) &&
            ($originalSetting != $this->watchReads) &&
            !$eventBase->updateEvent ($this)) {
          $this->watchReads = $originalSetting;
          
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
     * 
     * @access public
     * @return Promise
     **/
    public function write ($Data) : Promise {
      return new Promise (
        function (callable $resolveFunction, callable $rejectFunction) use ($Data) {
          // Enqueue the packet
          $this->writeBuffer [] = [ $Data, $resolveFunction, $rejectFunction ];
          
          // Make sure we catch write-events
          if (!$this->watchWrites && ($eventBase = $this->getEventBase ()))
            $eventBase->updateEvent ($this);
        }
      );
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param ABI\Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, ABI\Source $Source) {
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
     * @param bool $setState (optional) Set the status
     * 
     * @access public
     * @return bool
     **/
    public function watchWrite ($setState = null) {
      // Check wheter to change the status
      if ($setState !== null) {
        // Change the status, remember the old
        $originalSetting = $this->watchWrites;
        $this->watchWrites = !!$setState;
        
        // Update the event-loop if there were changes
        if (($eventBase = $this->getEventBase ()) &&
            (($originalSetting || (count ($this->writeBuffer) > 0)) != $this->watchWrites) &&
            !$eventBase->updateEvent ($this)) {
          $this->watchWrites = $originalSetting;
          
          return false;
        }
        
        return true;
      }
      
      // Return the current status
      return ($this->watchWrites || (count ($this->writeBuffer) > 0));
    }
    // }}}
    
    // {{{ getErrorFD
    /**
     * Retrive an additional stream-resource to watch for errors
     * @remark Read-/Write-FDs are always monitored for errors
     * 
     * @access public
     * @return resource May return NULL if no additional stream-resource should be watched
     **/
    public function getErrorFD () {
      return null;
    }
    // }}}
    
    /*****************************************************************
     * Stream closeing                                               *
     *****************************************************************/
    
    // {{{ close
    /**
     * Close this I/O-Stream
     * 
     * @param bool $Force (optional) Force close even if there is data on the write-buffer
     * 
     * @access public
     * @return Promise
     **/
    public function close ($Force = false) : Promise {
      // Check if there is a pending close
      if ($this->isClosing instanceof Promise) {
        // Remember the promise here as it might be removed by force-callbacks
        $closePromise = $this->isClosing;
        
        // Clear write-buffer if forced
        if ($Force) {
          foreach ($this->writeBuffer as $writeBuffer)
            call_user_func ($writeBuffer [2], 'Close was forced');
          
          $this->writeBuffer = [ ];
          
          $this->___callback ('eventDrained');
        }
        
        return $closePromise;
      }
      
      // Check if there are writes pending
      if (!$Force && (count ($this->writeBuffer) > 0))
        return ($this->isClosing = $this->once ('eventDrained')->then (
          function () {
            // Do the low-level-close
            if ($this->readFD)
              $this->___close ($this->readFD);
            elseif (!$this->writeFD)
              $this->___close ();
            
            if ($this->writeFD && ($this->writeFD !== $this->readFD))
              $this->___close ($this->writeFD);
            
            
            $this->readFD = null;
            $this->writeFD = null;
            $this->isWatching (false);
            
            // Remove closing-state
            $this->isClosing = false;
            
            // Raise callbacks
            $this->___callback ('eventClosed');
          }
        ));
      
      // Mark ourself as closing
      $this->isClosing = true;
      
      // Force the write-buffer to be cleared
      foreach ($this->writeBuffer as $writeBuffer)
        call_user_func ($writeBuffer [2], 'Close was forced');
      
      $this->writeBuffer = [ ];
      
      // Do the low-level-close
      if ($this->readFD)
        $this->___close ($this->readFD);
      elseif (!$this->writeFD)
        $this->___close ();
      
      if ($this->writeFD && ($this->writeFD !== $this->readFD))
        $this->___close ($this->writeFD);
      
      $this->readFD = null;
      $this->writeFD = null;
      
      $this->isWatching (false);
      
      // Remove closing-state
      $this->isClosing = false;
      
      // Raise callbacks
      $this->___callback ('eventClosed');
      
      return Promise::resolve ();
    }
    // }}}
    
    // {{{ ___close
    /**
     * Close the stream at the handler
     * 
     * @param mixed $closeFD
     * 
     * @access protected
     * @return bool
     **/
    abstract protected function ___close ($closeFD);
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
        
        if (!$this->watchWrites && ($eventBase = $this->getEventBase ()))
          $eventBase->updateEvent ($this);
        
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
        
        // Resolve promise
        call_user_func ($Finished [1]);
      }
      
      // Check wheter to remove write-watching
      if (!$this->watchWrites &&
          ($eventBase = $this->getEventBase ()))
        $eventBase->updateEvent ($this);
      
      // Fire the event when
      $this->___callback ('eventDrained');
      $this->___callback ('eventWritable');
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param ABI\Source $dataSource
     * 
     * @access public
     * @return Promise
     **/
    public function initConsumer (ABI\Source $dataSource) : Promise {
      $this->___callback ('eventPiped', $dataSource);
      
      return Promise::resolve ();
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param ABI\Stream $Source
     * 
     * @access public
     * @return Promise
     **/
    public function initStreamConsumer (ABI\Stream $Source) : Promise {
      // Raise a callback for this
      $this->___callback ('eventPipedStream', $Source);
      
      // Return a resolve promise
      return Promise::resolve ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     * 
     * @param ABI\Source $Source
     * 
     * @access public
     * @return Promise
     **/
    public function deinitConsumer (ABI\Source $Source) : Promise {
      $this->___callback ('eventUnpiped', $Source);
      
      return Promise::resolve ();
    }
    // }}}
    
    // {{{ raiseError
    /**
     * Callback: The Event-Loop detected an error-event
     * 
     * @param resource $fd
     * 
     * @access public
     * @return void
     **/
    public function raiseError ($fd) {
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
     * @param ABI\Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPiped (ABI\Source $Source) { }
    // }}}

    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param ABI\Stream $Source
     * 
     * @access protected
     * @return void
     **/  
    protected function eventPipedStream (ABI\Stream $Source) { }
    // }}}

    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param ABI\Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (ABI\Source $Source) { }
    // }}}
  }

?>