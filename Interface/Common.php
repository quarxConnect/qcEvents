<?PHP

  /**
   * qcEvents - Interface Commons
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
  
  require_once ('qcEvents/Interface/Hookable.php');
  
  interface qcEvents_Interface_Common extends qcEvents_Interface_Hookable {
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
     * Set the Event-Base of this source
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (qcEvents_Base $Base);
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
    public function isWatching ($Set = null);
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise;
    // }}}
    
    
    // {{{ eventClosed
    /**
     * Callback: The interface was closed
     * 
     * @access public
     * @return void
     **/
    # protected function eventClosed ();
    // }}}
    
    // {{{ eventError
    /**
     * Callback: An error was received for this handler on the event-loop
     * 
     * @access public
     * @return void  
     **/
    # protected function eventError ();
    // }}}
  }

?>