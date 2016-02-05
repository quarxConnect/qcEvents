<?PHP

  require_once ('qcEvents/Stream/MPEG/TS/Descriptor.php');
  
  class qcEvents_Stream_MPEG_TS_Descriptor_PrivateDataSpecifier extends qcEvents_Stream_MPEG_TS_Descriptor {
    const DESCRIPTOR_TAG = 0x5F;
    
    private $Private = '';
    
    protected function parse ($Data) {
      $this->Private = $Data;
      
      return true;
    }
  }
  
  qcEvents_Stream_MPEG_TS_Descriptor::registerDescriptor ('qcEvents_Stream_MPEG_TS_Descriptor_PrivateDataSpecifier');

?>