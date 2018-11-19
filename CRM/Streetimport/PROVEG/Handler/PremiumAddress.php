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
      $this->createPremiumActivity($contact, $record, 'CiviCRM Contact not identified.', 'Scheduled');
      $this->getLogger()->logImport($record, false, $config->translate('Premium Address'));

    } else {
      // get address data

      // contact found:
      switch ($record['AdrMerk']) {
        case 10:
          # "Empfänger / Firma unter der angegebenen Anschrift nicht zu ermitteln"
          # Kd_: ja, E_: nein, _NSA: nein
          $deleted_addresses = $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Address invalid', 'Scheduled', $deleted_addresses);
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
          $deleted_addresses = $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Moved (address unknown)', 'Completed', $deleted_addresses);
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 20:
          # Empfänger verzogen
          #  Kd_: ja, E_: ja, _NSA: ja
          $this->addAddress($contact, $record);
          $deleted_addresses = $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Moved', 'Completed', $deleted_addresses);
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 21:
          # Empfänger verzogen, Einwilligung zur Weitergabe der neuen Anschrift liegt nicht vor
          #  Kd_: ja, E_: ja, _NSA: nein
          $deleted_addresses = $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Moved (address classified)', 'Completed', $deleted_addresses);
          $this->getLogger()->logImport($record, true, $config->translate('Premium Address'));
          break;

        case 31:
          # Mängel in Adresse (PLZ, Ort, Straße, Hausnummer, Postfach)
          # Kd_: ja, E_: teilw., _NSA: nein
          $deleted_addresses = $this->deleteAddresses($contact, $record);
          $this->createPremiumActivity($contact, $record, 'Moved (address unknown)', 'Completed', $deleted_addresses);
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
    $this->getLogger()->logMessage("Find by display_name '{$contact_data['name_1']}' found: " . json_encode(array_keys($contact_search_1['values'])), $record, BE_AIVL_STREETIMPORT_DEBUG);
    $contact_ids += array_keys($contact_search_1['values']);

    // search with first/last name
    if (preg_match("#^(?P<prefix_id>Herr|Frau)? *(?P<first_name>\\w+).* (?P<last_name>\\w+)$#", $contact_data['name_1'], $matches)) {
      $contact_search_2 = civicrm_api3('Contact', 'get', [
          'first_name'   => $matches['first_name'],
          'last_name'    => $matches['last_name'],
          'return'       => 'id',
          'option.limit' => 0,
          'sequential'   => 0]);
      $this->getLogger()->logMessage("Find by first/last name '{$matches['first_name']}'/'{$matches['last_name']}' found: " . json_encode(array_keys($contact_search_2['values'])), $record, BE_AIVL_STREETIMPORT_DEBUG);
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
        $this->getLogger()->logMessage("Find by address '{$contact_data['street_address']}'/'{$contact_data['postal_code']}'/'{$contact_data['city']}' found: {$address['contact_id']}", $record, BE_AIVL_STREETIMPORT_DEBUG);
        $contact_ids[] = $address['contact_id'];
      }
    }

    if (empty($contact_ids)) {
      // nobody found
      $this->getLogger()->logMessage("No contacts found!", $record, BE_AIVL_STREETIMPORT_DEBUG);
      return [];
    }

    // NOW, load all the addresses and find the best match
    $address_candidates = civicrm_api3('Address', 'get', [
        'contact_id'   => ['IN' => $contact_ids],
        'option.limit' => 0
    ]);

    // ... and select the best ones
    $this->getLogger()->logMessage("Identified {$address_candidates['count']} addresses.", $record, BE_AIVL_STREETIMPORT_DEBUG);
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


  /**
   * Create a Premium Address Update activity
   *
   * @param $contact
   * @param $record
   * @param $subject
   * @param $status
   */
  protected function createPremiumActivity($contact, $record, $subject, $status, $deleted_addresses = array()) {
    $config = CRM_Streetimport_Config::singleton();
    $activity_data = [
        'activity_type_id'    => $this->getPremiumActivityTypeID(),
        'subject'             => $subject,
        'status_id'           => CRM_Core_Pseudoconstant::getKey('CRM_Activity_BAO_Activity', 'activity_status_id', $status),
        'activity_date_time'  => date('YmdHis'),
        'target_contact_id'   => (int) $contact['id'],
        'source_contact_id'   => (int) $config->getCurrentUserID(),
    ];

    // assign
    if ($status == 'Scheduled') {
      // assign to "fundraiser"
      $activity_data['assignee_contact_id'] = $config->getFundraiserContactID();
    }

    // render content
    $data = [
        'customer'          => $this->extractAddress($record, 'Kd_'),
        'old_address'       => $this->extractAddress($record, 'E_'),
        'new_address'       => $this->extractAddress($record, 'NSA_'),
        'deleted_addresses' => $deleted_addresses,
        'record'            => $record];
    $activity_data['details'] = $this->renderTemplate('PremiumAddressActivity.tpl', $data);

    $this->createActivity($activity_data, $record);
  }

  /**
   * Delete the addresses as specified by the file
   *  and return their data
   *
   * @param $contact
   * @param $record
   * @throws CiviCRM_API3_Exception
   */
  protected function deleteAddresses($contact, $record) {
    $addresses_deleted = array();
    if (!empty($contact['address'])) {
      $address_search = civicrm_api3('Address', 'get', array(
          'contact_id'     => $contact['id'],
          'street_address' => $contact['address']['street_address'],
          'postal_code'    => $contact['address']['postal_code'],
          'city'           => $contact['address']['city']
      ));
      foreach ($address_search['values'] as $address) {
        civicrm_api3('Address', 'delete', ['id' => $address['id']]);
        $addresses_deleted[] = $address;
        $this->getLogger()->logMessage("Deleted address: {$address['street_address']} | {$address['postal_code']} {$address['city']}", $record);
      }
    }

    return $addresses_deleted;
  }

  /**
   * Create the new address
   *
   * @param $contact
   * @param $record
   */
  protected function addAddress($contact, $record) {
    $entry_new = $this->extractAddress($record, 'NSA_');
    $entry_new['contact_id'] = $contact['id'];
    $entry_new['is_primary'] = 1;
    $entry_new['country_id'] = 'DE';
    if (!empty($contact['address']['location_type_id'])) {
      $entry_new['location_type_id'] = $contact['address']['location_type_id'];
    } else {
      $entry_new['location_type_id'] = 'Privat';
    }
    civicrm_api3('Address', 'create', $entry_new);
    $this->getLogger()->logMessage("Created new address: {$entry_new['street_address']} | {$entry_new['postal_code']} {$entry_new['city']}", $record);
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
      $this->getLogger()->logMessage("MATCH {$highscore}: " . json_encode($highscorer) . ' FOR: ' . json_encode($sample), $record, BE_AIVL_STREETIMPORT_DEBUG);
    } else {
      $this->getLogger()->logMessage("NO MATCH FOR: " . json_encode($sample) . ' FOR: ' . json_encode($sample), $record, BE_AIVL_STREETIMPORT_DEBUG);
    }

    if ($highscore >= 2.8) {
      return $highscorer;
    } else {
      $this->getLogger()->logMessage("MATCH SCORE TOO LOW!", $record, BE_AIVL_STREETIMPORT_DEBUG);
      return NULL;
    }
  }


  /**
   * Get (or create) the "Premium Address Update" activity type
   */
  protected function getPremiumActivityTypeID() {
    return $this->getActivityTypeID('premium_address_update', "Premium Address Update");
  }

}