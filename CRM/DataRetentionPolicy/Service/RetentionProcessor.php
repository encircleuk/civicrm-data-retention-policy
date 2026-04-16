<?php

class CRM_DataRetentionPolicy_Service_RetentionProcessor {

  public function applyPolicies($batchSize = 0) {
    $settings = Civi::settings();
    $results = [];

    $batchSize = (int) $batchSize;
    if ($batchSize <= 0) {
      $batchSize = (int) $settings->get('data_retention_batch_size');
    }

    $this->logAction('job_start', 'DataRetentionPolicyJob', NULL, [
      'time' => date('c'),
      'batch_size' => $batchSize,
    ]);

    foreach ($this->getEntityConfigurations($settings) as $config) {
      $amount = (int) $settings->get($config['amount_setting']);
      $unit = $settings->get($config['unit_setting']);
      if ($amount <= 0) {
        $results[$config['entity']] = ['deleted' => 0, 'remaining' => 0];
        continue;
      }

      $cutoff = $this->calculateCutoffDate($amount, $unit);
      if ($cutoff === NULL) {
        $results[$config['entity']] = ['deleted' => 0, 'remaining' => 0];
        continue;
      }

      $result = $this->deleteExpiredRecords($config, $cutoff, $batchSize);
      $results[$config['entity']] = $result;
    }

    if ($this->shouldCleanCustomData($settings)) {
      $deletedCustom = $this->cleanOrphanCustomData();
      $results['Custom data orphans'] = $deletedCustom;
    }

    $purgedLogs = $this->purgeAuditLog($settings);
    $results['Audit log purge'] = $purgedLogs;

    $this->logAction('job_complete', 'DataRetentionPolicyJob', NULL, ['time' => date('c'), 'results' => $results]);

    return $results;
  }

  protected function deleteExpiredRecords(array $config, DateTime $cutoff, $batchSize = 0) {
    $fetchLimit = ($batchSize > 0) ? $batchSize + 1 : 0;
    $ids = $this->getIdsToDelete($config, $cutoff, $fetchLimit);

    $hasMore = FALSE;
    if ($batchSize > 0 && count($ids) > $batchSize) {
      $hasMore = TRUE;
      $ids = array_slice($ids, 0, $batchSize);
    }

    $count = 0;
    foreach ($ids as $id) {
      $snapshot = $this->getRecordSnapshot($config['api_entity'], $id);
      $params = ['id' => $id];
      if (!empty($config['api_params'])) {
        $params = array_merge($params, $config['api_params']);
      }
      try {
        // Handle CMS user deletion for Contact entities based on configured handling mode.
        $cmsUserDeleted = FALSE;
        $cmsUserUnlinked = FALSE;
        if ($config['api_entity'] === 'Contact' && !empty($config['cms_user_handling'])) {
          if ($config['cms_user_handling'] === 'delete_both') {
            $cmsUserDeleted = $this->deleteCmsUserForContact($id);
          }
          elseif ($config['cms_user_handling'] === 'delete_contact_keep_user') {
            // Unlink the CMS user from the contact but preserve the CMS account.
            $cmsUserUnlinked = $this->unlinkCmsUserFromContact($id);
          }
        }

        civicrm_api3($config['api_entity'], 'delete', $params);
        $count++;
        $context = [
          'cutoff' => $cutoff->format('Y-m-d H:i:s'),
          'api_entity' => $config['api_entity'],
        ];
        if ($snapshot !== NULL) {
          $context['record'] = $snapshot;
        }
        if ($cmsUserDeleted) {
          $context['cms_user_deleted'] = TRUE;
        }
        if ($cmsUserUnlinked) {
          $context['cms_user_unlinked'] = TRUE;
        }
        $this->logAction('delete', $config['entity'], $id, $context);
      }
      catch (CiviCRM_API3_Exception $e) {
        Civi::log()->error('Data Retention Policy failed to delete record', [
          'entity' => $config['entity'],
          'id' => $id,
          'message' => $e->getMessage(),
        ]);
        $this->logAction('delete_failed', $config['entity'], $id, [
          'message' => $e->getMessage(),
          'api_entity' => $config['api_entity'],
        ]);
      }
    }

    $remaining = 0;
    if ($hasMore) {
      $remaining = $this->countIdsToDelete($config, $cutoff);
    }

    return ['deleted' => $count, 'remaining' => $remaining];
  }

  protected function getIdsToDelete(array $config, DateTime $cutoff, $limit = 0) {
    $params = [1 => [$cutoff->format('Y-m-d H:i:s'), 'String']];
    $sql = sprintf(
      'SELECT %s AS record_id FROM %s WHERE %s IS NOT NULL AND %s < %%1 AND %s',
      $config['id_field'],
      $config['table'],
      $config['date_expression'],
      $config['date_expression'],
      $config['additional_where']
    );

    if ($limit > 0) {
      $sql .= ' LIMIT ' . (int) $limit;
    }

    $dao = CRM_Core_DAO::executeQuery($sql, $params);
    $ids = [];
    while ($dao->fetch()) {
      $ids[] = $dao->record_id;
    }
    return $ids;
  }

