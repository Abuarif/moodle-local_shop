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
 * @package   local_shop
 * @category  local
 * @author    Valery Fremaux (valery.fremaux@gmail.com)
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_shop\front;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/local/shop/front/front.controller.php');
require_once($CFG->dirroot.'/auth/ticket/lib.php');
require_once($CFG->dirroot.'/local/shop/datahandling/production.php');
require_once($CFG->dirroot.'/local/shop/mailtemplatelib.php');

class production_controller extends front_controller_base {

    protected $ipncall;
    public $interactive;
    protected $abill;

    public function __construct(&$afullbill, $ipncall = false, $interactive = false) {
        $this->abill = $afullbill;
        $this->ipncall = $ipncall;
        $this->interactive = $interactive;
    }

    public function process($cmd, $holding = false) {
        global $SESSION, $DB, $CFG, $SITE, $OUTPUT;

        $config = get_config('local_shop');

        if ($cmd == 'navigate') {
            // No back possible after production.
            $next = $this->abill->theshop->get_next_step('produce');
            $params = array('view' => $next,
                            'shopid' => $this->abill->theshop->id,
                            'blockid' => 0 + @$this->abill->theblock->id,
                            'transid' => $this->abill->transactionid);
            $url = new \moodle_url('/local/shop/front/view.php', $params);
            if (empty($SESSION->shoppingcart->debug)) {
                redirect($url);
            } else {
                echo $OUTPUT->continue_button($url);
                die;
            }
        }

        $systemcontext = \context_system::instance();

        // Simpler to handle in code.
        $afullbill = $this->abill;

        // Trap any non defined command here (increase security).
        if ($cmd != 'produce' && $cmd != 'confirm') {
            return;
        }

        /*
         * Payment is overriden if :
         * - this is a free order (paymode has been detected as freeorder)
         * - the user is logged in and has special payment override capability
         */
        $paycheckoverride = (isloggedin() && has_capability('local/shop:paycheckoverride', $systemcontext)) ||
                ($afullbill->paymode == 'freeorder');
        $overriding = false;

        if ($cmd == 'confirm') {

            if ($paycheckoverride) {
                // Bump to next case.
                $cmd = 'produce';
                $overriding = true;
            } else {

                /*
                 * A more direct resolution when paiement is not performed online
                 * we can perform pre_pay operations
                 */
                shop_trace("[{$afullbill->transactionid}] ".'Order confirm (offline payments, bill is expected to be PENDING)');
                shop_trace("[{$afullbill->transactionid}] ".'Production starting ...');
                shop_trace("[{$afullbill->transactionid}] ".'Production Controller : Pre Pay process');

                if ($this->interactive && $this->ipncall) {
                    mtrace("[{$afullbill->transactionid}] ".'Order confirm (offline payments, bill is expected to be PENDING)');
                    mtrace("[{$afullbill->transactionid}] ".'Production starting ...');
                    mtrace("[{$afullbill->transactionid}] ".'Production Controller : Pre Pay process');
                }

                if ($this->interactive && $this->ipncall) {
                    mtrace("[{$afullbill->transactionid}] ".'Production Controller : Pre Pay process');
                }
                $productionfeedback = produce_prepay($afullbill);
                /*
                 * log new production data into bill record
                 * the first producing procedure stores production data.
                 * if interactive shopback process comes later, we just have production
                 * data to display to user.
                 */
                shop_aggregate_production($afullbill, $productionfeedback, $this->interactive);
                // All has been finished.
                unset($SESSION->shoppingcart);
            }
        }
        if ($cmd == 'produce') {

            // Start production.
            $message = "[{$afullbill->transactionid}] Production Controller :";
            $message .= " Full production starting from {$afullbill->status} ...";
            shop_trace($message);
            if ($this->interactive && $this->ipncall) {
                $message = "[{$afullbill->transactionid}] Production Controller :";
                $message .= " Full production starting from {$afullbill->status} ...";
                mtrace($message);
            }

            if ($afullbill->status == SHOP_BILL_PENDING || $afullbill->status == SHOP_BILL_SOLDOUT || $overriding) {
                /*
                 * when using the controller to finish a started production, do not
                 * preproduce again (paypal IPN finalization)
                 */
                if ($this->interactive && $this->ipncall) {
                    mtrace("[{$afullbill->transactionid}] ".'Production Controller : Pre Pay process');
                }
                $productionfeedback = produce_prepay($afullbill);

                if ($afullbill->status == SHOP_BILL_SOLDOUT || $overriding) {
                    shop_trace("[{$afullbill->transactionid}] ".'Production Controller : Post Pay process');
                    if ($this->interactive && $this->ipncall) {
                        mtrace("[{$afullbill->transactionid}] ".'Production Controller : Post Pay process');
                    }

                    if ($productionfeedback2 = produce_postpay($afullbill)) {
                        $productionfeedback->public .= '<br/>'.$productionfeedback2->public;
                        $productionfeedback->private .= '<br/>'.$productionfeedback2->private;
                        $productionfeedback->salesadmin .= '<br/>'.$productionfeedback2->salesadmin;
                        if ($overriding) {
                            $afullbill->status = SHOP_BILL_PREPROD; // Let replay for test.
                        } else {
                            $afullbill->status = SHOP_BILL_COMPLETE; // Let replay for test.
                        }
                        if (!$holding) {
                            // If holding for repeatable tests, do not complete the bill.
                            $afullbill->save();
                        }
                    }
                }
                /*
                 * log new production data into bill record
                 * the first producing procedure stores production data.
                 * if interactive shopback process comes later, we just have production
                 * data to display to user.
                 */
                shop_aggregate_production($afullbill, $productionfeedback, $this->interactive);
            } else {
                $productionfeedback = new \StdClass;
                $productionfeedback->public = "Completed";
                shop_aggregate_production($afullbill, $productionfeedback, $this->interactive);
            }
        }

        // Send final notification by mail if something has been done the end user should know.
        shop_trace("[{$afullbill->transactionid}] ".'Production Controller : Transaction Complete Operations');
        if ($this->interactive && $this->ipncall) {
            mtrace("[{$afullbill->transactionid}] ".'Production Controller : Transaction Complete Operations');
            mtrace($productionfeedback->public);
            mtrace($productionfeedback->private);
        }

        // Notify end user.
        // Feedback customer with mail confirmation.
        $customer = $DB->get_record('local_shop_customer', array('id' => $afullbill->customerid));

        $paymodename = get_string($afullbill->paymode, 'shoppaymodes_'.$afullbill->paymode);
        $vars = array('SERVER' => $SITE->shortname,
                      'SERVER_URL' => $CFG->wwwroot,
                      'SELLER' => $config->sellername,
                      'FIRSTNAME' => $customer->firstname,
                      'LASTNAME' => $customer->lastname,
                      'MAIL' => $customer->email,
                      'CITY' => $customer->city,
                      'COUNTRY' => $customer->country,
                      'ITEMS' => $afullbill->itemcount,
                      'PAYMODE' => $paymodename,
                      'AMOUNT' => sprintf("%.2f", round($afullbill->amount, 2)));
        $notification = shop_compile_mail_template('sales_feedback', $vars, '');
        $params = array('id' => $afullbill->shopid,
                        'blockid' => $afullbill->blockid,
                        'view' => 'bill',
                        'billid' => $afullbill->id,
                        'transid' => $afullbill->transactionid);
        $customerbillviewurl = new moodle_url('/local/shop/front/view.php', $params);

        $seller = new \StdClass;
        $seller->id = $DB->get_field('user', 'id', array('username' => 'admin', 'mnethostid' => $CFG->mnet_localhost_id));
        $seller->firstname = '';
        $seller->lastname = $config->sellername;
        $seller->email = $config->sellermail;
        $seller->maildisplay = true;
        $seller->id = $DB->get_field('user', 'id', array('email' => $config->sellermail));

        // Complete seller with expected fields.
        $fields = get_all_user_name_fields();
        foreach ($fields as $f) {
            if (!isset($seller->$f)) {
                $seller->$f = '';
            }
        }

        $title = $SITE->shortname . ' : ' . get_string('yourorder', 'local_shop');
        if (!empty($productiondata->private)) {
            $sentnotification = str_replace('<%%PRODUCTION_DATA%%>', $productiondata->private, $notification);
        } else {
            $sentnotification = str_replace('<%%PRODUCTION_DATA%%>', '', $notification);
        }
        if (empty($afullbill->customeruser)) {
            $afullbill->customeruser = $DB->get_record('user', array('id' => $afullbill->customer->hasaccount));
        }

        if ($afullbill->customeruser) {
            ticket_notify($afullbill->customeruser, $seller, $title, $sentnotification, $sentnotification, $customerbillviewurl);
        }

        if ($this->interactive && $this->ipncall) {
            mtrace("[{$afullbill->transactionid}] ".'Production Controller : Transaction notified to customer');
        }

        /* notify sales forces and administrator */
        // Send final notification by mail if something has been done the sales administrators users should know.
        $vars = array('TRANSACTION' => $afullbill->transactionid,
                      'SERVER' => $SITE->fullname,
                      'SERVER_URL' => $CFG->wwwroot,
                      'SELLER' => $config->sellername,
                      'FIRSTNAME' => $customer->firstname,
                      'LASTNAME' => $customer->lastname,
                      'MAIL' => $customer->email,
                      'CITY' => $customer->city,
                      'COUNTRY' => $customer->country,
                      'PAYMODE' => $afullbill->paymode,
                      'ITEMS' => $afullbill->itemcount,
                      'AMOUNT' => sprintf("%.2f", round($afullbill->untaxedamount, 2)),
                      'TAXES' => sprintf("%.2f", round($afullbill->taxes, 2)),
                      'TTC' => sprintf("%.2f", round($afullbill->amount, 2)));
        $salesnotification = shop_compile_mail_template('transaction_confirm', $vars, '');
        $params = array('id' => $afullbill->shopid,
                        'view' => 'viewBill',
                        'billid' => $afullbill->id,
                        'transid' => $afullbill->transactionid);
        $administratorviewurl = new moodle_url('/local/shop/bills/view.php', $params);;
        if ($salesrole = $DB->get_record('role', array('shortname' => 'sales'))) {
            $title = $SITE->shortname.' : '.get_string('orderconfirm', 'local_shop');
            if (!empty($productiondata->private)) {
                $sn = str_replace('<%%PRODUCTION_DATA%%>', $productiondata->salesadmin, $salesnotification);
            } else {
                $sn = str_replace('<%%PRODUCTION_DATA%%>', '', $salesnotification);
            }
            $sent = ticket_notifyrole($salesrole->id, $systemcontext, $seller, $title, $sn, $sn, $administratorviewurl);
            if ($sent) {
                $message = "[{$afullbill->transactionid}] Production Controller :";
                $message .= " shop Transaction Confirm Notification to sales";
                shop_trace($message);
            } else {
                $message = "[{$afullbill->transactionid}] Production Controller Warning :";
                $message .= " Seems no sales manager are assigned";
                shop_trace($message);
            }
        } else {
            shop_trace("[{$afullbill->transactionid}] ".'Production Controller : No sales role defined');
        }

        // Final destruction of the shopping session.

        if (!empty($this->interactive)) {
            if (!$holding) {
                unset($SESSION->shoppingcart);
            }
        }
    }
}