<?PHP

  // Setup test-environment
  error_reporting (E_ALL);
  set_include_path (dirname (__FILE__) . '/../../' . PATH_SEPARATOR . get_include_path ());

  echo 'Started at ', $s = microtime (true), "\n";

  // Create a new event-base for the test
  require_once ('qcEvents/Base.php');

  $Base = new qcEvents_Base;
  
  // Create a new Websocket-Connection with Pusher (and Bitstamp-Live-Ticker)
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/Websocket.php');

  # qcEvents_Stream_Websocket::$debugHooks = true;
  
  $Websocket = new qcEvents_Stream_Websocket (qcEvents_Stream_Websocket::TYPE_CLIENT, null, '/app/de504dc5763aeef9ff52?protocol=7&client=qc', 'https://www.bitstamp.net');
  $Socket = new qcEvents_Socket ($Base);
  $Socket->connect ('ws.pusherapp.com', 443, $Socket::TYPE_TCP, true);
  $Socket->pipeStream ($Websocket, true, function (qcEvents_Socket $Socket, $Status) use ($Websocket) {
    if (!$Status)
      die ('Websocket-Connection failed' . "\n");
    
    // Subscribe to live-trades-channel
    $JSON = array (
      'event' => 'pusher:subscribe',
      'data' => array (
        'auth' => null,
        'channel_data' => null,
        'channel' => 'live_trades',
      ),
    );
    
    $Websocket->sendMessage (new qcEvents_Stream_Websocket_Message ($Websocket, 0x01, json_encode ($JSON)));
  });
  $Websocket->addHook ('websocketMessage', function (qcEvents_Stream_Websocket $Websocket, qcEvents_Stream_Websocket_Message $Message) {
    // Check if it's a text-message
    if ($Message->getOpcode () != 0x01)
      return;
    
    // Try to parse the pusher-event
    $JSON = json_decode ($Message->getData ());
    
    // Filter for trade-events
    if ($JSON->event != 'trade')
      return;
    
    // Read the trade
    $Trade = json_decode ($JSON->data);
    
    // Output the trade
    echo $Trade->amount_str, ' BTC at ', $Trade->price_str, ' USD', "\n";
  });
  
  $Base->loop ();
  
?>