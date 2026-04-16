<?php

use CRM_DataRetentionPolicy_ExtensionUtil as E;

function _civicrm_api3_data_retention_policy_job_run_spec(&$spec) {
  $spec['batch_size'] = [
    'title' => E::ts('Batch Size'),
    'description' => E::ts('Maximum number of records to delete per entity type. Use 0 or omit to use the configured setting. Overrides the saved setting for this run.'),
    'type' => CRM_Utils_Type::T_INT,
    'api.default' => 0,
  ];
}

function civicrm_api3_data_retention_policy_job_run($params) {
  $batchSize = isset($params['batch_size']) ? (int) $params['batch_size'] : 0;

  $processor = new CRM_DataRetentionPolicy_Service_RetentionProcessor();
  $results = $processor->applyPolicies($batchSize);

  $messages = [];
  $total = 0;
  $hasRemaining = FALSE;
  foreach ($results as $entity => $info) {
    if (is_array($info)) {
      $messages[] = sprintf('%s: %d deleted, %d remaining', $entity, $info['deleted'], $info['remaining']);
      $total += $info['deleted'];
      if ($info['remaining'] > 0) {
        $hasRemaining = TRUE;
      }
    }
    else {
      $messages[] = sprintf('%s: %d', $entity, $info);
      $total += $info;
    }
  }

  $message = E::ts('Deleted records - %1', [1 => implode(', ', $messages)]);
  if ($hasRemaining) {
    $message .= ' ' . E::ts('(more records remain - job will continue on next run)');
  }

  return civicrm_api3_create_success([
    'total_deleted' => $total,
    'details' => $results,
    'has_remaining' => $hasRemaining,
    'message' => $message,
  ], $params, 'DataRetentionPolicyJob', 'run');
}
