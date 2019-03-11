<?PHP

  /**
   * qcEvents - Interface for Event-Sources
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
  
  require_once ('qcEvents/Interface/Common.php');
  
  interface qcEvents_Interface_Source extends qcEvents_Interface_Common {
    // {{{ read
    /**
     * Try to read pending data from this source
     * 
     * @param int $Size (optional)
     * 
     * @access public
     * @return string
     **/
    public function read ($Size = null);
    // }}}
    
    // {{{ pipe
    /**
     * Forward any data received from this source to another handler
     * 
     * @param qcEvents_Interface_Consumer $Handler
     * @param bool $Finish (optional) Raise close on the handler if we are finished (default)
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Source $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function pipe (qcEvents_Interface_Consumer $Handler, $Finish = true, callable $Callback = null, $Private = null);
    // }}}
    
    // {{{ unpipe
    /**
     * Remove a handler that is currently being piped
     * 
     * @param qcEvents_Interface_Consumer $Handler
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function unpipe (qcEvents_Interface_Consumer $Handler) : qcEvents_Promise;
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
    public function watchRead ($Set = null);
    // }}}
    
    
    // {{{ eventReadable
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    # protected function eventReadable ();
    // }}}
  }

?>