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
 * @package    shoppaymodes_mercanet
 * @category   local
 * @author     Valery Fremaux (valery.fremaux@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot.'/local/shop/paymodes/paymode.class.php');
require_once($CFG->dirroot.'/local/shop/locallib.php');
require_once($CFG->dirroot.'/local/shop/classes/Bill.class.php');

Use \local_shop\Bill;

# Response codes

define('MRCNT_PAYMENT_ACCEPTED', '00'); // Autorisation accept�e
define('MRCNT_MAX_LIMIT_REACHED', '02'); // Demande d�autorisation par t�l�phone � la banque � cause d�un d�passement du plafond d�autorisation sur la carte, si vous �tes autoris� � forcer les transactions. (cf. Annexe L) Dans le cas contraire, vous obtiendrez un code 05.
define('MRCNT_INVALID_MERCHANT', '03'); // Champ merchant_id invalide, v�rifier la valeur renseign�e dans la requ�te Contrat de vente � distance inexistant, contacter votre banque.
define('MRCNT_PAYMENT_REJECTED', '05'); // Autorisation refus�e
define('MRCNT_INVALID_TX', '12'); // Transaction invalide, v�rifier les param�tres transf�r�s dans la requ�te.
define('MRCNT_USER_CANCELLED', '17'); // Annulation de l�internaute
define('MRCNT_FORMAT_ERROR', '30'); // Erreur de format.
define('MRCNT_POSSIBLY_EVIL', '34'); // Suspicion de fraude
define('MRCNT_MAX_TRIES', '75'); // Nombre de tentatives de saisie du num�ro de carte d�pass�.
define('MRCNT_UNVAILABLE', '90'); // Service temporairement indisponible

class shop_paymode_mercanet extends shop_paymode{

    function __construct(&$shop) {
        parent::__construct('mercanet', $shop, true, true); // overrides local confirm
        $overridelocalconfirm = true;
    }

    function is_instant_payment() {
        return true;
    }

    // prints a payment porlet in an order form
    function print_payment_portlet(&$shoppingcart) {
        global $CFG, $USER;

        echo '<div id="shop-panel-caption">';

        echo shop_compile_mail_template('door_transfer_text', array(), 'shoppaymodes_mercanet');

        echo '</div>';
        echo '<div id="shop-panel-mercanet"><br />';

        $portlet = new StdClass();
        $portlet->sessionid = session_id();
        $portlet->amount = $shoppingcart->finalshippedtaxedtotal;
        $portlet->customer = (object)$shoppingcart->customerinfo;

        // Some payment method have special constraints for transaction ID format, so we cannot use generic internal unique bill ID all the time.
        $portlet->transactionid = $shoppingcart->transid;
        $portlet->onlinetransactionid = $this->generate_online_id();

        echo "Transaction ID : ".$portlet->onlinetransactionid;

        $portlet->returnurl = $CFG->wwwroot.'/local/shop/paymodes/mercanet/process.php';
        $portlet->cancelurl = $CFG->wwwroot.'/local/shop/paymodes/mercanet/cancel.php';
        $portlet->ipnurl = $CFG->wwwroot.'/local/shop/paymodes/mercanet/mercanet_ipn.php';

        include($CFG->dirroot.'/local/shop/paymodes/mercanet/mercanetAPI.portlet.php');

        echo '</div>';
        echo '<div id="shop-panel-nav">';

        echo '<center><p><span class="procedureOrdering"></span>';
        /*
        $payonlinestr = get_string('payonline', 'local_shop');
        echo "<input type=\"button\" name=\"go_btn\" value=\"$payonlinestr\" onclick=\"document.confirmation.submit();\" />";
        */
        echo '<p><span class="shop-procedure-cancel">X</span> ';
        $cancelstr = get_string('cancel');
        $cancelurl = new moodle_url('/local/shop/front/view.php', array('view' => 'shop', 'id' => $this->theshop->id));
        echo '<a href="'.$cancelurl.'" class="smalltext">'.$cancelstr.'</a>';
        echo '</div>';
    }

