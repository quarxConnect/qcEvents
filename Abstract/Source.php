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
  
  class qcEvents_Abstract_Source extends qcEvents_Abstract_Pipe implements qcEvents_Interface_Source {
    /* Local buffer of abstract source */
    private $Buffer = '';
    
    /* Raise events if data is available on the buffer */
    private $raiseEvents = true;
    
    /* Closed state */
    private $closed = false;
    
    // {{{ sourceInsert
    /**
     * Insert some data into the abstract source
     * 
     * @param string $Data
     * 
     * @access public
     * @return void
     **/
    public function sourceInsert ($Data) {
      // Check if we are closed
      if ($this->closed)
        return;
      
      // Append to local buffer
      $this->Buffer .= $Data;
      
      // Check wheter to raise an event
      if ($this->raiseEvents)
        $this->___callback ('eventReadable');
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
      if ($Size === null) {
        $Buffer = $this->Buffer;
        $this->Buffer = '';
      } else {
        $Buffer = substr ($this->Buffer, 0, $Size);
        $this->Buffer = substr ($this->Buffer, $Size);
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
        if ($this->raiseEvents = !!$Set)
          $this->___callback ('eventReadable');
        
        return true;
      }
      
      return $this->raiseEvents;
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
      # Unused
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
     * @param callable $Callback (optional) Callback to raise once the interface is closed
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * @access public
     * @return void  
     **/
    public function close (callable $Callback = null, $Private = null) {
      if (!$this->closed) {
        $this->closed = true;
        $this->___callback ('eventClosed');
        $this->Buffer = '';
      }
      
      $this->___raiseCallback ($Callback, $Private);
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