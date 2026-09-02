<?php

/**
 * -------------------------------------------------------------------------
 * Metabase plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Metabase.
 *
 * Metabase is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Metabase is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Metabase. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2018-2023 by Metabase plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/metabase
 * -------------------------------------------------------------------------
 *
 * CUSTOM FORK NOTICE
 * -------------------------------------------------------------------------
 * This class was added in a local fork to allow defining dashboard access
 * rights per Group and per User, in addition to the native per Profile
 * rights handled by PluginMetabaseProfileright.
 * -------------------------------------------------------------------------
 */

class PluginMetabaseItemright extends CommonDBTM
{
    /**
     * Itemtypes supported by this "generic" right holder.
     */
    public const SUPPORTED_ITEMTYPES = [Group::class, User::class];

    /**
     * {@inheritDoc}
     * @see CommonGLPI::getTypeName()
     */
    public static function getTypeName($nb = 0)
    {
        return __s('Metabase', 'metabase');
    }

    /**
     * Returns the GLPI right (and value) required to manage metabase
     * dashboard rights for the given itemtype.
     *
     * @param string $itemtype Group::class or User::class
     *
     * @return array{0: string, 1: int} [rightname, right value]
     */
    private static function getRequiredRight(string $itemtype): array
    {
        return match ($itemtype) {
            Group::class => ['group', UPDATE],
            User::class  => ['user', UPDATE],
            default      => ['profile', UPDATE],
        };
    }

    /**
     * {@inheritDoc}
     * @see CommonGLPI::getTabNameForItem()
     * 
     * NOTE: Unlike PluginMetabaseProfileright which requires only READ permission
     * to view the tab, we use READ here as well for consistency. The UPDATE
     * permission is only required for modifying rights (saving changes).
     * This allows users with read-only access to view the dashboard-rights matrix
     * without being able to modify it.
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $itemtype = $item::getType();

        if (!in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return '';
        }

        [$rightname, $rightvalue] = self::getRequiredRight($itemtype);
        // Use READ permission to display the tab (consistent with PluginMetabaseProfileright)
        if (Session::haveRight($rightname, READ)) {
            return self::createTabEntry(
                self::getTypeName(),
                0,
                $itemtype,
                PluginMetabaseConfig::getIcon()
            );
        }

        return '';
    }

    /**
     * {@inheritDoc}
     * @see CommonGLPI::displayTabContentForItem()
     * 
     * NOTE: READ permission is sufficient to view the content.
     * UPDATE permission is checked inside showRightsForm() for action buttons.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $itemtype = $item::getType();

        if (!in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return true;
        }

        [$rightname, $rightvalue] = self::getRequiredRight($itemtype);
        // Use READ permission to display content (consistent with PluginMetabaseProfileright)
        if (!Session::haveRight($rightname, READ)) {
            return true;
        }

        $itemright = new self();
        
        try {
            $itemright->showRightsForm($itemtype, $item->fields['id']);
        } catch (Exception $e) {
            if (method_exists('Toolbox', 'logDebug')) {
                \Toolbox::logDebug('Metabase ItemRight Error: ' . $e->getMessage());
                \Toolbox::logDebug($e->getTraceAsString());
            } else {
                error_log('Metabase ItemRight Error: ' . $e->getMessage());
            }
            
            PluginMetabaseTwig::getInstance()->renderSafe('itemright_alert.html.twig', [
                'type' => 'danger',
                'icon' => 'alert-circle',
                'message' => __('An error occurred while loading Metabase rights.', 'metabase'),
                'debug' => $_SESSION['glpi_use_mode'] == Session::DEBUG_MODE ? $e->getMessage() : null,
            ]);
        }

        return true;
    }

    /**
     * Display item (group/user) rights form using Twig templates.
     *
     * @param string  $itemtype Group::class or User::class
     * @param integer $itemsId  Group or User id
     * @param array   $options
     *
     * @return bool
     */
    public function showRightsForm($itemtype, $itemsId, $options = [])
    {
        if (!in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return false;
        }

        [$rightname, $rightvalue] = self::getRequiredRight($itemtype);
        $canUpdate = Session::haveRight($rightname, UPDATE);

        $config = PluginMetabaseConfig::getConfig();
        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            PluginMetabaseTwig::getInstance()->renderSafe('itemright_alert.html.twig', [
                'type' => 'warning',
                'icon' => 'alert-triangle',
                'message' => __('Metabase is not configured yet. Please configure the plugin first.', 'metabase'),
            ]);
            return false;
        }

