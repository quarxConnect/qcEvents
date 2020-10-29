<?PHP

  /**
   * qcEvents - Interface for Event-Sinks
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
  require_once ('qcEvents/Interface/Consumer.php');
  
  interface qcEvents_Interface_Sink extends qcEvents_Interface_Common, qcEvents_Interface_Consumer {
    // {{{ write
    /**
     * Write data to this sink
     * 
     * @param string $Data The data to write to this sink
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function write ($Data);
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
    public function watchWrite ($Set = null);
    // }}}
    
    
    // {{{ eventWritable
    /**
     * Callback: A writable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    # protected function eventWritable ();
    // }}}
    
    // {{{ eventError
    /**
     * Callback: An error was received for this handler on the event-loop
     * 
     * @access protected
     * @return void  
     **/
    # protected function eventError ();
    // }}}
  }

?>