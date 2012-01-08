<?PHP

  /**
   * qcEvents demo-application
   * --------------------------
   * 
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * @license http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons Attribution-Share Alike 3.0 Germany
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