<?PHP

  require_once ('qcEvents/socket.php');
  require_once ('qcEvents/socket/stream/xml/tag.php');
  
  /**
   * XML-Stream
   * ----------
   * Simple XML-Stream-Handler
   * 
   * @class qcEvents_Socket_Stream_XML
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * @license http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons Attribution-Share Alike 3.0 Germany
   **/
  class qcEvents_Socket_Stream_XML extends qcEvents_Socket {
    private $tagBuffer = null;
    private $tagRoot = null;
    private $tagCurrent = null;
    private $tagNext = null;
    private $tagDebug = true;
    private $tagWaiting = null;
    
    private $rootLocal = null;
    private $rootRemote = null;
    
    private $streamStarted = false;
    
    // {{{ startStream
    /**
     * Callback: Connection is established and stream is started
     * 
     * @access protected
     * @return void
     **/
    protected function startStream () { }
    // }}}
    
    // {{{ receiveRoot
    /**
     * Callback: The root-element of the remote stream was received
     * 
     * @param object $Tag
     * 
     * @access protected
     * @return void
     **/
    protected function receiveRoot ($Tag) { }
    // }}}
    
    // {{{ receiveTag
    /**
     * Receive a complete XML-Tag (including children)
     * 
     * @param object $Tag
     * 
     * @access protected  
     * @return void
     **/
    protected function receiveTag ($Tag) { }
    // }}}
    
    // {{{ setStream
    /**
     * Set stream for our parser
     * 
     * @param resource $Stream
     * 
     * @access public
     * @return void
     **/
    public function setStream ($Stream) {
      if (!is_resource ($Stream))
        return false;
      
      if ($this->isOnline ())
        $this->disconnect ();
      
      $this->setConnection ($Stream);
    }
    // }}}
    
    // {{{ restartStream
    /**
     * Force a start/restart of our XML-stream
     * 
     * @access public
     * @return void
     **/
    public function restartStream () {
      // Check if there is a root-element registered and send it
      if (is_object ($this->rootLocal))
        $this->sendXML ($this->rootLocal);
      
      // Do not restart the XML-Stream without a root-element
      elseif ($this->streamStarted)
        return false;
      
      $this->streamStarted = true;
      $this->rootRemote = null;
      
      // Fire up the callback
      $this->startStream ();
    }
    // }}}
    
    // {{{ setRootTag
    /**
     * Set our local root-tag
     * 
     * @param object $Tag
     * 
     * @access public
     * @return bool
     **/
    public function setRootTag ($Tag) {
      if (!($Tag instanceof qcEvents_Socket_Stream_XML_Tag))
        return false;
      
      if ($this->isOnline ())
        return false;
      
      $this->rootLocal = $Tag;
      $this->rootLocal->forceOpen ();
      
      return true;
    }
    // }}}
    
    
    // {{{ connected
    /**
     * Start the XML-Stream when the connection becomes ready
     * 
     * @access public
     * @return void
     **/
    protected function connected () {
      $this->write ('<?xml version="1.0"?>' . "\n");
      $this->restartStream ();
    }
    // }}}
    
    // {{{ closed
    /**
     * Callback: Connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function closed () {
      trigger_error ('Unexpected connection-close');
    }
    // }}}
    
    // {{{ receive
    /**
     * Callback: Invoked whenever incoming data is received
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function receive ($Data) {
      $this->bufferInsert ($Data);
    }
    // }}}
    
    // {{{ bufferInsert
    /**
     * Insert a string into our XML-Buffer
     * 
     * @param string $buf
     * 
     * @access public
     * @return void
     **/
    public function bufferInsert ($buf) {
      // Append the new data to our internal buffer
      if ($this->tagBuffer === null) {
        if (($p = strpos ($buf, '<')) === false)
          return;
        
        $this->tagBuffer = substr ($buf, $p);
      } else
        $this->tagBuffer .= $buf;
      
      $buf = &$this->tagBuffer;
      
      while (strlen ($buf) > 0) {
        // Start a new tag
        if (!is_object ($this->tagCurrent) || $this->tagCurrent->isReady ()) {
          if ($buf [0] != '<') {
            if (($p = strpos ($buf, '<')) === false)
              break;
            
            if (is_object ($this->tagCurrent)) {
              $this->tagCurrent->setValue ($val = substr ($buf, 0, $p));
              
              if ($this->tagDebug)
                echo '  Content ', $val, "\n";
            }
            
            $buf = substr ($buf, $p);
          }
          
          if (substr ($buf, 1, 4) == '?xml') {
            if (($p = strpos ($buf, '?>')) === false)
              break;
            
            $buf = substr ($buf, $p + 2);
            
            continue;
          }
          
          // Get the name of the tag
          $p1 = strpos ($buf, ' ');
          
          if (($p2 = strpos ($buf, '>')) === false)
            break;
          
          if (($p1 < $p2) && ($p1 !== false)) {
            $Name = substr ($buf, 1, $p1 - 1);
            $buf = substr ($buf, $p1 + 1);
          } else {
            $Name = substr ($buf, 1, $p2 - 1);
            
            if ($buf [$p2 - 1] == '/')
              $buf = substr ($buf, $p2 - 1);
            else
              $buf = substr ($buf, $p2);
          }
          
          if ($Name [strlen ($Name) - 1] == '/') {
            # $buf = '/' . $buf;
            $Name = substr ($Name, 0, -1);
          }
          
          if ($Name [0] == '/') {
            // Truncate / from name
            $Name = substr ($Name, 1);
            
            if (!is_object ($this->tagCurrent)) {
              $tag = null;
              trigger_error ('Closing XML-Tag without an open one');
            } elseif ($this->tagCurrent->isOpen ())
              $tag = $this->tagCurrent;
            else
              $tag = $this->tagCurrent->getParent ();
            
            if (is_object ($tag)) {
              if ($tag->getName () != $Name)
                trigger_error ('Open tag does not match closing tag: ' . $tag->getName () . ' vs. ' . $Name);
              
              $tag->isOpen (false);
              $this->tagCurrent = $tag->getParent ();
              
              if ($this->tagDebug)
                echo 'Closing tag', "\n";
              
              if ((!is_object ($this->tagCurrent) || ($tag == $this->tagRoot)) && $this->forwardEvent ())
                break;
            }
            
          } else {
            if ($this->tagDebug)
              echo 'Creating tag ', $Name, "\n";
            
            $this->tagCurrent = self::buildTag ($Name);
            $this->tagCurrent->isReady (false);
            
            if (is_object ($P = $this->tagCurrent->getParent ()))
              echo '  - Parent ', $P->getName (), "\n";
          
            if (!is_object ($this->tagRoot) && $this->tagAcceptRoot ($this->tagCurrent)) {
              $this->tagRoot = $this->tagCurrent;
              echo '  - Using as root', "\n";
            }
          }
        }
        
        // Parse attributes of tag
        $p1 = strpos ($buf, '=');
        $p2 = strpos ($buf, ' ');
        
        // Check if the tag is closed
        if ($buf [0] == '>') { 
          if (is_object ($this->tagCurrent) && !$this->tagCurrent->isReady ()) {
            $this->tagCurrent->isOpen (true);
            $this->tagCurrent->isReady (true);
            $this->tagReady ($this->tagCurrent);
            
            if ($this->tagDebug)
              echo '  Close tag [open]', "\n";
          }
           
          $this->tagBuffer = $buf = ltrim (substr ($buf, 1));
          
          // Forward the root-object
          if (is_object ($this->rootLocal) && !is_object ($this->rootRemote)) {
            $this->rootRemote = $this->tagCurrent;
            $this->tagCurrent = null;
            $this->tagRoot = null;
            $this->receiveRoot ($this->rootRemote);
          }
        
        // Check if the tag is closed and there is data pending
        } elseif (substr ($buf, 0, 2) == "/>") {
          if (is_object ($this->tagCurrent) && !$this->tagCurrent->isReady ()) {
            $this->tagCurrent->isOpen (false);
            $this->tagCurrent->isReady (true);
            $this->tagReady ($this->tagCurrent);
            
            if ($this->tagDebug)
              echo '  Close tag [closed]', "\n";
            
            if ($this->tagCurrent == $this->tagRoot) {
              if ($this->forwardEvent ())
                break;
            } else
              $this->tagCurrent = $this->tagCurrent->getParent ();
          }
           
          $buf = ltrim (substr ($buf, 2));
          
        // Append a key-value attribute
        } elseif ((($p1 < $p2) && ($p1 !== false)) || (($p1 !== false) && ($p2 == false))) {
          $Property = substr ($buf, 0, $p1);
          $Sep = $buf [$p1 + 1];
          
          if (($Sep == "'") || ($Sep == '"')) {
            $s = $p1 + 2;
            $e = strpos ($buf, $Sep, $s);
            
            $Value = substr ($buf, $s, $e - $s);
            $buf = ltrim (substr ($buf, $e + 1));
          } else {
            $p3 = strpos ($buf, '>');
            
            if (($p2 < $p3) && ($p3 !== false)) {
              $Value = substr ($buf, $p1 + 1, $p2 - $p1 - 1);
              $buf = ltrim (substr ($buf, $p2 + 1));
            } else {
              if ($buf [$p3 - 1] == '/')
                $p3--;
              
              $Value = substr ($buf, $p1 + 1, $p3 - $p1 - 2);
              $buf = ltrim (substr ($buf, $p3));
            }
          }
          
          if (is_object ($this->tagCurrent) && !$this->tagCurrent->isReady ()) {
            if ($this->tagDebug)
              echo '  Attribute ', $Property, ' = ', $Value, "\n";
            
            $this->tagCurrent->setAttribute ($Property, $Value);
          }
        
        // Just set a stand-alone attribute
        } else {
          if ($p2 === false)
            $p2 = strpos ($buf, '>');
          
          if ($p2 === false)
            break;
          
          if ($buf [$p2 - 1] == '/')
            $p2--;
          
          $Property = substr ($buf, 0, $p2);
          $buf = ltrim (substr ($buf, $p2));
          
          if (is_object ($this->tagCurrent) && !$this->tagCurrent->isReady ()) {
            if ($this->tagDebug)
              echo '  Attribute ', $Property, "\n";
            
            $this->tagCurrent->setAttribute ($Property, true);
          }
        }
      }
      
      // Store our buffer
      $this->tagBuffer = $buf;
    }
    // }}}
    
    // {{{ buildTag
    /**
     * Create a new XML-Tag Object
     * 
     * @param string $Name
     * 
     * @access private
     * @return object
     **/
    private function buildTag ($Name) {
      if (is_object ($this->tagCurrent))
        return $this->tagCurrent->createSubtag ($Name);
      
      $Classes = get_declared_classes ();
      $Candidates = array ();
      
      foreach ($Classes as $Class)
        if (is_subclass_of ($Class, 'qcEvents_Socket_Stream_XML_Tag') &&
            defined ($Class . '::TAG_NAME') &&
            (constant ($Class . '::TAG_NAME') == $Name))
          $Candidates [] = $Class;
      
      if (count ($Candidates) > 0) {
        # TODO: Check how to proceed if c>1
        $Class = array_shift ($Candidates);
      } else {
        $cls = get_class ($this);
        
        if (defined ($cls . '::DEFAULT_XML_TAG'))
          $Class = constant ($cls . '::DEFAULT_XML_TAG');
        
        if (!is_subclass_of ($Class, 'qcEvents_Socket_Stream_XML_Tag'))
          $Class = 'qcEvents_Socket_Stream_XML_Tag';
      }
      
      echo 'Creating ', $Class, ' for ', $Name, "\n";
      
      return new $Class ($Name, null);
    }
    // }}}
    
    // {{{ forwardEvent
    /**
     * Forward a stream-block
     * 
     * @access private
     * @return void
     **/
    private function forwardEvent () {
      // Remember the tag and reset
      $Tag = $this->tagRoot;
      $this->tagRoot = null;   
      $this->tagCurrent = null;
      
      // Handle the forward
      $this->receiveTag ($Tag);
      
      return $this->tagWaiting;
    }
    // }}}
    
    // {{{ tagAcceptRoot
    /**
     * Check wheter to use a given tag as root
     * 
     * @access protected
     * @return bool
     **/
    protected function tagAcceptRoot ($Tag) {
      return true;
    }
    // }}}
    
    protected function tagReady ($Tag) {
      if ($this->tagNext === true)
        $this->tagNext = $Tag;
    }
    
    protected function tagReadNext () {
      $this->tagNext = true;
      
      while (!is_object ($this->tagNext) && $this->isOnline ())
        $this->readBlock ();
      
      $rc = $this->tagNext;
      $this->tagNext = null;
      
      return $rc;
    }
    
    
    /* Debug types */
    const DEBUG_FATAL = 0;
    const DEBUG_ERROR = 1;
    const DEBUG_WARN = 2;
    const DEBUG_NOTICE = 3;
    const DEBUG_PACKETS = 4; 
    const DEBUG_DEBUG = 5;
    
    /* Debug-Level */
    private $_DebugLevel = self::DEBUG_FATAL;
    
    // {{{ setDebug
    /**
     * Sets the Debug-Level
     * 
     * @param int $Level (optional) Debug-Level to set
     * 
     * @access public
     * @return void
     */
    public function setDebug ($Level = self::DEBUG_DEBUG) {
      $this->_DebugLevel = $Level;
    }
    // }}}
    
    // {{{ __debug
    /**
     * Output some Debug-Info
     * 
     * @param int $lvl Debug-Level 
     * @param string $msg Debug-Message
     * @param string $fnc Function comming the message from
     * @param int $lin Line in code of message
     * @param string $cls Class sending debug-info
     * @param string $fle File with code
     * @param mixed $rc (optional) Default return for this function
     * 
     * @access public
     * @return mixed
     */
    public function __debug ($lvl, $msg, $fnc, $lin, $cls, $fle, $rc = null) {
      if ($lvl < $this->_DebugLevel + 1)
        echo '[', $lin ,'] ', $cls, '::', $fnc, ' ', $msg, "\n";
      
      return $rc;
    }
    // }}}
    
    // {{{ readBlock
    /**
     * Try to read the next XML-Block from stream
     * 
     * @access public
     * @return void
     **/
    public function readBlock () {
      // Check if our socket is open
      if (!$this->isOnline ())
        return;
      
      # TODO!
      
      // Check wheter to auto-create an event-handler
      if (!$this->isBound ()) {
        trigger_error ('This XML_Stream is not bound to a qcEvents_Base, auto-creating one');
        
        require_once ('qcEvents/base.php');
        
        $Base = new qcEvents_Base;
        $Base->addEvent ($this);
      }
      
      // Run one single loop
      parent::loopOnce ();
    }
    // }}}
    
    // {{{ waitBlock
    /**
     * Block until an expected XML-Tag appears
     * 
     * @param mixed $Types (optional) Wait for this tag-name(s)
     * @param mixed $IDs (optional) Wait for this tag-id(s)
     * 
     * @access public
     * @return object
     **/
    public function waitBlock ($Types = null, $IDs = null) {
      $this->tagWaiting = true;
      
      if (!is_array ($Types))
        $Types = ($Types !== null ? array ($Types) : array ());
      
      if (!is_array ($IDs))
        $IDs = ($IDs !== null ? array ($IDs) : array ());
      
      while (is_object ($Next = $this->tagReadNext ()))
        if (is_object ($Block = $this->waitBlockMatch ($Next, $Types, $IDs))) {
          $this->tagWaiting = null;
          
          return $Block;
        }
      
      $this->tagWaiting = null;
      
      return false;
    }
    // }}}
    
    // {{{ waitBlockMatch
    /**
     * Check if a given XML-Tag matches the one we are waiting for
     * 
     * @access protected
     * @return object
     **/
    protected function waitBlockMatch ($Tag, $Types, $IDs) {
      if (in_array ($Tag->getName (), $Types) ||
          (($ID = $Tag->getAttribute ('id', false)) && in_array ($ID, $IDs)))
        return $Tag;
      
      return false;
    }
    // }}}
    
    // {{{ toXML
    /**
     * Convert an object or array to XML
     * 
     * @param mixed $Data Object or Array to convert
     * 
     * @access public
     * @return string
     */
    public function toXML ($Data) {
      // Verify data-integry
      if (is_object ($Data)) {
        if ($Data instanceof qcEvents_Socket_Stream_XML_Tag)
          return $Data->toString ();
        
        $Data = (array)$Data;
      }
       
      if (!is_array ($Data))
        return false;
      
      // Start XML-Tag
      $buf = '<' . str_replace ('__', ':', $Data ['Name']) . ' ';
      
      // Put properties into Tag
      foreach ($Data as $Name=>$Value) {
        if (in_array ($Name, array ('Name', 'Subtags', 'Value', 'Parent')))
          continue;
        
        if (is_object ($Value)) {
          if (!isset ($Data ['Subtags'][$Name]))
            $Data ['Subtags'][$Name] = array ();
          
          $Data ['Subtags'][$Name][] = $Value;
          
          continue;
        }
         
        $buf .= str_replace ('__', ':', $Name) . '="' . addslashes ($Value) . '" ';
      }
       
      // Close tag
      if (!isset ($Data ['Value']) && (!isset ($Data ['Subtags']) || (count ($Data ['Subtags']) < 1)))
        $buf .= '/>';
      else {
        $buf = rtrim ($buf) . '>';
        
        // Include value or subtags
        if (isset ($Data ['Value'])) {
          $xml = $Data ['Value'];
          $xml = str_replace ('&', '&amp;', $xml);
          $xml = str_replace (array ('<', '>'), array ('&lt;', '&gt;'), $xml);
          #$buf .= $xml;
          # $buf .= htmlentities ($Data ["Value"], ENT_COMPAT, "UTF-8");
          $buf .= $xml;
        } elseif (isset ($Data ['Subtags']))
          foreach ($Data ['Subtags'] as $Tags) {
            if (!is_array ($Tags))
              $Tags = array ($Tags);
            
            foreach ($Tags as $Tag)
              $buf .= $this->toXML ($Tag);
          }
           
        // Close tag again ;)
        $buf .= '</' . str_replace ('__', ':', $Data ['Name']) . '>';
      }
       
      // Return XML
      return $buf; 
    }
    // }}}
    
    // {{{ sendXML
    /**
     * Submit XML to other side
     * 
     * @param mixed $Data Data to submit
     * 
     * @access public
     * @return bool  
     */
    public function sendXML ($Data, $returnLength = false) {
      // Check if our socket is really open
      if (!$this->isOnline ())
        return false;
      
      // Normalize Array to String   
      if (is_array ($Data) || is_object ($Data))
        $Data = $this->toXML ($Data);
      
      self::__debug (self::DEBUG_PACKETS, 'Sending ' . $Data, __FUNCTION__, __LINE__, __CLASS__, __FILE__);
      
      $this->_LastPacket = time ();
      
      if (!$this->write ($Data))
        return false;
      
      if ($returnLength)
        return strlen ($Data);
      
      return true;
    }
    // }}}
  }

?>