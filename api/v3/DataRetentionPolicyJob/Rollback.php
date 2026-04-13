<?php

use CRM_DataRetentionPolicy_ExtensionUtil as E;

/**
 * DataRetentionPolicyJob.rollback API specification.
 *
 * @param array $spec
 *   Description of fields supported by this API call.
 */
function _civicrm_api3_data_retention_policy_job_rollback_spec(&$spec) {
  $spec['limit'] = [
    'title' => E::ts('Batch Limit'),
    'description' => E::ts('Maximum number of records to restore in this batch. Use 0 or omit for no limit.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 100,
  ];
}

/**
 * DataRetentionPolicyJob.rollback API.
 *
 * Attempts to restore records that were deleted by the data retention policy.
 * Uses the record snapshots stored in the audit log to recreate the records.
 *
 * @param array $params
 *   API parameters.
 *
 * @return array
 *   API result.
 */
function civicrm_api3_data_retention_policy_job_rollback($params) {
  $limit = isset($params['limit']) ? (int) $params['limit'] : 100;
  
  $processor = new CRM_DataRetentionPolicy_Service_RetentionProcessor();
  $result = $processor->rollbackDeletions($limit);

  $restoredCount = $result['restored'];
  $remainingCount = $result['remaining'];

  return civicrm_api3_create_success([
    'restored_count' => $restoredCount,
    'remaining_count' => $remainingCount,
    'message' => E::ts('Restored %1 record(s) from audit log. %2 remaining.', [
      1 => $restoredCount,
      2 => $remainingCount,
    ]),
  ], $params, 'DataRetentionPolicyJob', 'rollback');
}
