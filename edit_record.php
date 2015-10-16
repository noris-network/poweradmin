<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Script that handles requests to edit zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */
require_once("inc/toolkit.inc.php");
include_once("inc/header.inc.php");
include_once("inc/RecordLog.class.php");
require_once("inc/RfcPermissions.class.php");
include_once("inc/Rfc.class.php");

global $pdnssec_use;

if (do_hook('verify_permission', 'zone_content_view_others')) {
    $perm_view = "all";
} elseif (do_hook('verify_permission', 'zone_content_view_own')) {
    $perm_view = "own";
} else {
    $perm_view = "none";
}

if (do_hook('verify_permission', 'zone_content_edit_others')) {
    $perm_content_edit = "all";
} elseif (do_hook('verify_permission', 'zone_content_edit_own')) {
    $perm_content_edit = "own";
} elseif (do_hook('verify_permission', 'zone_content_edit_own_as_client')) {
    $perm_content_edit = "own_as_client";
} else {
    $perm_content_edit = "none";
}

if (do_hook('verify_permission', 'zone_meta_edit_others')) {
    $perm_meta_edit = "all";
} elseif (do_hook('verify_permission', 'zone_meta_edit_own')) {
    $perm_meta_edit = "own";
} else {
    $perm_meta_edit = "none";
}

$zid = get_zone_id_from_record_id($_GET['id']);

$user_is_zone_owner = do_hook('verify_user_is_owner_zoneid' , $zid );
$zone_type = get_domain_type($zid);
$zone_name = get_zone_name_from_id($zid);

$is_rfc_commit = false;
if(isset($_POST['rfc_commit'])) {
    $is_rfc_commit = true;
}

// This might be a RFC that gets accepted
$user_approve = null;
if(isset($_POST['rfc_user_approve'])) {
    $user_approve = $_POST['rfc_user_approve'];
}

if(isset($_POST['create_rfc'])) {
    if (RfcPermissions::can_create_rfc($zid)) {
        $log = new RecordLog();
        $rid = $_GET['id'];
        global $db;

        $log->log_prior($rid);

        $before = new Record(get_record_from_id($rid));
        $after = RecordBuilder::make($rid, $zid, $_POST['name'], $_POST['type'], $_POST['content'], $_POST['prio'], $_POST['ttl'], time());

        if (!$log->has_changed($after->as_array(), true)) {
            success(SUC_ZONE_NOCHANGE);
        } else {
            $rfc = RfcBuilder::make()->now()->myself()->build();
            $serial = get_serial_by_zid($before->getZone());

            $rfc->add_change($zid, $serial, $rid, $before, $after);
            $rfc->write($db);
            success(SUC_RFC_CREATED);
        }
    } else {
        error(ERR_RFC_PERMISSIONS);
    }
}

