<?php
/*-------------------------------------------------------------+
| PROVEG StreetImporter Implementation                         |
| Copyright (C) 2018 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Class following Singleton pattern for specific extension configuration
 */
class CRM_Streetimport_PROVEG_Config extends CRM_Streetimport_Config {

  /**
   * Constructor method
   *
   * @param string $context
   */
  function __construct() {
    CRM_Streetimport_Config::__construct();
  }


  /**
   * get the default set of handlers
   *
   * @return array list of handler instances
   */
  public function getHandlers($logger) {
    return array(
      new CRM_Streetimport_PROVEG_Handler_PremiumAddress($logger),
    );
  }

  /**
   * returns the list of attributes that are required for
   * a valid address
   */
  public function getRequiredAddressAttributes() {
    return array('postal_code', 'street_address');
  }

  /**
   * returns the list of attributes that are required for
   * a valid address
   */
  public function getAllAddressAttributes() {
    return array('postal_code', 'street_address', 'city', 'country_id');
  }

  /**
   * Check if the "Little BIC Extension" is available
   */
  public function isLittleBicExtensionAccessible() {
    return CRM_Sepa_Logic_Settings::isLittleBicExtensionAccessible();
  }

  /**
   * Should processing of the whole file stop if no handler
   * was found for a line?
   */
  public function stopProcessingIfNoHanderFound() {
    return TRUE;
  }

}
