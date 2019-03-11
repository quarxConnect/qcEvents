<?PHP

  /**
   * qcEvents - Generic SNMP Receiver and Submitter
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
  
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Trait/Hookable.php');
  require_once ('qcEvents/Promise.php');
  require_once ('ASN1/Coder/DER.php');
  require_once ('SNMP/Message.php');
  
  class qcEvents_Stream_SNMP implements qcEvents_Interface_Consumer {
    use qcEvents_Trait_Hookable;
    
    private $Buffer = null;
    
    // {{{ consume
    /**
     * Internal Callback: Data was received over the wire
     * 
     * @param string $Data
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      // Try to parse an SNMP-Message from buffer
      if ($this->Buffer !== null) {
        // Append to buffer
        $this->Buffer .= $Data;
        unset ($Data);
        
        // Try to parse from buffer
        if (($Message = ASN1_Coder_DER::decodeHandle ('SNMP_Message', $this->Buffer)) === null)
          return;
        elseif ($Message === false)
          return ($this->Buffer = null);
      
      // Try to parse SNMP-Message from data
      } elseif (($Message = ASN1_Coder_DER::decodeHandle ('SNMP_Message', $Data)) === null) {
        $this->Buffer = $Data;
        
        return;
      } elseif ($Message === false)
        return;
      elseif (strlen ($Data) > 0)
        $this->Buffer = $Data;
      
      // Raise callback
      $this->___callback ('snmpMessageReceived', $Message);
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
      // Just raise callbacks
      $this->___callback ('eventClosed');
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Raise an event for this
      $this->___raiseCallback ($Callback, true, $Private);
      $this->___callback ('eventPiped', $Source);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Interface_Stream_Consumer $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source, callable $Callback = null, $Private = null) {
      // Raise an event for this
      $this->___callback ('eventPipedStream', $Source);
      $this->___raiseCallback ($Callback, true, $Private);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      // Raise an event for this
      $this->___callback ('eventUnpiped', $Source);
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    
    // {{{ snmpMessageReceived
    /**
     * Callback: An SNMP-Message was received
     * 
     * @param SNMP_Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected function snmpMessageReceived (SNMP_Message $Message) { }
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
    
    // {{{ eventPiped
    /**
     * Callback: A source was attached to this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPiped (qcEvents_Interface_Source $Source) { }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param qcEvents_Interface_Stream $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (qcEvents_Interface_Stream $Source) { }
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (qcEvents_Interface_Source $Source) { }
    // }}}
  }

?>