  protected function countIdsToDelete(array $config, DateTime $cutoff) {
    $params = [1 => [$cutoff->format('Y-m-d H:i:s'), 'String']];
    $sql = sprintf(
      'SELECT COUNT(%s) FROM %s WHERE %s IS NOT NULL AND %s < %%1 AND %s',
      $config['id_field'],
      $config['table'],
      $config['date_expression'],
      $config['date_expression'],
      $config['additional_where']
    );

    return (int) CRM_Core_DAO::singleValueQuery($sql, $params);
  }

  protected function getEntityConfigurations($settings = NULL) {
    if ($settings === NULL) {
      $settings = Civi::settings();
    }

    $contactDateSource = $settings->get('data_retention_contact_date_source');
    if ($contactDateSource !== 'login') {
      $contactDateSource = 'activity';
    }
    // Subquery to get max activity date for the contact
    $lastActivitySubquery = '(SELECT MAX(a.activity_date_time) FROM civicrm_activity_contact ac INNER JOIN civicrm_activity a ON ac.activity_id = a.id WHERE ac.contact_id = civicrm_contact.id)';
    $contactDateExpression = "COALESCE({$lastActivitySubquery}, modified_date, created_date)";
    if ($contactDateSource === 'login') {
      $contactDateExpression = "COALESCE((SELECT MAX(log_date) FROM civicrm_uf_match uf WHERE uf.contact_id = civicrm_contact.id), {$lastActivitySubquery}, modified_date, created_date)";
    }

    // Determine CMS user handling strategy.
    $cmsUserHandling = $settings->get('data_retention_cms_user_handling');
    if (!in_array($cmsUserHandling, ['skip', 'delete_both', 'delete_contact_keep_user'], TRUE)) {
      $cmsUserHandling = 'skip';
    }

    // Get exclusion lists for protected contacts.
    $protectedContactIds = $this->getProtectedContactIds($settings);
    $protectedContactClause = '';
    if (!empty($protectedContactIds)) {
      $idList = implode(',', array_map('intval', $protectedContactIds));
      $protectedContactClause = " AND id NOT IN ({$idList})";
    }

    // Build additional_where clause for contacts.
    // If 'skip', exclude contacts that have linked CMS user accounts.
    $contactAdditionalWhere = 'is_deleted = 0' . $protectedContactClause;
    if ($cmsUserHandling === 'skip') {
      $contactAdditionalWhere .= ' AND id NOT IN (SELECT DISTINCT contact_id FROM civicrm_uf_match WHERE contact_id IS NOT NULL)';
    }

    // Trash contacts: CMS users should also be handled based on setting.
    $trashAdditionalWhere = 'is_deleted = 1' . $protectedContactClause;
    if ($cmsUserHandling === 'skip') {
      $trashAdditionalWhere .= ' AND id NOT IN (SELECT DISTINCT contact_id FROM civicrm_uf_match WHERE contact_id IS NOT NULL)';
    }

    return [
      [
        'amount_setting' => 'data_retention_contact_years',
        'unit_setting' => 'data_retention_contact_unit',
        'entity' => 'Contact',
        'api_entity' => 'Contact',
        'table' => 'civicrm_contact',
        'id_field' => 'id',
        'date_expression' => $contactDateExpression,
        'additional_where' => $contactAdditionalWhere,
        'cms_user_handling' => $cmsUserHandling,
      ],
      [
        'amount_setting' => 'data_retention_contact_trash_days',
        'unit_setting' => 'data_retention_contact_trash_unit',
        'entity' => 'Contact (trash)',
        'api_entity' => 'Contact',
        'table' => 'civicrm_contact',
        'id_field' => 'id',
        'date_expression' => 'modified_date',
        'additional_where' => $trashAdditionalWhere,
        'api_params' => ['skip_undelete' => 1],
        'cms_user_handling' => $cmsUserHandling,
      ],
      [
        'amount_setting' => 'data_retention_participant_years',
        'unit_setting' => 'data_retention_participant_unit',
        'entity' => 'Participant',
        'api_entity' => 'Participant',
        'table' => 'civicrm_participant',
        'id_field' => 'id',
        'date_expression' => 'COALESCE(modified_date, register_date)',
        'additional_where' => '1',
      ],
      [
        'amount_setting' => 'data_retention_contribution_years',
        'unit_setting' => 'data_retention_contribution_unit',
        'entity' => 'Contribution',
        'api_entity' => 'Contribution',
        'table' => 'civicrm_contribution',
        'id_field' => 'id',
        'date_expression' => 'COALESCE(receive_date, modified_date, created_date)',
        'additional_where' => '1',
      ],
      [
        'amount_setting' => 'data_retention_membership_years',
        'unit_setting' => 'data_retention_membership_unit',
        'entity' => 'Membership',
        'api_entity' => 'Membership',
        'table' => 'civicrm_membership',
        'id_field' => 'id',
        'date_expression' => 'COALESCE(modified_date, end_date, start_date, join_date)',
        'additional_where' => '1',
      ],
    ];
  }

  protected function calculateCutoffDate($amount, $unit) {
    $interval = $this->createInterval($amount, $unit);
    if ($interval === NULL) {
      return NULL;
    }

    $cutoff = new DateTime('now', new DateTimeZone('UTC'));
    $cutoff->sub($interval);
    return $cutoff;
  }

