<?PHP

  require_once ('qcEvents/IOStream.php');
  
  class qcEvents_File extends qcEvents_IOStream {
    /* The filename of this stream */
    private $Filename = '';
    
    /* Modification-time to set on close */
    private $modificationTime = null;
    
    // {{{ readFileContents
    /**
     * Read full content of a file and pass to a given callback
     * 
     * @param qcEvents_Base $Base Event-Base to use
     * @param string $Filename Path to file
     * @param callable $Callback Callback to raise when all contents were read
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * Once completed the callback will be raised in the form of
     * 
     *   function (string $Content = null, mixed $Private = null) { }
     * 
     * @access public
     * @return qcEvents_File
     **/
    public static function readFileContents (qcEvents_Base $Base, $Filename, callable $Callback, $Private = null) {
      // Try to create a file-stream
      try {
        $File = new static ($Base, $Filename, true, false, false);
      } catch (Exception $E) {
        return call_user_func ($Callback, null, $Private);
      }
      
      // Bind Event-Handlers
      $Buffer = '';
      
      $File->addHook ('eventReadable', function ($File) use (&$Buffer) {
        // Try to read from stream
        if (($Data = $File->read ()) === false)
          return;
        
        // Push to our buffer
        $Buffer .= $Data;
      });
      
      $File->addHook ('eventClosed', function ($File) use ($Callback, $Private, &$Buffer) {
        // Forward the callback
        return call_user_func ($Callback, $Buffer, $Private);
      });
      
      return $File;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new File I/O-Stream
     * 
     * @param qcEvents_Base $Base
     * @param string $Filename
     * @param bool $Read (optional) Prepare for reading
     * @param bool $Write (optional) Prepare for direct writing
     * @param bool $Truncate (optional) Truncate the file
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base, $Filename, $Read = true, $Write = false, $Truncate = false) { 
      // Handle stream-wrappers
      $Mode = 'c+';
      
      # ZLIB does not support select()-calls
      #if (($p = strpos ($Filename, '://')) !== false) {
      #  $Wrapper = substr ($Filename, 0, $p);
      #  
      #  // Wrappers the are read-xor-write
      #  if (($Wrapper == 'compress.zlib') || ($Wrapper == 'compress.bzip2')) {
      #    if ($Read)
      #      $Mode = 'r';
      #    elseif ($Write)
      #      $Mode = 'w';
      #  }
      #}
      
      // Try to open the file
      if (!is_resource ($fd = fopen ($Filename, $Mode)))
        throw new Exception ('Could not open file');
      
      // Store the filename
      $this->Filename = $Filename;
      
      // Forward the event-base
      parent::__construct ($Base);
      
      // Forward the stream-fd
      $this->setStreamFD ($fd);
      
      // Forward the read/write-status
      $this->watchRead ($Read);
      $this->watchWrite ($Write);
      
      // Check wheter to truncate
      if ($Write && $Truncate)
        $this->truncate ();
    }
    // }}}
    
    // {{{ getFilename
    /**
     * Retrive the filename of this stream
     * 
     * @access public
     * @return string
     **/
    public function getFilename () {
      return $this->Filename;
    }
    // }}}
    
    // {{{ truncate
    /**
     * Truncate this file to a given size or to the size of the actual position
     * 
     * @access public
     * @return bool
     **/
    public function truncate ($Size = null) {
      // Try to access the descriptor
      if (!is_resource ($fd = $this->getWriteFD ()))
        return false;
      
      // Truncate the file
      return ftruncate ($fd, ($Size === null ? ftell ($fd) : $Size));
    }
    // }}}
    
    // {{{ setModificationTime
    /**
     * Set the modification-time of this file
     * 
     * @param int $Timestamp
     * 
     * @access public
     * @return bool
     **/
    public function setModificationTime ($Timestamp) {
      // Store the timestamp here
      $this->modificationTime = $Timestamp;
      
      // Try to set the timestamp initially
      return touch ($this->getFilename (), $Timestamp);
    }
    // }}}
    
    // {{{ ___read
    /**
     * Read from the underlying stream
     * 
     * @param int $Length (optional)
     * 
     * @access protected
     * @return string   
     **/
    protected function ___read ($Length = null) {
      // Retrive our descriptor
      if (!is_resource ($fd = $this->getReadFD ()))
        return false;
      
      // Check wheter to use the default read-length
      if ($Length === null)
        $Length = $this::DEFAULT_READ_LENGTH;
      
      // Try to read from file
      $Result = fread ($fd, $Length);
      
      return $Result;
    }
    // }}}
    
    // {{{ ___write
    /**
     * Write to the underlying stream 
     * 
     * @param string $Data
     * 
     * @access protected
     * @return int The number of bytes that have been written
     **/
    protected function ___write ($Data) {
      // Retrive our descriptor
      if (!is_resource ($fd = $this->getWriteFD ()))
        return false;
      
      // Just write out and return
      return @fwrite ($fd, $Data);
    }
    // }}}
    
    // {{{ ___close
    /**
     * Close the stream at the handler
     * 
     * @access protected
     * @return bool
     **/
    protected function ___close () {
      // Retrive our descriptor and close it
      if ((is_resource ($fd = $this->getReadFD ()) ||
           is_resource ($fd = $this->getWriteFD ())) &&
          !fclose ($fd))
        return false;
      
      // Check wheter to set modification-time
      if ($this->modificationTime !== null)
        touch ($this->getFilename (), $this->modificationTime);
      
      return true;
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Callback: The Event-Loop detected a read-event
     * 
     * @access public
     * @return void  
     **/
    public function raiseRead () {
      // Fire callbacks first
      parent::raiseRead ();
      
      // Check if we reached end-of-file
      if (is_resource ($fd = $this->getReadFD ()) && feof ($fd))
        $this->close ();
    }
    // }}}
  }

?>