if (isset($_POST["commit"])) {
    $do_rfc_commit = $is_rfc_commit && RfcPermissions::can_commit_rfcs();

    if (!($do_rfc_commit) && ($zone_type == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" || $perm_content_edit == "own_as_client") && $user_is_zone_owner == "0")) {
        error(ERR_PERM_EDIT_RECORD);
    } else {
        // Only update if necessary
        $log = new RecordLog($zid);
        $log->log_prior($_POST["rid"]);
        if(!$log->has_changed($_POST, true)) {
            success(SUC_ZONE_NOCHANGE);
        } else {
            $old_record_info = get_record_from_id($_POST["rid"]);
            $ret_val = edit_record($_POST);
            if ($ret_val == "1") {
                if ($_POST['type'] != "SOA") {
                    update_soa_serial($zid);
                }
                success(SUC_RECORD_UPD);

                $log->log_after($_POST["rid"]);
                $log->writeChange();

                $new_record_info = get_record_from_id($_POST["rid"]);
                log_info(sprintf('client_ip:%s user:%s operation:edit_record'
                    . ' old_record_type:%s old_record:%s old_content:%s old_ttl:%s old_priority:%s'
                    . ' record_type:%s record:%s content:%s ttl:%s priority:%s',
                    $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                    $old_record_info['type'], $old_record_info['name'], $old_record_info['content'], $old_record_info['ttl'], $old_record_info['prio'],
                    $new_record_info['type'], $new_record_info['name'], $new_record_info['content'], $new_record_info['ttl'], $new_record_info['prio']));

                if ($pdnssec_use) {
                    if (dnssec_rectify_zone($zid)) {
                        success(SUC_EXEC_PDNSSEC_RECTIFY_ZONE);
                    }
                }
            }
        }
    }
}

echo "    <h2>" . _('Edit record in zone') . " \"<a href=\"edit.php?id=" . $zid . "\">" . $zone_name . "</a>\"</h2>\n";

if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
    error(ERR_PERM_VIEW_RECORD);
} else {
    $record = get_record_from_id($_GET["id"]);
    echo "     <form method=\"post\" action=\"edit_record.php?domain=" . $zid . "&amp;id=" . $_GET["id"] . "\">\n";
    echo "      <table>\n";
    echo "       <tr>\n";
    echo "        <th>" . _('Name') . "</th>\n";
    echo "        <th>&nbsp;</th>\n";
    echo "        <th>" . _('Type') . "</th>\n";
    echo "        <th>" . _('Content') . "</th>\n";
    echo "        <th>" . _('Priority') . "</th>\n";
    echo "        <th>" . _('TTL') . "</th>\n";
    echo "       </tr>\n";

    /*
      Sanitize content due to SPF record quoting in PowerDNS
     */
    if ($record['type'] == "SRV" || $record['type'] == "SPF" || $record['type'] == "TXT") {
        $clean_content = trim($record['content'], "\x22\x27");
    } else {
        $clean_content = $record['content'];
    }

    $edit_rfc = RfcPermissions::can_create_rfc($zid);
    $read_only = $zone_type == "SLAVE" || $perm_content_edit == "none" || ($perm_content_edit == "own" || $perm_content_edit == "own_as_client") && $user_is_zone_owner == "0";

    if ($read_only && !$edit_rfc) {
        echo "      <tr>\n";
        echo "       <td>" . $record["name"] . "</td>\n";
        echo "       <td>IN</td>\n";
        echo "       <td>" . htmlspecialchars($record["type"]) . "</td>\n";
        echo "       <td>" . htmlspecialchars($clean_content) . "</td>\n";
        echo "       <td>" . htmlspecialchars($record["prio"]) . "</td>\n";
        echo "       <td>" . htmlspecialchars($record["ttl"]) . "</td>\n";
        echo "      </tr>\n";
    } else {
        echo "      <tr>\n";
        echo "       <td><input type=\"hidden\" name=\"rid\" value=\"" . $_GET["id"] . "\">\n";
        echo "       <input type=\"hidden\" name=\"zid\" value=\"" . $zid . "\">\n";
        echo "       <input type=\"text\" name=\"name\" value=\"" . htmlspecialchars(trim(str_replace($zone_name, '', $record["name"]), '.')) . "\" class=\"input\">." . $zone_name . "</td>\n";
        echo "       <td>IN</td>\n";
        echo "       <td>\n";
        echo "        <select name=\"type\">\n";
        $found_selected_type = false;
        foreach (get_record_types() as $type_available) {
            if ($type_available == $record["type"]) {
                $add = " SELECTED";
                $found_selected_type = true;
            } else {
                $add = "";
            }
            echo "         <option" . $add . " value=\"" . htmlspecialchars($type_available) . "\" >" . $type_available . "</option>\n";
        }
        if (!$found_selected_type)
            echo "         <option SELECTED value=\"" . htmlspecialchars($record['type']) . "\"><i>" . $record['type'] . "</i></option>\n";
        echo "        </select>\n";
        echo "       </td>\n";
        echo "       <td><input type=\"text\" name=\"content\" value=\"" . htmlspecialchars($clean_content) . "\" class=\"input\"></td>\n";
        echo "       <td><input type=\"text\" name=\"prio\" value=\"" . htmlspecialchars($record["prio"]) . "\" class=\"sinput\"></td>\n";
        echo "       <td><input type=\"text\" name=\"ttl\" value=\"" . htmlspecialchars($record["ttl"]) . "\" class=\"sinput\"></td>\n";
        echo "      </tr>\n";
    }
    echo "      </table>\n";

    // Show only if I am authorized
    if (RfcPermissions::can_create_rfc($zid)) {
        echo '<input type="submit" class="button" name="create_rfc" value="' . _('Create RFC') . '">';
    }

    // Show only if I am authorized
    if(RfcPermissions::can_edit_zone($zid)) {
        echo '<input type="submit" class="button" name="commit" value="' . _('Commit changes') . '">';
    }


    echo '<input type="reset" name="reset" value="' . _('Reset changes') . '" class="button">';
    echo '</form>';
}


include_once("inc/footer.inc.php");
