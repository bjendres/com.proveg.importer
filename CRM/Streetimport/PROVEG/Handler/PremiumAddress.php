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
   * @param $record array an array of key=>value pairs
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
   * @param $record array an array of key=>value pairs
   * @return true
   * @throws exception if failed
   */
  public function processRecord($record, $sourceURI) {
    $config = CRM_Streetimport_Config::singleton();

    // find contact
    $contact = $this->identifyContact($record);

    if (!$contact) {
      // if contact not found: create activity
      $this->createPremiumActivity($contact, $record, 'Contact not found.', 'Scheduled');
      $this->getLogger()->logImport($record, false, $config->translate('Premium Address'));

    } else {
      // get address data

      // contact found:
      switch ($record['AdrMerk']) {
        case 10:
          # "Empfänger / Firma unter der angegebenen Anschrift nicht zu ermitteln"
          # Kd_: ja, E_: nein, _NSA: nein
          $this->createPremiumActivity($contact, $record, 'Contact not known', 'Scheduled');
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 12:
          # Empfänger soll verstorben sein
          # Kd_: ja, E_: teilw., _NSA: nein
          // TODO: mark deceased?
          $this->createPremiumActivity($contact, $record, 'Contact deceased', 'Scheduled');
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 14:
          # Empfänger / Firma unter der angegebenen Anschrift nicht zu ermitteln, Qualifiziert durch Datenbank
          # Kd_: ja, E_: ja, _NSA: nein
          $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Couldn\'t reach', 'Completed');
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 20:
          # Empfänger verzogen
          #  Kd_: ja, E_: ja, _NSA: ja
          $this->addAddress($contact, $record);
          $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Moved', 'Completed');
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 21:
          # Empfänger verzogen, Einwilligung zur Weitergabe der neuen Anschrift liegt nicht vor
          #  Kd_: ja, E_: ja, _NSA: nein
          $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Moved (address unknown)', 'Completed');
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 31:
          # Mängel in Adresse (PLZ, Ort, Straße, Hausnummer, Postfach)
          # Kd_: ja, E_: teilw., _NSA: nein
          $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Moved (address unknown)', 'Completed');
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        default:
          $this->createPremiumActivity($contact, $record, "Unknown reply [{$record['AdrMerk']}]", 'Scheduled');
          $this->getLogger()->logImport($record, false, $config->translate('Premium Address'));
          break;
      }
    }
  }

  /**
   * Get the contact data from the given record
   *
   * @param array $record
   * @return array contact data
   */
  protected function extractAddress($record, $prefix = '') {
    $data = [
        'name_1'      => CRM_Utils_Array::value("{$prefix}Na1",  $record, ''),
        'name_2'      => CRM_Utils_Array::value("{$prefix}Na2",  $record, ''),
        'name_3'      => CRM_Utils_Array::value("{$prefix}Na3",  $record, ''),
        'name_4'      => CRM_Utils_Array::value("{$prefix}Na4",  $record, ''),
        'street'      => CRM_Utils_Array::value("{$prefix}Str",  $record, ''),
        'number'      => CRM_Utils_Array::value("{$prefix}HNr",  $record, ''),
        'postal_code' => CRM_Utils_Array::value("{$prefix}PLZ",  $record, ''),
        'city'        => CRM_Utils_Array::value("{$prefix}Ort",  $record, ''),
    ];

    // compile address
    $data['street_address'] = trim("{$data['street']} {$data['number']}");


    return $data;
  }


  /**
   * Tries to identify the contact and all releated addresses
   *
   * @param array $record
   * @return array with contact data, with an entry 'address' with an array of all matching addresses
   */
  protected function identifyContact($record) {
    // find all potential contact ids
    $contact_ids = [];

    // get customer data (the data that we sent)
    $contact_data = $this->extractAddress($record, 'Kd_');

    // search with display name
    $contact_search_1 = civicrm_api3('Contact', 'get', [
        'display_name' => $contact_data['name_1'],
        'return'       => 'id',
        'option.limit' => 0,
        'sequential'   => 0]);
    $contact_ids += array_keys($contact_search_1['values']);

    // search with first/last name
    if (preg_match("#^(?P<prefix_id>Herr|Frau)? *(?P<first_name>\\w+).* (?P<last_name>\\w+)$#", $contact_data['name_1'], $matches)) {
      $contact_search_2 = civicrm_api3('Contact', 'get', [
          'first_name'   => $matches['first_name'],
          'last_name'    => $matches['last_name'],
          'return'       => 'id',
          'option.limit' => 0,
          'sequential'   => 0]);
      $contact_ids += array_keys($contact_search_2['values']);
    }

    // search by address
    if (!empty($contact_data['street_address']) && !empty($contact_data['postal_code']) && !empty($contact_data['city'])) {
      $address_search_1 = civicrm_api3('Address', 'get', [
          'street_address' => $contact_data['street_address'],
          'postal_code'    => $contact_data['postal_code'],
          'city'           => $contact_data['city'],
          'option.limit'   => 0,
          'return'         => 'contact_id,id'
      ]);
      foreach ($address_search_1['values'] as $address) {
        $contact_ids[] = $address['contact_id'];
      }
    }

    if (empty($contact_ids)) {
      // nobody found
      return [];
    }

    // NOW, load all the addresses and find the best match
    $address_candidates = civicrm_api3('Address', 'get', [
        'contact_id'   => ['IN' => $contact_ids],
        'option.limit' => 0
    ]);

    // ... and select the best ones
    $address = $this->selectAddressMatch($address_candidates['values'], $contact_data, $record);

    if (!$address) {
      // no address found
      return [];
    }

    // load contact
    $contact = civicrm_api3('Contact', 'getsingle', ['id' => $address['contact_id']]);
    $contact['address'] = $address;
    return $contact;
  }


  protected function createPremiumActivity($contact, $record, $subject, $status) {
    $this->getLogger()->logMessage("Would create {$status} activity: $subject", $record);
    // TODO
  }

  protected function deleteAddresses($contact, $record) {
    $this->getLogger()->logMessage("Would delete address: {$contact['street_address']} | {$contact['postal_code']} {$contact['city']}", $record);
    // TODO
  }

  protected function addAddress($contact, $record) {
    $entry_new = $this->extractAddress($record, 'NSA_');
    $this->getLogger()->logMessage("Would add new address: {$entry_new['street_address']} | {$entry_new['postal_code']} {$entry_new['city']}", $record);
    // TODO
  }


  /**
   * Find all addresses with the contact
   *
   * @param $contact_data
   * @throws CiviCRM_API3_Exception
   * @return array addresses
   */
  protected function findAddresses($contacts, $record) {
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
  protected function selectAddressMatch($addresses, $sample, $record) {
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

    if ($highscore >= 2.8) {
      return $highscorer;
    } else {
      return NULL;
    }
  }
}