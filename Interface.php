<?PHP

  /**
   * qcEvents - Event Interface
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  interface qcEvents_Interface {
    /* Event-Types */
    const EVENT_READ = 0;
    const EVENT_WRITE = 1;
    const EVENT_ERROR = 2;
    const EVENT_TIMER = 3;
    
    // {{{ getFD
    /**
     * Retrive the FD of this event to watch for actions
     * 
     * @access public
     * @return resource
     **/
    public function getFD ();
    // }}}
    
    
    // {{{ setHandler
    /**
     * Store the Event-Base-Hanlder on this event
     * 
     * @param qcEvents_Base $Base
     * @param bool $setBound (optional) Mark this event as bound
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (qcEvents_Base $Base, $setBound = false);
    // }}}
    
    // {{{ unbind
    /**
     * Remove this event from its Event-Base-Handler
     * 
     * @access public
     * @return void
     **/
    public function unbind ();
    // }}}
    
    
    // {{{ watchRead
    /**
     * Check if the event-fd should be watched for read-events
     * 
     * @access public
     * @return bool
     **/
    public function watchRead ();
    // }}}
    
    // {{{ watchWrite
    /**
     * Check wheter for watch for write-events of the fd of this event
     * 
     * @access public
     * @return bool
     **/
    public function watchWrite ();
    // }}}
    
    // {{{ watchError
    /**
     * Check wheter to watch for error-exceptions on the events fd
     * 
     * @access public
     * @return bool
     **/
    public function watchError ();
    // }}}
    
    
    // {{{ readEvent
    /**
     * Callback: A read-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function readEvent ();
    // }}}
    
    // {{{ writeEvent
    /**
     * Callback: A write-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function writeEvent ();
    // }}}
    
    // {{{ errorEvent
    /**
     * Callback: An error-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function errorEvent ();
    // }}}
    
    // {{{ timerEvent
    /**
     * Callback: A timer-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function timerEvent ();
    // }}}
  }

?>