  protected function createInterval($amount, $unit) {
    $amount = (int) $amount;
    if ($amount <= 0) {
      return NULL;
    }

    $unit = strtolower((string) $unit);
    $spec = NULL;

    switch ($unit) {
      case 'day':
      case 'days':
        $spec = sprintf('P%dD', $amount);
        break;

      case 'week':
      case 'weeks':
        $spec = sprintf('P%dW', $amount);
        break;

      case 'month':
      case 'months':
        $spec = sprintf('P%dM', $amount);
        break;

      case 'year':
      case 'years':
      default:
        $spec = sprintf('P%dY', $amount);
        break;
    }

    try {
      return new DateInterval($spec);
    }
    catch (Exception $e) {
      Civi::log()->error('Data Retention Policy failed to create interval', [
        'amount' => $amount,
        'unit' => $unit,
        'message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  protected function shouldCleanCustomData($settings) {
    return (bool) $settings->get('data_retention_clean_orphan_custom_data');
  }

  protected function cleanOrphanCustomData() {
    $sql = "SELECT id, table_name, extends FROM civicrm_custom_group WHERE table_name IS NOT NULL AND extends IS NOT NULL";
    $dao = CRM_Core_DAO::executeQuery($sql);
    $totalDeleted = 0;

    while ($dao->fetch()) {
      $tableName = $dao->table_name;
      $extends = $dao->extends;
      $entityTable = $this->mapExtendsToTable($extends);

      if (!$tableName || !$entityTable) {
        continue;
      }

      if (!CRM_Core_DAO::checkTableExists($tableName) || !CRM_Core_DAO::checkTableExists($entityTable)) {
        continue;
      }

      $sqlDelete = "DELETE orphan FROM {$tableName} AS orphan LEFT JOIN {$entityTable} AS entity ON orphan.entity_id = entity.id WHERE entity.id IS NULL";
      $deleteDao = CRM_Core_DAO::executeQuery($sqlDelete);
      $deleted = (int) $deleteDao->rowCount();

      if ($deleted > 0) {
        $totalDeleted += $deleted;
        $this->logAction('delete_orphan_custom_data', $tableName, NULL, [
          'extends' => $extends,
          'deleted' => $deleted,
        ]);
      }
    }

    if ($totalDeleted === 0) {
      $this->logAction('delete_orphan_custom_data', 'custom_data', NULL, ['deleted' => 0]);
    }

    return $totalDeleted;
  }

  protected function mapExtendsToTable($extends) {
    $map = [
      'Contact' => 'civicrm_contact',
      'Individual' => 'civicrm_contact',
      'Organization' => 'civicrm_contact',
      'Household' => 'civicrm_contact',
      'Activity' => 'civicrm_activity',
      'Contribution' => 'civicrm_contribution',
      'Membership' => 'civicrm_membership',
      'Participant' => 'civicrm_participant',
      'Event' => 'civicrm_event',
      'Case' => 'civicrm_case',
      'Grant' => 'civicrm_grant',
      'Pledge' => 'civicrm_pledge',
      'PledgePayment' => 'civicrm_pledge_payment',
      'Address' => 'civicrm_address',
      'Phone' => 'civicrm_phone',
      'Email' => 'civicrm_email',
      'IM' => 'civicrm_im',
      'OpenID' => 'civicrm_openid',
      'Website' => 'civicrm_website',
      'Relationship' => 'civicrm_relationship',
      'Note' => 'civicrm_note',
      'Campaign' => 'civicrm_campaign',
      'Survey' => 'civicrm_survey',
      'CaseType' => 'civicrm_case_type',
      'GrantApplication' => 'civicrm_grant',
    ];

    return isset($map[$extends]) ? $map[$extends] : NULL;
  }

  protected function purgeAuditLog($settings) {
    $amount = (int) $settings->get('data_retention_audit_log_years');
    $unit = $settings->get('data_retention_audit_log_unit');

    if ($amount <= 0) {
      return 0;
    }

    $cutoff = $this->calculateCutoffDate($amount, $unit);
    if ($cutoff === NULL) {
      return 0;
    }

    $params = [1 => [$cutoff->format('Y-m-d H:i:s'), 'String']];
    $sql = 'DELETE FROM civicrm_data_retention_audit_log WHERE action_date < %1';
    $dao = CRM_Core_DAO::executeQuery($sql, $params);
    $deleted = (int) $dao->rowCount();

    if ($deleted > 0) {
      $this->logAction('purge_audit_log', 'AuditLog', NULL, [
        'deleted' => $deleted,
        'cutoff' => $cutoff->format('Y-m-d H:i:s'),
      ]);
    }

    return $deleted;
  }

  /**
   * Rollback deletions from the audit log.
   *
   * @param int $limit
   *   Maximum number of records to restore in this batch. 0 = no limit.
   *
   * @return array
   *   Array with 'restored' count and 'remaining' count.
   */
  public function rollbackDeletions($limit = 0) {
    $limit = (int) $limit;
    
    // Get count of remaining records to restore
    $remainingCount = CRM_Core_DAO::singleValueQuery(
      'SELECT COUNT(*) FROM civicrm_data_retention_audit_log WHERE action = %1',
      [1 => ['delete', 'String']]
    );
    
    $sql = 'SELECT id, entity_type, entity_id, details FROM civicrm_data_retention_audit_log WHERE action = %1 ORDER BY id ASC';
    if ($limit > 0) {
      $sql .= ' LIMIT ' . $limit;
    }
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => ['delete', 'String']]);
    $restored = 0;

    while ($dao->fetch()) {
      $details = json_decode($dao->details, TRUE);
      if (!is_array($details)) {
        $this->logAction('rollback_failed', $dao->entity_type, $dao->entity_id, [
          'message' => 'Audit log entry is missing rollback details.',
          'audit_id' => $dao->id,
        ]);
        continue;
      }

      $apiEntity = CRM_Utils_Array::value('api_entity', $details, $dao->entity_type);
      $record = CRM_Utils_Array::value('record', $details);

      if (empty($record) || !is_array($record)) {
        $this->logAction('rollback_failed', $apiEntity, $dao->entity_id, [
          'message' => 'No record snapshot available for rollback.',
          'audit_id' => $dao->id,
        ]);
        continue;
      }

      // Check if the record still exists in the database (soft-deleted)
      // Permanently deleted records cannot be restored
      if (!$this->recordExistsInDatabase($apiEntity, $dao->entity_id)) {
        $this->logAction('rollback_skipped', $apiEntity, $dao->entity_id, [
          'message' => 'Record was permanently deleted and cannot be restored.',
          'audit_id' => $dao->id,
        ]);
        // Mark as not restorable so we don't keep trying
        CRM_Core_DAO::executeQuery(
          'UPDATE civicrm_data_retention_audit_log SET action = %1 WHERE id = %2',
          [
            1 => ['permanently_deleted', 'String'],
            2 => [$dao->id, 'Integer'],
          ]
        );
        continue;
      }

      // For Contact entities, check for UF_Match conflicts before restoring.
      $ufMatchConflictInfo = NULL;
      if ($apiEntity === 'Contact') {
        $ufMatchConflictInfo = $this->checkUfMatchConflicts($dao->entity_id, $details);
        if (!$ufMatchConflictInfo['can_restore_uf_match'] && !empty($ufMatchConflictInfo['conflicts'])) {
          // Log the conflict but proceed with the contact restore.
          // The UF_Match link will not be restored.
          Civi::log()->warning('Data Retention Policy: UF_Match conflicts detected during rollback', [
            'contact_id' => $dao->entity_id,
            'conflicts' => $ufMatchConflictInfo['conflicts'],
          ]);
        }
      }

      $params = $this->prepareRecordForCreate($record);
      if (!isset($params['id']) && !empty($dao->entity_id)) {
        $params['id'] = $dao->entity_id;
      }

      $created = $this->attemptRecordRestore($apiEntity, $params);

      if ($created) {
        $restored++;
        $details['rolled_back_at'] = date('c');
        if ($ufMatchConflictInfo !== NULL && !$ufMatchConflictInfo['can_restore_uf_match']) {
          $details['uf_match_not_restored'] = TRUE;
          $details['uf_match_conflicts'] = $ufMatchConflictInfo['conflicts'];
        }
        CRM_Core_DAO::executeQuery(
          'UPDATE civicrm_data_retention_audit_log SET action = %1, details = %2 WHERE id = %3',
          [
            1 => ['rolled_back', 'String'],
            2 => [json_encode($details), 'String'],
            3 => [$dao->id, 'Integer'],
          ]
        );

        $restoredId = CRM_Utils_Array::value('id', $params);
        if (!$restoredId) {
          $restoredId = CRM_Utils_Array::value('id', $details['record'], $dao->entity_id);
        }

        $logContext = ['source_audit_id' => $dao->id];
        if ($ufMatchConflictInfo !== NULL && !$ufMatchConflictInfo['can_restore_uf_match']) {
          $logContext['uf_match_not_restored'] = TRUE;
          $logContext['uf_match_conflicts'] = $ufMatchConflictInfo['conflicts'];
        }

        $this->logAction('rollback_restored', $apiEntity, $restoredId, $logContext);
      }
      else {
        $this->logAction('rollback_failed', $apiEntity, $dao->entity_id, [
          'message' => 'Record could not be restored from audit log.',
          'audit_id' => $dao->id,
        ]);
      }
    }

    if ($restored === 0) {
      $this->logAction('rollback_restored', 'AuditLog', NULL, [
        'message' => 'No deletions were restored during rollback.',
      ]);
    }

    // Calculate remaining after this batch
    $newRemaining = $remainingCount - $restored;
    if ($newRemaining < 0) {
      $newRemaining = 0;
    }

    return [
      'restored' => $restored,
      'remaining' => $newRemaining,
    ];
  }

  /**
   * Rollback specific deletions by audit log IDs.
   *
   * @param array $auditIds
   *   Array of audit log IDs to rollback.
   * @param int $limit
   *   Maximum number of records to restore in this batch. 0 = no limit.
   *
   * @return array
   *   Array with 'restored', 'remaining', and 'skipped' counts.
   */
  public function rollbackSelectedDeletions(array $auditIds, $limit = 0) {
    if (empty($auditIds)) {
      return [
        'restored' => 0,
        'remaining' => 0,
        'skipped' => 0,
      ];
    }

    $limit = (int) $limit;
    $auditIds = array_map('intval', $auditIds);
    $totalCount = count($auditIds);
    
    // Build the ID list for the query
    $idList = implode(',', $auditIds);
    
    $sql = "SELECT id, entity_type, entity_id, details 
            FROM civicrm_data_retention_audit_log 
            WHERE id IN ({$idList}) AND action = 'delete' 
            ORDER BY id ASC";
    if ($limit > 0) {
      $sql .= ' LIMIT ' . $limit;
    }
    
    $dao = CRM_Core_DAO::executeQuery($sql);
    $restored = 0;
    $skipped = 0;
    $processed = 0;

    while ($dao->fetch()) {
      $processed++;
      $details = json_decode($dao->details, TRUE);
      
      if (!is_array($details)) {
        $this->logAction('rollback_failed', $dao->entity_type, $dao->entity_id, [
          'message' => 'Audit log entry is missing rollback details.',
          'audit_id' => $dao->id,
        ]);
        $skipped++;
        continue;
      }

      $apiEntity = CRM_Utils_Array::value('api_entity', $details, $dao->entity_type);
      $record = CRM_Utils_Array::value('record', $details);

      if (empty($record) || !is_array($record)) {
        $this->logAction('rollback_failed', $apiEntity, $dao->entity_id, [
          'message' => 'No record snapshot available for rollback.',
          'audit_id' => $dao->id,
        ]);
        $skipped++;
        continue;
      }

      // Check if the record still exists in the database (soft-deleted)
      if (!$this->recordExistsInDatabase($apiEntity, $dao->entity_id)) {
        $this->logAction('rollback_skipped', $apiEntity, $dao->entity_id, [
          'message' => 'Record was permanently deleted and cannot be restored.',
          'audit_id' => $dao->id,
        ]);
        // Mark as not restorable
        CRM_Core_DAO::executeQuery(
          'UPDATE civicrm_data_retention_audit_log SET action = %1 WHERE id = %2',
          [
            1 => ['permanently_deleted', 'String'],
            2 => [$dao->id, 'Integer'],
          ]
        );
        $skipped++;
        continue;
      }

      // For Contact entities, check for UF_Match conflicts before restoring.
      $ufMatchConflictInfo = NULL;
      if ($apiEntity === 'Contact') {
        $ufMatchConflictInfo = $this->checkUfMatchConflicts($dao->entity_id, $details);
        if (!$ufMatchConflictInfo['can_restore_uf_match'] && !empty($ufMatchConflictInfo['conflicts'])) {
          Civi::log()->warning('Data Retention Policy: UF_Match conflicts detected during selected rollback', [
            'contact_id' => $dao->entity_id,
            'conflicts' => $ufMatchConflictInfo['conflicts'],
          ]);
        }
      }

      $params = $this->prepareRecordForCreate($record);
      if (!isset($params['id']) && !empty($dao->entity_id)) {
        $params['id'] = $dao->entity_id;
      }

      $created = $this->attemptRecordRestore($apiEntity, $params);

      if ($created) {
        $restored++;
        $details['rolled_back_at'] = date('c');
        if ($ufMatchConflictInfo !== NULL && !$ufMatchConflictInfo['can_restore_uf_match']) {
          $details['uf_match_not_restored'] = TRUE;
          $details['uf_match_conflicts'] = $ufMatchConflictInfo['conflicts'];
        }
        CRM_Core_DAO::executeQuery(
          'UPDATE civicrm_data_retention_audit_log SET action = %1, details = %2 WHERE id = %3',
          [
            1 => ['rolled_back', 'String'],
            2 => [json_encode($details), 'String'],
            3 => [$dao->id, 'Integer'],
          ]
        );

        $restoredId = CRM_Utils_Array::value('id', $params);
        if (!$restoredId) {
          $restoredId = CRM_Utils_Array::value('id', $details['record'], $dao->entity_id);
        }

        $logContext = ['source_audit_id' => $dao->id];
        if ($ufMatchConflictInfo !== NULL && !$ufMatchConflictInfo['can_restore_uf_match']) {
          $logContext['uf_match_not_restored'] = TRUE;
          $logContext['uf_match_conflicts'] = $ufMatchConflictInfo['conflicts'];
        }

        $this->logAction('rollback_restored', $apiEntity, $restoredId, $logContext);
      }
      else {
        $this->logAction('rollback_failed', $apiEntity, $dao->entity_id, [
          'message' => 'Record could not be restored from audit log.',
          'audit_id' => $dao->id,
        ]);
        $skipped++;
      }
    }

    // Calculate remaining (from the selected set, not processed yet)
    $remaining = max(0, $totalCount - $processed);

    return [
      'restored' => $restored,
      'remaining' => $remaining,
      'skipped' => $skipped,
    ];
  }

  protected function attemptRecordRestore($apiEntity, array $params) {
    try {
      // For Contacts, we need to explicitly restore from trash first
      // because the API create won't properly update is_deleted on existing records
      if ($apiEntity === 'Contact' && !empty($params['id'])) {
        $contactId = (int) $params['id'];
        
        // Check if contact is in trash
        $isDeleted = CRM_Core_DAO::singleValueQuery(
          'SELECT is_deleted FROM civicrm_contact WHERE id = %1',
          [1 => [$contactId, 'Integer']]
        );
        
        if ($isDeleted == 1) {
          // Restore from trash by directly setting is_deleted = 0
          CRM_Core_DAO::executeQuery(
            'UPDATE civicrm_contact SET is_deleted = 0 WHERE id = %1',
            [1 => [$contactId, 'Integer']]
          );
          Civi::log()->info('Data Retention Policy restored contact from trash', [
            'contact_id' => $contactId,
          ]);
        }
        
        // Now update with the rest of the data (optional - the restore is the key part)
        // Only update if we have meaningful data beyond just the ID
        $updateParams = $params;
        unset($updateParams['is_deleted']); // Already handled above
        if (count($updateParams) > 1) {
          civicrm_api3($apiEntity, 'create', $updateParams);
        }
      }
      else {
        // For other entities, use the standard create call
        // Ensure is_deleted is explicitly set to 0
        $params['is_deleted'] = 0;
        civicrm_api3($apiEntity, 'create', $params);
      }
      return TRUE;
    }
    catch (CiviCRM_API3_Exception $e) {
      Civi::log()->error('Data Retention Policy failed to restore record', [
        'entity' => $apiEntity,
        'params' => $params,
        'message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  protected function prepareRecordForCreate(array $record) {
    // Remove error fields and computed/read-only fields
    $removeFields = [
      'is_error', 'error_message',
      // Computed display fields
      'display_name', 'sort_name', 'email_greeting_display', 'postal_greeting_display', 'addressee_display',
      // Read-only fields
      'hash', 'api_key', 'created_date', 'modified_date',
      // Join fields from related entities
      'address_id', 'street_address', 'supplemental_address_1', 'supplemental_address_2', 'supplemental_address_3',
      'city', 'postal_code', 'postal_code_suffix', 'geo_code_1', 'geo_code_2', 'state_province_id', 'country_id',
      'state_province_name', 'state_province', 'country', 'worldregion_id', 'world_region',
      'phone_id', 'phone', 'phone_type_id',
      'email_id', 'on_hold',
      'im_id', 'im', 'provider_id',
      'languages', 'individual_prefix', 'individual_suffix', 'communication_style', 'gender',
    ];
    foreach ($removeFields as $field) {
      unset($record[$field]);
    }

    // Map contact_id to id for Contact entity
    if (isset($record['contact_id']) && !isset($record['id'])) {
      $record['id'] = $record['contact_id'];
    }
    unset($record['contact_id'], $record['contact_is_deleted']);

    if (isset($record['id'])) {
      $record['id'] = (int) $record['id'];
    }

    // Ensure restored records are not marked as deleted
    if (isset($record['is_deleted'])) {
      $record['is_deleted'] = 0;
    }

    return $record;
  }

  protected function getRecordSnapshot($apiEntity, $id) {
    try {
      $record = civicrm_api3($apiEntity, 'getsingle', ['id' => $id]);
      return $this->prepareRecordForCreate($record);
    }
    catch (CiviCRM_API3_Exception $e) {
      Civi::log()->warning('Data Retention Policy failed to capture snapshot for audit log', [
        'entity' => $apiEntity,
        'id' => $id,
        'message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Delete CMS user account(s) linked to a contact.
   *
   * This method deletes the CMS (Drupal, WordPress, etc.) user account
   * associated with a contact via the civicrm_uf_match table.
   *
   * @param int $contactId
   *   The CiviCRM contact ID.
   *
   * @return bool
   *   TRUE if a CMS user was deleted, FALSE otherwise.
   */
  protected function deleteCmsUserForContact($contactId) {
    $sql = 'SELECT uf_id FROM civicrm_uf_match WHERE contact_id = %1';
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [(int) $contactId, 'Integer']]);

    $deleted = FALSE;
    while ($dao->fetch()) {
      $ufId = (int) $dao->uf_id;
      if ($ufId <= 0) {
        continue;
      }

      try {
        // Use CiviCRM's CMS integration to delete the user.
        $config = CRM_Core_Config::singleton();
        $ufSystem = $config->userSystem;

        if (method_exists($ufSystem, 'deleteUser')) {
          // Preferred method if available.
          $ufSystem->deleteUser($ufId);
          $deleted = TRUE;
          Civi::log()->info('Data Retention Policy deleted CMS user', [
            'contact_id' => $contactId,
            'uf_id' => $ufId,
          ]);
        }
        elseif (defined('DRUPAL_ROOT') && function_exists('user_delete')) {
          // Drupal 7 fallback.
          user_delete($ufId);
          $deleted = TRUE;
          Civi::log()->info('Data Retention Policy deleted Drupal user via user_delete', [
            'contact_id' => $contactId,
            'uf_id' => $ufId,
          ]);
        }
        elseif (defined('DRUPAL_ROOT') && class_exists('\\Drupal\\user\\Entity\\User')) {
          // Drupal 8/9/10 fallback.
          $user = \Drupal\user\Entity\User::load($ufId);
          if ($user) {
            $user->delete();
            $deleted = TRUE;
            Civi::log()->info('Data Retention Policy deleted Drupal user via Entity API', [
              'contact_id' => $contactId,
              'uf_id' => $ufId,
            ]);
          }
        }
        elseif (defined('ABSPATH') && function_exists('wp_delete_user')) {
          // WordPress fallback.
          wp_delete_user($ufId);
          $deleted = TRUE;
          Civi::log()->info('Data Retention Policy deleted WordPress user', [
            'contact_id' => $contactId,
            'uf_id' => $ufId,
          ]);
        }
        else {
          // Delete the civicrm_uf_match entry manually if no CMS API available.
          // This prevents orphaned uf_match records but doesn't delete the actual CMS user.
          CRM_Core_DAO::executeQuery(
            'DELETE FROM civicrm_uf_match WHERE contact_id = %1 AND uf_id = %2',
            [
              1 => [(int) $contactId, 'Integer'],
              2 => [$ufId, 'Integer'],
            ]
          );
          Civi::log()->warning('Data Retention Policy removed uf_match entry but could not delete CMS user (no API available)', [
            'contact_id' => $contactId,
            'uf_id' => $ufId,
          ]);
        }
      }
      catch (Exception $e) {
        Civi::log()->error('Data Retention Policy failed to delete CMS user', [
          'contact_id' => $contactId,
          'uf_id' => $ufId,
          'message' => $e->getMessage(),
        ]);
      }
    }

    return $deleted;
  }

  /**
   * Check if a record still exists in the database.
   *
   * Used to determine if a deleted record can be rolled back (soft-deleted)
   * or is permanently gone (hard-deleted).
   *
   * @param string $apiEntity
   *   The API entity name (e.g., 'Contact', 'Participant').
   * @param int $id
   *   The record ID.
   *
   * @return bool
   *   TRUE if the record exists (even if soft-deleted), FALSE if permanently deleted.
   */
  protected function recordExistsInDatabase($apiEntity, $id) {
    $tableMap = [
      'Contact' => 'civicrm_contact',
      'Participant' => 'civicrm_participant',
      'Contribution' => 'civicrm_contribution',
      'Membership' => 'civicrm_membership',
    ];

    $table = isset($tableMap[$apiEntity]) ? $tableMap[$apiEntity] : NULL;
    if (!$table) {
      // Unknown entity type - assume it might exist
      return TRUE;
    }

    $sql = "SELECT COUNT(*) FROM {$table} WHERE id = %1";
    $count = CRM_Core_DAO::singleValueQuery($sql, [1 => [(int) $id, 'Integer']]);
    return $count > 0;
  }

  protected function logAction($action, $entityType, $entityId = NULL, array $context = []) {
    if ($entityId === NULL) {
      $params = [
        1 => [$action, 'String'],
        2 => [$entityType, 'String'],
        3 => [date('Y-m-d H:i:s'), 'String'],
        4 => [json_encode($context), 'String'],
      ];
      $sql = 'INSERT INTO civicrm_data_retention_audit_log (action, entity_type, entity_id, action_date, details) VALUES (%1, %2, NULL, %3, %4)';
    }
    else {
      $params = [
        1 => [$action, 'String'],
        2 => [$entityType, 'String'],
        3 => [(int) $entityId, 'Integer'],
        4 => [date('Y-m-d H:i:s'), 'String'],
        5 => [json_encode($context), 'String'],
      ];
      $sql = 'INSERT INTO civicrm_data_retention_audit_log (action, entity_type, entity_id, action_date, details) VALUES (%1, %2, %3, %4, %5)';
    }

    CRM_Core_DAO::executeQuery($sql, $params);
  }

  /**
   * Get list of contact IDs that should never be deleted.
   *
   * This includes the Default Organization contact and optionally
   * contacts with relationships to it.
   *
   * @param \Civi\Core\SettingsBag $settings
   *   The settings object.
   *
   * @return array
   *   Array of contact IDs to protect from deletion.
   */
  protected function getProtectedContactIds($settings) {
    $protectedIds = [];

    // Always protect the Default Organization contact.
    $defaultOrgId = $this->getDefaultOrganizationId();
    if ($defaultOrgId) {
      $protectedIds[] = $defaultOrgId;

      // Check if we should also protect related contacts.
      $protectRelated = (bool) $settings->get('data_retention_protect_default_org_contacts');
      if ($protectRelated) {
        $relatedIds = $this->getDefaultOrganizationRelatedContacts($defaultOrgId);
        $protectedIds = array_merge($protectedIds, $relatedIds);
      }
    }

    // Also check for any explicitly excluded contact IDs from settings.
    $explicitExclusions = $settings->get('data_retention_excluded_contact_ids');
    if (!empty($explicitExclusions)) {
      if (is_string($explicitExclusions)) {
        $explicitExclusions = array_filter(array_map('intval', explode(',', $explicitExclusions)));
      }
      if (is_array($explicitExclusions)) {
        $protectedIds = array_merge($protectedIds, $explicitExclusions);
      }
    }

    return array_unique(array_filter($protectedIds));
  }

  /**
   * Get the Default Organization contact ID from civicrm_domain.
   *
   * @return int|null
   *   The contact ID of the Default Organization, or NULL if not found.
   */
  protected function getDefaultOrganizationId() {
    try {
      $domainId = CRM_Core_Config::domainID();
      $contactId = CRM_Core_DAO::singleValueQuery(
        'SELECT contact_id FROM civicrm_domain WHERE id = %1',
        [1 => [$domainId, 'Integer']]
      );
      return $contactId ? (int) $contactId : NULL;
    }
    catch (Exception $e) {
      Civi::log()->warning('Data Retention Policy: Could not determine Default Organization contact', [
        'message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get contact IDs that have relationships with the Default Organization.
   *
   * @param int $orgContactId
   *   The Default Organization contact ID.
   *
   * @return array
   *   Array of related contact IDs.
   */
  protected function getDefaultOrganizationRelatedContacts($orgContactId) {
    $relatedIds = [];
    $orgContactId = (int) $orgContactId;

    if ($orgContactId <= 0) {
      return $relatedIds;
    }

    // Get contacts related via civicrm_relationship (as contact_id_a or contact_id_b).
    $sql = 'SELECT DISTINCT CASE 
              WHEN contact_id_a = %1 THEN contact_id_b 
              ELSE contact_id_a 
            END AS related_contact_id
            FROM civicrm_relationship 
            WHERE (contact_id_a = %1 OR contact_id_b = %1) 
            AND is_active = 1';

    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$orgContactId, 'Integer']]);
    while ($dao->fetch()) {
      if ($dao->related_contact_id && $dao->related_contact_id != $orgContactId) {
        $relatedIds[] = (int) $dao->related_contact_id;
      }
    }

    return array_unique($relatedIds);
  }

  /**
   * Unlink CMS user account from a contact without deleting the CMS user.
   *
   * This removes the civicrm_uf_match entry but preserves the CMS user account.
   * Use this when you want to delete the contact but keep the CMS user for audit
   * or when the CMS account should be managed separately.
   *
   * @param int $contactId
   *   The CiviCRM contact ID.
   *
   * @return bool
   *   TRUE if a UF_Match record was removed, FALSE otherwise.
   */
  protected function unlinkCmsUserFromContact($contactId) {
    $contactId = (int) $contactId;
    if ($contactId <= 0) {
      return FALSE;
    }

    // First capture the UF_Match details for the audit log.
    $ufMatchDetails = [];
    $sql = 'SELECT id, uf_id, uf_name FROM civicrm_uf_match WHERE contact_id = %1';
    $dao = CRM_Core_DAO::executeQuery($sql, [1 => [$contactId, 'Integer']]);
    while ($dao->fetch()) {
      $ufMatchDetails[] = [
        'uf_match_id' => $dao->id,
        'uf_id' => $dao->uf_id,
        'uf_name' => $dao->uf_name,
      ];
    }

    if (empty($ufMatchDetails)) {
      return FALSE;
    }

    // Delete the UF_Match records.
    CRM_Core_DAO::executeQuery(
      'DELETE FROM civicrm_uf_match WHERE contact_id = %1',
      [1 => [$contactId, 'Integer']]
    );

    Civi::log()->info('Data Retention Policy unlinked CMS user from contact', [
      'contact_id' => $contactId,
      'uf_match_details' => $ufMatchDetails,
    ]);

    return TRUE;
  }

  /**
   * Check for UF_Match conflicts before restoring a contact.
   *
   * When rolling back a contact deletion, we need to verify that the
   * original UF_Match link can be restored without creating duplicates.
   *
   * @param int $contactId
   *   The contact ID being restored.
   * @param array $auditDetails
   *   The audit log details which may contain original UF_Match info.
   *
   * @return array
   *   Array with 'can_restore_uf_match' boolean and 'conflicts' array.
   */
  protected function checkUfMatchConflicts($contactId, array $auditDetails) {
    $result = [
      'can_restore_uf_match' => TRUE,
      'conflicts' => [],
    ];

    // Check if the contact already has a UF_Match record.
    $existingMatch = CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civicrm_uf_match WHERE contact_id = %1',
      [1 => [(int) $contactId, 'Integer']]
    );

    if ($existingMatch) {
      $result['can_restore_uf_match'] = FALSE;
      $result['conflicts'][] = 'Contact already has an existing UF_Match record';
      return $result;
    }

    // Check if we have UF_Match details in the audit log.
    if (!empty($auditDetails['uf_match_details']) && is_array($auditDetails['uf_match_details'])) {
      foreach ($auditDetails['uf_match_details'] as $ufMatch) {
        $ufId = isset($ufMatch['uf_id']) ? (int) $ufMatch['uf_id'] : 0;
        $ufName = isset($ufMatch['uf_name']) ? $ufMatch['uf_name'] : '';

        // Check if this uf_id is now linked to a different contact.
        if ($ufId > 0) {
          $conflictingContact = CRM_Core_DAO::singleValueQuery(
            'SELECT contact_id FROM civicrm_uf_match WHERE uf_id = %1 AND contact_id != %2',
            [
              1 => [$ufId, 'Integer'],
              2 => [(int) $contactId, 'Integer'],
            ]
          );

          if ($conflictingContact) {
            $result['can_restore_uf_match'] = FALSE;
            $result['conflicts'][] = sprintf(
              'UF ID %d is now linked to contact %d',
              $ufId,
              $conflictingContact
            );
          }
        }

        // Check if uf_name is now used by a different record.
        if (!empty($ufName)) {
          $conflictingUfName = CRM_Core_DAO::singleValueQuery(
            'SELECT contact_id FROM civicrm_uf_match WHERE uf_name = %1 AND contact_id != %2',
            [
              1 => [$ufName, 'String'],
              2 => [(int) $contactId, 'Integer'],
            ]
          );

          if ($conflictingUfName) {
            $result['can_restore_uf_match'] = FALSE;
            $result['conflicts'][] = sprintf(
              'UF name "%s" is now linked to contact %d',
              $ufName,
              $conflictingUfName
            );
          }
        }
      }
    }

    return $result;
  }

}
