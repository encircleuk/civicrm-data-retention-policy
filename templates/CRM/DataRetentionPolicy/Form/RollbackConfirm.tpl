<div class="crm-block crm-form-block">
  <div class="messages warning no-popup">
    <p><strong><i class="crm-i fa-exclamation-triangle" aria-hidden="true"></i> {ts}Confirm Rollback Operation{/ts}</strong></p>
    <p>{ts}You are about to restore the selected records that were previously deleted by the data retention policy.{/ts}</p>
  </div>

  <div class="crm-section">
    <h3>{ts}Summary{/ts}</h3>
    <table class="report-layout">
      <tr>
        <td><strong>{ts}Records selected:{/ts}</strong></td>
        <td>{$selectedCount}</td>
      </tr>
      <tr>
        <td><strong>{ts}Records that can be restored:{/ts}</strong></td>
        <td><span style="color: #0a0;">{$restorableCount}</span></td>
      </tr>
      {if $permanentlyDeletedCount > 0}
      <tr>
        <td><strong>{ts}Records permanently deleted (cannot restore):{/ts}</strong></td>
        <td><span style="color: #c00;">{$permanentlyDeletedCount}</span></td>
      </tr>
      {/if}
    </table>
  </div>

  {if $restorableCount > 0}
    <div class="crm-section">
      <h3>{ts}Records to Restore{/ts}</h3>
      <table class="selector row-highlight" style="max-height: 400px; overflow-y: auto;">
        <thead>
          <tr class="columnheader">
            <th>{ts}Type{/ts}</th>
            <th>{ts}ID{/ts}</th>
            <th>{ts}Name{/ts}</th>
            <th>{ts}Deleted On{/ts}</th>
            <th>{ts}Status{/ts}</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$recordsToRestore item=record}
            <tr class="{cycle values="odd-row,even-row"} {if !$record.can_restore}disabled{/if}">
              <td>{$record.entity_type|escape}</td>
              <td>{$record.entity_id|escape}</td>
              <td>{$record.display_name|escape}</td>
              <td>{$record.action_date|crmDate}</td>
              <td>
                {if $record.can_restore}
                  <span style="color: #0a0;"><i class="crm-i fa-check" aria-hidden="true"></i> {ts}Will be restored{/ts}</span>
                {else}
                  <span style="color: #c00;"><i class="crm-i fa-times" aria-hidden="true"></i> {ts}Cannot restore{/ts}</span>
                {/if}
              </td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>

    <div class="messages status no-popup">
      <p><i class="crm-i fa-info-circle" aria-hidden="true"></i> {ts 1=$restorableCount}Clicking "Confirm Rollback" will restore %1 record(s) from the trash back to active status.{/ts}</p>
    </div>
  {else}
    <div class="messages status no-popup">
      <p><i class="crm-i fa-info-circle" aria-hidden="true"></i> {ts}None of the selected records can be restored. They may have been permanently deleted.{/ts}</p>
    </div>
  {/if}

  <div class="crm-section">
    <p><a href="{$previewUrl}">&laquo; {ts}Back to Preview{/ts}</a></p>
  </div>

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