        $apiclient = new PluginMetabaseAPIClient();
        
        if (!$apiclient->checkSession()) {
            PluginMetabaseTwig::getInstance()->renderSafe('itemright_alert.html.twig', [
                'type' => 'warning',
                'icon' => 'alert-triangle',
                'message' => __('Unable to connect to Metabase. Please check your configuration.', 'metabase'),
            ]);
            return false;
        }

        $dashboards = $apiclient->getDashboards();

        if (!$dashboards || !is_array($dashboards) || empty($dashboards)) {
            PluginMetabaseTwig::getInstance()->renderSafe('itemright_alert.html.twig', [
                'type' => 'warning',
                'icon' => 'alert-triangle',
                'message' => __('No dashboards found in Metabase.', 'metabase'),
            ]);
            return false;
        }

        $templateData = [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'can_update' => $canUpdate,
            'dashboards' => [],
        ];

        foreach ($dashboards as $dashboard) {
            if (!isset($dashboard['enable_embedding']) || !$dashboard['enable_embedding']) {
                continue;
            }
            
            $templateData['dashboards'][] = [
                'id' => $dashboard['id'],
                'name' => $dashboard['name'] ?? __('Unnamed Dashboard', 'metabase'),
                'description' => $dashboard['description'] ?? '',
                'rights' => self::getItemRightForDashboard(
                    $itemtype,
                    $itemsId,
                    $dashboard['id']
                ),
            ];
        }

        if (empty($templateData['dashboards'])) {
            PluginMetabaseTwig::getInstance()->renderSafe('itemright_alert.html.twig', [
                'type' => 'warning',
                'icon' => 'alert-triangle',
                'message' => __('No embeddable dashboards found in Metabase.', 'metabase'),
            ]);
            return false;
        }

        $twig = PluginMetabaseTwig::getInstance();
        $twig->renderSafe('itemright_form.html.twig', $templateData);

