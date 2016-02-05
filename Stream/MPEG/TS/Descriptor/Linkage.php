<?PHP

  require_once ('qcEvents/Stream/MPEG/TS/Descriptor.php');
  
  class qcEvents_Stream_MPEG_TS_Descriptor_Linkage extends qcEvents_Stream_MPEG_TS_Descriptor {
    const DESCRIPTOR_TAG = 0x4A;
    
    private $transportStreamID = 0x0000;
    private $originalNetworkID = 0x0000;
    private $serviceID = 0x0000;
    private $linkageType = 0x00;
    
    private $handOverType = null;
    private $originType = null;
    private $networkID = null;
    private $initialServiceID = null;
    
    private $targetListed = null;
    private $eventSimulcast = null;
    
    private $Private = '';
    
    protected function parse ($Data) {
      $Offset = 0;
      
      $this->transportStreamID = (ord ($Data [$Offset++]) << 8) | ord ($Data [$Offset++]);
      $this->originalNetworkID = (ord ($Data [$Offset++]) << 8) | ord ($Data [$Offset++]);
      $this->serviceID = (ord ($Data [$Offset++]) << 8) | ord ($Data [$Offset++]);
      $this->linkageType = ord ($Data [$Offset++]);
      
      if ($this->linkageType == 0x08) {
        $Flags = ord ($Data [$Offset++]);
        $this->handOverType = (($Flags & 0xF0) >> 4);
        $this->originType = ($Flags & 0x01);
        
        if (($this->handOverType > 0x00) && ($this->handOverType < 0x04))
          $this->networkID = (ord ($Data [$Offset++]) << 8) | ord ($Data [$Offset++]);
        
        if ($this->originType == 0x00)
          $this->initialServiceID = (ord ($Data [$Offset++]) << 8) | ord ($Data [$Offset++]);
      } elseif ($this->linkageType == 0x0D) {
        $this->targetEventID = (ord ($Data [$Offset++]) << 8) | ord ($Data [$Offset++]);
        
        $Flags = ord ($Data [$Offset++]);
        $this->targetListed = (($Flags & 0x80) >> 7);
        $this->eventSimulcast (($Flags & 0x40) >> 6);
      }
      
      $this->Private = substr ($Data, $Offset);
      
      return true;
    }
  }
  
  qcEvents_Stream_MPEG_TS_Descriptor::registerDescriptor ('qcEvents_Stream_MPEG_TS_Descriptor_Linkage');

?>