<?PHP

  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Abstract/Source.php');
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  
  class qcEvents_Stream_TAR extends qcEvents_Hookable implements qcEvents_Interface_Consumer, qcEvents_Interface_Stream_Consumer {
    /* Source-Buffer */
    private $Buffer = '';
    
    /* TAR-Header for current file */
    private $Header = null;
    
    /* Pseudo-Stream for current file */
    private $Stream = null;
    
    /* Read files from source using pseudo-streams */
    private $useStreams = true;
    
    /* Read an extended header as file */
    private $readExtendedHeader = false;
    
    // {{{ consume 
    /** 
     * Consume a set of data
     *    
     * @param mixed $Data
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source = null) {
      // Copy the data to our buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Try to process the buffered data
      $this->processBuffer ();
    }
    // }}}
    
    // {{{ processBuffer
    /**
     * Process data on our buffer
     * 
     * @access private
     * @return void
     **/
    private function processBuffer () {
      // Check if there is a header queued
      if ($this->Header && !$this->processFile ())
        return;
      
      // Try to read headers
      $p = 0;
      $l = strlen ($this->Buffer);
      
      while ($p + 512 < $l) {
        // Read the entire header
        $this->Header = array (
          'Filename'  => self::readString (substr ($this->Buffer, $p, 100)),
          'Filemode'  => self::readNumber (substr ($this->Buffer, $p + 100, 8)),
          'UserID'    => self::readNumber (substr ($this->Buffer, $p + 108, 8)),
          'GroupID'   => self::readNumber (substr ($this->Buffer, $p + 116, 8)),
          'Filesize'  => self::readSize (substr ($this->Buffer, $p + 124, 12)),
          'FileMTime' => self::readNumber (substr ($this->Buffer, $p + 136, 12)),
          'Checksum'  => substr ($this->Buffer, $p + 148, 8),
          'Filetype'  => substr ($this->Buffer, $p + 156, 1),
          'LinkDestination' => self::readString (substr ($this->Buffer, $p + 157, 100)),
        );
        
        // Post-Process file-type
        if (strval (intval ($this->Header ['Filetype'])) == $this->Header ['Filetype'])
          $this->Header ['Filetype'] = intval ($this->Header ['Filetype']);
        
        $this->readExtendedHeader = ($this->Header ['Filetype'] == 'x');
        
        // Parse additional ustar-header
        if (substr ($this->Buffer, $p + 257, 5) == 'ustar') {
          $this->Header ['Username'] = self::readString (substr ($this->Buffer, $p + 265, 32));
          $this->Header ['Groupname'] = self::readString (substr ($this->Buffer, $p + 297, 32));
          $this->Header ['DeviceMajor'] = self::readNumber (substr ($this->Buffer, $p + 329, 8));
          $this->Header ['DeviceMinor'] = self::readNumber (substr ($this->Buffer, $p + 337, 8));
          $this->Header ['Fileprefix'] = self::readString (substr ($this->Buffer, $p + 345, 155));
        }
        
        # TODO: Validate Checksum
        
        // Move pointer beyond header
        $p += 512;
        
        // Check if the buffer is longh enough
        $this->Header ['Blocks'] = ceil ($this->Header ['Filesize'] / 512);
        $Next = $p + ($this->Header ['Blocksize'] = $this->Header ['Blocks'] * 512);
        
        if ($this->Header ['Filename']) {
          // Setup stream if requested
          if ($this->useStreams) {
            $this->Stream = new qcEvents_Abstract_Source;
            $this->___callback ('tarNewFileStream', $this->Header ['Filename'], $this->Header, $this->Stream);
          }
          
          // Try to process this file
          if (!$this->processFile ($p))
            break;
        }
        
        // Move forward
        $p = $Next;
      }
      
      // Truncate the buffer
      if ($p > 0)
        $this->Buffer = substr ($this->Buffer, $p);
      
      // Just one last time
      if ($this->Header)
        $this->processFile ();
    }
    // }}}
    
    // {{{ processExtendedHeader
    /**
     * Process contents of an extended TAR-Header
     * 
     * @param string $Header
     * 
     * @access private
     * @return void
     **/
    private function processExtendedHeader ($Header) {
      # TODO
    }
    // }}}
    
    // {{{ processFile
    /**
     * Process file-retrival
     * 
     * @param int $Offset (optional)
     * 
     * @access private
     * @return bool TRUE if the file was received entirely
     **/
    private function processFile ($Offset = null) {
      // Prepare
      $Peek = ($Offset !== null);
      $Offset = intval ($Offset);
      $Length = strlen ($this->Buffer);
      
      // Check if we are running in stream-mode
      if ($this->Stream) {
        // Don't do anything if we are peeking
        if ($Peek)
          return null;
        
        // Check if we have to bootstrap
        if (!isset ($this->Header ['rBlocks']))
          $this->Header ['rBlocks'] = $this->Header ['Blocks'];
        
        // Check number of blocks on buffer
        $Blocks = floor ($Length / 512);
        
        // Forward blocks to stream
        $wBlocks = 0;
        
        if ($this->Header ['rBlocks'] > 1)
          while (($Blocks > 0) && ($this->Header ['rBlocks'] > 1)) {
            $this->Stream->sourceInsert (substr ($this->Buffer, 512 * $wBlocks++, 512));
            $Blocks--;
            $this->Header ['rBlocks']--;
          }
        
        if (($Blocks > 0) && ($this->Header ['rBlocks'] == 1)) {
          $this->Stream->sourceInsert (substr ($this->Buffer, 512 * $wBlocks++, (($l = (512 - ($this->Header ['Blocksize'] - $this->Header ['Filesize']))) == 0 ? 512 : $l)));
          $this->Header ['rBlocks']--;
        }
        
        // Truncate local buffer
        $this->Buffer = substr ($this->Buffer, $wBlocks * 512);
        
        if ($this->Header ['rBlocks'] < 1) {
          unset ($this->Header ['rBlocks']);
          
          $Result = $this->Stream;
          $this->Stream->close ();
          $this->Stream = null;
        } else
          return;
      
      // Check if there is enough data on the buffer
      } elseif ($Length >= $Offset + $this->Header ['Blocksize']) {
        $Result = substr ($this->Buffer, $Offset, $this->Header ['Filesize']);
        
        // Remove the file from buffer
        if (!$Peek)
          $this->Buffer = substr ($this->Buffer, 0, $Offset) . substr ($this->Buffer, $Offset + $this->Header ['Blocksize']);
      
      // Skip the call if not in stream-mode and not enough data is available
      } else
        return null;
      
      // Fire the callback
      if ($this->readExtendedHeader)
        $this->processExtendedHeader ($Result);
      else
        $this->___callback ('tarFileReceived', $this->Header ['Filename'], $Result, $this->Header);
      
      // Cleanup
      $this->Header = null;
      
      return true;
    }
    // }}}
    
    // {{{ readString
    /**
     * Read a nul-terminated string
     * 
     * @param string $Value
     * 
     * @access private
     * @return string
     **/
    private static function readString ($Value) {
      if (($p = strpos ($Value, "\x00")) !== false)
        return substr ($Value, 0, $p);
      
      return $Value;
    }
    // }}}
    
    // {{{ readNumber
    /**
     * Read a number from an tar-encoded string
     * 
     * @param string $Value
     * 
     * @access private
     * @return int
     **/
    private static function readNumber ($Value) {
      $l = strlen ($Value);
      
      if (($Value [$l - 1] == ' ') || ($Value [$l - 1] == "\x00"))
        $Value = substr ($Value, 0, --$l);
      elseif (($l == 1) && ($Value == "\x00"))
        return 0;
      
      return octdec ($Value);
    }
    // }}}
    
    // {{{ readSize
    /**
     * Read a size-value from tar-encoded string
     * 
     * @param string $Value
     * 
     * @access private
     * @return int
     **/
    private static function readSize ($Value) {
      if ((ord ($Value [0]) & 0x80) != 0x80)
        return self::readNumber ($Value);
      
      # TODO
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @param callable $Callback (optional) Callback to raise once the interface is closed
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * @access public
     * @return void  
     **/
    public function close (callable $Callback = null, $Private = null) {
      if (strlen ($this->Buffer) > 0)
        $this->processBuffer ();
      
      $this->___callback ('eventClosed');
      $this->___raiseCallback ($Callback, $Private);
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
     *   function (qcEvents_Interface_Source $Source, qcEvents_Interface_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      $this->Buffer = '';
      $this->Header = null;
     
      $this->___raiseCallback ($Callback, $Source, $this, true, $Private);
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
     *   function (qcEvents_Interface_Stream $Source, qcEvents_Interface_Stream_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source, callable $Callback = null, $Private = null) {
      $this->Buffer = '';
      $this->Header = null;
      
      $this->___raiseCallback ($Callback, $Source, $this, true, $Private);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of 
     * 
     *   function (qcEvents_Interface_Source $Source, qcEvents_Interface_Stream_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void  
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      $this->___raiseCallback ($Callback, $Source, $this, true, $Private);
    }
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
    
    protected function tarNewFileStream ($Filename, $Header, $Stream) { }
    protected function tarFileReceived ($Filename, $File, $Header) { }
  }

?>