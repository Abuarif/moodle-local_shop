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
 * the common base class for all shop objects.
 *
 * @package     local_shop
 * @category    local
 * @author      Valery Fremaux <valery.fremaux@gmail.com>
 * @copyright   Valery Fremaux <valery.fremaux@gmail.com> (MyLearningFactory.com)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_shop;

defined('MOODLE_INTERNAL') || die();

/**
 * A shop object is a generic object that has record in DB
 */
class ShopObject {

    protected static $table;

    protected $record;

    public function __construct($recordorid, $recordtable) {
        global $DB;

        self::$table = $recordtable;

        if (empty($recordorid)) {
            $this->record = new \StdClass;
            $this->record->id = 0;
        } else if (is_numeric($recordorid)) {
            $this->record = $DB->get_record(self::$table, array('id' => $recordorid));
            if (!$this->record) {
                throw new \Exception('Missing record exception in table '.self::$table." for ID $recordorid ");
            }
        } else {
            $this->record = $recordorid;
        }
    }

    /**
     * magic getter
     * @param string $field
     */
    public function __get($field) {

        // Return raw record.
        if ($field == 'record') {
            return $this->record;
        }

        // Object field value will always prepend on deeper representation.
        if (isset($this->$field)) {
            return $this->$field;
        }

        if (isset($this->record->$field)) {
            return $this->record->$field;
        }
    }

    /**
     * magic setter. This allows not polluting DB records with ton
     * of irrelevant members
     * @param string $field
     * @param mixed $value
     */
    public function __set($field, $value) {

        if (empty($this->record)) {
            throw new \Exception("empty object");
        }

        if (property_exists($this->record, $field)) {
            if (method_exists($this, '_magic_set_'.$field)) {
                $fname = '_magic_set_'.$field;
                return $this->$fname($value);
            }
            $this->record->$field = $value;
        } else {
            $this->$field = $value;
        }

        return true;
    }

    /**
     * generic saving
     */
    public function save() {
        global $DB;

        $class = get_called_class();

        if (empty($this->record->id)) {
            $this->record->id = $DB->insert_record($class::$table, $this->record);
        } else {
            $DB->update_record($class::$table, $this->record);
        }
        return $this->record->id;
    }

    public function delete() {
        global $DB;

        $class = get_called_class();

        // Finally delete record.
        $DB->delete_records($class::$table, array('id' => $this->id));
    }

    static protected function _count($table, $filter = array()) {
        global $DB;

        return $DB->count_records($table, $filter);
    }

    /**
     * Get instances of the object. If some filtering is needed, override
     * this method providing a filter as input.
     * @param array $filter an array of specialized field filters
     * @return array of object instances keyed by primary id.
     */
    static protected function _get_instances($table, $filter = array(), $order = '',
                                             $fields = '*', $limitfrom = 0, $limitnum = '') {
        global $DB;

        $records = $DB->get_records($table, $filter, $order, $fields, $limitfrom, $limitnum);
        $instances = array();
        if ($records) {
            $class = get_called_class();
            foreach ($records as $rec) {
                $instances[$rec->id] = new $class($rec);
            }
        }

        return $instances;
    }

    /**
     * Get instances of the object. If some filtering is needed, override
     * this method providing a filter as input.
     * @param array $filter an array of specialized field filters
     * @return array of object instances keyed by primary id.
     */
    static protected function _count_instances($table, $filter = array(), $order = '', $fields = '*',
                                               $limitfrom = 0, $limitnum = '') {
        global $DB;

        $recordscount = $DB->count_records($table, $filter, $order, $fields, $limitfrom, $limitnum);

        return $recordscount;
    }

    /**
     * @param array $filter
     * @param string $field
     * @param boolean $choosenone
     */
    public static function get_instances_menu($filter = array(), $field = 'name', $choosenone = false) {

        $class = get_called_class();
        $instances = $class::get_instances($filter, $field, 'id, '.$field);

        if ($choosenone) {
            $instancemenu = array();
        } else {
            $instancemenu = array(0 => get_string('choosedots'));
        }
        if ($instances) {
            foreach ($instances as $i) {
                $instancemenu[$i->id] = format_string($i->$field);
            }
        }
        return $instancemenu;
    }

    protected function export($level = 0) {

        $indent = str_repeat('    ', $level);

        $yml = '';
        if (!empty($this->record)) {
            foreach ($this->record as $key => $value) {
                $yml .= $indent.$key.': '.$value."\n";
            }
        }

        return $yml;
    }
}