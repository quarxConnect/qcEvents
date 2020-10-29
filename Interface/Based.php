<?PHP

  /**
   * qcEvents - Interface Based
   * Copyright (C) 2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  interface qcEvents_Interface_Based {
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return qcEvents_Base
     **/
    public function getEventBase ();
    // }}}
    
    // {{{ setEventBase
    /**
     * Set the Event-Base of this source
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (qcEvents_Base $eventBase);
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void
     **/
    public function unsetEventBase ();
    // }}}
  }

?>