<?php
/*-------------------------------------------------------------+
| PROVEG StreetImporter Implementation                         |
| Copyright (C) 2018 SYSTOPIA                                  |
| Author: B. Endres (endres -at- systopia.de)                  |
| http://www.systopia.de/                                      |
+--------------------------------------------------------------*/

/**
 * Abstract handler class with common functions for PROVEG handlers
 *
 * @author Björn Endres (SYSTOPIA) <endres@systopia.de>
 * @license AGPL-3.0
 */
abstract class CRM_Streetimport_PROVEG_Handler_PVRecordHandler extends CRM_Streetimport_RecordHandler {

  protected $_activity_name2id = [];


  /**
   * @param $activity_name
   * @param $activity_label
   */
  protected function getActivityTypeID($activity_name, $activity_label, $more_params = []) {
    // check the cache
    if (array_key_exists($activity_name, $this->_activity_name2id)) {
      return $this->_activity_name2id[$activity_name];
    }

    // try to find the activity type
    $activity_search = civicrm_api3('OptionValue', 'get', [
        'option_group_id' => 'activity_type',
        'name'            => $activity_name,
        'return'          => 'id,value']);
    if ($activity_search['count']) {
      $activity = reset($activity_search['values']);
      $this->_activity_name2id[$activity_name] = $activity['value'];
      return $activity['value'];
    }

    // last resort: create the activity type
    $activity_data = $more_params;
    $activity_data['name'] = $activity_name;
    $activity_data['label'] = $activity_label;
    $activity_data['option_group_id'] = 'activity_type';
    $activity = civicrm_api3('OptionValue', 'create', $activity_data);
    $this->_activity_name2id[$activity_name] = $activity['value'];
    return $activity['value'];
  }
}
