{include file="CRM/common/formButtons.tpl" location="top"}

<div class="messages warning no-popup">
  <p><strong>{ts}Important: Permanent deletion cannot be undone{/ts}</strong></p>
  <p>{ts}When contacts are deleted by this policy, they first move to the <strong>trash</strong> (soft delete). While in the trash, all related data (activities, contributions, memberships, event registrations, etc.) remains intact and contacts can be fully restored using the Rollback feature.{/ts}</p>
  <p>{ts}Once the <strong>trash retention period</strong> expires, contacts are <strong>permanently deleted</strong> along with ALL related data. This includes:{/ts}</p>
  <ul style="margin-left: 20px; margin-top: 5px;">
    <li>{ts}Activities and activity history{/ts}</li>
    <li>{ts}Contributions and financial records{/ts}</li>
    <li>{ts}Event registrations (participants){/ts}</li>
    <li>{ts}Memberships{/ts}</li>
    <li>{ts}Relationships, notes, tags, and group memberships{/ts}</li>
    <li>{ts}Custom field data{/ts}</li>
  </ul>
  <p><strong>{ts}Recommendation:{/ts}</strong> {ts}Set the trash retention period to at least 30-90 days to allow adequate time to identify and recover incorrectly deleted contacts before permanent destruction.{/ts}</p>
</div>

<div class="messages status no-popup">
  <p><strong>{ts}Default Organisation Protection{/ts}</strong></p>
  <p>{ts}The Default Organisation contact (configured in CiviCRM domain settings) is always protected and will never be deleted by the retention policy. You can also protect contacts related to the Default Organisation and specify additional contact IDs to exclude in the settings below.{/ts}</p>
</div>

<div class="crm-block crm-form-block">
  {foreach from=$entityDefinitions key=key item=definition}
    <div class="crm-section">
      <div class="label">{$form.$key.label}</div>
      <div class="content">
        {$form.$key.html}
        {if $definition.description}
          <div class="description">{$definition.description}</div>
        {/if}
      </div>
      <div class="clear"></div>
    </div>
  {/foreach}
</div>

<div class="crm-block crm-form-block">
  <h3>{ts}Rollback Deletions{/ts}</h3>
  <div class="crm-section">
    <p>{ts}If contacts were deleted in error, you can review and restore soft-deleted records from the audit log.{/ts}</p>
    <p><a class="button" href="{$rollbackPreviewUrl}"><span><i class="crm-i fa-undo" aria-hidden="true"></i> {ts}Review Rollback Options{/ts}</span></a></p>
  </div>
</div>

{include file="CRM/common/formButtons.tpl" location="bottom"}
