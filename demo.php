<?PHP

  /**
   * qcEvents - Demo application
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
  
  /**
   * qcEvents demo-application
   * --------------------------
   * 
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   **/
  
  error_reporting (E_ALL);
  
  // Just to be sure
  set_include_path ("../" . PATH_SEPARATOR . get_include_path ());
  
  // Load the qcEvents-Library
  require_once ('qcEvents/Base.php');
  require_once ('qcEvents/Event.php');
  
  // Create a new event-base
  $Base = new qcEvents_Base;
  
  class Server_Socket extends qcEvents_Event {
    private $Socket = null;
    
    // {{{ __construct
    /**
     * Create a new server-socket and setup the event
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Create a server-socket
      $this->Socket = stream_socket_server ("tcp://0.0.0.0:1024");
      
      // Setup our parent event
      parent::__construct ($this->Socket, true, false, null);
    }
    // }}}
    
    // {{{ readEvent
    /**
     * This callback is invoked whenever a new connection arrives
     * 
     * @access public
     * @return void
     **/
    public function readEvent () {
      $Client = stream_socket_accept ($this->Socket, null, $Peer);
      
      fwrite ($Client, "Hello World!\n");
      
      print "Client $Peer accepted, greeted and dismissed\n";
    }
    // }}}
  }
  
  // Check our mode
  if (qcEvents_Base::checkLibEvent ())
    print "Running with native libEvent-Support\n";
  else
    print "Running with php-coded Event-Support\n";
  
  // Create the server-class
  $Server = new Server_Socket;
  
  // Add the server to our event-handler
  $Base->addEvent ($Server);
  
  print "Server created, waiting for events\n";
  
  // Run the main-loop
  $Base->loop ();

?>