<?php

class LogOutput {

    private $db;

    ///////////////////////////////////////////////////////////////////////////
    // CONSTRUCTORS

    public static function with_db($db) {
        $instance = new LogOutput();
        $instance->set_database($db);
        return $instance;
    }

    ///////////////////////////////////////////////////////////////////////////
    // MAIN METHODS

    public function as_html()
    {
        $diff_data = $this->get_diff_data();
        $s  = "<table>";

        // Headers
        $s .= "<tr>";
        $s .= "    <th>" . _('Time')           . "</th>";
        $s .= "    <th>" . _('Event')          . "</th>";
        $s .= "    <th>" . _('User')           . "</th>";
        $s .= "    <th>" . _('Approving user') . "</th>";
        $s .= "    <th>" . _('Zone')           . "</th>";
        $s .= "    <th>" . _('Record name')    . "</th>";
        $s .= "    <th>" . _('Type')           . "</th>";
        $s .= "    <th>" . _('Content')        . "</th>";
        $s .= "    <th>" . _('Priority')       . "</th>";
        $s .= "    <th>" . _('TTL')            . "</th>";
        $s .= "    <th>" . _('Change date')    . "</th>";
        $s .= "</tr>";

        // Data
        while($diff = $diff_data->fetchRow()) {
            $this->merge_domain_name($diff);
            $indent = "    ";

            switch($diff['event']) {

                // Will print row without rowspan
                case 'record_create':
                    // Render only the created ('after' is interesting)
                    $s .= $indent . $this->create_diff_row($diff);
                    break;

                // Will print row without rowspan
                case 'record_delete':
                    // Render only the deleted ('prior' is interesting)
                    $s .= $indent . $this->delete_diff_row($diff);
                    break;

                // Will print row with rowspan
                case 'record_edit':
                    $s .= $indent . $this->edit_diff_row($diff);
                    break;

                default:
                    break;
            }
        }
        $s .= "</table>";
        return $s;
    }

    private function create_diff_row($diff) {
        $prefix = 'after'; // After a create, only what is there after the create is interesting.
        $class = "record-create";
        return $this->diff_row($diff, $prefix, $class);
    }

    private function delete_diff_row($diff) {
        $prefix = 'prior';  // After a delete, only what was there previous to the delete is interesting.
        $class = "record-delete";
        return $this->diff_row($diff, $prefix, $class);
    }

    private function diff_row($diff, $prefix, $class) {
        $rowspan = 0;

        $s  = "<tr class=\"$class\">";
        $s .= $this->add_diff_meta($diff, $rowspan);
        $s .= $this->add_diff_data($diff, $prefix);
        $s .= "</tr>";
        return $s;
    }

    private function edit_diff_row($diff) {
        $rowspan = 2;
        $colorize_edit = true;

        // Part with rowspan (meta + data)
        $s = "<tr class=\"record-edit\">";
        $s .= $this->add_diff_meta($diff, $rowspan);
        $s .= $this->add_diff_data($diff, 'prior', $colorize_edit);
        $s .= "</tr>";

        // Part without (only data)
        $s .= "<tr class=\"record-edit\">";
        $s .= $this->add_diff_data($diff, 'after', $colorize_edit);
        $s .= "</tr>";

        return $s;
    }

    ///////////////////////////////////////////////////////////////////////////
    // HELPER METHODS

    private function get_diff_data() {
        $sql = "SELECT
                    timestamp                     as time,
                    record_type.name              as event,
                    record.user,
                    record.user_approve,
                    domain_prior.name as domain_prior,
                    domain_after.name as domain_after,

                    record_data_prior.name        as prior_record_name,
                    record_data_prior.type        as prior_record_type,
                    record_data_prior.content     as prior_record_content,
                    record_data_prior.ttl         as prior_record_ttl,
                    record_data_prior.prio        as prior_record_prio,
                    record_data_prior.change_date as prior_record_change_date,

                    record_data_after.name        as after_record_name,
                    record_data_after.type        as after_record_type,
                    record_data_after.content     as after_record_content,
                    record_data_after.ttl         as after_record_ttl,
                    record_data_after.prio        as after_record_prio,
                    record_data_after.change_date as after_record_change_date
                FROM
                    log_records      record
                INNER JOIN
                    log_records_type record_type       ON record.log_records_type_id  = record_type.id
                LEFT JOIN
                    log_records_data record_data_prior ON record.prior                = record_data_prior.id
                LEFT JOIN
                    log_records_data record_data_after ON record.after                = record_data_after.id
                LEFT JOIN
                    domains          domain_prior      ON record_data_prior.domain_id = domain_prior.id
                LEFT JOIN
                    domains          domain_after      ON record_data_after.domain_id = domain_after.id
                ORDER BY
                    timestamp DESC;";
        return $this->db->query($sql);
    }

    private function set_database($db) {
        $this->db = $db;
    }

    private function merge_domain_name(&$diff) {
        $diff['domain'] = $diff['domain_prior'] ? $diff['domain_prior'] : $diff['domain_after'];
        unset($diff['domain_prior']);
        unset($diff['domain_after']);
    }

    private function add_diff_meta($diff, $rowspan) {
        $s = "";
        $rowspan_attr = $rowspan > 0 ? " rowspan=\"$rowspan\"" : "";
        $fields = array('time', 'event', 'user', 'approving_user', 'domain');

        foreach($fields as $field) {
            $data = $diff[$field];

            // Special cases
            if($field == 'approving_user')
            {
                // If the approving user is null, print a '-'. Else the name of the user.
                $data = $data ? $data : "-";
            }
            $s .= "<td" . $rowspan_attr . ">" . $data . "</td>";
        }
        return $s;
    }

    private function add_diff_data($diff, $prefix, $colorize_edit = false) {
        $s = "";
        $fields = array(
            'record_name',
            'record_type',
            'record_content',
            'record_prio',
            'record_ttl',
            'record_change_date'
        );

        foreach($fields as $field) {
            $data = $diff[$prefix . "_" . $field];
            $colorize_cell = false;

            // Try to colorize when we are supposed to do it
            if($colorize_edit) {
                // Select the other data
                $other_prefix = $prefix == 'prior' ? 'after' : 'prior';
                $other_data = $diff[$other_prefix . "_" . $field];

                // If we have changes, make it colorful!
                $colorize_cell = ($data !== $other_data);
            }

            if($colorize_cell) {
                $s .= "<td class=\"record-edit-cell\">" . $data . "</td>";
            } else {
                $s .= "<td>" . $data . "</td>";
            }
        }
        return $s;
    }
}
