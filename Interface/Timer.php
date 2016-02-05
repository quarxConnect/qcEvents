<?PHP

  /**
   * qcEvents - Interface for event-handlers that may capture timer-events
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
  
  interface qcEvents_Interface_Timer extends qcEvents_Interface_Hookable {
    // {{{ addTimer
    /**
     * Register a timer for this event-handle
     * 
     * @param mixed $Timeout
     * @param bool $Repeat (optional)
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function addTimer ($Timeout, $Repeat = false, callable $Callback = null, $Private = null);
    // }}}
    
    // {{{ clearTimer
    /**
     * Try to unregister a previous declared timer for this event-handle
     * 
     * @param mixed $Timeout
     * @param bool $Repeat (optional)
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function clearTimer ($Timeout, $Repeat = false, callable $Callback = null, $Private = null);
    // }}}
    
    // {{{ raiseTimer
    /**
     * Callback: A timer-event was raised
     * 
     * @access public
     * @return void
     **/
    public function raiseTimer ();
    // }}}
  }

?>