    // prints a payment porlet in an order form
    function print_invoice_info(&$billdata = null) {
        echo get_string($this->name.'paymodeinvoiceinfo', 'shoppaymodes_mercanet', $this->name);
    }

    function print_complete() {
        echo shop_compile_mail_template('bill_complete_text', array());
    }

    /**
     * extract DATA, get context_return and bounce to shop entrance with proper context values
     */
    function cancel() {
        global $CFG, $SESSION, $DB;

        $paydata = $this->decode_return_data();

        list($cmd, $instanceid, $transid) = explode('-', $paydata['return_context']);
        // mark transaction (order record) as abandonned

        $aFullBill = Bill::get_by_transaction($transid);

        $this->theshop = $aFullBill->theshop;

        $aFullBill->onlinetransactionid = $paydata['merchant_id'].'-'.$paydata['transmission_date'].'-'.$paydata['transaction_id'];
        $aFullBill->paymode = 'mercanet';
        $aFullBill->status = SHOP_BILL_CANCELLED;
        $aFullBill->save(true);

        // cancel shopping cart
        // unset($SESSION->shoppingcart);

        $redirecturl = new moodle_url('/local/shop/front/view.php', array('view' => 'shop', 'id' => $this->theshop->id));
        redirect($redirecturl);
    }

