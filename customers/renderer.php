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
 * @package     local_shop
 * @category    local
 * @author      Valery Fremaux <valery.fremaux@gmail.com>
 * @copyright   Valery Fremaux <valery.fremaux@gmail.com> (MyLearningFactory.com)
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/local/shop/classes/Shop.class.php');
use local_shop\Shop;

class shop_customers_renderer {

    protected $theshop;

    public function load_context(&$theshop) {
        $this->theshop = $theshop;
    }

    public function customers($customers) {
        global $OUTPUT;

        $customerstr = get_string('customer', 'local_shop');
        $lastnamestr = get_string('lastname');
        $firstnamestr = get_string('firstname');
        $purchasesstr = get_string('purchases', 'local_shop');
        $totalamountstr = get_string('totalamount', 'local_shop');

        $table = new html_table();
        $table->width = '100%';
        $table->head = array('',
                             "<b>$customerstr</b>",
                             "<b>$lastnamestr</b>",
                             "<b>$firstnamestr</b>",
                             "<b>$purchasesstr</b>",
                             "<b>$totalamountstr</b>",
                             '');
        $table->align = array('center', 'left', 'left', 'left', 'center', 'center', 'right');

        $emptyaccounts = 0;
        foreach ($customers as $c) {
            if ($c->billcount == 0) {
                $emptyaccounts++;
            }
            $row = array();
            $params = array('view' => 'viewCustomer', 'customer' => $c->id);
            $customerurl = new moodle_url('/local/shop/customers/view.php', $params);
            $row[] = '<a href="'.$customerurl.'">'.$c->id.'</a>';
            $row[] = $c->lastname;
            $row[] = $c->firstname;
            $row[] = sprintf("%.2f", round($c->totalaccount, 2)).' '.$this->theshop->defaultcurrency;
            $editurl = new moodle_url('/local/shop/customers/edit_customer.php', array('customerid' => $c->id));
            $cmd = '<a href="'.$editurl.'"><img src="'.$OUTPUT->pix_url('t/edit').'"/></a>';
            if ($c->billcount == 0) {
                $params = array('view' => 'viewAllCustomers', 'customerid[]' => $c->id, 'what' => 'deletecustomer');
                $deleteurl = new moodle_url('/local/shop/customers/view.php', $params);
                $cmd .= '&nbsp;<a href="'.$deleteurl.'"><img src="'.$OUTPUT->pix_url('t/delete').'"/></a>';
            }
            $row[] = $cmd;
            $table->data[] = $row;
        }

        return html_writer::table($table);
    }

    /**
     * Detail information for a customer
     * @param \local_shop\Customer $customer
     */
    public function customer_detail($customer) {
        $str = '<table class="generaltable">';
        $str .= '<tr>';
        $str .= '<td>';
        $str .= '<b>'.get_string('identification', 'local_shop').':</b></td>';
        $str .= '<td>';
        $str .= '<a href="mailto:'.$customer->email.'">'.$customer->email.'</a>';
        $str .= '</td>';

        $str .= '<td>';
        $str .= '<b>'.get_string('moodleaccount', 'local_shop').':</b></td>';

        $str .= '<td>';
        if ($customer->hasaccount) {
            $viewmoodleacocuntstr = get_string('viewmoodleaccount', 'local_shop');
            $userurl = new moodle_url('/user/view.php', array('id' => $customer->hasaccount));
            $str .= '<a href="'.$userurl.'">'.$viewmoodleacocuntstr.'</a>';
        } else {
            print_string('nomoodleaccount', 'local_shop');
        }
        $str .= '</td>';
        $str .= '</tr>';

        $str .= '<tr>';
        $str .= '<td>';
        $str .= '<b>'.get_string('customer', 'local_shop').':</b></td>';
        $str .= '<td colspan="3">'.$customer->lastname.' '.$customer->firstname.'</td>';
        $str .= '</tr>';

        $str .= '<tr>';
        $str .= '<td><b>'.get_string('city').'</b>:</td>';
        $str .= '<td colspan="3">'.$customer->city.'</td>';
        $str .= '</tr>';

        $str .= '<tr>';
        $str .= '<td><b>'.get_string('country').'</b>:</td>';
        $str .= '<td colspan="3">'.$customer->country.'</td>';
        $str .= '</tr>';
        $str .= '</table>';

        return $str;
    }

    /**
     * Bills for a customer
     * @param array $billset
     * @param string $status filtering on bill state
     */
    public function customer_bills($billset, $status) {
        global $OUTPUT;

        $config = get_config('local_shop');

        $numstr = get_string('num', 'local_shop');
        $idnumberstr = get_string('lettering', 'local_shop');
        $emissiondatestr = get_string('emissiondate', 'local_shop');
        $lastmovestr = get_string('lastmove', 'local_shop');
        $titlestr = get_string('title', 'local_shop');
        $amountstr = get_string('amount', 'local_shop');

        $table = new html_table();
        $table->heading = print_string('bill_' . $status.'s', 'local_shop');
        $table->head = array("<b>$numstr</b>",
                             "<b>$idnumberstr</b>",
                             "<b>$emissiondatestr</b>",
                             "<b>$lastmovestr</b>",
                             "<b>$titlestr</b>",
                             "<b>$amountstr</b>",
                             '');
        $table->size = array('5%', '5%', '%10', '10%', '50%', '10%', '10%');
        $table->width = '100%';
        $table->data = array();

        foreach ($billset as $portlet) {
            $row = array();
            $url = new moodle_url('/local/shop/bills/view.php', array('view' => 'viewBill', 'billid' => $portlet->id));
            $row[] = 'B-'.date('Y-m', $portlet->emissiondate).'-'.$portlet->id;
            $row[] = '<a href="'.$url.'">'.$portlet->idnumber.'</a>';
            $row[] = userdate($portlet->emissiondate);
            $row[] = userdate($portlet->lastactiondate);
            $row[] = $portlet->title;
            $row[] = sprintf("%.2f", round($portlet->amount, 2)).' '.$config->defaultcurrency;
            if ($portlet->status == 'PENDING') {
                $markstr = get_string('mark', 'local_shop');
                $params = array('view' => 'viewCustomer',
                                'what' => 'sellout',
                                'billid' => $portlet->id,
                                'customer' => $portlet->userid);
                $url = new moodle_url('/local/shop/view.php', $params);
                $row[] = '<a href="'.$url.'" alt="'.$markstr.'"><img src="'.$OUTPUT->pix_url('mark', 'local_shop').'"/></a>';
            } else if ($portlet->status == 'SOLDOUT') {
                $params = array('view' => 'viewCustomer',
                                'what' => 'unmark',
                                'billid' => $portlet->id,
                                'customer' => $portlet->userid);
                $url = new moodle_url('/local/shop/view.php', $params);
                $row[] = '<a href="'.$url.'" alt="'.$unmarkstr.'"><img src="'.$OUTPUT->pix_url('unmark', 'local_shop').'" ></a>';
            }
            $table->data[] = $row;
        }
        echo html_writer::table($table);
    }
}
