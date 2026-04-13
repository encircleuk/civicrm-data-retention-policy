# CiviCRM Data Retention Policy

This extension provides a configurable data retention policy for CiviCRM installations. Administrators can define how long specific record types should be kept, and a scheduled job enforces those rules by deleting records whose activity is older than the configured window.

## Features

* New **Data Retention Policy** settings screen under `Administer » System Settings`.
* Individual retention periods (in years) for contacts, participants, and contributions.
* Option to evaluate contact retention windows using either their last activity date or their last login date.
* Separate control (in days) for how long deleted contacts remain in the trash before being purged permanently.
* **Default Organisation protection** — the Default Organisation contact is automatically excluded from deletion.
* **Related contacts protection** — optionally protect contacts with active relationships to the Default Organisation.
* **CMS user account handling** — configurable behaviour for contacts with linked Drupal/WordPress user accounts.
* Scheduled job (`Apply Data Retention Policies`) which deletes records older than the defined retention window using the CiviCRM API.
* Audit logging with rollback capability for soft-deleted records.
* **UF_Match conflict detection** — rollback operations check for duplicate CMS user links before restoring contacts.

## Installation

1. Copy the extension directory to your CiviCRM extension directory.
2. Enable the extension from **Administer » System Settings » Extensions**.

## Configuration

1. Navigate to **Administer » System Settings » Data Retention Policy**.
2. Enter the retention period in years for each entity you want to purge automatically. Use `0` (or leave blank) to disable deletion for that entity. Configure the number of days that contacts should remain in the trash before they are permanently removed.
3. Save the settings.

The scheduled job evaluates the following activity dates when determining whether a record should be deleted:

| Entity        | Activity field(s) used |
| ------------- | ---------------------- |
| Contacts      | Last activity date (from `civicrm_activity_contact`), falling back to `modified_date` or `created_date` (or `log_date` from the CMS account when configured) |
| Contacts (trash) | `modified_date` |
| Participants  | `modified_date`, falling back to `register_date` or `create_date` |
| Contributions | `receive_date`, falling back to `modified_date` or `create_date` |

## Protected Contacts

Certain contacts are automatically protected from deletion by the retention policy:

### Default Organisation

