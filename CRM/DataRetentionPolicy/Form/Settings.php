<?php

use CRM_DataRetentionPolicy_ExtensionUtil as E;

class CRM_DataRetentionPolicy_Form_Settings extends CRM_Core_Form {

  protected $settingKeys = [
    'data_retention_contact_years' => 'contact',
    'data_retention_contact_unit' => 'contact',
    'data_retention_contact_date_source' => 'contact',
    'data_retention_cms_user_handling' => 'contact',
    'data_retention_contact_trash_days' => 'contact_trash',
    'data_retention_contact_trash_unit' => 'contact_trash',
    'data_retention_participant_years' => 'participant',
    'data_retention_participant_unit' => 'participant',
    'data_retention_contribution_years' => 'contribution',
    'data_retention_contribution_unit' => 'contribution',
    'data_retention_membership_years' => 'membership',
    'data_retention_membership_unit' => 'membership',
    'data_retention_clean_orphan_custom_data' => 'custom_data',
    'data_retention_protect_default_org_contacts' => 'protection',
    'data_retention_excluded_contact_ids' => 'protection',
    'data_retention_audit_log_years' => 'audit_log',
    'data_retention_audit_log_unit' => 'audit_log',
    'data_retention_batch_size' => 'job_settings',
  ];

  public function buildQuickForm() {
    $this->setTitle(E::ts('Data Retention Policy Settings'));

    $definitions = $this->getEntityDefinitions();
    foreach ($definitions as $key => $definition) {
      $inputType = CRM_Utils_Array::value('input_type', $definition, 'text');
      switch ($inputType) {
        case 'select':
          $this->add('select', $key, $definition['label'], CRM_Utils_Array::value('options', $definition, []));
          break;
        case 'checkbox':
          $this->add('checkbox', $key, $definition['label']);
          break;

        default:
          $attributes = CRM_Utils_Array::value('attributes', $definition, ['size' => 4, 'maxlength' => 3]);
          $this->add('text', $key, $definition['label'], $attributes);
          $ruleMessage = !empty($definition['rule_message']) ? $definition['rule_message'] : E::ts('Please enter a whole number or leave blank to disable deletion.');
          $this->addRule($key, $ruleMessage, 'integer');
          break;
      }
    }

    $this->assign('entityDefinitions', $definitions);
    $this->assign('rollbackPreviewUrl', CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/preview', 'reset=1'));

    $this->addButtons([
      ['type' => 'submit', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  public function setDefaultValues() {
    $defaults = [];
    $settings = Civi::settings();
    foreach (array_keys($this->settingKeys) as $setting) {
      $defaults[$setting] = $settings->get($setting);
    }
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $settings = Civi::settings();

    $definitions = $this->getEntityDefinitions();
    foreach (array_keys($this->settingKeys) as $setting) {
      $definition = CRM_Utils_Array::value($setting, $definitions, []);
      $valueType = CRM_Utils_Array::value('value_type', $definition, 'integer');

      switch ($valueType) {
        case 'string':
          $value = CRM_Utils_Array::value($setting, $values);
          $options = CRM_Utils_Array::value('options', $definition, []);
          if (!array_key_exists($value, $options)) {
            $value = CRM_Utils_Array::value('default', $definition, '');
          }
          break;

        case 'boolean':
          $value = !empty($values[$setting]) ? 1 : 0;
          break;

        default:
          $value = CRM_Utils_Array::value($setting, $values);
          $value = is_numeric($value) ? (int) $value : 0;
          if ($value < 0) {
            $value = 0;
          }
          break;
      }

      $settings->set($setting, $value);
    }

    CRM_Core_Session::setStatus(E::ts('Data retention policy settings have been saved.'), E::ts('Saved'), 'success');
  }

  protected function getEntityDefinitions() {
    return [
      'data_retention_contact_years' => [
        'label' => E::ts('Contact records (amount)'),
        'description' => E::ts('Contacts are deleted when their most recent activity, modification or creation is older than the configured interval.'),
        'value_type' => 'integer',
      ],
      'data_retention_contact_unit' => [
        'label' => E::ts('Contact records (unit)'),
        'description' => '',
        'input_type' => 'select',
        'options' => $this->getIntervalUnitOptions(),
        'value_type' => 'string',
        'default' => 'year',
      ],
      'data_retention_contact_date_source' => [
        'label' => E::ts('Contact retention date source'),
        'description' => E::ts('Select whether contacts should be evaluated using their last recorded activity or their last login date.'),
        'input_type' => 'select',
        'options' => [
          'activity' => E::ts('Last activity date'),
          'login' => E::ts('Last login date (from CMS account)'),
        ],
        'value_type' => 'string',
        'default' => 'activity',
      ],
      'data_retention_cms_user_handling' => [
        'label' => E::ts('CMS user account handling'),
        'description' => E::ts('Control how contacts with linked CMS user accounts (Drupal, WordPress, etc.) are handled. "Skip" will exclude these contacts from deletion. "Delete both" will delete the CMS user account along with the contact. "Delete contact only" will remove the CiviCRM contact but preserve the CMS user account.'),
        'input_type' => 'select',
        'options' => [
          'skip' => E::ts('Skip - never delete contacts with CMS user accounts'),
          'delete_both' => E::ts('Delete both - remove contact and CMS user'),
          'delete_contact_keep_user' => E::ts('Delete contact only - preserve CMS user account'),
        ],
        'value_type' => 'string',
        'default' => 'skip',
      ],
      'data_retention_contact_trash_days' => [
        'label' => E::ts('Contacts in trash (amount)'),
        'description' => E::ts('Contacts that have already been deleted (moved to the trash) are permanently removed after exceeding the configured interval in the trash.'),
        'value_type' => 'integer',
      ],
      'data_retention_contact_trash_unit' => [
        'label' => E::ts('Contacts in trash (unit)'),
        'description' => '',
        'input_type' => 'select',
        'options' => $this->getIntervalUnitOptions(),
        'value_type' => 'string',
        'default' => 'day',
      ],
      'data_retention_participant_years' => [
        'label' => E::ts('Participant records (amount)'),
        'description' => E::ts('Participants are deleted when their most recent modification or registration is older than the configured interval.'),
        'value_type' => 'integer',
      ],
      'data_retention_participant_unit' => [
        'label' => E::ts('Participant records (unit)'),
        'description' => '',
        'input_type' => 'select',
        'options' => $this->getIntervalUnitOptions(),
        'value_type' => 'string',
        'default' => 'year',
      ],
      'data_retention_contribution_years' => [
        'label' => E::ts('Contribution records (amount)'),
        'description' => E::ts('Contributions are deleted when their receive date (or creation date if receive date is empty) is older than the configured interval.'),
        'value_type' => 'integer',
      ],
      'data_retention_contribution_unit' => [
        'label' => E::ts('Contribution records (unit)'),
        'description' => '',
        'input_type' => 'select',
        'options' => $this->getIntervalUnitOptions(),
        'value_type' => 'string',
        'default' => 'year',
      ],
      'data_retention_membership_years' => [
        'label' => E::ts('Membership records (amount)'),
        'description' => E::ts('Memberships are deleted when their most recent modification or creation is older than the configured interval.'),
        'value_type' => 'integer',
      ],
      'data_retention_membership_unit' => [
        'label' => E::ts('Membership records (unit)'),
        'description' => '',
        'input_type' => 'select',
        'options' => $this->getIntervalUnitOptions(),
        'value_type' => 'string',
        'default' => 'year',
      ],
      'data_retention_clean_orphan_custom_data' => [
        'label' => E::ts('Delete orphaned custom data records'),
        'description' => E::ts('Remove orphaned rows from custom data tables each time the scheduled job runs.'),
        'input_type' => 'checkbox',
        'value_type' => 'boolean',
      ],
      'data_retention_protect_default_org_contacts' => [
        'label' => E::ts('Protect Default Organisation related contacts'),
        'description' => E::ts('When enabled, contacts with active relationships to the Default Organisation will be excluded from retention processing. The Default Organisation contact itself is always protected regardless of this setting.'),
        'input_type' => 'checkbox',
        'value_type' => 'boolean',
      ],
      'data_retention_excluded_contact_ids' => [
        'label' => E::ts('Excluded contact IDs'),
        'description' => E::ts('Enter a comma-separated list of contact IDs that should never be deleted by the retention policy (e.g. 1,5,42). These contacts are protected in addition to the Default Organisation.'),
        'attributes' => ['size' => 40, 'maxlength' => 255],
        'value_type' => 'string',
        'default' => '',
      ],
      'data_retention_audit_log_years' => [
        'label' => E::ts('Audit log records (amount)'),
        'description' => E::ts('Audit log entries are deleted after they exceed the configured interval.'),
        'value_type' => 'integer',
      ],
      'data_retention_audit_log_unit' => [
        'label' => E::ts('Audit log records (unit)'),
        'description' => '',
        'input_type' => 'select',
        'options' => $this->getIntervalUnitOptions(),
        'value_type' => 'string',
        'default' => 'month',
      ],
      'data_retention_batch_size' => [
        'label' => E::ts('Batch size per entity type'),
        'description' => E::ts('Maximum number of records to delete per entity type during each scheduled job run. Set to 0 for no limit. Default is 50. If more records remain after a run, they will be processed on the next scheduled execution.'),
        'value_type' => 'integer',
      ],
    ];
  }

  protected function getIntervalUnitOptions() {
    return [
      'day' => E::ts('Days'),
      'week' => E::ts('Weeks'),
      'month' => E::ts('Months'),
      'year' => E::ts('Years'),
    ];
  }

}
