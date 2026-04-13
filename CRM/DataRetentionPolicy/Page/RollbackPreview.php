<?php

use CRM_DataRetentionPolicy_ExtensionUtil as E;

/**
 * Page to preview audit log entries that can be rolled back.
 */
class CRM_DataRetentionPolicy_Page_RollbackPreview extends CRM_Core_Page {

  /**
   * Default number of records per page.
   */
  const DEFAULT_PAGE_SIZE = 50;

  /**
   * Allowed page sizes for "rows per page" selector.
   */
  const ALLOWED_PAGE_SIZES = [25, 50, 100, 200];

  /**
   * Allowed sort columns.
   */
  const ALLOWED_SORT_COLUMNS = [
    'entity_type' => 'entity_type',
    'entity_id' => 'entity_id',
    'action_date' => 'action_date',
  ];

  /**
   * Run the page.
   *
   * @return void
   */
  public function run() {
    CRM_Utils_System::setTitle(E::ts('Rollback Preview - Data Retention Policy'));

    // Get pagination parameters
    $page = CRM_Utils_Request::retrieve('page', 'Positive', $this, FALSE, 1);
    $rowsPerPage = CRM_Utils_Request::retrieve('rp', 'Positive', $this, FALSE, self::DEFAULT_PAGE_SIZE);

    // Validate rows per page
    if (!in_array($rowsPerPage, self::ALLOWED_PAGE_SIZES)) {
      $rowsPerPage = self::DEFAULT_PAGE_SIZE;
    }

    // Get sort parameters
    $sort = CRM_Utils_Request::retrieve('sort', 'String', $this, FALSE, 'action_date');
    $sortDir = CRM_Utils_Request::retrieve('dir', 'String', $this, FALSE, 'DESC');

    // Validate sort column and direction
    if (!isset(self::ALLOWED_SORT_COLUMNS[$sort])) {
      $sort = 'action_date';
    }
    $sortDir = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';

    // Get filter parameters
    $filters = [
      'entity_type' => CRM_Utils_Request::retrieve('f_entity_type', 'String', $this, FALSE, ''),
      'entity_id' => CRM_Utils_Request::retrieve('f_entity_id', 'String', $this, FALSE, ''),
      'name' => CRM_Utils_Request::retrieve('f_name', 'String', $this, FALSE, ''),
      'date_from' => CRM_Utils_Request::retrieve('f_date_from', 'String', $this, FALSE, ''),
      'date_to' => CRM_Utils_Request::retrieve('f_date_to', 'String', $this, FALSE, ''),
      'status' => CRM_Utils_Request::retrieve('f_status', 'String', $this, FALSE, ''),
    ];

    // Sanitise filter values
    $filters = array_map(function ($v) {
      return is_string($v) ? trim($v) : $v;
    }, $filters);

    $offset = ($page - 1) * $rowsPerPage;

    // Get total count and summary (unfiltered for overview)
    $summary = $this->getSummary();

    // Get filtered count
    $filteredCount = $this->getFilteredCount($filters);
    $totalPages = max(1, ceil($filteredCount / $rowsPerPage));

    // Ensure page is within valid range
    if ($page > $totalPages) {
      $page = $totalPages;
      $offset = max(0, ($page - 1) * $rowsPerPage);
    }

    $entries = $this->getRollbackableEntries($offset, $rowsPerPage, $sort, $sortDir, $filters);

    // Calculate page range for pagination display
    $pageRange = $this->calculatePageRange($page, $totalPages);

    // Build filter query string for pagination links
    $filterQueryString = $this->buildFilterQueryString($filters);

    // Check if any filters are active
    $hasActiveFilters = !empty(array_filter($filters));

    $this->assign('entries', $entries);
    $this->assign('hasEntries', !empty($entries) || $hasActiveFilters);
    $this->assign('summary', $summary);
    $this->assign('currentPage', $page);
    $this->assign('totalPages', $totalPages);
    $this->assign('totalCount', $summary['total']);
    $this->assign('filteredCount', $filteredCount);
    $this->assign('rowsPerPage', $rowsPerPage);
    $this->assign('allowedPageSizes', self::ALLOWED_PAGE_SIZES);
    $this->assign('pageRange', $pageRange);
    $this->assign('sort', $sort);
    $this->assign('sortDir', $sortDir);
    $this->assign('filters', $filters);
    $this->assign('filterQueryString', $filterQueryString);
    $this->assign('hasActiveFilters', $hasActiveFilters);
    $this->assign('settingsUrl', CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/settings', 'reset=1'));
    $this->assign('confirmUrl', CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/confirm', 'reset=1'));
    $this->assign('baseUrl', CRM_Utils_System::url('civicrm/admin/dataretentionpolicy/rollback/preview', 'reset=1'));

    parent::run();
  }

  /**
   * Build URL query string from filter values.
   *
   * @param array $filters
   * @return string
   */
  protected function buildFilterQueryString($filters) {
    $params = [];
    foreach ($filters as $key => $value) {
      if ($value !== '' && $value !== NULL) {
        $params[] = 'f_' . $key . '=' . urlencode($value);
      }
    }
    return implode('&', $params);
  }

  /**
   * Get count of entries matching current filters.
   *
   * @param array $filters
   * @return int
   */
  protected function getFilteredCount($filters) {
    $whereClause = $this->buildFilterWhereClause($filters, $params);
    $sql = "SELECT COUNT(*) 
            FROM civicrm_data_retention_audit_log a
            LEFT JOIN civicrm_contact c ON a.entity_id = c.id
            WHERE a.action = 'delete' AND a.entity_type = 'Contact' {$whereClause}";
    return (int) CRM_Core_DAO::singleValueQuery($sql, $params);
  }

  /**
   * Build WHERE clause additions for filters.
   *
   * @param array $filters
   * @param array &$params
   * @return string
   */
  protected function buildFilterWhereClause($filters, &$params = []) {
    $where = [];
    $paramIndex = 1;

    if (!empty($filters['entity_type'])) {
      $where[] = "a.entity_type = %{$paramIndex}";
      $params[$paramIndex] = [$filters['entity_type'], 'String'];
      $paramIndex++;
    }

    if (!empty($filters['entity_id'])) {
      $where[] = "a.entity_id = %{$paramIndex}";
      $params[$paramIndex] = [(int) $filters['entity_id'], 'Integer'];
      $paramIndex++;
    }

    if (!empty($filters['date_from'])) {
      $where[] = "DATE(a.action_date) >= %{$paramIndex}";
      $params[$paramIndex] = [$filters['date_from'], 'String'];
      $paramIndex++;
    }

    if (!empty($filters['date_to'])) {
      $where[] = "DATE(a.action_date) <= %{$paramIndex}";
      $params[$paramIndex] = [$filters['date_to'], 'String'];
      $paramIndex++;
    }

    if (!empty($filters['status'])) {
      if ($filters['status'] === 'restorable') {
        $where[] = "c.id IS NOT NULL";
      }
      elseif ($filters['status'] === 'deleted') {
        $where[] = "c.id IS NULL";
      }
    }

    if (!empty($filters['name'])) {
      // Search in the JSON details field for name
      $where[] = "a.details LIKE %{$paramIndex}";
      $params[$paramIndex] = ['%' . $filters['name'] . '%', 'String'];
      $paramIndex++;
    }

    return !empty($where) ? ' AND ' . implode(' AND ', $where) : '';
  }

  /**
   * Calculate which page numbers to display in pagination.
   *
   * Shows first page, last page, current page, and 2 pages either side
   * of current, with ellipsis where gaps exist.
   *
   * @param int $currentPage
   * @param int $totalPages
   * @return array
   */
  protected function calculatePageRange($currentPage, $totalPages) {
    if ($totalPages <= 1) {
      return [];
    }

    $pages = [];
    $range = 2; // Pages either side of current

    // Always include first page
    $pages[1] = 1;

    // Add pages around current page
    for ($i = max(2, $currentPage - $range); $i <= min($totalPages - 1, $currentPage + $range); $i++) {
      $pages[$i] = $i;
    }

    // Always include last page if > 1
    if ($totalPages > 1) {
      $pages[$totalPages] = $totalPages;
    }

    ksort($pages);

    // Convert to array with ellipsis markers
    $result = [];
    $prev = 0;
    foreach ($pages as $pageNum) {
      if ($prev && $pageNum - $prev > 1) {
        $result[] = ['type' => 'ellipsis'];
      }
      $result[] = ['type' => 'page', 'num' => $pageNum];
      $prev = $pageNum;
    }

    return $result;
  }

  /**
   * Get summary counts of rollbackable entries using optimized JOIN query.
   *
   * This uses a single SQL query with LEFT JOIN instead of checking each
   * record individually, providing significant performance improvement
   * with large datasets (4000+ records in ~7ms vs ~30s with naive loop).
   *
   * @return array
   */
  protected function getSummary() {
    $sql = "SELECT 
              COUNT(*) as total,
              SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) as restorable,
              SUM(CASE WHEN c.id IS NULL THEN 1 ELSE 0 END) as permanently_deleted
            FROM civicrm_data_retention_audit_log a
            LEFT JOIN civicrm_contact c ON a.entity_id = c.id
            WHERE a.action = 'delete' AND a.entity_type = 'Contact'";
    $dao = CRM_Core_DAO::executeQuery($sql);
    $dao->fetch();

    return [
      'total' => (int) $dao->total,
      'restorable' => (int) $dao->restorable,
      'permanently_deleted' => (int) $dao->permanently_deleted,
    ];
  }

  /**
   * Get paginated audit log entries that can be rolled back.
   *
   * @param int $offset
   * @param int $limit
   * @param string $sort
   * @param string $sortDir
   * @param array $filters
   * @return array
   */
  protected function getRollbackableEntries($offset, $limit, $sort = 'action_date', $sortDir = 'DESC', $filters = []) {
    $entries = [];

    // Validate sort column to prevent SQL injection
    $sortColumn = self::ALLOWED_SORT_COLUMNS[$sort] ?? 'action_date';
    $sortDirection = $sortDir === 'ASC' ? 'ASC' : 'DESC';

    // Build filter WHERE clause
    $params = [];
    $whereClause = $this->buildFilterWhereClause($filters, $params);

    // Add pagination params (offset after filter params)
    $offsetParamIdx = count($params) + 1;
    $limitParamIdx = $offsetParamIdx + 1;
    $params[$offsetParamIdx] = [$offset, 'Integer'];
    $params[$limitParamIdx] = [$limit, 'Integer'];

    $sql = "SELECT a.id, a.entity_type, a.entity_id, a.action_date, a.details,
                   CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END as can_restore
            FROM civicrm_data_retention_audit_log a
            LEFT JOIN civicrm_contact c ON a.entity_id = c.id
            WHERE a.action = 'delete' AND a.entity_type = 'Contact' {$whereClause}
            ORDER BY {$sortColumn} {$sortDirection}
            LIMIT %{$offsetParamIdx}, %{$limitParamIdx}";
    $dao = CRM_Core_DAO::executeQuery($sql, $params);

    while ($dao->fetch()) {
      $details = json_decode($dao->details, TRUE);
      $displayName = $this->getDisplayName($dao->entity_id, $details);
      // Use the can_restore from the JOIN query instead of separate check
      $canRestore = (bool) $dao->can_restore;

      $entries[] = [
        'id' => $dao->id,
        'entity_type' => $dao->entity_type,
        'entity_id' => $dao->entity_id,
        'action_date' => $dao->action_date,
        'display_name' => $displayName,
        'can_restore' => $canRestore,
        'restore_status' => $canRestore ? E::ts('Can be restored') : E::ts('Permanently deleted - cannot restore'),
        'details' => $details,
      ];
    }

    return $entries;
  }

  /**
   * Extract display name from audit log details or look up current contact.
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

    // Try to look up the contact if it still exists
    try {
      $contact = civicrm_api3('Contact', 'getsingle', [
        'id' => $entityId,
        'return' => ['display_name'],
      ]);
      return $contact['display_name'];
    }
    catch (Exception $e) {
      return E::ts('Record #%1', [1 => $entityId]);
    }
  }

  /**
   * Check if a record can be restored (still exists in database as soft-deleted).
   *
   * @param string $entityType
   * @param int $entityId
   * @param array|null $details
   * @return bool
   */
  protected function checkCanRestore($entityType, $entityId, $details) {
    $apiEntity = !empty($details['api_entity']) ? $details['api_entity'] : $entityType;

    // For contacts, check if record exists in database (including soft-deleted)
    if ($apiEntity === 'Contact') {
      $sql = 'SELECT id FROM civicrm_contact WHERE id = %1';
      $exists = CRM_Core_DAO::singleValueQuery($sql, [1 => [$entityId, 'Integer']]);
      return !empty($exists);
    }

    // For other entities, try a generic check
    try {
      civicrm_api3($apiEntity, 'getsingle', [
        'id' => $entityId,
        'options' => ['limit' => 1],
      ]);
      return TRUE;
    }
    catch (Exception $e) {
      return FALSE;
    }
  }

}