    // processes an explicit payment return
    function process() {
        global $CFG, $SESSION, $DB, $OUTPUT;

        $config = get_config('local_shop');

        $paydata = $this->decode_return_data();
        // Erreur, affiche le message d'erreur
        if ($paydata['code'] != 0 && !empty($paydata['error'])) {
            echo $OUTPUT->header();
            $mercanetapierrorstr = get_string('mercanetapierror', 'shoppaymodes_mercanet');
            echo "<center><b>{$mercanetapierrorstr}</b></center>";
            echo '<br/><br/>';
            $mercaneterror = get_string('mercaneterror', 'shoppaymodes_mercanet', $paydata['error']);
            echo $mercaneterror.'<br/>';
            return false;

        } else {

            // OK, affichage des champs de la r�ponse

            list($cmd, $instanceid, $transid) = explode('-', $paydata['return_context']);

            $aFullBill = Bill::get_by_transaction($transid);

            $this->theshop = $aFullBill->theshop;

            if (debugging() && $config->test) {
                echo $OUTPUT->header();
                # OK, affichage du mode DEBUG si activ�
                echo "<center>\n";
                echo "<H3>TEST MODE : R&eacute;ponse manuelle du serveur MERCANET</H3>\n";
                echo '<hr/>';
                echo "<br/><br/><hr/>";
                echo $paydata['error'];
                echo "<H3>FINAL STATE : {$aFullBill->status}</H3>\n";
                echo "</center>\n";
            }
            
            // bill could already be SOLDOUT by IPN    so do nothing
            // process it only if needing to process.
            if ($aFullBill->status == SHOP_BILL_PLACED) {
                // processing bill changes
                if ($paydata['response_code'] == MRCNT_PAYMENT_ACCEPTED) {
                    $aFullBill->onlinetransactionid = $paydata['merchant_id'].'-'.$paydata['transmission_date'].'-'.$paydata['transaction_id'];
                    $aFullBill->status = SHOP_BILL_PENDING;
                    $aFullBill->save(true);

                    // redirect to success for ordering production with significant data
                    shop_trace("[$transid] Mercanet : Transaction Pending for IPN confirmation, transferring to success end point");

                    if (empty($config->test)) {
                        $redirecturl = new moodle_url('/local/shop/front/view.php', array('view' => 'produce', 'id' => $this->theshop->id, 'what' => 'confirm', 'transid' => $transid));
                        redirect($redirecturl);
                    } else {
                        $continueurl = new moodle_url('/local/shop/front/view.php', array('view' => 'produce', 'id' => $this->theshop->id, 'what' => 'confirm', 'transid' => $transid));
                        echo $OUTPUT->continue_button($continueurl, get_string('continueaftersuccess', 'shoppaymodes_mercanet'));
                    }
                } elseif ($paydata['response_code'] == MRCNT_PAYMENT_REJECTED) {
                    $aFullBill->status = SHOP_BILL_REFUSED;
                    $aFullBill->save(true);

                    // Do not erase shopping cart : user might try again with other payment mean
                    // unset($SESSION->shoppingcart);
                    if (empty($config->test)) {
                        $redirecturl = new moodle_url('/local/shop/front/view.php', array('view' => $this->theshop->get_starting_step(), 'id' => $this->theshop->id, 'transid' => $transid));
                        redirect($redirecturl);
                    } else {
                        $continueurl = new moodle_url('/local/shop/front/view.php', array('view' => $this->theshop->get_starting_step(), 'id' => $this->theshop->id, 'transid' => $transid));
                        echo $OUTPUT->continue_button($continueurl, get_string('continueafterfailure', 'shoppaymodes_mercanet'));
                    }
                } else {
                    $aFullBill->status = SHOP_BILL_FAILED;
                    $aFullBill->save(true);

                    // Do not erase shopping cart : user might try again with other payment mean
                    // unset($SESSION->shoppingcart);
                    if (empty($config->test)) {
                        $redirecturl = new moodle_url('/local/shop/front/view.php', array('view' => $this->theshop->get_starting_step(), 'id' => $this->theshop->id, 'transid' => $transid));
                        redirect($redirecturl);
                    } else {
                        $continueurl = new moodle_url('/local/shop/front/view.php', array('view' => $this->theshop->get_starting_step(), 'id' => $this->theshop->id, 'transid' => $transid));
                        echo $OUTPUT->continue_button($continueurl, get_string('continueafterfailure', 'shoppaymodes_mercanet'));
                    }
                }
            }
            if ($aFullBill->status == SHOP_BILL_SOLDOUT) {
                if (empty($config->test)) {
                    $redirecturl = new moodle_url('/local/shop/front/view.php', array('view' => 'produce', 'id' => $this->theshop->id, 'what' => 'produce', 'transid' => $transid));
                    redirect($redirecturl);
                } else {
                    $continueurl = new moodle_url('/local/shop/front/view.php', array('view' => 'produce', 'id' => $this->theshop->id, 'what' => 'produce', 'transid' => $transid));
                    echo $OUTPUT->continue_button($continueurl, get_string('continueaftersoldout', 'shoppaymodes_mercanet'));
                }
            }
            if ($aFullBill->status == SHOP_BILL_COMPLETE) {
                // All is done already. clear everything.
                unset($SESSION->shoppingcart);
                if (empty($config->test)) {
                    $redirecturl = new moodle_url('/local/shop/front/view.php', array('view' => $this->theshop->get_starting_step(), 'id' => $this->theshop->id, 'what' => 'produce', 'transid' => $transid));
                    redirect($redirecturl);
                } else {
                    $continueurl = new moodle_url('/local/shop/front/view.php', array('view' => $this->theshop->get_starting_step(), 'id' => $this->theshop->id, 'what' => 'produce', 'transid' => $transid));
                    echo $OUTPUT->continue_button($continueurl, get_string('continueaftersoldout', 'shoppaymodes_mercanet'));
                }
            }
        }
    }

