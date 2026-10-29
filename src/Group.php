<?php

/*
 -------------------------------------------------------------------------
 More Groups plugin for GLPI
 Copyright (c) 2022-2026 by the TICGAL Team.
 https://www.tic.gal
 -------------------------------------------------------------------------
 LICENSE
 This file is part of the More Groups plugin.
 More Groups plugin is free software; you can redistribute it and/or modify
 it under the terms of the GNU Affero General Public License as published by
 the Free Software Foundation; either version 3 of the License, or
 (at your option) any later version.
 More Groups plugin is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU Affero General Public License for more details.
 You should have received a copy of the GNU Affero General Public License
 along with More Groups. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 @package   More Groups
 @author    the TICGAL team
 @copyright Copyright (c) 2022-2026 TICGAL team
 @license   AGPL License 3.0 or (at your option) any later version
                http://www.gnu.org/licenses/agpl-3.0-standalone.html
 @link      https://www.tic.gal
 @since     2022
 ----------------------------------------------------------------------
*/

namespace GlpiPlugin\Moregroups;

use CommonDBChild;
use CommonDBTM;
use DBConnection;
use Glpi\Application\View\TemplateRenderer;
use Group_User;
use Html;
use MassiveAction;
use Migration;
use Session;
use User;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class Group extends CommonDBChild
{
    public static string $itemtype = 'Group';
    public static string $items_id = 'groups_id';
    public bool $dohistory = true;

    public static function getTypeName($nb = 0)
    {
        return __('Deactivated users', 'moregroups');
    }

    public function getForbiddenStandardMassiveAction()
    {
        $forbidden   = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        $forbidden[] = 'clone';
        return $forbidden;
    }

    public function getSpecificMassiveActions($checkitem = null)
    {
        $actions = [];
        if (Group_User::canUpdate()) {
            $actions[__CLASS__ . MassiveAction::CLASS_ACTION_SEPARATOR . 'activate'] = __('Activate users', 'moregroups');
        }

        $actions += parent::getSpecificMassiveActions($checkitem);
        return $actions;
    }

    public static function showMassiveActionsSubForm(MassiveAction $ma)
    {
        switch ($ma->getAction()) {
            case 'deactivate':
                $submitname = _sx('button', 'Deactivate users', 'moregroups');
                echo Html::submit($submitname, ['name' => 'massiveaction', 'class' => 'btn btn-sm btn-primary']);
                return true;
            case 'activate':
                $submitname = _sx('button', 'Activate users', 'moregroups');
                echo Html::submit($submitname, ['name' => 'massiveaction', 'class' => 'btn btn-sm btn-primary']);
                return true;
        }
        return parent::showMassiveActionsSubForm($ma);
    }

    /**
     * Single authorization rule for moving a membership out of glpi_groups_users. It is the
     * rule core applies to the Group > Users tab (add and remove members): anyone who may
     * manage group memberships may also deactivate one. Update right on the group itself is
     * not required, the same as for core. Entity access is part of the membership's own check.
     */
    public static function canDeactivateMembership(Group_User $group_user): bool
    {
        return Group_User::canUpdate()
            && $group_user->can((int) $group_user->getID(), PURGE);
    }

    /**
     * Single authorization rule for putting a deactivated user back in the group: the
     * right core requires to add that member (same rule as the Group > Users tab).
     */
    public static function canActivateMembership(self $item): bool
    {
        $input = $item->fields;
        unset($input['id']);

        return Group_User::canUpdate()
            && (new Group_User())->can(-1, CREATE, $input);
    }

    public static function processMassiveActionsForOneItemtype(MassiveAction $ma, CommonDBTM $item, array $ids)
    {
        switch ($ma->getAction()) {
            case 'deactivate':
                // Core's process stage trusts the processor and does not re-check which itemtype was
                // posted: only act on the itemtype this action is designed for.
                if (!$item instanceof Group_User) {
                    foreach ($ids as $id) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                    }
                    $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                    return;
                }
                foreach ($ids as $id) {
                    if (!$item->getFromDB($id)) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                        $ma->addMessage($item->getErrorMessage(ERROR_NOT_FOUND, $item::getTypeName(1)));
                    } elseif (!self::canDeactivateMembership($item)) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                        $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                    } else {
                        $input = $item->fields;
                        unset($input['id']);
                        $group = new self();
                        if ($group->add($input)) {
                            $group_user = new Group_User();
                            if ($group_user->delete(['id' => $id])) {
                                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                            } else {
                                $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                                $ma->addMessage($group_user->getErrorMessage(ERROR_NOT_FOUND));
                            }
                        } else {
                            $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                            $ma->addMessage($group->getErrorMessage(ERROR_NOT_FOUND));
                        }
                    }
                }
                return;

            case 'activate':
                if (!$item instanceof self) {
                    foreach ($ids as $id) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                    }
                    $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                    return;
                }
                foreach ($ids as $id) {
                    if (!$item->getFromDB($id)) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                        $ma->addMessage($item->getErrorMessage(ERROR_NOT_FOUND, $item::getTypeName(1)));
                    } elseif (!self::canActivateMembership($item)) {
                        $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_NORIGHT);
                        $ma->addMessage($item->getErrorMessage(ERROR_RIGHT));
                    } else {
                        $input = $item->fields;
                        unset($input['id']);
                        $group_user = new Group_User();
                        if ($group_user->add($input)) {
                            // Purga el registro de la tabla del plugin tras reactivar exitosamente al usuario
                            $item->delete(['id' => $id], true);
                            $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_OK);
                        } else {
                            $ma->itemDone($item->getType(), $id, MassiveAction::ACTION_KO);
                            $ma->addMessage($group_user->getErrorMessage(ERROR_NOT_FOUND));
                        }
                    }
                }
                return;
        }
        parent::processMassiveActionsForOneItemtype($ma, $item, $ids);
    }

    public static function showDeactivated($item)
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($item->getType() != 'Group') {
            return false;
        }

        $ID = $item->getID();
        if (!User::canView() || !$item->can($ID, READ)) {
            return false;
        }

        $query = [
            'FROM'  => self::getTable(),
            'WHERE' => [
                'groups_id' => $ID,
            ],
        ];

        // Same rule as core's Group > Users tab and as GroupActionController: whoever can manage memberships
        $canedit = Group_User::canUpdate();
        $rand    = mt_rand();

        echo "<div class='card mx-n2 mb-n2 mt-4 border-0 shadow-none'>";
        echo "<div class='card-header'>";
        echo "<div class='ribbon ribbon-bookmark ribbon-top ribbon-start bg-blue s-1'>";
        echo "<i class='ti ti-users-group fa-2x'></i>";
        echo "</div>";
        echo "<h4 class='card-title ps-5'>" . __('Deactivated users', 'moregroups') . "</h4>";
        echo "</div>";
        echo "<div class='card-body'>";
        $entries = [];
        foreach ($DB->request($query) as $row) {
            $user = new User();
            $user->getFromDB($row['users_id']);

            $entry = [
                'itemtype'  => __CLASS__,
                'id'        => $row['id'],
                'row_class' => 'table-danger',
                'user'      => $user->getLink(),
                'dynamic'   => $row['is_dynamic'] ? "<i class='ti ti-check'></i>" : '',
                'manager'   => $row['is_manager'] ? "<i class='ti ti-check'></i>" : '',
                'delegatee' => $row['is_userdelegate'] ? "<i class='ti ti-check'></i>" : '',
            ];
            if ($canedit) {
                $entry['actions'] = sprintf(
                    "<button type='button' class='btn btn-sm btn-primary' data-moregroups-action='activate' data-moregroups-rowid='%d' title='%s'><i class='ti ti-eye'></i></button>",
                    (int) $row['id'],
                    htmlescape(_sx('button', 'Activate user', 'moregroups')),
                );
            }
            $entries[] = $entry;
        }

        $columns = [
            'user'      => User::getTypeName(1),
            'dynamic'   => __('Dynamic'),
            'manager'   => _n('Manager', 'Managers', 1),
            'delegatee' => __('Delegatee'),
        ];
        $formatters = [
            'user'      => 'raw_html',
            'dynamic'   => 'raw_html',
            'manager'   => 'raw_html',
            'delegatee' => 'raw_html',
        ];
        if ($canedit) {
            $columns['actions'] = '';
            $formatters['actions'] = 'raw_html';
        }

        TemplateRenderer::getInstance()->display('components/datatable.html.twig', [
            'is_tab'             => true,
            'nofilter'           => true,
            'nosort'             => true,
            'columns'            => $columns,
            'formatters'         => $formatters,
            'entries'            => $entries,
            'total_number'       => count($entries),
            'filtered_number'    => count($entries),
            'showmassiveactions' => $canedit,
            'massiveactionparams' => [
                'num_displayed' => count($entries),
                'container'     => 'mass' . str_replace('\\', '_', self::class) . $rand,
            ],
        ]);

        if ($canedit) {
            $label = json_encode(
                _sx('button', 'Deactivate user', 'moregroups'),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
            );
            // Nowdoc: the script is static, the only dynamic value is the JSON-encoded label.
            $script = str_replace('__LABEL__', $label, <<<'JAVASCRIPT'
                $(document).ready(function() {
                    const label = __LABEL__;
                    $("input[name^='item[Group_User]']").each(function() {
                        const match = /^item\[Group_User\]\[(\d+)\]$/.exec($(this).attr('name') || '');
                        if (match === null) {
                            return;
                        }
                        const button = $('<button>', {
                            'type': 'button',
                            'class': 'btn btn-sm btn-primary',
                            'title': label,
                            'data-moregroups-action': 'deactivate',
                            'data-moregroups-rowid': match[1],
                        }).append($('<i>', {'class': 'ti ti-eye-off'}));
                        // Core rows end with an empty cell under the header's "Filter" column: use it,
                        // so the button sits inside the table. Only add a cell if that one is missing.
                        const row = $(this).closest('tr');
                        const cells = row.children('td');
                        const last = cells.last();
                        const columns = row.closest('table').find('thead tr').first().children('th').length;
                        if (cells.length === columns && last.children().length === 0 && last.text().trim() === '') {
                            last.addClass('center').append(button);
                        } else {
                            row.append($('<td>', {'class': 'center'}).append(button));
                        }
                    });
                    $(document).off('click.moregroups').on('click.moregroups', '[data-moregroups-action]', function() {
                        const form = document.getElementById('activateForm');
                        if (form.dataset.submitting === '1') {
                            return;
                        }
                        form.dataset.submitting = '1';
                        $('[data-moregroups-action]').prop('disabled', true);
                        form.rowaction.value = this.dataset.moregroupsAction;
                        form.rowid.value = this.dataset.moregroupsRowid;
                        form.submit();
                    });
                });
                JAVASCRIPT);

            echo Html::scriptBlock($script);
        }

        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;
        echo "<form id='activateForm' action='" . htmlescape($CFG_GLPI['root_doc'] . '/plugins/moregroups/GroupAction') . "' method='post'>";
        echo "<input type='hidden' name='rowaction' value=''>";
        echo "<input type='hidden' name='rowid' value=''>";
        Html::closeForm();
        echo "</div>";
        echo "</div>";
    }

    public static function install(Migration $migration)
    {
        /** @var \DBmysql $DB */
        global $DB;

        $default_charset = DBConnection::getDefaultCharset();
        $default_collation = DBConnection::getDefaultCollation();
        $default_key_sign = DBConnection::getDefaultPrimaryKeySignOption();

        // Itemtype stored in glpi_logs, displaypreferences, ... before the PSR-4 migration (3.0.0)
        $migration->renameItemtype('PluginMoregroupsGroup', self::class, false);

        $table = self::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");
            $query = "CREATE TABLE IF NOT EXISTS $table (
                `id` int {$default_key_sign} NOT NULL auto_increment,
                `users_id` int unsigned NOT NULL DEFAULT '0',
                `groups_id` int unsigned NOT NULL DEFAULT '0',
                `is_dynamic` tinyint NOT NULL DEFAULT '0',
                `is_manager` tinyint NOT NULL DEFAULT '0',
                `is_userdelegate` tinyint NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`users_id`,`groups_id`),
                KEY `groups_id` (`groups_id`),
                KEY `is_dynamic` (`is_dynamic`),
                KEY `is_manager` (`is_manager`),
                KEY `is_userdelegate` (`is_userdelegate`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;";

            // doQuery() throws on failure (GLPI 12), so there is no error branch to handle
            $DB->doQuery($query);
        }
    }

    public static function uninstall(Migration $migration)
    {
        $table = self::getTable();
        $migration->displayMessage("Uninstalling $table");
        $migration->dropTable($table);
    }
}
