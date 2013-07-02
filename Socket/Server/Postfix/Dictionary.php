<?PHP

  /**
   * qcEvents - Postfix Dictionary Server
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Socket/Client.php');
  
  /**
   * Postfix Dictionary Server
   * -------------------------
   * Simple and abstract interface to serve requests to Postfix-Dictionaries via TCP-Maps
   * 
   * @class qcEvents_Socket_Server_Postfix_Dictionary
   * @extends qcEvents_Socket_Buffer
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Server_Postfix_Dictionary extends qcEvents_Socket_Client {
    /* Tell our parent controller to use the line-buffer */
    const USE_LINE_BUFFER = true;
    
    private $Function = array (
      'get' => 'lookupKey',
      'set' => 'updateKey',
    );
    
    private $Responded = true;
    
    // {{{ receivedLine
    /**
     * Callback: Handle an incoming lookup-request
     * 
     * @param string $Line
     *  
     * @access protected
     * @return bool
     **/
    protected function receivedLine ($Line) {
      // Check for an empty request
      if (strlen ($Line) == 0)
        return $this->permanentError ('Syntax error');
      
      // Parse the command
      $Command = explode (' ', trim ($Line));
      unset ($Line);
      
      // Check if there was anything parsed
      if (count ($Command) == 0)
        return $this->permanentError ('Syntax error');
      
      // Execute the command
      if (isset ($this->Function [$Command [0]])) {
        // Retrive the command
        $Command [0] = $this->Function [$Command [0]];
        
        // Exectute the command as callback
        $this->Responded = false;
        
        if (!call_user_func_array (array ($this, '___callback'), $Command) && !$this->Responded)
          return $this->permanentError ('Command failed');
      }
    }
    // }}}
    
    // {{{ permanentError
    /**
     * Raise a permanent error
     * 
     * @param string $Descr (optional) Describtive text for error
     * 
     * @access public
     * @return bool
     */
    public function permanentError ($Descr = null) {
      if ($Descr === null)
        $Descr = 'Undefined internal error';
      
      return $this->respond (500, $Descr);
    }
    // }}}
    
    // {{{ temporaryError
    /**
     * Raise a temporary error
     * 
     * @param string $Descr (optional) Describtive text for error
     * 
     * @access public
     * @return bool  
     */
    public function temporaryError ($Descr = null) {
      if ($Descr === null)
        $Descr = 'Undefined internal error';
      
      return $this->respond (400, $Descr);
    }
    // }}}
    
    // {{{ respond
    /**
     * Write a response to the stream
     * 
     * @param int $Code
     * @param string $Text
     * 
     * @access protected
     * @return void
     */
    protected function respond ($Code, $Text) {
      if (!$this->Responded)
        $this->write ($Code . ' ' . $this->encodeOutput ($Text) . "\n");
      
      $this->Responded = true;
    }
    // }}}
    
    // {{{ encodeOutput
    /**
     * Prepare a textual string to be sent over the wire
     * 
     * @param string $Text
     * 
     * @access protected
     * @return string
     **/
    protected function encodeOutput ($Text) {
      // Allowed Characters on the wire
      $Chars = ',+-_.@abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
      
      // Encode the output
      for ($i = 0; $i < strlen ($Text); $i++)
        if (strpos ($Chars, $Text [$i]) === false) {
          $Text = substr ($Text, 0, $i) . '%' . sprintf ('%02X', ord ($Text [$i])) . substr ($Text, $i + 1);
          $i += 2;
        }
      
      return $Text;
    }
    // }}}
    
    // {{{ lookupKey
    /**
     * Lookup a key
     * 
     * @param string $Key
     * 
     * @access protected
     * @return bool
     **/
    protected function lookupKey ($Key) {
      return $this->temporaryError ('Unimplemented');
    }
    // }}}
    
    // {{{ updateKey
    /**
     * Update a key
     * 
     * @param string $Key
     * @param string $Value
     * 
     * @access protected
     * @return bool
     **/
    protected function updateKey ($Key, $Value) {
      return $this->temporaryError ('Unimplemented');
    }
    // }}}
  }

?>