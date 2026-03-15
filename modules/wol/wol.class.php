<?php
/**
* @package project
* @author Wizard <sergejey@gmail.com>
* @copyright http://majordomo.smartliving.ru/ (c)
* @version 0.2 (исправлено для PHP 8.4)
*/
//
//
ini_set('display_errors', 'off');

class wol extends module {

    /**
     * Конструктор класса (совместим с PHP 8+)
     */
    function __construct() {
        parent::__construct(); // вызов конструктора родителя, если требуется
        $this->name = "wol";
        $this->title = "WakeOnLan";
        $this->module_category = "<#LANG_SECTION_DEVICES#>";
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0) {
        $p = array();
        if (IsSet($this->id)) {
            $p["id"] = $this->id;
        }
        if (IsSet($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (IsSet($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (IsSet($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams() {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run() {
        global $session;
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (IsSet($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (IsSet($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;

        // Обработка view_mode=wake (отправка WOL-пакета)
        if ($this->view_mode == 'wake') {
            $mac = '';
            if (!empty($this->mac)) {
                $mac = $this->mac;
            } else {
                global $mac;
                if (isset($mac) && !empty($mac)) {
                    $mac = $mac;
                }
            }

            if (!empty($mac)) {
                // Попытаемся найти устройство в БД, если ещё не добавлено
                $cmd_rec = SQLSelectOne("SELECT * FROM wol_devices WHERE MAC='" . DBSafe($mac) . "'");
                if (!$cmd_rec['ID']) {
                    $cmd_rec['MAC'] = $mac;
                    SQLInsert('wol_devices', $cmd_rec);
                }

                // Отправляем WOL на несколько широковещательных адресов
                $this->wakeOnLan('255.255.255.255', $mac);
                $this->wakeOnLan('192.168.255.255', $mac);
                $this->wakeOnLan('192.168.0.255', $mac);
                $this->wakeOnLan('192.168.1.255', $mac);
            }
        }

        if ($this->view_mode == 'indata_del') {
            $this->delete($this->id);
        }

        if ($this->view_mode == 'addtopinghost') {
            $this->add_to_pinghost($this->id);
        }

        if ($this->view_mode == 'ping') {
            $this->pingall();
        }

        if ($this->view_mode == 'discover') {
            $this->discover();
        }

        if ($this->view_mode == 'nmap') {
            $this->nmap();
        }

        if ($this->view_mode == 'clearall') {
            $this->clearall();
        }
    }

    /**
     * Поиск устройств в сети (discover)
     */
    function discover() {
        if (PHP_OS_FAMILY === 'Linux') {
            $cmd = 'arp -a';
            $answ = shell_exec($cmd);
            $data2 = preg_split('/\\r\\n?|\\n/', $answ);

            for ($i = 0; $i < count($data2); $i++) {
                $parts = explode(' ', $data2[$i]);
                if (count($parts) < 4) continue;

                $name = $parts[0];
                $ipadr = str_replace(['(', ')'], '', $parts[1]);
                $mac = $parts[3];

                if (empty($mac) || strlen($mac) < 8) continue;

                $vendor = $this->getvendor($mac);

                $cmd_rec = SQLSelectOne("SELECT * FROM wol_devices WHERE MAC='" . DBSafe($mac) . "'");
                $cmd_rec['MAC'] = $mac;
                $cmd_rec['IPADDR'] = $ipadr;
                $cmd_rec['TITLE'] = $name;
                $cmd_rec['VENDOR'] = $vendor;

                if (!$cmd_rec['ID']) {
                    if (strlen($mac) > 4) SQLInsert('wol_devices', $cmd_rec);
                } else {
                    SQLUpdate('wol_devices', $cmd_rec);
                }
            }
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $cmd = 'arp -a';
            $answ = shell_exec($cmd);
            $data2 = preg_split('/\\r\\n?|\\n/', $answ);

            for ($i = 0; $i < count($data2); $i++) {
                $ar = explode(' ', $data2[$i]);
                // Ожидаем формат: "192.168.1.1 00-11-22-33-44-55 динамический"
                if (count($ar) < 3) continue;

                $ipadr = $ar[0];
                $mac = str_replace('-', ':', $ar[1]); // преобразуем в единый формат

                if (empty($mac) || strlen($mac) < 8) continue;

                $name = $this->nbt_getName($ipadr);
                $vendor = $this->getvendor($mac);

                $cmd_rec = SQLSelectOne("SELECT * FROM wol_devices WHERE MAC='" . DBSafe($mac) . "'");
                $cmd_rec['MAC'] = $mac;
                $cmd_rec['IPADDR'] = $ipadr;
                $cmd_rec['TITLE'] = $name;
                $cmd_rec['VENDOR'] = $vendor;

                if (!$cmd_rec['ID']) {
                    if (strlen($mac) > 4) SQLInsert('wol_devices', $cmd_rec);
                } else {
                    SQLUpdate('wol_devices', $cmd_rec);
                }
            }
        }

        $this->pingall();
    }

    /**
     * Очистка таблицы устройств
     */
    function clearall() {
        SQLExec("DELETE FROM wol_devices");
    }

    /**
     * Проверка доступности устройств (ping)
     */
    function pingall() {
        $mhdevices = SQLSelect("SELECT * FROM wol_devices");
        $total = count($mhdevices);
        for ($i = 0; $i < $total; $i++) {
            $ip = $mhdevices[$i]['IPADDR'];
            $lastping = $mhdevices[$i]['LASTPING'];
            if ((!$lastping) || (time() - $lastping > 300)) {
                $cmd = '
                    $online = ping(processTitle("' . $ip . '"));
                    if ($online) {
                        SQLexec("UPDATE wol_devices SET ONLINE=1, LASTPING=' . time() . ' WHERE IPADDR=\'' . $ip . '\'");
                    } else {
                        SQLexec("UPDATE wol_devices SET ONLINE=0, LASTPING=' . time() . ' WHERE IPADDR=\'' . $ip . '\'");
                    }
                ';
                SetTimeOut('wol_devices_ping' . $i, $cmd, '1');
            }
        }
    }

    /**
     * Удаление устройства по ID
     */
    function delete($id) {
        $rec = SQLSelectOne("SELECT * FROM wol_devices WHERE ID='" . (int)$id . "'");
        if ($rec['ID']) {
            SQLExec("DELETE FROM wol_devices WHERE ID='" . $rec['ID'] . "'");
        }
    }

    /**
     * Поиск устройств (отображение)
     */
    function searchdevices(&$out) {
        $this->pingall();
        require(DIR_MODULES . $this->name . '/search.inc.php');
    }

    /**
     * BackEnd
     */
    function admin(&$out) {
        if ($this->view_mode == 'mac') {
            global $mac;
            if (!empty($mac)) {
                $res = $this->wakeOnLan("255.255.255.255", $mac);
                $this->wakeOnLan('192.168.255.255', $mac);
                $this->wakeOnLan('192.168.0.255', $mac);
                $this->wakeOnLan('192.168.1.255', $mac);
                $out['RESULT'] = print_r($res, true);
            }
        }

        $this->searchdevices($out);
    }

    /**
     * ОСНОВНАЯ ФУНКЦИЯ ОТПРАВКИ WOL-ПАКЕТА (исправлена для PHP 8.4)
     *
     * @param string $broadcast Широковещательный IP
     * @param string $mac MAC-адрес (формат: xx:xx:xx:xx:xx:xx)
     * @return bool
     */
    function wakeOnLan($broadcast, $mac) {
        // Очистка MAC от лишних символов и разделение
        $mac = preg_replace('/[^a-fA-F0-9:]/', '', $mac);
        $mac_array = explode(':', $mac);
        if (count($mac_array) != 6) {
            // Неверный формат MAC
            return false;
        }

        // Формирование аппаратного адреса
        $hwaddr = '';
        foreach ($mac_array as $octet) {
            $hwaddr .= chr(hexdec($octet));
        }

        // Магический пакет: 6 байт FF + 16 повторов MAC
        $packet = str_repeat("\xFF", 6) . str_repeat($hwaddr, 16);

        // Создание сокета
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            // Не удалось создать сокет
            return false;
        }

        // Установка опции BROADCAST (используем константы вместо магических чисел)
        if (!@socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1)) {
            socket_close($sock);
            return false;
        }

        // Отправка на порты 7 и 9 (оба обычно используются для WOL)
        $result1 = @socket_sendto($sock, $packet, strlen($packet), 0, $broadcast, 7);
        $result2 = @socket_sendto($sock, $packet, strlen($packet), 0, $broadcast, 9);

        socket_close($sock);

        return ($result1 !== false || $result2 !== false);
    }

    // Удалены дублирующиеся функции WakeOnLan1, WakeOnLan3, WakeOnLan6, WakeOnLan7
    // Все вызовы используют единую wakeOnLan

    /**
     * FrontEnd
     */
    function usual(&$out) {
        $this->admin($out);
    }

    /**
     * Install
     */
    function install($data = '') {
        parent::install();
    }

    function dbInstall($data) {
        $data = <<<EOD
        wol_devices: ID int(10) unsigned NOT NULL auto_increment
        wol_devices: TITLE varchar(100) NOT NULL DEFAULT ''
        wol_devices: MAC varchar(100) NOT NULL DEFAULT ''
        wol_devices: IPADDR varchar(100) NOT NULL DEFAULT ''
        wol_devices: NAME varchar(100) NOT NULL DEFAULT ''
        wol_devices: LASTPING varchar(100) NOT NULL DEFAULT ''
        wol_devices: ONLINE varchar(100) NOT NULL DEFAULT ''
        wol_devices: VENDOR varchar(100) NOT NULL DEFAULT ''
EOD;
        parent::dbInstall($data);
    }

    function uninstall() {
        SQLExec('DROP TABLE IF EXISTS wol_devices');
        parent::uninstall();
    }

    // --------------------------------------------------------------------
    // Вспомогательные функции для NetBIOS и определения vendor
    // --------------------------------------------------------------------

    /**
     * Отправка NBSTAT-запроса и получение информации
     */
    function nbt_getinfo($ip) {
        // Пакет NetBIOS с запросом NBSTAT
        $data = chr(0x81) . chr(0x0c) . chr(0x00) . chr(0x00) . chr(0x00) . chr(0x01) .
            chr(0x00) . chr(0x00) . chr(0x00) . chr(0x00) . chr(0x00) . chr(0x00) .
            chr(0x20) . chr(0x43) . chr(0x4b) . chr(0x41) . chr(0x41) . chr(0x41) .
            chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) .
            chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) .
            chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) .
            chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) .
            chr(0x41) . chr(0x41) . chr(0x41) . chr(0x41) . chr(0x00) . chr(0x00) . chr(0x21) .
            chr(0x00) . chr(0x01);

        $fp = @fsockopen("udp://$ip:137", $errno, $errstr, 2); // таймаут 2 секунды
        if (!$fp) {
            return -1;
        }

        fputs($fp, $data);
        stream_set_timeout($fp, 1);

        $response['transaction_id'] = fread($fp, 2);
        if (empty($response['transaction_id'])) {
            fclose($fp);
            return -1;
        }

        $response['transaction_id'] = $this->word2num($response['transaction_id']);
        $response['flags'] = $this->word2num(fread($fp, 2));
        $response['questions'] = $this->word2num(fread($fp, 2));
        $response['answers'] = $this->word2num(fread($fp, 2));
        $response['authority'] = $this->word2num(fread($fp, 2));
        $response['additional'] = $this->word2num(fread($fp, 2));

        if (!($response['questions'] == 0 && $response['answers'] == 1 &&
            $response['authority'] == 0 && $response['additional'] == 0)) {
            fclose($fp);
            return 2;
        }

        // Answer section
        $buf = fread($fp, 1);
        if ($buf != chr(0x20)) {
            fclose($fp);
            return 3;
        }

        // Answer Name
        $response['answer_name'] = '';
        while ($buf != chr(0)) {
            $buf = fread($fp, 1);
            $response['answer_name'] .= $buf;
        }

        // Type (should be NBSTAT)
        $response['answer_type'] = $this->word2num(fread($fp, 2));
        if ($response['answer_type'] != 33) {
            fclose($fp);
            return 3;
        }

        // Class
        $response['answer_class'] = $this->word2num(fread($fp, 2));
        // TTL
        $response['answer_ttl'] = $this->dword2num(fread($fp, 4));
        // Data length
        $response['answer_length'] = $this->word2num(fread($fp, 2));
        // Number of names
        $response['answer_number'] = ord(fread($fp, 1));

        // Getting names
        for ($i = 1; $i <= $response['answer_number']; $i++) {
            $response['answer_value'][$i] = fread($fp, 15);
            $response['answer_type_'][$i] = ord(fread($fp, 1));
            $response['answer_flags'][$i] = $this->word2num(fread($fp, 2));
        }

        // Unit ID (MAC)
        $response['answer_mac'] = fread($fp, 6);

        fclose($fp);
        return $response;
    }

    /**
     * Извлечение NetBIOS-имени из ответа
     */
    function nbt_getName($ip) {
        $response = $this->nbt_getinfo($ip);
        if (!is_array($response) || !isset($response['answer_type_'])) {
            return '';
        }
        $i = 1;
        foreach ($response['answer_type_'] as $answer_type_) {
            if ($answer_type_ == 0 && isset($response['answer_value'][$i])) {
                return trim($response['answer_value'][$i]);
            }
            $i++;
        }
        return '';
    }

    /**
     * Преобразование 2 байт в число (big-endian)
     */
    function word2num($word) {
        if (strlen($word) < 2) return 0;
        return unpack('n', $word)[1];
    }

    /**
     * Преобразование 4 байт в число (big-endian)
     */
    function dword2num($dword) {
        if (strlen($dword) < 4) return 0;
        return unpack('N', $dword)[1];
    }

    /**
     * Добавление устройства в список ping-хостов Majordomo
     */
    function add_to_pinghost($id) {
        if (!$id) {
            $id = (int)($_GET["id"] ?? 0);
        }
        $ph = SQLSelectOne("SELECT * FROM wol_devices WHERE ID='" . $id . "'");
        if (!$ph) return;

        $pinghosts = array();
        $pinghosts['TITLE'] = $ph['TITLE'];
        $pinghosts['TYPE'] = '0';
        $pinghosts['OFFLINE_INTERVAL'] = '600';
        $pinghosts['ONLINE_INTERVAL'] = '600';
        $pinghosts['HOSTNAME'] = $ph['IPADDR'];
        $pinghosts['CODE_OFFLINE'] = 'say("Устройство ".$host[\'TITLE\']." пропало из сети, возможно его отключили" ,2);';
        $pinghosts['CODE_ONLINE'] = 'say("Устройство ".$host[\'TITLE\']." появилось в сети." ,2);';
        $pinghosts['LINKED_OBJECT'] = '';
        $pinghosts['LINKED_PROPERTY'] = "alive";
        $pinghosts['CHECK_NEXT'] = date("Y-m-d H:i:s");

        $chek = SQLSelectOne("SELECT * FROM pinghosts WHERE HOSTNAME='" . DBSafe($ph['IPADDR']) . "'");
        if ($chek['ID']) {
            $pinghosts['ID'] = $chek['ID'];
            SQLUpdate('pinghosts', $pinghosts);
        } else {
            SQLInsert('pinghosts', $pinghosts);
        }
    }

    /**
     * Получение производителя по MAC-адресу через API macvendors.co
     */
    function getvendor($mac) {
        $mac = preg_replace('/[^a-fA-F0-9]/', '', $mac);
        if (strlen($mac) < 6) return '';

        $url = "https://macvendors.co/api/$mac/json";
        $context = stream_context_create(['http' => ['timeout' => 3]]);
        $file = @file_get_contents($url, false, $context);
        if ($file === false) return '';

        $data = json_decode($file, true);
        if (isset($data['result']['company'])) {
            return $data['result']['company'];
        }
        return '';
    }

}
/*
*
* TW9kdWxlIGNyZWF0ZWQgTWFyIDEzLCAyMDE2IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
* Исправлено для PHP 8.4
*/
?>