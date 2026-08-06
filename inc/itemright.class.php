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
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $itemtype = $item::getType();

        if (!in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return '';
        }

        [$rightname, $rightvalue] = self::getRequiredRight($itemtype);
        if (Session::haveRight($rightname, $rightvalue)) {
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
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $itemtype = $item::getType();

        if (!in_array($itemtype, self::SUPPORTED_ITEMTYPES, true)) {
            return true;
        }

        [$rightname, $rightvalue] = self::getRequiredRight($itemtype);
        if (!Session::haveRight($rightname, $rightvalue)) {
            return true;
        }

        $itemright = new self();
        
        try {
            $itemright->showRightsForm($itemtype, $item->fields['id']);
        } catch (Exception $e) {
            Toolbox::logError('Metabase ItemRight Error: ' . $e->getMessage());
            Toolbox::logError($e->getTraceAsString());
            
            echo '<div class="alert alert-danger">';
            echo '<i class="ti ti-alert-circle"></i> ';
            echo __('An error occurred while loading Metabase rights.', 'metabase');
            if ($_SESSION['glpi_use_mode'] == Session::DEBUG_MODE) {
                echo '<br><br><strong>Debug:</strong> ' . htmlspecialchars($e->getMessage());
            }
            echo '</div>';
        }

        return true;
    }

    /**
     * Display item (group/user) rights form usando Twig.
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
        if (!Session::haveRight($rightname, $rightvalue)) {
            return false;
        }

        // Verificar configuração
        $config = PluginMetabaseConfig::getConfig();
        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            echo '<div class="alert alert-warning">' .
                '<i class="ti ti-alert-triangle"></i> ' .
                __('Metabase is not configured yet. Please configure the plugin first.', 'metabase') .
                '</div>';
            return false;
        }

        // Conectar ao Metabase
        $apiclient = new PluginMetabaseAPIClient();
        
        if (!$apiclient->checkSession()) {
            echo '<div class="alert alert-warning">' .
                '<i class="ti ti-alert-triangle"></i> ' .
                __('Unable to connect to Metabase. Please check your configuration.', 'metabase') .
                '</div>';
            return false;
        }

        // Buscar dashboards
        $dashboards = $apiclient->getDashboards();

        if (!$dashboards || !is_array($dashboards) || empty($dashboards)) {
            echo '<div class="alert alert-warning">' .
                '<i class="ti ti-alert-triangle"></i> ' .
                __('No dashboards found in Metabase.', 'metabase') .
                '</div>';
            return false;
        }

        // Preparar dados para o template
        $templateData = [
            'itemtype' => $itemtype,
            'items_id' => $itemsId,
            'dashboards' => [],
        ];

        // Adicionar direitos atuais para cada dashboard
        foreach ($dashboards as $dashboard) {
            // Filtrar apenas dashboards com embedding habilitado
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
            echo '<div class="alert alert-warning">' .
                '<i class="ti ti-alert-triangle"></i> ' .
                __('No embeddable dashboards found in Metabase.', 'metabase') .
                '</div>';
            return false;
        }

        // Renderizar template Twig
        $twig = PluginMetabaseTwig::getInstance();
        $twig->renderSafe('itemright_form.html.twig', $templateData);

        return true;
    }

    /**
     * Renderiza uma mensagem de erro/aviso.
     *
     * @param string $message
     * @param string $type (danger, warning, info, success)
     * @return string
     */
    private function renderError(string $message, string $type = 'danger'): string
    {
        $icon = match($type) {
            'warning' => 'ti-alert-triangle',
            'info' => 'ti-info-circle',
            'success' => 'ti-check-circle',
            default => 'ti-alert-circle',
        };
        
        return '<div class="alert alert-' . $type . '">' .
               '<i class="ti ' . $icon . '"></i> ' .
               htmlspecialchars($message) .
               '</div>';
    }

    // ... CONTINUAÇÃO DOS MÉTODOS EXISTENTES (canGroupsViewDashboards, etc.) ...
    // Mantenha todos os métodos que já estavam funcionando

    /**
     * Check if any group from the given list is able to view at least one dashboard.
     *
     * @param int[] $groupIds
     *
     * @return boolean
     */
    public static function canGroupsViewDashboards(array $groupIds): bool
    {
        foreach ($groupIds as $groupId) {
            if (self::canItemViewDashboards(Group::class, $groupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if any group from the given list is able to view the given dashboard.
     *
     * @param int[]   $groupIds
     * @param integer $dashboardUuid
     *
     * @return boolean
     */
    public static function canGroupsViewDashboard(array $groupIds, $dashboardUuid): bool
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
                'dashboard_uuid' => $dashboardUuid,
            ],
        ]);
        foreach ($iterator as $right) {
            if (($right['rights'] & READ) !== 0) {
                return true;
            }
        }
        return false;
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

        $iterator = $DB->request(
            [
                'FROM'  => self::getTable(),
                'WHERE' => [
                    'itemtype' => $itemtype,
                    'items_id' => $itemsId,
                ],
            ],
        );

        foreach ($iterator as $right) {
            if (($right['rights'] & READ) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if item (group/user) is able to view given dashboard.
     *
     * @param string  $itemtype
     * @param integer $itemsId
     * @param integer $dashboardUuid
     *
     * @return boolean
     */
    public static function canItemViewDashboard($itemtype, $itemsId, $dashboardUuid): bool
    {
        return (self::getItemRightForDashboard($itemtype, $itemsId, $dashboardUuid) & READ) !== 0;
    }

    /**
     * Returns item (group/user) rights for given dashboard.
     *
     * @param string  $itemtype
     * @param integer $itemsId
     * @param integer $dashboardUuid
     *
     * @return integer
     */
    private static function getItemRightForDashboard($itemtype, $itemsId, $dashboardUuid)
    {
        if (empty($itemsId)) {
            return 0;
        }

        $rightCriteria = [
            'itemtype'       => $itemtype,
            'items_id'       => $itemsId,
            'dashboard_uuid' => $dashboardUuid,
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
     * @param integer $dashboardUuid
     * @param integer $rights
     *
     * @return void
     */
    public static function setDashboardRightsForItem($itemtype, $itemsId, $dashboardUuid, $rights)
    {
        $itemright = new self();

        $rightsExists = $itemright->getFromDBByCrit(
            [
                'itemtype'       => $itemtype,
                'items_id'       => $itemsId,
                'dashboard_uuid' => $dashboardUuid,
            ],
        );

        if ($rightsExists) {
            $itemright->update(
                [
                    'id'     => $itemright->fields['id'],
                    'rights' => $rights,
                ],
            );
        } else {
            $itemright->add(
                [
                    'itemtype'       => $itemtype,
                    'items_id'       => $itemsId,
                    'dashboard_uuid' => $dashboardUuid,
                    'rights'         => $rights,
                ],
            );
        }
    }

    /**
     * Install itemrights database.
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
                     `dashboard_uuid` int NOT NULL,
                     `rights` int NOT NULL,
                     PRIMARY KEY (`id`),
                     UNIQUE `itemtype_items_id_dashboard_uuid` (`itemtype`, `items_id`, `dashboard_uuid`)
                  ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";
            $DB->doQuery($query);
        }
    }

    /**
     * Uninstall itemrights database.
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