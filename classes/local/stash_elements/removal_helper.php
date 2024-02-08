<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Removal of items element helper
 *
 * @package    block_stash\local\stash_elements
 * @copyright  2023 Adrian Greeve <adriangreeve.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_stash\local\stash_elements;

use block_stash\drop_pickup;

class removal_helper {

    private $manager;

    public function __construct($manager) {
        $this->manager = $manager;
    }

    public function handle_form_data($data) {
        global $DB;

        $dbdata = [
            'stashid' => $this->manager->get_stash()->get_id(),
            'modulename' => 'quiz', // Need to update for other modules.
            'cmid' => $data->quizcmid,
            'detail' => $data->detail_editor['text'],
            'detailformat' => $data->detail_editor['format']
        ];
        $removalid = $DB->insert_record('block_stash_removal', $dbdata);
        foreach ($data->items as $item) {
            $dbdata = [
                'removalid' => $removalid,
                'itemid' => $item['itemid'],
                'quantity' => $item['quantity']
            ];
            $DB->insert_record('block_stash_remove_items', $dbdata);
        }
    }

    public function get_all_removals() {
        global $DB;

        return $DB->get_records('block_stash_removal', ['stashid' => $this->manager->get_stash()->get_id()]);
    }

    public function get_the_full_whammy() {
        global $DB;

        $sql = "SELECT ri.id, r.stashid, r.cmid, r.id as removalid, ri.itemid, i.name, ri.quantity
                  FROM {block_stash_remove_items} ri
                  JOIN {block_stash_removal} r ON r.id = ri.removalid
                  JOIN {block_stash_items} i ON i.id = ri.itemid
                 WHERE r.stashid = :stashid";
        return $DB->get_records_sql($sql, ['stashid' => $this->manager->get_stash()->get_id()]);

    }

    public function get_removal_details($cmid) {
        global $DB;

        $sql = "SELECT ri.id, r.stashid, r.cmid, r.id as removalid, ri.itemid, ri.quantity
                  FROM {block_stash_remove_items} ri
                  JOIN {block_stash_removal} r ON r.id = ri.removalid
                 WHERE r.cmid = :cmid
                   AND r.stashid = :stashid";

        return $DB->get_records_sql($sql, ['cmid' => $cmid, 'stashid' => $this->manager->get_stash()->get_id()]);
    }

    public function can_user_lose_removal_items($removals, $userid) {
        foreach ($removals as $removal) {
            if (!$this->manager->user_has_item_to_trade($removal->itemid, $removal->quantity, $userid)) {
                return false;
            }
        }
        return true;
    }

    public function remove_user_item($removal, $userid) {
        global $DB;

        // Is the item a scarce resource? If so it needs to be made available to everyone again.
        $item = $this->manager->get_item($removal->itemid);
        $useritem = $this->manager->get_user_item($userid, $removal->itemid);
        $removalquantity = $useritem->get_quantity();
        $itemlimit = $item->get_amountlimit();
        if ($itemlimit) {
            $currentamount = $item->get_currentamount();
            $maxamount = $itemlimit - $currentamount;
            if ($maxamount == 0) {
                // The item is already at the max amount, so we can't add any more.
            } else  if ($removalquantity > $maxamount) {
                $item->set_currentamount($maxamount);
            } else {
                $item->set_currentamount($currentamount + $removalquantity);
            }
            $item->update();
        }
        // The user needs the ability to pick the scarce item back up again.
        // For this the drop pickups need to have their pickup count updated, even though the item could have been acquired in
        // a different way (such as a trade, or the teacher manually giving them one).
        // Not that the drop pickup entry updates the pickup count and lastpickup (not a new entry)
        $sql = "SELECT p.*
                  FROM {block_stash_drop_pickups} p
                  JOIN {block_stash_drops} d ON p.dropid = d.id
                  JOIN {block_stash_items} i ON d.itemid = i.id
                 WHERE i.id = :itemid AND p.userid = :userid";
        $params = ['itemid' => $removal->itemid, 'userid' => $userid];
        $records = $DB->get_records_sql($sql, $params);
        $workingquantity = $removal->quantity;
        foreach ($records as $record) {
            $dp = new drop_pickup($record->id, $record);
            $pickupcount = $dp->get_pickupcount();
            if ($workingquantity <= $pickupcount) {
                $dp->set_pickupcount($pickupcount - $workingquantity);
                $dp->update();
                break;
            } else {
                $workingquantity = $workingquantity - $pickupcount;
                $dp->set_pickupcount(0);
                $dp->update();
            }
        }

        if ($useritem->get_quantity() <= $removal->quantity) {
            // Remove this entry.
            $useritem->delete();
        } else {
            $useritem->set_quantity($useritem->get_quantity() - $removal->quantity);
            $useritem->update();
        }
    }

    public function get_quizzes_for_course() {
        $courseid = $this->manager->get_courseid();
        $course = get_course($courseid);
        $courseinstances = get_all_instances_in_course("quiz", $course);
        $tmep = [];
        foreach($courseinstances as $instance) {
            $tmep[$instance->coursemodule] = $instance->name;
        }
        // print_object($courseinstances);
        return $tmep;
    }
}
