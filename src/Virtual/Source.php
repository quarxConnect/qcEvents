<?php

  /**
   * quarxConnect Events - Abstract/Dummy Source
   * Copyright (C) 2015-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Virtual;
  use quarxConnect\Events;
  
  class Source extends Pipe implements Events\ABI\Source {
    use Events\Feature\Based;
    
    /* Local buffer of abstract source */
    private $sourceBuffer = '';
    
    /* Raise events if data is available on the buffer */
    private $raiseEvents = true;
    
    /* Closed state */
    private $closed = false;
    
    /* Close stream on drain */
    private $closeOnDrain = false;
    
    // {{{ __construct
    /**
     * Create a new abstract source
     * 
     * @param Events\Base $eventBase (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase = null) {
      if ($eventBase)
        $this->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ sourceInsert
    /**
     * Insert some data into the abstract source
     * 
     * @param string $sourceData
     * @param bool $closeOnDrain (optional)
     * 
     * @access public
     * @return void
     **/
    public function sourceInsert (string $sourceData, bool $closeOnDrain = null) : void {
      // Check if we are closed
      if ($this->closed)
        return;
      
      // Append to local buffer
      $this->sourceBuffer .= $sourceData;
      
      // Check wheter to raise an event
      if ($this->raiseEvents) {
        if ($eventBase = $this->getEventBase ())
          $eventBase->forceCallback ([ $this, 'raiseRead' ]);
        else
          $this->___callback ('eventReadable');
      }
      
      if ($closeOnDrain !== null)
        $this->closeOnDrain = !!$closeOnDrain;
    }
    // }}}
    
    // {{{ getBufferSize
    /**
     * Retrive the number of bytes on our local buffer
     * 
     * @access public
     * @return int
     **/
    public function getBufferSize () {
      return strlen ($this->sourceBuffer);
    }
    // }}}
    
    // {{{ read
    /**
     * Try to read pending data from this source
     * 
     * @param int $readLength (optional)
     * 
     * @access public
     * @return string
     **/
    public function read (int $readLength = null) : string {
      // Get the requested bytes from buffer
      if ($readLength !== null) {
        $readBuffer = substr ($this->sourceBuffer, 0, $readLength);
        $this->sourceBuffer = substr ($this->sourceBuffer, $readLength);
        
        // In PHP <8.0 substr() might return false instead of an empty string
        if ($readBuffer === false)
          $readBuffer = '';
        
        if ($this->sourceBuffer === false)
          $this->sourceBuffer = '';
      } else {
        $readBuffer = $this->sourceBuffer;
        $this->sourceBuffer = '';
      }
      
      // Check if we shall close
      if ($this->closeOnDrain && (strlen ($this->sourceBuffer) == 0)) {
        if ($eventBase = $this->getEventBase ())
          $eventBase->forceCallback ([ $this, 'close' ]);
        else
          $this->close ();
      }
      
      return $readBuffer;  
    }
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
      if ($Set !== null) {
        if ($this->raiseEvents = !!$Set) {
          if ($eventBase = $this->getEventBase ())
            $eventBase->forceCallback ([ $this, 'raiseRead' ]);
          else
            $this->___callback ('eventReadable');
        }
        
        return true;
      }
      
      return $this->raiseEvents;
    }
    // }}}
    
    // {{{ isWatching
    /**
     * Check if we are registered on the assigned Event-Base and watching for events
     * 
     * @param bool $Set (optional) Toogle the state
     * 
     * @access public
     * @return bool
     **/
    public function isWatching ($Set = null) {
      return $this->watchRead ($Set);
    }
    // }}}
    
    // {{{ isClosed
    /**
     * Check if this source has been closed
     * 
     * @access public
     * @return bool
     **/
    public function isClosed () {
      return $this->closed;
    }
    // }}}
    
    // {{{ close
    /**   
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      if (!$this->closed) {
        $this->closed = true;
        $this->___callback ('eventClosed');
        $this->sourceBuffer = '';
      }
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Callback: The Event-Loop detected a read-event
     * 
     * @access public
     * @return void
     **/
    public function raiseRead () {
      if (strlen ($this->sourceBuffer) == 0)
        return;
      
      $this->___callback ('eventReadable');
      
      if ($eventBase = $this->getEventBase ())
        $eventBase->forceCallback ([ $this, 'raiseRead' ]);
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
    
    // {{{ eventClosed
    /**
     * Callback: This stream was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
  }
