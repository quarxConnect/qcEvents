<?PHP

  /**
   * qcEvents - XML-Streams
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Consumer.php');
  require_once ('qcEvents/Trait/Hookable.php');
  require_once ('qcEvents/Stream/XML/Node.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * XML-Stream
   * ----------
   * Simple XML-Stream-Handler
   * 
   * @class qcEvents_Stream_XML
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Stream_XML implements qcEvents_Interface_Consumer {
    use qcEvents_Trait_Hookable;
    
    /* Default Class for XML-Nodes */
    const DEFAULT_XML_NODE = 'qcEvents_Stream_XML_Node';
    
    /* Stream-Source */
    private $Source = null;
    
    /* Buffer for unparsed XML */
    private $xmlBuffer = null;
    
    /* XML-Root-Node of the current node */
    private $xmlNodeRoot = null;
    
    /* Current XML-Node */
    private $xmlNodeCurrent = null;
    
    /* XML-Node to start the stream from our side */
    private $xmlRootLocal = null;
    
    /* Copy of the remote XML-Node the stream was started with */
    private $xmlRootRemote = null;
    
    /* Indicator wheter the XML-Stream was started or not */
    private $streamStarted = false;
    
    /* Initialization-Callback */
    private $initCallback = null;
    
    // {{{ sendXML
    /**
     * Submit XML to other side
     * 
     * @param qcEvents_Stream_XML_Node $XML
     * @param bool $returnLength (optional)
     * 
     * @access public  
     * @return bool  
     */
    public function sendXML (qcEvents_Stream_XML_Node $XML, $returnLength = false) {
      // Check if our socket is really open
      if (!($this->Source instanceof qcEvents_Interface_Sink))
        return false;
      
      // Normalize Array to String   
      $Data = $XML->toString ();
      
      self::__debug (self::DEBUG_PACKETS, 'Sending ' . $Data, __FUNCTION__, __LINE__, __CLASS__, __FILE__);
      
      $this->_LastPacket = time ();
      
      // Try to write to the stream
      if (!$this->Source->write ($Data))
        return false;
      
      if ($returnLength)
        return strlen ($Data);
      
      return true;
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
      if (is_object ($this->xmlRootLocal))
        $this->sendXML ($this->xmlRootLocal);
      
      // Do not restart the XML-Stream without a root-element
      elseif ($this->streamStarted)
        return false;
      
      $this->streamStarted = true;
      $this->xmlRootRemote = null;
      
      // Fire up the callback
      $this->___callback ('xmlStreamStarted');
    }
    // }}}
    
    // {{{ xmlSetRootNode
    /**
     * Set our local root-node
     * 
     * @param qcEvents_Stream_XML_Node $xmlNode
     * 
     * @access public
     * @return bool
     **/
    public function xmlSetRootNode (qcEvents_Stream_XML_Node $xmlNode) {
      $this->xmlRootLocal = $xmlNode;
      $this->xmlRootLocal->forceOpen ();
      
      return true;
    }
    // }}}
    
    // {{{ xmlBufferInsert
    /**
     * Insert a string into our XML-Buffer
     * 
     * @param string $buf
     * 
     * @access private
     * @return void
     **/
    private function xmlBufferInsert ($buf) {
      // Append the new data to our internal buffer
      if ($this->xmlBuffer === null) {
        if (($p = strpos ($buf, '<')) === false)
          return;
        
        $this->xmlBuffer = substr ($buf, $p);
      } else
        $this->xmlBuffer .= $buf;
      
      // Try to find new XML-Nodes  
      $buf = &$this->xmlBuffer;
      
      while (strlen ($buf) > 0) {
        // Check wheter to start a new XML-Node
        if (!is_object ($this->xmlNodeCurrent) || $this->xmlNodeCurrent->isReady ()) {
          // Make sure the next XML-Node starts at first byte of buffer
          if ($buf [0] != '<') {
            // Check if we are able to see the next node
            if (($p = strpos ($buf, '<')) === false)
              break;
            
            // Use the stuff as value if an XML-Node is available
            if (is_object ($this->xmlNodeCurrent))
              $this->xmlNodeCurrent->setValue (html_entity_decode ($val = substr ($buf, 0, $p), ENT_XML1, 'UTF-8'));
            
            // Truncate the buffer
            $buf = substr ($buf, $p);
          }
          
          // Check if this is an XML-Processing-Instruction
          if (substr ($buf, 1, 4) == '?xml') {
            if (($p = strpos ($buf, '?>')) === false)
              break;
            
            // Just discard it
            $buf = substr ($buf, $p + 2);
            
            continue;
          }
           
          // Get the name of the node
          if (($pte = strpos ($buf, '>')) === false)
            break;
          
          if ((($psp = strpos ($buf, ' ')) === false) || ($psp > $pte)) {
            $p = $pte;
            
            if ($buf [$p - 1] == '/')
              $p--;
          } else
            $p = $psp;
          
          $Name = substr ($buf, 1, $p - 1);
          
          while (($buf [$p] == ' ') || ($buf [$p] == "\t") || ($buf [$p] == "\n") || ($buf [$p] == "\r"))
            $p++;
          
          $buf = substr ($buf, $p);
          
          # WHY THIS?!
          # if ($Name [strlen ($Name) - 1] == '/') {
          #   # $buf = '/' . $buf;
          #   $Name = substr ($Name, 0, -1);
          # }
          
          // Check if the name indicates the end of an XML-Node-Block
          if ($Name [0] == '/') {
            // Truncate / from name
            $Name = substr ($Name, 1);
            
            // Make sure we have a current node assigned, its open and names match
            if (!is_object ($this->xmlNodeCurrent) ||
                !$this->xmlNodeCurrent->isOpen () ||
                !($this->xmlNodeCurrent->getName () == $Name)) {
              $this->___callback ('xmlError');
              
              return $this->close ();
            }
            
            // Close the XML-Node
            $this->xmlNodeCurrent->isOpen (false);
            
            // Check if the current node was our current root-node
            if ($this->xmlNodeCurrent === $this->xmlNodeRoot)
              $this->xmlNodeReady ();
            
            // Switch to its parent
            else
              $this->xmlNodeCurrent = $this->xmlNodeCurrent->getParent ();
            
            $buf = substr ($buf, strpos ($buf, '>') + 1);
            
            continue;
            
          // ... or if a new XML-Node is beginning
          } else {
            // Create a new XML-Node-Object
            $this->xmlNodeCurrent = $this->xmlBuildNode ($Name);
            $this->xmlNodeCurrent->isReady (false);
            
            // Check wheter to use this XML-Node as root
            if (!is_object ($this->xmlNodeRoot) && ($this->___callback ('xmlNodeAcceptRoot', $this->xmlNodeCurrent) !== false))
              $this->xmlNodeRoot = $this->xmlNodeCurrent;
          }
        }
        
        // Check if the node is closed
        if ($buf [0] == '>') { 
          if (is_object ($this->xmlNodeCurrent)) {
            // Set Status of the current XML-Node
            $this->xmlNodeCurrent->isOpen (true);
            $this->xmlNodeCurrent->isReady (true);
            
            // Fire callback
            $this->___callback ('xmlNodeStart', $this->xmlNodeCurrent);
          }
           
          $this->xmlBuffer = $buf = substr ($buf, 1);
          
          // Forward the root-object
          if (!is_object ($this->xmlRootRemote)) {
            $this->xmlRootRemote = $this->xmlNodeCurrent;
            $this->xmlNodeCurrent = null;
            $this->xmlNodeRoot = null;   
            
            // Raise callbacks
            if ($this->initCallback)
              $this->___raiseCallback ($this->initCallback [0], true, $this->initCallback [1]);
            
            $this->___callback ('xmlReceiveRoot', $this->xmlRootRemote);
            
            // Make sure the init-callback is removed
            $this->initCallback = null;
          }
           
        // Check if the node is closed (and does not carry any contents)
        } elseif (($buf [0] == '/') && ($buf [1] == '>')) {
          if (is_object ($this->xmlNodeCurrent) && !$this->xmlNodeCurrent->isReady ()) {
            // Mark this node as ready
            $this->xmlNodeCurrent->isOpen (false);
            $this->xmlNodeCurrent->isReady (true);
            
            // Check if the node is our root-node
            if ($this->xmlNodeCurrent == $this->xmlNodeRoot) {
              if ($this->xmlNodeReady ())
                continue;
            
            // Move to parent node
            } else
              $this->xmlNodeCurrent = $this->xmlNodeCurrent->getParent ();
          }
           
          $buf = ltrim (substr ($buf, 2));
          
        // Append a key-value attribute
        } else {
          $l = strlen ($buf);
          
          // Find Stop-Words
          if (($psp = strpos ($buf, ' ')) === false)
            $psp = $l;
          
          if (($peq = strpos ($buf, '=')) === false)
            $peq = $l;
          
          if (($pte = strpos ($buf, '>')) === false)
            $pte = $l;
          
          // Check if there is an attribute available
          if (($psp == $peq) && ($psp == $pte))
            break;
          
          // Check type of attribute
          $next = min ($psp, $peq, $pte);
          
          // Standalone attribute without any value
          if (($next == $psp) || ($next == $pte)) {
            if (($next == $pte) && ($buf [$next - 1] == '/'))
              $next--;
            
            $Attribute = substr ($buf, 0, $next);
            $Value = null;
            
            $buf = ltrim (substr ($buf, $next));
          
          // Attribute with value
          } else {
            // Check if there is enough data on the buffer
            if ($psp == $pte)
              break;
            
            // Start of attribute-value
            $v = $next + 1;
            
            // Check if the value is escaped
            if (($buf [$v] == '"') || ($buf [$v] == "'")) {
              if (($ve = strpos ($buf, $buf [$v], $v + 1)) === false)
                break;
              
              $v++;
              $p = 1;
            } else {
              $p = 0;
              $ve = min ($psp, $pte);
            }
            
            // Retrive the name and value
            $Attribute = substr ($buf, 0, $next);
            $Value = substr ($buf, $v, $ve - $v);
            
            // Truncate the buffer
            $buf = ltrim (substr ($buf, $ve + $p));
          }
          
          // Store the attribute
          if (is_object ($this->xmlNodeCurrent) && !$this->xmlNodeCurrent->isReady ())
            $this->xmlNodeCurrent->setAttribute ($Attribute, $Value);
        }
      }
      
      // Store our buffer
      $this->xmlBuffer = $buf;
    }
    // }}}
    
    // {{{ xmlBuildNode
    /**
     * Create a new XML-Node Object
     * 
     * @param string $Name
     * 
     * @access private
     * @return object
     **/
    private function xmlBuildNode ($Name) {
      // Check if there is an XML-Node to which we may offload this request
      if (is_object ($this->xmlNodeCurrent))
        return $this->xmlNodeCurrent->createChild ($Name);
      
      // Try to find a matching class for this Node-name
      $Classes = get_declared_classes ();
      $Candidates = array ();
      
      foreach ($Classes as $Class)
        if (is_subclass_of ($Class, 'qcEvents_Stream_XML_Node') &&
            defined ($Class . '::NODE_NAME') &&
            (constant ($Class . '::NODE_NAME') == $Name))
          $Candidates [] = $Class;
      
      // Check if a candidate was found or try to use some default stuff
      if (count ($Candidates) == 0) {
        $Class = $this::DEFAULT_XML_NODE;
        
        if (!is_subclass_of ($Class, 'qcEvents_Stream_XML_Node'))
          $Class = 'qcEvents_Stream_XML_Node';
      } else
        # TODO: Check how to proceed if c>1
        $Class = array_shift ($Candidates);
      
      return $Class::createXMLNode ($Name);
    }
    // }}}
    
    // {{{ xmlNodeReady
    /**
     * An XML-Node was parsed completly any may be forwarded
     * 
     * @access private
     * @return void
     **/
    private function xmlNodeReady () {
      // Remember the node and reset
      $xmlNode = $this->xmlNodeRoot;
      $this->xmlNodeRoot = null;   
      $this->xmlNodeCurrent = null;
      
      // Handle the forward
      $this->___callback ('xmlReceiveNode', $xmlNode);
    }
    // }}}
    
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
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      if ($Source === $this->Source)
        $this->xmlBufferInsert ($Data);
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
      // Try to gracefully close the stream
      if ($this->xmlRootLocal && $this->Source) {
        $Source = $this->Source;
        $this->Source = null;
        
        $this->___callback ('eventClosed');
        
        return $Source->write ('</' . $this->xmlRootLocal->getName () . '>' . "\r\n")->then (
          function () use ($Source) {
            return $Source->close ();
          },
          function () use ($Source) {
            return $Source->close ();
          }
        );
      
      // Just close the stream
      } elseif ($this->Source) {
        $Source = $this->Source;
        $this->Source = null;
        
        $this->___callback ('eventClosed');
        
        return $Source->close ();
      
      // Just raise the callback
      } elseif ($this->streamStarted)
        $this->___callback ('eventClosed');
      
      // Reset our state   
      $this->resetState ();
      
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
      // Reset our state
      $this->resetState ();
      
      // Write out the first XML-Data
      if ($Source instanceof qcEvents_Interface_Sink)
        $Source->write ('<?xml version="1.0"?>' . "\n");
      
      // Assign the source
      $this->Source = $Source;
      
      // Perform an internal restart
      $this->restartStream ();
      
      // Fire callback
      $this->___callback ('eventPiped', $Source);
      
      // Register Init-Callback
      $this->initCallback = array ($Callback, $Private);
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
      // Reset our state   
      $this->resetState ();
      
      // Write out the first XML-Data
      $Source->write ('<?xml version="1.0"?>' . "\n");
      
      // Assign the source
      $this->Source = $Source;
      
      // Perform an internal restart
      $this->restartStream ();
      
      // Fire callback
      $this->___callback ('eventPipedStream', $Source);
      
      // Register Init-Callback
      $this->initCallback = array ($Callback, $Private);
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this sink
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
     * @return void  
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      // Reset our state   
      $this->resetState ();
      
      // Fire callback
      $this->___raiseCallback ($Callback, true, $Private);
      $this->___callback ('eventUnpiped', $Source);
    }
    // }}}
    
    // {{{ resetState
    /**
     * Reset our internal state
     * 
     * @access private
     * @return void
     **/
    private function resetState () {
      if ($this->initCallback)
        $this->___raiseCallback ($this->initCallback [0], false, $this->initCallback [1]);
      
      $this->xmlBuffer = '';
      $this->xmlNodeRoot = null;
      $this->xmlNodeCurrent = null;
      $this->xmlRootRemote = null;
      $this->streamStarted = false;
      $this->initCallback = null;
    }
    // }}}
    
    
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
    
    
    // {{{ eventClosed
    /**
     * Callback: The XML-Stream was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ xmlStreamStarted
    /**
     * Callback: XML-Stream was started
     * 
     * @access protected
     * @return void
     **/
    protected function xmlStreamStarted () { }
    // }}}
    
    // {{{ xmlError
    /**
     * Callback: An XML-Error occured on the stream
     * 
     * @access protected
     * @return void
     **/
    protected function xmlError () { }
    // }}}
    
    // {{{ xmlNodeAcceptRoot
    /**
     * Callback: Accept an XML-Node as Root-Node for an XML-Block
     * 
     * @param qcEvents_Stream_XML_Node $xmlNode
     * 
     * @access protected
     * @return bool
     **/
    protected function xmlNodeAcceptRoot (qcEvents_Stream_XML_Node $xmlNode) { }
    // }}}
    
    // {{{ xmlNodeStart
    /**
     * Callback: Parsing of a node was started
     * 
     * @param qcEvents_Stream_XML_Node $xmlNode
     * 
     * @access protected
     * @return void
     **/
    protected function xmlNodeStart (qcEvents_Stream_XML_Node $xmlNode) { }
    // }}}
    
    // {{{ xmlReceiveRoot
    /**
     * Callback: XML-Node for this stream was received
     * 
     * @param qcEvents_Stream_XML_Node $xmlNode
     * 
     * @access protected
     * @return void
     **/
    protected function xmlReceiveRoot (qcEvents_Stream_XML_Node $xmlNode) { }
    // }}}
    
    // {{{ xmlReceiveNode
    /**
     * Callback: An XML-Node/Block was received
     * 
     * @param qcEvents_Stream_XML_Node $xmlNode
     * 
     * @access protected
     * @return void
     **/
    protected function xmlReceiveNode (qcEvents_Stream_XML_Node $xmlNode) { }
    // }}}
  }

?>