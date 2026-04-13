<?php
/**
 * Script to recreate audit log entries for testing rollback.
 * 
 * This creates audit log entries for contacts currently in the trash
 * so that the rollback functionality can be tested.
 * 
 * Usage: Run via cv or drush:
 *   cv scr extensions/civicrm-data-retention-policy/scripts/recreate_test_audit_log.php
 */

// Ensure we're in a CiviCRM context
if (!defined('CIVICRM_DSN')) {
  echo "This script must be run within a CiviCRM context (e.g., via cv scr).\n";
  exit(1);
}

echo "Recreating audit log entries for rollback testing...\n\n";

// Clear existing 'delete' entries to start fresh
$clearSql = "DELETE FROM civicrm_data_retention_audit_log WHERE action IN ('delete', 'rolled_back')";
CRM_Core_DAO::executeQuery($clearSql);
echo "Cleared existing delete/rolled_back audit log entries.\n";

// Find contacts currently in the trash
$sql = "SELECT id, first_name, last_name, contact_type, email_greeting_id, postal_greeting_id, addressee_id 
        FROM civicrm_contact 
        WHERE is_deleted = 1 
        ORDER BY id ASC 
        LIMIT 2000";
$dao = CRM_Core_DAO::executeQuery($sql);

$count = 0;
while ($dao->fetch()) {
  $contactId = (int) $dao->id;
  
  // Get full contact data via API for the snapshot
  try {
    $contact = civicrm_api3('Contact', 'getsingle', [
      'id' => $contactId,
      'is_deleted' => 1, // Include deleted contacts
    ]);
    
    // Remove computed/read-only fields
    $removeFields = [
      'is_error', 'error_message',
      'display_name', 'sort_name', 'email_greeting_display', 'postal_greeting_display', 'addressee_display',
      'hash', 'api_key', 'created_date', 'modified_date',
      'address_id', 'street_address', 'supplemental_address_1', 'supplemental_address_2', 'supplemental_address_3',
      'city', 'postal_code', 'postal_code_suffix', 'geo_code_1', 'geo_code_2', 'state_province_id', 'country_id',
      'state_province_name', 'state_province', 'country', 'worldregion_id', 'world_region',
      'phone_id', 'phone', 'phone_type_id',
      'email_id', 'on_hold',
      'im_id', 'im', 'provider_id',
      'languages', 'individual_prefix', 'individual_suffix', 'communication_style', 'gender',
    ];
    foreach ($removeFields as $field) {
      unset($contact[$field]);
    }
    
    // Create the audit log entry
    $details = [
      'cutoff' => date('Y-m-d H:i:s', strtotime('-7 years')),
      'api_entity' => 'Contact',
      'record' => $contact,
    ];
    
    $insertSql = 'INSERT INTO civicrm_data_retention_audit_log (action, entity_type, entity_id, action_date, details) VALUES (%1, %2, %3, %4, %5)';
    $params = [
      1 => ['delete', 'String'],
      2 => ['Contact', 'String'],
      3 => [$contactId, 'Integer'],
      4 => [date('Y-m-d H:i:s', strtotime('-1 hour')), 'String'],
      5 => [json_encode($details), 'String'],
    ];
    
    CRM_Core_DAO::executeQuery($insertSql, $params);
    $count++;
    
    $name = trim($dao->first_name . ' ' . $dao->last_name);
    if (empty($name)) {
      $name = "Contact #{$contactId}";
    }
    echo "  Created audit log entry for: {$name} (ID: {$contactId})\n";
  }
  catch (Exception $e) {
    echo "  Warning: Could not create entry for contact {$contactId}: {$e->getMessage()}\n";
  }
}

echo "\nDone! Created {$count} audit log entries.\n";
echo "You can now test rollback at: /civicrm/admin/dataretentionpolicy/rollback/preview\n";
