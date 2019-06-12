<?PHP

  /**
   * qcEvents - Interface for Event-Streams
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
  
  require_once ('qcEvents/Interface/Source.php');
  require_once ('qcEvents/Interface/Sink.php');
  
  interface qcEvents_Interface_Stream extends qcEvents_Interface_Source, qcEvents_Interface_Sink {
    // {{{ pipeStream
    /**
     * Create a bidrectional pipe
     * Forward any data received from this source to another handler and
     * allow the handler to write back to this stream
     * 
     * @param qcEvents_Interface_Stream_Consumer $Handler
     * @param bool $Finish (optional) Raise close on the handler if we are finished (default)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function pipeStream (qcEvents_Interface_Stream_Consumer $Handler, $Finish = true) : qcEvents_Promise;
    // }}}
    
    // {{{ unpipe
    /**
     * Remove a handler that is currently being piped
     * 
     * @param qcEvents_Interface_Consumer_Common $Handler
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function unpipe (qcEvents_Interface_Consumer_Common $Handler) : qcEvents_Promise;
    // }}}
  }

?>