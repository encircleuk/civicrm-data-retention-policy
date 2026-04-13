<?php

use CRM_DataRetentionPolicy_ExtensionUtil as E;

/**
 * Form to confirm rollback of deleted records.
 */
class CRM_DataRetentionPolicy_Form_RollbackConfirm extends CRM_Core_Form {

  /**
   * Selected audit log IDs to rollback.
   *
   * @var array
   */
  protected $selectedIds = [];

  /**
   * Records to be restored.
   *
   * @var array
   */
  protected $recordsToRestore = [];

  /**
   * Pre-process the form.
   */
  public function preProcess() {
    parent::preProcess();

    // Get selected IDs from URL (GET parameter)
    $selectedIdsString = CRM_Utils_Request::retrieve('selected_ids', 'String', $this, FALSE, '');
    
    if (!empty($selectedIdsString)) {
      // Parse and validate IDs
      $ids = array_filter(array_map('intval', explode(',', $selectedIdsString)));
      $this->selectedIds = $ids;
    }

    // If no IDs provided, redirect back to preview
    if (empty($this->selectedIds)) {
      CRM_Core_Session::setStatus(E::ts('No records were selected for rollback.'), E::ts('Rollback'), 'warning');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/preview', 'reset=1'));
    }

    // Load details of selected records
    $this->recordsToRestore = $this->getSelectedRecordDetails($this->selectedIds);
  }

  /**
   * Build the form.
   */
  public function buildQuickForm() {
    $this->setTitle(E::ts('Confirm Rollback - Data Retention Policy'));

    // Count restorable vs permanently deleted
    $restorableCount = 0;
    $permanentlyDeletedCount = 0;
    foreach ($this->recordsToRestore as $record) {
      if ($record['can_restore']) {
        $restorableCount++;
      }
      else {
        $permanentlyDeletedCount++;
      }
    }

    $this->assign('selectedCount', count($this->selectedIds));
    $this->assign('restorableCount', $restorableCount);
    $this->assign('permanentlyDeletedCount', $permanentlyDeletedCount);
    $this->assign('recordsToRestore', $this->recordsToRestore);
    $this->assign('previewUrl', CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/preview', 'reset=1'));

    // Add hidden field with selected IDs
    $this->add('hidden', 'selected_ids');

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Confirm Rollback (%1 records)', [1 => $restorableCount]),
        'isDefault' => TRUE,
        'icon' => 'fa-undo',
      ],
      [
        'type' => 'cancel',
        'name' => E::ts('Cancel'),
      ],
    ]);
  }

  /**
   * Set default values for the form.
   *
   * @return array
   */
  public function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    $defaults['selected_ids'] = implode(',', $this->selectedIds);
    return $defaults;
  }

  /**
   * Get details of selected records from audit log.
   *
   * @param array $auditIds
   * @return array
   */
  protected function getSelectedRecordDetails($auditIds) {
    if (empty($auditIds)) {
      return [];
    }

    $records = [];
    
    // Build safe ID list
    $idList = implode(',', array_map('intval', $auditIds));
    $sql = "SELECT a.id, a.entity_type, a.entity_id, a.action_date, a.details,
                   CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END as can_restore
            FROM civicrm_data_retention_audit_log a
            LEFT JOIN civicrm_contact c ON a.entity_id = c.id
            WHERE a.id IN ({$idList}) AND a.action = 'delete'
            ORDER BY a.id ASC";
    
    $dao = CRM_Core_DAO::executeQuery($sql);

    while ($dao->fetch()) {
      $details = json_decode($dao->details, TRUE);
      $displayName = $this->getDisplayName($dao->entity_id, $details);

      $records[] = [
        'audit_id' => $dao->id,
        'entity_type' => $dao->entity_type,
        'entity_id' => $dao->entity_id,
        'action_date' => $dao->action_date,
        'display_name' => $displayName,
        'can_restore' => (bool) $dao->can_restore,
      ];
    }

    return $records;
  }

  /**
   * Extract display name from audit log details.
   *
   * @param int $entityId
   * @param array|null $details
   * @return string
   */
  protected function getDisplayName($entityId, $details) {
    if (!empty($details['record']['display_name'])) {
      return $details['record']['display_name'];
    }
    if (!empty($details['record']['sort_name'])) {
      return $details['record']['sort_name'];
    }
    if (!empty($details['record']['first_name']) || !empty($details['record']['last_name'])) {
      return trim(($details['record']['first_name'] ?? '') . ' ' . ($details['record']['last_name'] ?? ''));
    }
    if (!empty($details['record']['organization_name'])) {
      return $details['record']['organization_name'];
    }
    if (!empty($details['record']['household_name'])) {
      return $details['record']['household_name'];
    }

    return E::ts('Record #%1', [1 => $entityId]);
  }

  /**
   * Process the form submission.
   */
  public function postProcess() {
    $values = $this->exportValues();
    
    // Parse selected IDs from form hidden field
    $selectedIdsString = CRM_Utils_Array::value('selected_ids', $values, '');
    $auditIds = array_filter(array_map('intval', explode(',', $selectedIdsString)));

    if (empty($auditIds)) {
      CRM_Core_Session::setStatus(E::ts('No records were selected for rollback.'), E::ts('Rollback'), 'warning');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/preview', 'reset=1'));
      return;
    }

    $batchSize = 100;
    $processor = new CRM_DataRetentionPolicy_Service_RetentionProcessor();
    $result = $processor->rollbackSelectedDeletions($auditIds, $batchSize);

    $restored = $result['restored'];
    $remaining = $result['remaining'];
    $skipped = $result['skipped'];

    // Build message
    if ($restored > 0) {
      $message = E::ts('Successfully restored %1 record(s) from the data retention audit log.', [1 => $restored]);
      
      if ($remaining > 0) {
        // Remaining IDs that weren't processed in this batch
        $processedCount = $restored + $skipped;
        $remainingIds = array_slice($auditIds, $processedCount);
        
        $message .= '<br><br><strong>' . E::ts('%1 records remaining to restore.', [1 => $remaining]) . '</strong>';
        $message .= ' <a href="' . CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/confirm', 'reset=1&selected_ids=' . implode(',', $remainingIds)) . '">' . E::ts('Continue rollback') . '</a>';
      }
      
      if ($skipped > 0) {
        $message .= '<br><br>' . E::ts('%1 record(s) could not be restored (permanently deleted).', [1 => $skipped]);
      }
      
      CRM_Core_Session::setStatus($message, E::ts('Rollback Complete'), 'success', ['expires' => 0]);
    }
    else {
      $message = E::ts('No records could be restored.');
      if ($skipped > 0) {
        $message .= ' ' . E::ts('%1 record(s) were permanently deleted and cannot be restored.', [1 => $skipped]);
      }
      CRM_Core_Session::setStatus($message, E::ts('Rollback Complete'), 'info', ['expires' => 0]);
    }

    // Redirect back to preview page
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/preview', 'reset=1'));
  }

}
