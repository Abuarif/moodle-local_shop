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

defined('MOODLE_INTERNAL') || die();

/**
 * @package   local_shop
 * @category  local
 * @subpackage shophandlers
 * @author    Valery Fremaux (valery.fremaux@gmail.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * STD_CREATE_VINSTANCE is a standard shop product action handler that can deply a full Virtualized
 * Moodle instance in the domaine scope.
 */
require_once($CFG->dirroot.'/local/shop/datahandling/shophandler.class.php');
require_once($CFG->dirroot.'/local/shop/datahandling/handlercommonlib.php');
require_once($CFG->dirroot.'/local/shop/classes/Product.class.php');
require_once($CFG->dirroot.'/local/shop/classes/Shop.class.php');

Use local_shop\Product;
Use local_shop\Shop;

class shop_handler_std_createvinstance extends shop_handler {

    function __construct($label) {
        $this->name = 'std_createvinstance'; // for unit test reporting
        parent::__construct($label);
    }

    function validate_required_data($itemname, $fieldname, $instance, $value, &$errors) {
        global $DB;

        if ($fieldname == 'shortname') {
            if ($DB->record_exists('block_vmoodle', array('shortname' => $value)) {
                $errors[$itemname][$fieldname][$instance] = get_string('errorhostnameexists', 'shophanlders_createvinstance', $value);
                return false;
            }
        }
        return true;
    }

    function produce_prepay(&$data) {
        global $CFG, $DB, $USER;

        $productionfeedback = new StdClass();

        // Get customersupportcourse designated by handler internal params
        if (!isset($data->actionparams['customersupport'])) {
            $theShop = new Shop($data->shopid);
            $data->actionparams['customersupport'] = 0 + @$theShop->defaultcustomersupportcourse;
        }

        $customer = $DB->get_record('local_shop_customer', array('id' => $data->get_customerid()));
        if (isloggedin()) {
            if ($customer->hasaccount != $USER->id) {
                // do it quick in this case. Actual user could authentify, so it is the legitimate account.
                // We guess if different non null id that the customer is using a new account. This should not really be possible
                $customer->hasaccount = $USER->id;
                $DB->update_record('local_shop_customer', $customer);
            } else {
                $productionfeedback->public = get_string('knownaccount', 'local_shop', $USER->username);
                $productionfeedback->private = get_string('knownaccount', 'local_shop', $USER->username);
                $productionfeedback->salesadmin = get_string('knownaccount', 'local_shop', $USER->username);
                shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Prepay : Known account {$USER->username} at process entry.");
                return $productionfeedback;
            }
        } else {
            // In this case we can have a early Customer that never confirmed a product or a brand new Customer comming in.
            // The Customer might match with an existing user... 
            // TODO : If a collision is to be detected, a question should be asked to the customer.
    
            // Create Moodle User but no assignation
            if (!shop_create_customer_user($data, $customer, $newuser)) {
                shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Prepay Error : User could not be created {$newuser->username}.");
                $productionfeedback->public = get_string('customeraccounterror', 'local_shop', $newuser->username);
                $productionfeedback->private = get_string('customeraccounterror', 'local_shop', $newuser->username);
                $productionfeedback->salesadmin = get_string('customeraccounterror', 'local_shop', $newuser->username);
                return $productionfeedback;
            }

            $productionfeedback->public = get_string('productiondata_public', 'shophandlers_std_createvinstance');
            $a->username = $newuser->username;
            $a->password = $customer->password;
            $productionfeedback->private = get_string('productiondata_private', 'shophandlers_std_createvinstance', $a);
            $productionfeedback->salesadmin = get_string('productiondata_sales', 'shophandlers_std_createvinstance', $newuser->username);
        }

        return $productionfeedback;
    }

    function produce_postpay(&$data) {
        global $CFG, $DB;

        $productionfeedback = new StdClass();

        if (!isset($data->customerdata['shortname'])) {
            shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay Error : No shortname defined");
            $preprocesserror = 1;
        }

        if (!isset($data->customerdata['name'])) {
            shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay Error : No name defined");
            $preprocesserror = 1;
        }

        if (!isset($data->actionparams['template'])) {
            shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay Error : No template name defined");
            $preprocesserror = 1;
        }

        if (is_dir($CFG->dirroot.'/local/vmoodle')) {
            require_once($CFG->dirroot.'/local/vmoodle/locallib.php');

            if (!vmoodle_exist_template($data->actionparams['template'])) {
                shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay Error : Template not available");
                $preprocesserror = 1;
            }
        } else {
            shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay Error : VMoodle not installed");
            $preprocesserror = 1;
        }

        if (!$preprocesserror) {
            $domain = @$data->actionparams['domain'];
            if (empty($domain)) {
                // Use same domain than current.
                $parts = explode('.', $_SERVER['SERVER_NAME']);
                array_shift($parts);
                $domain = implode('.', $parts);
            }

            $dbuser = @$data->actionparams['dbuser'];
            if (empty($dbuser)) {
                $dbuser = $CFG->dbuser;
            }

            $dbpass = @$data->actionparams['dbpass'];
            if (empty($dbpass)) {
                $dbpass = $CFG->dbpass;
            }

            $dbhost = @$data->actionparams['dbhost'];
            if (empty($dbhost)) {
                $dbhost = $CFG->dbhost;
            }

            $dbtype = @$data->actionparams['dbtype'];
            if (empty($dbtype)) {
                $dbtype = $CFG->dbtype;
            }

            $data->required['shortname'] = $this->clean_hostname($data->customerdata['shortname']);

            $SESSION->vmoodledata['vhostname'] = $data->customerdata['shortname'].'.'.$domain;
            $SESSION->vmoodledata['name'] = $data->actionparams['name'];
            $SESSION->vmoodledata['shortname'] = $data->actionparams['shortname'];
            $SESSION->vmoodledata['description'] = '';
            $SESSION->vmoodledata['vdbtype'] = $dbtype;
            $SESSION->vmoodledata['vdbname'] = 'vmdl_'.$data->actionparams['vhostname'];
            $SESSION->vmoodledata['vdblogin'] = $dbuser;
            $SESSION->vmoodledata['vdbpass'] = $dbpass;
            $SESSION->vmoodledata['vdbhost'] = $dbhost;
            $SESSION->vmoodledata['vdbpersist'] = 0 + @$CFG->block_vmoodle_dbpersist;
            $SESSION->vmoodledata['vdbprefix'] = ($CFG->block_vmoodle_dbprefix) ? $CFG->block_vmoodle_dbprefix : 'mdl_';
            $SESSION->vmoodledata['vtemplate'] = $data->handlerparams['template'];
            $SESSION->vmoodledata['vdatapath'] = dirname($CFG->dataroot).'/'.$data->actionparams['vhostname'];
            $SESSION->vmoodledata['mnet'] = 'NEW';

            $action = 'doadd';
            $automation = true;

            for ($step = 0 ; $step <= 4; $step++) {
                include $CFG->dirroot.'/local/vmoodle/controller.management.php';
            }

            // Now setup local "manager" account.

            $VDB = vmoodle_setup_DB($n);

            // special fix for deployed networks :
            // Fix the master node name in mnet_host
            // We need overseed the issue of loosing the name of the master node in the deploied instance
            // TODO : this is a turnaround quick fix.
            if ($remote_vhost = $VDB->get_record('mnet_host', array('wwwroot' => $CFG->wwwroot))) {
                global $SITE;
                $remote_vhost->name = $SITE->fullname;
                $VDB->update_record('mnet_host', $remote_vhost, 'id');
            }

            // Setup the customer as manager account
            $customer = $DB->get_record('local_shop_customer', array('id' => $data->get_customerid()));
            $manager = new StdClass();
            $manager->firstname = 'Manager';
            $manager->lastname = 'Site';
            $manager->username = 'manager';
            $password = generate_password(8);
            $manager->password = md5($password);
            $manager->confirmed = 1;
            $manager->city = strtoupper($customer->city);
            $manager->country = strtoupper($customer->country);
            $manager->lang = strtoupper($customer->country);
            $manager->email = $customer->email;
            $VDB->insert_record($manager);

            // TODO : Enrol him as manager at system level

            $productionfeedback->public = get_string('productiondata_delivered_public', 'shophandlers_std_createvinstance', '');
            $productionfeedback->private = get_string('productiondata_delivered_private', 'shophandlers_std_createvinstance', '');
            $productionfeedback->salesadmin = get_string('productiondata_delivered_sales', 'shophandlers_std_createvinstance', '');
        } else {
            // vmoodle not installed
            $productionfeedback->public = get_string('productiondata_failure_public', 'shophandlers_std_createvinstance', 'Code : CATEGORY CREATION');
            $productionfeedback->private = get_string('productiondata_failure_private', 'shophandlers_std_createvinstance', $data);
            $productionfeedback->salesadmin = get_string('productiondata_failure_sales', 'shophandlers_std_createvinstance', $data);
            shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay Error : Training credits not installed.");
            return $productionfeedback;
        }

        // Add user to customer support on real purchase
        if (!empty($data->actionparams['customersupport'])) {
            shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay : Registering Customer Support");
            shop_register_customer_support($data->actionparams['customersupport'], $customeruser, $data->transactionid);
        }

        shop_trace("[{$data->transactionid}] STD_CREATE_VINSTANCE Postpay : Complete.");
        return $productionfeedback;
    } 

    function unit_test($data, &$errors, &$warnings, &$messages) {

        $messages[$data->code][] = get_string('usinghandler', 'local_shop', $this->name);

        parent::unit_test($data, $errors, $warnings, $messages);

        if (!isset($data->actionparams['name'])) {
            $errors[$data->code][] = get_string('errornoname', 'shophandlers_std_createvinstance');
        }

        if (!isset($data->actionparams['shortname'])) {
            $errors[$data->code][] = get_string('errornoshortname', 'shophandlers_std_createvinstance');
        }

        if (!isset($data->actionparams['vhostname'])) {
            $errors[$data->code][] = get_string('errornohostname', 'shophandlers_std_createvinstance');
        } else {

            if (strlen($data->actionparams['vhostname']) > 25) {
                $errors[$data->code][] = get_string('errortoolong', 'shophandlers_std_createvinstance');
            }
    
            if (preg_match('/\\./', $data->actionparams['vhostname'])) {
                $errors[$data->code][] = get_string('errorbadtoken', 'shophandlers_std_createvinstance');
            }
        }

        if (empty($data->handlerparams['template'])) {
            $errors[$data->code][] = get_string('errornotemplate', 'shophandlers_std_createvinstance');
        }

        if (empty($data->handlerparams['dbuser'])) {
            $warnings[$data->code][] = get_string('warningemptydbuser', 'shophandlers_std_createvinstance');
        }

        if (empty($data->handlerparams['dbpass'])) {
            $warnings[$data->code][] = get_string('warningemptydbpass', 'shophandlers_std_createvinstance');
        }

        if (empty($data->handlerparams['dbtype'])) {
            $warnings[$data->code][] = get_string('warningemptydbtype', 'shophandlers_std_createvinstance');
        }

        if (empty($data->handlerparams['dbhost'])) {
            $warnings[$data->code][] = get_string('warningemptydbhost', 'shophandlers_std_createvinstance');
        }

    }

    protected function clean_hostname($str) {
        $str = str_replace(' ', '-', $str);

        return $str;
    }
}