<?PHP

  require_once ('qcEvents/Stream/MPEG/TS/Descriptor.php');
  
  class qcEvents_Stream_MPEG_TS_Descriptor_SateliteDeliverySystem extends qcEvents_Stream_MPEG_TS_Descriptor {
    const DESCRIPTOR_TAG = 0x43;
    
    const POLARISATION_HORIZONTAL = 0x00;
    const POLARISATION_VERTICAL = 0x01;
    const POLARISATION_LEFT = 0x02;
    const POLARISATION_RIGHT = 0x03;
    
    const ROLLOFF_35 = 0x00;
    const ROLLOFF_25 = 0x01;
    const ROLLOFF_20 = 0x02;
    const ROLLOFF_RESERVED = 0x03;
    
    const MODULATION_SYSTEM_DVBS = 0x00;
    const MODULATION_SYSTEM_DVBS2 = 0x01;
    
    const MODULATION_TYPE_AUTO = 0x00;
    const MODULATION_TYPE_QPSK = 0x01;
    const MODULATION_TYPE_8PSK = 0x02;
    const MODULATION_TYPE_16QAM = 0x03;
    
    private $Frequency = 0x00;
    private $OrbitalPosition = 0x00;
    private $WestEastFlag = 0x00;
    private $Polarisation = 0x00;
    private $RollOff = 0x00;
    private $ModulationSystem = 0x00;
    private $ModulationType = 0x00;
    private $SymbolRate = 0x00;
    private $FECinner = 0x00;
    
    protected function parse ($Data) {
      $Offset = 0;
      $this->Frequency =
        (ord ($Data [$Offset++]) << 24) |
        (ord ($Data [$Offset++]) << 16) |
        (ord ($Data [$Offset++]) <<  8) |
        (ord ($Data [$Offset++]));
      $this->OrbitalPosition = (ord ($Data [$Offset++]) <<  8) | ord ($Data [$Offset++]);
      
      $Flags = ord ($Data [$Offset++]);
      $this->WestEastFlag = (($Flags & 0x80) >> 7);
      $this->Polarisation = (($Flags & 0x60) >> 5);
      $this->RollOff = (($Flags & 0x18) >> 3);
      $this->ModulationSystem = (($Flags & 0x04) >> 2);
      $this->ModulationType = ($Flags & 0x03);
      
      $Flags =
        (ord ($Data [$Offset++]) << 24) |
        (ord ($Data [$Offset++]) << 16) |
        (ord ($Data [$Offset++]) <<  8) |
        (ord ($Data [$Offset++]));
      $this->SymbolRate = ($Flags >> 4);
      $this->FECinner = ($Flags & 0x0F);
      
      return true;
    }
  }
  
  qcEvents_Stream_MPEG_TS_Descriptor::registerDescriptor ('qcEvents_Stream_MPEG_TS_Descriptor_SateliteDeliverySystem');

?>