        return true;
    }

    // ============ OPTIMIZED BATCH QUERY METHODS ============

    /**
     * Get all dashboard IDs that the given groups can view.
     * Uses a single batch query instead of per-group/per-dashboard queries.
     * Performance: O(D) instead of O(D × G)
     *
     * @param int[] $groupIds
     * @return int[] List of dashboard IDs (Metabase IDs)
     */
    public static function getGroupsViewableDashboards(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }

        /** @var DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => Group::class,
                'items_id' => $groupIds,
                'rights'   => ['&', READ],
            ],
            'GROUPBY' => 'dashboard_id',
        ]);

        $dashboardIds = [];
        foreach ($iterator as $row) {
            $dashboardIds[] = (int) $row['dashboard_id'];
        }

        return $dashboardIds;
    }

    /**
     * Get all dashboard IDs that the user can view.
     * Single batch query for performance.
     *
     * @param integer $userId
     * @return int[] List of dashboard IDs (Metabase IDs)
     */
    public static function getUserViewableDashboards($userId): array
    {
        if (empty($userId)) {
            return [];
        }

        /** @var DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => User::class,
                'items_id' => $userId,
                'rights'   => ['&', READ],
            ],
            'GROUPBY' => 'dashboard_id',
        ]);

        $dashboardIds = [];
        foreach ($iterator as $row) {
            $dashboardIds[] = (int) $row['dashboard_id'];
        }

        return $dashboardIds;
    }

    /**
     * Check if any group from the given list is able to view at least one dashboard.
     * Uses LIMIT 1 for performance.
     *
     * @param int[] $groupIds
     *
     * @return boolean
     */
    public static function canGroupsViewDashboards(array $groupIds): bool
    {
        if (empty($groupIds)) {
            return false;
        }

        /** @var DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => Group::class,
                'items_id' => $groupIds,
                'rights'   => ['&', READ],
            ],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0;
    }

    /**
     * Check if any group from the given list is able to view the given dashboard.
     * Uses LIMIT 1 for performance.
     *
     * @param int[]   $groupIds
     * @param integer $dashboardId  Dashboard ID in Metabase
     *
     * @return boolean
     */
    public static function canGroupsViewDashboard(array $groupIds, $dashboardId): bool
    {
        if (empty($groupIds)) {
            return false;
        }

        /** @var DBmysql $DB */
        global $DB;

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype'       => Group::class,
                'items_id'       => $groupIds,
                'dashboard_id'   => $dashboardId,
                'rights'         => ['&', READ],
            ],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0;
    }

    /**
     * Check if item (group/user) is able to view at least one dashboard.
     *
     * @param string  $itemtype
     * @param integer $itemsId
     *
     * @return boolean
     */
    public static function canItemViewDashboards($itemtype, $itemsId): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        if (empty($itemsId)) {
            return false;
        }

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype' => $itemtype,
                'items_id' => $itemsId,
                'rights'   => ['&', READ],
            ],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0;
    }

    /**
     * Check if item (group/user) is able to view given dashboard.
     *
     * @param string  $itemtype
     * @param integer $itemsId
     * @param integer $dashboardId  Dashboard ID in Metabase
     *
     * @return boolean
     */
    public static function canItemViewDashboard($itemtype, $itemsId, $dashboardId): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        if (empty($itemsId)) {
            return false;
        }

        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
                'dashboard_id' => $dashboardId,
                'rights'       => ['&', READ],
            ],
            'LIMIT' => 1,
        ]);

        return count($iterator) > 0;
    }

    /**
     * Returns item (group/user) rights for given dashboard.
     *
     * @param string  $itemtype
     * @param integer $itemsId
     * @param integer $dashboardId  Dashboard ID in Metabase
     *
     * @return integer
     */
    private static function getItemRightForDashboard($itemtype, $itemsId, $dashboardId)
    {
        if (empty($itemsId)) {
            return 0;
        }

        $rightCriteria = [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'dashboard_id' => $dashboardId,
        ];

        $itemright = new self();
        if ($itemright->getFromDBByCrit($rightCriteria)) {
            return $itemright->fields['rights'];
        }

        return 0;
    }

    /**
     * Defines item (group/user) rights for dashboard.
     *
     * @param string  $itemtype
     * @param integer $itemsId
     * @param integer $dashboardId  Dashboard ID in Metabase
     * @param integer $rights
     *
     * @return void
     */
    public static function setDashboardRightsForItem($itemtype, $itemsId, $dashboardId, $rights)
    {
        $itemright = new self();

        $rightsExists = $itemright->getFromDBByCrit([
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'dashboard_id' => $dashboardId,
        ]);

        if ($rightsExists) {
            $itemright->update([
                'id'     => $itemright->fields['id'],
                'rights' => $rights,
            ]);
        } else {
            $itemright->add([
                'itemtype'     => $itemtype,
                'items_id'     => $itemsId,
                'dashboard_id' => $dashboardId,
                'rights'       => $rights,
            ]);
        }
    }

    /**
     * Install itemrights database table.
     *
     * @param Migration $migration
     *
     * @return void
     */
    public static function install(Migration $migration)
    {
        /** @var DBmysql $DB */
        global $DB;

        $default_charset   = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $query = "CREATE TABLE IF NOT EXISTS `$table` (
                     `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
                     `itemtype` varchar(100) NOT NULL,
                     `items_id` int {$default_key_sign} NOT NULL,
                     `dashboard_id` int NOT NULL,
                     `rights` int NOT NULL,
                     PRIMARY KEY (`id`),
                     UNIQUE `itemtype_items_id_dashboard_id` (`itemtype`, `items_id`, `dashboard_id`),
                     KEY `dashboard_id` (`dashboard_id`),
                     KEY `rights` (`rights`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
            $DB->doQuery($query);
        } else {
            $columns = $DB->listFields($table);
            if (isset($columns['dashboard_uuid'])) {
                $migration->displayMessage("Renaming dashboard_uuid to dashboard_id in $table");
                $query = "ALTER TABLE `$table` CHANGE `dashboard_uuid` `dashboard_id` int {$default_key_sign} NOT NULL";
                $DB->doQuery($query);
            }
        }
    }

    /**
     * Uninstall itemrights database table.
     *
     * @return void
     */
    public static function uninstall()
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->doQuery('DROP TABLE IF EXISTS `' . self::getTable() . '`');
    }
}