The **Default Organisation** contact (configured in CiviCRM's domain settings) is **always protected** and will never be deleted, regardless of activity dates. This is a critical system contact required for CiviCRM to function correctly.

### Related Contacts Protection

You can optionally protect contacts that have **active relationships** with the Default Organisation. When enabled (via the "Protect Default Organisation related contacts" setting), any contact linked to the Default Organisation via `civicrm_relationship` will be excluded from retention processing.

This is useful for protecting staff members, administrators, or key stakeholders who are connected to your organisation record.

### Excluded Contact IDs

For additional protection, you can specify a comma-separated list of contact IDs that should never be deleted. Enter these in the "Excluded contact IDs" setting (e.g., `1,5,42`). These contacts are protected in addition to the Default Organisation.

Use cases:
- VIP contacts or major donors
- System accounts or integration contacts
- Historical records that must be preserved indefinitely

## CMS User Account Handling

When CiviCRM is integrated with a CMS (Drupal, WordPress, etc.), contacts may be linked to CMS user accounts via the `civicrm_uf_match` table. The extension provides three options for handling these contacts:

| Option | Behaviour |
| ------ | --------- |
| **Skip** (default) | Contacts with linked CMS user accounts are excluded from retention processing entirely. The CMS user and contact both remain untouched. |
| **Delete both** | Both the CiviCRM contact AND the CMS user account are deleted. Use with caution — this permanently removes the user's ability to log in. |
| **Delete contact only** | The CiviCRM contact is deleted, but the CMS user account is preserved. The link in `civicrm_uf_match` is removed to prevent orphaned references. |

> ⚠️ **Important:** The "Delete both" option will permanently remove CMS user accounts. Ensure this aligns with your data protection policies and that affected users are notified appropriately.

## Understanding the Two-Stage Deletion Process

This extension uses a **two-stage deletion process** for contacts to provide a safety net:

### Stage 1: Soft Delete (Recoverable)

When an **active contact** exceeds its retention period, the job moves it to the **trash** by setting `is_deleted = 1`. The contact record **remains in the database** and can be:
- Viewed in CiviCRM's trash
- Restored manually through the CiviCRM UI
- **Rolled back** using this extension's rollback API

### Stage 2: Permanent Delete (Unrecoverable)

When a **trashed contact** exceeds the trash retention period, the job **permanently deletes** it from the database. Once permanently deleted:
- The record is completely removed from CiviCRM
- **Rollback is NOT possible** — even with the audit log snapshot
- Related data (activities, contributions, etc.) may also be deleted by CiviCRM's cascading deletes

> ⚠️ **Critical:** Set a generous trash retention period (e.g., 30-90 days or more) to give your team adequate time to identify and recover incorrectly deleted contacts before they are permanently destroyed.

### Recommended Configuration

| Setting | Recommendation | Reason |
| ------- | -------------- | ------ |
| Contact retention | Match your legal/policy requirements | Primary retention window |
| **Trash retention** | **30-90 days minimum** | **Recovery window before permanent deletion** |
| Audit log retention | 1-2 years | Compliance evidence and rollback capability |
| Protect Default Org contacts | Enabled | Prevents accidental deletion of staff/administrators |
| CMS user handling | Skip (default) | Safest option; prevents user account issues |

## Scheduled Job

The extension registers a scheduled job named **Apply Data Retention Policies**. Review the job in **Administer » System Settings » Scheduled Jobs** and adjust the execution schedule to match your compliance needs. When run, the job reports how many records were deleted per entity and logs any failures to the CiviCRM log.

> ⚠️ **Important:** Permanent deletion cannot be undone. Ensure that the configured retention windows align with your organisation's policies and any legal requirements before enabling the scheduled job.

## Audit Log

All deletions performed by the data retention job are recorded in the `civicrm_data_retention_audit_log` table. Each entry includes:

| Column | Description |
| ------ | ----------- |
| `action` | The action performed: `job_start`, `delete`, `job_complete`, `rolled_back`, `rollback_restored`, `rollback_skipped`, `permanently_deleted` |
| `entity_type` | The type of record affected (e.g., `Contact`, `Contact (trash)`, `Participant`) |
| `entity_id` | The ID of the deleted record |
| `action_date` | When the action occurred |
| `details` | JSON containing the full record snapshot before deletion, cutoff date, API entity used, and CMS user handling information (`cms_user_deleted`, `cms_user_unlinked`, `uf_match_not_restored`, `uf_match_conflicts`) |

The audit log enables:
- **Compliance reporting**: Track what was deleted and when
- **Rollback capability**: Restore soft-deleted records using the stored snapshots
- **Debugging**: Investigate failures or unexpected deletions

### Audit Log Retention

The audit log itself can be configured to auto-purge old entries. Set the retention period in the Data Retention Policy settings screen. Entries older than the configured period will be deleted during job execution.

## Rollback Procedure

If contacts are deleted in error, you can restore **soft-deleted records only** using the rollback API.

> ⚠️ **Important:** Rollback only works for records that are still in the database (soft-deleted/trashed). **Permanently deleted records cannot be restored**, even though snapshots exist in the audit log. This is why setting an adequate trash retention period is critical.

### Running Rollback via Command Line

```bash
cv api3 DataRetentionPolicyJob.rollback
```

### Running Rollback via PHP

```php
$result = civicrm_api3('DataRetentionPolicyJob', 'rollback', []);
echo $result['values']['message'];
```

### How Rollback Works

1. **Reads audit log**: Finds all entries where `action = 'delete'`
2. **Checks if record exists**: Verifies the record is still in the database
3. **Skips permanently deleted records**: If the record no longer exists in the database, it is marked as `permanently_deleted` in the audit log and skipped
4. **Checks UF_Match conflicts** (contacts only): Verifies no duplicate CMS user links would be created
5. **Restores soft-deleted records**: Updates the existing record via API to set `is_deleted = 0`
6. **Updates audit log**: Changes the action from `delete` to `rolled_back` for successfully restored records
7. **Logs results**: Creates `rollback_restored` or `rollback_skipped` entries

### UF_Match Conflict Detection

When rolling back a contact that was linked to a CMS user account, the extension checks for potential conflicts:

- **Contact already has a UF_Match record**: If the contact being restored already has a different CMS user linked, the UF_Match restoration is skipped.
- **UF ID already linked elsewhere**: If the original CMS user ID (`uf_id`) is now linked to a different contact, the UF_Match restoration is skipped.
- **Username already in use**: If the original username (`uf_name`) is now linked to a different contact, the UF_Match restoration is skipped.

When conflicts are detected:
- The **contact is still restored** successfully
- The UF_Match link is **not restored** to avoid creating duplicates
- The conflict details are logged in the audit log (`uf_match_not_restored`, `uf_match_conflicts`)
- A warning is written to the CiviCRM log

### Rollback Behaviour by Deletion Type

| Original State | After Deletion Job | Can Rollback? | After Rollback |
| -------------- | ------------------ | ------------- | -------------- |
| Active contact | Moved to trash (`is_deleted=1`) | ✅ **Yes** | Restored to active, same ID |
| Trashed contact | Permanently deleted | ❌ **No** | Marked as `permanently_deleted` |
| Participant/Contribution | Permanently deleted | ❌ **No** | Marked as `permanently_deleted` |

### Limitations

- **Permanently deleted records cannot be restored**: Once a record is removed from the database, rollback is not possible. The audit log snapshot is retained for compliance purposes only.
- **Snapshot contains contact record only**: The audit log stores a snapshot of the **contact record itself** (name, email address values, custom fields on the contact, etc.). It does **NOT** capture related records stored in separate tables.
- **UF_Match links may not be restored**: If the CMS user account link would conflict with existing records, the contact is restored but the UF_Match link is skipped. Check the audit log for `uf_match_not_restored` entries.
- **One-time operation**: Running rollback multiple times will only process entries still marked as `delete`. Already rolled-back entries are skipped.
- **Audit log retention**: If the audit log entries have been purged, rollback is not possible.

### What Is NOT Captured in the Snapshot

When a contact is deleted, CiviCRM's cascading deletes remove all related data. The following are **NOT stored** in the audit log and **cannot be recovered** if the contact is permanently deleted:

| Data Type | Notes |
| --------- | ----- |
| Activities | All activities where the contact was a participant |
| Contributions | Donations, payments, and financial records |
| Event Registrations | Participant records for events |
| Memberships | Membership records and history |
| Relationships | Connections to other contacts |
| Notes | Notes attached to the contact |
| Phone numbers | Stored in `civicrm_phone` table |
| Email addresses | Stored in `civicrm_email` table (values are in snapshot, but not as separate records) |
| Addresses | Stored in `civicrm_address` table |
| Custom field data | Data in custom field tables (some values may be in snapshot) |
| Tags and Groups | Group memberships and tag assignments |
| Change log / Activity history | CiviCRM's internal logging |
| Case records | Cases associated with the contact |
| Pledges | Pledge records and payments |
| CMS User links | UF_Match records linking contacts to Drupal/WordPress users (conflict checks are performed on rollback) |

> ⚠️ **This is why the trash retention period is critical.** While a contact is in the trash (soft-deleted), all related data remains intact in the database. Rollback will restore the contact and all its relationships. Once permanently deleted, everything is gone.

### Checking Rollback Status

Query the audit log to see what can be rolled back:

```sql
-- Records available for rollback (soft-deleted only)
SELECT entity_type, COUNT(*) as count 
FROM civicrm_data_retention_audit_log 
WHERE action = 'delete' 
GROUP BY entity_type;

-- Records that were permanently deleted (cannot be restored)
SELECT entity_type, COUNT(*) as count 
FROM civicrm_data_retention_audit_log 
WHERE action = 'permanently_deleted' 
GROUP BY entity_type;

-- Recent rollback activity
SELECT * FROM civicrm_data_retention_audit_log 
WHERE action IN ('rolled_back', 'rollback_restored', 'rollback_skipped', 'permanently_deleted') 
ORDER BY id DESC LIMIT 20;

-- Check for rollbacks where UF_Match was not restored due to conflicts
SELECT id, entity_id, action_date, 
       JSON_EXTRACT(details, '$.uf_match_conflicts') as conflicts
FROM civicrm_data_retention_audit_log 
WHERE action = 'rolled_back' 
  AND JSON_EXTRACT(details, '$.uf_match_not_restored') = true;
```

## Development

The extension key is `uk.co.encircle.dataretentionpolicy`. Contributions are welcome via pull requests.