    /**
     * processes a payment asynchronoous confirmation.
     * Do not care about session here as you are sessionless backcall.
     */
    function process_ipn() {
        global $CFG;

        $paydata = $this->decode_return_data();

        if ($paydata['code'] != 0 && !empty($paydata['error'])) {

            $mercanetapierrorstr = get_string('mercanetapierror', 'shoppaymodes_mercanet');
            shop_trace("Mercanet IPN : {$mercanetapierrorstr} : ".$paydata['error']);
            die;

        } else {

            list($cmd, $instanceid, $transid) = explode('-', $paydata['return_context']);

            shop_trace("[$transid] Mercanet IPN processing");

            // Mark transaction (order record) as abandonned.
            $aFullBill = Bill::get_by_transaction($transid);

            $this->theshop = $aFullBill->theshop;

            // Processing bill changes.
            if ($aFullBill->status == SHOP_BILL_PENDING || $aFullBill->status == SHOP_BILL_PLACED) {

                if ($paydata['response_code'] == MRCNT_PAYMENT_ACCEPTED) {
                    $aFullBill->onlinetransactionid = $paydata['merchant_id'].'-'.$paydata['transmission_date'].'-'.$paydata['transaction_id'];
                    $aFullBill->status = SHOP_BILL_SOLDOUT;
                    $aFullBill->save(true);

                    shop_trace("[$transid]  Mercanet IPN : success, transferring to production controller");
                    // now we need to execute non interactive production code
                    // this SHOULD NOT be done by redirection as Mercanet server might not 
                    // handle this. Thus only use the controller and die afterwoods.

                    // here we need to fake user login in if has account or we might create another diverging account
                    // payment acceptation is sufficiant to validate userid information out from bill data
                    if (!empty($aFullBill->customer->hasaccount)) {
                        global $USER;
                        $USER = $DB->get_record('user', array('id' => $aFullBill->customer->hasaccount));
                    }

                    include_once($CFG->dirroot.'/local/shop/front/produce.controller.php');
                    $controller = new \local_shop\front\production_controller($aFullBill, true, false);
                    $result = $controller->process('produce');
                    die;

                } elseif ($paydata['response_code'] == MRCNT_PAYMENT_REJECTED) {
                    $aFullBill->status = SHOP_BILL_REFUSED;
                    $aFullBill->save(true);
                    die;
                } else {
                    $aFullBill->status = SHOP_BILL_FAILED;
                    $aFullBill->save(true); // stateonly

                    shop_trace("[$transid] Mercanet IPN failure : ".$paydata['response_code']);
                    die;
                }
            } else {
                shop_trace("[$transid] Mercanet IPN : Inactive state ".$aFullBill->status);
            }
        }
    }

