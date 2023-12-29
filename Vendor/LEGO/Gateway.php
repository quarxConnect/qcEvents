<?PHP

  /**
   * qcEvents - LEGO Dimensions Gateway
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
  
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Deferred.php');
  
  class qcEvents_Vendor_LEGO_Gateway extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* NFC-Actions */
    const ACTION_ADDED = 0x00;
    const ACTION_REMOVED = 0x01;
    
    /* Pads */
    const PAD_ALL = 0x00;
    const PAD_CENTER = 0x01;
    const PAD_LEFT = 0x02;
    const PAD_RIGHT = 0x03;
    
    /* Assigned source-stream */
    private $sourceStream = null;
    
    /* Read-Buffer */
    private $readBuffer = '';
    
    /* UIDs currently added to a pad */
    private $padUIDs = array (
      qcEvents_Vendor_LEGO_Gateway::PAD_CENTER => array (),
      qcEvents_Vendor_LEGO_Gateway::PAD_LEFT => array (),
      qcEvents_Vendor_LEGO_Gateway::PAD_RIGHT => array (),
    );
    
    /* Deferred promise returned by initStreamConsumer() */
    private $initPromise = null;
    
    // {{{ getPadUIDs
    /**
     * Retrive array with UIDs for a given pad
     * 
     * @param int $padIndex
     * 
     * @access public
     * @return array
     **/
    public function getPadUIDs ($padIndex) : array {
      if (!isset ($this->padUIDs [$padIndex]))
        throw new Error ('Invalid Pad');
      
      return $this->padUIDs [$padIndex];
    }
    // }}}
    
    // {{{ setColor
    /**
     * Set the color of a NFC-Pad
     * 
     * @param int $padIndex
     * @param int $redColor
     * @param int $greenColor
     * @param int $blueColor
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function setColor ($padIndex, $redColor, $greenColor, $blueColor) : qcEvents_Promise {
      return $this->writeCommand ([ 0xC0, 0x01, $padIndex, $redColor, $greenColor, $blueColor ]);
    }
    // }}}
    
    // {{{  setFade
    /**
     * Set a fading-color for a NFC-Pad
     * 
     * @param int $padIndex
     * @param int $redColor
     * @param int $greenColor
     * @param int $blueColor
     * @param int $pulseTime
     * @þaram int $pulseCount
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function setFade ($padIndex, $redColor, $greenColor, $blueColor, $pulseTime, $pulseCount) : qcEvents_Promise {
      return $this->writeCommand ([ 0xC2, 0x03, $padIndex, $pulseTime, $pulseCount, $redColor, $greenColor, $blueColor ]);
    }
    // }}}
    
    // {{{ setFlash
    /**
     * Set a flash-color for a NFC-Pad
     * 
     * @param int $padIndex
     * @param int $redColor
     * @param int $greenColor
     * @param int $blueColor
     * @param int $onLength
     * @param int $offLength
     * @param int $pulseCount
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function setFlash ($padIndex, $redColor, $greenColor, $blueColor, $onLength, $offLength, $pulseCount) : qcEvents_Promise {
      return $this->writeCommand ([ 0xC3, 0x02, $padIndex, $onLength, $offLength, $pulseCount, $redColor, $greenColor, $blueColor ]);
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $readData
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access public
     * @return void
     **/
    public function consume ($readData, qcEvents_Interface_Source $sourceStream) {
      // Push data to read-buffer
      $this->readBuffer .= $readData;
      unset ($readData);
      
      // Process read-buffer
      $bufferOffset = 0;
      $bufferLength = strlen ($this->readBuffer);
      
      while (($bufferOffset + 32) <= $bufferLength) {
        // Unpack the message
        $commandMessage = unpack ('C32', substr ($this->readBuffer, $bufferOffset, 32));
        $bufferOffset += 32;
        
        // Find last byte and build checksum
        $lastByte = null;
        $checkSum = 0;
        
        for ($i = 32; $i > 0; $i--)
          if ($lastByte === null) {
            if ($commandMessage [$i] != 0x00)
              $lastByte = $i;
            
            continue;
          } else
            $checkSum = ($checkSum + ($commandMessage [$i] & 0xFF)) & 0xFF;
          
        // Validate the checksum
        if ($checkSum !== $commandMessage [$lastByte]) {
          trigger_error ('Invalid checksum received');
          
          continue;
        }
        
        // Validate the length
        if ($lastByte != $commandMessage [2] + 3) {
          trigger_error ('Invalid length received');
          
          continue;
        }
        
        // Truncate checksum and zero-bytes
        $this->consumeMessage (array_slice ($commandMessage, 0, $lastByte - 1));
      }
      
      if ($bufferOffset > 0)
        $this->readBuffer = substr ($this->readBuffer, $bufferOffset);
    }
    // }}}
    
    // {{{ consumeMessage
    /**
     * Process a decoded message received from the gateway
     * 
     * @param array $messageSequence
     * 
     * @access private
     * @return void
     **/
    private function consumeMessage (array $messageSequence) {
      // Check wheter to resolve an init-promise
      if ($this->initPromise) {
        $this->initPromise->resolve ();
        $this->initPromise = null;
        
        $this->___callback ('eventPipedStream', $this->sourceStream);
      }
      
      // Strip of message-type and length
      $messageType = array_shift ($messageSequence);
      array_shift ($messageSequence);
      
      // Forward to callback
      if ($this->___callback ('legoGatewayRead', $messageType, $messageSequence) === false)
        return;
      
      // Ignore some kind of ACK-message
      if (($messageType == 0x55) && ($messageSequence [0] == 0x01))
        return;
      
      // Process NFC-Events
      if ($messageType == 0x56) {
        $padIndex = $messageSequence [0];
        $nfcAction = $messageSequence [3];
        $nfcUID = array_slice ($messageSequence, 4, 7);
        
        if ($nfcAction == $this::ACTION_ADDED) {
          if (!in_array ($nfcUID, $this->padUIDs [$padIndex]))
            $this->padUIDs [$padIndex][] = $nfcUID;
          
          return $this->___callback ('nfcAdded', $nfcUID, $padIndex);
        } elseif ($nfcAction == $this::ACTION_REMOVED) {
          if (($indexKey = array_search ($nfcUID, $this->padUIDs [$padIndex])) !== null)
            unset ($this->padUIDs [$padIndex][$indexKey]);
          
          return $this->___callback ('nfcRemoved', $nfcUID, $padIndex);
        }
      }
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $sourceStream) : qcEvents_Promise {
      // Check if there is already an init-promise
      if ($this->initPromise) {
        if ($this->sourceStream === $sourceStream)
          return $this->initPromise->getPromise ();
        
        $this->initPromise->reject ('Replaced by another stream');
      }
      
      $this->initPromise = new qcEvents_Deferred;
      $this->sourceStream = $sourceStream;
      
      $this->writeCommand ([ 0xb0, 0x01, 0x28, 0x63, 0x29, 0x20, 0x4c, 0x45, 0x47, 0x4f, 0x20, 0x32, 0x30, 0x31, 0x34 ]);
      
      return $this->initPromise->getPromise ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $sourceStream) : qcEvents_Promise {
      if ($sourceStream !== $this->sourceStream)
        return qcEvents_Promise::resolve ();
      
      $this->sourceStream = null;
      $this->___callback ('eventUnpiped', $sourceStream);
      
      return qcEvents_Promise::resolve ();
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
      $this->___callback ('eventClosed');
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ writeCommand
    /**
     * Write a command to the gateway
     * 
     * @param array $commandSequence
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function writeCommand (array $commandSequence) : qcEvents_Promise {
      // Prepare the message
      $commandMessage = array_fill (0, 33, 0x00);
      $commandMessage [0] = 'C32';
      $commandMessage [1] = 0x55;
      $commandMessage [2] = count ($commandSequence);
      
      // Push bytes to message and calculate checksum
      $checkSum = (0x55 + count ($commandSequence)) & 0xFF;
      
      foreach (array_values ($commandSequence) as $commandByte=>$commandValue) {
        $commandMessage [$commandByte + 3] = $commandValue;
        $checkSum = ($checkSum + ($commandValue & 0xFF)) & 0xFF;
      }
      
      $commandMessage [count ($commandSequence) + 3] = $checkSum;
      
      // Generate the message
      if ($this->___callback ('legoGatewayWrite', $commandSequence) === false)
        return qcEvents_Promise::reject ('Rejected by hook');
      
      return $this->sourceStream->write (call_user_func_array ('pack', $commandMessage));
    }
    // }}}
    
    
    // {{{ legoGatewayRead
    /**
     * Callback: A message was received from the gateway
     * 
     * @param int $messageType
     * @param array $messageSequence
     * 
     * @access protected
     * @return bool
     **/
    protected function legoGatewayRead ($messageType, array $messageSequence) { }
    // }}}
    
    // {{{ legoGatewayWrite
    /**
     * Callback: A message is being written to our stream
     * 
     * @param array $messageSequence
     * 
     * @access protected
     * @return bool
     **/
    protected function legoGatewayWrite (array $messageSequence) { }
    // }}}
    
    // {{{ nfcAdded
    /**
     * Callback: A tag was added to a NFC-Pad
     * 
     * @param array $nfcUID
     * @param int $nfcPad
     * 
     * @access protected
     * @return void
     **/
    protected function nfcAdded (array $nfcUID, $nfcPad) { }
    // }}}
    
    // {{{ nfcRemoved
    /**
     * Callback: A tag was removed from a NFC-Pad
     * 
     * @param array $nfcUID
     * @param int $nfcPad
     * 
     * @access protected
     * @return void
     **/
    protected function nfcRemoved (array $nfcUID, $nfcPad) { }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param qcEvents_Interface_Stream $sourceStream
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (qcEvents_Interface_Stream $sourceStream) { }
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (qcEvents_Interface_Source $sourceStream) { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: The interface was closed
     * 
     * @access public
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
  }

?>