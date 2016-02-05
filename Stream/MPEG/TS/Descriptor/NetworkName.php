<?PHP

  require_once ('qcEvents/Stream/MPEG/TS/Descriptor.php');
  
  class qcEvents_Stream_MPEG_TS_Descriptor_NetworkName extends qcEvents_Stream_MPEG_TS_Descriptor {
    const DESCRIPTOR_TAG = 0x40;
    
    private $Name = '';
    
    protected function parse ($Data) {
      $this->Name = $Data;
      
      return true;
    }
  }
  
  qcEvents_Stream_MPEG_TS_Descriptor::registerDescriptor ('qcEvents_Stream_MPEG_TS_Descriptor_NetworkName');

?>