    /**
     * Provides global settings to add to shop settings when installed.
     */
    function settings(&$settings) {
        global $CFG;

        $settings->add(new admin_setting_heading('local_shop_'.$this->name, get_string($this->name.'paymodeparams', 'shoppaymodes_mercanet'), ''));

        $settings->add(new admin_setting_configtext('local_shop_mercanet_merchant_id', get_string('mercanetmerchantid', 'shoppaymodes_mercanet'),
                           get_string('configmercanetmerchantid', 'shoppaymodes_mercanet'), '', PARAM_TEXT));

        // TODO : Generalize
        $countryoptions['fr'] = get_string('france', 'shoppaymodes_mercanet');
        $countryoptions['be'] = get_string('belgium', 'shoppaymodes_mercanet');
        $countryoptions['en'] = get_string('england', 'shoppaymodes_mercanet');
        $countryoptions['de'] = get_string('germany', 'shoppaymodes_mercanet');
        $countryoptions['es'] = get_string('spain', 'shoppaymodes_mercanet');
        $settings->add(new admin_setting_configselect('local_shop_mercanet_country', get_string('mercanetcountry', 'shoppaymodes_mercanet'),
                           get_string('configmercanetcountry', 'shoppaymodes_mercanet'), '', $countryoptions));

        $pathfileurl = new moodle_url('/local/shop/paymodes/mercanet/makepathfile.php');
        $settings->add(new admin_setting_heading('local_shop_mercanet_generatepathfile', '', '<a href="'.$pathfileurl.'">'.get_string('makepathfile', 'shoppaymodes_mercanet').'</a>'));

        $currencycodesoptions = array('978' => get_string('cur978', 'shoppaymodes_mercanet'),
                                    '840' => get_string('cur840', 'shoppaymodes_mercanet'),
                                    '756' => get_string('cur756', 'shoppaymodes_mercanet'),
                                    '826' => get_string('cur826', 'shoppaymodes_mercanet'),
                                    '124' => get_string('cur124', 'shoppaymodes_mercanet'),
                                    // Yen 392 0 106 106
                                    // Peso Mexicain 484 2 106.55 10655
                                    '949' => get_string('cur949', 'shoppaymodes_mercanet'),
                                    '036' => get_string('cur036', 'shoppaymodes_mercanet'),
                                    '554' => get_string('cur554', 'shoppaymodes_mercanet'),
                                    '578' => get_string('cur578', 'shoppaymodes_mercanet'),
                                    '986' => get_string('cur986', 'shoppaymodes_mercanet'),
                                    '032' => get_string('cur032', 'shoppaymodes_mercanet'),
                                    '116' => get_string('cur116', 'shoppaymodes_mercanet'),
                                    '901' => get_string('cur901', 'shoppaymodes_mercanet'),
                                    '752' => get_string('cur752', 'shoppaymodes_mercanet'),
                                    '208' => get_string('cur208', 'shoppaymodes_mercanet'),
                                    '702' => get_string('cur702', 'shoppaymodes_mercanet'));
        $settings->add(new admin_setting_configselect('local_shop_mercanet_currency_code', get_string('mercanetcurrencycode', 'shoppaymodes_mercanet'),
                           get_string('configmercanetcurrencycode', 'shoppaymodes_mercanet'), '', $currencycodesoptions));

        $processoroptions = array('32' => '32 bits', '64' => '64 bits');
        $settings->add(new admin_setting_configselect('local_shop_mercanet_processor_type', get_string('mercanetprocessortype', 'shoppaymodes_mercanet'),
                           get_string('configmercanetprocessortype', 'shoppaymodes_mercanet'), '', $processoroptions));
    }

    /**
     * Generates the realpath file for the required implementation from the template file. 
     */
    function generate_pathfile() {
        global $CFG, $OUTPUT;

        $config = get_config('local_shop');

        $os = (preg_match('/Linux/i', $CFG->os)) ? 'linux' : 'win' ;
        $pathfiletemplate = $CFG->dirroot.'/local/shop/paymodes/mercanet/mercanet_615_PLUGIN_'.$os.$config->mercanet_processor_type.'/param/pathfile.tpl';
        $pathfile = $this->get_pathfile($os);
        assert(file_exists($pathfiletemplate));
        $tmp = implode('', file($CFG->dirroot.'/local/shop/paymodes/mercanet/mercanet_615_PLUGIN_'.$os.$config->mercanet_processor_type.'/param/pathfile.tpl'));

        if ($os == 'linux') {
            $tmp = str_replace('<%%DIRROOT%%>', $CFG->dirroot, $tmp);
            $tmp = str_replace('<%%DATAROOT%%>', $CFG->dataroot, $tmp);
            $mercanetdebug = ($config->test) ? 'YES' : 'NO';
            $tmp = str_replace('<%%DEBUG%%>', $mercanetdebug, $tmp);
            /* $tmp = str_replace('<%%COUNTRY%%>', $config->mercanet_country, $tmp);
             $tmp = str_replace('<%%CERTIFICATEID%%>', $config->mercanet_merchant_id, $tmp); */
             // Unnecessary as compiled internaly by API
        } else {
            // make exact windows slashing for writing pathfile
            $tmp = str_replace('<%%DIRROOT%%>', str_replace('/', '\\', $CFG->dirroot), $tmp);
            $tmp = str_replace('<%%DATAROOT%%>', str_replace('/', '\\', $CFG->dataroot), $tmp);
            $mercanetdebug = ($config->test) ? 'YES' : 'NO';
            $tmp = str_replace('<%%DEBUG%%>', $mercanetdebug, $tmp);
            /* $tmp = str_replace('<%%CERTIFICATEID%%>', $config->mercanet_merchant_id, $tmp);
            $tmp = str_replace('<%%COUNTRY%%>', $config->mercanet_country, $tmp); */
        }

        if ($PATHFILE = @fopen($pathfile, 'w')) {
            fputs($PATHFILE, $tmp);
            fclose($PATHFILE);
            echo $OUTPUT->notification('Pathfile generated', new moodle_url('/admin/settings.php', array('section' => 'localsettingshop')));
        } else {
            echo $OUTPUT->notification('Pathfile is not writable. Check file permissions on system. This is a very SENSIBLE file. Don\'t forget to protect it back after operation.', new moodle_url('/admin/settings.php', array('section' => 'localsettingshop')));
        }
    }

