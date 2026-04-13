<?php

if (!class_exists('CRM_DataRetentionPolicy_ExtensionUtil')) {
  require_once __DIR__ . '/../CRM/DataRetentionPolicy/ExtensionUtil.php';
}

use CRM_DataRetentionPolicy_ExtensionUtil as E;

return [
  'data_retention_contact_years' => [
    'name' => 'data_retention_contact_years',
    'type' => 'Integer',
    'title' => E::ts('Contact retention interval'),
    'description' => E::ts('Delete contacts when they have no recorded activity newer than the configured interval.'),
    'default' => 0,
  ],
  'data_retention_contact_unit' => [
    'name' => 'data_retention_contact_unit',
    'type' => 'String',
    'title' => E::ts('Contact retention unit'),
    'description' => E::ts('Time unit for the contact retention policy.'),
    'default' => 'year',
  ],
  'data_retention_contact_date_source' => [
    'name' => 'data_retention_contact_date_source',
    'type' => 'String',
    'title' => E::ts('Contact retention date source'),
    'description' => E::ts('Choose whether contacts are evaluated using their last activity date or their last login date when determining deletion.'),
    'default' => 'activity',
  ],
  'data_retention_contact_trash_days' => [
    'name' => 'data_retention_contact_trash_days',
    'type' => 'Integer',
    'title' => E::ts('Contact trash retention interval'),
    'description' => E::ts('Permanently delete contacts that have remained in the trash longer than the configured interval.'),
    'default' => 0,
  ],
  'data_retention_contact_trash_unit' => [
    'name' => 'data_retention_contact_trash_unit',
    'type' => 'String',
    'title' => E::ts('Contact trash retention unit'),
    'description' => E::ts('Time unit for the contact trash retention policy.'),
    'default' => 'day',
  ],
  'data_retention_participant_years' => [
    'name' => 'data_retention_participant_years',
    'type' => 'Integer',
    'title' => E::ts('Participant retention interval'),
    'description' => E::ts('Delete participant records when their most recent update is older than the configured interval.'),
    'default' => 0,
  ],
  'data_retention_participant_unit' => [
    'name' => 'data_retention_participant_unit',
    'type' => 'String',
    'title' => E::ts('Participant retention unit'),
    'description' => E::ts('Time unit for the participant retention policy.'),
    'default' => 'year',
  ],
  'data_retention_contribution_years' => [
    'name' => 'data_retention_contribution_years',
    'type' => 'Integer',
    'title' => E::ts('Contribution retention interval'),
    'description' => E::ts('Delete contribution records when their receive date is older than the configured interval.'),
    'default' => 0,
  ],
  'data_retention_contribution_unit' => [
    'name' => 'data_retention_contribution_unit',
    'type' => 'String',
    'title' => E::ts('Contribution retention unit'),
    'description' => E::ts('Time unit for the contribution retention policy.'),
    'default' => 'year',
  ],
  'data_retention_membership_years' => [
    'name' => 'data_retention_membership_years',
    'type' => 'Integer',
    'title' => E::ts('Membership retention interval'),
    'description' => E::ts('Delete membership records when their most recent update is older than the configured interval.'),
    'default' => 0,
  ],
  'data_retention_membership_unit' => [
    'name' => 'data_retention_membership_unit',
    'type' => 'String',
    'title' => E::ts('Membership retention unit'),
    'description' => E::ts('Time unit for the membership retention policy.'),
    'default' => 'year',
  ],
  'data_retention_clean_orphan_custom_data' => [
    'name' => 'data_retention_clean_orphan_custom_data',
    'type' => 'Boolean',
    'title' => E::ts('Clean orphaned custom data records'),
    'description' => E::ts('Delete orphaned custom data records when the scheduled job runs.'),
    'default' => 0,
  ],
  'data_retention_protect_default_org_contacts' => [
    'name' => 'data_retention_protect_default_org_contacts',
    'type' => 'Boolean',
    'title' => E::ts('Protect Default Organisation related contacts'),
    'description' => E::ts('When enabled, contacts with active relationships to the Default Organisation will be excluded from retention processing. The Default Organisation contact is always protected.'),
    'default' => 1,
  ],
  'data_retention_excluded_contact_ids' => [
    'name' => 'data_retention_excluded_contact_ids',
    'type' => 'String',
    'title' => E::ts('Excluded contact IDs'),
    'description' => E::ts('Comma-separated list of contact IDs that should never be deleted by the retention policy. These contacts are protected in addition to the Default Organisation.'),
    'default' => '',
  ],
  'data_retention_cms_user_handling' => [
    'name' => 'data_retention_cms_user_handling',
    'type' => 'String',
    'title' => E::ts('CMS user account handling'),
    'description' => E::ts('Control how contacts with linked CMS user accounts (Drupal, WordPress, etc.) are handled during retention processing. "Skip" will not delete contacts with CMS accounts. "Delete both" will delete the contact AND the CMS user account. "Delete contact only" will delete the contact but preserve the CMS user account (the link in civicrm_uf_match will be removed).'),
    'default' => 'skip',
  ],
  'data_retention_audit_log_years' => [
    'name' => 'data_retention_audit_log_years',
    'type' => 'Integer',
    'title' => E::ts('Audit log retention interval'),
    'description' => E::ts('Delete audit log entries older than the configured interval.'),
    'default' => 12,
  ],
  'data_retention_audit_log_unit' => [
    'name' => 'data_retention_audit_log_unit',
    'type' => 'String',
    'title' => E::ts('Audit log retention unit'),
    'description' => E::ts('Time unit for audit log retention.'),
    'default' => 'month',
  ],
];
