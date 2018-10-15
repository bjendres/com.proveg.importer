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
    CRM_Core_Error::debug_log_message("Looking at record [{$record['UebgID']}]", $record);

    // STEP 1: extract data
    $old_data  = $this->extractAddress($record, 'E_');
    $new_data  = $this->extractAddress($record, 'NSA_');

    // STEP 2: find contact and address
    $contacts  = $this->findContacts($old_data);
    $addresses = $this->findAddresses($contacts);
    $address   = $this->selectAddressMatch($addresses, $new_data);

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
        'first_name'  => CRM_Utils_Array::value("{$prefix}Na1",  $record, ''),
        'last_name'   => CRM_Utils_Array::value("{$prefix}Na2",  $record, ''),
        'name_3'      => CRM_Utils_Array::value("{$prefix}Na3",  $record, ''),
        'name_4'      => CRM_Utils_Array::value("{$prefix}Na4",  $record, ''),
        'street'      => CRM_Utils_Array::value("{$prefix}Str",  $record, ''),
        'number'      => CRM_Utils_Array::value("{$prefix}HNr",  $record, ''),
        'postal_code' => CRM_Utils_Array::value("{$prefix}PLZ",  $record, ''),
        'city'        => CRM_Utils_Array::value("{$prefix}Ort",  $record, ''),
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

  /**
   * Find all contacts matching first and last name
   *
   * @param $contact_data
   * @throws CiviCRM_API3_Exception
   * @return array contacts
   */
  protected function findContacts($contact_data) {
    $contacts = civicrm_api3('Contact', 'get', [
        'first_name'   => $contact_data['first_name'],
        'last_name'    => $contact_data['last_name'],
        'return'       => 'id',
        'option.limit' => 0,
        'sequential'   => 0,
    ]);
    CRM_Core_Error::debug_log_message("Found " . $contacts['count'] . " contacts.");
    return $contacts['values'];
  }

  /**
   * Find all addresses with the contact
   *
   * @param $contact_data
   * @throws CiviCRM_API3_Exception
   * @return array addresses
   */
  protected function findAddresses($contacts) {
    if (empty($contacts)) {
      // no contacts found
      return [];
    }

    // else: find all addresses from the contacts
    $addresses = civicrm_api3('Address', 'get', [
        'contact_id'   => ['IN' => array_keys($contacts)],
        'option.limit' => 0,
        'sequential'   => 0,
    ]);
    CRM_Core_Error::debug_log_message("Found " . $addresses['count'] . " addresses.");
    return $addresses['values'];
  }

  /**
   * Function to pick the best matching address from the list
   *
   * @param $addresses
   * @param $sample
   *
   * @return array|null an address if one over the threshold is found.
   */
  protected function selectAddressMatch($addresses, $sample) {
    $fields = ['street_address', 'postal_code', 'city'];
    $highscore  = 0.0;
    $highscorer = NULL;

    foreach ($addresses as $candidate) {
      // rate the address vs. the sample
      $score = 0.0;
      foreach ($fields as $field) {
        $candidate_value = CRM_Utils_Array::value($field, $candidate, 'NULL');
        $sample_value    = CRM_Utils_Array::value($field, $sample,    'NULL');
        $score += (1.0 - levenshtein($candidate_value, $sample_value) / max(strlen($candidate_value), strlen($sample_value)));
      }

      // save if it's the best
      if ($score > $highscore) {
        $highscore  = $score;
        $highscorer = $candidate;
      }
    }

    if ($highscorer) {
      CRM_Core_Error::debug_log_message("MATCH {$highscore}: " . json_encode($highscorer) . ' FOR: ' . json_encode($sample));
    } else {
      CRM_Core_Error::debug_log_message("NO MATCH FOR: " . json_encode($sample));
    }

    return $highscorer;
  }
}