    /**
     * returns pathfile location
     */
    function get_pathfile($os) {
        global $CFG;

        $config = get_config('local_shop');

        if (!isset($config->mercanet_processor_type)) {
            $config->mercanet_processor_type = '32';
        }
        if ($os == 'linux') {
            return $CFG->dirroot.'/local/shop/paymodes/mercanet/mercanet_615_PLUGIN_'.$os.$config->mercanet_processor_type.'/param/pathfile';
        } else {
            return str_replace('/', "\\", $CFG->dirroot).'\\blocks\\shop\\paymodes\\mercanet\\mercanet_615_PLUGIN_'.$os.$config->mercanet_processor_type.'\\param\\pathfile';
        }
    }

    /**
     * returns mercanet request form generator location
     */
    function get_request_bin($os) {
        global $CFG;

        $config = get_config('local_shop');

        $exeextension = ($os == 'linux') ? '' : '.exe';
        $relpath = ($os == 'linux') ? 'static/' : '';
        return $CFG->dirroot.'/local/shop/paymodes/mercanet/mercanet_615_PLUGIN_'.$os.$config->mercanet_processor_type.'/bin/'.$relpath.'request'.$exeextension;
    }

    /**
     * returns mercanet request form response decoder
     */
    function get_response_bin($os) {
        global $CFG;

        $config = get_config('local_shop');

        $exeextension = ($os == 'linux') ? '' : '.exe';
        $relpath = ($os == 'linux') ? 'static/' : '';
        return $CFG->dirroot.'/local/shop/paymodes/mercanet/mercanet_615_PLUGIN_'.$os.$config->mercanet_processor_type.'/bin/'.$relpath.'response'.$exeextension;
    }

    /**
     * generates a suitable online id for the transaction.
     * real bill online id is : merchant_country, merchant_id, payment_date, and the onlinetxid generated here.
     */
    function generate_online_id() {
        global $CFG;

        $now = time();
        $midnight = mktime (0, 0, 0, date("n", $now), date("j", $now), date("Y", $now));
        if ($midnight > 0 + @$CFG->shop_mercanet_lastmidnight) {
            set_config('shop_mercanet_idseq', 1);
            set_config('shop_mercanet_lastmidnight', $midnight);
        }
        $onlinetxid = sprintf('%06d', ++$CFG->shop_mercanet_idseq);
        set_config('shop_mercanet_idseq', $CFG->shop_mercanet_idseq);
        return $onlinetxid;
    }

