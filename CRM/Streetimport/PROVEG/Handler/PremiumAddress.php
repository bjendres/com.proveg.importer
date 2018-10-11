<?php
/*-------------------------------------------------------------+
| PROVEG StreetImporter Implementation                         |
| Copyright (C) 2018 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Implements PremiumAddress (Deutsche Post) address changes
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
class CRM_Streetimport_PROVEG_Handler_PremiumAddress extends CRM_Streetimport_PROVEG_Handler_PVRecordHandler {

  /**
   * Check if the given handler implementation can process the record
   *
   * @param $record  an array of key=>value pairs
   * @return true or false
   */
  public function canProcessRecord($record, $sourceURI) {
    // just check if some typical values are there...
    return isset($record['UebgID'])
        && isset($record['FrkDat'])
        && isset($record['KdInfoDMC']);
  }

  /**
   * process the given record
   *
   * @param $record  an array of key=>value pairs
   * @return true
   * @throws exception if failed
   */
  public function processRecord($record, $sourceURI) {
    $config = CRM_Streetimport_Config::singleton();

    // STEP 1: extract data
    $old_data  = $this->extractAddress($record, 'E_');
    $new_data  = $this->extractAddress($record, 'NSA_');

    // STEP 2: find contact and address
    $contact = $this->findContact($old_data);
    $address = $this->findAddress($contact, $old_data);

    // STEP 3: update address

    // STEP 4: create activity

    $this->logger->logImport($record, true, $config->translate('Premium Address'));
  }

  /**
   * Get the contact data from the given record
   *
   * @param array $record
   * @return array contact data
   */
  protected function extractAddress($record, $prefix = '') {
    $data = [
        'first_name'  => CRM_Utils_Array::value("{$prefix}Na1", $record, ''),
        'last_name'   => CRM_Utils_Array::value("{$prefix}Na2", $record, ''),
        'name_2'      => CRM_Utils_Array::value("{$prefix}Na3", $record, ''),
        'name_3'      => CRM_Utils_Array::value("{$prefix}Na4", $record, ''),
        'street'      => CRM_Utils_Array::value("{$prefix}Str", $record, ''),
        'number'      => CRM_Utils_Array::value("{$prefix}HNr", $record, ''),
        'postal_code' => CRM_Utils_Array::value("{$prefix}PLZ", $record, ''),
        'city'        => CRM_Utils_Array::value("{$prefix}Ort", $record, ''),
        'country'     => CRM_Utils_Array::value("{$prefix}Land", $record, ''),
    ];

    // compile address
    $data['street_address'] = trim("{$data['street']} {$data['number']}");

    // default country is DE
    if (empty($data['country'])) {
      $data['country'] = 'DE';
    }

    return $data;
  }
}
