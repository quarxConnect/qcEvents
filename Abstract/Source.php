<?PHP

  /**
   * qcEvents - Abstract/Dummy Source
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
  
  require_once ('qcEvents/Abstract/Pipe.php');
  require_once ('qcEvents/Interface/Source.php');
  require_once ('qcEvents/Promise.php');
  
  class qcEvents_Abstract_Source extends qcEvents_Abstract_Pipe implements qcEvents_Interface_Source {
    /* Assigned event-base */
    private $eventBase = null;
    
    /* Local buffer of abstract source */
    private $Buffer = '';
    
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
     * @param  qcEvents_Base $eventBase (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase = null) {
      $this->eventBase = $eventBase;
    }
    // }}}
    
    // {{{ sourceInsert
    /**
     * Insert some data into the abstract source
     * 
     * @param string $Data
     * @param bool $closeOnDrain (optional)
     * 
     * @access public
     * @return void
     **/
    public function sourceInsert ($Data, $closeOnDrain = null) {
      // Check if we are closed
      if ($this->closed)
        return;
      
      // Append to local buffer
      $this->Buffer .= $Data;
      
      // Check wheter to raise an event
      if ($this->raiseEvents) {
        if ($this->eventBase)
          $this->eventBase->forceCallback (array ($this, 'raiseRead'));
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
      return strlen ($this->Buffer);
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
      // Get the requested bytes from buffer
      if ($Size === null) {
        $Buffer = $this->Buffer;
        $this->Buffer = '';
      } else {
        $Buffer = substr ($this->Buffer, 0, $Size);
        $this->Buffer = substr ($this->Buffer, $Size);
      }
      
      // Check if we shall close
      if ($this->closeOnDrain && (strlen ($this->Buffer) == 0)) {
        if ($this->eventBase)
          $this->eventBase->forceCallback (array ($this, 'close'));
        else
          $this->close ();
      }
      
      return $Buffer;  
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
          if ($this->eventBase)
            $this->eventBase->forceCallback (array ($this, 'raiseRead'));
          else
            $this->___callback ('eventReadable');
        }
        
        return true;
      }
      
      return $this->raiseEvents;
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the assigned event-base
     * 
     * @access public
     * @return qcEvents_Base
     **/
    public function getEventBase () {
      return $this->eventBase;
    }
    // }}}
    
    // {{{ setEventBase
    /**   
     * Set the Event-Base of this source
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (qcEvents_Base $Base) {
      $this->eventBase = $Base;
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
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      if (!$this->closed) {
        $this->closed = true;
        $this->___callback ('eventClosed');
        $this->Buffer = '';
      }
      
      return qcEvents_Promise::resolve ();
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
      if (strlen ($this->Buffer) == 0)
        return;
      
      $this->___callback ('eventReadable');
      
      if ($this->eventBase)
        $this->eventBase->forceCallback (array ($this, 'raiseRead'));
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

?>