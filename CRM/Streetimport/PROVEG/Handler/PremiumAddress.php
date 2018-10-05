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
    // TODO
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

    // STEP 1: match/create contact
    $contact_id = $this->processContact($record);

    // STEP 2: create a new contract for the contact
    $contract_id = $this->createDDContract($contact_id, $record);

    // STEP 3: process Features and stuff
    $this->processAdditionalInformation($contact_id, $contract_id, $record);

    // STEP 4: create 'manual check' activity
    $note = trim(CRM_Utils_Array::value('Bemerkungen', $record, ''));
    if ($note) {
      $this->createManualUpdateActivity($contact_id, $note, $record);
    }

    $deprecated_start_date = trim(CRM_Utils_Array::value('Vertrags_Beginn', $record, ''));
    if ($deprecated_start_date && (strtotime($deprecated_start_date) > strtotime('now'))) {
      $this->createManualUpdateActivity($contact_id, "Deprecated value 'Vertrags_Beginn' given: {$deprecated_start_date}", $record);
    }

    civicrm_api3('Contract', 'process_scheduled_modifications', array());
    $this->logger->logImport($record, true, $config->translate('DD Contact'));
  }

}
