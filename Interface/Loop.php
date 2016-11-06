<?PHP

  /**
   * qcEvents - Interface for Event-Handlers that are run by the event-loop
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
  interface qcEvents_Interface_Loop {
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return qcEvents_Base May be NULL if none is assigned
     * 
     * @remark This is implemented by qcEvents_Trait_Parented
     **/
    public function getEventBase ();
    // }}}
    
    // {{{ setEventBase
    /**
     * Set a new event-loop-handler
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void
     * 
     * @remark This is implemented by qcEvents_Trait_Parented
     **/
    public function setEventBase (qcEvents_Base $Base);
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void
     * 
     * @remark This is implemented by qcEvents_Trait_Parented
     **/
    public function unsetEventBase ();
    // }}}
    
    // {{{ getReadFD
    /**
     * Retrive the stream-resource to watch for reads
     * 
     * @access public
     * @return resource May return NULL if no reads should be watched
     **/
    public function getReadFD ();
    // }}}
    
    // {{{ getWriteFD
    /**
     * Retrive the stream-resource to watch for writes
     * 
     * @access public
     * @return resource May return NULL if no writes should be watched
     **/
    public function getWriteFD ();
    // }}}
    
    // {{{ getErrorFD
    /**
     * Retrive an additional stream-resource to watch for errors
     * @remark Read-/Write-FDs are always monitored for errors
     * 
     * @access public
     * @return resource May return NULL if no additional stream-resource should be watched
     **/
    public function getErrorFD ();
    // }}}
    
    
    // {{{ raiseRead
    /**
     * Callback: The Event-Loop detected a read-event
     * 
     * @access public
     * @return void
     **/
    public function raiseRead ();
    // }}}
    
    // {{{ raiseWrite
    /**
     * Callback: The Event-Loop detected a write-event
     * 
     * @access public
     * @return void
     **/
    public function raiseWrite ();
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
    public function raiseError ($fd);
    // }}}
  }

?>