    /**
     * Get the mercanet buffer and extract info from cryptic response.
     */
    function decode_return_data() {
        global $CFG;

        // R�cup�ration de la variable crypt�e DATA
        $message = 'message='.$_POST['DATA'];
        if (empty($message)) {
              $mercanetreturnerrorstr = get_string('emptymessage', 'local_shop');
            echo "<br/><center>$mercanetreturnerrorstr</center><br/>";
            return false;
        }
        // Initialisation du chemin du fichier pathfile (� modifier)
        // ex :
        // -> Windows : $pathfile="pathfile=c:/repertoire/pathfile";
        // -> Unix    : $pathfile="pathfile=/home/repertoire/pathfile";
        $os = (preg_match('/Linux/i', $CFG->os)) ? 'linux' : 'win' ;
        $pathfile = 'pathfile='.$this->get_pathfile($os);
        // Initialisation du chemin de l'executable response (� modifier)
        // ex :
        // -> Windows : $path_bin = "c:/repertoire/bin/response";
        // -> Unix    : $path_bin = "/home/repertoire/bin/response";
        // $path_bin = $this->get_response_bin($os);
        // Appel du binaire response
          $message = escapeshellcmd($message);
        $result = exec("$path_bin $pathfile $message");
        // Sortie de la fonction : !code!error!v1!v2!v3!...!v29
        // - code=0    : la fonction retourne les donn�es de la transaction dans les variables v1, v2, ...
        // : Ces variables sont d�crites dans le GUIDE DU PROGRAMMEUR
        // - code=-1     : La fonction retourne un message d'erreur dans la variable error
        // on separe les differents champs et on les met dans une variable tableau
        $paymentresponse = explode ("!", $result);
        // analyse du code retour
        if (!$paymentresponse || (($paymentresponse[1] === '' /* code */) && ($paymentresponse[2] == '' /* error */))) {
              $mercanetapierrorstr = get_string('errorcallingAPI', 'shoppaymodes_mercanet', $path_bin);
            echo "<br/><center>$mercanetapierrorstr</center><br/>";
            return false;
        }
        // R�cup�ration des donn�es de la r�ponse
        $paydata['code'] = $paymentresponse[1];
        $paydata['error'] = $paymentresponse[2];
        $paydata['merchant_id'] = $paymentresponse[3];
        $paydata['merchant_country'] = $paymentresponse[4];
        $paydata['amount'] = $paymentresponse[5];
        $paydata['transaction_id'] = $paymentresponse[6];
        $paydata['payment_means'] = $paymentresponse[7];
        $paydata['transmission_date'] = $paymentresponse[8];
        $paydata['payment_time'] = $paymentresponse[9];
        $paydata['payment_date'] = $paymentresponse[10];
        $paydata['response_code'] = $paymentresponse[11];
        $paydata['payment_certificate'] = $paymentresponse[12];
        $paydata['authorisation_id'] = $paymentresponse[13];
        $paydata['currency_code'] = $paymentresponse[14];
        $paydata['card_number'] = $paymentresponse[15];
        $paydata['cvv_flag'] = $paymentresponse[16];
        $paydata['cvv_response_code'] = $paymentresponse[17];
        $paydata['bank_response_code'] = $paymentresponse[18];
        $paydata['complementary_code'] = $paymentresponse[19];
        $paydata['complementary_info'] = $paymentresponse[20];
        $paydata['return_context'] = $paymentresponse[21];
        $paydata['caddie'] = $paymentresponse[22];
        $paydata['receipt_complement'] = $paymentresponse[23];
        $paydata['merchant_language'] = $paymentresponse[24];
        $paydata['language'] = $paymentresponse[25];
        $paydata['customer_id'] = $paymentresponse[26];
        $paydata['order_id'] = $paymentresponse[27];
        $paydata['customer_email'] = $paymentresponse[28];
        $paydata['customer_ip_address'] = $paymentresponse[29];
        $paydata['capture_day'] = $paymentresponse[30];
        $paydata['capture_mode'] = $paymentresponse[31];
        $paydata['data'] = $paymentresponse[32];
        $paydata['order_validity'] = $paymentresponse[33];
        $paydata['transaction_condition'] = $paymentresponse[34];
        $paydata['statement_reference'] = $paymentresponse[35];
        $paydata['card_validity'] = $paymentresponse[36];
        $paydata['score_value'] = $paymentresponse[37];
        $paydata['score_color'] = $paymentresponse[38];
        $paydata['score_info'] = $paymentresponse[39];
        $paydata['score_threshold'] = $paymentresponse[40];
        $paydata['score_profile'] = $paymentresponse[41];
        return $paydata;
    }
}